<?php
/* ================================================================
 * DIGITAL ISLAMIYYA — STUDENT LEARN PAGE
 * ----------------------------------------------------------------
 * Opened from a live catalog card (student/islamiyya.php). A book
 * only opens here when it is effectively live (admin published AND
 * every expected lesson uploaded AND every lesson has a quiz —
 * islamiyya_book_is_live()). Lessons unlock sequentially: Lesson 1
 * is always open; lesson N>1 needs lesson N-1 passed at >= 90%.
 * Each lesson's quiz is auto-graded on submit and stored in
 * islamiyya_lesson_progress. The all-lessons PDF handout unlocks
 * once Lesson 1 is passed.
 * ================================================================ */
require __DIR__ . '/../config/security/helpers.php';
require __DIR__ . '/../auth/auth_check.php';
require __DIR__ . '/../config/db.php';

require_role('student');

$student_id = (int)$_SESSION['user_id'];
$book_id = (int)($_GET['book'] ?? $_POST['book_id'] ?? 0);
$book = $book_id > 0 ? islamiyya_book($conn, $book_id) : null;

if (!$book || !islamiyya_book_is_live($conn, $book)) {
    ui_message_page('warning', 'Book Not Ready Yet',
        'This book is still being prepared by the admin. Save your slot in the catalog and we will keep you first in line.',
        'islamiyya.php', 'Back to Digital Islamiyya', 'book-open');
}

