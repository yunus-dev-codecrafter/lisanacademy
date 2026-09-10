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

if (!function_exists('hafiz_current_week_no')) {
    /**
     * Calculate the current week number from a revision row's current_page.
     * Pages 1-20 = week 1, 21-40 = week 2, etc.
     */
    function hafiz_current_week_no($revision) {
        $page = (int)($revision['current_page'] ?? 1);
        return (int)ceil($page / 20);
    }
}

if (!function_exists('hafiz_pages_this_week')) {
    /**
     * Count of accepted hafiz_sessions for the given week in the given cycle.
     */
    function hafiz_pages_this_week($conn, $revision_id, $week_no) {
        $revision_id = (int)$revision_id;
        $week_no = (int)$week_no;
        if (!db_table_exists($conn, 'hafiz_sessions') || !db_table_exists($conn, 'hafiz_revision')) return 0;

        $from_page = ($week_no - 1) * 20 + 1;
        $to_page = $week_no * 20;

        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) c FROM hafiz_sessions
                WHERE revision_id = ? AND page_no >= ? AND page_no <= ? AND status = 'accepted'
            ");
            $stmt->bind_param("iii", $revision_id, $from_page, $to_page);
            $stmt->execute();
            return (int)$stmt->get_result()->fetch_assoc()['c'];
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('hafiz_week_quota_met')) {
    /**
     * True when the student has recited at least 20 pages this week.
     */
    function hafiz_week_quota_met($conn, $revision_id, $week_no) {
        return hafiz_pages_this_week($conn, $revision_id, $week_no) >= 20;
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
     * Detect if the weekly cycle has expired (past Friday). If so:
     *  - If quota met or skip approved: log the week as complete, start new week.
     *  - Else: RESET progress to page 1 (new cycle).
     *
     * Returns ['action' => 'ok'|'reset'|'new_week', 'reason' => string].
     */
    function hafiz_check_week_transition($conn, $student_id) {
        $student_id = (int)$student_id;
        $revision = hafiz_get_active_revision($conn, $student_id);
        if (!$revision) return ['action' => 'ok', 'reason' => 'no active revision'];

        $week_started_at = $revision['week_started_at'];
        $now = date('Y-m-d H:i:s');
        $friday = hafiz_friday_boundary($week_started_at);

        // If we haven't passed the Friday boundary yet, no transition needed
        if (strtotime($now) < strtotime($friday)) {
            return ['action' => 'ok', 'reason' => 'still in current week'];
        }

        // We've passed the Friday boundary — check last week's quota
        $revision_id = (int)$revision['id'];
        $week_no = hafiz_current_week_no($revision);
        $pages = hafiz_pages_this_week($conn, $revision_id, $week_no);
        $quota_met = $pages >= 20;
        $skip_approved = (int)$revision['skip_approved'] === 1;

        if ($quota_met || $skip_approved) {
            // Log the completed week
            $was_skipped = $skip_approved && !$quota_met ? 1 : 0;
            try {
                $stmt = $conn->prepare("
                    INSERT INTO hafiz_weekly_log (revision_id, student_id, week_no, pages_completed, target_met, was_skipped, week_started_at, week_ended_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        pages_completed = VALUES(pages_completed),
                        target_met = VALUES(target_met),
                        was_skipped = VALUES(was_skipped),
                        week_ended_at = NOW()
                ");
                $target_met_db = $quota_met ? 1 : 0;
                $stmt->bind_param("iiiisis", $revision_id, $student_id, $week_no, $pages, $target_met_db, $was_skipped, $week_started_at);
                $stmt->execute();
            } catch (Throwable $e) { /* ignore */ }

            // Start new week
            try {
                $stmt = $conn->prepare("
                    UPDATE hafiz_revision
                    SET week_started_at = NOW(), skip_approved = 0, skip_approved_at = NULL
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $revision_id);
                $stmt->execute();
            } catch (Throwable $e) { /* ignore */ }

            return ['action' => 'new_week', 'reason' => 'week completed, new week started'];
        }

        // Missed quota, no approval — RESET
        $new_cycle = (int)$revision['cycle_no'] + 1;
        try {
            // Log the failed week
            $was_skipped = 0;
            $target_met_db = 0;
            $stmt = $conn->prepare("
                INSERT INTO hafiz_weekly_log (revision_id, student_id, week_no, pages_completed, target_met, was_skipped, week_started_at, week_ended_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    pages_completed = VALUES(pages_completed),
                    target_met = VALUES(target_met),
                    was_skipped = VALUES(was_skipped),
                    week_ended_at = NOW()
            ");
            $stmt->bind_param("iiiisis", $revision_id, $student_id, $week_no, $pages, $target_met_db, $was_skipped, $week_started_at);
            $stmt->execute();
        } catch (Throwable $e) { /* ignore */ }

        try {
            $stmt = $conn->prepare("
                UPDATE hafiz_revision
                SET current_page = 1, cycle_no = ?, week_started_at = NOW(),
                    skip_approved = 0, skip_approved_at = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("ii", $new_cycle, $revision_id);
            $stmt->execute();
        } catch (Throwable $e) { /* ignore */ }

        return ['action' => 'reset', 'reason' => 'weekly target not met, progress reset to page 1'];
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

        // Check for pending session
        if (db_table_exists($conn, 'hafiz_sessions')) {
            try {
                $stmt = $conn->prepare("SELECT id FROM hafiz_sessions WHERE student_id = ? AND status = 'pending' LIMIT 1");
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) {
                    return ['ok' => false, 'reason' => 'You already have a pending recitation awaiting review. Please wait for your teacher to review it.'];
                }
            } catch (Throwable $e) { /* ignore */ }
        }

        // Check weekly max (21 pages)
        $week_no = hafiz_current_week_no($revision);
        $pages = hafiz_pages_this_week($conn, (int)$revision['id'], $week_no);
        if ($pages >= 21) {
            return ['ok' => false, 'reason' => 'You have reached the weekly maximum of 21 pages. Well done! Wait for next week.'];
        }

        // Check week transition
        $transition = hafiz_check_week_transition($conn, $student_id);
        if ($transition['action'] === 'reset') {
            return ['ok' => false, 'reason' => 'You missed the weekly target. Your progress has been reset to page 1. Start a new cycle.'];
        }

        return ['ok' => true, 'reason' => ''];
    }
}

if (!function_exists('hafiz_weekly_skip_remaining')) {
    /**
     * Pages remaining this week before hitting the 21-page maximum.
     */
    function hafiz_weekly_skip_remaining($conn, $revision_id, $week_no) {
        $pages = hafiz_pages_this_week($conn, $revision_id, $week_no);
        return max(0, 21 - $pages);
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