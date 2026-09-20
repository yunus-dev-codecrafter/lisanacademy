<?php  
require '../config/security/helpers.php';  
require_role('student');  
require '../auth/auth_check.php';  
require '../config/db.php';  

$student_id = (int)$_SESSION['user_id']; 

/* Fetch all feedback for the student */
$q = $conn->query("
    (
        SELECT   
            sr.id,
            sr.rating,
            sr.feedback,
            sr.admin_audio_feedback,
            sr.status,
            l.from_verse,
            l.to_verse,
            s.name_en AS surah_name,
            'audio' AS type
        FROM student_recitation sr
        JOIN lessons l ON l.id = sr.learning_plan_id
        JOIN surahs s ON s.id = l.surah_id
        WHERE sr.student_id = $student_id
          AND sr.student_deleted = 0
    )

    UNION ALL

    (
        SELECT
            lr.id,
            NULL AS rating,
            CONCAT(
                'Live recitation scheduled for ',
                lr.preferred_date,
                ' at ',
                lr.preferred_time
            ) AS feedback,
            NULL AS admin_audio_feedback,
            lr.status,
            l.from_verse,
            l.to_verse,
            s.name_en AS surah_name,
            'live' AS type
        FROM live_recitation_requests lr
        JOIN lessons l ON l.id = lr.lesson_id
        JOIN surahs s ON s.id = l.surah_id
        WHERE lr.student_id = $student_id
          AND lr.status IN ('accepted','rejected')
    )

    ORDER BY id DESC
");

/* If the student has unseen accepted/rejected feedback, show the matching
   confirmation page first so they acknowledge the result, then the full list. */
$unseen = $conn->prepare("
    SELECT id, status FROM student_recitation
    WHERE student_id = ? AND student_deleted = 0
      AND status IN ('accepted','rejected') AND feedback_seen = 0
    ORDER BY id DESC LIMIT 1
");
$unseen->bind_param("i", $student_id);
$unseen->execute();
$unseen_row = $unseen->get_result()->fetch_assoc();
if ($unseen_row) {
    header('Location: recitation_' . $unseen_row['status'] . '.php?id=' . (int)$unseen_row['id']);
    exit;
}
?>

<!DOCTYPE html>  
<html>  
<head>  
<title>Admin Feedback</title>  
<meta name="viewport" content="width=device-width, initial-scale=1.0">  
<?= ui_css() ?>
</head>  
<?php ui_page_start('student', 'feedback', 'Feedback', 'Your Reviews'); ?>

<div class="page-hero animate-rise">
    <h1>Admin Feedback on Your Recitations</h1>
    <p>Guidance from your teacher to help you perfect your recitation.</p>
</div>

<?php if ($q->num_rows === 0): ?>  
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('chat', 40) ?></div>
        <div class="empty-title">No feedback available</div>
        <p class="small" style="margin:0;">Feedback from your teacher will appear here.</p>
    </div>
<?php endif; ?>  

<?php while ($row = $q->fetch_assoc()): ?>  

<div class="feedback-card animate-rise d1">  
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px;">
        <span class="type-badge <?= $row['type'] === 'live' ? 'live' : 'audio' ?>"><?= strtoupper($row['type']) ?> RECITATION</span>
        <span class="status <?= $row['status'] === 'accepted' ? 'status-accepted' : 'status-rejected' ?>"><?= strtoupper($row['status']) ?></span>
    </div>

    <h3 style="margin:4px 0;"><?= htmlspecialchars($row['surah_name']) ?></h3>
    <p class="small text-muted" style="margin:0 0 10px;">Verses <?= (int)$row['from_verse'] ?> – <?= (int)$row['to_verse'] ?></p>

    <?php if ($row['rating'] !== null): ?>
        <p class="small" style="margin:0 0 6px;"><strong>Rating:</strong> <?= htmlspecialchars($row['rating']) ?></p>
    <?php endif; ?>

    <p style="margin:0 0 6px;"><strong>Admin Feedback:</strong><br>  
        <?= nl2br(htmlspecialchars($row['feedback'])) ?>  
    </p>  

    <?php if (!empty($row['admin_audio_feedback']) && file_exists(__DIR__ . '/../uploads/admin_feedback/' . $row['admin_audio_feedback'])): ?>
        <p class="small" style="margin:10px 0 4px;"><strong>Audio Feedback:</strong></p>
        <audio controls>
            <source src="../uploads/admin_feedback/<?= htmlspecialchars($row['admin_audio_feedback']) ?>" type="audio/mpeg">
            Your browser does not support audio playback.
        </audio>
    <?php elseif ($row['type'] === 'audio'): ?>
        <p class="small text-muted" style="margin-top:10px;">No audio feedback yet.</p>
    <?php endif; ?>

    <?php if ($row['type'] === 'audio'): ?>
    <form method="post" action="delete_feedback.php"
          onsubmit="return confirm('Delete this feedback?');" style="margin-top:14px;">  
        <input type="hidden" name="recitation_id" value="<?= (int)$row['id'] ?>">  
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-danger"><?= ui_icon('trash', 15) ?> Delete Feedback</button>  
    </form>
    <?php endif; ?>

</div>  

<?php endwhile; ?>  

<?php ui_page_end(); ?>

</body>  
</html>