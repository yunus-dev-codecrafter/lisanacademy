<?php
// auth/login_process.php

require_once '../config/db.php';
require_once '../config/security/session.php';
require_once '../config/security/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if (empty($email) || empty($pass)) {
        redirect('login.php?error=' . urlencode("Please enter email and password"));
    }

    // Fetch extra fields needed for profile reflection
    $stmt = $conn->prepare("
        SELECT id, email, password, role, name, profile_image
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();

        if (password_verify($pass, $user['password'])) {
            session_regenerate_id(true);

            // Core session data (UNCHANGED)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['last_activity'] = time();

            // NEW: Profile data for dashboard reflection
            $_SESSION['name'] = $user['name'];
            $_SESSION['profile_image'] = $user['profile_image'];

            // Redirect based on role (UNCHANGED)
            if ($user['role'] === 'admin') {
                redirect('../admin/dashboard.php');
            } elseif (holiday_mode_on($conn)) {
                redirect('../student/holiday.php');
            } else {
                redirect('../student/dashboard.php');
            }
        }
    }

    // Login failed
    redirect('login.php?error=' . urlencode("Invalid email or password"));

} else {
    redirect('login.php');
}