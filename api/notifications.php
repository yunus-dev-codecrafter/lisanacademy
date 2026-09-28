<?php
// api/notifications.php — Handles in-app and browser notifications
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../config/security/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';

// Check if today's 7:00 AM Islamic virtue reminder should be dispatched
if (function_exists('check_and_trigger_daily_virtue_notification')) {
    check_and_trigger_daily_virtue_notification($conn);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');

if ($action === 'get') {
    $unread_count = get_unread_notification_count($conn, $user_id, $role);
    $notifications = get_user_notifications($conn, $user_id, $role, 20);
    $settings = get_user_notification_settings($conn, $user_id);

    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'notifications' => $notifications,
        'settings' => $settings,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if ($action === 'poll') {
    $since_id = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
    $unread_count = get_unread_notification_count($conn, $user_id, $role);
    $settings = get_user_notification_settings($conn, $user_id);

    // Fetch notifications newer than since_id
    $new_items = [];
    if ($since_id > 0) {
        $stmt = $conn->prepare("
            SELECT n.*, (unr.notification_id IS NOT NULL) AS is_read
            FROM app_notifications n
            LEFT JOIN user_notification_reads unr
                ON unr.notification_id = n.id AND unr.user_id = ?
            WHERE (n.user_id = ? OR (n.user_id IS NULL AND (n.target_role = ? OR n.target_role = 'all')))
              AND n.id > ?
            ORDER BY n.id ASC
            LIMIT 10
        ");
        $stmt->bind_param("iisi", $user_id, $user_id, $role, $since_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $new_items[] = $r;
        }
    }

    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'new_notifications' => $new_items,
        'settings' => $settings
    ]);
    exit;
}

if ($action === 'mark_read') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    $notif_id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($notif_id > 0) {
        mark_notification_as_read($conn, $user_id, $notif_id);
    }

    $unread_count = get_unread_notification_count($conn, $user_id, $role);
    echo json_encode(['success' => true, 'unread_count' => $unread_count]);
    exit;
}

if ($action === 'mark_all_read') {
    mark_all_notifications_read($conn, $user_id, $role);
    echo json_encode(['success' => true, 'unread_count' => 0]);
    exit;
}

if ($action === 'update_settings') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;

    $enabled = isset($data['notifications_enabled']) ? (int)$data['notifications_enabled'] : 1;
    $daily = isset($data['daily_reminder_enabled']) ? (int)$data['daily_reminder_enabled'] : 1;
    $push_sub = isset($data['push_subscription']) ? (string)$data['push_subscription'] : null;

    update_user_notification_settings($conn, $user_id, $enabled, $daily, $push_sub);

    echo json_encode(['success' => true, 'enabled' => $enabled, 'daily_enabled' => $daily]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
