<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

if (
    empty($_POST['name']) ||
    empty($_POST['email']) ||
    empty($_POST['password'])
) {
    redirect('add_student.php?error=' . urlencode('All fields are required'));
}

$name  = trim($_POST['name']);
$email = trim($_POST['email']);
$pass  = $_POST['password'];
$phone = trim($_POST['phone'] ?? '');
$hafiz = (int)($_POST['hafiz'] ?? 0);

/* check email uniqueness */
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    redirect('add_student.php?error=' . urlencode('Email already exists'));
}

/* hash password securely */
$hashed = password_hash($pass, PASSWORD_DEFAULT);

/* insert student */
$has_phone = db_column_exists($conn, 'users', 'phone');
$has_hafiz = db_column_exists($conn, 'users', 'hafiz');

if ($has_phone && $has_hafiz) {
    $stmt = $conn->prepare("
        INSERT INTO users (name, email, phone, password, role, device_type, suspended, blocked, hafiz)
        VALUES (?, ?, ?, ?, 'student', 'iphone', 0, 0, ?)
    ");
    $stmt->bind_param("sssii", $name, $email, $phone, $hashed, $hafiz);
} elseif ($has_phone) {
    $stmt = $conn->prepare("
        INSERT INTO users (name, email, phone, password, role, device_type, suspended, blocked)
        VALUES (?, ?, ?, ?, 'student', 'iphone', 0, 0)
    ");
    $stmt->bind_param("ssss", $name, $email, $phone, $hashed);
} else {
    $stmt = $conn->prepare("
        INSERT INTO users (name, email, password, role, device_type, suspended, blocked)
        VALUES (?, ?, ?, 'student', 'iphone', 0, 0)
    ");
    $stmt->bind_param("sss", $name, $email, $hashed);
}
$stmt->execute();
$new_student_id = (int)$conn->insert_id;

/* If student is Hafiz, create first revision cycle */
if ($hafiz === 1 && $new_student_id > 0 && db_table_exists($conn, 'hafiz_revision')) {
    try {
        $now = date('Y-m-d H:i:s');
        $ins = $conn->prepare("
            INSERT INTO hafiz_revision (student_id, cycle_no, current_page, week_started_at, status, started_at)
            VALUES (?, 1, 1, ?, 'active', ?)
        ");
        $ins->bind_param("iss", $new_student_id, $now, $now);
        $ins->execute();
    } catch (Throwable $e) { /* ignore */ }
}

/* Referral link: if this new student's phone matches a pending friend invite,
   mark that invite as 'joined' (the friend has now registered). */
if ($phone !== '' && db_column_exists($conn, 'student_invites', 'status')) {
    try {
        $stmt = $conn->prepare("
            UPDATE student_invites
            SET status = 'joined', joined_student_id = ?
            WHERE friend_phone = ? AND status = 'invited'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("is", $new_student_id, $phone);
        $stmt->execute();
    } catch (Throwable $e) { /* ignore */ }
}

/* success */
redirect('students.php');