<?php
// config/security/helpers.php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/impersonate.php';

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit;
    }
}

if (!function_exists('clean')) {
    function clean($data) {
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('arabic_text')) {
    /**
     * Return the string only if it contains genuine Arabic script.
     * Values holding placeholders/mojibake (e.g. "???????") return "" so
     * corrupted rows never render as question marks on screen.
     */
    function arabic_text($str) {
        $str = trim((string)$str);
        if ($str === '') return '';
        if (!preg_match('//u', $str)) return '';                            // not valid UTF-8
        if (!preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $str)) {
            return '';
        }
        return $str;
    }
}

if (!function_exists('require_role')) {
    function require_role($role) {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
            redirect('/auth/login.php');
        }
    }
}

if (!function_exists('normalize_phone_to_intl')) {
    /**
     * Normalize a Nigerian phone number into international digits for wa.me links.
     *   08012345678  -> 2348012345678
     *   +2348012345678 / 2348012345678 / 0801 234 5678 -> 2348012345678
     * Non-Nigerian numbers (with a different country code) are kept as digits.
     * Returns '' when the input has no usable digits.
     */
    function normalize_phone_to_intl($phone) {
        $digits = preg_replace('/[^0-9]/', '', (string)$phone);
        if ($digits === '') return '';
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return '234' . substr($digits, 1);
        }
        if (strlen($digits) === 11 && $digits[0] === '0') {
            return '234' . substr($digits, 1);
        }
        if (strpos($digits, '234') === 0) return $digits;
        if (strlen($digits) === 13 && strpos($digits, '234') === 0) return $digits;
        return $digits;
    }
}

