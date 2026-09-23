<?php
require_once __DIR__ . '/../config/security/session.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* Opportunistic media cleanup: Muraja'ah videos (36h) + completed-cycle audios (24h) */
run_opportunistic_cleanup($conn);

$holiday_on = holiday_mode_on($conn);
$holiday_days = holiday_days_left($conn);

// ==========================
// STUDENT WITH MOST REQUESTS
// ==========================
$top_student = mysqli_fetch_assoc(
    mysqli_query(
        $conn,
        "SELECT u.id, u.name, COUNT(l.id) AS requests_count
         FROM users u
         LEFT JOIN lessons l ON l.student_id = u.id
         GROUP BY u.id
         ORDER BY requests_count DESC
         LIMIT 1"
    )
);

// ==========================
// TOTAL STUDENTS
// ==========================
$total_students = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role='student'")
)['c'];

// ==========================
// REAL PENDING RECITATIONS
// (must JOIN users/lessons/surahs like teaching.php Section A so orphan
// rows are never counted — otherwise the badge shows N while the page
// is empty)
// ==========================
$pending_recitations_submissions = mysqli_fetch_assoc(
    mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c
         FROM student_recitation sr
         JOIN users u ON u.id = sr.student_id
         JOIN lessons l ON l.id = sr.learning_plan_id
         JOIN surahs s ON s.id = l.surah_id
         WHERE sr.status = 'pending'
           AND sr.student_deleted = 0"
    )
)['c'];

// ==========================
// PENDING LESSON REQUESTS
// (no admin audio yet; must JOIN users/surahs like teaching.php Section B)
// ==========================
$pending_lesson_requests = mysqli_fetch_assoc(
    mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c
         FROM lessons l
         JOIN users u ON u.id = l.student_id
         JOIN surahs s ON s.id = l.surah_id
         LEFT JOIN admin_audio aa
            ON aa.learning_plan_id = l.id
         WHERE l.status = 'requested'
           AND aa.id IS NULL"
    )
)['c'];

// ==========================
// PENDING LIVE RECITATION REQUESTS
// (status still pending; must JOIN users/lessons/surahs like Section C)
// ==========================
$pending_live_recitations = mysqli_fetch_assoc(
    mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c
         FROM live_recitation_requests lr
         JOIN users u ON u.id = lr.student_id
         JOIN lessons l ON l.id = lr.lesson_id
         JOIN surahs s ON s.id = l.surah_id
         WHERE lr.status = 'pending'"
    )
)['c'];

// ==========================
// PENDING MURAJA'AH REVIEWS (Memorizers)
// Uses the same queue helper as teaching.php Section F (JOINs users,
// requires the memorization engine) so hidden rows are never counted.
// ==========================
$pending_murajaah = function_exists('mem_admin_queue') ? count(mem_admin_queue($conn)) : 0;

// ==========================
// PENDING ASSISTANCE REQUESTS (Section G on Teaching)
// ==========================
$pending_assistance = function_exists('mem_assistance_pending') ? count(mem_assistance_pending($conn)) : 0;
$pending_mem_total  = $pending_murajaah + $pending_assistance;

// ==========================
// PENDING HAFIZ REVISION SESSIONS
// Matches teaching.php Section D: pending+rejected WITH joins so the
// dashboard total always equals the teaching page.
// ==========================
$pending_hafiz_sessions = 0;
if (db_table_exists($conn, 'hafiz_sessions') && db_table_exists($conn, 'hafiz_revision')) {
    $pending_hafiz_sessions = (int)mysqli_fetch_assoc(
        mysqli_query(
            $conn,
            "SELECT COUNT(*) AS c FROM hafiz_sessions hs
             JOIN users u ON u.id = hs.student_id
             JOIN hafiz_revision hr ON hr.id = hs.revision_id
             WHERE hs.status IN ('pending','rejected')"
        )
    )['c'];
}

