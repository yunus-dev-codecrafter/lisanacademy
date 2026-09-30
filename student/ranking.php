<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');

$logged_in_id = (int)$_SESSION['user_id'];
$is_hafiz = student_is_hafiz($conn, $logged_in_id);
$is_memorizer = student_is_memorizing($conn, $logged_in_id);

/* Default tab = viewer's own category */
$default_scope = $is_hafiz ? 'hafiz' : ($is_memorizer ? 'memorizer' : 'nonhafiz');
$scope = $_GET['scope'] ?? $default_scope;
if (!in_array($scope, ['nonhafiz', 'hafiz', 'memorizer'], true)) $scope = $default_scope;

/* ----------------------------------
   NON-HAFIZ: lesson-request ranking (learners only)
----------------------------------- */
$students = [];
if ($scope === 'nonhafiz') {
    $hafiz_col = db_column_exists($conn, 'users', 'hafiz') ? 'COALESCE(u.hafiz,0)=0 AND ' : '';
    $mem_col = db_column_exists($conn, 'users', 'memorizing') ? 'COALESCE(u.memorizing,0)=0 AND ' : '';
    $result = $conn->query("
        SELECT
            u.id,
            u.name,
            COUNT(l.id) AS total_requests
        FROM users u
        LEFT JOIN lessons l ON l.student_id = u.id
        WHERE u.role = 'student' AND {$hafiz_col}{$mem_col}1=1
        GROUP BY u.id
        ORDER BY total_requests DESC, u.name ASC
    ");
    if ($result) while ($row = $result->fetch_assoc()) $students[] = $row;
    $top_requests = $students[0]['total_requests'] ?? 0;
    $top_count = 0;
    foreach ($students as $s) { if ((int)$s['total_requests'] === (int)$top_requests) $top_count++; }
    $show_star = ($top_requests > 0 && $top_count === 1);
    $top_student = $show_star ? $students[0] : null;
} elseif ($scope === 'hafiz') {
    /* HAFIZ: rank colleagues by revision score (accepted + cycles) */
    $students = hafiz_rank_list($conn);
    $top_student = $students[0] ?? null;
    $show_star = $top_student && (int)$top_student['score'] > 0 && (!isset($students[1]) || (int)$students[1]['score'] !== (int)$top_student['score']);
} else {
    /* MEMORIZER: rank colleague memorizers by pages memorized (never Huffaz) */
    $students = mem_rank_list($conn);
    $top_student = $students[0] ?? null;
    $show_star = $top_student && (int)$top_student['pages'] > 0 && (!isset($students[1]) || (int)$students[1]['pages'] !== (int)$top_student['pages']);
}

$scope_labels = ['nonhafiz' => 'Learners', 'hafiz' => 'Huffaz', 'memorizer' => 'Memorizers'];
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Ranking</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'ranking', 'Ranking', 'Leaderboard'); ?>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;" class="animate-rise">
    <?php foreach ($scope_labels as $key => $label): ?>
        <a class="btn btn-sm <?= $scope === $key ? 'btn-gold' : 'btn-ghost' ?>" href="ranking.php?scope=<?= $key ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</div>

<!-- STAR CARD -->
<div class="quran-star-card animate-rise" onclick="toggleRanking()">

<?php if ($show_star && $top_student): ?>
    <div class="quran-star-content">
        <h3><?= ui_icon('star', 20) ?> Real Qur’an Companion — <?= htmlspecialchars($scope_labels[$scope]) ?></h3>
        <p class="big"><?= htmlspecialchars($top_student['name']) ?></p>
        <?php if ($scope === 'nonhafiz'): ?>
            <span><?= (int)$top_student['total_requests'] ?> lesson requests</span>
        <?php elseif ($scope === 'hafiz'): ?>
            <span><?= (int)$top_student['score'] ?> pts · <?= (int)$top_student['accepted'] ?> pages accepted · <?= (int)$top_student['cycles'] ?> cycles</span>
        <?php else: ?>
            <span><?= (int)$top_student['pages'] ?> / 604 pages memorized</span>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="quran-star-content">
        <h3><?= ui_icon('book-open', 20) ?> Qur’an Reminder</h3>
        <p class="quran-reminder">
          “The most beloved deeds to Allah are those done consistently,
          even if they are small.”
        </p>
        <span><?= ui_icon('sprout', 14) ?> Be the one who takes the lead</span>
    </div>
<?php endif; ?>

</div>

<!-- RANKING LIST -->
<div id="rankingBox" style="display:none;">

<div class="table-wrap animate-rise d1">
<table class="table">
    <thead>
        <tr>
            <th>Rank</th>
            <th>Student</th>
            <th><?= $scope === 'nonhafiz' ? 'Requests' : ($scope === 'hafiz' ? 'Score' : 'Pages') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $position = 1;
    foreach ($students as $s):
        $is_you = ((int)$s['id'] === $logged_in_id);
    ?>
        <tr <?= $is_you ? 'style="background:var(--emerald-50);"' : '' ?>>
            <td>
                <span class="badge <?= $position === 1 ? 'badge-gold' : 'badge-grey' ?>"><?= $position === 1 ? ui_icon('trophy', 14) : '#' . $position ?></span>
            </td>
            <td>
                <strong><?= htmlspecialchars($s['name']) ?></strong>
                <?php if ($is_you): ?>
                    <span class="badge badge-green" style="margin-left:6px;">You</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($scope === 'nonhafiz'): ?>
                    <span class="badge badge-green"><?= (int)$s['total_requests'] ?></span>
                <?php elseif ($scope === 'hafiz'): ?>
                    <span class="badge badge-green"><?= (int)$s['score'] ?> pts</span>
                    <span class="small text-muted"><?= (int)$s['accepted'] ?> pg · <?= (int)$s['cycles'] ?> cyc</span>
                <?php else: ?>
                    <span class="badge badge-green"><?= (int)$s['pages'] ?> / 604</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php $position++; endforeach; ?>
    <?php if (empty($students)): ?>
        <tr><td colspan="3" class="text-center text-muted">No students in this category yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<div class="panel text-center" style="margin-top:16px;">
  <?php if ($scope === 'nonhafiz'): ?>
    Rankings update automatically based on lesson requests.<br>
  <?php elseif ($scope === 'hafiz'): ?>
    Huffaz are ranked by accepted revision pages plus completed cycles.<br>
  <?php else: ?>
    Memorizers are ranked by pages memorized (Huffaz excluded).<br>
  <?php endif; ?>
  Consistency is more beloved than competition <?= ui_icon('heart', 14) ?>
</div>
</div>

<?php ui_page_end(); ?>

<script>
function toggleRanking(){
  const box = document.getElementById('rankingBox');
  box.style.display = box.style.display === 'block' ? 'none' : 'block';
}
</script>

</body>
</html>
