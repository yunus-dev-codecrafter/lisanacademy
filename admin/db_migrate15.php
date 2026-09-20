<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

$title_en_missing = db_table_exists($conn, 'islamiyya_books') && !db_column_exists($conn, 'islamiyya_books', 'title_en');
if (db_table_exists($conn, 'islamiyya_books') === false) {
    $missing[] = 'islamiyya_books (table — run db_migrate13 first)';
} elseif ($title_en_missing) {
    $missing[] = 'islamiyya_books.title_en (column)';
}

$total_type = db_table_exists($conn, 'islamiyya_books') ? strtolower(db_column_type($conn, 'islamiyya_books', 'total_lessons')) : '';
if (db_table_exists($conn, 'islamiyya_books') && $total_type !== '' && strpos($total_type, 'default') === false && strpos($total_type, 'int') === false) {
    $missing[] = 'islamiyya_books.total_lessons (type)';
}

if (db_table_exists($conn, 'islamiyya_interests') === false) $missing[] = 'islamiyya_interests (table)';

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. islamiyya_books.title_en — the admin manager reads/writes it,
       but db_migrate13 never created it, so Book save fails without it. */
    if (!db_table_exists($conn, 'islamiyya_books')) {
        $steps[] = ['islamiyya_books.title_en (column)', 'ERROR — islamiyya_books table missing, run db_migrate13 first'];
    } elseif (!db_column_exists($conn, 'islamiyya_books', 'title_en')) {
        try {
            $conn->query("ALTER TABLE islamiyya_books ADD COLUMN title_en VARCHAR(200) NULL AFTER title");
            $steps[] = ['islamiyya_books.title_en (column)', 'added'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_books.title_en (column)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_books.title_en (column)', 'already present'];
    }

    /* 2. total_lessons may stay 0 = TBD until uploads clarify the scope.
       Ensure the column accepts 0 with a 0 default (no data is touched). */
    if (!db_table_exists($conn, 'islamiyya_books')) {
        $steps[] = ['islamiyya_books.total_lessons (TBD support)', 'ERROR — islamiyya_books table missing, run db_migrate13 first'];
    } else {
        try {
            $conn->query("ALTER TABLE islamiyya_books MODIFY total_lessons INT NOT NULL DEFAULT 0");
            $steps[] = ['islamiyya_books.total_lessons (TBD support)', 'allows 0 = TBD (existing values kept)'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_books.total_lessons (TBD support)', 'ERROR — ' . $e->getMessage()];
        }
    }

    /* 3. islamiyya_interests — per-book "Save my slot" (notify-me) table. */
    if (!db_table_exists($conn, 'islamiyya_interests')) {
        try {
            $conn->query("
                CREATE TABLE islamiyya_interests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    book_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT NOW(),
                    UNIQUE KEY uq_student_book (student_id, book_id),
                    KEY idx_book (book_id),
                    KEY idx_student (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['islamiyya_interests (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_interests (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_interests (table)', 'already present'];
    }
}

$missing_after = [];
if (db_table_exists($conn, 'islamiyya_books') === false) {
    $missing_after[] = 'islamiyya_books';
} elseif (!db_column_exists($conn, 'islamiyya_books', 'title_en')) {
    $missing_after[] = 'islamiyya_books.title_en';
}
if (db_table_exists($conn, 'islamiyya_interests') === false) $missing_after[] = 'islamiyya_interests';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Islamiyya Repair — Database Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Digital Islamiyya Repair Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Digital Islamiyya Repair</h1>
    <p>Fixes the Book Manager save crash (adds the missing <code>islamiyya_books.title_en</code> column that <code>db_migrate13</code> never created), allows <code>total_lessons = 0</code> meaning <strong>TBD until uploads are done</strong>, and creates the per-book <code>islamiyya_interests</code> table behind the student's "Save my slot" button. Safe to run again — every step checks existence first. Note: <code>db_migrate14</code> is a separate announcements-emoji migration and is left untouched.</p>
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
                                <?php elseif (strncmp($st[1], 'already', 7) === 0): ?>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> Islamiyya repair complete. Book saving, TBD lesson counts and slot-saving are ready.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-ghost" href="islamiyya.php"><?= ui_icon('book-open', 16) ?> Book Manager</a>
            <a class="btn btn-ghost" href="dashboard.php"><?= ui_icon('grid', 16) ?> Dashboard</a>
        </div>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the Islamiyya repair schema is already in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                Without <code>title_en</code>, saving any book from the Book Manager fails. Without
                <code>islamiyya_interests</code>, the student's "Save my slot" button has nowhere to store interest.
            </p>
            <form method="POST" onsubmit="return confirm('Run the Digital Islamiyya repair migration now?');">
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
