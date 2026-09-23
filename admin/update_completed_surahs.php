<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

csrf_verify();

// Use the correct POST key
if (!isset($_POST['student_id'], $_POST['surahs']) || !is_array($_POST['surahs'])) {
    exit('Invalid request');
}

$student_id = (int)$_POST['student_id'];
$surah_ids  = array_values(array_unique(array_map('intval', $_POST['surahs'])));

// Mark the checked surahs as completed
$has_completed_at = function_exists('db_column_exists') && db_column_exists($conn, 'student_learning', 'completed_at');
if (!empty($surah_ids)) {
    if ($has_completed_at) {
        $stmt = $conn->prepare("
            INSERT INTO student_learning (student_id, surah_id, verses_per_request, completed_requests, status, completed_at, audio_cleaned)
            VALUES (?, ?, 0, 0, 'completed', NOW(), 0)
            ON DUPLICATE KEY UPDATE status='completed', completed_at = COALESCE(completed_at, NOW())
        ");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO student_learning (student_id, surah_id, verses_per_request, completed_requests, status)
            VALUES (?, ?, 0, 0, 'completed')
            ON DUPLICATE KEY UPDATE status='completed'
        ");
    }

    foreach ($surah_ids as $surah_id) {
        $stmt->bind_param("ii", $student_id, $surah_id);
        $stmt->execute();
    }
}

// Un-complete any surah the admin no longer checks (correction requested, mistake, etc.)
try {
    $all_rows = $conn->query("SELECT id FROM surahs");
    $all_ids  = array_map('intval', array_column($all_rows->fetch_all(MYSQLI_ASSOC), 'id'));
    $unchecked = array_values(array_diff($all_ids, $surah_ids));
    if (!empty($unchecked)) {
        $placeholders = implode(',', array_fill(0, count($unchecked), '?'));
        $set_clause = $has_completed_at ? "status='pending', completed_at = NULL, audio_cleaned = 0" : "status='pending'";
        $stmt2 = $conn->prepare("
            UPDATE student_learning
            SET $set_clause
            WHERE student_id = ? AND surah_id IN ($placeholders) AND status='completed'
        ");
        $types = 'i' . str_repeat('i', count($unchecked));
        $stmt2->bind_param($types, $student_id, ...$unchecked);
        $stmt2->execute();
    }
} catch (Throwable $e) { /* ignore */ }

header("Location: student_detail.php?id=" . $student_id);
exit;