// ==========================
// PENDING HAFIZ WEEKLY TESTS
// ==========================
$pending_hafiz_tests = 0;
if (db_table_exists($conn, 'hafiz_weekly_tests')) {
    $pending_hafiz_tests = (int)mysqli_fetch_assoc(
        mysqli_query(
            $conn,
            "SELECT COUNT(*) AS c FROM hafiz_weekly_tests t
             JOIN users u ON u.id = t.student_id
             WHERE t.status = 'submitted'"
        )
    )['c'];
}

// ==========================
// TOTAL PENDING (all combined)
// ==========================
$pending_recitations = (int)$pending_recitations_submissions
                     + (int)$pending_lesson_requests
                     + (int)$pending_live_recitations
                     + (int)$pending_murajaah
                     + (int)$pending_assistance
                     + (int)$pending_hafiz_sessions
                     + (int)$pending_hafiz_tests;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
<?= ui_css() ?>
</head>

<?php ui_page_start('admin', 'dashboard', 'Dashboard'); ?>

<div class="hero-banner animate-rise">
    <span class="hero-banner-ico"><?= ui_icon('grid', 26) ?></span>
    <div style="flex:1;min-width:220px;">
        <h1>Assalamu alaikum</h1>
        <p><span id="liveClock">—</span> · Here’s what’s happening across the academy.</p>
    </div>
    <div class="hero-banner-actions">
        <a class="btn btn-gold btn-sm" href="teaching.php"><?= ui_icon('book', 15) ?> Teaching<?php if ($pending_recitations > 0): ?> (<?= $pending_recitations ?>)<?php endif; ?></a>
        <a class="btn btn-sm btn-outline-light" href="applications.php"><?= ui_icon('clipboard', 15) ?> Applications</a>
    </div>
</div>

<?php if ($holiday_on): ?>
    <div class="alert alert-warning animate-rise d1">
        <?= ui_icon('clock', 18) ?>
        <span style="flex:1;"><strong>Holiday mode is active.</strong> Students are locked out of learning and see the holiday notice page (<?= $holiday_days !== null ? "about $holiday_days day(s) remaining" : 'auto-deactivate pending' ?>).</span>
        <a class="btn btn-gold btn-sm" href="holiday_settings.php"><?= ui_icon('clock', 15) ?> Manage Holiday</a>
    </div>
<?php endif; ?>

<?php
/* Digital Islamiyya — publish-readiness for the admin's dashboard card.
 * A book only ever ships live when both hold: its record is status=live
 * AND every lesson + question pack is uploaded. Everything else stays
 * sealed ("Coming Soon") until the admin finishes the full pack. */
$islamiyya_live_count = 0;
$islamiyya_total      = 0;
foreach ((islamiyya_books($conn) ?? []) as $b) {
    $islamiyya_total++;
    if (islamiyya_book_is_live($conn, $b)) $islamiyya_live_count++;
}
$islamiyya_coming_count = $islamiyya_total - $islamiyya_live_count;
?>
<div class="stat-grid animate-rise d1">
    <div class="stat-card stat-green">
        <span class="stat-ico"><?= ui_icon('users', 22) ?></span>
        <span class="stat-label">Total Students</span>
        <span class="stat-value"><?= $total_students ?></span>
        <span class="stat-sub">Enrolled in the academy</span>
    </div>

    <a href="all_students_requests.php" class="stat-card top gold">
        <span class="stat-ico"><?= ui_icon('trophy', 22) ?></span>
        <span class="stat-label">Top Student</span>
        <span class="stat-value"><?= htmlspecialchars($top_student['name'] ?? 'No students') ?></span>
        <span class="stat-pill"><?= (int)($top_student['requests_count'] ?? 0) ?> requests</span>
    </a>

    <div class="stat-card stat-gold">
        <span class="stat-ico"><?= ui_icon('clock', 22) ?></span>
        <span class="stat-label">Pending Work</span>
        <span class="stat-value"><?= $pending_recitations ?></span>
        <span class="stat-sub">Recitations · lessons · live · hafiz · memorizer</span>
    </div>

    <a href="islamiyya.php" class="stat-card stat-blue" title="Digital Islamiyya overview — readiness counts">
        <span class="stat-ico"><?= ui_icon('book-open', 22) ?></span>
        <span class="stat-label">Islamiyya Overview</span>
        <span class="stat-value"><?= $islamiyya_live_count ?> <span class="small text-muted">/ <?= $islamiyya_total ?> live</span></span>
        <span class="stat-sub"><?= $islamiyya_coming_count > 0 ? $islamiyya_coming_count . ' book(s) still being prepared' : 'all books live for students' ?></span>
    </a>
