<?php
/*
 * Run by a cPanel cron job every 15 minutes:
 *   php /home/USER/your-app-folder/cron.php
 * Sends one phone notification listing the lessons whose confirmation reminder is now due.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('GLB', 1);
date_default_timezone_set('Asia/Jerusalem');
require __DIR__ . '/push-lib.php';

$dir = __DIR__ . '/data';
if (!is_file($dir . '/store.json')) exit(0);
$lock = fopen($dir . '/.cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$store = json_decode((string)file_get_contents($dir . '/store.json'), true) ?: [];
$settings = $store['settings'] ?? [];
$stateFile = $dir . '/cron-state.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$now = time();
$last = (int)($state['last'] ?? ($now - 15 * 60));
if ($last < $now - 6 * 3600) $last = $now - 6 * 3600;   // after a long outage, don't flood
$notified = $state['notified'] ?? [];

$due = [];
foreach (($store['lessons'] ?? []) as $id => $l) {
    if (($l['status'] ?? 'ok') === 'cancelled' || in_array($l['confirm'] ?? '', ['yes', 'no'], true)) continue;
    $st = $store['students'][$l['studentId'] ?? ''] ?? null;
    if (!$st || !empty($st['archived'])) continue;
    $at = reminder_time($l, $st, $settings);
    if ($at === null || $at > $now || $at <= $last) continue;
    if (strtotime($l['date'] . ' ' . ($l['time'] ?? '00:00')) < $now) continue;     // lesson already started
    if (isset($notified[$id . '@' . $at])) continue;
    $due[] = ['id' => $id, 'name' => trim(explode(' ', trim($st['name'] ?? ''))[0] ?: 'תלמיד'), 'date' => $l['date'], 'time' => $l['time'] ?? '', 'key' => $id . '@' . $at];
}

if ($due) {
    usort($due, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
    $today = date('Y-m-d');
    $parts = array_map(fn($d) => $d['name'] . ' ' . ($d['date'] === $today ? 'היום' : 'מחר') . ' ב־' . $d['time'], $due);
    $n = count($due);
    $msg = [
        'title' => $n === 1 ? 'תזכורת לאישור הגעה' : "$n שיעורים לאישור הגעה",
        'body' => implode(' · ', $parts) . "\nלחצו כדי לשלוח תזכורות",
        'tag' => 'confirm-' . date('Y-m-d-H'),
        'url' => './#confirm',
    ];
    push_all($dir, $msg);
    foreach ($due as $d) $notified[$d['key']] = $now;
}

// forget old entries
foreach ($notified as $k => $t) if ($t < $now - 3 * 86400) unset($notified[$k]);
file_put_contents($stateFile, json_encode(['last' => $now, 'notified' => $notified]));
@chmod($stateFile, 0600);
