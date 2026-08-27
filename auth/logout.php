<?php
// auth/logout.php

require_once __DIR__ . '/../config/security/session.php';
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../config/security/impersonate.php';
require_once __DIR__ . '/../config/db.php';

/* If we are currently surveying a student, finalize the audit row before the
   session is wiped. This restores the admin session data first (harmless, since
   it is about to be destroyed) so the log's ended_at is recorded. */
if (function_exists('is_impersonating') && is_impersonating()) {
    stop_impersonation($conn);
}

// Unset all session variables
$_SESSION = [];

// Destroy session
session_destroy();

// Remove session cookie (important on shared hosting)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Redirect to login
header('Location: /auth/login.php');
exit;
