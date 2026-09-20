<?php
require '../config/security/helpers.php';
require_role('admin');
require '../auth/auth_check.php';
require '../config/db.php';

/* Fetch all student recitations */
$recitations = $conn->query("
    SELECT sr.id AS recitation_id, sr.status, sr.rating, sr.feedback,
           u.name AS student_name, u.email AS student_email,
           l.id AS lesson_id, s.name_en AS surah_name,
           l.from_verse, l.to_verse
    FROM student_recitation sr
    JOIN users u ON u.id = sr.student_id
    JOIN lessons l ON l.id = sr.learning_plan_id
    JOIN surahs s ON s.id = l.surah_id
    WHERE sr.student_deleted = 0
    ORDER BY sr.id DESC
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
    <p>Full history of every recitation submission.</p>
</div>

<?php if ($recitations->num_rows === 0): ?>
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
                <th>Student</th>
                <th>Surah</th>
                <th>Verses</th>
                <th>Status</th>
                <th>Rating</th>
                <th>Feedback</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while($r = $recitations->fetch_assoc()): ?>
            <tr>
                <td><span class="badge badge-grey">#<?= $r['recitation_id'] ?></span></td>
                <td>
                    <strong><?= htmlspecialchars($r['student_name']) ?></strong><br>
                    <span class="small text-muted"><?= htmlspecialchars($r['student_email']) ?></span>
                </td>
                <td><?= htmlspecialchars($r['surah_name']) ?></td>
                <td><?= (int)$r['from_verse'] ?> – <?= (int)$r['to_verse'] ?></td>
                <td>
                    <?php
                    $pill = $r['status'] === 'accepted' ? 'badge-green' : ($r['status'] === 'rejected' ? 'badge-red' : 'badge-grey');
                    ?>
                    <span class="badge <?= $pill ?>"><?= htmlspecialchars($r['status']) ?></span>
                </td>
                <td><?= htmlspecialchars($r['rating'] ?? '—') ?></td>
                <td class="cell-wrap"><?= nl2br(htmlspecialchars($r['feedback'])) ?></td>
                <td>
                    <form method="post" action="/admin/delete_recitation.php" onsubmit="return confirm('Are you sure you want to delete this recitation request?');">
                        <input type="hidden" name="rec_id" value="<?= $r['recitation_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><?= ui_icon('trash', 15) ?> Delete</button>
                    </form>
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