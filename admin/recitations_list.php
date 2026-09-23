<?php
require '../config/security/helpers.php';
require_role('admin');
require '../auth/auth_check.php';
require '../config/db.php';

/* ── Build optional UNION branches ────────────────────────────────────────── */
$hafiz_union = '';
if (db_table_exists($conn, 'hafiz_sessions')) {
    $hafiz_union = "
    UNION ALL
    (
        SELECT hs.id AS recitation_id,
               CONVERT(hs.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
               CONVERT(hs.rating USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rating,
               CONVERT(hs.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
                CONVERT(u.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_name,
                CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_email,
                NULL AS lesson_id,
                CONCAT('Hafiz — Page ', hs.page_no) COLLATE utf8mb4_unicode_ci AS surah_name,
                hs.page_no AS from_verse, hs.page_no AS to_verse,
                'Hafiz Revision' COLLATE utf8mb4_unicode_ci AS rec_type
        FROM hafiz_sessions hs
        JOIN users u ON u.id = hs.student_id
        ORDER BY hs.id DESC
    )";
}

$murajaah_union = '';
if (db_table_exists($conn, 'quran_murajaah_sessions')) {
    $murajaah_union = "
    UNION ALL
    (
        SELECT qms.id AS recitation_id,
               CONVERT(qms.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
               NULL AS rating,
               CONVERT(qms.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
                CONVERT(u.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_name,
                CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_email,
                NULL AS lesson_id,
                CONCAT(\"Muraja'ah — Day \", qms.task_day) COLLATE utf8mb4_unicode_ci AS surah_name,
                qms.start_page AS from_verse, qms.end_page AS to_verse,
                \"Muraja'ah\" COLLATE utf8mb4_unicode_ci AS rec_type
        FROM quran_murajaah_sessions qms
        JOIN users u ON u.id = qms.student_id
        ORDER BY qms.id DESC
    )";
}

/* ── Main query with UNIONs ─────────────────────────────────────────────────
   Every string column carries an explicit COLLATE so the UNION never fails
   with "Illegal mix of collations" when legacy tables (latin1/utf8/general_ci)
   are combined with newer utf8mb4 tables or with string literals such as
   'Hafiz — Page …' (the em-dash forces a collation coercion). */
$recitations = $conn->query("
    (
        SELECT sr.id AS recitation_id,
               CONVERT(sr.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
               CONVERT(sr.rating USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rating,
               CONVERT(sr.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
               CONVERT(u.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_name,
               CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_email,
                l.id AS lesson_id, CONVERT(s.name_en USING utf8mb4) COLLATE utf8mb4_unicode_ci AS surah_name,
                l.from_verse, l.to_verse,
                'Standard' COLLATE utf8mb4_unicode_ci AS rec_type
        FROM student_recitation sr
        JOIN users u ON u.id = sr.student_id
        JOIN lessons l ON l.id = sr.learning_plan_id
        JOIN surahs s ON s.id = l.surah_id
        WHERE sr.student_deleted = 0
        ORDER BY sr.id DESC
    )
    $hafiz_union
    $murajaah_union
    ORDER BY recitation_id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Recitations</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'recitations', 'Recitations', 'Review'); ?>

<div class="page-hero animate-rise">
    <h1>Student Recitation Requests</h1>
    <p>Full history of every recitation submission — standard, Hafiz, and Memorizer.</p>
</div>

<?php if (!$recitations || $recitations->num_rows === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('notes', 40) ?></div>
        <div class="empty-title">No recitation requests found</div>
    </div>
<?php else: ?>
    <div class="table-wrap animate-rise d1">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Student</th>
                <th>Surah / Section</th>
                <th>Range</th>
                <th>Status</th>
                <th>Rating</th>
                <th>Feedback</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($r = $recitations->fetch_assoc()): ?>
            <tr>
                <td><span class="badge badge-grey">#<?= $r['recitation_id'] ?></span></td>
                <td>
                    <?php
                    $type_label = $r['rec_type'] ?? 'Standard';
                    $type_class = match ($type_label) {
                        'Hafiz Revision' => 'badge-blue',
                        "Muraja'ah"      => 'badge-purple',
                        default          => 'badge-grey',
                    };
                    ?>
                    <span class="badge <?= $type_class ?>"><?= htmlspecialchars($type_label) ?></span>
                </td>
                <td>
                    <strong><?= htmlspecialchars($r['student_name']) ?></strong><br>
                    <span class="small text-muted"><?= htmlspecialchars($r['student_email']) ?></span>
                </td>
                <td><?= htmlspecialchars($r['surah_name']) ?></td>
                <td><?= (int)$r['from_verse'] ?> – <?= (int)$r['to_verse'] ?></td>
                <td>
                    <?php
                    $pill = $r['status'] === 'accepted' || $r['status'] === 'passed'
                        ? 'badge-green'
                        : ($r['status'] === 'rejected' || $r['status'] === 'failed' ? 'badge-red' : 'badge-grey');
                    ?>
                    <span class="badge <?= $pill ?>"><?= htmlspecialchars($r['status']) ?></span>
                </td>
                <td><?= htmlspecialchars($r['rating'] ?? '—') ?></td>
                <td class="cell-wrap"><?= nl2br(htmlspecialchars((string)$r['feedback'])) ?></td>
                <td>
                    <?php if ($r['rec_type'] === 'Standard' || !isset($r['rec_type'])): ?>
                    <form method="post" action="/admin/delete_recitation.php" onsubmit="return confirm('Are you sure you want to delete this recitation request?');">
                        <input type="hidden" name="rec_id" value="<?= $r['recitation_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><?= ui_icon('trash', 15) ?> Delete</button>
                    </form>
                    <?php else: ?>
                        <span class="small text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>