</div>

<div class="section-label animate-rise d2"><?= ui_icon('grid', 16) ?> Quick Actions</div>
<div class="action-grid animate-rise d2">
    <a href="teaching.php" class="action-card action-gold">
        <span class="ac-ico"><?= ui_icon('book', 24) ?></span>
        <span class="ac-title">Teaching</span>
        <span class="ac-sub">Lesson requests &amp; recitations</span>
        <?php if ($pending_recitations > 0): ?>
            <span class="badge badge-count ac-badge"><?= $pending_recitations ?> pending</span>
        <?php endif; ?>
    </a>

    <a href="students.php" class="action-card action-forest">
        <span class="ac-ico"><?= ui_icon('users', 24) ?></span>
        <span class="ac-title">Students</span>
        <span class="ac-sub">View and manage students</span>
    </a>

    <?php if (db_table_exists($conn, 'quran_memorization')): ?>
    <a href="memorization_students.php" class="action-card action-gold">
        <span class="ac-ico"><?= ui_icon('star', 24) ?></span>
        <span class="ac-title">Memorization</span>
        <span class="ac-sub">Memorizer progress &amp; Muraja&#8217;ah review</span>
        <?php if ($pending_mem_total > 0): ?>
            <span class="badge badge-count ac-badge"><?= $pending_mem_total ?> pending</span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <a href="announcements.php" class="action-card action-emerald">
        <span class="ac-ico"><?= ui_icon('bell', 24) ?></span>
        <span class="ac-title">Announcements</span>
        <span class="ac-sub">Post and manage announcements</span>
    </a>

    <a href="islamiyya.php" class="action-card action-purple">
        <span class="ac-ico"><?= ui_icon('book-open', 24) ?></span>
        <span class="ac-title">Manage Islamiyya</span>
        <span class="ac-sub">Upload lessons, quizzes &amp; publish</span>
        <?php if ($islamiyya_live_count > 0): ?>
            <span class="badge badge-count ac-badge"><?= $islamiyya_live_count ?> live</span>
        <?php elseif ($islamiyya_coming_count > 0): ?>
            <span class="badge badge-count ac-badge"><?= $islamiyya_coming_count ?> coming soon</span>
        <?php endif; ?>
    </a>

    <a href="suggestions.php" class="action-card action-blue">
        <span class="ac-ico"><?= ui_icon('bulb', 24) ?></span>
        <span class="ac-title">Suggestions</span>
        <span class="ac-sub">Read student feedback</span>
    </a>

    <a href="invites.php" class="action-card action-gold">
        <span class="ac-ico"><?= ui_icon('gift', 24) ?></span>
        <span class="ac-title">Invites &amp; Referrals</span>
        <span class="ac-sub">Student invites &amp; 15% rewards</span>
    </a>
</div>

<?php ui_page_end(); ?>

<script>
(function(){
    var el = document.getElementById('liveClock');
    if (!el) return;
    function tick(){
        var d = new Date();
        el.textContent = d.toLocaleString('en-GB', {
            weekday: 'long', day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }
    tick();
    setInterval(tick, 30000);
})();
</script>

</body>
</html>