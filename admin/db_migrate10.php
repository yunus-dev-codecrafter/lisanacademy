<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

if (db_column_exists($conn, 'users', 'hafiz') === false) $missing[] = 'users.hafiz';
if (db_table_exists($conn, 'hafiz_revision') === false)  $missing[] = 'hafiz_revision (table)';
if (db_table_exists($conn, 'hafiz_sessions') === false)  $missing[] = 'hafiz_sessions (table)';
if (db_table_exists($conn, 'hafiz_weekly_log') === false) $missing[] = 'hafiz_weekly_log (table)';

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. users.hafiz flag */
    if (!db_column_exists($conn, 'users', 'hafiz')) {
        try {
            $conn->query("ALTER TABLE users ADD COLUMN hafiz TINYINT(1) NOT NULL DEFAULT 0 AFTER blocked");
            $steps[] = ['users.hafiz', 'added'];
        } catch (Throwable $e) {
            $steps[] = ['users.hafiz', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['users.hafiz', 'already present'];
    }

    /* 2. hafiz_revision table */
    if (!db_table_exists($conn, 'hafiz_revision')) {
        try {
            $conn->query("
                CREATE TABLE hafiz_revision (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    cycle_no INT NOT NULL DEFAULT 1,
                    current_page INT NOT NULL DEFAULT 1,
                    week_started_at DATETIME NOT NULL,
                    status ENUM('active','completed') NOT NULL DEFAULT 'active',
                    started_at DATETIME NOT NULL,
                    completed_at DATETIME NULL,
                    skip_approved TINYINT(1) NOT NULL DEFAULT 0,
                    skip_approved_at DATETIME NULL,
                    UNIQUE KEY uq_student_cycle (student_id, cycle_no),
                    KEY idx_student_status (student_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['hafiz_revision (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['hafiz_revision (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['hafiz_revision (table)', 'already present'];
    }

    /* 3. hafiz_sessions table */
    if (!db_table_exists($conn, 'hafiz_sessions')) {
        try {
            $conn->query("
                CREATE TABLE hafiz_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    revision_id INT NOT NULL,
                    page_no INT NOT NULL,
                    session_type ENUM('live','audio') NOT NULL DEFAULT 'audio',
                    audio_file VARCHAR(255) NULL,
                    status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
                    rating VARCHAR(50) NULL,
                    feedback TEXT NULL,
                    admin_audio_feedback VARCHAR(255) NULL,
                    submitted_at DATETIME NOT NULL,
                    reviewed_at DATETIME NULL,
                    UNIQUE KEY uq_revision_page (revision_id, page_no),
                    KEY idx_student_status (student_id, status),
                    KEY idx_revision_status (revision_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['hafiz_sessions (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['hafiz_sessions (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['hafiz_sessions (table)', 'already present'];
    }

    /* 4. hafiz_weekly_log table */
    if (!db_table_exists($conn, 'hafiz_weekly_log')) {
        try {
            $conn->query("
                CREATE TABLE hafiz_weekly_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    revision_id INT NOT NULL,
                    student_id INT NOT NULL,
                    week_no INT NOT NULL,
                    pages_completed INT NOT NULL DEFAULT 0,
                    target_met TINYINT(1) NOT NULL DEFAULT 0,
                    was_skipped TINYINT(1) NOT NULL DEFAULT 0,
                    week_started_at DATETIME NOT NULL,
                    week_ended_at DATETIME NULL,
                    UNIQUE KEY uq_revision_week (revision_id, week_no),
                    KEY idx_student (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['hafiz_weekly_log (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['hafiz_weekly_log (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['hafiz_weekly_log (table)', 'already present'];
    }
}

$missing_after = [];
if (db_column_exists($conn, 'users', 'hafiz') === false) $missing_after[] = 'users.hafiz';
if (db_table_exists($conn, 'hafiz_revision') === false)  $missing_after[] = 'hafiz_revision';
if (db_table_exists($conn, 'hafiz_sessions') === false)  $missing_after[] = 'hafiz_sessions';
if (db_table_exists($conn, 'hafiz_weekly_log') === false) $missing_after[] = 'hafiz_weekly_log';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hafiz Revision — Database Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Hafiz Revision Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Hafiz Revision</h1>
    <p>Adds the <code>users.hafiz</code> flag and three new tables for page-based Qur'an revision tracking. Safe to run again — every step checks existence first.</p>
</div>

<?php if ($ran): ?>
    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;"><?= ui_icon('check-circle', 18) ?> Migration Result</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Object</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $st): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($st[0]) ?></code></td>
                            <td>
                                <?php if (strncmp($st[1], 'ERROR', 5) === 0): ?>
                                    <span class="badge badge-red">Failed</span>
                                    <div class="small text-muted"><?= htmlspecialchars($st[1]) ?></div>
                                <?php elseif ($st[1] === 'already present'): ?>
                                    <span class="badge badge-grey"><?= htmlspecialchars($st[1]) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-green"><?= htmlspecialchars($st[1]) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($missing_after)): ?>
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place. Hafiz revision is ready.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the Hafiz revision schema is already in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                This migration adds the <strong>Hafiz Revision</strong> feature: a <code>users.hafiz</code> flag to designate Hafiz students,
                plus three tables (<code>hafiz_revision</code>, <code>hafiz_sessions</code>, <code>hafiz_weekly_log</code>) to track
                sequential page-based Qur'an revision with weekly quotas.
            </p>
            <form method="POST" onsubmit="return confirm('Run the Hafiz revision schema migration now?');">
                <?= csrf_field() ?>
                <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('refresh', 17) ?> Run Migration</button>
            </form>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-ghost" href="dashboard.php"><?= ui_icon('grid', 16) ?> Dashboard</a>
        </div>
    </div>

<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>
