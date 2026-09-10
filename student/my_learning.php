<?php
require '../config/security/helpers.php';
require_role('student');
require '../auth/auth_check.php';
require '../config/db.php';

$student_id = (int)$_SESSION['user_id'];

if (student_is_hafiz($conn, $student_id)) {
    header("Location: hafiz_revision.php");
    exit;
}

$exam_mode = student_in_exam($conn, $student_id);
$locked    = student_exam_locked($conn, $student_id);

/* =============================
   FETCH CURRENT ACTIVE PLAN
============================= */
$has_start_verse = db_ensure_start_verse_column($conn);
$active_plan_res = $conn->prepare("
    SELECT sl.id AS plan_id, sl.surah_id, sl.verses_per_request, sl.completed_requests,
           sl.status, s.name_en AS surah_name, s.name_ar AS surah_name_ar, s.total_verses"
           . ($has_start_verse ? ", sl.start_verse" : "") . "
    FROM student_learning sl
    JOIN surahs s ON s.id = sl.surah_id
    WHERE sl.student_id = ? AND sl.status = 'active'
    LIMIT 1
");
$active_plan_res->bind_param("i", $student_id);
$active_plan_res->execute();
$active_plan = $active_plan_res->get_result()->fetch_assoc();

if ($active_plan) {
    $active_plan['start_verse'] = $has_start_verse ? max(1, (int)($active_plan['start_verse'] ?? 1)) : 1;
}

/* =============================
   UPDATE ACTIVE PLAN PROGRESS
============================= */
$percentage = 0;
if ($active_plan) {
    $plan_id = (int)$active_plan['plan_id'];
    $surah_id = (int)$active_plan['surah_id'];

    // Count accepted recitations for this plan
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM student_recitation sr
        JOIN lessons l ON l.id = sr.learning_plan_id
        WHERE sr.student_id = ? AND l.surah_id = ? AND sr.status='accepted'
    ");
    $stmt->bind_param("ii", $student_id, $surah_id);
    $stmt->execute();
    $completed_requests = (int)$stmt->get_result()->fetch_assoc()['c'];

    $remaining_verses = max(1, (int)$active_plan['total_verses'] - (int)$active_plan['start_verse'] + 1);
    $total_requests = max(1, (int)ceil($remaining_verses / $active_plan['verses_per_request']));
    $percentage = round(($completed_requests / $total_requests) * 100);

    // Auto-complete plan if finished
    if ($completed_requests >= $total_requests) {
        $stmt = $conn->prepare("UPDATE student_learning SET status='completed', completed_requests=? WHERE id=?");
        $stmt->bind_param("ii", $completed_requests, $plan_id);
        $stmt->execute();
        $active_plan = null; // remove active plan so student can start new
    } else {
        $active_plan['completed_requests'] = $completed_requests;
    }
}

/* =============================
   LAST RECITATION STATUS
   (decides whether the student may request the next portion)
============================= */
$last_rec_status = null;
if ($active_plan) {
    $stmt = $conn->prepare("
        SELECT sr.status
        FROM student_recitation sr
        JOIN lessons l ON l.id = sr.learning_plan_id
        WHERE sr.student_id = ? AND l.surah_id = ?
        ORDER BY sr.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("ii", $student_id, $active_plan['surah_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $last_rec_status = $row['status'] ?? null;
}

/* =============================
   OUTSTANDING UNRESOLVED LESSON
   (requested but not delivered, or delivered but not yet accepted)
============================= */
$has_outstanding_lesson = false;
if ($active_plan) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) c
        FROM lessons l
        WHERE l.student_id = ? AND l.surah_id = ?
          AND NOT EXISTS (
              SELECT 1 FROM student_recitation sr
              WHERE sr.learning_plan_id = l.id AND sr.status = 'accepted'
          )
    ");
    $stmt->bind_param("ii", $student_id, $active_plan['surah_id']);
    $stmt->execute();
    $has_outstanding_lesson = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
}

/* =============================
   FETCH SURAH LIST (NOT STARTED OR ACTIVE)
============================= */
$completed_surah_ids = [];
$started_surah_ids = [];
$plan_res = $conn->prepare("
    SELECT surah_id, status FROM student_learning
    WHERE student_id = ?
");
$plan_res->bind_param("i", $student_id);
$plan_res->execute();
$res = $plan_res->get_result();
while($row = $res->fetch_assoc()){
    $started_surah_ids[] = (int)$row['surah_id'];
    if ($row['status'] === 'completed') {
        $completed_surah_ids[] = (int)$row['surah_id'];
    }
}

/* student_learning is unique per (student, surah) — a surah can only ever be
   started once, so ANY surah with an existing row (active, completed, or an
   odd leftover status) must not be offered again in the dropdown, otherwise
   starting it would hit the unique key and crash. */
$exclude_ids = $started_surah_ids;

// Safe query for surahs
if (!empty($exclude_ids)) {
    $ids_str = implode(',', $exclude_ids);
    $surah_where = "WHERE id NOT IN ($ids_str)";
} else {
    $surah_where = "";
}
$surahs_res = $conn->query("SELECT id, name_en AS name, name_ar, total_verses FROM surahs $surah_where ORDER BY id ASC");

/* =============================
   FETCH FEEDBACK COUNT
============================= */
$feedback_count_stmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM student_recitation sr
    JOIN lessons l ON l.id = sr.learning_plan_id
    WHERE sr.student_id = ? AND sr.status IN ('accepted','rejected')
      AND sr.feedback_seen = 0 AND sr.student_deleted = 0
");
$feedback_count_stmt->bind_param("i", $student_id);
$feedback_count_stmt->execute();
$feedback_count = (int)$feedback_count_stmt->get_result()->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html>
<head>
<title>My Learning</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'learning', 'My Learning', 'Journey'); ?>

<?php if ($exam_mode): ?>
<div class="alert alert-warning animate-rise">
    <?= ui_icon('alert', 18) ?>
    <span style="flex:1;"><strong>Exam mode is active.</strong> You cannot request new lessons or start new learning plans until the exam is concluded.</span>
    <a class="btn btn-gold btn-sm" href="exam.php"><?= ui_icon('notes', 15) ?> Take Exam</a>
</div>
<?php endif; ?>

<?php if ($locked): ?>
<div class="alert alert-danger animate-rise">
    <?= ui_icon('lock', 18) ?>
    <span style="flex:1;"><strong>You have an outstanding exam from the last term.</strong> Lessons and new plans stay paused until your exam is accepted (and any ₦500 fee is paid).</span>
    <a class="btn btn-gold btn-sm" href="exam.php"><?= ui_icon('notes', 15) ?> Pay / View Exam</a>
</div>
<?php endif; ?>

<div class="stat-grid animate-rise">
    <a class="stat-card stat-green" href="completed_surahs.php">
        <span class="stat-ico"><?= ui_icon('book', 20) ?></span>
        <span class="stat-label">Surahs Completed</span>
        <span class="stat-value"><?= count($completed_surah_ids) ?></span>
        <span class="stat-sub">View all completed surahs</span>
    </a>
    <?php if ($feedback_count > 0): ?>
    <a class="stat-card stat-gold" href="feedback.php">
        <span class="stat-ico"><?= ui_icon('chat', 20) ?></span>
        <span class="stat-label">New Feedback</span>
        <span class="stat-value"><?= $feedback_count ?></span>
        <span class="stat-sub">Awaiting your review</span>
    </a>
    <?php endif; ?>
</div>

<?php if ($active_plan): ?>
<div class="card animate-rise d1">
    <div class="card-title">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;min-width:0;">
            <h3 style="margin:0;"><?=htmlspecialchars($active_plan['surah_name'])?></h3>
            <?php $active_plan_ar = arabic_text($active_plan['surah_name_ar'] ?? ''); if ($active_plan_ar !== ''): ?>
                <span class="arabic"><?=htmlspecialchars($active_plan_ar)?></span>
            <?php endif; ?>
            <span class="badge badge-green"><?= ui_icon('book', 12) ?> Active Plan</span>
        </div>
        <span class="small text-muted">Complete this plan before starting another surah.</span>
    </div>

    <?php $plan_done = (int)$active_plan['completed_requests']; $plan_remaining = max(1, (int)$active_plan['total_verses'] - (int)$active_plan['start_verse'] + 1); $plan_total = max(1, (int)ceil($plan_remaining / $active_plan['verses_per_request'])); ?>
    <div class="grid-3" style="margin:14px 0 4px;">
        <div class="panel" style="margin:0;text-align:center;">
            <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--emerald-800);"><?= (int)$active_plan['verses_per_request'] ?></div>
            <div class="small text-muted">Verses per request</div>
        </div>
        <div class="panel" style="margin:0;text-align:center;">
            <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--emerald-800);"><?= $plan_done ?> / <?= $plan_total ?></div>
            <div class="small text-muted">Requests completed</div>
        </div>
        <div class="panel" style="margin:0;text-align:center;">
            <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--gold-deep);"><?= $percentage ?>%</div>
            <div class="small text-muted">Overall progress</div>
        </div>
    </div>

    <div class="progress" style="margin-top:6px;">
        <div class="progress-fill" style="width:<?=$percentage?>%"></div>
        <div class="progress-text"><?=$percentage?>%</div>
    </div>

    <div class="plan-action">
        <?php if ($exam_mode): ?>
            <div class="alert alert-warning" style="margin:0;"><?= ui_icon('alert', 16) ?> <span style="flex:1;min-width:0;">Requesting the next portion is paused while exam mode is active.</span></div>
        <?php elseif ($locked): ?>
            <div class="alert alert-danger" style="margin:0;"><?= ui_icon('lock', 16) ?> <span style="flex:1;min-width:0;">You missed the exam term. Requesting lessons is paused until you pay the ₦500 fee and your exam is accepted.</span></div>
        <?php elseif ($last_rec_status === 'pending'): ?>
            <div class="alert alert-info" style="margin:0;"><?= ui_icon('clock', 16) ?> <span style="flex:1;min-width:0;">Your latest recitation is <strong>awaiting review</strong>. You can request the next portion once your teacher reviews it.</span></div>
        <?php elseif ($last_rec_status === 'rejected'): ?>
            <div class="alert alert-warning" style="margin:0;"><?= ui_icon('close', 16) ?> <span style="flex:1;min-width:0;">Your last recitation was <strong>rejected</strong>. Please re-record it from <strong>My Lessons</strong> before requesting the next portion.</span></div>
        <?php elseif ($has_outstanding_lesson): ?>
            <div class="alert alert-info" style="margin:0;"><?= ui_icon('clock', 16) ?> <span style="flex:1;min-width:0;">Your lesson has not been delivered or fully reviewed yet. You can request the next portion once your current lesson has been accepted.</span></div>
        <?php else: ?>
            <form method="post" action="request_lesson.php">
                <input type="hidden" name="surah_id" value="<?=$active_plan['surah_id']?>">
                <button type="submit" class="btn btn-gold btn-lg"><?= ui_icon('send', 18) ?> Send Request for Next Portion</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$active_plan && !$exam_mode && !$locked && $surahs_res && $surahs_res->num_rows > 0): ?>
