<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

if (db_table_exists($conn, 'quran_pages') === false)       $missing[] = 'quran_pages (table)';
if (db_table_exists($conn, 'hafiz_weekly_tests') === false)  $missing[] = 'hafiz_weekly_tests (table)';
if (db_table_exists($conn, 'hafiz_test_answers') === false)  $missing[] = 'hafiz_test_answers (table)';

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. quran_pages table */
    if (!db_table_exists($conn, 'quran_pages')) {
        try {
            $conn->query("
                CREATE TABLE quran_pages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    page_no INT NOT NULL,
                    surah_id INT NOT NULL,
                    verse INT NOT NULL,
                    UNIQUE KEY uq_page (page_no),
                    KEY idx_surah (surah_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['quran_pages (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['quran_pages (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['quran_pages (table)', 'already present'];
    }

    /* 1b. populate quran_pages from config data */
    $data_file = __DIR__ . '/../config/quran_pages_data.php';
    if (file_exists($data_file) && db_table_exists($conn, 'quran_pages')) {
        try {
            $cnt = (int)$conn->query("SELECT COUNT(*) c FROM quran_pages")->fetch_assoc()['c'];
            if ($cnt === 0) {
                $page_map = require $data_file;
                $stmt = $conn->prepare("INSERT INTO quran_pages (page_no, surah_id, verse) VALUES (?, ?, ?)");
                $inserted = 0;
                foreach ($page_map as $pn => $kv) {
                    $stmt->bind_param('iii', $pn, $kv['surah'], $kv['verse']);
                    $stmt->execute();
                    $inserted++;
                }
                $steps[] = ['quran_pages data', $inserted . ' pages loaded'];
            } else {
                $steps[] = ['quran_pages data', "already present ($cnt rows)"];
            }
        } catch (Throwable $e) {
            $steps[] = ['quran_pages data', 'ERROR — ' . $e->getMessage()];
        }
    } elseif (!db_table_exists($conn, 'quran_pages')) {
        $steps[] = ['quran_pages data', 'skipped (table missing)'];
    }

    /* 2. hafiz_weekly_tests table */
    if (!db_table_exists($conn, 'hafiz_weekly_tests')) {
        try {
            $conn->query("
                CREATE TABLE hafiz_weekly_tests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    revision_id INT NOT NULL,
                    week_no INT NOT NULL,
                    status ENUM('draft','submitted','passed','failed','expired') NOT NULL DEFAULT 'draft',
                    started_at DATETIME NOT NULL,
                    submitted_at DATETIME NULL,
                    reviewed_at DATETIME NULL,
                    admin_audio_file VARCHAR(255) NULL,
                    admin_feedback TEXT NULL,
                    KEY idx_student (student_id),
                    KEY idx_rev_week (revision_id, week_no),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['hafiz_weekly_tests (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['hafiz_weekly_tests (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['hafiz_weekly_tests (table)', 'already present'];
    }

    /* 3. hafiz_test_answers table */
    if (!db_table_exists($conn, 'hafiz_test_answers')) {
        try {
            $conn->query("
                CREATE TABLE hafiz_test_answers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    test_id INT NOT NULL,
                    page_no INT NOT NULL,
                    surah_id INT NOT NULL,
                    from_verse INT NOT NULL,
                    to_verse INT NOT NULL,
                    status ENUM('pending','submitted') NOT NULL DEFAULT 'pending',
                    audio_file VARCHAR(255) NULL,
                    answered_at DATETIME NULL,
                    KEY idx_test (test_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['hafiz_test_answers (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['hafiz_test_answers (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['hafiz_test_answers (table)', 'already present'];
    }
}

$missing_after = [];
if (db_table_exists($conn, 'quran_pages') === false)       $missing_after[] = 'quran_pages';
if (db_table_exists($conn, 'hafiz_weekly_tests') === false)  $missing_after[] = 'hafiz_weekly_tests';
if (db_table_exists($conn, 'hafiz_test_answers') === false)  $missing_after[] = 'hafiz_test_answers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hafiz Weekly Test — Database Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Hafiz Weekly Test Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Hafiz Weekly Test</h1>
    <p>Adds the <code>quran_pages</code> Madani Mushaf page index plus two new tables (<code>hafiz_weekly_tests</code>, <code>hafiz_test_answers</code>) for the weekly Friday test system. Safe to run again — every step checks existence first.</p>
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
                                <?php elseif (strncmp($st[1], 'already', 7) === 0 || strncmp($st[1], 'skipped', 7) === 0): ?>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place. Hafiz weekly tests are ready.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the Hafiz weekly test schema is already in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                This migration adds the <strong>Hafiz Weekly Test</strong> feature: a Madani Mushaf page index (<code>quran_pages</code>),
                a weekly test attempt tracker (<code>hafiz_weekly_tests</code>), and per-question answers (<code>hafiz_test_answers</code>).
            </p>
            <form method="POST" onsubmit="return confirm('Run the Hafiz weekly test schema migration now?');">
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