<?php
/*
 * Guitar lesson book — data API.
 * Stores everything as JSON in ./data (blocked from the web by .htaccess),
 * protected by one password. Keeps a daily backup in ./data/backups (60 days).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const DATA_DIR = __DIR__ . '/data';
define('GLB', 1);
date_default_timezone_set('Asia/Jerusalem');
require __DIR__ . '/push-lib.php';
const COLLS = ['students', 'lessons', 'charges'];
const BACKUP_DAYS = 60;

function out(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
function readJson(string $f, array $def): array {
    if (!is_file($f)) return $def;
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : $def;
}
function writeJson(string $f, array $d): void {
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE)) === false) out(['ok' => false, 'error' => 'write failed'], 500);
    @chmod($tmp, 0600);
    rename($tmp, $f);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);

if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0700, true)) out(['ok' => false, 'error' => 'cannot create data folder'], 500);
if (!is_file(DATA_DIR . '/.htaccess')) file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
if (!is_file(DATA_DIR . '/index.html')) file_put_contents(DATA_DIR . '/index.html', '');

$lock = fopen(DATA_DIR . '/.lock', 'c');
flock($lock, LOCK_EX);

$authFile = DATA_DIR . '/auth.json';
$auth = readJson($authFile, ['hash' => null, 'tokens' => [], 'fails' => 0, 'lockUntil' => 0]);
$action = (string)($_GET['action'] ?? '');
$raw = file_get_contents('php://input');
if (strlen((string)$raw) > 8 * 1024 * 1024) out(['ok' => false, 'error' => 'too large'], 413);
$in = json_decode($raw ?: '{}', true);
if (!is_array($in)) $in = [];

function newToken(array &$auth): string {
    $t = bin2hex(random_bytes(32));
    $auth['tokens'][hash('sha256', $t)] = ['created' => time(), 'device' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 150)];
    return $t;
}

/* ---- no login needed ---- */
if ($action === 'status') out(['ok' => true, 'needsSetup' => empty($auth['hash'])]);

if ($action === 'setup') {
    if (!empty($auth['hash'])) out(['ok' => false, 'error' => 'password already set'], 403);
    $p = (string)($in['password'] ?? '');
    if (mb_strlen($p) < 8) out(['ok' => false, 'error' => 'password too short'], 400);
    $auth['hash'] = password_hash($p, PASSWORD_DEFAULT);
    $t = newToken($auth);
    writeJson($authFile, $auth);
    out(['ok' => true, 'token' => $t]);
}

if ($action === 'login') {
    if (time() < (int)($auth['lockUntil'] ?? 0)) out(['ok' => false, 'error' => 'locked', 'retryIn' => (int)$auth['lockUntil'] - time()], 429);
    $p = (string)($in['password'] ?? '');
    if (empty($auth['hash']) || !password_verify($p, $auth['hash'])) {
        $auth['fails'] = (int)($auth['fails'] ?? 0) + 1;
        if ($auth['fails'] >= 5) { $auth['lockUntil'] = time() + 300; $auth['fails'] = 0; }
        writeJson($authFile, $auth);
        sleep(1);
        out(['ok' => false, 'error' => 'wrong password', 'needsSetup' => empty($auth['hash'])], 401);
    }
    $auth['fails'] = 0;
    $t = newToken($auth);
    writeJson($authFile, $auth);
    out(['ok' => true, 'token' => $t]);
}

/* ---- everything below needs a valid token ---- */
$tok = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
$th = hash('sha256', $tok);
if (strlen($tok) < 32 || !isset($auth['tokens'][$th])) out(['ok' => false, 'error' => 'auth', 'needsSetup' => empty($auth['hash'])], 401);

if ($action === 'logout') {
    unset($auth['tokens'][$th]);
    writeJson($authFile, $auth);
    out(['ok' => true]);
}