<div class="card animate-rise d1">
    <div class="card-title">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px;"><?= ui_icon('book-open', 18) ?> Start New Learning Plan</h3>
        <span class="small text-muted">Pick a surah and how many verses you want to work on per request.</span>
    </div>
    <form method="post" action="start_learning.php">
        <div class="form-group">
            <label class="form-label" for="surahSelect">Select Surah</label>
            <select class="form-select" name="surah_id" id="surahSelect" required>
                <option value="">-- Select --</option>
                <?php while ($s = $surahs_res->fetch_assoc()): ?>
                    <option
                        value="<?=$s['id']?>"
                        data-name="<?=htmlspecialchars($s['name'])?>"
                        data-total="<?=$s['total_verses']?>"
                    >
                        <?=htmlspecialchars($s['name'])?> (<?=$s['total_verses']?> verses)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="versesInput">Verses per request</label>
            <input class="form-input" type="number" name="verses_per_request" id="versesInput" min="1" required>
            <p class="small text-muted" style="margin-top:6px;"><?= ui_icon('info', 14) ?> Smaller portions are reviewed more quickly.</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="startVerseInput">Start from verse</label>
            <input class="form-input" type="number" name="start_verse" id="startVerseInput" min="1" value="1" required>
            <p class="small text-muted" style="margin-top:6px;"><?= ui_icon('info', 14) ?> Already learned some of this surah before? Tell us where to continue from — we'll calculate the remaining verses for you.</p>
        </div>

        <div class="alert alert-info" id="message" style="display:none;"></div>

        <button type="submit" class="btn btn-block">Start Learning</button>
    </form>
