<?php
// config/security/impersonate.php
//
// Admin "login as student" (impersonation) helpers.
// No top-level code requires a database connection, so this file is safe to
// include everywhere (it is pulled in by config/security/helpers.php).

if (!function_exists('is_impersonating')) {
    /**
     * True when the current session is an admin temporarily acting as a student.
     */
    function is_impersonating() {
        return !empty($_SESSION['impersonating']) && !empty($_SESSION['impersonator_id']);
    }
}

if (!function_exists('impersonator_id')) {
    /**
     * The real admin user id that started the current impersonation (0 if none).
     */
    function impersonator_id() {
        return is_impersonating() ? (int)$_SESSION['impersonator_id'] : 0;
    }
}

if (!function_exists('survey_key_hash')) {
    /**
     * Return the bcrypt hash of the admin survey key stored in app_settings,
     * or '' when it has not been set yet. Never throws.
     */
    function survey_key_hash($conn) {
        try {
            $k = 'admin_survey_key';
            $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
            if (!$stmt) return '';
            $stmt->bind_param("s", $k);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ? (string)$r['setting_value'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('set_survey_key_hash')) {
    /**
     * Store (or replace) the bcrypt-hashed admin survey key in app_settings.
     * Uses UPDATE-then-INSERT so it works whether or not setting_key is unique.
     */
    function set_survey_key_hash($conn, $hash) {
        $esc = $conn->real_escape_string($hash);
        $conn->query("UPDATE app_settings SET setting_value = '$esc' WHERE setting_key = 'admin_survey_key'");
        if ($conn->affected_rows === 0) {
            $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('admin_survey_key', '$esc')");
        }
    }
}

if (!function_exists('verify_survey_key')) {
    /**
     * True when the supplied key matches the stored admin survey key.
     * Always returns false when no key has been configured yet.
     */
    function verify_survey_key($conn, $key) {
        $hash = survey_key_hash($conn);
        if ($hash === '' || $key === '') return false;
        return password_verify($key, $hash);
    }
}

if (!function_exists('start_impersonation')) {
    /**
     * Swap the active session to the given student while remembering the admin
     * who started it. Also writes an admin_impersonation_log row.
     * Returns true on success, false when the student/admin cannot be loaded.
     */
    function start_impersonation($conn, $student_id, $admin_id) {
        $student_id = (int)$student_id;
        $admin_id   = (int)$admin_id;
        if ($student_id <= 0 || $admin_id <= 0) return false;

        $stmt = $conn->prepare("SELECT id, email, role, name, profile_image FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        if (!$student) return false;

        $stmt2 = $conn->prepare("SELECT id, email, role, name, profile_image FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
        $stmt2->bind_param("i", $admin_id);
        $stmt2->execute();
        $admin = $stmt2->get_result()->fetch_assoc();
        if (!$admin) return false;

        /* Audit log (best-effort — table may not exist on a fresh install). */
        $log_id = 0;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';
        try {
            $ins = $conn->prepare("INSERT INTO admin_impersonation_log (admin_id, student_id, started_at, admin_ip) VALUES (?, ?, NOW(), ?)");
            $ins->bind_param("iis", $admin_id, $student_id, $ip);
            $ins->execute();
            $log_id = $conn->insert_id;
        } catch (Throwable $e) { /* ignore */ }

        /* Remember who we really are, then become the student. */
        $_SESSION['impersonator_id']      = $admin_id;
        $_SESSION['impersonating']        = true;
        $_SESSION['impersonation_log_id'] = $log_id;

        $_SESSION['user_id']         = $student['id'];
        $_SESSION['email']           = $student['email'];
        $_SESSION['role']            = $student['role'];
        $_SESSION['name']            = $student['name'];
        $_SESSION['profile_image']   = $student['profile_image'];
        $_SESSION['last_activity']   = time();

        return true;
    }
}

if (!function_exists('stop_impersonation')) {
    /**
     * Restore the original admin session and finalize the audit row.
     * Returns true when an impersonation was active (and restored).
     */
    function stop_impersonation($conn = null) {
        if (!is_impersonating()) return false;

        $admin_id = (int)$_SESSION['impersonator_id'];

        if (!empty($_SESSION['impersonation_log_id']) && $conn) {
            try {
                $upd = $conn->prepare("UPDATE admin_impersonation_log SET ended_at = NOW() WHERE id = ?");
                $upd->bind_param("i", $_SESSION['impersonation_log_id']);
                $upd->execute();
            } catch (Throwable $e) { /* ignore */ }
        }

        if ($conn) {
            $stmt = $conn->prepare("SELECT id, email, role, name, profile_image FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            if ($admin) {
                $_SESSION['user_id']       = $admin['id'];
                $_SESSION['email']         = $admin['email'];
                $_SESSION['role']          = $admin['role'];
                $_SESSION['name']          = $admin['name'];
                $_SESSION['profile_image'] = $admin['profile_image'];
            }
        }

        unset($_SESSION['impersonating'], $_SESSION['impersonator_id'], $_SESSION['impersonation_log_id']);
        $_SESSION['last_activity'] = time();

        return true;
    }
}
