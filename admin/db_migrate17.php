<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

if (db_table_exists($conn, 'quran_murajaah_sessions')) {
    $col = $conn->query("SHOW COLUMNS FROM quran_murajaah_sessions LIKE 'session_type'")->fetch_assoc();
    $enum = (string)($col['Type'] ?? '');
    if (strpos($enum, 'video') === false || strpos($enum, 'inperson') === false) {
        $missing[] = 'quran_murajaah_sessions.session_type (video/inperson)';
    }
} else {
    $missing[] = 'quran_murajaah_sessions (table)';
}
if (db_table_exists($conn, 'mem_assistance_requests') === false) $missing[] = 'mem_assistance_requests (table)';
if (db_column_exists($conn, 'student_learning', 'completed_at') === false) $missing[] = 'student_learning.completed_at';
if (db_column_exists($conn, 'student_learning', 'audio_cleaned') === false) $missing[] = 'student_learning.audio_cleaned';

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. Extend session_type ENUM: live|audio|video|inperson */
    if (db_table_exists($conn, 'quran_murajaah_sessions')) {
        $col = $conn->query("SHOW COLUMNS FROM quran_murajaah_sessions LIKE 'session_type'")->fetch_assoc();
        $enum = (string)($col['Type'] ?? '');
        if (strpos($enum, 'video') === false || strpos($enum, 'inperson') === false) {
            try {
                $conn->query("ALTER TABLE quran_murajaah_sessions
                    MODIFY COLUMN session_type ENUM('live','audio','video','inperson') NOT NULL DEFAULT 'video'");
                $steps[] = ['quran_murajaah_sessions.session_type', 'extended to video/inperson'];
            } catch (Throwable $e) {
                $steps[] = ['quran_murajaah_sessions.session_type', 'ERROR - ' . $e->getMessage()];
            }
        } else {
            $steps[] = ['quran_murajaah_sessions.session_type', 'already present'];
        }

        /* 1b. Index for the 36h Muraja'ah video auto-deletion sweep */
        $idx = $conn->query("SHOW INDEX FROM quran_murajaah_sessions WHERE Key_name = 'idx_audio_submitted'");
        if ($idx && $idx->num_rows === 0) {
            try {
                $conn->query("ALTER TABLE quran_murajaah_sessions ADD INDEX idx_audio_submitted (audio_file, submitted_at)");
                $steps[] = ['quran_murajaah_sessions idx_audio_submitted', 'added'];
            } catch (Throwable $e) {
                $steps[] = ['quran_murajaah_sessions idx_audio_submitted', 'ERROR - ' . $e->getMessage()];
            }
        } else {
            $steps[] = ['quran_murajaah_sessions idx_audio_submitted', 'already present'];
        }
    }

    /* 2. mem_assistance_requests table */
    if (!db_table_exists($conn, 'mem_assistance_requests')) {
        try {
            $conn->query("
                CREATE TABLE mem_assistance_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    task_day INT NOT NULL,
                    start_page INT NOT NULL,
                    end_page INT NOT NULL,
                    note TEXT NULL,
                    admin_audio VARCHAR(255) NULL,
                    admin_notes TEXT NULL,
                    status ENUM('pending','done') NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    resolved_at DATETIME NULL,
                    resolved_by INT NULL,
                    INDEX idx_status (status),
                    INDEX idx_student (student_id),
                    INDEX idx_student_day (student_id, task_day)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['mem_assistance_requests (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['mem_assistance_requests (table)', 'ERROR - ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['mem_assistance_requests (table)', 'already present'];
    }

    /* 3. student_learning.completed_at */
    if (db_table_exists($conn, 'student_learning')) {
        if (!db_column_exists($conn, 'student_learning', 'completed_at')) {
            try {
                $conn->query("ALTER TABLE student_learning ADD COLUMN completed_at DATETIME NULL AFTER status");
                $steps[] = ['student_learning.completed_at', 'added'];
            } catch (Throwable $e) {
                $steps[] = ['student_learning.completed_at', 'ERROR - ' . $e->getMessage()];
            }
        } else {
            $steps[] = ['student_learning.completed_at', 'already present'];
        }

        /* 4. student_learning.audio_cleaned */
        if (!db_column_exists($conn, 'student_learning', 'audio_cleaned')) {
            try {
                $conn->query("ALTER TABLE student_learning ADD COLUMN audio_cleaned TINYINT(1) NOT NULL DEFAULT 0 AFTER completed_at");
                $steps[] = ['student_learning.audio_cleaned', 'added'];
            } catch (Throwable $e) {
                $steps[] = ['student_learning.audio_cleaned', 'ERROR - ' . $e->getMessage()];
            }
        } else {
            $steps[] = ['student_learning.audio_cleaned', 'already present'];
        }

        /* 5. student_learning cleanup index */
        if (db_column_exists($conn, 'student_learning', 'audio_cleaned')) {
            $idx = $conn->query("SHOW INDEX FROM student_learning WHERE Key_name = 'idx_cleanup'");
            if ($idx && $idx->num_rows === 0) {
                try {
                    $conn->query("ALTER TABLE student_learning ADD INDEX idx_cleanup (status, audio_cleaned, completed_at)");
                    $steps[] = ['student_learning idx_cleanup', 'added'];
                } catch (Throwable $e) {
                    $steps[] = ['student_learning idx_cleanup', 'ERROR - ' . $e->getMessage()];
                }
            } else {
                $steps[] = ['student_learning idx_cleanup', 'already present'];
            }
        }

        /* 6. Backfill completed_at for existing completed plans */
        if (db_column_exists($conn, 'student_learning', 'completed_at')) {
            try {
                $conn->query("UPDATE student_learning SET completed_at = NOW() WHERE status = 'completed' AND completed_at IS NULL");
                $steps[] = ['student_learning.completed_at backfill', 'done'];
            } catch (Throwable $e) {
                $steps[] = ['student_learning.completed_at backfill', 'ERROR - ' . $e->getMessage()];
            }
        }
    }
}

$missing_after = [];
if (db_table_exists($conn, 'mem_assistance_requests') === false) $missing_after[] = 'mem_assistance_requests';
if (db_column_exists($conn, 'student_learning', 'completed_at') === false) $missing_after[] = 'student_learning.completed_at';
if (db_column_exists($conn, 'student_learning', 'audio_cleaned') === false) $missing_after[] = 'student_learning.audio_cleaned';
if (db_table_exists($conn, 'quran_murajaah_sessions')) {
    $col = $conn->query("SHOW COLUMNS FROM quran_murajaah_sessions LIKE 'session_type'")->fetch_assoc();
    $enum = (string)($col['Type'] ?? '');
    if (strpos($enum, 'video') === false || strpos($enum, 'inperson') === false) {
        $missing_after[] = 'quran_murajaah_sessions.session_type (video/inperson)';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Memorizer System — Database Migration 17</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Memorizer System Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Memorizer Fixes &amp; Non-Memorizer Audio Auto-Delete</h1>
    <p>Extends Muraja'ah <code>session_type</code> (video/in-person), adds the <code>mem_assistance_requests</code> table, and adds <code>completed_at</code> / <code>audio_cleaned</code> to <code>student_learning</code> for the 24h audio auto-deletion sweep. Safe to run again — every step checks existence first.</p>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the Memorizer System schema is already in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                This migration adds:
                <strong>video</strong>/<strong>inperson</strong> Muraja'ah session types, the <code>mem_assistance_requests</code>
                recitation-assistance table, and the <code>completed_at</code> + <code>audio_cleaned</code> fields that power the
                24-hour completed-learning-cycle audio auto-deletion.
            </p>
            <form method="POST" onsubmit="return confirm('Run the Memorizer System schema migration now?');">
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