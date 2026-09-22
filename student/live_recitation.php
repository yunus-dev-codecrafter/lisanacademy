<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');

if (student_is_hafiz($conn, (int)($_SESSION['user_id'] ?? 0))) {
    header("Location: hafiz_revision.php");
    exit;
}

if (student_is_memorizing($conn, (int)($_SESSION['user_id'] ?? 0))) {
    header("Location: quran_memorization.php");
    exit;
}

if (student_in_exam($conn, (int)($_SESSION['user_id'] ?? 0))) {
    header("Location: exam.php");
    exit;
}

if (student_exam_locked($conn, (int)$_SESSION['user_id'])) {
    header("Location: exam_defaulted.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];

/* =========================
   CONFIRM STUDENT COMPLETED LESSON
========================= */
$lesson = $conn->query("
    SELECT 
        aa.learning_plan_id AS lesson_id,
        s.name_en AS surah_name
    FROM admin_audio aa
    JOIN lessons l ON l.id = aa.learning_plan_id
    JOIN surahs s ON s.id = l.surah_id
    WHERE aa.student_id = $student_id
      AND aa.acknowledged = 1
    ORDER BY aa.sent_at DESC
    LIMIT 1
")->fetch_assoc();

if (!$lesson) {
    die('Access denied. Complete your lesson first.');
}

/* =========================
   STUDENT INFO
========================= */
$stmt = $conn->prepare("SELECT name FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

/* =========================
   HANDLE LIVE REQUEST SUBMISSION
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day  = $_POST['day']  ?? '';
    $time = $_POST['time'] ?? '';

    if (!$day || !$time) {
        die('Invalid request');
    }

    $stmt = $conn->prepare("
        INSERT INTO live_recitation_requests
        (student_id, lesson_id, preferred_date, preferred_time)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "iiss",
        $student_id,
        $lesson['lesson_id'],
        $day,
        $time
    );
    $stmt->execute();

    /* WhatsApp redirect */
    $whatsapp = setting($conn, 'whatsapp_number', '2348029979040');
    $msg = urlencode(
        "Assalamu alaikum warahmatullah Ustadh.\n" .
        "I would like to recite my new lesson (" . $lesson['surah_name'] . ") on " .
        $day . " at " . $time . "."
    );

    header("Location: https://wa.me/$whatsapp?text=$msg");
    exit;
}

/* =========================
   FETCH FEEDBACK (normal + live)
========================= */
$feedbacks = $conn->query("
    SELECT 
        sr.id,
        sr.rating,
        sr.feedback,
        sr.status,
        l.from_verse,
        l.to_verse,
        s.name_en AS surah_name,
        'normal' AS type
    FROM student_recitation sr
    JOIN lessons l ON l.id = sr.learning_plan_id
    JOIN surahs s ON s.id = l.surah_id
    WHERE sr.student_id = $student_id
      AND sr.student_deleted = 0

    UNION ALL

    SELECT
        lr.id,
        lr.rating,
        NULL AS feedback,
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
    
    ORDER BY id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Recitation & Feedback</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'dashboard', 'Live Recitation', 'Schedule'); ?>

<div class="animate-rise" style="max-width:560px;margin:0 auto;">

    <div class="card" style="padding:30px;border-radius:var(--radius-lg);">
        <div class="text-center" style="margin-bottom:14px;">
            <span class="live-icon"><?= ui_icon('video', 34) ?></span>
            <h2 style="margin:8px 0 4px;">Live Qur’an Recitation</h2>
        </div>

        <p class="small" style="text-align:center;">
            Assalamu alaikum <strong><?=htmlspecialchars($student['name'])?></strong><br>
            You are about to recite <strong><?=htmlspecialchars($lesson['surah_name'])?></strong> live with your teacher.
        </p>

        <form method="POST">
            <div class="form-group">
                <label class="form-label" for="day">Preferred Day</label>
                <input class="form-input" type="date" id="day" name="day" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="time">Preferred Time</label>
                <input class="form-input" type="time" id="time" name="time" required>
            </div>

            <button type="submit" class="btn btn-gold btn-block btn-lg"><?= ui_icon('phone', 17) ?> Schedule via WhatsApp</button>
        </form>
    </div>

    <!-- =========================
         FEEDBACK SECTION
    ========================= -->
    <?php if($feedbacks && $feedbacks->num_rows > 0): ?>
        <h2 class="mt-3" style="text-align:center;">Your Recitation Feedback</h2>
        <?php while($f = $feedbacks->fetch_assoc()): ?>
            <div class="feedback-card animate-rise">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px;">
                    <h4 style="margin:0;"><?= htmlspecialchars($f['surah_name']) ?> (<?= (int)$f['from_verse'] ?>–<?= (int)$f['to_verse'] ?>)</h4>
                    <span class="type-badge <?= $f['type'] === 'live' ? 'live' : 'audio' ?>"><?= ucfirst($f['type']) ?></span>
                </div>
                <p style="margin:4px 0;">Status: <span class="status <?= $f['status'] === 'accepted' ? 'status-accepted' : 'status-rejected' ?>"><?= strtoupper($f['status']) ?></span></p>
                <?php if(!empty($f['rating'])): ?>
                    <p style="margin:4px 0;"><strong>Rating:</strong> <?= htmlspecialchars($f['rating']) ?></p>
                <?php endif; ?>
                <?php if(!empty($f['feedback'])): ?>
                    <p style="margin:6px 0 0;"><strong>Admin Feedback:</strong><br><?= nl2br(htmlspecialchars($f['feedback'])) ?></p>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p class="text-center small text-muted mt-3">No feedback available yet.</p>
    <?php endif; ?>
</div>

<?php ui_page_end(); ?>

</body>
</html>