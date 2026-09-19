<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

if (!db_column_exists($conn, 'users', 'islamiyya_subscribed'))   $missing[] = 'users.islamiyya_subscribed (column)';
if (db_table_exists($conn, 'islamiyya_books') === false)        $missing[] = 'islamiyya_books (table)';
if (db_table_exists($conn, 'islamiyya_lessons') === false)      $missing[] = 'islamiyya_lessons (table)';
if (db_table_exists($conn, 'islamiyya_questions') === false)    $missing[] = 'islamiyya_questions (table)';
if (db_table_exists($conn, 'islamiyya_book_progress') === false) $missing[] = 'islamiyya_book_progress (table)';
if (db_table_exists($conn, 'islamiyya_lesson_progress') === false) $missing[] = 'islamiyya_lesson_progress (table)';

$pending = count($missing);

/* ============ BOOK SEED ============ */
/* Digital Islamiyya catalog — the books a student can learn, taken from
   "Digital_Islamiiyya_books.txt". Seed runs only when the table is empty.
   title  => displayed book name (Arabic + transliteration)
   author => subject label (Tauhid / Fiqhu / Hadith / Seerah / Arabic)
   media  => audio | video */
$BOOK_SEED = [
    ['title' => 'الأصول الثلاثة',            'author' => 'Tauhid',             'media' => 'audio'],
    ['title' => 'متن الأخضري',               'author' => 'Fiqhu',              'media' => 'audio'],
    ['title' => 'الأربعون النووية',          'author' => 'Hadith',             'media' => 'audio'],
    ['title' => 'أرجوزة الميئية',            'author' => 'Seerah',             'media' => 'audio'],
    ['title' => 'لغة العربية',               'author' => 'Arabic',             'media' => 'audio'],
];

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. users.islamiyya_subscribed (admin toggles => student is subscribed to Digital Islamiyya) */
    if (!db_column_exists($conn, 'users', 'islamiyya_subscribed')) {
        try {
            $conn->query("ALTER TABLE users ADD COLUMN islamiyya_subscribed TINYINT(1) NOT NULL DEFAULT 0 AFTER hafiz");
            $steps[] = ['users.islamiyya_subscribed (column)', 'added'];
        } catch (Throwable $e) {
            $steps[] = ['users.islamiyya_subscribed (column)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['users.islamiyya_subscribed (column)', 'already present'];
    }

    /* 2. islamiyya_books table */
    if (!db_table_exists($conn, 'islamiyya_books')) {
        try {
            $conn->query("
                CREATE TABLE islamiyya_books (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(200) NOT NULL,
                    author VARCHAR(150) NULL,
                    description TEXT NULL,
                    cover_image VARCHAR(255) NULL,
                    media_type ENUM('audio','video') NOT NULL DEFAULT 'audio',
                    total_lessons INT NOT NULL DEFAULT 0,
                    pdf_file VARCHAR(255) NULL,
                    status ENUM('coming_soon','live') NOT NULL DEFAULT 'coming_soon',
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT NOW(),
                    UNIQUE KEY uq_title (title)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['islamiyya_books (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_books (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_books (table)', 'already present'];
    }

    /* 3. islamiyya_lessons table */
    if (!db_table_exists($conn, 'islamiyya_lessons')) {
        try {
            $conn->query("
                CREATE TABLE islamiyya_lessons (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    book_id INT NOT NULL,
                    lesson_no INT NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    media_type ENUM('audio','video') NOT NULL,
                    media_file VARCHAR(255) NOT NULL,
                    duration_seconds INT NULL,
                    created_at DATETIME NOT NULL DEFAULT NOW(),
                    UNIQUE KEY uq_book_lesson (book_id, lesson_no),
                    KEY idx_book (book_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['islamiyya_lessons (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_lessons (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_lessons (table)', 'already present'];
    }

    /* 4. islamiyya_questions table (auto-graded MCQ per lesson) */
    if (!db_table_exists($conn, 'islamiyya_questions')) {
        try {
            $conn->query("
                CREATE TABLE islamiyya_questions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    lesson_id INT NOT NULL,
                    question_text TEXT NOT NULL,
                    option_a VARCHAR(255) NOT NULL,
                    option_b VARCHAR(255) NOT NULL,
                    option_c VARCHAR(255) NOT NULL,
                    option_d VARCHAR(255) NOT NULL,
                    correct_option ENUM('a','b','c','d') NOT NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    KEY idx_lesson (lesson_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['islamiyya_questions (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_questions (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_questions (table)', 'already present'];
    }

    /* 5. islamiyya_book_progress table */
    if (!db_table_exists($conn, 'islamiyya_book_progress')) {
        try {
            $conn->query("
                CREATE TABLE islamiyya_book_progress (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    book_id INT NOT NULL,
                    status ENUM('active','completed') NOT NULL DEFAULT 'active',
                    started_at DATETIME NOT NULL DEFAULT NOW(),
                    completed_at DATETIME NULL,
                    UNIQUE KEY uq_student_book (student_id, book_id),
                    KEY idx_student (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['islamiyya_book_progress (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_book_progress (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_book_progress (table)', 'already present'];
    }

    /* 6. islamiyya_lesson_progress table */
    if (!db_table_exists($conn, 'islamiyya_lesson_progress')) {
        try {
            $conn->query("
                CREATE TABLE islamiyya_lesson_progress (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    book_id INT NOT NULL,
                    lesson_no INT NOT NULL,
                    best_score DECIMAL(5,2) NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    status ENUM('unlocked','completed') NOT NULL DEFAULT 'unlocked',
                    completed_at DATETIME NULL,
                    UNIQUE KEY uq_student_book_lesson (student_id, book_id, lesson_no),
                    KEY idx_student (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['islamiyya_lesson_progress (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_lesson_progress (table)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_lesson_progress (table)', 'already present'];
    }

    /* 7. Seed the catalog from $BOOK_SEED (only when the table is empty). */
    if (db_table_exists($conn, 'islamiyya_books')) {
        try {
            $cnt = (int)$conn->query("SELECT COUNT(*) c FROM islamiyya_books")->fetch_assoc()['c'];
            if ($cnt === 0 && count($BOOK_SEED) > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO islamiyya_books (title, author, media_type, status, sort_order, description)
                    VALUES (?, ?, ?, 'coming_soon', ?, ?)
                ");
                $i = 1;
                foreach ($BOOK_SEED as $b) {
                    $desc = 'Digital Islamiyya — ' . $b['author'] . ' program. Lessons are being prepared and will be available soon.';
                    $stmt->bind_param("sssis", $b['title'], $b['author'], $b['media'], $i, $desc);
                    $stmt->execute();
                    $i++;
                }
                $steps[] = ['islamiyya_books seed', count($BOOK_SEED) . ' books inserted (coming soon)'];
            } else {
                $steps[] = ['islamiyya_books seed', 'skipped (table not empty — ' . $cnt . ' books)'];
            }
        } catch (Throwable $e) {
            $steps[] = ['islamiyya_books seed', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['islamiyya_books seed', 'skipped (islamiyya_books missing)'];
    }
}

$missing_after = [];
if (!db_column_exists($conn, 'users', 'islamiyya_subscribed')) $missing_after[] = 'users.islamiyya_subscribed';
if (db_table_exists($conn, 'islamiyya_books') === false)       $missing_after[] = 'islamiyya_books';
if (db_table_exists($conn, 'islamiyya_lessons') === false)     $missing_after[] = 'islamiyya_lessons';
if (db_table_exists($conn, 'islamiyya_questions') === false)   $missing_after[] = 'islamiyya_questions';
if (db_table_exists($conn, 'islamiyya_book_progress') === false) $missing_after[] = 'islamiyya_book_progress';
if (db_table_exists($conn, 'islamiyya_lesson_progress') === false) $missing_after[] = 'islamiyya_lesson_progress';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Islamiyya — Database Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Digital Islamiyya Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Digital Islamiyya</h1>
    <p>Adds the <code>users.islamiyya_subscribed</code> flag plus five new tables (<code>islamiyya_books</code>, <code>islamiyya_lessons</code>, <code>islamiyya_questions</code>, <code>islamiyya_book_progress</code>, <code>islamiyya_lesson_progress</code>) for the book-based learning program, and seeds the starting catalog. Safe to run again — every step checks existence first.</p>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place. Digital Islamiyya is ready to manage.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the Digital Islamiyya schema is already in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                This migration powers the <strong>Digital Islamiyya</strong> book-based learning program: the subscription flag,
                the book catalog, per-book lessons, auto-graded MCQ questions, and per-student progress tracking.
            </p>
            <?php if (count($BOOK_SEED) > 0): ?>
                <p class="small" style="margin-top:6px;"><?= ui_icon('book', 14) ?> The catalog will be seeded with <strong><?= count($BOOK_SEED) ?></strong> book(s): <?= htmlspecialchars(implode(', ', array_map(fn($b) => $b['title'] . ' (' . $b['author'] . ')', $BOOK_SEED))) ?> — all marked <em>Coming Soon</em>.</p>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Run the Digital Islamiyya schema migration now?');">
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