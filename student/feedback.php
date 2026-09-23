<?php
require '../config/security/helpers.php';
require_role('student');
require '../auth/auth_check.php';
require '../config/db.php';

$student_id = (int)$_SESSION['user_id'];

/* ── Mark all unseen accepted/rejected recitations as seen silently ────────
   Instead of redirecting away (which caused a loop), mark them here and show
   a banner below so the student sees the result without leaving this page.
   Wrapped in try/catch so a schema drift (missing feedback_seen column) can
   never 500 this page — the banner is best-effort. */
$has_new = false;
try {
    $mark_unseen = $conn->prepare("
        SELECT id FROM student_recitation
        WHERE student_id = ? AND student_deleted = 0
          AND status IN ('accepted','rejected') AND feedback_seen = 0
    ");
    $mark_unseen->bind_param("i", $student_id);
    $mark_unseen->execute();
    $unseen_ids = $mark_unseen->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!empty($unseen_ids)) {
        $has_new = true;
        $ids_flat = implode(',', array_map('intval', array_column($unseen_ids, 'id')));
        $conn->query("UPDATE student_recitation SET feedback_seen = 1 WHERE id IN ($ids_flat)");
    }
} catch (Throwable $e) {
    // Schema drift or transient DB error: log and continue with $has_new=false.
    error_log('feedback.php mark_unseen skipped: ' . $e->getMessage());
}

/* ── Helper: only use an optional UNION branch when every column it needs ──
   actually exists. The old code checked table existence only, so a partially
   migrated table (table present, new columns missing) threw
   "Unknown column …" and, via mysqli exceptions, 500'd the whole page. */
$branch_ready = function ($table, array $cols) use ($conn) {
    if (!db_table_exists($conn, $table)) return false;
    foreach ($cols as $c) {
        if (!db_column_exists($conn, $table, $c)) return false;
    }
    return true;
};

/* ── Build optional UNION branches for extra table types ────────────────────
   Every string expression carries COLLATE utf8mb4_unicode_ci so mixing legacy
   tables (latin1/utf8/general_ci) with newer utf8mb4 tables or with literals
   never raises "Illegal mix of collations for operation 'UNION'". */