</div>
<?php elseif (!$active_plan && !$exam_mode && !$locked): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('trophy', 40) ?></div>
        <div class="empty-title">You have completed every available surah!</div>
        <p class="small" style="margin:0;">Masha’Allah! Your certificate is ready whenever you need it.</p>
    </div>
<?php endif; ?>

<?php if ($feedback_count > 0): ?>
<div class="card card-gold animate-rise d2">
    <div class="card-title"><h3 style="margin-top:0;display:flex;align-items:center;gap:8px;"><?= ui_icon('chat', 18) ?> Admin Feedback</h3></div>
    <p class="small" style="margin:0 0 12px;">You have <strong><?=$feedback_count?></strong> piece(s) of feedback waiting for you. Review it to see how your teacher rated your recitation.</p>
    <a class="btn btn-gold" href="feedback.php">View Feedback</a>
</div>
<?php endif; ?>

<?php ui_page_end(); ?>

<script>
const surahSelect = document.getElementById('surahSelect');
const versesInput = document.getElementById('versesInput');
const startVerseInput = document.getElementById('startVerseInput');
const messageBox = document.getElementById('message');

function updateMessage() {
    if (!surahSelect || !versesInput || !startVerseInput) return;
    const opt = surahSelect.options[surahSelect.selectedIndex];
    if (!opt || !opt.dataset.total || !versesInput.value || !startVerseInput.value) {
        messageBox.style.display = 'none';
        return;
    }
    const total = parseInt(opt.dataset.total);
    const verses = parseInt(versesInput.value);
    let start = parseInt(startVerseInput.value);
    if (isNaN(start) || start < 1) start = 1;
    if (startVerseInput.max != total) startVerseInput.max = total;
    messageBox.style.display = 'flex';
    if (start > total) {
        messageBox.innerHTML =
            `<strong>Start verse cannot be greater than ${total}.</strong> Please choose a verse between 1 and ${total}.`;
        return;
    }
    const remaining = total - start + 1;
    const requests = Math.ceil(remaining / verses);
    messageBox.innerHTML =
        `You selected <strong>${opt.dataset.name}</strong><br>
         Total verses: ${total}<br>
         Starting from verse: ${start}<br>
         Remaining verses: ${remaining}<br>
         Verses per request: ${verses}<br>
         Estimated requests: <strong>${requests}</strong>`;
}

if (surahSelect && versesInput && startVerseInput) {
    surahSelect.addEventListener('change', updateMessage);
    versesInput.addEventListener('input', updateMessage);
    startVerseInput.addEventListener('input', updateMessage);
}
</script>

</body>
</html>