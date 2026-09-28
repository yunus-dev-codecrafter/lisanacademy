<?php
// cron/daily_reminder.php — Scheduled runner for daily 7:00 AM Islamic virtue reminder
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../config/db.php';

$force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');
$key = $_GET['key'] ?? '';

// Basic protection if a secret key is set in app_settings
$configured_key = (string)setting($conn, 'cron_secret_key', '');
if ($configured_key !== '' && $key !== $configured_key && !$force) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid cron key']);
    exit;
}

$now = date('Y-m-d H:i:s');
$hour = (int)date('G');
$today = date('Y-m-d');
$last_sent = (string)setting($conn, 'daily_virtue_last_date', '');

$dispatched = false;
$notif_id = null;

if ($force || ($hour >= 7 && $last_sent !== $today)) {
    $notif_id = check_and_trigger_daily_virtue_notification($conn, $force);
    $dispatched = (bool)$notif_id;
}

$virtue = get_daily_islamic_virtue();

echo json_encode([
    'success' => true,
    'time' => $now,
    'hour' => $hour,
    'today' => $today,
    'last_sent_date' => (string)setting($conn, 'daily_virtue_last_date', ''),
    'dispatched' => $dispatched,
    'notification_id' => $notif_id,
    'today_virtue' => $virtue
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
