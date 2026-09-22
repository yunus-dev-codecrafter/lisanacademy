<?php
require '../config/security/helpers.php';
require_role('student');
require '../auth/auth_check.php';
require '../config/db.php';

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

// Validate POST input
if (!isset($_POST['surah_id']) || $_POST['surah_id'] <= 0) {
    exit('Invalid surah or learning plan.');
}

$surah_id = (int)$_POST['surah_id'];

/* The start_verse column may not exist yet in the database (the app creates it
   on demand, but ALTER can fail on some shared hosts). Never reference it in a
   query unless it is actually present, otherwise requesting a lesson errors. */
$has_start_verse = db_ensure_start_verse_column($conn);

/* Get total verses and verses_per_request from student_learning */
$stmt = $conn->prepare("
    SELECT sl.verses_per_request, s.total_verses, s.name_en"
    . ($has_start_verse ? ", sl.start_verse" : "") . "
    FROM student_learning sl
    JOIN surahs s ON s.id = sl.surah_id
    WHERE sl.student_id = ? AND sl.surah_id = ? AND sl.status = 'active'
    LIMIT 1
");
$stmt->bind_param("ii", $student_id, $surah_id);
$stmt->execute();
$sl = $stmt->get_result()->fetch_assoc();

if (!$sl) exit('Invalid surah or learning plan');

$verses_per_request = (int)$sl['verses_per_request'];
$total_verses       = (int)$sl['total_verses'];
$surah_name         = $sl['name_en'];

/* The next portion never goes below the verse the student chose to start the
   plan from, even when no recitation has been accepted yet (e.g. a previous
   lesson request was deleted). */
$plan_start_verse = $has_start_verse ? max(1, (int)($sl['start_verse'] ?? 1)) : 1;

/* Determine next verse range based on last ACCEPTED recitation */
$stmt = $conn->prepare("
    SELECT l.to_verse
    FROM student_recitation sr
    JOIN lessons l ON l.id = sr.learning_plan_id
    WHERE sr.student_id = ? AND l.surah_id = ? AND sr.status = 'accepted'
    ORDER BY l.to_verse DESC
    LIMIT 1
");
$stmt->bind_param("ii", $student_id, $surah_id);
$stmt->execute();
$last_accepted = $stmt->get_result()->fetch_assoc();

$from_verse = $last_accepted ? max($plan_start_verse, (int)$last_accepted['to_verse'] + 1) : $plan_start_verse;

if ($from_verse > $total_verses) {
    ui_message_page(
        'success',
        'Surah Completed',
        'Masha’Allah! You have completed <strong>' . htmlspecialchars($surah_name) . '</strong> — may Allah accept it from you.',
        'dashboard.php',
        'Back to Dashboard',
        'trophy'
    );
}

$to_verse = min($from_verse + $verses_per_request - 1, $total_verses);

/* Check if last recitation was rejected or still pending review */
$stmt = $conn->prepare("
    SELECT sr.status
    FROM student_recitation sr
    JOIN lessons l ON l.id = sr.learning_plan_id
    WHERE sr.student_id = ? AND l.surah_id = ?
    ORDER BY sr.id DESC
    LIMIT 1
");
$stmt->bind_param("ii", $student_id, $surah_id);
$stmt->execute();
$last = $stmt->get_result()->fetch_assoc();

if ($last && $last['status'] === 'pending') {
    ui_message_page(
        'info',
        'Recitation Awaiting Review',
        'Your last recitation is still being reviewed by your teacher.<br>You will be able to request the next portion once it is accepted.',
        'dashboard.php',
        'Go Back',
        'clock'
    );
}

if ($last && $last['status'] === 'rejected') {
    ui_message_page(
        'danger',
        'Recitation Rejected',
        'Your last recitation was rejected.<br>Please record it again from <strong>My Lessons</strong> before requesting the next portion.',
        'dashboard.php',
        'Go Back',
        'close'
    );
}

/* Check if there is an outstanding lesson that has not been fully completed yet.
   A lesson only counts as completed once it has an ACCEPTED recitation, so this
   blocks new requests while any lesson for this surah is still waiting on one
   (requested-but-unsent, delivered-but-not-recorded, pending review, or
   rejected-and-needing-a-re-record). */
$stmt = $conn->prepare("
    SELECT l.id
    FROM lessons l
    WHERE l.student_id = ? AND l.surah_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM student_recitation sr
          WHERE sr.learning_plan_id = l.id AND sr.status = 'accepted'
      )
    LIMIT 1
");
$stmt->bind_param("ii", $student_id, $surah_id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    ui_message_page(
        'info',
        'Lesson Not Ready Yet',
        'You already have a lesson that has not been delivered or fully reviewed yet.<br>You will be able to request the next portion once your current lesson has been accepted.',
        'dashboard.php',
        'Go Back',
        'clock'
    );
}

/* Insert new lesson request */
$stmt = $conn->prepare("
    INSERT INTO lessons (student_id, surah_id, from_verse, to_verse, status)
    VALUES (?, ?, ?, ?, 'requested')
");
$stmt->bind_param("iiii", $student_id, $surah_id, $from_verse, $to_verse);
$stmt->execute();

ui_message_page(
    'success',
    'Lesson Request Sent!',
    'You requested verses <strong>' . (int)$from_verse . ' – ' . (int)$to_verse . '</strong> of <strong>' . htmlspecialchars($surah_name) . '</strong>.<br>Your teacher will prepare your lesson soon, in shaa Allah.',
    'dashboard.php',
    'Back to Dashboard',
    'check-circle'
);