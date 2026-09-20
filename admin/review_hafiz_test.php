<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('admin');

$test_id = (int)($_GET['id'] ?? 0);
$message = '';
$error = '';

if ($test_id <= 0) {
    redirect('hafiz_tests.php');
}

if (!db_table_exists($conn, 'hafiz_weekly_tests') || !db_table_exists($conn, 'hafiz_test_answers')) {
    redirect('hafiz_tests.php');
}

/* ---- Fetch the test + student ---- */
$stmt = $conn->prepare("
    SELECT t.*, u.name AS student_name, u.email AS student_email
    FROM hafiz_weekly_tests t
    JOIN users u ON u.id = t.student_id
    WHERE t.id = ? LIMIT 1
");
$stmt->bind_param("i", $test_id);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();

if (!$test) {
    redirect('hafiz_tests.php');
}

$answers = hafiz_week_test_answers($conn, $test_id);

/* ---- Handle Pass / Fail review ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $decision = $_POST['decision'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');

    if (!in_array($decision, ['passed', 'failed'], true)) {
        $error = 'Invalid decision.';
    } elseif ($test['status'] !== 'submitted') {
        $error = 'This test has already been reviewed.';
    } else {
        // Optional admin audio feedback (observations / corrections)
        $admin_audio = null;
        if (isset($_FILES['admin_audio']) && $_FILES['admin_audio']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = dirname(__DIR__) . '/uploads/admin_feedback/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $res = audio_save_upload($_FILES['admin_audio']['tmp_name'], $upload_dir, 'test_feedback_' . $test_id . '_', $_FILES['admin_audio']['name']);
            if ($res['ok']) {
                $admin_audio = $res['file'];
            }
        }

        $now = date('Y-m-d H:i:s');
        if ($admin_audio) {
            $stmt = $conn->prepare("
                UPDATE hafiz_weekly_tests
                SET status = ?, admin_feedback = ?, admin_audio_file = ?, reviewed_at = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssssi", $decision, $feedback, $admin_audio, $now, $test_id);
        } else {
            $stmt = $conn->prepare("
                UPDATE hafiz_weekly_tests
                SET status = ?, admin_feedback = ?, admin_audio_file = NULL, reviewed_at = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssi", $decision, $feedback, $now, $test_id);
        }
        if ($stmt->execute()) {
            $message = $decision === 'passed'
                ? "Test marked PASSED. The student may now continue reciting."
                : "Test marked FAILED. The student must retake it before continuing.";
            // Refresh test
            $stmt = $conn->prepare("SELECT * FROM hafiz_weekly_tests WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $test_id);
            $stmt->execute();
            $test = $stmt->get_result()->fetch_assoc();
        } else {
            $error = 'Failed to save the review.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review Weekly Test — Hafiz</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Review Weekly Test', 'Hafiz Tests'); ?>

<div class="page-hero animate-rise">
    <h1><?= ui_icon('mic', 24) ?> Weekly Test Review</h1>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
        <span class="badge badge-blue">Juz <?= (int)$test['week_no'] ?></span>
        <?php if ($test['status'] === 'passed'): ?>
            <span class="badge badge-green">Passed</span>
        <?php elseif ($test['status'] === 'failed'): ?>
            <span class="badge badge-red">Failed</span>
        <?php elseif ($test['status'] === 'submitted'): ?>
            <span class="badge badge-gold">Awaiting Review</span>
        <?php else: ?>
            <span class="badge badge-grey"><?= ucfirst($test['status']) ?></span>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-success animate-rise"><?= ui_icon('check-circle', 16) ?> <?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger animate-rise"><?= ui_icon('close', 16) ?> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card animate-rise d1">
    <div class="card-title"><h3 style="margin:0;"><?= ui_icon('user', 17) ?> Student</h3></div>
    <p class="small" style="margin:0;"><strong><?= htmlspecialchars($test['student_name']) ?></strong> — <?= htmlspecialchars($test['student_email']) ?></p>
    <p class="small text-muted" style="margin:4px 0 0;">
        Started: <?= date('d M Y, g:i A', strtotime($test['started_at'])) ?>
        <?php if ($test['submitted_at']): ?> · Submitted: <?= date('d M Y, g:i A', strtotime($test['submitted_at'])) ?><?php endif; ?>
    </p>
</div>

<div class="card animate-rise d2">
    <div class="card-title"><h3 style="margin:0;"><?= ui_icon('list', 17) ?> Questions &amp; Recordings</h3></div>
    <?php if (!$answers): ?>
        <p class="small text-muted" style="margin:0;">No answer recordings found for this test.</p>
    <?php endif; ?>
    <?php foreach ($answers as $idx => $a): ?>
        <div class="panel" style="margin:.5rem 0;">
            <strong>Question <?= $idx + 1 ?>:</strong> Recite Surah <?= htmlspecialchars($a['surah_name']) ?>
            <?php $ar = arabic_text($a['surah_name_ar'] ?? ''); if ($ar !== ''): ?><span class="arabic"><?= htmlspecialchars($ar) ?></span><?php endif; ?>
            from verse <?= (int)$a['from_verse'] ?> to <?= (int)$a['to_verse'] ?>
            <?php if (!empty($a['audio_file'])): ?>
                <div style="margin-top:8px;">
                    <?= media_player_html($a['audio_file'], '../uploads/hafiz_test_audio/') ?>
                </div>
            <?php else: ?>
                <div style="margin-top:6px;"><span class="badge badge-grey">No recording</span></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($test['status'] === 'submitted'): ?>
<div class="card card-gold animate-rise d3" style="max-width:760px;">
    <div class="card-title"><h3 style="margin:0;"><?= ui_icon('gavel', 17) ?> Make a Decision</h3></div>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label">Decision</label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="decision" value="passed" required> <strong>Pass</strong> — student continues successfully</label>
                <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="decision" value="failed" required> <strong>Fail</strong> — student must retake before continuing</label>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Text Feedback (optional)</label>
            <textarea class="form-input" name="feedback" rows="2" placeholder="e.g. Beautiful tajweed on Surah Mulk. Work on the madd in verse 15."></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Audio Feedback (optional — upload a voice note with observations about where to correct)</label>
            <input class="file-input" type="file" name="admin_audio" accept="audio/*,.mp3,.m4a,.wav,.ogg,.webm,.aac">
        </div>

        <div class="alert alert-info" style="margin:0 0 12px;">
            <?= ui_icon('info', 15) ?>
            <span style="flex:1;">The audio feedback is optional. If the student fails, they will be asked to retake the test with new random questions. If they pass, the next Juz is unlocked for them.</span>
        </div>

        <button class="btn btn-gold btn-lg btn-block" type="submit" onclick="return confirm('Confirm this decision?');"><?= ui_icon('send', 17) ?> Submit Review</button>
    </form>
</div>
<?php elseif ($test['admin_feedback'] || $test['admin_audio_file']): ?>
<div class="card animate-rise d3">
    <div class="card-title"><h3 style="margin:0;"><?= ui_icon('chat', 17) ?> Given Feedback</h3></div>
    <?php if ($test['admin_feedback']): ?><div class="panel"><strong>Text feedback:</strong><br><?= nl2br(htmlspecialchars($test['admin_feedback'])) ?></div><?php endif; ?>
    <?php if ($test['admin_audio_file']): ?>
        <div class="panel"><strong>Audio notes:</strong>
            <div style="margin-top:8px;"><audio controls preload="none" style="width:100%;" src="../uploads/admin_feedback/<?= htmlspecialchars($test['admin_audio_file']) ?>"></audio></div>
        </div>
    <?php endif; ?>
    <?php if ($test['reviewed_at']): ?><p class="small text-muted" style="margin:8px 0 0;">Reviewed: <?= date('d M Y, g:i A', strtotime($test['reviewed_at'])) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
    <a class="btn btn-ghost" href="hafiz_tests.php"><?= ui_icon('arrow-left', 16) ?> All Weekly Tests</a>
    <a class="btn btn-ghost" href="teaching.php"><?= ui_icon('grid', 16) ?> Teaching</a>
</div>

<?php ui_page_end(); ?>

</body>
</html>