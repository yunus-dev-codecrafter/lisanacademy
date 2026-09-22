<?php    
require_once __DIR__ . '/../config/security/helpers.php';    
require_once __DIR__ . '/../auth/auth_check.php';    
require_once __DIR__ . '/../config/db.php';    
    
require_role('student');    
$student_id = (int)$_SESSION['user_id'];

$is_hafiz = student_is_hafiz($conn, $student_id);
$is_memorizer = student_is_memorizing($conn, $student_id);

$exam_mode      = student_in_exam($conn, $student_id);
$locked         = student_exam_locked($conn, $student_id);
$student_access = student_exam_access($conn, $student_id);

/* Pending feedback count */    
$feedbackCount = $conn->query("    
    SELECT COUNT(*) c    
    FROM student_recitation    
    WHERE student_id = $student_id    
      AND status IN ('accepted','rejected')    
      AND feedback_seen = 0    
      AND student_deleted = 0    
")->fetch_assoc()['c'];    

/* Unread announcements count */    
$announcementCount = $conn->query("    
    SELECT COUNT(*) AS c     
    FROM announcements a     
    LEFT JOIN announcement_reads ar     
        ON a.id = ar.announcement_id AND ar.student_id = $student_id     
    WHERE ar.id IS NULL OR ar.seen = 0     
")->fetch_assoc()['c'];    

/* Student info */    
$stmt = $conn->prepare("SELECT name,email FROM users WHERE id=?");    
$stmt->bind_param("i", $student_id);    
$stmt->execute();    
$student = $stmt->get_result()->fetch_assoc();    

/* Progress */    
$total = $conn->query("SELECT COUNT(*) c FROM surahs")->fetch_assoc()['c'];    
$done  = $conn->query("    
    SELECT COUNT(DISTINCT sl.surah_id) c     
    FROM student_learning sl     
    WHERE sl.student_id=$student_id AND sl.status='completed'    
")->fetch_assoc()['c'];    
$percent = $total ? round(($done/$total)*100) : 0;

/* Record the moment of 100% completion (starts the 7-day auto-delete clock).
   Memorizers are internally guarded by student_is_memorizing(). */
if (!$is_memorizer) { mark_graduated_if_due($conn, $student_id); }    

/* Latest admin lesson audio */    
$lessonAudio = $conn->query("    
    SELECT aa.id, aa.audio_file, aa.acknowledged,    
           l.id AS lesson_id,    
           s.name_en AS surah    
    FROM admin_audio aa    
    JOIN lessons l ON l.id = aa.learning_plan_id    
    JOIN surahs s ON s.id = l.surah_id    
    WHERE aa.student_id = $student_id     
    ORDER BY aa.sent_at DESC     
    LIMIT 1     
")->fetch_assoc(); 

/* Can student request live recitation? */
$canGoLive = false;

if ($lessonAudio && (int)$lessonAudio['acknowledged'] === 1) {
    $canGoLive = true;
}
?>  
<!DOCTYPE html>  
<html>    
<head>    
<title>Student Dashboard</title>    
<meta name="viewport" content="width=device-width, initial-scale=1.0">  
<?= ui_css() ?>
</head>  
<?php ui_page_start('student', 'dashboard', 'Dashboard'); ?>
<?= csrf_field() ?>

<div class="hero-banner animate-rise">
    <span class="hero-banner-ico"><?= ui_icon('book-open', 26) ?></span>
    <div style="flex:1;min-width:220px;">
        <h1>Assalamu alaikum, <?=htmlspecialchars($student['name'])?></h1>
        <p>Here’s your learning overview · <?=htmlspecialchars($student['email'])?></p>
    </div>
    <div class="hero-banner-actions">
        <?php if ($is_hafiz): ?>
            <a class="btn btn-gold btn-sm" href="hafiz_revision.php"><?= ui_icon('book', 15) ?> Continue Revision</a>
        <?php elseif ($is_memorizer): ?>
            <a class="btn btn-gold btn-sm" href="quran_memorization.php"><?= ui_icon('star', 15) ?> Continue Memorization</a>
        <?php else: ?>
            <a class="btn btn-gold btn-sm" href="my_learning.php"><?= ui_icon('book', 15) ?> Continue Learning</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-light" href="announcements.php"><?= ui_icon('bell', 15) ?> Updates<?php if ($announcementCount > 0): ?> (<?=$announcementCount?>)<?php endif; ?></a>
    </div>
</div>

<?php if ($exam_mode): ?>
<div class="alert alert-warning animate-rise">
    <?= ui_icon('alert', 18) ?>
    <span style="flex:1;"><strong>Exam mode is active.</strong> You cannot request new lessons or submit recitations until the exam is concluded.</span>
    <a class="btn btn-gold btn-sm" href="exam.php"><?= ui_icon('notes', 15) ?> Take Exam</a>
</div>
<?php endif; ?>

<?php if ($locked): ?>
<div class="alert alert-danger animate-rise">
    <?= ui_icon('lock', 18) ?>
    <span style="flex:1;"><strong>You have an outstanding exam from the last term.</strong> Normal lessons stay paused until your exam is accepted (and any ₦500 fee is paid). Other students continue their normal lessons.</span>
    <a class="btn btn-gold btn-sm" href="exam.php"><?= ui_icon('notes', 15) ?> Pay / View Exam</a>
</div>
<?php endif; ?>

<?php
/* Digital Islamiyya — live-count backing the student's catalog card.
 * Same publish rule as the manager + admin card: a book only ever counts
 * as live once its record is status=live AND every lesson + full
 * question pack is uploaded. Until then the card stays sealed. */
$islamiyya_live_count = 0;
$islamiyya_total_count = 0;
foreach ((islamiyya_books($conn) ?? []) as $islamiyya_book) {
    $islamiyya_total_count++;
    if (islamiyya_book_is_live($conn, $islamiyya_book)) $islamiyya_live_count++;
}
$islamiyya_coming_count = $islamiyya_total_count - $islamiyya_live_count;
?>
<?php if ($islamiyya_total_count > 0 && $islamiyya_live_count === 0): ?>
<div class="alert alert-warning animate-rise d1" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
    <?= ui_icon('book-open', 18) ?>
    <span style="flex:1;min-width:220px;"><strong>New program: Digital Islamiyya is coming soon.</strong> Classical books in Tauhid, Fiqh, Hadith, Seerah and Arabic — explore the catalog and save your slot today.</span>
    <a class="btn btn-gold btn-sm" href="islamiyya.php"><?= ui_icon('arrow-right', 15) ?> Explore &amp; Save My Slot</a>
</div>
<?php endif; ?>
<div class="stat-grid animate-rise d1">
    <a class="stat-card stat-gold" href="islamiyya.php"
        title="Digital Islamiyya — classical books with audio/video lessons and quizzes"
        style="border:2px solid var(--gold);box-shadow:0 8px 24px rgba(180,130,30,.18);">
        <span class="stat-ico"><?= ui_icon('book-open', 22) ?></span>
        <span class="stat-label">Digital Islamiyya <span class="badge badge-gold">New</span></span>
        <span class="stat-value"><?= $islamiyya_live_count ?> <span class="small text-muted">/ <?= $islamiyya_total_count ?> live</span></span>
        <span class="stat-sub"><?= $islamiyya_live_count > 0 ? 'live books — tap to start learning' : 'explore the books & save your slot' ?></span>
    </a>
    <?php if ($is_hafiz): ?>
    <?php
    $hafiz_revision = hafiz_get_active_revision($conn, $student_id);
    $hafiz_current_page = $hafiz_revision ? (int)$hafiz_revision['current_page'] : 1;
    $hafiz_completed = $hafiz_current_page > 1 ? $hafiz_current_page - 1 : 0;
    $hafiz_pct = round(($hafiz_completed / 604) * 100);
    $hafiz_completed_cycles = hafiz_completed_cycles_count($conn, $student_id);
    $hafiz_juz_no = $hafiz_revision ? hafiz_current_week_no($hafiz_revision) : 1;
    ?>
    <a class="stat-card stat-green" href="hafiz_revision.php">
        <span class="stat-ico"><?= ui_icon('book', 22) ?></span>
        <span class="stat-label">Qur'an Revision</span>
        <span class="stat-value">Juz <?= $hafiz_juz_no ?> <span class="small text-muted">· <?= $hafiz_completed ?>/604 pages</span></span>
        <div class="progress" style="margin-top:10px;">
            <div class="progress-fill" style="width:<?=$hafiz_pct?>%"></div>
            <div class="progress-text"><?=$hafiz_pct?>%</div>
        </div>
    </a>
    <a class="stat-card stat-gold" href="feedback.php" title="Review feedback from your teacher">
        <span class="stat-ico"><?= ui_icon('chat', 22) ?></span>
        <span class="stat-label">New Feedback</span>
        <span class="stat-value"><?=$feedbackCount?></span>
        <span class="stat-sub">Review from your teacher</span>
    </a>
    <?php if ($hafiz_completed_cycles > 0): ?>
    <div class="stat-card stat-blue">
        <span class="stat-ico"><?= ui_icon('trophy', 22) ?></span>
        <span class="stat-label">Completed Cycles</span>
        <span class="stat-value"><?=$hafiz_completed_cycles?></span>
        <span class="stat-sub">Daurah cycles finished</span>
    </div>
    <?php endif; ?>
    <?php elseif ($is_memorizer): ?>
    <?php
    if (mem_engine_installed($conn)) {
        mem_self_heal($conn, $student_id);
        $mem_summary = mem_progress_summary($conn, $student_id);
        $mem_task = $mem_summary ? $mem_summary['task'] : null;
    } else {
        $mem_summary = null;
        $mem_task = null;
    }
    $mem_pct = $mem_summary ? (int)$mem_summary['pct'] : 0;
    $mem_label = 'Memorization is not available yet.';
    if ($mem_task) {
        if ($mem_task['task_type'] === 'celebration') {
            $mem_label = 'Today is a celebration day!';
        } elseif ($mem_task['task_type'] === 'memorization') {
            $mem_label = 'Today: Memorize Page ' . (int)$mem_task['start_page'];
        } else {
            $mem_label = 'Today: Muraja\'ah Pages ' . (int)$mem_task['start_page'] . '–' . (int)$mem_task['end_page'];
        }
    }
    ?>
    <a class="stat-card stat-gold" href="quran_memorization.php">
        <span class="stat-ico"><?= ui_icon('star', 22) ?></span>
        <span class="stat-label">Qur'an Memorization</span>
        <span class="stat-value"><span class="small text-muted"><?= $mem_summary ? (int)$mem_summary['pages_memorized'] . '/604 pages' : '—' ?></span></span>
        <span class="stat-sub" style="white-space:normal;"><?= htmlspecialchars($mem_label) ?></span>
        <?php if ($mem_summary): ?>
            <div class="progress" style="margin-top:10px;">
                <div class="progress-fill" style="width:<?=$mem_pct?>%;background:linear-gradient(90deg,#d97706,#f59e0b);"></div>
                <div class="progress-text"><?=$mem_pct?>%</div>
            </div>
        <?php endif; ?>
    </a>
    <a class="stat-card stat-green" href="feedback.php" title="Review feedback from your teacher">
        <span class="stat-ico"><?= ui_icon('chat', 22) ?></span>
        <span class="stat-label">New Feedback</span>
        <span class="stat-value"><?=$feedbackCount?></span>
        <span class="stat-sub">Review from your teacher</span>
    </a>
    <a class="stat-card stat-blue" href="announcements.php" title="Read the latest updates">
        <span class="stat-ico"><?= ui_icon('bell', 22) ?></span>
        <span class="stat-label">Announcements</span>
        <span class="stat-value"><?=$announcementCount?></span>
        <span class="stat-sub">Unread updates</span>
    </a>
    <?php else: ?>
    <a class="stat-card stat-green" href="my_learning.php">
        <span class="stat-ico"><?= ui_icon('book', 22) ?></span>
        <span class="stat-label">Surahs Completed</span>
        <span class="stat-value"><?=$done?> <span class="small text-muted">/ <?=$total?></span></span>
        <div class="progress" style="margin-top:10px;">
            <div class="progress-fill" style="width:<?=$percent?>%"></div>
            <div class="progress-text"><?=$percent?>%</div>
        </div>
    </a>
    <a class="stat-card stat-gold" href="feedback.php" title="Review feedback from your teacher">
        <span class="stat-ico"><?= ui_icon('chat', 22) ?></span>
        <span class="stat-label">New Feedback</span>
        <span class="stat-value"><?=$feedbackCount?></span>
        <span class="stat-sub">Review from your teacher</span>
    </a>
    <a class="stat-card stat-blue" href="announcements.php" title="Read the latest updates">
        <span class="stat-ico"><?= ui_icon('bell', 22) ?></span>
        <span class="stat-label">Announcements</span>
        <span class="stat-value"><?=$announcementCount?></span>
        <span class="stat-sub">Unread updates</span>
    </a>
    <?php endif; ?>
</div>

<!-- =====================  
 QURAN COMPANION CARD  
===================== -->
<div class="quran-star-card animate-rise d2" onclick="openRanking()">  
    <div class="quran-star-overlay"></div>  
    <div class="quran-star-content">  
        <h3><?= ui_icon('star', 20) ?> Real Qur’an Companion</h3>
        <span class="star-divider"></span>  
        <?php  
        /* FETCH TOP STUDENT */  
        $top = $conn->query("  
            SELECT u.name, COUNT(l.id) AS total_requests  
            FROM users u  
            LEFT JOIN lessons l ON l.student_id = u.id  
            WHERE u.role='student'  
            GROUP BY u.id  
            ORDER BY total_requests DESC  
            LIMIT 1  
        ")->fetch_assoc();  

        /* CHECK IF ALL COUNTS ARE EQUAL */  
        $check = $conn->query("  
            SELECT COUNT(DISTINCT request_count) c FROM (  
                SELECT COUNT(l.id) request_count  
                FROM users u  
                LEFT JOIN lessons l ON l.student_id = u.id  
                WHERE u.role='student'  
                GROUP BY u.id  
            ) x  
        ")->fetch_assoc()['c'];  

        if ($top && $check > 1 && $top['total_requests'] > 0):  
        ?>  
            <p class="big"><?= htmlspecialchars($top['name']) ?></p>  
            <span><?= (int)$top['total_requests'] ?> lesson requests</span>  
        <?php else: ?>  
            <p class="quran-reminder">  
                “The most beloved deeds to Allah are those done consistently, even if they are small.”  
            </p>  
            <span><?= ui_icon('book', 14) ?> Be the first to take the lead</span>  
        <?php endif; ?>  
    </div>
</div>  

<!-- New Lesson card (feature) — learners only -->
<?php if (!$is_memorizer): ?>
<div class="card animate-rise d3">
    <div class="card-title lesson-head">
        <span class="lesson-ico"><?= ui_icon('book-open', 20) ?></span>
        <div><h3>New Lesson from Admin</h3><p class="card-subtitle">Listen, then mark it complete</p></div>
    </div>
    <?php if ($exam_mode): ?>
        <div class="alert alert-warning" style="margin:0;"><?= ui_icon('alert', 16) ?> Recitation is paused while exam mode is active. You may still listen to your lessons.</div>
    <?php elseif($lessonAudio): ?>    
        <p><strong>Surah:</strong> <?=htmlspecialchars($lessonAudio['surah'])?></p>    
        <audio controls src="../uploads/admin_audio/<?=$lessonAudio['audio_file']?>"></audio>    

        <?php if((int)$lessonAudio['acknowledged'] === 0): ?>    
            <button class="btn btn-gold" onclick="acknowledgeLesson(<?=$lessonAudio['id']?>)">Mark as Completed</button>    
        <?php else: ?>    
            <p class="small text-muted">You have completed this lesson. Now record or upload your recitation for review.</p>
            <a class="btn btn-gold" href="new_lesson.php"><?= ui_icon('mic', 16) ?> Record / Upload Your Recitation</a>
        <?php endif; ?>
    <?php else: ?>    
        <p class="text-muted">No lesson yet. Request one from <strong>My Learning</strong>.</p>
    <?php endif; ?>  
</div>
<?php endif; ?>

<!-- Live Recitation Card — learners only -->
<?php if (!$exam_mode && !$is_memorizer): ?>
<?php $liveUnlocked = ($lessonAudio && (int)$lessonAudio['acknowledged'] === 1); ?>
<div class="card live-recitation-card <?= $liveUnlocked ? 'unlocked' : 'locked' ?>" id="liveRecitationCard">
    <div class="card-title" style="display:flex;align-items:center;gap:12px;">
        <span class="live-icon"><?= ui_icon('video', 30) ?></span>
        <div style="flex:1;">
            <h3>Live Qur’an Recitation</h3>
            <p class="small text-muted" style="margin:0;">Recite your new lesson live with your teacher via Google Meet.</p>
        </div>
        <span class="live-pill <?= $liveUnlocked ? 'ready' : 'off' ?>"><?= $liveUnlocked ? 'Ready' : 'Locked' ?></span>
    </div>
    <?php if (!$liveUnlocked): ?>
        <p class="small text-muted mt-2">Available once you complete your lesson.</p>
        <button class="live-btn" disabled><?= ui_icon('lock', 16) ?> Locked</button>
    <?php else: ?>
        <p class="small text-muted mt-2">Pick a day &amp; time to recite live.</p>
        <button class="live-btn" onclick="openLiveRecitation()"><?= ui_icon('calendar', 16) ?> Schedule Live Recitation</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="section-label animate-rise d3"><?= ui_icon('grid', 16) ?> Quick Actions</div>
<div class="action-grid">
    <a class="action-card action-purple animate-rise d3" href="islamiyya.php">
        <span class="ac-ico"><?= ui_icon('book-open', 24) ?></span>
        <span class="ac-title">Digital Islamiyya</span>
        <span class="ac-sub">Classical books, lessons &amp; quizzes</span>
        <?php if ($islamiyya_live_count > 0): ?><span class="badge badge-count ac-badge"><?=$islamiyya_live_count?> available</span>
        <?php elseif ($islamiyya_coming_count > 0): ?><span class="badge badge-count ac-badge">Save your slot</span><?php endif; ?>
    </a>
    <?php if (!$is_hafiz && $done > 0): ?>
    <a class="action-card action-gold animate-rise d3" href="certificate.php">
        <span class="ac-ico"><?= ui_icon('gem', 24) ?></span>
        <span class="ac-title">Certificate</span>
        <span class="ac-sub">View &amp; print your certificate</span>
    </a>
    <?php endif; ?>
    <a class="action-card action-emerald animate-rise d3" href="profile.php">
        <span class="ac-ico"><?= ui_icon('user', 24) ?></span>
        <span class="ac-title">Profile</span>
        <span class="ac-sub">Manage your account</span>
    </a>
    <a class="action-card action-forest animate-rise d3" href="announcements.php">
        <span class="ac-ico"><?= ui_icon('bell', 24) ?></span>
        <span class="ac-title">Announcements</span>
        <span class="ac-sub">Latest updates</span>
        <?php if ($announcementCount > 0): ?><span class="badge badge-count ac-badge"><?=$announcementCount?> new</span><?php endif; ?>
    </a>
    <?php if ($is_hafiz): ?>
    <a class="action-card action-gold animate-rise d4" href="hafiz_revision.php">
        <span class="ac-ico"><?= ui_icon('book', 24) ?></span>
        <span class="ac-title">Qur'an Revision</span>
        <span class="ac-sub">Continue your page revision</span>
    </a>
    <?php elseif ($is_memorizer): ?>
    <a class="action-card action-gold animate-rise d4" href="quran_memorization.php">
        <span class="ac-ico"><?= ui_icon('star', 24) ?></span>
        <span class="ac-title">Qur'an Memorization</span>
        <span class="ac-sub">Today's task &amp; progress</span>
    </a>
    <?php else: ?>
    <a class="action-card action-gold animate-rise d4" href="my_learning.php">
        <span class="ac-ico"><?= ui_icon('book', 24) ?></span>
        <span class="ac-title">My Learning</span>
        <span class="ac-sub">Track your current surah</span>
    </a>
    <?php endif; ?>
    <a class="action-card action-blue animate-rise d4" href="feedback.php">
        <span class="ac-ico"><?= ui_icon('chat', 24) ?></span>
        <span class="ac-title">Admin's Feedback</span>
        <span class="ac-sub">View teacher feedback</span>
        <?php if ($feedbackCount > 0): ?><span class="badge badge-count ac-badge"><?=$feedbackCount?> new</span><?php endif; ?>
    </a>
</div>

<!-- Restart Learning — learners only -->
<?php if (!$is_memorizer): ?>
<a class="card card-danger mt-2 animate-rise" style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;text-decoration:none;color:inherit;" href="reset_progress.php">
    <div style="flex:1;min-width:220px;">
        <h3 style="color:var(--danger);margin:0 0 6px;display:flex;align-items:center;gap:8px;"><?= ui_icon('alert', 18) ?> Restart Your Learning</h3>
        <p class="small text-muted" style="margin:0;">Reset your plan, recitations and completed surahs. Cannot be undone.</p>
    </div>
    <span class="btn btn-danger"><?= ui_icon('refresh', 16) ?> Reset Learning</span>
</a>
<?php endif; ?>

<?php ui_page_end(); ?>

<script>
/* =========================
   ACKNOWLEDGE ADMIN AUDIO
========================= */
function acknowledgeLesson(id) {
    const fd = new FormData();
    const csrfInput = document.querySelector('[name=csrf_token]');
    fd.append('audio_id', id);
    if (csrfInput) fd.append('csrf_token', csrfInput.value);
    fetch('acknowledge_admin_audio.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => {
            if (res.trim() === 'OK') location.reload();
            else alert(res);
        });
}

function openRanking() {
    window.location.href = "ranking.php";
}

function openLiveRecitation() {
    window.location.href = "live_recitation.php";
}
</script>
</body>
</html>