if (!function_exists('setting')) {
    /**
     * Read a value from the app_settings table (safe if table missing).
     */
    function setting($conn, $key, $default = '') {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
            if (!$stmt) return $default;
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ? (string)$r['setting_value'] : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('exam_term_info')) {
    /**
     * Return the currently active exam term row (from exam_terms), or null.
     * Safe to call even before the table exists (returns null).
     */
    function exam_term_info($conn) {
        $id = (int)setting($conn, 'current_term_id', 0);
        if ($id <= 0) return null;
        try {
            $stmt = $conn->prepare("SELECT * FROM exam_terms WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $t = $stmt->get_result()->fetch_assoc();
            return $t ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('term_date_windows')) {
    /**
     * The academy runs 3 terms within a single calendar year:
     *   Term 1 (Jan – Apr) · Term 2 (May – Aug) · Term 3 (Sep – Dec)
     * Returns the label + date window for each term in the given year.
     */
    function term_date_windows($year = null) {
        $year = (int)($year ?: date('Y'));
        return [
            1 => ['name' => 'Term 1 (Jan – Apr)', 'start' => "$year-01-01", 'end' => "$year-04-30"],
            2 => ['name' => 'Term 2 (May – Aug)', 'start' => "$year-05-01", 'end' => "$year-08-31"],
            3 => ['name' => 'Term 3 (Sep – Dec)', 'start' => "$year-09-01", 'end' => "$year-12-31"],
        ];
    }
}

if (!function_exists('current_term_no')) {
    /**
     * Which of the 3 terms today's date falls in (1, 2 or 3).
     */
    function current_term_no() {
        $m = (int)date('n');
        if ($m >= 1 && $m <= 4) return 1;
        if ($m >= 5 && $m <= 8) return 2;
        return 3;
    }
}

if (!function_exists('exam_terms_has_schema')) {
    /**
     * True when exam_terms carries the term_no/school_year columns.
     */
    function exam_terms_has_schema($conn) {
        return db_table_exists($conn, 'exam_terms')
            && db_column_exists($conn, 'exam_terms', 'term_no')
            && db_column_exists($conn, 'exam_terms', 'school_year');
    }
}

if (!function_exists('exam_term_label')) {
    /**
     * Human label for an exam term, e.g. "Term 2 (May – Aug)".
     * $term is an exam_terms row (array) or a term id (with $conn).
     */
    function exam_term_label($term, $conn = null) {
        static $cache = [];

        if (is_array($term)) {
            $no = (int)($term['term_no'] ?? 0);
            $year = (int)($term['school_year'] ?? date('Y'));
            if ($no >= 1 && $no <= 3) {
                $windows = term_date_windows($year);
                return $windows[$no]['name'];
            }
            return 'Term ' . (int)($term['id'] ?? 0);
        }

        $term_id = (int)$term;
        if ($term_id <= 0) return '—';
        if (isset($cache[$term_id])) return $cache[$term_id];

        if (!$conn) {
            $cache[$term_id] = 'Term ' . $term_id;
            return $cache[$term_id];
        }

        try {
            $stmt = $conn->prepare("SELECT term_no, school_year FROM exam_terms WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $term_id);
            $stmt->execute();
            $t = $stmt->get_result()->fetch_assoc();
            if ($t) {
                $no = (int)($t['term_no'] ?? 0);
                if ($no >= 1 && $no <= 3) {
                    $windows = term_date_windows((int)($t['school_year'] ?? date('Y')));
                    $cache[$term_id] = $windows[$no]['name'];
                    return $cache[$term_id];
                }
                $cache[$term_id] = 'Term ' . $term_id;
                return $cache[$term_id];
            }
        } catch (Throwable $e) {
            /* fall through */
        }
        $cache[$term_id] = 'Term ' . $term_id;
        return $cache[$term_id];
    }
}

if (!function_exists('finalize_exam_term')) {
    /**
     * Close the active exam term: switch exam mode off, then snapshot
     * participation. Students with NO attempt in this term become defaulters
     * (locked from normal lessons; owe the N500 fee). Students whose attempt
     * was rejected keep free exam access until they pass.
     */
    function finalize_exam_term($conn) {
        $term = exam_term_info($conn);
        $term_id = $term ? (int)$term['id'] : 0;

        if ($term && !$term['deactivated_at']) {
            $conn->query("UPDATE exam_terms SET deactivated_at = NOW(), finalized = 1 WHERE id = $term_id");
        }
        $conn->query("UPDATE app_settings SET setting_value = 'off' WHERE setting_key = 'exam_mode'");

        if ($term_id <= 0) return;

        /* ---- Non-participants among the SELECTED students ----
           Only students the admin selected for this term are expected to
           participate. A selected student with no (valid) attempt in this term
           becomes a defaulter (locked from normal lessons; owe the N500 fee).
           Non-selected students are never flagged — they keep normal lessons. */
        $res = $conn->query("
            SELECT u.id FROM users u
            WHERE u.role = 'student'
              AND u.exam_selected = 1
              AND NOT EXISTS (
                  SELECT 1 FROM exam_attempts ea
                  WHERE ea.student_id = u.id AND ea.term_id = $term_id
                    AND ea.status != 'draft'
              )
        ");
        $ids = [];
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
        if (!empty($ids)) {
            $ids_str = implode(',', $ids);
            try {
                $conn->query("
                    UPDATE users
                    SET exam_defaulted = 1, exam_owed = 1, exam_access = 0
                    WHERE id IN ($ids_str)
                ");
            } catch (Throwable $e) {
                /* users columns missing — ignore (SQL not yet applied) */
            }
        }

        /* ---- Participants who were rejected: free exam-only access until they pass ----
           Locked from normal lessons (exam_defaulted = 1) but no fee (exam_owed = 0)
           and free retake access (exam_access = 1). Approved exams clear all flags. */
        $res = $conn->query("
            SELECT DISTINCT ea.student_id FROM exam_attempts ea
            WHERE ea.term_id = $term_id AND ea.status = 'rejected'
              AND NOT EXISTS (
                  SELECT 1 FROM exam_attempts a2
                  WHERE a2.student_id = ea.student_id AND a2.term_id = $term_id AND a2.status = 'approved'
              )
        ");
        $ids = [];
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['student_id'];
        if (!empty($ids)) {
            $ids_str = implode(',', $ids);
            try {
                $conn->query("UPDATE users SET exam_defaulted = 1, exam_owed = 0, exam_access = 1 WHERE id IN ($ids_str)");
            } catch (Throwable $e) {
                /* ignore */
            }
        }
    }
}

if (!function_exists('reset_exam_section')) {
    /**
     * Wipe the entire exam section back to defaults, regardless of the current state:
     *   - deletes every exam answer + attempt (and their audio files on disk)
     *   - deletes every exam term
     *   - turns exam mode OFF and clears exam_started_at / current_term_id
     *   - clears every student's exam_defaulted / exam_owed / exam_access / exam_selected / exam_paid_at
     * Every step is guarded so it never crashes on a partial/missing schema.
     */
    function reset_exam_section($conn) {
        /* 1. Delete exam audio files from disk before dropping the rows */
        try {
            $res = $conn->query("SELECT audio_file FROM exam_answers");
            if ($res) {
                $base = dirname(__DIR__, 2) . '/uploads/exam_audio/';
                while ($r = $res->fetch_assoc()) {
                    $name = (string)($r['audio_file'] ?? '');
                    if ($name === '') continue;
                    $file = $base . basename($name);
                    if (is_file($file)) @unlink($file);
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        /* 2. Drop all exam rows (answers first for FK-less child/parent order) */
        foreach (['exam_answers', 'exam_attempts', 'exam_terms'] as $t) {
            try { $conn->query("DELETE FROM `$t`"); } catch (Throwable $e) { /* ignore */ }
        }

        /* 3. Reset settings back to default */
        $settings = [
            'exam_mode'        => 'off',
            'exam_started_at'  => '',
            'current_term_id'  => '',
        ];
        foreach ($settings as $k => $v) {
            try {
                $esc = $conn->real_escape_string($v);
                $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('$k', '$esc')
                              ON DUPLICATE KEY UPDATE setting_value = '$esc'");
            } catch (Throwable $e) { /* ignore */ }
        }

        /* 4. Clear every student's exam flags */
        $sets = ['exam_defaulted = 0', 'exam_owed = 0', 'exam_access = 0', 'exam_selected = 0'];
        if (db_column_exists($conn, 'users', 'exam_paid_at')) $sets[] = 'exam_paid_at = NULL';
        try {
            $conn->query("UPDATE users SET " . implode(', ', $sets) . " WHERE role = 'student'");
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('exam_mode_on')) {
    /**
     * True when the admin has activated global exam mode.
     * Auto-closes the term once its 10-day window has expired.
     * Safe to call even before app_settings exists (returns false).
     */
    function exam_mode_on($conn) {
        $mode = setting($conn, 'exam_mode', 'off');
        if ($mode !== 'on') return false;

        $term = exam_term_info($conn);
        if ($term && empty($term['deactivated_at']) && !empty($term['auto_close_at'])) {
            if (strtotime($term['auto_close_at']) <= time()) {
                finalize_exam_term($conn);
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('student_exam_locked')) {
    /**
     * True when the student missed the current exam term and has not yet
     * gotten an approved result — their normal lessons stay locked.
     */
    function student_exam_locked($conn, $student_id) {
        $student_id = (int)$student_id;
        try {
            $stmt = $conn->prepare("SELECT exam_defaulted FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ? ((int)$r['exam_defaulted'] === 1) : false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('student_exam_access')) {
    /**
     * True when the admin granted per-student exam access (after paying the
     * N500 fee, or free retake after a rejection).
     */
    function student_exam_access($conn, $student_id) {
        $student_id = (int)$student_id;
        try {
            $stmt = $conn->prepare("SELECT exam_access FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ? ((int)$r['exam_access'] === 1) : false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('student_exam_selected')) {
    /**
     * True when the admin selected this student as a qualified participant
     * for the CURRENT exam term. Non-selected students are not expected to
     * sit the exam and keep following their normal lessons.
     */
    function student_exam_selected($conn, $student_id) {
        $student_id = (int)$student_id;
        try {
            $stmt = $conn->prepare("SELECT exam_selected FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ? ((int)($r['exam_selected'] ?? 0) === 1) : false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('student_in_exam')) {
    /**
     * True when this individual student's normal lessons are paused because
     * they were selected to participate in the currently-active exam term.
     * Replaces the old global "exam_mode_on" gate so non-selected students
     * keep following their normal lessons while the exam runs.
     */
    function student_in_exam($conn, $student_id) {
        return exam_mode_on($conn) && student_exam_selected($conn, $student_id);
    }
}

if (!function_exists('can_take_exam')) {
    /**
     * True when the student may sit the exam: they were selected for the
     * active exam term, OR the exam was reopened for this student only
     * (paid fee / free retake).
     */
    function can_take_exam($conn, $student_id) {
        return student_in_exam($conn, $student_id) || student_exam_access($conn, $student_id);
    }
}

if (!function_exists('db_table_exists')) {
    /**
     * True when the given table exists. Never throws (returns false).
     */
    function db_table_exists($conn, $table) {
        try {
            $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
            return $r && $r->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('db_column_exists')) {
    /**
     * True when the given table has the given column. Never throws.
     */
    function db_column_exists($conn, $table, $column) {
        try {
            $res = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "`");
            if (!$res) return false;
            $needle = strtolower((string)$column);
            while ($row = $res->fetch_assoc()) {
                if (strtolower((string)$row['Field']) === $needle) return true;
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('db_ensure_start_verse_column')) {
    /**
     * Make sure student_learning.start_verse exists, creating it if needed.
     * Idempotent and never throws. Returns true when the column exists
     * afterwards (either already present or just added).
     */
    function db_ensure_start_verse_column($conn) {
        if (db_column_exists($conn, 'student_learning', 'start_verse')) {
            return true;
        }
        try {
            $conn->query("ALTER TABLE student_learning ADD COLUMN start_verse INT NOT NULL DEFAULT 1 AFTER verses_per_request");
        } catch (Throwable $e) {
            error_log('db_ensure_start_verse_column failed: ' . $e->getMessage());
            return false;
        }
        return db_column_exists($conn, 'student_learning', 'start_verse');
    }
}

if (!function_exists('db_column_type')) {
    /**
     * Return the column type definition (e.g. "enum('pending','approved','rejected')")
     * or "" when missing. Never throws.
     */
    function db_column_type($conn, $table, $column) {
        try {
            $res = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "`");
            if (!$res) return '';
            $needle = strtolower((string)$column);
            while ($row = $res->fetch_assoc()) {
                if (strtolower((string)$row['Field']) === $needle) return (string)$row['Type'];
            }
            return '';
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('users_has_exam_columns')) {
    /**
     * True when the users table carries the exam default/payment columns.
     * Missing schema would otherwise crash exam_defaults.php / exam_settings.php.
     */
    function users_has_exam_columns($conn) {
        return db_column_exists($conn, 'users', 'exam_defaulted')
            && db_column_exists($conn, 'users', 'exam_owed')
            && db_column_exists($conn, 'users', 'exam_access')
            && db_column_exists($conn, 'users', 'exam_paid_at');
    }
}

if (!function_exists('exam_attempts_has_exam_columns')) {
    /**
     * True when exam_attempts carries the term_id / mistakes_count columns.
     * Missing schema would otherwise crash admin/exams.php.
     */
    function exam_attempts_has_exam_columns($conn) {
        return db_column_exists($conn, 'exam_attempts', 'term_id')
            && db_column_exists($conn, 'exam_attempts', 'mistakes_count');
    }
}

if (!function_exists('ensure_exam_submit_schema')) {
    /**
     * Idempotently add the columns the exam submit flow reads/writes, so a
     * never-migrated database (e.g. missing exam_answers.audio_file) does not
     * crash student/submit_exam.php with a confusing HTML error page.
     * Returns true when every required column is present afterwards.
     */
    function ensure_exam_submit_schema($conn) {
        $answers_cols = [
            'day_no'      => 'TINYINT NULL AFTER to_verse',
            'status'      => "ENUM('pending','submitted') NOT NULL DEFAULT 'pending' AFTER day_no",
            'answered_at' => 'DATETIME NULL AFTER status',
            'audio_file'  => 'VARCHAR(255) NULL AFTER answered_at',
        ];
        foreach ($answers_cols as $col => $def) {
            if (!db_column_exists($conn, 'exam_answers', $col)) {
                try {
                    $conn->query("ALTER TABLE exam_answers ADD COLUMN `$col` $def");
                } catch (Throwable $e) {
                    error_log('ensure_exam_submit_schema failed (exam_answers.' . $col . '): ' . $e->getMessage());
                    return false;
                }
            }
        }
        $attempts_cols = [
            'term_id'       => 'INT NULL AFTER student_id',
            'question_count' => 'TINYINT NULL AFTER mistakes_count',
            'total_days'     => 'TINYINT NULL AFTER question_count',
            'day_no'         => 'TINYINT NOT NULL DEFAULT 1 AFTER total_days',
        ];
        foreach ($attempts_cols as $col => $def) {
            if (!db_column_exists($conn, 'exam_attempts', $col)) {
                try {
                    $conn->query("ALTER TABLE exam_attempts ADD COLUMN `$col` $def");
                } catch (Throwable $e) {
                    error_log('ensure_exam_submit_schema failed (exam_attempts.' . $col . '): ' . $e->getMessage());
                    return false;
                }
            }
        }
        return true;
    }
}

if (!function_exists('exam_tier_schema_ready')) {
    /**
     * True when the tiered (3/7/10 question, multi-day) exam schema is present:
     * exam_attempts.question_count / total_days / day_no and
     * exam_answers.day_no / status. Pages that query these columns should gate
     * on this so a pre-migration database never crashes.
     */
    function exam_tier_schema_ready($conn) {
        return db_column_exists($conn, 'exam_attempts', 'question_count')
            && db_column_exists($conn, 'exam_attempts', 'total_days')
            && db_column_exists($conn, 'exam_attempts', 'day_no')
            && db_column_exists($conn, 'exam_answers', 'day_no')
            && db_column_exists($conn, 'exam_answers', 'status');
    }
}

if (!function_exists('exam_criteria_html')) {
    /**
     * Acceptances criteria shown on the student exam page.
     */
    function exam_criteria_html() {
        return '<div class="card card-gold animate-rise">
            <div class="card-title"><h3 style="margin-top:0;">' . ui_icon('check-circle', 18) . ' Acceptance Criteria</h3></div>
            <p class="small" style="margin:0 0 6px;">Your recitation will be reviewed against this rule:</p>
            <ul class="small" style="margin:0;padding-left:20px;">
                <li><strong>Maximum mistakes allowed: 3</strong> across your entire recitation.</li>
                <li>If more than <strong>3 mistakes</strong> are found, you must <strong>retake</strong> this term&#8217;s examination.</li>
            </ul>
        </div>';
    }
}

if (!function_exists('quran_completion_percent')) {
    /**
     * Verse-based Qur'an completion percentage for a student.
     * Counts only COMPLETED plans: for each completed surah the student is
     * credited with the whole surah (MAX of tracked completed_verses and the
     * surah's total), so admin-flagged completions that never tracked verses
     * still count fully. Denominator = total verses of the entire Qur'an.
     * Returns 0 when surahs/student_learning data is unavailable.
     */
    function quran_completion_percent($conn, $student_id) {
        $student_id = (int)$student_id;
        try {
            $res = $conn->query("
                SELECT s.total_verses, sl.completed_verses
                FROM student_learning sl
                JOIN surahs s ON s.id = sl.surah_id
                WHERE sl.student_id = $student_id AND sl.status = 'completed'
            ");
            $done = 0;
            while ($r = $res->fetch_assoc()) {
                $total = (int)($r['total_verses'] ?? 0);
                $comp  = (int)($r['completed_verses'] ?? 0);
                $done += max($total, $comp);
            }
        } catch (Throwable $e) {
            return 0;
        }

        try {
            $all = (int)$conn->query("SELECT COALESCE(SUM(total_verses),0) t FROM surahs")->fetch_assoc()['t'];
        } catch (Throwable $e) {
            $all = 0;
        }
        if ($all <= 0) return 0;
        return (int)round(($done / $all) * 100);
    }
}

if (!function_exists('student_has_graduated')) {
    /**
     * True when the student has completed every surah of the Glorious Qur'an
     * (a completed student_learning row exists for each surah). Used to count
     * graduates and to trigger graduation / account lifecycle actions.
     */
    function student_has_graduated($conn, $student_id) {
        $student_id = (int)$student_id;
        try {
            $total = (int)$conn->query("SELECT COUNT(*) c FROM surahs")->fetch_assoc()['c'];
            if ($total <= 0) return false;
            $done = (int)$conn->query("
                SELECT COUNT(DISTINCT sl.surah_id) c
                FROM student_learning sl
                WHERE sl.student_id = $student_id AND sl.status = 'completed'
            ")->fetch_assoc()['c'];
            return $done >= $total;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mark_graduated_if_due')) {
    /**
     * Record the moment a student first reaches 100% completion. Idempotent:
     * only sets users.graduated_at the first time. Safe if the column/migration
     * is missing (returns false without crashing).
     */
    function mark_graduated_if_due($conn, $student_id) {
        if (!student_has_graduated($conn, $student_id)) return false;
        $student_id = (int)$student_id;
        try {
            $r = $conn->query("SELECT graduated_at FROM users WHERE id = $student_id")->fetch_assoc();
            if ($r && empty($r['graduated_at'])) {
                $conn->query("UPDATE users SET graduated_at = NOW() WHERE id = $student_id");
                return true;
            }
        } catch (Throwable $e) {
            /* users.graduated_at not yet migrated — ignore */
        }
        return false;
    }
}

if (!function_exists('purge_graduated_accounts')) {
    /**
     * Hard-delete every student account that graduated more than 7 days ago,
     * together with all of their records and uploaded media. Each step is
     * guarded so a partial schema never crashes the site.
     */
    function purge_graduated_accounts($conn) {
        try {
            $res = $conn->query("
                SELECT id FROM users
                WHERE role = 'student' AND graduated_at IS NOT NULL
                  AND graduated_at < NOW() - INTERVAL 7 DAY
            ");
            $ids = [];
            while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
            if (empty($ids)) return 0;
            $ids_str = implode(',', $ids);

            /* Remove exam + profile media from disk */
            try {
                $audio = $conn->query("SELECT audio_file FROM exam_answers WHERE attempt_id IN (SELECT id FROM exam_attempts WHERE student_id IN ($ids_str))");
                $base = dirname(__DIR__, 2) . '/uploads/exam_audio/';
                while ($r = $audio->fetch_assoc()) {
                    $f = $base . basename((string)($r['audio_file'] ?? ''));
                    if ($f !== $base && is_file($f)) @unlink($f);
                }
            } catch (Throwable $e) { /* ignore */ }
            try {
                $pics = $conn->query("SELECT profile_image FROM users WHERE id IN ($ids_str)");
                $picBase = dirname(__DIR__, 2) . '/uploads/profile_pics/';
                while ($r = $pics->fetch_assoc()) {
                    $n = (string)($r['profile_image'] ?? '');
                    if ($n !== '' && $n !== 'default.png') {
                        $f = $picBase . basename($n);
                        if (is_file($f)) @unlink($f);
                    }
                }
            } catch (Throwable $e) { /* ignore */ }

            /* Remove every table that references a student by student_id */
            foreach (['student_learning', 'student_recitation', 'exam_answers', 'exam_attempts',
                      'certificates', 'announcement_reads', 'student_invites',
                      'admin_audio', 'donations', 'suggestions', 'feedback'] as $t) {
                try {
                    $conn->query("DELETE FROM `$t` WHERE student_id IN ($ids_str)");
                } catch (Throwable $e) { /* missing table/column — ignore */ }
            }

            $conn->query("DELETE FROM users WHERE id IN ($ids_str)");
            return count($ids);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('exam_tier_info')) {
    /**
     * The tier the student's exam falls into, based on Qur'an completion:
     *   < 20%      -> 3 questions, 1 day (no day choice)
     *   20% – 50%  -> 7 questions, up to 3 days
     *   > 50%      -> 10 questions, up to 5 days
     * Returns ['percent'=>N, 'questions'=>N, 'max_days'=>N, 'label'=>string].
     */
    function exam_tier_info($conn, $student_id) {
        $percent = quran_completion_percent($conn, $student_id);
        if ($percent > 50) {
            return ['percent' => $percent, 'questions' => 10, 'max_days' => 5, 'label' => 'Advanced'];
        }
        if ($percent >= 20) {
            return ['percent' => $percent, 'questions' => 7, 'max_days' => 3, 'label' => 'Intermediate'];
        }
        return ['percent' => $percent, 'questions' => 3, 'max_days' => 1, 'label' => 'Foundation'];
    }
}

if (!function_exists('exam_generate_questions')) {
    /**
     * Generate up to $count random recitation questions from the student's
     * completed surahs. Each question is a random 8–15 verse window. Long
     * surahs can yield several windows; if completed material is too small,
     * fewer questions are returned (the caller stores the ACTUAL count).
     */
    function exam_generate_questions($conn, $student_id, $count) {
        $count = (int)$count;
        if ($count <= 0) return [];

        $surahs = [];
        try {
            $res = $conn->query("
                SELECT s.id, s.name_en, s.name_ar, s.total_verses
                FROM student_learning sl
                JOIN surahs s ON s.id = sl.surah_id
                WHERE sl.student_id = " . (int)$student_id . " AND sl.status = 'completed'
                ORDER BY s.id ASC
            ");
            while ($r = $res->fetch_assoc()) $surahs[] = $r;
        } catch (Throwable $e) {
            return [];
        }
        if (!$surahs) return [];

        /* Pass 1 — walk every completed surah into non-overlapping windows. */
        $candidates = [];
        foreach ($surahs as $s) {
            $total = (int)$s['total_verses'];
            if ($total < 1) continue;
            $pos = 1;
            $guard = 0;
            while ($pos <= $total && $guard < 100) {
                $guard++;
                $max_range = min(15, $total - $pos + 1);
                $range = ($max_range >= 8) ? rand(8, $max_range) : $max_range;
                $to = min($pos + $range - 1, $total);
                /* Absorb a trailing tail smaller than the minimum window size
                   into this window, so a surah never ends in a degenerate
                   question like "verse 176 to 176". */
                if ($total - $to < 8) {
                    $to = $total;
                }
                if (($to - $pos + 1) >= 8) {
                    $candidates[] = [
                        'surah_id' => (int)$s['id'],
                        'name_en'  => $s['name_en'],
                        'name_ar'  => $s['name_ar'],
                        'from'     => $pos,
                        'to'       => $to,
                    ];
                }
                $pos = $to + 1;
            }
        }
        shuffle($candidates);

        $questions = array_slice($candidates, 0, $count);

        /* Pass 2 — if still short, fill with random windows anywhere in completed surahs. */
        $tries = 0;
        while (count($questions) < $count && $tries < 200) {
            $tries++;
            $s = $surahs[array_rand($surahs)];
            $total = (int)$s['total_verses'];
            if ($total < 1) continue;
            $max_range = min(15, $total);
            $range = ($max_range >= 8) ? rand(8, $max_range) : $max_range;
            $max_start = $total - $range + 1;
            if ($max_start < 1) $max_start = 1;
            $from = rand(1, $max_start);
            $to = min($from + $range - 1, $total);
            if ($to <= $from || ($to - $from + 1) < 8) continue;
            $dup = false;
            foreach ($questions as $qq) {
                if ($qq['surah_id'] === (int)$s['id'] && $qq['from'] === $from && $qq['to'] === $to) { $dup = true; break; }
            }
            if ($dup) continue;
            $questions[] = [
                'surah_id' => (int)$s['id'],
                'name_en'  => $s['name_en'],
                'name_ar'  => $s['name_ar'],
                'from'     => $from,
                'to'       => $to,
            ];
        }

        return $questions;
    }
}

if (!function_exists('exam_distribute_days')) {
    /**
     * Assign each question in $questions a day (1..$total_days) as evenly as
     * possible (floor + remainder). Returns the same array with 'day_no' added.
     * Example: 7 questions / 3 days -> 3+2+2 · 10 / 5 -> 2+2+2+2+2.
     */
    function exam_distribute_days($questions, $total_days) {
        $total_days = max(1, (int)$total_days);
        $q = count($questions);
        if ($q === 0) return [];

        $base = intdiv($q, $total_days);
        $rem  = $q % $total_days;
        $cap  = [];
        $acc  = 0;
        for ($d = 1; $d <= $total_days; $d++) {
            $acc += $base + ($d <= $rem ? 1 : 0);
            $cap[$d] = $acc;
        }

        foreach ($questions as $i => $qq) {
            $day = 1;
            foreach ($cap as $d => $c) {
                if ($i < $c) { $day = $d; break; }
            }
            $questions[$i]['day_no'] = $day;
        }
        return $questions;
    }
}

if (!function_exists('exam_questions_remaining_in_days')) {
    /**
     * How many of the given questions are still pending for a draft attempt,
     * optionally scoped to a single day. Used for progress display.
     */
    function exam_questions_remaining_in_days($conn, $attempt_id, $day_no = null) {
        $attempt_id = (int)$attempt_id;
        $where = $day_no !== null ? "AND day_no = " . (int)$day_no : '';
        try {
            $res = $conn->query("SELECT COUNT(*) c FROM exam_answers WHERE attempt_id = $attempt_id AND status = 'pending' $where");
            return (int)$res->fetch_assoc()['c'];
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('applications_pending_count')) {
    /**
     * Count of public applications still in the early pipeline
     * (pending / contacted / payment_pending). Used for the admin sidebar badge.
     */
    function applications_pending_count($conn) {
        try {
            $res = $conn->query("SELECT COUNT(*) c FROM applications WHERE status IN ('pending','contacted','payment_pending')");
            return (int)$res->fetch_assoc()['c'];
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('holiday_info')) {
    /**
     * Return the holiday mode settings as an array.
     * Safe to call even before app_settings exists (returns defaults).
     */
    function holiday_info($conn) {
        return [
            'mode'             => setting($conn, 'holiday_mode', 'off'),
            'started_at'       => setting($conn, 'holiday_started_at', ''),
            'duration_days'    => (int)setting($conn, 'holiday_duration_days', 0),
            'ends_at'          => setting($conn, 'holiday_ends_at', ''),
            'resumption_date'  => setting($conn, 'holiday_resumption_date', ''),
            'message'          => setting($conn, 'holiday_message', ''),
        ];
    }
}

if (!function_exists('holiday_mode_on')) {
    /**
     * True when the admin has activated holiday mode and it has not expired.
     * Auto-deactivates itself once the configured duration has been reached
     * (when holiday_ends_at is in the past). Safe to call anytime.
     */
    function holiday_mode_on($conn) {
        $info = holiday_info($conn);
        if ($info['mode'] !== 'on') return false;

        if ($info['ends_at'] !== '' && strtotime($info['ends_at']) <= time()) {
            try {
                $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('holiday_mode', 'off')
                              ON DUPLICATE KEY UPDATE setting_value = 'off'");
            } catch (Throwable $e) { /* ignore */ }
            return false;
        }
        return true;
    }
}

if (!function_exists('holiday_days_left')) {
    /**
     * Whole days remaining until the holiday ends (0 when over/missing).
     * Returns null when holiday mode is not active or no end date is set.
     */
    function holiday_days_left($conn) {
        $info = holiday_info($conn);
        if ($info['mode'] !== 'on' || $info['ends_at'] === '') return null;
        $left = (int)ceil((strtotime($info['ends_at']) - time()) / 86400);
        return $left < 0 ? 0 : $left;
    }
}

if (!function_exists('holiday_resumption_label')) {
    /**
     * The date learning resumes: admin-specified resumption date if set,
     * otherwise the day the holiday ends.
     */
    function holiday_resumption_label($conn) {
        $info = holiday_info($conn);
        if ($info['resumption_date'] !== '') {
            $t = strtotime($info['resumption_date']);
            return $t ? date('D, d M Y', $t) : $info['resumption_date'];
        }
        if ($info['ends_at'] !== '') {
            $t = strtotime($info['ends_at']);
            return $t ? date('D, d M Y', $t) : '';
        }
        return '';
    }
}

if (!function_exists('holiday_allowed_student_page')) {
    /**
     * Student pages that stay reachable while holiday mode is on.
     * Every other student page is redirected to student/holiday.php.
     * $page must be a bare filename (e.g. 'certificate.php').
     */
    function holiday_allowed_student_page($page) {
        $allowed = [
            'holiday.php',
            'certificate.php',
            'exam.php',
            'exam_start.php',
            'submit_exam.php',
            'exam_result.php',
            'invite.php',
            'donate.php',
            'fees.php',
            'announcements.php',
            'suggestions.php',
            'profile.php',
            'update_profile.php',
            'logout.php',
        ];
        return in_array($page, $allowed, true);
    }
}

if (!function_exists('student_referral_code')) {
    /**
     * Return (and lazily create if needed) the student's unique referral code.
     * Format: LMA-<year>-<zero-padded user id>.
     */
    function student_referral_code($conn, $student_id) {
        $student_id = (int)$student_id;
        try {
            $stmt = $conn->prepare("SELECT referral_code FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            if ($r && !empty($r['referral_code'])) return $r['referral_code'];

            $code = 'LMA-' . date('Y') . '-' . str_pad($student_id, 4, '0', STR_PAD_LEFT);
            $conn->query("UPDATE users SET referral_code = '" . $conn->real_escape_string($code) . "' WHERE id = $student_id");
            return $code;
        } catch (Throwable $e) {
            return 'LMA-' . date('Y') . '-' . str_pad($student_id, 4, '0', STR_PAD_LEFT);
        }
    }
}

if (!function_exists('next_term_label')) {
    /**
     * Human label for the NEXT academy term (the term after the current one).
     * e.g. "Term 3 (Sep – Dec)" — and rolls over to the next school year.
     */
    function next_term_label() {
        $cur  = current_term_no();
        $next = $cur === 3 ? 1 : $cur + 1;
        $year = (int)date('Y');
        if ($cur === 3) $year += 1;
        return term_date_windows($year)[$next]['name'];
    }
}

if (!function_exists('reward_invite')) {
    /**
     * Mark a friend invite as rewarded and grant the inviting student a 15%
     * discount on their NEXT term fees. Only call this once the invited friend
     * has registered AND started learning.
     */
    function reward_invite($conn, $invite_id) {
        $invite_id = (int)$invite_id;
        if ($invite_id <= 0) return;
        try {
            $res = $conn->query("SELECT student_id FROM student_invites WHERE id = $invite_id LIMIT 1");
            if (!$res || $res->num_rows === 0) return;
            $inviter_id = (int)$res->fetch_assoc()['student_id'];
            if ($inviter_id <= 0) return;

            $conn->query("UPDATE student_invites SET status = 'rewarded', rewarded_at = NOW() WHERE id = $invite_id");

            $term = next_term_label();
            $conn->query("UPDATE users
                          SET next_term_discount = 1, discount_term = '" . $conn->real_escape_string($term) . "'
                          WHERE id = $inviter_id");
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('reward_inviter_for_joined_student')) {
    /**
     * When a student who was invited by a friend starts learning, find the
     * matching 'joined' invite and reward the inviter with the discount.
     */
    function reward_inviter_for_joined_student($conn, $joined_student_id) {
        $joined_student_id = (int)$joined_student_id;
        if ($joined_student_id <= 0) return;
        try {
            $stmt = $conn->prepare("SELECT id FROM student_invites WHERE joined_student_id = ? AND status = 'joined' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("i", $joined_student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            if ($r) reward_invite($conn, $r['id']);
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify() {
        $t = $_POST['csrf_token'] ?? '';
        if ($t === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
            http_response_code(403);
            $ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($ajax) {
                echo 'Invalid or expired session. Please refresh the page and try again.';
                exit;
            }
            ui_message_page('danger', 'Invalid Request', 'Invalid CSRF token. Please go back, refresh the page and try again.', '', '', 'close');
        }
    }
}

/* =====================================================
   HAFIZ REVISION HELPERS
   ===================================================== */

if (!function_exists('student_is_hafiz')) {
    /**
     * True when the student has the Hafiz flag set. Safe if column is missing.
     */
    function student_is_hafiz($conn, $student_id) {
        $student_id = (int)$student_id;
        if (!db_column_exists($conn, 'users', 'hafiz')) return false;
        try {
            $stmt = $conn->prepare("SELECT hafiz FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ? ((int)$r['hafiz'] === 1) : false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('hafiz_get_active_revision')) {
    /**
     * Return the active hafiz_revision row for this student, or null.
     */
    function hafiz_get_active_revision($conn, $student_id) {
        $student_id = (int)$student_id;
        if (!db_table_exists($conn, 'hafiz_revision')) return null;
        try {
            $stmt = $conn->prepare("SELECT * FROM hafiz_revision WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('hafiz_has_active_revision')) {
    /**
     * True when the student has an active revision cycle.
     */
    function hafiz_has_active_revision($conn, $student_id) {
        return hafiz_get_active_revision($conn, (int)$student_id) !== null;
    }
}

if (!function_exists('hafiz_juz_of_page')) {
    /**
     * Juz (1-30) that contains a given physical page in the 604-page Medina
     * Mushaf layout: juz 1 = pp.1-21, juzs 2-29 = 20 pages each, juz 30 =
     * pp.582-604 (23 pages). Totals 604.
     */
    function hafiz_juz_of_page($page) {
        $page = (int)$page;
        if ($page <= 21) return 1;
        if ($page > 581) return 30; // juz 30 runs pp.582-604 (23 pages)
        return (int)ceil(($page - 21) / 20) + 1;
    }
}

if (!function_exists('hafiz_juz_range')) {
    /**
     * [first_page, last_page] for a juz number (1-30).
     */
    function hafiz_juz_range($juz) {
        $juz = (int)$juz;
        if ($juz <= 1) return [1, 21];
        if ($juz >= 30) return [582, 604];
        return [20 * $juz - 18, 20 * $juz + 1];
    }
}

if (!function_exists('hafiz_juz_total_pages')) {
    /**
     * Number of physical pages in a juz (1-30).
     */
    function hafiz_juz_total_pages($juz) {
        $r = hafiz_juz_range($juz);
        return $r[1] - $r[0] + 1;
    }
}

if (!function_exists('hafiz_juz_progress')) {
    /**
     * ['accepted' => int, 'total' => int] of accepted pages in a juz.
     */
    function hafiz_juz_progress($conn, $revision_id, $juz) {
        $revision_id = (int)$revision_id;
        $r = hafiz_juz_range($juz);
        $total = $r[1] - $r[0] + 1;
        if (!db_table_exists($conn, 'hafiz_sessions')) {
            return ['accepted' => 0, 'total' => $total];
        }
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT page_no) c FROM hafiz_sessions
                WHERE revision_id = ? AND status = 'accepted' AND page_no >= ? AND page_no <= ?
            ");
            $stmt->bind_param("iii", $revision_id, $r[0], $r[1]);
            $stmt->execute();
            return ['accepted' => (int)$stmt->get_result()->fetch_assoc()['c'], 'total' => $total];
        } catch (Throwable $e) {
            return ['accepted' => 0, 'total' => $total];
        }
    }
}

if (!function_exists('hafiz_juz_complete')) {
    /**
     * True when every page of the juz has an accepted session (teacher
     * approved the whole juz).
     */
    function hafiz_juz_complete($conn, $revision_id, $juz) {
        if (!db_table_exists($conn, 'hafiz_sessions')) return false;
        $p = hafiz_juz_progress($conn, $revision_id, $juz);
        return $p['total'] > 0 && $p['accepted'] >= $p['total'];
    }
}

if (!function_exists('hafiz_juz_test_passed')) {
    /**
     * True when the latest weekly test keyed to this juz (week_no = juz)
     * passed.
     */
    function hafiz_juz_test_passed($conn, $revision_id, $juz) {
        $test = hafiz_get_latest_test($conn, (int)$revision_id, (int)$juz);
        return $test !== null && $test['status'] === 'passed';
    }
}

if (!function_exists('hafiz_completed_juz_map')) {
    /**
     * Map of completed juz_no => array of accepted page_no's.
     * Uses a single query for all accepted pages of the revision to prevent
     * query limits or latency on shared hosting.
     * A juz is considered completed when all pages in its range are accepted.
     * If no juz is fully accepted yet (e.g. testing or partial cycle), falls back
     * to grouping all accepted pages by juz so question generation never starves.
     */
    function hafiz_completed_juz_map($conn, $revision_id) {
        $revision_id = (int)$revision_id;
        if (!db_table_exists($conn, 'hafiz_sessions')) return [];

        $accepted_pages = [];
        try {
            $stmt = $conn->prepare("
                SELECT DISTINCT page_no FROM hafiz_sessions
                WHERE revision_id = ? AND status = 'accepted'
                ORDER BY page_no ASC
            ");
            $stmt->bind_param("i", $revision_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $accepted_pages[] = (int)$row['page_no'];
            }
        } catch (Throwable $e) {
            return [];
        }

        if (!$accepted_pages) return [];

        // Group accepted pages by juz
        $pages_by_juz = [];
        foreach ($accepted_pages as $pn) {
            $j = hafiz_juz_of_page($pn);
            if ($j >= 1 && $j <= 30) {
                if (!isset($pages_by_juz[$j])) $pages_by_juz[$j] = [];
                $pages_by_juz[$j][] = $pn;
            }
        }

        // Filter for fully completed juzs
        $completed_map = [];
        foreach ($pages_by_juz as $j => $pgs) {
            $total = hafiz_juz_total_pages($j);
            if (count($pgs) >= $total) {
                $completed_map[$j] = $pgs;
            }
        }

        // Fallback: if no juz is 100% accepted, include all juzs with accepted pages
        if (empty($completed_map)) {
            $completed_map = $pages_by_juz;
        }

        return $completed_map;
    }
}

if (!function_exists('hafiz_completed_juz_pages')) {
    /**
     * Accepted page numbers that live inside completed juzs — the pool a
     * weekly test may draw questions from.
     */
    function hafiz_completed_juz_pages($conn, $revision_id) {
        $map = hafiz_completed_juz_map($conn, $revision_id);
        $out = [];
        foreach ($map as $j => $pgs) {
            foreach ($pgs as $p) $out[] = (int)$p;
        }
        sort($out);
        return array_values(array_unique($out));
    }
}

if (!function_exists('hafiz_juz_gate')) {
    /**
     * Gate check for crossing into the first page of a juz. When the revision
     * pointer sits on the first page of juz > 1, the PREVIOUS juz must be fully
     * accepted by the teacher AND its weekly test must be passed before the
     * student may recite the new juz.
     * Returns ['ok' => bool, 'reason' => string].
     */
    function hafiz_juz_gate($conn, $revision) {
        if (!$revision) return ['ok' => true, 'reason' => ''];
        $current_page = (int)($revision['current_page'] ?? 1);
        $juz = hafiz_juz_of_page($current_page);
        if ($juz <= 1) return ['ok' => true, 'reason' => ''];
        if ($current_page !== hafiz_juz_range($juz)[0]) return ['ok' => true, 'reason' => ''];

        $revision_id = (int)$revision['id'];
        $prev = $juz - 1;

        if (!hafiz_juz_complete($conn, $revision_id, $prev)) {
            return ['ok' => false, 'reason' => "You have finished reciting Juz $prev. Your teacher must approve every page of Juz $prev before you can continue."];
        }
        if (hafiz_juz_test_passed($conn, $revision_id, $prev)) {
            return ['ok' => true, 'reason' => ''];
        }

        $test = hafiz_get_latest_test($conn, $revision_id, $prev);
        if ($test) {
            switch ($test['status']) {
                case 'submitted':
                    return ['ok' => false, 'reason' => "Juz $prev test is with your teacher for review. You can recite Juz $juz once it is marked Passed."];
                case 'failed':
                    return ['ok' => false, 'reason' => "You must pass the Juz $prev test retake before reciting Juz $juz."];
                case 'draft':
                case 'expired':
                default:
                    return ['ok' => false, 'reason' => "Juz $prev is complete — take your weekly test to unlock Juz $juz."];
            }
        }
        return ['ok' => false, 'reason' => "Masha'Allah, Juz $prev is complete! Take your weekly test to unlock Juz $juz."];
    }
}

if (!function_exists('hafiz_current_week_no')) {
    /**
     * Backwards-compatible alias: the revision's current Juz (1-30). Kept under
     * the old name because the weekly test is keyed per juz.
     */
    function hafiz_current_week_no($revision) {
        return hafiz_juz_of_page((int)($revision['current_page'] ?? 1));
    }
}

if (!function_exists('hafiz_test_juz')) {
    /**
     * The juz whose weekly test is currently relevant. Normally the juz the
     * revision pointer is inside (hafiz_current_week_no). BUT once current_page
     * rolls past the end of a completed juz (e.g. page 21 accepted -> pointer
     * at page 22, the first page of juz 2), the relevant test is the PREVIOUS
     * juz's — that juz was just approved by the teacher and must be passed
     * before the new juz unlocks.
     */
    function hafiz_test_juz($conn, $revision) {
        if (!$revision) return 0;
        $current_page = (int)($revision['current_page'] ?? 1);
        $juz = hafiz_juz_of_page($current_page);
        if ($juz > 1 && $current_page === hafiz_juz_range($juz)[0]) {
            // Sitting on the first page of a new juz — the relevant test is
            // always the PREVIOUS juz's (finished but must be tested to open
            // this one). Whether that juz is fully approved yet is decided by
            // the state machine below.
            return $juz - 1;
        }
        return $juz;
    }
}

if (!function_exists('hafiz_pages_this_week')) {
    /**
     * Count of distinct pages recited (submitted) in the current CALENDAR week
     * (since the revision's week_started_at, forward-rolled on Fridays). The
     * weekly maximum counts every distinct page regardless of review status,
     * without counting retries of the same page twice.
     */
    function hafiz_pages_this_week($conn, $revision_id, $week_no = 0) {
        $revision_id = (int)$revision_id;
        if (!db_table_exists($conn, 'hafiz_sessions') || !db_table_exists($conn, 'hafiz_revision')) return 0;

        $week_started_at = null;
        try {
            $stmt = $conn->prepare("SELECT week_started_at FROM hafiz_revision WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $revision_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $week_started_at = $r ? $r['week_started_at'] : null;
        } catch (Throwable $e) { /* ignore */ }
        if (!$week_started_at) return 0;

        try {
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT page_no) c FROM hafiz_sessions
                WHERE revision_id = ? AND submitted_at >= ? AND submitted_at <= NOW()
            ");
            $stmt->bind_param("is", $revision_id, $week_started_at);
            $stmt->execute();
            return (int)$stmt->get_result()->fetch_assoc()['c'];
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('hafiz_friday_boundary')) {
    /**
     * Return the next Friday 23:59:59 boundary from a given date.
     * If today is Friday and it's before 23:59, returns today's Friday 23:59:59.
     * If today is Friday and it's past 23:59, returns next Friday.
     * For Saturday-Thursday, returns the upcoming Friday.
     */
    function hafiz_friday_boundary($from_date = null) {
        $d = new DateTime($from_date ?: date('Y-m-d H:i:s'), new DateTimeZone('Africa/Lagos'));
        $day_of_week = (int)$d->format('N'); // 1=Mon ... 7=Sun

        if ($day_of_week === 5) {
            // Today is Friday
            $end_of_day = clone $d;
            $end_of_day->setTime(23, 59, 59);
            if ($d->getTimestamp() < $end_of_day->getTimestamp()) {
                return $end_of_day->format('Y-m-d H:i:s');
            }
            // Past Friday, get next Friday
            $d->modify('+7 days');
        } else {
            // Days until Friday: Friday=5, so for Mon(1) it's +4, Tue(2)->+3, etc.
            $days_until_friday = (5 - $day_of_week + 7) % 7;
            if ($days_until_friday === 0) $days_until_friday = 7;
            $d->modify("+$days_until_friday days");
        }
        $d->setTime(23, 59, 59);
        return $d->format('Y-m-d H:i:s');
    }
}

if (!function_exists('hafiz_check_week_transition')) {
    /**
     * Roll the CALENDAR week forward when the Friday boundary has passed.
     * Logs the completed week into hafiz_weekly_log (juz-based week_no, 24-page
     * target) and restarts week_started_at at NOW. Never resets progress.
     * Returns ['action' => 'ok'|'new_week', 'reason' => string].
     */
    function hafiz_check_week_transition($conn, $student_id) {
        $student_id = (int)$student_id;
        $revision = hafiz_get_active_revision($conn, $student_id);
        if (!$revision) return ['action' => 'ok', 'reason' => 'no active revision'];

        $week_started_at = $revision['week_started_at'];
        $now = date('Y-m-d H:i:s');
        $friday = hafiz_friday_boundary($week_started_at);

        // If we haven't passed the Friday boundary yet, no transition needed.
        if (strtotime($now) < strtotime($friday)) {
            return ['action' => 'ok', 'reason' => 'still in current week'];
        }

        $revision_id = (int)$revision['id'];
        $pages = hafiz_pages_this_week($conn, $revision_id);
        $week_no = hafiz_current_week_no($revision);
        $target_met = $pages >= 24 ? 1 : 0;

        // Log the completed calendar week.
        try {
            $stmt = $conn->prepare("
                INSERT INTO hafiz_weekly_log (revision_id, student_id, week_no, pages_completed, target_met, was_skipped, week_started_at, week_ended_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?)
                ON DUPLICATE KEY UPDATE
                    pages_completed = VALUES(pages_completed),
                    target_met = VALUES(target_met),
                    week_ended_at = VALUES(week_ended_at)
            ");
            $stmt->bind_param("iiiiiss", $revision_id, $student_id, $week_no, $pages, $target_met, $week_started_at, $friday);
            $stmt->execute();
        } catch (Throwable $e) { /* ignore */ }

        // Start the new calendar week now.
        try {
            $stmt = $conn->prepare("
                UPDATE hafiz_revision
                SET week_started_at = NOW(), skip_approved = 0, skip_approved_at = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("i", $revision_id);
            $stmt->execute();
        } catch (Throwable $e) { /* ignore */ }

        return ['action' => 'new_week', 'reason' => 'new calendar week started'];
    }
}

if (!function_exists('hafiz_advance_page')) {
    /**
     * Advance the current_page by 1. If page > 604, mark cycle as completed.
     * Returns 'advanced' or 'cycle_complete'.
     */
    function hafiz_advance_page($conn, $revision_id) {
        $revision_id = (int)$revision_id;
        if (!db_table_exists($conn, 'hafiz_revision')) return 'advanced';

        try {
            $stmt = $conn->prepare("SELECT current_page FROM hafiz_revision WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $revision_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $current_page = (int)($r['current_page'] ?? 1);
        } catch (Throwable $e) {
            return 'advanced';
        }

        $next_page = $current_page + 1;

        if ($next_page > 604) {
            try {
                $stmt = $conn->prepare("
                    UPDATE hafiz_revision SET status = 'completed', completed_at = NOW() WHERE id = ?
                ");
                $stmt->bind_param("i", $revision_id);
                $stmt->execute();
            } catch (Throwable $e) { /* ignore */ }
            return 'cycle_complete';
        }

        try {
            $stmt = $conn->prepare("UPDATE hafiz_revision SET current_page = ? WHERE id = ?");
            $stmt->bind_param("ii", $next_page, $revision_id);
            $stmt->execute();
        } catch (Throwable $e) { /* ignore */ }

        return 'advanced';
    }
}

if (!function_exists('hafiz_can_recite')) {
    /**
     * Master gate check: can the student start a new recitation session?
     * Returns ['ok' => bool, 'reason' => string].
     */
    function hafiz_can_recite($conn, $student_id) {
        $student_id = (int)$student_id;
        if (!student_is_hafiz($conn, $student_id)) {
            return ['ok' => false, 'reason' => 'You are not designated as a Hafiz student.'];
        }

        $revision = hafiz_get_active_revision($conn, $student_id);
        if (!$revision) {
            return ['ok' => false, 'reason' => 'You do not have an active revision cycle. Please contact the admin.'];
        }

        if ((int)$revision['current_page'] > 604) {
            return ['ok' => false, 'reason' => 'You have completed this revision cycle! Masha\'Allah!'];
        }

        // Roll the calendar week forward if the Friday boundary has passed.
        hafiz_check_week_transition($conn, $student_id);

        // Re-fetch the revision so the week_started_at is fresh after any roll.
        $revision = hafiz_get_active_revision($conn, $student_id);
        if (!$revision) {
            return ['ok' => false, 'reason' => 'You do not have an active revision cycle. Please contact the admin.'];
        }

        // Juz gate: the first page of a juz is locked until the previous juz is
        // fully accepted by the teacher AND its weekly test is passed.
        $required_page = hafiz_required_page($conn, $revision);
        $is_retry = $required_page !== (int)$revision['current_page'];
        if (!$is_retry) {
            $gate = hafiz_juz_gate($conn, $revision);
            if (!$gate['ok']) {
                return ['ok' => false, 'reason' => $gate['reason']];
            }
        }

        // Weekly maximum: 24 distinct pages per calendar week. Re-recitations
        // of flagged (rejected) pages are always allowed.
        $pages = hafiz_pages_this_week($conn, (int)$revision['id']);
        if (!$is_retry && $pages >= 24) {
            return ['ok' => false, 'reason' => 'You have reached the weekly maximum of 24 pages. Masha\'Allah! Wait for next week.'];
        }

        return ['ok' => true, 'reason' => ''];
    }
}

if (!function_exists('hafiz_required_page')) {
    /**
     * The page the student must recite next: the earliest flagged (rejected)
     * page in the cycle that has already been started, otherwise the current
     * next new page. Flagged pages must be re-recited in order.
     */
    function hafiz_required_page($conn, $revision) {
        $current_page = (int)($revision['current_page'] ?? 1);
        $revision_id = (int)($revision['id'] ?? 0);
        if ($revision_id > 0 && db_table_exists($conn, 'hafiz_sessions')) {
            try {
                $stmt = $conn->prepare("
                    SELECT page_no FROM hafiz_sessions
                    WHERE revision_id = ? AND status = 'rejected' AND page_no < ?
                    ORDER BY page_no ASC LIMIT 1
                ");
                $stmt->bind_param("ii", $revision_id, $current_page);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                if ($r) return (int)$r['page_no'];
            } catch (Throwable $e) { /* ignore */ }
        }
        return $current_page;
    }
}

if (!function_exists('hafiz_ensure_advanced')) {
    /**
     * Idempotently advance the revision pointer past a submitted page so the
     * student is never stuck on a page that was already recorded.
     * Sets current_page = max(current_page, page_no + 1), capped at 605;
     * reaching 605 marks the cycle complete.
     */
    function hafiz_ensure_advanced($conn, $revision_id, $page_no) {
        $revision_id = (int)$revision_id;
        $page_no = (int)$page_no;
        if (!db_table_exists($conn, 'hafiz_revision')) return;

        try {
            $stmt = $conn->prepare("
                UPDATE hafiz_revision
                SET current_page = LEAST(605, GREATEST(current_page, ? + 1))
                WHERE id = ?
            ");
            $stmt->bind_param("ii", $page_no, $revision_id);
            $stmt->execute();

            // Reaching 605 means every page has been recited — complete the cycle.
            $stmt = $conn->prepare("
                UPDATE hafiz_revision
                SET status = 'completed', completed_at = NOW()
                WHERE id = ? AND current_page >= 605 AND status <> 'completed'
            ");
            $stmt->bind_param("i", $revision_id);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('hafiz advance failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('hafiz_record_submission')) {
    /**
     * Record a recitation submission for a page.
     *  - New page        : INSERT a pending session.
     *  - Flagged page    : retry resets the existing row to 'pending' (no
     *                      duplicate-key crash), keeping one row per page.
     *  - Already pending : acknowledged in place with a friendly message.
     *  - Already accepted: acknowledged in place with a friendly message.
     * The revision pointer always advances past a submitted page (idempotently),
     * so the student is never stuck on a page that was already recorded.
     * Returns ['ok' => bool, 'reason' => string].
     */
    function hafiz_record_submission($conn, $student_id, $revision_id, $page_no, $session_type, $audio_file = null) {
        $student_id = (int)$student_id;
        $revision_id = (int)$revision_id;
        $page_no = (int)$page_no;
        if (!db_table_exists($conn, 'hafiz_sessions')) {
            return ['ok' => false, 'reason' => 'Recitation tracking is not set up yet.'];
        }
        $now = date('Y-m-d H:i:s');

        try {
            $stmt = $conn->prepare("SELECT id, status FROM hafiz_sessions WHERE revision_id = ? AND page_no = ? LIMIT 1");
            $stmt->bind_param("ii", $revision_id, $page_no);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
        } catch (Throwable $e) {
            error_log('hafiz lookup failed: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'Could not save your recitation. Please try again.'];
        }

        try {
            if ($existing) {
                if ($existing['status'] === 'accepted') {
                    // Already accepted — the recitation is recorded; just make sure
                    // the pointer isn't stuck behind it.
                    hafiz_ensure_advanced($conn, $revision_id, $page_no);
                    return ['ok' => false, 'reason' => 'This page has already been accepted. Masha\'Allah!'];
                }
                if ($existing['status'] === 'pending') {
                    // Already pending — must never leave the student stuck on this
                    // page, so advance past it before telling them.
                    hafiz_ensure_advanced($conn, $revision_id, $page_no);
                    return ['ok' => false, 'reason' => 'This page is already awaiting your teacher\'s review. You can move on to the next page.'];
                }
                // status is 'rejected' — this is a retry: reset the row to pending
                $sid = (int)$existing['id'];
                if ($audio_file !== null) {
                    $upd = $conn->prepare("
                        UPDATE hafiz_sessions
                        SET session_type = ?, audio_file = ?, status = 'pending', rating = NULL,
                            feedback = NULL, admin_audio_feedback = NULL, submitted_at = ?, reviewed_at = NULL
                        WHERE id = ?
                    ");
                    $upd->bind_param("sssi", $session_type, $audio_file, $now, $sid);
                } else {
                    $upd = $conn->prepare("
                        UPDATE hafiz_sessions
                        SET session_type = ?, status = 'pending', rating = NULL, feedback = NULL,
                            submitted_at = ?, reviewed_at = NULL
                        WHERE id = ?
                    ");
                    $upd->bind_param("sssi", $session_type, $now, $sid);
                }
                $upd->execute();
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO hafiz_sessions (student_id, revision_id, page_no, session_type, audio_file, status, submitted_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?)
                ");
                $stmt->bind_param("iiisss", $student_id, $revision_id, $page_no, $session_type, $audio_file, $now);
                $stmt->execute();
            }
        } catch (Throwable $e) {
            error_log('hafiz submit failed: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'Could not save your recitation. Please try again.'];
        }

        // Always advance past the submitted page (idempotent, concurrency-safe).
        hafiz_ensure_advanced($conn, $revision_id, $page_no);

        return ['ok' => true, 'reason' => 'OK'];
    }
}

/* =====================================================
   HAFIZ WEEKLY TEST
   ===================================================== */

if (!function_exists('hafiz_test_time_limit')) {
    /**
     * Weekly test timed limits.
     *  - time_limit : minutes student has to answer before the test locks.
     *  - grace      : extra minutes allowed before the draft is voided.
     *  - total      : combined deadline (20 + 5 = 25 minutes).
     */
    function hafiz_test_time_limit() {
        return [
            'time_limit' => 20,
            'grace'      => 5,
            'total'      => 25,
        ];
    }
}

if (!function_exists('hafiz_test_deadline')) {
    /**
     * Return the 'Y-m-d H:i:s' deadline for a started_at value:
     * started_at + (time limit + grace).
     */
    function hafiz_test_deadline($started_at) {
        $t = hafiz_test_time_limit();
        return date('Y-m-d H:i:s', strtotime($started_at) + $t['total'] * 60);
    }
}

if (!function_exists('hafiz_get_latest_test')) {
    /**
     * The most recent hafiz_weekly_tests row for this revision/week, or null.
     */
    function hafiz_get_latest_test($conn, $revision_id, $week_no) {
        $revision_id = (int)$revision_id;
        $week_no = (int)$week_no;
        if (!db_table_exists($conn, 'hafiz_weekly_tests')) return null;
        try {
            $stmt = $conn->prepare("
                SELECT * FROM hafiz_weekly_tests
                WHERE revision_id = ? AND week_no = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->bind_param("ii", $revision_id, $week_no);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('hafiz_expire_stale_draft')) {
    /**
     * If the latest test is a draft that has blown past its deadline, mark it
     * 'expired' (the student must start over). Returns the test row afterwards.
     */
    function hafiz_expire_stale_draft($conn, $revision_id, $week_no) {
        $test = hafiz_get_latest_test($conn, $revision_id, $week_no);
        if (!$test) return null;
        if ($test['status'] === 'draft' && strtotime($test['started_at']) < strtotime(date('Y-m-d H:i:s')) - (hafiz_test_time_limit()['total'] * 60)) {
            try {
                $stmt = $conn->prepare("UPDATE hafiz_weekly_tests SET status = 'expired' WHERE id = ?");
                $stmt->bind_param("i", (int)$test['id']);
                $stmt->execute();
                $test['status'] = 'expired';
            } catch (Throwable $e) { /* ignore */ }
        }
        return $test;
    }
}

if (!function_exists('hafiz_week_test_qualified')) {
    /**
     * True when the weekly test for the relevant juz (hafiz_test_juz) may be
     * shown / taken: the juz is fully accepted by the teacher. The test's own
     * status (passed/pending/failed/etc.) is handled separately by the state
     * machine.
     */
    function hafiz_week_test_qualified($conn, $revision) {
        if (!$revision) return false;
        $revision_id = (int)$revision['id'];
        $juz = hafiz_test_juz($conn, $revision);
        if ($juz <= 0) return false;
        return hafiz_juz_complete($conn, $revision_id, $juz);
    }
}

if (!function_exists('hafiz_week_test_state')) {
    /**
     * State machine for the weekly test of the relevant juz (hafiz_test_juz).
     * Returns ['state' => ..., 'test' => row|null, 'reason' => string].
     *  - locked     : relevant juz not fully accepted by the teacher yet.
     *  - available  : juz complete, no pending/active test - can Generate.
     *  - in_progress: a draft is active (deadline not yet hit).
     *  - expired    : a draft exists but the deadline passed - must restart.
     *  - pending    : submitted, awaiting teacher review.
     *  - passed     : approved for this juz - next juz unlocked.
     *  - failed     : must retake before continuing to the next juz.
     */
    function hafiz_week_test_state($conn, $revision) {
        if (!$revision) return ['state' => 'locked', 'test' => null, 'reason' => 'No active revision cycle.'];

        $revision_id = (int)$revision['id'];
        $week_no = hafiz_test_juz($conn, $revision);

        if ($week_no <= 0) {
            return ['state' => 'locked', 'test' => null, 'reason' => 'No active revision cycle.'];
        }

        if (!hafiz_juz_complete($conn, $revision_id, $week_no)) {
            $p = hafiz_juz_progress($conn, $revision_id, $week_no);
            $reason = "Your teacher must approve every page of Juz {$week_no} before the weekly test unlocks. Accepted: {$p['accepted']}/{$p['total']} pages of Juz {$week_no}.";

            // List the exact pages still not accepted, with their review status,
            // so the teacher knows precisely what to approve.
            $rng = hafiz_juz_range($week_no);
            $missing = [];
            if (db_table_exists($conn, 'hafiz_sessions')) {
                try {
                    $stmt = $conn->prepare("SELECT page_no, status FROM hafiz_sessions WHERE revision_id = ? AND page_no >= ? AND page_no <= ?");
                    $stmt->bind_param("iii", $revision_id, $rng[0], $rng[1]);
                    $stmt->execute();
                    $byPage = [];
                    $rows = $stmt->get_result();
                    while ($row = $rows->fetch_assoc()) $byPage[(int)$row['page_no']] = $row['status'];
                    for ($pg = $rng[0]; $pg <= $rng[1]; $pg++) {
                        if (!isset($byPage[$pg])) {
                            $missing[] = "page $pg (not recited yet)";
                        } elseif ($byPage[$pg] !== 'accepted') {
                            $missing[] = "page $pg ({$byPage[$pg]})";
                        }
                    }
                } catch (Throwable $e) { /* ignore */ }
            }
            if ($missing) $reason .= ' Still to approve: ' . implode(', ', $missing) . '.';

            return ['state' => 'locked', 'test' => null, 'reason' => $reason];
        }

        $test = hafiz_expire_stale_draft($conn, $revision_id, $week_no);

        if (!$test) {
            return ['state' => 'available', 'test' => null, 'reason' => 'Juz ' . $week_no . ' is complete. Generate your weekly test to begin — the timer starts immediately.'];
        }

        switch ($test['status']) {
            case 'passed':
                return ['state' => 'passed', 'test' => $test, 'reason' => 'You passed this juz\'s test. JazakAllahu khayran!'];
            case 'failed':
                return ['state' => 'failed', 'test' => $test, 'reason' => 'Retake this juz\'s test to unlock your next pages.'];
            case 'submitted':
                return ['state' => 'pending', 'test' => $test, 'reason' => 'Your test is with the teacher for review.'];
            case 'expired':
                return ['state' => 'expired', 'test' => $test, 'reason' => 'Time ran out. Generate a new test to start over.'];
            case 'draft':
            default:
                // A draft with no questions is a dead-end (nothing to answer and
                // no way to finish) — expire it so the student can regenerate.
                if (db_table_exists($conn, 'hafiz_test_answers')) {
                    $cnt = -1;
                    try {
                        $stmt = $conn->prepare("SELECT COUNT(*) c FROM hafiz_test_answers WHERE test_id = ?");
                        $stmt->bind_param("i", (int)$test['id']);
                        $stmt->execute();
                        $cnt = (int)$stmt->get_result()->fetch_assoc()['c'];
                    } catch (Throwable $e) { /* ignore */ }
                    if ($cnt === 0) {
                        try {
                            $u = $conn->prepare("UPDATE hafiz_weekly_tests SET status = 'expired' WHERE id = ?");
                            $u->bind_param("i", (int)$test['id']);
                            $u->execute();
                        } catch (Throwable $e) { /* ignore */ }
                        return ['state' => 'expired', 'test' => $test, 'reason' => 'Your previous test had no questions. Generate a fresh weekly test to begin.'];
                    }
                }
                $deadline = hafiz_test_deadline($test['started_at']);
                if (strtotime($deadline) < time()) {
                    return ['state' => 'expired', 'test' => $test, 'reason' => 'Time ran out. Generate a new test to start over.'];
                }
                return ['state' => 'in_progress', 'test' => $test, 'reason' => 'Complete this test before the timer runs out.'];
        }
    }
}

if (!function_exists('hafiz_week_test_block_recite')) {
    /**
     * True when the juz test gate says the student may NOT recite new pages:
     * the pointer sits at the first page of a juz whose previous juz is not
     * yet fully accepted and passed. Used for banner messaging.
     */
    function hafiz_week_test_block_recite($conn, $revision) {
        if (!$revision) return false;
        $gate = hafiz_juz_gate($conn, $revision);
        return !$gate['ok'];
    }
}

if (!function_exists('hafiz_surah_verse_count')) {
    /**
     * Total verses of a surah. Uses the immutable 114-surah Madani verse count
     * array directly (0 DB queries) for instant, fail-safe O(1) lookups without
     * MySQL connection throttling.
     */
    function hafiz_surah_verse_count($conn, $surah_id) {
        $surah_id = (int)$surah_id;
        if ($surah_id < 1 || $surah_id > 114) return 0;
        static $counts = [1=>7,2=>286,3=>200,4=>176,5=>120,6=>165,7=>206,8=>75,9=>129,10=>109,11=>123,12=>111,13=>43,14=>52,15=>99,16=>128,17=>111,18=>110,19=>98,20=>135,21=>112,22=>78,23=>118,24=>64,25=>77,26=>227,27=>93,28=>88,29=>69,30=>60,31=>34,32=>30,33=>73,34=>54,35=>45,36=>83,37=>182,38=>88,39=>75,40=>85,41=>54,42=>53,43=>89,44=>59,45=>37,46=>35,47=>38,48=>29,49=>18,50=>45,51=>60,52=>49,53=>62,54=>55,55=>78,56=>96,57=>29,58=>22,59=>24,60=>13,61=>14,62=>11,63=>11,64=>18,65=>12,66=>12,67=>30,68=>52,69=>52,70=>44,71=>28,72=>28,73=>20,74=>56,75=>40,76=>31,77=>50,78=>40,79=>46,80=>42,81=>29,82=>19,83=>36,84=>25,85=>22,86=>17,87=>19,88=>26,89=>30,90=>20,91=>15,92=>21,93=>11,94=>8,95=>8,96=>19,97=>5,98=>8,99=>8,100=>11,101=>11,102=>8,103=>3,104=>9,105=>5,106=>4,107=>7,108=>3,109=>6,110=>3,111=>5,112=>4,113=>5,114=>6];
        return $counts[$surah_id] ?? 0;
    }
}

if (!function_exists('hafiz_page_verse_span')) {
    /**
     * Resolve the verse span actually covered by a single physical page using
     * the quran_pages index, with a fallback to the static
     * config/quran_pages_data.php map so question generation never silently
     * depends on the DB table being populated. Returns
     * ['start' => ['surah'=>, 'verse'=>], 'end' => ['surah'=>, 'verse'=>]]
     * or null if the page is unknown.
     */
    function hafiz_page_verse_span($conn, $page_no) {
        $page_no = (int)$page_no;

        if ($page_no < 1 || $page_no > 604) return null;

        $cur = null;
        $nxt = null;

        // Static Madani index (config/quran_pages_data.php) covers the whole
        // 604 pages and ships with the app, so it is the authoritative source.
        // Preferring it here means a missing/partial quran_pages DB table can
        // never break question generation.
        static $index = null;
        if ($index === null) {
            $index = [];
            try {
                $loaded = require __DIR__ . '/../quran_pages_data.php';
                if (is_array($loaded)) $index = $loaded;
            } catch (Throwable $e) { /* ignore */ }
        }
        if (isset($index[$page_no])) {
            $cur = ['page_no' => $page_no, 'surah_id' => (int)$index[$page_no]['surah'], 'verse' => (int)$index[$page_no]['verse']];
        }
        if (isset($index[$page_no + 1])) {
            $nxt = ['page_no' => $page_no + 1, 'surah_id' => (int)$index[$page_no + 1]['surah'], 'verse' => (int)$index[$page_no + 1]['verse']];
        }

        // DB fallback (only when the static index is unavailable or incomplete).
        if (!$cur || !$nxt) {
            if (db_table_exists($conn, 'quran_pages')) {
                try {
                    $nxt_page = $page_no + 1;
                    $stmt = $conn->prepare("SELECT page_no, surah_id, verse FROM quran_pages WHERE page_no = ? OR page_no = ? ORDER BY page_no ASC");
                    $stmt->bind_param("ii", $page_no, $nxt_page);
                    $stmt->execute();
                    $rows = $stmt->get_result();
                    while ($r = $rows->fetch_assoc()) {
                        if (!$cur && (int)$r['page_no'] === $page_no) $cur = $r;
                        elseif (!$nxt && (int)$r['page_no'] === $nxt_page) $nxt = $r;
                    }
                } catch (Throwable $e) { /* ignore */ }
            }
        }

        if (!$cur) return null;

        if (!$nxt) {
            // Page 604 is the final page of the Qur'an: it ends at the end of Surah 114 (An-Nas).
            if ($page_no !== 604) return null;
            return [
                'start' => ['surah' => (int)$cur['surah_id'], 'verse' => (int)$cur['verse']],
                'end'   => ['surah' => 114, 'verse' => hafiz_surah_verse_count($conn, 114)],
            ];
        }

        $end_s = (int)$nxt['surah_id'];
        $end_v = (int)$nxt['verse'] - 1;

        // A page whose next page opens a NEW surah at verse 1 ends at that
        // previous surah's final verse, never at "verse 0".
        if ($end_v < 1) {
            $end_s = $end_s - 1;
            $end_v = hafiz_surah_verse_count($conn, $end_s);
            if ($end_v < 1) $end_v = 1;
        }

        return [
            'start' => ['surah' => (int)$cur['surah_id'], 'verse' => (int)$cur['verse']],
            'end'   => ['surah' => $end_s, 'verse' => $end_v],
        ];
    }
}

if (!function_exists('hafiz_accepted_pages')) {
    /**
     * Ordered list of page numbers accepted so far in the given revision cycle.
     */
    function hafiz_accepted_pages($conn, $revision_id) {
        $revision_id = (int)$revision_id;
        if (!db_table_exists($conn, 'hafiz_sessions')) return [];
        try {
            $stmt = $conn->prepare("SELECT DISTINCT page_no FROM hafiz_sessions WHERE revision_id = ? AND status = 'accepted' ORDER BY page_no ASC");
            $stmt->bind_param("i", $revision_id);
            $stmt->execute();
            $out = [];
            $rows = $stmt->get_result();
            while ($r = $rows->fetch_assoc()) $out[] = (int)$r['page_no'];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('hafiz_recited_pages')) {
    /**
     * Ordered list of page numbers recited (submitted) so far in the given
     * revision cycle, regardless of review status.
     */
    function hafiz_recited_pages($conn, $revision_id) {
        $revision_id = (int)$revision_id;
        if (!db_table_exists($conn, 'hafiz_sessions')) return [];
        try {
            $stmt = $conn->prepare("SELECT DISTINCT page_no FROM hafiz_sessions WHERE revision_id = ? ORDER BY page_no ASC");
            $stmt->bind_param("i", $revision_id);
            $stmt->execute();
            $out = [];
            $rows = $stmt->get_result();
            while ($r = $rows->fetch_assoc()) $out[] = (int)$r['page_no'];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('hafiz_windows_for_pages')) {
    /**
     * Generate candidate question windows from an array of physical page numbers.
     * Decomposes pages into surah-bounded runs, then generates passage windows
     * with 4 passes to ensure even small pools or short surahs have ample distinct
     * questions.
     */
    function hafiz_windows_for_pages($conn, $pages) {
        if (!is_array($pages) || empty($pages)) return [];

        // Decompose every accepted page into ordered, surah-bounded verse chunks
        $chunks = [];
        foreach ($pages as $pn) {
            $span = hafiz_page_verse_span($conn, (int)$pn);
            if (!$span) continue;
            $start_s = (int)$span['start']['surah'];
            $start_v = (int)$span['start']['verse'];
            $end_s   = (int)$span['end']['surah'];
            $end_v   = (int)$span['end']['verse'];

            $s = $start_s;
            $v = $start_v;
            $guard = 0;
            while ($guard++ < 150) {
                $tv = hafiz_surah_verse_count($conn, $s);
                if ($tv < 1) { $s++; $v = 1; continue; }

                $seg_to = ($s === $end_s) ? $end_v : $tv;
                if ($seg_to >= $v) {
                    $chunks[] = [
                        'page_no'  => (int)$pn,
                        'surah_id' => $s,
                        'from'     => $v,
                        'to'       => $seg_to,
                    ];
                }

                if ($s === $end_s) break;
                $s++;
                $v = 1;
            }
        }
        if (!$chunks) return [];

        // Merge consecutive chunks of the same surah into contiguous runs
        $runs = [];
        foreach ($chunks as $c) {
            $ri = count($runs) - 1;
            if ($ri >= 0 && (int)$runs[$ri]['surah_id'] === (int)$c['surah_id'] && (int)$c['from'] === (int)$runs[$ri]['to'] + 1) {
                $runs[$ri]['to'] = $c['to'];
                $runs[$ri]['pages'][] = ['page_no' => $c['page_no'], 'from' => $c['from'], 'to' => $c['to']];
            } else {
                $runs[] = [
                    'surah_id' => $c['surah_id'],
                    'from'     => $c['from'],
                    'to'       => $c['to'],
                    'pages'    => [['page_no' => $c['page_no'], 'from' => $c['from'], 'to' => $c['to']]],
                ];
            }
        }

        $find_page = function ($run, $v) {
            foreach ($run['pages'] as $pg) {
                if ($v >= $pg['from'] && $v <= $pg['to']) return (int)$pg['page_no'];
            }
            return (int)($run['pages'][0]['page_no'] ?? 0);
        };

        $windows = [];

        // Pass 1: Standard 10-15 verse non-overlapping windows
        foreach ($runs as $run) {
            $run_to = (int)$run['to'];
            $pos = (int)$run['from'];
            while ($run_to - $pos + 1 >= 10) {
                $remaining = $run_to - $pos + 1;
                $len = rand(10, min(15, $remaining));
                $jitter = ($remaining - $len > 0) ? rand(0, min(2, $remaining - $len)) : 0;
                $start = $pos + $jitter;

                $windows[] = [
                    'page_no'    => $find_page($run, $start),
                    'surah_id'   => (int)$run['surah_id'],
                    'from_verse' => $start,
                    'to_verse'   => $start + $len - 1,
                ];
                $pos = $start + $len;
            }
        }

        // Pass 2: Shorter runs (5-9 verses) as complete passage questions
        foreach ($runs as $run) {
            $total = (int)$run['to'] - (int)$run['from'] + 1;
            if ($total >= 5 && $total < 10) {
                $windows[] = [
                    'page_no'    => $find_page($run, (int)$run['from']),
                    'surah_id'   => (int)$run['surah_id'],
                    'from_verse' => (int)$run['from'],
                    'to_verse'   => (int)$run['to'],
                ];
            }
        }

        // Pass 3: Sliding sampling with step offset for runs >= 10
        foreach ($runs as $run) {
            $total = (int)$run['to'] - (int)$run['from'] + 1;
            if ($total >= 10) {
                $len = min(10, $total);
                for ($offset = 3; $offset <= $total - $len; $offset += 4) {
                    $start = (int)$run['from'] + $offset;
                    $windows[] = [
                        'page_no'    => $find_page($run, $start),
                        'surah_id'   => (int)$run['surah_id'],
                        'from_verse' => $start,
                        'to_verse'   => $start + $len - 1,
                    ];
                }
            }
        }

        // Pass 4: Fallback for small runs or pages to guarantee ample candidate windows
        if (count($windows) < 4) {
            foreach ($runs as $run) {
                $total = (int)$run['to'] - (int)$run['from'] + 1;
                for ($subLen = min($total, 7); $subLen >= 2; $subLen--) {
                    for ($start = (int)$run['from']; $start <= (int)$run['to'] - $subLen + 1; $start++) {
                        $windows[] = [
                            'page_no'    => $find_page($run, $start),
                            'surah_id'   => (int)$run['surah_id'],
                            'from_verse' => $start,
                            'to_verse'   => $start + $subLen - 1,
                        ];
                        if (count($windows) >= 12) break;
                    }
                    if (count($windows) >= 12) break;
                }
            }
        }

        // Deduplicate windows
        $unique = [];
        $seen = [];
        foreach ($windows as $w) {
            $k = $w['page_no'] . '-' . $w['surah_id'] . '-' . $w['from_verse'] . '-' . $w['to_verse'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $unique[] = $w;
            }
        }

        return $unique;
    }
}

if (!function_exists('hafiz_generate_questions')) {
    /**
     * Build $count random questions drawn fairly across all completed juzs.
     * When any juz has been completed, it falls into the selection pool so
     * questions can be sampled from any completed juz. The total number of
     * generated questions is strictly guaranteed to be $count (default 4).
     *
     * Returns a list of ['page_no','surah_id','from_verse','to_verse'].
     */
    function hafiz_generate_questions($conn, $revision_id, $count = 4) {
        $count = (int)$count;
        if ($count <= 0) return [];

        $juz_map = hafiz_completed_juz_map($conn, $revision_id);
        if (empty($juz_map)) return [];

        // Generate windows for each completed juz
        $juz_windows = [];
        $eligible_juzs = [];
        foreach ($juz_map as $j => $pgs) {
            $wins = hafiz_windows_for_pages($conn, $pgs);
            if (!empty($wins)) {
                $juz_windows[$j] = $wins;
                $eligible_juzs[] = (int)$j;
            }
        }

        if (empty($eligible_juzs)) return [];
        sort($eligible_juzs);

        // Determine question slot allocation across completed juzs
        $allocation = [];
        foreach ($eligible_juzs as $j) $allocation[$j] = 0;

        $num_juzs = count($eligible_juzs);
        if ($num_juzs === 1) {
            // Only 1 completed juz: all questions come from this juz
            $allocation[$eligible_juzs[0]] = $count;
        } elseif ($num_juzs === 2) {
            // 2 completed juzs: balanced 2 from each
            $allocation[$eligible_juzs[0]] = (int)ceil($count / 2);
            $allocation[$eligible_juzs[1]] = $count - $allocation[$eligible_juzs[0]];
        } elseif ($num_juzs === 3) {
            // 3 completed juzs: at least 1 from each, bonus randomly allocated
            foreach ($eligible_juzs as $j) $allocation[$j] = 1;
            $bonus = $eligible_juzs[array_rand($eligible_juzs)];
            $allocation[$bonus] += ($count - 3);
        } elseif ($num_juzs === 4) {
            // 4 completed juzs: exactly 1 from each
            foreach ($eligible_juzs as $j) $allocation[$j] = 1;
        } else {
            // More than 4 completed juzs:
            // Randomly select $count distinct completed juzs (any completed juz can be chosen)
            $shuffled_juzs = $eligible_juzs;
            shuffle($shuffled_juzs);
            for ($i = 0; $i < $count; $i++) {
                $allocation[$shuffled_juzs[$i]] = 1;
            }
        }

        // Pick the allocated questions from each juz
        $selected = [];
        $used_keys = [];

        foreach ($allocation as $j => $target) {
            if ($target <= 0 || !isset($juz_windows[$j])) continue;
            $pool = $juz_windows[$j];
            shuffle($pool);
            $picked = 0;
            foreach ($pool as $w) {
                $k = $w['page_no'] . '-' . $w['surah_id'] . '-' . $w['from_verse'] . '-' . $w['to_verse'];
                if (!isset($used_keys[$k])) {
                    $used_keys[$k] = true;
                    $selected[] = $w;
                    $picked++;
                    if ($picked >= $target) break;
                }
            }
        }

        // If any juz had fewer questions than allocated, backfill from remaining unused candidate windows
        if (count($selected) < $count) {
            $all_remaining = [];
            foreach ($eligible_juzs as $j) {
                foreach ($juz_windows[$j] as $w) {
                    $k = $w['page_no'] . '-' . $w['surah_id'] . '-' . $w['from_verse'] . '-' . $w['to_verse'];
                    if (!isset($used_keys[$k])) {
                        $all_remaining[] = $w;
                    }
                }
            }
            shuffle($all_remaining);
            foreach ($all_remaining as $w) {
                $selected[] = $w;
                if (count($selected) >= $count) break;
            }
        }

        // Final shuffle so questions from different juzs are randomly ordered
        shuffle($selected);
        return array_slice($selected, 0, $count);
    }
}

if (!function_exists('hafiz_create_weekly_test')) {
    /**
     * Create a fresh draft weekly test for the revision's current juz with
     * $count random questions drawn from completed juzs. Returns the new test
     * row or null on failure. Stale drafts for the same juz are voided first.
     */
    function hafiz_create_weekly_test($conn, $student_id, $revision, $count = 4) {
        if (!$revision) return null;
        $student_id = (int)$student_id;
        $revision_id = (int)$revision['id'];
        $week_no = hafiz_test_juz($conn, $revision);
        if ($week_no <= 0) return null;

        // Void any stale draft so only one active test exists per week.
        foreach (['draft', 'expired'] as $st) {
            $stmt = $conn->prepare("UPDATE hafiz_weekly_tests SET status = 'expired' WHERE revision_id = ? AND week_no = ? AND status = ?");
            $stmt->bind_param("iis", $revision_id, $week_no, $st);
            $stmt->execute();
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT INTO hafiz_weekly_tests (student_id, revision_id, week_no, status, started_at) VALUES (?, ?, ?, 'draft', ?)");
        $stmt->bind_param("iiis", $student_id, $revision_id, $week_no, $now);
        if (!$stmt->execute()) return null;
        $test_id = (int)$conn->insert_id;

        $questions = hafiz_generate_questions($conn, $revision_id, $count);

        if (count($questions) < $count) {
            // Never leave an under-filled test: remove it and log diagnostic info
            try {
                $u = $conn->prepare("DELETE FROM hafiz_weekly_tests WHERE id = ?");
                $u->bind_param("i", $test_id);
                $u->execute();
                $pool = hafiz_completed_juz_pages($conn, $revision_id);
                @error_log("hafiz_generate_questions yielded " . count($questions) . " questions (< $count) (revision_id=$revision_id, week_no=$week_no, student_id=$student_id, accepted_pages=" . count($pool) . ")");
            } catch (Throwable $e) { /* ignore */ }
            return null;
        }

        $ins = $conn->prepare("INSERT INTO hafiz_test_answers (test_id, page_no, surah_id, from_verse, to_verse, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $p_page = 0; $p_surah = 0; $p_from = 0; $p_to = 0;
        $ins->bind_param("iiiii", $test_id, $p_page, $p_surah, $p_from, $p_to);
        foreach ($questions as $q) {
            $p_page = (int)$q['page_no'];
            $p_surah = (int)$q['surah_id'];
            $p_from = (int)$q['from_verse'];
            $p_to = (int)$q['to_verse'];
            $ins->execute();
        }

        return hafiz_get_latest_test($conn, $revision_id, $week_no);
    }
}

if (!function_exists('hafiz_week_test_answers')) {
    /**
     * All answer rows of a weekly test (join surah names), ordered by id.
     */
    function hafiz_week_test_answers($conn, $test_id) {
        $test_id = (int)$test_id;
        if (!db_table_exists($conn, 'hafiz_test_answers')) return [];
        try {
            $stmt = $conn->prepare("
                SELECT a.*, s.name_en AS surah_name, s.name_ar AS surah_name_ar
                FROM hafiz_test_answers a
                JOIN surahs s ON s.id = a.surah_id
                WHERE a.test_id = ?
                ORDER BY a.id ASC
            ");
            $stmt->bind_param("i", $test_id);
            $stmt->execute();
            $out = [];
            $rows = $stmt->get_result();
            while ($r = $rows->fetch_assoc()) $out[] = $r;
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('hafiz_test_result_for_student')) {
    /**
     * Convenience wrapper for dashboard/hafiz_revision card rendering.
     * Returns ['state', 'test', 'deadline', 'started_at', 'question_count'].
     */
    function hafiz_test_result_for_student($conn, $revision) {
        $st = hafiz_week_test_state($conn, $revision);
        $q_count = 0;
        if ($st['test']) {
            $test_id = (int)$st['test']['id'];
            if (db_table_exists($conn, 'hafiz_test_answers')) {
                try {
                    $stmt = $conn->prepare("SELECT COUNT(*) c FROM hafiz_test_answers WHERE test_id = ?");
                    $stmt->bind_param("i", $test_id);
                    $stmt->execute();
                    $q_count = (int)$stmt->get_result()->fetch_assoc()['c'];
                } catch (Throwable $e) { /* ignore */ }
            }
        }
        return [
            'state'          => $st['state'],
            'reason'         => $st['reason'],
            'test'           => $st['test'],
            'deadline'       => $st['test'] ? hafiz_test_deadline($st['test']['started_at']) : null,
            'started_at'     => $st['test'] ? $st['test']['started_at'] : null,
            'question_count' => $q_count,
        ];
    }
}

if (!function_exists('hafiz_completed_cycles_count')) {
    /**
     * Number of completed revision cycles for this student.
     */
    function hafiz_completed_cycles_count($conn, $student_id) {
        $student_id = (int)$student_id;
        if (!db_table_exists($conn, 'hafiz_revision')) return 0;
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM hafiz_revision WHERE student_id = ? AND status = 'completed'");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            return (int)$stmt->get_result()->fetch_assoc()['c'];
        } catch (Throwable $e) {
            return 0;
        }
    }
}

/* =====================================================
   EXAM GATING: Hafiz are exempt from exams
   ===================================================== */

if (!function_exists('student_in_exam')) {
    /**
     * True when this individual student's normal lessons are paused because
     * they were selected to participate in the currently-active exam term.
     * Hafiz are always exempt.
     */
    function student_in_exam($conn, $student_id) {
        if (student_is_hafiz($conn, $student_id)) return false;
        return exam_mode_on($conn) && student_exam_selected($conn, $student_id);
    }
}

if (!function_exists('student_exam_locked')) {
    /**
     * True when the student missed the current exam term and has not yet
     * gotten an approved result. Hafiz are always exempt.
     */
    function student_exam_locked($conn, $student_id) {
        if (student_is_hafiz($conn, $student_id)) return false;
        $student_id = (int)$student_id;
        try {
            $stmt = $conn->prepare("SELECT exam_defaulted FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r ? ((int)$r['exam_defaulted'] === 1) : false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('can_take_exam')) {
    /**
     * True when the student may sit the exam. Hafiz are always exempt.
     */
    function can_take_exam($conn, $student_id) {
        if (student_is_hafiz($conn, $student_id)) return false;
        return student_in_exam($conn, $student_id) || student_exam_access($conn, $student_id);
    }
}

if (!function_exists('finalize_exam_term')) {
    /**
     * Close the active exam term: switch exam mode off, then snapshot
     * participation. Students with NO attempt in this term become defaulters.
     * Hafiz students are excluded.
     */
    function finalize_exam_term($conn) {
        $term = exam_term_info($conn);
        $term_id = $term ? (int)$term['id'] : 0;

        if ($term && !$term['deactivated_at']) {
            $conn->query("UPDATE exam_terms SET deactivated_at = NOW(), finalized = 1 WHERE id = $term_id");
        }
        $conn->query("UPDATE app_settings SET setting_value = 'off' WHERE setting_key = 'exam_mode'");

        if ($term_id <= 0) return;

        $hafiz_filter = db_column_exists($conn, 'users', 'hafiz') ? 'AND u.hafiz = 0' : '';

        $res = $conn->query("
            SELECT u.id FROM users u
            WHERE u.role = 'student'
              AND u.exam_selected = 1
              $hafiz_filter
              AND NOT EXISTS (
                  SELECT 1 FROM exam_attempts ea
                  WHERE ea.student_id = u.id AND ea.term_id = $term_id
                    AND ea.status != 'draft'
              )
        ");
        $ids = [];
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
        if (!empty($ids)) {
            $ids_str = implode(',', $ids);
            try {
                $conn->query("
                    UPDATE users
                    SET exam_defaulted = 1, exam_owed = 1, exam_access = 0
                    WHERE id IN ($ids_str)
                ");
            } catch (Throwable $e) { /* ignore */ }
        }

        $res = $conn->query("
            SELECT DISTINCT ea.student_id FROM exam_attempts ea
            WHERE ea.term_id = $term_id AND ea.status = 'rejected'
              AND NOT EXISTS (
                  SELECT 1 FROM exam_attempts a2
                  WHERE a2.student_id = ea.student_id AND a2.term_id = $term_id AND a2.status = 'approved'
              )
        ");
        $ids = [];
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['student_id'];
        if (!empty($ids)) {
            $ids_str = implode(',', $ids);
            try {
                $conn->query("UPDATE users SET exam_defaulted = 1, exam_owed = 0, exam_access = 1 WHERE id IN ($ids_str)");
            } catch (Throwable $e) { /* ignore */ }
        }
    }
}

if (!function_exists('student_has_graduated')) {
    /**
     * True when the student has completed every surah of the Glorious Qur'an.
     * Hafiz students are handled differently (cycle-based completion).
     */
    function student_has_graduated($conn, $student_id) {
        if (student_is_hafiz($conn, $student_id)) return false;
        $student_id = (int)$student_id;
        try {
            $total = (int)$conn->query("SELECT COUNT(*) c FROM surahs")->fetch_assoc()['c'];
            if ($total <= 0) return false;
            $done = (int)$conn->query("
                SELECT COUNT(DISTINCT sl.surah_id) c
                FROM student_learning sl
                WHERE sl.student_id = $student_id AND sl.status = 'completed'
            ")->fetch_assoc()['c'];
            return $done >= $total;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mark_graduated_if_due')) {
    /**
     * Record the moment a student first reaches 100% completion.
     * Hafiz are excluded.
     */
    function mark_graduated_if_due($conn, $student_id) {
        if (student_is_hafiz($conn, $student_id)) return false;
        if (!student_has_graduated($conn, $student_id)) return false;
        $student_id = (int)$student_id;
        try {
            $r = $conn->query("SELECT graduated_at FROM users WHERE id = $student_id")->fetch_assoc();
            if ($r && empty($r['graduated_at'])) {
                $conn->query("UPDATE users SET graduated_at = NOW() WHERE id = $student_id");
                return true;
            }
        } catch (Throwable $e) { /* ignore */ }
        return false;
    }
}

if (!function_exists('purge_graduated_accounts')) {
    /**
     * Hard-delete every student account that graduated more than 7 days ago.
     * Hafiz are excluded.
     */
    function purge_graduated_accounts($conn) {
        try {
            $hafiz_filter = db_column_exists($conn, 'users', 'hafiz') ? 'AND hafiz = 0' : '';
            $res = $conn->query("
                SELECT id FROM users
                WHERE role = 'student' AND graduated_at IS NOT NULL
                  AND graduated_at < NOW() - INTERVAL 7 DAY
                  $hafiz_filter
            ");
            $ids = [];
            while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
            if (empty($ids)) return 0;
            $ids_str = implode(',', $ids);

            try {
                $audio = $conn->query("SELECT audio_file FROM exam_answers WHERE attempt_id IN (SELECT id FROM exam_attempts WHERE student_id IN ($ids_str))");
                $base = dirname(__DIR__, 2) . '/uploads/exam_audio/';
                while ($r = $audio->fetch_assoc()) {
                    $f = $base . basename((string)($r['audio_file'] ?? ''));
                    if ($f !== $base && is_file($f)) @unlink($f);
                }
            } catch (Throwable $e) { /* ignore */ }
            try {
                $pics = $conn->query("SELECT profile_image FROM users WHERE id IN ($ids_str)");
                $picBase = dirname(__DIR__, 2) . '/uploads/profile_pics/';
                while ($r = $pics->fetch_assoc()) {
                    $n = (string)($r['profile_image'] ?? '');
                    if ($n !== '' && $n !== 'default.png') {
                        $f = $picBase . basename($n);
                        if (is_file($f)) @unlink($f);
                    }
                }
            } catch (Throwable $e) { /* ignore */ }

            foreach (['student_learning', 'student_recitation', 'exam_answers', 'exam_attempts',
                      'certificates', 'announcement_reads', 'student_invites',
                      'admin_audio', 'donations', 'suggestions', 'feedback'] as $t) {
                try {
                    $conn->query("DELETE FROM `$t` WHERE student_id IN ($ids_str)");
                } catch (Throwable $e) { /* ignore */ }
            }
            // Also clean up hafiz tables
            foreach (['hafiz_sessions', 'hafiz_weekly_log', 'hafiz_revision'] as $t) {
                try {
                    $conn->query("DELETE FROM `$t` WHERE student_id IN ($ids_str)");
                } catch (Throwable $e) { /* ignore */ }
            }

            $conn->query("DELETE FROM users WHERE id IN ($ids_str)");
            return count($ids);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('maybe_auto_request_next_lesson')) {
    /**
     * Automatically create the next lesson request for a student whose
     * recitation of a surah was just accepted. Mirrors the guards and verse
     * maths used in student/request_lesson.php so a server-side request never
     * bypasses the restrictions a student would hit.
     *
     * Returns one of:
     *   'requested'              — next lesson created
     *   'skipped_exam'           — student is selected for the active exam
     *   'skipped_locked'         — student has an outstanding exam
     *   'skipped_holiday'        — holiday mode is active
     *   'skipped_no_plan'        — no active learning plan for this surah
     *   'skipped_surah_complete' — the whole surah has been completed
     *   'skipped_outstanding'    — another lesson still awaits an accepted recitation
     */
    function maybe_auto_request_next_lesson($conn, $student_id, $surah_id) {
        $student_id = (int)$student_id;
        $surah_id   = (int)$surah_id;
        if ($student_id <= 0 || $surah_id <= 0) return 'skipped_no_plan';

        if (student_in_exam($conn, $student_id)) return 'skipped_exam';
        if (student_exam_locked($conn, $student_id)) return 'skipped_locked';
        if (holiday_mode_on($conn)) return 'skipped_holiday';

        /* Active learning plan for this surah. */
        $has_start_verse = db_ensure_start_verse_column($conn);
        $stmt = $conn->prepare("
            SELECT sl.verses_per_request, s.total_verses"
            . ($has_start_verse ? ", sl.start_verse" : "") . "
            FROM student_learning sl
            JOIN surahs s ON s.id = sl.surah_id
            WHERE sl.student_id = ? AND sl.surah_id = ? AND sl.status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param("ii", $student_id, $surah_id);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        if (!$plan) return 'skipped_no_plan';

        $verses_per_request = (int)$plan['verses_per_request'];
        $total_verses       = (int)$plan['total_verses'];
        $plan_start_verse   = $has_start_verse ? max(1, (int)($plan['start_verse'] ?? 1)) : 1;

        /* Next portion begins right after the last ACCEPTED recitation. */
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

        $from_verse = $last_accepted
            ? max($plan_start_verse, (int)$last_accepted['to_verse'] + 1)
            : $plan_start_verse;

        if ($from_verse > $total_verses) return 'skipped_surah_complete';

        $to_verse = min($from_verse + $verses_per_request - 1, $total_verses);

        /* Guard: any OTHER lesson for this surah still without an accepted
           recitation blocks a new request (same rule as request_lesson.php). */
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
        if ($stmt->get_result()->fetch_assoc()) return 'skipped_outstanding';

        $stmt = $conn->prepare("
            INSERT INTO lessons (student_id, surah_id, from_verse, to_verse, status)
            VALUES (?, ?, ?, ?, 'requested')
        ");
        $stmt->bind_param("iiii", $student_id, $surah_id, $from_verse, $to_verse);
        $stmt->execute();

        return 'requested';
    }
}