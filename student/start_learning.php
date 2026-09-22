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

// Validate input
if (!isset($_POST['surah_id'], $_POST['verses_per_request']) || 
    $_POST['surah_id'] === '' || $_POST['verses_per_request'] < 1) {
    header("Location: my_learning.php");
    exit;
}

$surah_id = (int) $_POST['surah_id'];
$verses_per_request = (int) $_POST['verses_per_request'];

/* Get total verses of the surah (also needed to validate the starting verse). */
$stmt = $conn->prepare("SELECT total_verses FROM surahs WHERE id = ?");
$stmt->bind_param("i", $surah_id);
$stmt->execute();
$total_verses = (int)($stmt->get_result()->fetch_assoc()['total_verses'] ?? 0);

if ($total_verses <= 0) {
    ui_message_page('danger', 'Invalid Surah', 'That surah could not be found. Please go back and select a valid surah.', 'my_learning.php', 'Back to My Learning', 'close');
}

/* Optional "start from verse" — lets a student who already knows part of a
   surah continue from where they stopped instead of always beginning at 1.
   The student's chosen verse is never silently dropped: if the backing column
   is missing we try to create it first, and fail loudly if that is not possible
   instead of quietly starting from verse 1. */
$has_start_verse = db_ensure_start_verse_column($conn);
$start_verse = (int)($_POST['start_verse'] ?? 1);
if (!$has_start_verse) {
    ui_message_page(
        'danger',
        'Feature Unavailable',
        'We could not enable the "start from a chosen verse" option right now. Please contact the academy so the database can be updated, then try again.',
        'my_learning.php',
        'Back to My Learning',
        'close'
    );
}
if ($start_verse < 1 || $start_verse > $total_verses) {
    ui_message_page(
        'danger',
        'Invalid Starting Verse',
        'Your starting verse must be between <strong>1</strong> and <strong>' . $total_verses . '</strong>. Please go back and choose a valid verse.',
        'my_learning.php',
        'Back to My Learning',
        'close'
    );
}

/* Check if the student already has ANY learning plan for this surah.
   student_learning is unique per (student, surah), so a plan can only ever be
   started once — a completed plan (or any leftover row with an odd status)
   also blocks a second one. */
$check = $conn->prepare("
    SELECT id 
    FROM student_learning 
    WHERE student_id = ? AND surah_id = ?
    LIMIT 1
");
$check->bind_param("ii", $student_id, $surah_id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    ui_message_page(
        'info',
        'Surah Already Started',
        'You already have a learning plan for this surah. Continue from your active plan in <strong>My Learning</strong>.',
        'my_learning.php',
        'Back to My Learning',
        'book'
    );
}

/* Create learning plan */
if ($has_start_verse) {
    $stmt = $conn->prepare("
        INSERT INTO student_learning (
            student_id,
            surah_id,
            verses_per_request,
            start_verse,
            completed_requests,
            status
        ) VALUES (?, ?, ?, ?, 0, 'active')
    ");
    $stmt->bind_param("iiii", $student_id, $surah_id, $verses_per_request, $start_verse);
} else {
    $stmt = $conn->prepare("
        INSERT INTO student_learning (
            student_id,
            surah_id,
            verses_per_request,
            completed_requests,
            status
        ) VALUES (?, ?, ?, 0, 'active')
    ");
    $stmt->bind_param("iii", $student_id, $surah_id, $verses_per_request);
}

/* The unique key (student, surah) is the final backstop — if a second row is
   somehow attempted, show a friendly message instead of a 500 page. */
try {
    $stmt->execute();
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        ui_message_page(
            'info',
            'Surah Already Started',
            'You already have a learning plan for this surah. Continue from your active plan in <strong>My Learning</strong>.',
            'my_learning.php',
            'Back to My Learning',
            'book'
        );
    }
    throw $e;
}
$plan_id = $stmt->insert_id;

if ($total_verses > 0) {
    /* Create first lesson request (from the chosen starting verse) */
    $from_verse = $start_verse;
    $to_verse = min($from_verse + $verses_per_request - 1, $total_verses);

    $stmt = $conn->prepare("
        INSERT INTO lessons (student_id, surah_id, from_verse, to_verse, status)
        VALUES (?, ?, ?, ?, 'requested')
    ");
    $stmt->bind_param("iiii", $student_id, $surah_id, $from_verse, $to_verse);
    $stmt->execute();
}

/* Referral reward: if this student was invited by a friend and has now started
   learning, reward the friend who invited them with a 15% next-term discount. */
reward_inviter_for_joined_student($conn, $student_id);

header("Location: my_learning.php");
exit;