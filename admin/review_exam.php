<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('admin');

$attempt_id = (int)($_GET['id'] ?? 0);
if ($attempt_id <= 0) redirect('exams.php');

/* ============ RATING HELPERS ============ */
$rating_values = ['Excellent' => 100, 'Very Good' => 85, 'Good' => 70, 'Fair' => 55, 'Needs Improvement' => 40, 'Fail' => 0];

function rating_from_score($score) {
    if ($score >= 90) return 'Excellent';
    if ($score >= 75) return 'Very Good';
    if ($score >= 60) return 'Good';
    if ($score >= 45) return 'Fair';
    if ($score >= 30) return 'Needs Improvement';
    return 'Fail';
}

/* ============ HANDLE GRADING ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $attempt = $conn->query("SELECT id, status, student_id FROM exam_attempts WHERE id = $attempt_id")->fetch_assoc();
    if (!$attempt || $attempt['status'] !== 'pending') {
        redirect('exams.php');
    }

    $ratings = $_POST['rating'] ?? [];
    $feedbacks = $_POST['feedback'] ?? [];
    $overall_feedback = trim($_POST['overall_feedback'] ?? '');

    $scores = [];
    foreach ($ratings as $answer_id => $rating) {
        $answer_id = (int)$answer_id;
        $rating = in_array($rating, array_keys($rating_values), true) ? $rating : 'Fair';
        $feedback = trim($feedbacks[$answer_id] ?? '');
        $scores[] = $rating_values[$rating];

        $stmt = $conn->prepare("UPDATE exam_answers SET rating = ?, feedback = ? WHERE id = ? AND attempt_id = ?");
        $stmt->bind_param("ssii", $rating, $feedback, $answer_id, $attempt_id);
        $stmt->execute();
    }

    $overall_score = $scores ? (int)round(array_sum($scores) / count($scores)) : 0;
    $overall_rating = rating_from_score($overall_score);

    /* Acceptance rule: max 3 mistakes allowed. More than 3 = must retake. */
    $mistakes = (int)($_POST['mistakes_count'] ?? 0);
    if ($mistakes < 0) $mistakes = 0;
    if ($mistakes > 99) $mistakes = 99;
    $decision = $mistakes > 3 ? 'rejected' : 'approved';

    $stmt = $conn->prepare("
        UPDATE exam_attempts
        SET status = ?, mistakes_count = ?, overall_rating = ?, overall_score = ?, overall_feedback = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("sisisi", $decision, $mistakes, $overall_rating, $overall_score, $overall_feedback, $attempt_id);
    $stmt->execute();

    /* Approved exam => student may resume normal lessons (clears any default flags) */
    if ($decision === 'approved' && !empty($attempt['student_id'])) {
        $conn->query("UPDATE users SET exam_defaulted = 0, exam_owed = 0, exam_access = 0 WHERE id = " . (int)$attempt['student_id']);
    }

    /* Rejected exam => student must retake: open the exam for them only, no fee,
       and keep normal lessons locked until their retake is approved. */
    if ($decision === 'rejected' && !empty($attempt['student_id'])) {
        $conn->query("UPDATE users SET exam_defaulted = 1, exam_owed = 0, exam_access = 1 WHERE id = " . (int)$attempt['student_id']);
    }

    redirect('exams.php');
}

