<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    // 1. Create app_notifications table
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS app_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                target_role ENUM('all', 'student', 'admin') NOT NULL DEFAULT 'all',
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(50) NOT NULL DEFAULT 'general',
                action_url VARCHAR(255) NULL,
                icon VARCHAR(50) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_role (user_id, target_role, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = ['app_notifications table', 'created or already exists'];
    } catch (Throwable $e) {
        $steps[] = ['app_notifications table', 'ERROR: ' . $e->getMessage()];
    }

    // 2. Create user_notification_reads table
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS user_notification_reads (
                user_id INT NOT NULL,
                notification_id INT NOT NULL,
                read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, notification_id),
                INDEX idx_user_read (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = ['user_notification_reads table', 'created or already exists'];
    } catch (Throwable $e) {
        $steps[] = ['user_notification_reads table', 'ERROR: ' . $e->getMessage()];
    }

    // 3. Create user_notification_settings table
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS user_notification_settings (
                user_id INT PRIMARY KEY,
                notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
                daily_reminder_enabled TINYINT(1) NOT NULL DEFAULT 1,
                push_subscription TEXT NULL,
                last_daily_sent_date DATE NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = ['user_notification_settings table', 'created or already exists'];
    } catch (Throwable $e) {
        $steps[] = ['user_notification_settings table', 'ERROR: ' . $e->getMessage()];
    }

    // 4. Test dispatch initial notification
    try {
        if (function_exists('check_and_trigger_daily_virtue_notification')) {
            check_and_trigger_daily_virtue_notification($conn);
            $steps[] = ['Daily 7 AM reminder check', 'verified and initialized'];
        }
    } catch (Throwable $e) {
        $steps[] = ['Daily 7 AM reminder check', 'ERROR: ' . $e->getMessage()];
    }
}

$has_notifications = db_table_exists($conn, 'app_notifications');
$has_reads = db_table_exists($conn, 'user_notification_reads');
$has_settings = db_table_exists($conn, 'user_notification_settings');
?>
<!DOCTYPE html>
<html>
<head>
<title>Notifications System Migration</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'settings', 'Migration', 'Notifications'); ?>

<div class="page-hero animate-rise">
    <h1>Notifications System Migration</h1>
    <p>Sets up in-app and browser notifications tables, user settings, and daily 7 AM reminder schedule.</p>
</div>

<div class="card animate-rise d1" style="max-width:600px;margin-bottom:20px;">
    <h3>Current Schema Status</h3>
    <ul style="margin:14px 0 20px;padding-left:20px;line-height:1.8;">
        <li>app_notifications: <strong><?= $has_notifications ? '<span style="color:var(--success);">Installed</span>' : '<span style="color:var(--danger);">Missing</span>' ?></strong></li>
        <li>user_notification_reads: <strong><?= $has_reads ? '<span style="color:var(--success);">Installed</span>' : '<span style="color:var(--danger);">Missing</span>' ?></strong></li>
        <li>user_notification_settings: <strong><?= $has_settings ? '<span style="color:var(--success);">Installed</span>' : '<span style="color:var(--danger);">Missing</span>' ?></strong></li>
    </ul>

    <?php if ($ran): ?>
        <div class="alert alert-success" style="margin-bottom:16px;">
            <strong>Migration Results:</strong>
            <ul style="margin:8px 0 0;padding-left:16px;">
                <?php foreach ($steps as [$name, $status]): ?>
                    <li><?= htmlspecialchars($name) ?>: <?= htmlspecialchars($status) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-gold btn-block">
            <?= ui_icon('refresh', 16) ?> <?= ($has_notifications && $has_reads && $has_settings) ? 'Re-run Migration (Safe)' : 'Run Migration Now' ?>
        </button>
    </form>
</div>

<?php ui_page_end(); ?>
</body>
</html>
