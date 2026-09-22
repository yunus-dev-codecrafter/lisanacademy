<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

if (db_column_exists($conn, 'users', 'memorizing') === false) $missing[] = 'users.memorizing';
if (db_table_exists($conn, 'quran_memorization') === false)   $missing[] = 'quran_memorization (table)';
if (db_table_exists($conn, 'quran_memorization_log') === false) $missing[] = 'quran_memorization_log (table)';
if (db_table_exists($conn, 'quran_murajaah_sessions') === false) $missing[] = 'quran_murajaah_sessions (table)';

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. users.memorizing flag */
    if (!db_column_exists($conn, 'users', 'memorizing')) {
        try {
            $conn->query("ALTER TABLE users ADD COLUMN memorizing TINYINT(1) NOT NULL DEFAULT 0 AFTER hafiz");
            $steps[] = ['users.memorizing', 'added'];
        } catch (Throwable $e) {
            $steps[] = ['users.memorizing', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['users.memorizing', 'already present'];
    }

    /* 2. quran_memorization table */
    if (!db_table_exists($conn, 'quran_memorization')) {
        try {
            $conn->query("
                CREATE TABLE quran_memorization (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    pace INT NOT NULL DEFAULT 1,
                    current_page INT NOT NULL DEFAULT 1,
                    pages_memorized INT NOT NULL DEFAULT 0,
                    day_count INT NOT NULL DEFAULT 0,
                    segment_type ENUM('normal25','rev100','revhalf','revfinal','done') NOT NULL DEFAULT 'normal25',
                    segment_day INT NOT NULL DEFAULT 1,
                    current_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full',
                    task_type ENUM('memorization','murajaah') NOT NULL DEFAULT 'memorization',
                    current_month INT NOT NULL DEFAULT 1,
                    current_100_block INT NOT NULL DEFAULT 1,
                    milestone VARCHAR(30) NOT NULL DEFAULT 'none',
                    status ENUM('active','paused','completed') NOT NULL DEFAULT 'active',
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL,
                    completed_at DATETIME NULL,
                    UNIQUE KEY uq_student (student_id),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['quran_memorization (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['quran_memorization (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['quran_memorization (table)', 'already present'];
    }

    /* 3. quran_memorization_log table */
    if (!db_table_exists($conn, 'quran_memorization_log')) {
        try {
            $conn->query("
                CREATE TABLE quran_memorization_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    task_day INT NOT NULL,
                    task_type ENUM('memorization','murajaah') NOT NULL,
                    start_page INT NOT NULL,
                    end_page INT NOT NULL,
                    day_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full',
                    result ENUM('completed','passed','failed','skipped','adjusted') NOT NULL DEFAULT 'completed',
                    completed_by ENUM('student','admin') NOT NULL DEFAULT 'student',
                    notes TEXT NULL,
                    assigned_date DATETIME NULL,
                    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_student_day (student_id, task_day),
                    KEY idx_student_created (student_id, completed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['quran_memorization_log (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['quran_memorization_log (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['quran_memorization_log (table)', 'already present'];
    }

    /* 4. quran_murajaah_sessions table */
    if (!db_table_exists($conn, 'quran_murajaah_sessions')) {
        try {
            $conn->query("
                CREATE TABLE quran_murajaah_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    task_day INT NOT NULL,
                    start_page INT NOT NULL,
                    end_page INT NOT NULL,
                    day_session ENUM('full','morning','evening') NOT NULL DEFAULT 'full',
                    session_type ENUM('live','audio') NOT NULL DEFAULT 'audio',
                    audio_file VARCHAR(255) NULL,
                    status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
                    feedback TEXT NULL,
                    admin_audio_feedback VARCHAR(255) NULL,
                    reviewed_by INT NULL,
                    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    reviewed_at DATETIME NULL,
                    KEY idx_student_status (student_id, status),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['quran_murajaah_sessions (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['quran_murajaah_sessions (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['quran_murajaah_sessions (table)', 'already present'];
    }
}

$missing_after = [];
if (db_column_exists($conn, 'users', 'memorizing') === false) $missing_after[] = 'users.memorizing';
if (db_table_exists($conn, 'quran_memorization') === false)   $missing_after[] = 'quran_memorization';
if (db_table_exists($conn, 'quran_memorization_log') === false) $missing_after[] = 'quran_memorization_log';
if (db_table_exists($conn, 'quran_murajaah_sessions') === false) $missing_after[] = 'quran_murajaah_sessions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Qur'an Memorization — Database Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Qur\'an Memorization Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Qur'an Memorization &amp; Muraja'ah</h1>
    <p>Adds the <code>users.memorizing</code> flag plus three tables (<code>quran_memorization</code>, <code>quran_memorization_log</code>, <code>quran_murajaah_sessions</code>) for the state-based 604-page memorization &amp; Muraja'ah system. Safe to run again — every step checks existence first.</p>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place. Qur'an Memorization is ready.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the Qur'an Memorization schema is already in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                This migration adds the <strong>Qur'an Memorization &amp; Muraja'ah</strong> feature: a <code>users.memorizing</code> flag to designate Memorizer students,
                a per-student state row (<code>quran_memorization</code>), a permanent completion history (<code>quran_memorization_log</code>),
                and the Muraja'ah assessment submissions (<code>quran_murajaah_sessions</code>).
            </p>
            <form method="POST" onsubmit="return confirm('Run the Qur'an Memorization schema migration now?');">
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