/* ---- phone notifications ---- */
if ($action === 'push-key') out(['ok' => true, 'key' => vapid_keys(DATA_DIR)['public']]);
if ($action === 'subscribe' || $action === 'unsubscribe') {
    $sub = $in['subscription'] ?? null;
    $ep = is_array($sub) ? (string)($sub['endpoint'] ?? '') : '';
    if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?/#i', $ep)) out(['ok' => false, 'error' => 'bad subscription'], 400);
    $f = DATA_DIR . '/subscriptions.json';
    $subs = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $key = hash('sha256', $ep);
    if ($action === 'subscribe') {
        if (empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) out(['ok' => false, 'error' => 'bad keys'], 400);
        $subs[$key] = ['endpoint' => $ep, 'keys' => ['p256dh' => (string)$sub['keys']['p256dh'], 'auth' => (string)$sub['keys']['auth']], 'added' => time()];
        $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host) file_put_contents(DATA_DIR . '/origin.json', json_encode(['origin' => 'https://' . $host]));
    } else unset($subs[$key]);
    file_put_contents($f, json_encode($subs)); @chmod($f, 0600);
    out(['ok' => true, 'devices' => count($subs)]);
}
if ($action === 'test-push') {
    $res = push_all(DATA_DIR, ['title' => 'יומן שיעורי גיטרה', 'body' => 'ההתראות עובדות. כך תיראה תזכורת לאישור הגעה.', 'tag' => 'test', 'url' => './']);
    out(['ok' => true, 'results' => $res]);
}

if ($action === 'change-password') {
    $old = (string)($in['old'] ?? ''); $new = (string)($in['new'] ?? '');
    if (!password_verify($old, (string)$auth['hash'])) out(['ok' => false, 'error' => 'wrong password'], 403);
    if (mb_strlen($new) < 8) out(['ok' => false, 'error' => 'password too short'], 400);
    $auth['hash'] = password_hash($new, PASSWORD_DEFAULT);
    $auth['tokens'] = [$th => $auth['tokens'][$th]]; // sign out every other device
    writeJson($authFile, $auth);
    out(['ok' => true]);
}

$storeFile = DATA_DIR . '/store.json';
$store = readJson($storeFile, ['students' => [], 'lessons' => [], 'charges' => [], 'settings' => []]);
foreach (array_merge(COLLS, ['settings']) as $c) if (!isset($store[$c]) || !is_array($store[$c])) $store[$c] = [];

/* daily backup, keeps the last BACKUP_DAYS days */
$bdir = DATA_DIR . '/backups';
if (!is_dir($bdir)) @mkdir($bdir, 0700, true);
$bf = $bdir . '/' . date('Y-m-d') . '.json';
if (is_file($storeFile) && !is_file($bf)) {
    @copy($storeFile, $bf);
    $all = glob($bdir . '/*.json') ?: [];
    sort($all);
    while (count($all) > BACKUP_DAYS) @unlink(array_shift($all));
}

function payload(array $store): array {
    return ['ok' => true, 'students' => (object)$store['students'], 'lessons' => (object)$store['lessons'], 'charges' => (object)$store['charges'], 'settings' => (object)$store['settings'], 'serverTime' => time()];
}
function validId(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9_\-.:]{1,160}$/', $id); }
function applyOp(array &$store, $op): bool {
    if (!is_array($op)) return false;
    $c = (string)($op['coll'] ?? '');
    if ($c === 'settings') { if (is_array($op['data'] ?? null)) { $store['settings'] = $op['data']; return true; } return false; }
    if (!in_array($c, COLLS, true)) return false;
    $id = (string)($op['id'] ?? '');
    if (!validId($id)) return false;
    if (isset($op['data']) && is_array($op['data'])) {
        $d = $op['data']; unset($d['id']); $d['_u'] = time();
        $store[$c][$id] = $d;
    } else {
        unset($store[$c][$id]);
    }
    return true;
}

if ($action === 'all') out(payload($store));

if ($action === 'batch') {
    $ops = $in['ops'] ?? [];
    if (!is_array($ops) || count($ops) > 500) out(['ok' => false, 'error' => 'bad ops'], 400);
    $n = 0;
    foreach ($ops as $op) if (applyOp($store, $op)) $n++;
    writeJson($storeFile, $store);
    out(['ok' => true, 'applied' => $n]);
}

if ($action === 'import') {
    $d = $in['data'] ?? null;
    if (!is_array($d)) out(['ok' => false, 'error' => 'no data'], 400);
    $counts = [];
    foreach (COLLS as $c) {
        $counts[$c] = 0;
        foreach (($d[$c] ?? []) as $id => $doc) {
            if (is_array($doc) && applyOp($store, ['coll' => $c, 'id' => (string)$id, 'data' => $doc])) $counts[$c]++;
        }
    }
    if (is_array($d['settings'] ?? null)) $store['settings'] = array_merge($store['settings'], $d['settings']);
    writeJson($storeFile, $store);
    out(['ok' => true, 'imported' => $counts]);
}

out(['ok' => false, 'error' => 'unknown action'], 400);
