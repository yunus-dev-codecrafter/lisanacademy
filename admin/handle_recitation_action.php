<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

$type   = $_POST['type']   ?? '';
$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$id || !$type || !$action) {
    die('Invalid request');
}

$status = ($action === 'accept') ? 'accepted' : 'rejected';

/* =========================
   AUDIO RECITATION
========================= */
if ($type === 'audio') {

    $rating  = $_POST['rating'] ?? null;
    $comment = $_POST['comment'] ?? '';

    $stmt = $conn->prepare("
        UPDATE student_recitation
        SET 
            status = ?,
            rating = ?,
            feedback = ?
        WHERE id = ?
    ");

    $stmt->bind_param("sssi", $status, $rating, $comment, $id);
    $stmt->execute();

    // Notify student
    if (function_exists('notify_student')) {
        $row = $conn->query("SELECT student_id FROM student_recitation WHERE id=$id LIMIT 1")->fetch_assoc();
        if ($row && !empty($row['student_id'])) {
            $vtitle = ($status === 'accepted') ? "🎉 Recitation Accepted!" : "📝 Recitation Reviewed";
            $rating_str = $rating ? " (Rating: $rating)" : "";
            notify_student($conn, (int)$row['student_id'], $vtitle,
                "Your teacher has reviewed your recitation$rating_str. Tap to view feedback.",
                'recitation_review', '/student/feedback.php', 'check-circle');
        }
    }
}

/* =========================
   LIVE RECITATION
   (NO COMMENT STORED)
========================= */
if ($type === 'live') {

    $stmt = $conn->prepare("
        UPDATE live_recitation_requests
        SET status = ?
        WHERE id = ?
    ");

    $stmt->bind_param("si", $status, $id);
    $stmt->execute();

    // Notify student about live session decision
    if (function_exists('notify_student')) {
        $row = $conn->query("SELECT student_id FROM live_recitation_requests WHERE id=$id LIMIT 1")->fetch_assoc();
        if ($row && !empty($row['student_id'])) {
            $vtitle = ($status === 'accepted') ? "📅 Live Session Confirmed!" : "📅 Live Session Update";
            $vmsg = ($status === 'accepted') ? "Your live recitation request has been accepted. Check your WhatsApp for scheduling details." : "Your live recitation request was reviewed. Please contact your teacher for next steps.";
            notify_student($conn, (int)$row['student_id'], $vtitle, $vmsg, 'recitation_review', '/student/dashboard.php', 'calendar');
        }
    }
}

header("Location: teaching.php");
exit;