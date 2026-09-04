<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

/* Not on holiday anymore? Go back to the normal dashboard. */
if (!holiday_mode_on($conn)) {
    redirect('dashboard.php');
}

$info    = holiday_info($conn);
$days    = holiday_days_left($conn);
$resume  = holiday_resumption_label($conn);
$message = trim((string)$info['message']);

/* Latest graded exam result (approved or rejected) for the download link. */
$latest_attempt = null;
try {
    $stmt = $conn->prepare("SELECT id, status FROM exam_attempts WHERE student_id = ? AND status IN ('approved','rejected') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $latest_attempt = $stmt->get_result()->fetch_assoc();
} catch (Throwable $e) {
    $latest_attempt = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>School Holiday</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'holiday', 'School Holiday', 'Notice'); ?>

<div class="page-hero animate-rise">
    <h1><?= ui_icon('clock', 20) ?> We are currently on holiday</h1>
    <p>The academy is on a short break. Learning resumes on <strong><?= htmlspecialchars($resume !== '' ? $resume : 'a date to be announced') ?></strong>.</p>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-warning animate-rise d1">
        <?= ui_icon('bell', 18) ?>
        <span style="flex:1;"><?= nl2br(htmlspecialchars($message)) ?></span>
    </div>
<?php endif; ?>

<div class="stat-grid animate-rise d1">
    <div class="stat-card stat-gold">
        <span class="stat-ico"><?= ui_icon('clock') ?></span>
        <span class="stat-label">Holiday Mode</span>
        <span class="stat-value">ON</span>
        <span class="stat-sub">All learning activities are paused</span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-ico"><?= ui_icon('calendar') ?></span>
        <span class="stat-label">Resumption Date</span>
        <span class="stat-value" style="font-size:1.15rem;"><?= htmlspecialchars($resume !== '' ? $resume : 'TBA') ?></span>
        <span class="stat-sub">Classes start again on this day</span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-ico"><?= ui_icon('gift') ?></span>
        <span class="stat-label">Days Remaining</span>
        <span class="stat-value"><?= $days !== null ? $days : '—' ?></span>
        <span class="stat-sub">Enjoy your break!</span>
    </div>
</div>

<div class="card animate-rise d1" style="margin-top:18px;border:1px solid var(--gold-light);">
    <div class="card-title"><h3 style="margin-top:0;"><?= ui_icon('bulb', 18) ?> While you wait</h3></div>
    <p class="small text-muted" style="margin:0;">Lessons, recitations and exams are paused for the break, but you can still take care of a few things:</p>
</div>

<div class="grid-2 animate-rise d2">
    <?php if ($latest_attempt && $latest_attempt['status'] === 'rejected'): ?>
        <a class="action-card action-red" href="exam.php">
            <span class="ac-ico"><?= ui_icon('refresh') ?></span>
            <span class="ac-title">Retake Exam</span>
            <span class="ac-sub">Your last attempt needs a retake</span>
        </a>
    <?php endif; ?>

    <?php if ($latest_attempt): ?>
        <a class="action-card action-gold" href="exam_result.php?attempt=<?= (int)$latest_attempt['id'] ?>">
            <span class="ac-ico"><?= ui_icon('download') ?></span>
            <span class="ac-title">Download Exam Result</span>
            <span class="ac-sub">Print your latest result sheet</span>
        </a>
    <?php else: ?>
        <div class="action-card action-gold" style="opacity:.55;">
            <span class="ac-ico"><?= ui_icon('notes') ?></span>
            <span class="ac-title">Exam Result</span>
            <span class="ac-sub">No graded result available yet</span>
        </div>
    <?php endif; ?>

    <a class="action-card action-emerald" href="certificate.php">
        <span class="ac-ico"><?= ui_icon('gem') ?></span>
        <span class="ac-title">Download Certificate</span>
        <span class="ac-sub">View &amp; print your certificate</span>
    </a>

    <a class="action-card action-blue" href="invite.php">
        <span class="ac-ico"><?= ui_icon('users') ?></span>
        <span class="ac-title">Invite Friends</span>
        <span class="ac-sub">Earn 15% off next term fees</span>
    </a>

    <a class="action-card action-forest" href="donate.php">
        <span class="ac-ico"><?= ui_icon('gift') ?></span>
        <span class="ac-title">Donate</span>
        <span class="ac-sub">Support the academy's development</span>
    </a>

    <a class="action-card action-gold" href="fees.php">
        <span class="ac-ico"><?= ui_icon('send') ?></span>
        <span class="ac-title">Pay Next Term Fees</span>
        <span class="ac-sub">Arrange payment via WhatsApp</span>
    </a>

    <a class="action-card action-emerald" href="announcements.php">
        <span class="ac-ico"><?= ui_icon('bell') ?></span>
        <span class="ac-title">Announcements</span>
        <span class="ac-sub">Latest updates from the academy</span>
    </a>
</div>

<p class="small text-muted animate-rise d3" style="margin-top:18px;">
    Questions? Contact the academy on
    <a href="https://wa.me/<?= htmlspecialchars(setting($conn, 'whatsapp_number', '2348029979040')) ?>" target="_blank">WhatsApp</a>.
    See you after the break!
</p>

<?php ui_page_end(); ?>

</body>
</html>
