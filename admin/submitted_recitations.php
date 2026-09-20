<?php
require '../config/security/helpers.php';
require_role('admin');
include '../auth/auth_check.php';
include '../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

// Fetch all pending recitations with student info and surah name
$sql = "
SELECT sr.*, u.name, u.email, s.name_en AS surah_name
FROM student_recitation sr
JOIN users u ON u.id = sr.student_id
JOIN lessons l ON l.id = sr.learning_plan_id
JOIN surahs s ON s.id = l.surah_id
WHERE sr.status='pending'
  AND sr.student_deleted = 0
ORDER BY sr.submitted_at ASC
";
$recitations = $conn->query($sql);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Review Student Recitations</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'teaching', 'Pending Recitations', 'Teaching'); ?>

<div class="page-hero animate-rise">
    <h1>Pending Student Recitations</h1>
    <p>Review each submission and return feedback.</p>
</div>

<?php if($recitations->num_rows === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('check-circle', 40) ?></div>
        <div class="empty-title">All caught up!</div>
        <p class="small" style="margin:0;">No pending submissions at the moment.</p>
    </div>
<?php endif; ?>

<?php while($r = $recitations->fetch_assoc()): ?>
<div class="card animate-rise">
    <div class="card-title" style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;">
        <h3 style="margin:0;"><?= htmlspecialchars($r['name']) ?></h3>
        <span class="small text-muted"><?= htmlspecialchars($r['email']) ?></span>
    </div>
    <p class="small">
        <span class="badge badge-green"><?= htmlspecialchars($r['surah_name'] ?: 'Unknown') ?></span>
        &nbsp;· Submitted <?= date('d M Y, H:i', strtotime($r['submitted_at'])) ?>
    </p>

    <?= media_player_html($r['audio_file'], '../uploads/student_audio/') ?>

    <form method="POST" action="review_recitation.php" style="margin-top:6px;">
        <input type="hidden" name="rec_id" value="<?= $r['id'] ?>">

        <div class="form-group">
            <label class="form-label">Rating</label>
            <select class="form-select" name="rating" required>
                <option value="">--Select--</option>
                <option value="Excellent">Excellent (A)</option>
                <option value="Very Good">Very Good (B)</option>
                <option value="Good">Good (C)</option>
                <option value="Fair">Fair (D)</option>
                <option value="Needs Improvement">Needs Improvement (E)</option>
                <option value="Fail">Fail (F)</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Feedback / Corrections</label>
            <textarea class="form-textarea" name="feedback" placeholder="Write comments or corrections here..."></textarea>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" name="status" value="accepted" class="btn">Accept</button>
            <button type="submit" name="status" value="rejected" class="btn btn-danger">Reject</button>
        </div>
    </form>
</div>
<?php endwhile; ?>

<?php ui_page_end(); ?>

</body>
</html>