/* ------------------- quiz submit (own POST) ------------------- */
$quiz_msg = trim($_GET['m'] ?? '');
$quiz_err = trim($_GET['e'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $lesson_id = (int)($_POST['lesson_id'] ?? 0);
    $lesson = islamiyya_lesson($conn, $lesson_id);
    if (!$lesson || (int)$lesson['book_id'] !== $book_id) {
        header('Location: islamiyya_learn.php?book=' . $book_id . '&e=' . rawurlencode('Lesson not found.'));
        exit;
    }
    $lno = (int)$lesson['lesson_no'];
    if (!islamiyya_lesson_unlocked($conn, $student_id, $book_id, $lno)) {
        header('Location: islamiyya_learn.php?book=' . $book_id . '&e=' . rawurlencode('Complete Lesson ' . ($lno - 1) . ' first (score 90% or more).'));
        exit;
    }
    $questions = islamiyya_lesson_questions($conn, $lesson_id);
    if (empty($questions)) {
        header('Location: islamiyya_learn.php?book=' . $book_id . '&e=' . rawurlencode('This lesson has no quiz yet.'));
        exit;
    }
    $answers = $_POST['answers'] ?? [];
    $correct = 0;
    foreach ($questions as $qq) {
        $qid = (int)$qq['id'];
        $given = strtolower(trim((string)($answers[$qid] ?? '')));
        if ($given === strtolower((string)$qq['correct_option'])) $correct++;
    }
    $score = count($questions) > 0 ? round(($correct / count($questions)) * 100, 2) : 0;
    $passed = $score >= 90.0;

    /* ensure progress rows exist, then record the attempt */
    islamiyya_book_progress($conn, $student_id, $book_id);
    $prog = islamiyya_lesson_progress($conn, $student_id, $book_id, $lno);
    $best = $prog ? (float)($prog['best_score'] ?? 0) : 0.0;
    $new_best = max($best, (float)$score);
    $status = ($passed || ($prog && ($prog['status'] ?? '') === 'completed')) ? 'completed' : 'unlocked';
    try {
        if ($passed) {
            $stmt = $conn->prepare("UPDATE islamiyya_lesson_progress SET best_score=?, attempts=attempts+1, status='completed', completed_at=NOW() WHERE student_id=? AND book_id=? AND lesson_no=?");
            $stmt->bind_param("diii", $new_best, $student_id, $book_id, $lno);
        } else {
            $stmt = $conn->prepare("UPDATE islamiyya_lesson_progress SET best_score=?, attempts=attempts+1 WHERE student_id=? AND book_id=? AND lesson_no=?");
            $stmt->bind_param("diii", $new_best, $student_id, $book_id, $lno);
        }
        $stmt->execute();
    } catch (Throwable $e) { /* ignore — score display below still works */ }

    /* whole-book completion */
    if ($passed && islamiyya_current_lesson_no($conn, $student_id, $book_id) === null && db_table_exists($conn, 'islamiyya_book_progress')) {
        try {
            $stmt = $conn->prepare("UPDATE islamiyya_book_progress SET status='completed', completed_at=NOW() WHERE student_id=? AND book_id=?");
            $stmt->bind_param("ii", $student_id, $book_id);
            $stmt->execute();
        } catch (Throwable $e) { /* ignore */ }
    }

    if ($passed) {
        $quiz_msg = 'Lesson ' . $lno . ' passed — ' . $correct . '/' . count($questions) . ' correct (' . rtrim(rtrim(number_format($score, 2), '0'), '.') . '%). ' .
            (islamiyya_current_lesson_no($conn, $student_id, $book_id) === null ? 'Book completed — masha Allah!' : 'Lesson ' . ($lno + 1) . ' is now unlocked.');
    } else {
        $quiz_err = 'You scored ' . $correct . '/' . count($questions) . ' (' . rtrim(rtrim(number_format($score, 2), '0'), '.') . '%). You need 90% to unlock the next lesson — review the lesson and try again.';
    }
    header('Location: islamiyya_learn.php?book=' . $book_id .
        ($quiz_msg !== '' ? '&m=' . rawurlencode($quiz_msg) : '&e=' . rawurlencode($quiz_err)) .
        '#lesson-' . $lno);
    exit;
}

/* --------------------------- page state --------------------------- */
$lessons = islamiyya_book_lessons($conn, $book_id);
$pct = islamiyya_book_pct($conn, $student_id, $book_id);
$pdf_ready = islamiyya_pdf_unlocked($conn, $student_id, $book_id) && !empty($book['pdf_file']);
$current_no = islamiyya_current_lesson_no($conn, $student_id, $book_id);
$cover_file = trim((string)($book['cover_image'] ?? ''));
$cover_path = ($cover_file !== '' && is_file(__DIR__ . '/../uploads/islamiyya_covers/' . basename($cover_file)))
    ? '../uploads/islamiyya_covers/' . rawurlencode(basename($cover_file))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($book['title'], ENT_QUOTES) ?> — Digital Islamiyya</title>
<?= ui_css() ?>
</head>
<?php
ui_page_start('student', 'islamiyya', $book['title'], 'Digital Islamiyya');
?>

<div class="page-hero animate-rise">
    <h1><?= htmlspecialchars($book['title'], ENT_QUOTES) ?></h1>
    <p>
        <?php if (!empty($book['title_en'])): ?><?= htmlspecialchars($book['title_en'], ENT_QUOTES) ?> · <?php endif; ?>
        <?php if (!empty($book['author'])): ?><?= htmlspecialchars($book['author'], ENT_QUOTES) ?> · <?php endif; ?>
        <?= count($lessons) ?> lesson(s) · <?= $book['media_type'] === 'video' ? 'Video' : 'Audio' ?> lessons with quizzes
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
        <a class="btn btn-sm btn-ghost" href="islamiyya.php"><?= ui_icon('arrow-left', 14) ?> Back to Catalog</a>
    </div>
</div>

<?php if ($quiz_msg !== ''): ?>
    <div class="alert alert-success animate-rise"><?= ui_icon('check-circle', 16) ?> <span style="flex:1;"><?= htmlspecialchars($quiz_msg, ENT_QUOTES) ?></span></div>
<?php endif; ?>
<?php if ($quiz_err !== ''): ?>
    <div class="alert alert-warning animate-rise"><?= ui_icon('alert', 16) ?> <span style="flex:1;"><?= htmlspecialchars($quiz_err, ENT_QUOTES) ?></span></div>
<?php endif; ?>

<div class="card animate-rise d1" style="margin-bottom:16px;">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
        <?php if ($cover_path !== ''): ?>
            <img src="<?= htmlspecialchars($cover_path, ENT_QUOTES) ?>" alt="" style="width:72px;height:96px;object-fit:cover;border-radius:8px;">
        <?php else: ?>
            <div style="width:72px;height:96px;border-radius:8px;background:linear-gradient(135deg,var(--emerald-700),var(--emerald-500));display:flex;align-items:center;justify-content:center;color:#fff;"><?= ui_icon('book-open', 26) ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:220px;">
            <strong>Your progress — <?= $pct ?>%</strong>
            <div class="progress" style="margin:8px 0;max-width:340px;">
                <div class="progress-fill" style="width:<?= $pct ?>%;"></div>
                <div class="progress-text"><?= $pct ?>%</div>
            </div>
            <p class="small text-muted" style="margin:0;">
                <?= $current_no === null ? 'Book completed — masha Allah! Review any lesson below.' : 'You are on Lesson ' . $current_no . ' of ' . count($lessons) . '. Score 90%+ in each quiz to unlock the next lesson.' ?>
            </p>
        </div>
        <?php if ($pdf_ready): ?>
            <a class="btn btn-gold btn-sm" href="../uploads/islamiyya_pdfs/<?= htmlspecialchars($book['pdf_file'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= ui_icon('file-text', 15) ?> Lesson Handout (PDF)</a>
        <?php else: ?>
            <span class="small text-muted" title="Pass Lesson 1 to unlock the handout"><?= ui_icon('lock', 14) ?> Handout unlocks after Lesson 1</span>
        <?php endif; ?>
    </div>
    <?php if (!empty($book['description'])): ?><p class="small" style="margin:10px 0 0;"><?= htmlspecialchars($book['description'], ENT_QUOTES) ?></p><?php endif; ?>
</div>

<?php foreach ($lessons as $lesson): ?>
<?php
    $lid = (int)$lesson['id'];
    $lno = (int)$lesson['lesson_no'];
    $unlocked = islamiyya_lesson_unlocked($conn, $student_id, $book_id, $lno);
    $done = islamiyya_lesson_completed($conn, $student_id, $book_id, $lno);
    $prog = islamiyya_lesson_progress($conn, $student_id, $book_id, $lno);
    $best = $prog && $prog['best_score'] !== null ? (float)$prog['best_score'] : null;
    $questions = $unlocked ? islamiyya_lesson_questions($conn, $lid) : [];
?>
<div class="card animate-rise" id="lesson-<?= $lno ?>" style="margin-bottom:14px;<?= $unlocked ? '' : 'opacity:.75;' ?>">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <span class="badge <?= $done ? 'badge-green' : ($unlocked ? 'badge-gold' : 'badge-grey') ?>">
            <?= ui_icon($done ? 'check' : ($unlocked ? 'video' : 'lock'), 12) ?>
            Lesson <?= $lno ?><?= $done ? ' — passed' : ($unlocked ? '' : ' — locked') ?>
        </span>
        <h3 style="margin:0;flex:1;min-width:180px;"><?= htmlspecialchars($lesson['title'], ENT_QUOTES) ?></h3>
        <?php if ($best !== null): ?><span class="small text-muted">Best: <?= rtrim(rtrim(number_format($best, 2), '0'), '.') ?>%</span><?php endif; ?>
    </div>

    <?php if ($unlocked): ?>
        <div style="margin:10px 0;">
            <?php if ($lesson['media_type'] === 'video'): ?>
                <video controls preload="none" style="width:100%;max-width:640px;border-radius:8px;" src="../uploads/islamiyya_media/<?= htmlspecialchars($lesson['media_file'], ENT_QUOTES) ?>"></video>
            <?php else: ?>
                <audio controls preload="none" style="width:100%;max-width:640px;" src="../uploads/islamiyya_media/<?= htmlspecialchars($lesson['media_file'], ENT_QUOTES) ?>"></audio>
            <?php endif; ?>
        </div>

        <?php if (!empty($questions)): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="book_id" value="<?= $book_id ?>">
            <input type="hidden" name="lesson_id" value="<?= $lid ?>">
            <h4 style="margin:6px 0 8px;"><?= ui_icon('check-circle', 15) ?> Lesson <?= $lno ?> Quiz — <?= count($questions) ?> question(s), 90% to pass</h4>
            <?php foreach ($questions as $i => $qq): ?>
            <?php $qid = (int)$qq['id']; ?>
            <div style="margin-bottom:12px;padding:10px;border:1px solid var(--border);border-radius:8px;">
                <p style="margin:0 0 8px;"><strong><?= ($i + 1) ?>. <?= htmlspecialchars($qq['question_text'], ENT_QUOTES) ?></strong></p>
                <?php foreach (['a' => $qq['option_a'], 'b' => $qq['option_b'], 'c' => $qq['option_c'], 'd' => $qq['option_d']] as $opt => $txt): ?>
                <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:6px;cursor:pointer;">
                    <input type="radio" name="answers[<?= $qid ?>]" value="<?= $opt ?>" required style="margin-top:4px;">
                    <span><strong><?= strtoupper($opt) ?>.</strong> <?= htmlspecialchars($txt, ENT_QUOTES) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <button class="btn <?= $done ? 'btn-ghost' : 'btn-gold' ?>" type="submit"><?= ui_icon('send', 15) ?> <?= $done ? 'Retake Quiz' : 'Submit Quiz' ?></button>
        </form>
        <?php else: ?>
            <p class="small text-muted">Quiz coming soon for this lesson.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="small text-muted" style="margin:8px 0 0;"><?= ui_icon('lock', 14) ?> Locked — pass Lesson <?= $lno - 1 ?>'s quiz at 90% or more to unlock this lesson.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php ui_page_end(); ?>
</body>
</html>