/* ============ FETCH ATTEMPT ============ */
try {
    $attempt = $conn->query("
        SELECT e.*, u.name, u.email
        FROM exam_attempts e
        JOIN users u ON u.id = e.student_id
        WHERE e.id = $attempt_id
    ")->fetch_assoc();

    if (!$attempt) redirect('exams.php');

    $answers = $conn->query("
        SELECT a.*, s.name_en AS surah_name, s.name_ar AS surah_name_ar
        FROM exam_answers a
        JOIN surahs s ON s.id = a.surah_id
        WHERE a.attempt_id = $attempt_id
        ORDER BY a.id ASC
    ");
} catch (mysqli_sql_exception $e) {
    /* Don't die with a blank 500 — show the schema problem so it can be fixed. */
    http_response_code(500);
    echo '<div class="alert alert-danger" style="max-width:780px;margin:20px auto;">'
       . '<strong>Could not load this exam review.</strong><br>'
       . htmlspecialchars($e->getMessage())
       . '</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review Exam — <?= htmlspecialchars($attempt['name']) ?></title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'exams', 'Review Exam', 'Exams'); ?>

<div class="page-hero animate-rise">
    <h1><?= htmlspecialchars($attempt['name']) ?></h1>
    <p><?= htmlspecialchars($attempt['email']) ?> · <?= htmlspecialchars(exam_term_label($attempt['term_id'] ?? 0, $conn)) ?> · Submitted <?= date('d M Y H:i', strtotime($attempt['submitted_at'])) ?></p>
    <span class="badge <?= $attempt['status'] === 'pending' ? 'badge-gold' : ($attempt['status'] === 'rejected' ? 'badge-red' : ($attempt['status'] === 'draft' ? 'badge-blue' : 'badge-green')) ?>">
        <?= $attempt['status'] === 'pending' ? 'Pending Review' : ($attempt['status'] === 'rejected' ? 'Retake Required' : ($attempt['status'] === 'draft' ? 'In Progress' : 'Accepted')) ?>
    </span>
    <?php if ($attempt['status'] === 'approved'): ?>
        <span class="badge badge-blue" style="margin-left:8px;">
            Overall: <?= htmlspecialchars($attempt['overall_rating']) ?> (<?= (int)$attempt['overall_score'] ?>%) · Mistakes: <?= (int)$attempt['mistakes_count'] ?>
        </span>
    <?php elseif ($attempt['status'] === 'rejected'): ?>
        <span class="badge badge-red" style="margin-left:8px;">
            Mistakes: <?= (int)$attempt['mistakes_count'] ?> / max 3 → retake
        </span>
    <?php endif; ?>
</div>

<?php if ($attempt['status'] !== 'pending'): ?>
    <div class="card <?= $attempt['status'] === 'rejected' ? 'card-danger' : '' ?> animate-rise d1">
        <h3 style="margin-top:0;"><?= $attempt['status'] === 'rejected' ? 'Retake Required' : 'Accepted' ?></h3>
        <?php if ($attempt['status'] === 'rejected'): ?>
            <p class="small" style="margin:0;"><?= ui_icon('alert', 16) ?> <strong><?= (int)$attempt['mistakes_count'] ?></strong> mistakes were found in the entire recitation (the maximum allowed is <strong>3</strong>). The student must retake this term's exam. The exam is already reopened for them.</p>
        <?php else: ?>
            <p class="small" style="margin:0;"><?= ui_icon('check-circle', 16) ?> The recitation had <strong><?= (int)$attempt['mistakes_count'] ?></strong> mistake(s) (max allowed: 3). The student has passed and may continue normal lessons.</p>
        <?php endif; ?>
    </div>
    <?php while ($a = $answers->fetch_assoc()): ?>
        <div class="card animate-rise d1">
            <div class="card-title">
                <h3 style="margin:0;">Question: Recite Surah <?= htmlspecialchars($a['surah_name']) ?><?php $ar = arabic_text($a['surah_name_ar'] ?? ''); if ($ar !== ''): ?> <span class="arabic"><?= htmlspecialchars($ar) ?></span><?php endif; ?> from verse <?= (int)$a['from_verse'] ?> to <?= (int)$a['to_verse'] ?></h3>
                <?php if ($a['audio_file']): ?>
                    <?= media_player_html($a['audio_file'], '../uploads/exam_audio/') ?>
                <?php endif; ?>
            </div>
            <p><strong>Rating:</strong> <?= htmlspecialchars($a['rating'] ?? '—') ?></p>
            <?php if (!empty($a['feedback'])): ?>
                <p><strong>Feedback:</strong><br><?= nl2br(htmlspecialchars($a['feedback'])) ?></p>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
    <?php if (!empty($attempt['overall_feedback'])): ?>
        <div class="card card-gold animate-rise d2">
            <h3 style="margin-top:0;">Overall Feedback</h3>
            <p style="margin:0;"><?= nl2br(htmlspecialchars($attempt['overall_feedback'])) ?></p>
        </div>
    <?php endif; ?>
    <div class="card card-danger animate-rise d2">
        <h3 style="margin-top:0;color:var(--danger);">Delete This Exam</h3>
        <p class="small" style="margin:0 0 10px;">Permanently remove this reviewed submission. The student's exam audio is deleted and their exam locks are cleared so they can be managed again.</p>
        <form method="POST" action="reset_exam_attempt.php" onsubmit="return confirm('Delete this reviewed exam permanently? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="attempt_id" value="<?= (int)$attempt_id ?>">
            <button class="btn btn-danger" type="submit"><?= ui_icon('trash', 15) ?> Delete Exam</button>
        </form>
    </div>
<?php else: ?>
    <form method="POST">
        <?= csrf_field() ?>
        <?php $i = 0; while ($a = $answers->fetch_assoc()): $i++; ?>
            <div class="card animate-rise d1">
                <div class="card-title" style="display:flex;align-items:center;gap:8px;">
                    <span class="badge badge-blue">Q<?= $i ?></span>
                    <h3 style="margin:0;">Recite Surah <?= htmlspecialchars($a['surah_name']) ?><?php $ar = arabic_text($a['surah_name_ar'] ?? ''); if ($ar !== ''): ?> <span class="arabic"><?= htmlspecialchars($ar) ?></span><?php endif; ?> from verse <?= (int)$a['from_verse'] ?> to <?= (int)$a['to_verse'] ?></h3>
                </div>

                <?php if ($a['audio_file']): ?>
                    <?= media_player_html($a['audio_file'], '../uploads/exam_audio/') ?>
                <?php else: ?>
                    <div class="alert alert-warning"><?= ui_icon('alert', 16) ?> No audio was uploaded for this question.</div>
                <?php endif; ?>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Rating</label>
                        <select class="form-select" name="rating[<?= (int)$a['id'] ?>]" required>
                            <option value="">--Select--</option>
                            <?php foreach (array_keys($rating_values) as $r): ?>
                                <option value="<?= $r ?>"><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Feedback / Corrections</label>
                    <textarea class="form-textarea" name="feedback[<?= (int)$a['id'] ?>]" placeholder="Write comments or corrections for this answer..."></textarea>
                </div>
            </div>
        <?php endwhile; ?>

<div class="card animate-rise d2">
    <h3 style="margin-top:0;">Acceptance Decision</h3>
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Total mistakes found in the entire recitation</label>
        <input class="form-input" type="number" name="mistakes_count" value="0" min="0" max="99" required style="max-width:180px;">
        <p class="small text-muted" style="margin-top:6px;">
            <?= ui_icon('info', 14) ?> Acceptance rule: if more than <strong>3 mistakes</strong> are found, the student
            must <strong>retake</strong> this term's exam. The system rejects a result automatically when
            mistakes &gt; 3.
        </p>
    </div>
</div>

<div class="card animate-rise d2">
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Overall Feedback (shown to the student)</label>
        <textarea class="form-textarea" name="overall_feedback" placeholder="Summary of the student's exam performance..."></textarea>
    </div>
</div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('check', 17) ?> Grade &amp; Release Result</button>
            <a class="btn btn-ghost btn-lg" href="exams.php">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>
