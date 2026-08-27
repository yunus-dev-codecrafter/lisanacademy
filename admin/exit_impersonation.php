<?php
// admin/exit_impersonation.php
// Returns the session to the original admin and finalizes the audit row.
// NOTE: while impersonating, $_SESSION['role'] === 'student', so this handler
// must NOT require the admin role — it only checks is_impersonating().

require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security/impersonate.php';

if (!is_impersonating()) {
    redirect('/admin/dashboard.php');
}

csrf_verify();
stop_impersonation($conn);
redirect('/admin/dashboard.php');
