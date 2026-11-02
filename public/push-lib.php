<?php
/*
 * Web Push (RFC 8291 aes128gcm + VAPID RFC 8292) using only PHP's openssl and curl.
 * Included by api.php and cron.php; does nothing when requested directly.
 */
declare(strict_types=1);
if (!defined('GLB')) { http_response_code(404); exit; }

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Raw uncompressed EC point (65 bytes) from an openssl key. */
function ec_raw_public($key): string {
    $d = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}
/** PEM public key from a raw uncompressed P-256 point. */
function ec_pem_from_raw(string $raw): string {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function ec_new() {
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/** VAPID keys, created once and kept in data/vapid.json */
function vapid_keys(string $dataDir): array {
    $f = $dataDir . '/vapid.json';
    if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j) && !empty($j['private'])) return $j; }
    $k = ec_new();
    openssl_pkey_export($k, $pem);
    $j = ['private' => $pem, 'public' => b64u(ec_raw_public($k))];
    file_put_contents($f, json_encode($j)); @chmod($f, 0600);
    return $j;
}

/** DER ECDSA signature -> raw 64-byte r||s */
function der_to_raw(string $der): string {
    $pos = 2; if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $pos++; $len = ord($der[$pos]); $pos++;
        $v = substr($der, $pos, $len); $pos += $len;
        $v = ltrim($v, "\0");
        $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

function vapid_header(string $endpoint, array $vapid, string $subject): string {
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    $key = openssl_pkey_get_private($vapid['private']);
    openssl_sign("$h.$c", $sig, $key, OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$h.$c." . b64u(der_to_raw($sig)) . ', k=' . $vapid['public'];
}

/** Encrypts a payload for one subscription (aes128gcm). Returns the request body. */
function push_encrypt(string $payload, string $p256dhB64, string $authB64): string {
    $uaPublic = b64u_dec($p256dhB64);
    $authSecret = b64u_dec($authB64);
    if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) throw new RuntimeException('bad subscription keys');
    $as = ec_new();
    $asPublic = ec_raw_public($as);
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(ec_pem_from_raw($uaPublic)), $as, 32);
    if ($ecdh === false) throw new RuntimeException('ecdh failed');
    $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
}

/** Sends to one subscription. Returns the HTTP status (0 on network error). */
function push_send(array $sub, string $payload, array $vapid, string $subject): int {
    $body = push_encrypt($payload, $sub['keys']['p256dh'], $sub['keys']['auth']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 43200', 'Urgency: high',
            'Authorization: ' . vapid_header($sub['endpoint'], $vapid, $subject),
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Sends to every saved device; drops subscriptions the push service says are gone. */
function push_all(string $dataDir, array $msg): array {
    $f = $dataDir . '/subscriptions.json';
    $subs = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $vapid = vapid_keys($dataDir);
    $origin = (string)(json_decode((string)@file_get_contents($dataDir . '/origin.json'), true)['origin'] ?? 'https://example.com');
    $res = []; $changed = false;
    foreach ($subs as $id => $sub) {
        try { $code = push_send($sub, json_encode($msg, JSON_UNESCAPED_UNICODE), $vapid, $origin); }
        catch (Throwable $e) { $code = -1; }
        $res[] = $code;
        if ($code === 404 || $code === 410) { unset($subs[$id]); $changed = true; }
    }
    if ($changed) { file_put_contents($f, json_encode($subs)); }
    return $res;
}

/** Which lessons need a confirmation reminder, and when (unix time). */
function reminder_time(array $lesson, array $student, array $settings): ?int {
    $when = $student['remindWhen'] ?? 'same';
    if ($when === 'none') return null;
    $t = $when === 'before' ? ($settings['remindBeforeAt'] ?? '21:00') : ($settings['remindSameAt'] ?? '10:00');
    $day = $when === 'before' ? date('Y-m-d', strtotime($lesson['date'] . ' -1 day')) : $lesson['date'];
    $ts = strtotime($day . ' ' . $t);
    return $ts === false ? null : $ts;
}