/* Qur'an Muraja'ah (Memorizer) */
$murajaah_sql = '';
if ($branch_ready('quran_murajaah_sessions', ['task_day','start_page','end_page','feedback','admin_audio_feedback','status','submitted_at','reviewed_at','student_id'])) {
    $murajaah_sql = "
    UNION ALL
    (
        SELECT
            qms.id,
            CONCAT('Day ', qms.task_day) COLLATE utf8mb4_unicode_ci AS rating,
            CONVERT(qms.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
            CONVERT(qms.admin_audio_feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS admin_audio_feedback,
            CONVERT(qms.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            qms.start_page AS from_verse,
            qms.end_page AS to_verse,
            'Qur\\'an Muraja\\'ah' COLLATE utf8mb4_unicode_ci AS surah_name,
            'murajaah' COLLATE utf8mb4_unicode_ci AS type,
            COALESCE(qms.reviewed_at, qms.submitted_at) AS sort_date
        FROM quran_murajaah_sessions qms
        WHERE qms.student_id = $student_id
          AND qms.status IN ('passed','failed')
    )";
}

/* Hafiz Revision Sessions */
$hafiz_sql = '';
if ($branch_ready('hafiz_sessions', ['page_no','rating','feedback','admin_audio_feedback','status','submitted_at','reviewed_at','student_id'])) {
    $hafiz_sql = "
    UNION ALL
    (
        SELECT
            hs.id,
            CONVERT(hs.rating USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rating,
            CONVERT(hs.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
            CONVERT(hs.admin_audio_feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS admin_audio_feedback,
            CONVERT(hs.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            hs.page_no AS from_verse,
            hs.page_no AS to_verse,
            CONCAT('Page ', hs.page_no) COLLATE utf8mb4_unicode_ci AS surah_name,
            'hafiz' COLLATE utf8mb4_unicode_ci AS type,
            COALESCE(hs.reviewed_at, hs.submitted_at) AS sort_date
        FROM hafiz_sessions hs
        WHERE hs.student_id = $student_id
          AND hs.status IN ('accepted','rejected')
    )";
}

/* Hafiz Weekly Tests */
$hafiz_test_sql = '';
if ($branch_ready('hafiz_weekly_tests', ['week_no','admin_feedback','admin_audio_file','status','submitted_at','reviewed_at','student_id'])) {
    $hafiz_test_sql = "
    UNION ALL
    (
        SELECT
            hwt.id,
            CONVERT(hwt.admin_feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rating,
            CONVERT(hwt.admin_feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
            CONVERT(hwt.admin_audio_file USING utf8mb4) COLLATE utf8mb4_unicode_ci AS admin_audio_feedback,
            CONVERT(hwt.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            hwt.week_no AS from_verse,
            hwt.week_no AS to_verse,
            CONCAT('Week ', hwt.week_no, ' Test') COLLATE utf8mb4_unicode_ci AS surah_name,
            'hafiz_test' COLLATE utf8mb4_unicode_ci AS type,
            COALESCE(hwt.reviewed_at, hwt.submitted_at) AS sort_date
        FROM hafiz_weekly_tests hwt
        WHERE hwt.student_id = $student_id
          AND hwt.status IN ('passed','failed')
    )";
}

/* Memorizer Assistance Responses */
$assistance_sql = '';
if ($branch_ready('mem_assistance_requests', ['start_page','end_page','admin_notes','admin_audio','status','created_at','resolved_at','student_id'])) {
    $assistance_sql = "
    UNION ALL
    (
        SELECT
            mar.id,
            NULL AS rating,
            CONVERT(mar.admin_notes USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
            CONVERT(mar.admin_audio USING utf8mb4) COLLATE utf8mb4_unicode_ci AS admin_audio_feedback,
            'done' COLLATE utf8mb4_unicode_ci AS status,
            mar.start_page AS from_verse,
            mar.end_page AS to_verse,
            CONCAT('Page ', mar.start_page, ' Assistance') COLLATE utf8mb4_unicode_ci AS surah_name,
            'assistance' COLLATE utf8mb4_unicode_ci AS type,
            COALESCE(mar.resolved_at, mar.created_at) AS sort_date
        FROM mem_assistance_requests mar
        WHERE mar.student_id = $student_id
          AND mar.status = 'done'
          AND (mar.admin_notes IS NOT NULL OR mar.admin_audio IS NOT NULL)
    )";
}

/* ── Main unified query ─────────────────────────────────────────────────────
   Tried as one UNION first; on any failure (collation, missing column, …)
   we fall back to the two core branches so the student still sees their main
   feedback instead of a 500 page. The failure is logged for the admin. */
$base_sql = "
    (
        SELECT
            sr.id,
            CONVERT(sr.rating USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rating,
            CONVERT(sr.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
            CONVERT(sr.admin_audio_feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS admin_audio_feedback,
            CONVERT(sr.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            l.from_verse,
            l.to_verse,
            CONVERT(s.name_en USING utf8mb4) COLLATE utf8mb4_unicode_ci AS surah_name,
            'audio' COLLATE utf8mb4_unicode_ci AS type,
            sr.submitted_at AS sort_date
        FROM student_recitation sr
        JOIN lessons l ON l.id = sr.learning_plan_id
        JOIN surahs s ON s.id = l.surah_id
        WHERE sr.student_id = $student_id
          AND sr.student_deleted = 0
          AND sr.status IN ('accepted','rejected')
    )

    UNION ALL

    (
        SELECT
            lr.id,
            NULL AS rating,
            CONCAT('Live recitation scheduled for ', lr.preferred_date, ' at ', lr.preferred_time) COLLATE utf8mb4_unicode_ci AS feedback,
            NULL AS admin_audio_feedback,
            CONVERT(lr.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            l.from_verse,
            l.to_verse,
            CONVERT(s.name_en USING utf8mb4) COLLATE utf8mb4_unicode_ci AS surah_name,
            'live' COLLATE utf8mb4_unicode_ci AS type,
            lr.created_at AS sort_date
        FROM live_recitation_requests lr
        JOIN lessons l ON l.id = lr.lesson_id
        JOIN surahs s ON s.id = l.surah_id
        WHERE lr.student_id = $student_id
          AND lr.status IN ('accepted','rejected')
    )
";
$q = false;
try {
    $q = $conn->query("
        $base_sql
        $murajaah_sql
        $hafiz_sql
        $hafiz_test_sql
        $assistance_sql
        ORDER BY sort_date DESC
    ");
} catch (Throwable $e) {
    error_log('feedback.php unified UNION failed, falling back to core branches: ' . $e->getMessage());
    try {
        $q = $conn->query("$base_sql ORDER BY sort_date DESC");
    } catch (Throwable $e2) {
        error_log('feedback.php core feedback query failed: ' . $e2->getMessage());
        $q = false;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Admin Feedback</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'feedback', 'Feedback', 'Your Reviews'); ?>

<div class="page-hero animate-rise">
    <h1>Admin Feedback on Your Recitations</h1>
    <p>Guidance from your teacher to help you perfect your recitation.</p>
</div>

<?php if ($has_new): ?>
    <div class="alert alert-success animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('check-circle', 18) ?>
        <span style="flex:1;"><strong>New feedback!</strong> Your teacher has reviewed one or more of your recitations — see the results below.</span>
    </div>
<?php endif; ?>

<?php if (!$q || $q->num_rows === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('chat', 40) ?></div>
        <div class="empty-title">No feedback available</div>
        <p class="small" style="margin:0;">Feedback from your teacher will appear here once a submission has been reviewed.</p>
    </div>
<?php endif; ?>

<?php if ($q): while ($row = $q->fetch_assoc()): ?>

<div class="feedback-card animate-rise d1">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px;">
        <?php if ($row['type'] === 'murajaah'): ?>
            <span class="type-badge" style="background:#7c3aed;color:#fff;">MURAJA'AH ASSESSMENT</span>
        <?php elseif ($row['type'] === 'hafiz'): ?>
            <span class="type-badge" style="background:#0e7490;color:#fff;">HAFIZ REVISION</span>
        <?php elseif ($row['type'] === 'hafiz_test'): ?>
            <span class="type-badge" style="background:#1e40af;color:#fff;">HAFIZ WEEKLY TEST</span>
        <?php elseif ($row['type'] === 'assistance'): ?>
            <span class="type-badge" style="background:#b45309;color:#fff;">RECITATION ASSISTANCE</span>
        <?php else: ?>
            <span class="type-badge <?= $row['type'] === 'live' ? 'live' : 'audio' ?>"><?= strtoupper($row['type']) ?> RECITATION</span>
        <?php endif; ?>

        <?php
        $positive_statuses = ['accepted', 'passed', 'done'];
        $is_positive = in_array($row['status'], $positive_statuses, true);
        ?>
        <span class="status <?= $is_positive ? 'status-accepted' : 'status-rejected' ?>"><?= strtoupper($row['status']) ?></span>
    </div>

    <h3 style="margin:4px 0;"><?= htmlspecialchars($row['surah_name']) ?></h3>

    <?php if ($row['type'] === 'hafiz_test'): ?>
        <p class="small text-muted" style="margin:0 0 10px;">Weekly Revision Test</p>
    <?php elseif ($row['type'] === 'assistance'): ?>
        <p class="small text-muted" style="margin:0 0 10px;">Pages <?= (int)$row['from_verse'] ?> – <?= (int)$row['to_verse'] ?></p>
    <?php elseif (in_array($row['type'], ['murajaah', 'hafiz'], true)): ?>
        <p class="small text-muted" style="margin:0 0 10px;">Page <?= (int)$row['from_verse'] ?><?= $row['from_verse'] !== $row['to_verse'] ? ' – ' . (int)$row['to_verse'] : '' ?></p>
    <?php else: ?>
        <p class="small text-muted" style="margin:0 0 10px;">Verses <?= (int)$row['from_verse'] ?> – <?= (int)$row['to_verse'] ?></p>
    <?php endif; ?>

    <?php if ($row['rating'] !== null && trim((string)$row['rating']) !== '' && $row['type'] !== 'hafiz_test'): ?>
        <p class="small" style="margin:0 0 6px;"><strong><?= in_array($row['type'], ['murajaah','hafiz'], true) ? 'Assessment' : 'Rating' ?>:</strong> <?= htmlspecialchars($row['rating']) ?></p>
    <?php endif; ?>

    <?php if (!empty($row['feedback'])): ?>
        <p style="margin:0 0 6px;"><strong>Admin Feedback:</strong><br>
            <?= nl2br(htmlspecialchars($row['feedback'])) ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($row['admin_audio_feedback']) && file_exists(__DIR__ . '/../uploads/admin_feedback/' . $row['admin_audio_feedback'])): ?>
        <p class="small" style="margin:10px 0 4px;"><strong>Audio Feedback:</strong></p>
        <?= media_player_html($row['admin_audio_feedback'], '../uploads/admin_feedback/') ?>
    <?php elseif ($row['type'] === 'audio'): ?>
        <p class="small text-muted" style="margin-top:10px;">No audio feedback yet.</p>
    <?php endif; ?>

    <?php if ($row['type'] === 'audio'): ?>
    <form method="post" action="delete_feedback.php"
          onsubmit="return confirm('Delete this feedback?');" style="margin-top:14px;">
        <input type="hidden" name="recitation_id" value="<?= (int)$row['id'] ?>">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-danger"><?= ui_icon('trash', 15) ?> Delete Feedback</button>
    </form>
    <?php endif; ?>

</div>

<?php endwhile; endif; ?>

<?php ui_page_end(); ?>

</body>
</html>