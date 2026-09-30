<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('admin');

/* Opportunistic media cleanup: Muraja'ah videos (36h) + completed-cycle audios (24h) */
run_opportunistic_cleanup($conn);

/* ----------------------
   Section A: Student submissions for review
---------------------- */
$recitations = $conn->query("
    SELECT 
        sr.id AS rec_id,
        sr.audio_file,
        sr.submitted_at,
        sr.status,
        sr.rating,
        sr.feedback,
        sr.admin_audio_feedback,
        sr.feedback_seen,
        sr.student_deleted,
        u.id AS student_id,
        u.name,
        u.email,
        u.device_type,
        s.name_en AS surah_name,
        l.from_verse,
        l.to_verse
    FROM student_recitation sr
    JOIN users u ON u.id = sr.student_id
    JOIN lessons l ON l.id = sr.learning_plan_id
    JOIN surahs s ON s.id = l.surah_id
    WHERE sr.status = 'pending' AND sr.student_deleted = 0
    ORDER BY sr.submitted_at ASC
");

/* ----------------------
   Section B: New lesson requests
---------------------- */
$new_requests = $conn->query("
    SELECT 
        l.id AS lesson_id,
        l.student_id,
        u.device_type,
        l.from_verse,
        l.to_verse,
        u.name,
        u.email,
        s.name_en AS surah_name
    FROM lessons l
    JOIN users u ON u.id = l.student_id
    JOIN surahs s ON s.id = l.surah_id
    LEFT JOIN admin_audio aa ON aa.learning_plan_id = l.id
    WHERE l.status = 'requested' AND aa.id IS NULL
    ORDER BY l.id ASC
");

/* ----------------------
   Section C: Pending Live Recitation Requests
---------------------- */
$live_requests = $conn->query("
    SELECT 
        lr.id,
        lr.student_id,
        lr.lesson_id,
        lr.preferred_date,
        lr.preferred_time,
        lr.status,
        u.name AS student_name,
        u.email AS student_email,
        u.device_type,
        s.name_en AS surah_name
    FROM live_recitation_requests lr
    JOIN users u ON u.id = lr.student_id
    JOIN lessons l ON l.id = lr.lesson_id
    JOIN surahs s ON s.id = l.surah_id
    WHERE lr.status = 'pending'
    ORDER BY lr.created_at ASC
");

/* ----------------------
   Section D: Hafiz Revision Sessions
---------------------- */
$hafiz_groups = [];
if (db_table_exists($conn, 'hafiz_sessions') && db_table_exists($conn, 'hafiz_revision')) {
    $hafiz_rows = $conn->query("
        SELECT
            hs.id AS session_id,
            hs.student_id,
            hs.revision_id,
            hs.page_no,
            hs.session_type,
            hs.audio_file,
            hs.status,
            hs.rating,
            hs.feedback,
            hs.submitted_at,
            hs.reviewed_at,
            u.name AS student_name,
            u.email AS student_email,
            hr.cycle_no,
            hr.current_page
        FROM hafiz_sessions hs
        JOIN users u ON u.id = hs.student_id
        JOIN hafiz_revision hr ON hr.id = hs.revision_id
        WHERE hs.status IN ('pending','rejected')
        ORDER BY hs.student_id ASC, hs.page_no ASC, hs.id ASC
    ");
    if ($hafiz_rows) {
        while ($hs = $hafiz_rows->fetch_assoc()) {
            $sid = (int)$hs['student_id'];
            if (!isset($hafiz_groups[$sid])) {
                $hafiz_groups[$sid] = [
                    'student_id'    => $sid,
                    'student_name'  => $hs['student_name'],
                    'student_email' => $hs['student_email'],
                    'cycle_no'      => (int)$hs['cycle_no'],
                    'current_page'  => (int)$hs['current_page'],
                    'pages'         => [],
                ];
            }
            $hafiz_groups[$sid]['pages'][] = $hs;
        }
    }
}

/* ----------------------
   Section E: Hafiz Weekly Tests
---------------------- */
$hafiz_tests = null;
if (db_table_exists($conn, 'hafiz_weekly_tests') && db_table_exists($conn, 'hafiz_test_answers')) {
    $hafiz_tests = $conn->query("
        SELECT
            t.id AS test_id,
            t.student_id,
            t.week_no,
            t.status,
            t.started_at,
            t.submitted_at,
            u.name AS student_name,
            u.email AS student_email,
            (SELECT COUNT(*) FROM hafiz_test_answers a WHERE a.test_id = t.id) AS q_count
        FROM hafiz_weekly_tests t
        JOIN users u ON u.id = t.student_id
        WHERE t.status = 'submitted'
        ORDER BY t.submitted_at ASC
    ");
}

/* ----------------------
   Section F: Qur'an Memorization — Muraja'ah Sessions Awaiting Review
---------------------- */
$mem_queue = mem_admin_queue($conn);

/* Section badge counts — every section (A–G) contributes, so the in-page
   pills always add up to the sidebar badge from teaching_pending_count().
   Previously B (lessons) and C (live) were queried but never counted, which
   made the badge show a number while the page appeared empty. */
$cnt_a = ($recitations && $recitations->num_rows > 0) ? $recitations->num_rows : 0;
$cnt_b = ($new_requests && $new_requests->num_rows > 0) ? $new_requests->num_rows : 0;
$cnt_c = ($live_requests && $live_requests->num_rows > 0) ? $live_requests->num_rows : 0;
$cnt_d = array_sum(array_map(fn($g) => count($g['pages']), $hafiz_groups));
$cnt_e = ($hafiz_tests && $hafiz_tests->num_rows > 0) ? $hafiz_tests->num_rows : 0;
$cnt_f = count($mem_queue);
$assistance_requests = function_exists('mem_assistance_pending') ? mem_assistance_pending($conn) : [];
$cnt_g = count($assistance_requests);
?>
<!DOCTYPE html>
<html>
<head>
<title>Teaching Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
<style>
/* Teaching dashboard — modern layout: stats, sticky tabs, tcard design */
.teach-hero{overflow:visible}
.tstats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-top:14px}
.tstat{
  display:flex;flex-direction:column;align-items:flex-start;gap:2px;
  background:var(--surface,#fff);border:1px solid var(--border);border-radius:14px;
  padding:12px 14px;cursor:pointer;text-align:left;font-family:inherit;
  transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;
  position:relative;overflow:hidden;
}
.tstat::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--emerald-600);opacity:.85}
.tstat.s-b::before{background:var(--gold)} .tstat.s-c::before{background:#3b82f6}
.tstat.s-d::before{background:#8b5cf6} .tstat.s-e::before{background:#f59e0b}
.tstat.s-f::before{background:#7c3aed} .tstat.s-g::before{background:#d97706}
.tstat:hover{transform:translateY(-2px);box-shadow:var(--shadow-md,0 12px 32px rgba(15,23,42,.10))}
.tstat.is-active{border-color:var(--emerald-600);box-shadow:var(--shadow-green,0 12px 28px rgba(4,120,87,.22))}
.tstat-ico{
  width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;
  background:var(--emerald-50,#ecfdf5);color:var(--emerald-700,#047857);margin-bottom:4px;
}
.s-b .tstat-ico{background:#fef6e0;color:#8a6d1c}.s-c .tstat-ico{background:#e8f1fe;color:#1d4ed8}
.s-d .tstat-ico,.s-f .tstat-ico{background:#f1eafe;color:#7c3aed}
.s-e .tstat-ico,.s-g .tstat-ico{background:#fef3e2;color:#b45309}
.tstat-num{font-size:1.35rem;font-weight:800;line-height:1.1;color:var(--text)}
.tstat-label{font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
.tstat.is-zero{opacity:.55}
.ttabs{
  position:sticky;top:calc(var(--topbar-h,64px) + 8px);z-index:30;
  display:flex;gap:8px;margin-top:12px;padding:10px 12px;overflow-x:auto;
  background:rgba(255,255,255,.94);backdrop-filter:blur(8px);
  border:1px solid var(--border);border-radius:14px;
  box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.06));
  scrollbar-width:thin;
}
.ttab{
  flex:none;display:inline-flex;align-items:center;gap:8px;
  border:1px solid var(--border);background:var(--surface,#fff);color:var(--text);
  border-radius:999px;padding:8px 14px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;
  transition:background .15s ease,color .15s ease,border-color .15s ease;
}
.ttab:hover{border-color:var(--emerald-600);color:var(--emerald-800)}
.ttab.is-active{background:var(--emerald-800,#065f46);border-color:var(--emerald-800,#065f46);color:#fff}
.ttab-letter{
  width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
  font-size:.7rem;font-weight:800;background:var(--surface-muted,#f1f5f1);color:var(--emerald-800);
}
.ttab.is-active .ttab-letter{background:rgba(255,255,255,.2);color:#fff}
.ttab .nav-badge{background:var(--danger,#dc2626)}
.ttab.is-active .nav-badge{background:var(--gold,#c9a24b);color:#fff}
h2[id^="sec-"]{scroll-margin-top:calc(var(--topbar-h,64px) + 130px)}
.teach-search-row{display:flex;align-items:center;gap:10px;margin-top:12px}
.teach-search-row .form-input{max-width:340px}
/* Modern review cards */
.tcard{
  background:var(--surface,#fff);border:1px solid var(--border);border-radius:18px;
  padding:18px;box-shadow:var(--shadow-sm,0 2px 8px rgba(15,23,42,.06));
  transition:box-shadow .18s ease,transform .18s ease;
}
.tcard:hover{box-shadow:var(--shadow-md,0 12px 32px rgba(15,23,42,.10))}
.tcard + .tcard{margin-top:14px}
.tcard-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}
.tavatar{
  width:46px;height:46px;flex:none;border-radius:50%;
  display:inline-flex;align-items:center;justify-content:center;
  font-weight:800;font-size:1.15rem;color:#fff;
  background:linear-gradient(135deg,var(--emerald-700),var(--emerald-500,#10b981));
  box-shadow:0 4px 10px rgba(4,120,87,.25);
}
.tcard[data-sec="b"] .tavatar{background:linear-gradient(135deg,#b98a1e,var(--gold,#c9a24b))}
.tcard[data-sec="c"] .tavatar{background:linear-gradient(135deg,#1d4ed8,#60a5fa)}
.tcard[data-sec="d"] .tavatar,.tcard[data-sec="f"] .tavatar{background:linear-gradient(135deg,#6d28d9,#a78bfa)}
.tcard[data-sec="e"] .tavatar,.tcard[data-sec="g"] .tavatar{background:linear-gradient(135deg,#b45309,#f59e0b)}
.tmeta{min-width:0;flex:1}
.tmeta h3{margin:0;font-size:1rem;line-height:1.3;overflow-wrap:anywhere}
.tmeta span{display:block;font-size:.78rem;color:var(--text-muted);overflow-wrap:anywhere}
.tchips{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.tcard .tmeta-row{margin:6px 0 10px}
.tcard audio{width:100%;margin:8px 0}
.tupload{margin-top:10px;border:1px dashed var(--border);border-radius:12px;padding:4px 12px 12px;background:var(--surface-muted,#f8faf8)}
.tupload summary{cursor:pointer;font-size:.84rem;font-weight:600;color:var(--emerald-800);padding:8px 0}
.rec-ind{display:none;align-items:center;gap:8px;font-size:.82rem;font-weight:700;color:#b91c1c}
.rec-ind.on{display:inline-flex}
.rec-dot{width:10px;height:10px;border-radius:50%;background:#dc2626;animation:recPulse 1.1s ease-in-out infinite}
@keyframes recPulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(.6);opacity:.5}}
.teach-status{font-size:.82rem;color:var(--text-muted);margin:8px 0 0}
.teach-status.err{color:#b91c1c}
.teach-status.ok{color:#047857}
.teach-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px}
@media (max-width:640px){
  .teach-search-row .form-input{max-width:none;flex:1}
  .tstats{grid-template-columns:repeat(2,1fr)}
  .tcard{padding:14px;border-radius:14px}
  .tavatar{width:40px;height:40px;font-size:1rem}
  .tmeta h3{font-size:.92rem}
  .ttabs{top:calc(var(--topbar-h,64px) + 4px);padding:8px}
}
</style>
</head>
<?php ui_page_start('admin', 'teaching', 'Teaching', 'Dashboard'); ?>

<?php
$sections = [
    'a' => ['letter' => 'A', 'label' => 'Submissions', 'full' => 'Student Submissions', 'icon' => 'mic', 'count' => $cnt_a],
    'b' => ['letter' => 'B', 'label' => 'Lessons', 'full' => 'Lesson Requests', 'icon' => 'upload', 'count' => $cnt_b],
    'c' => ['letter' => 'C', 'label' => 'Live', 'full' => 'Live Requests', 'icon' => 'video', 'count' => $cnt_c],
    'd' => ['letter' => 'D', 'label' => 'Hafiz Pages', 'full' => 'Hafiz Revision', 'icon' => 'book', 'count' => $cnt_d],
    'e' => ['letter' => 'E', 'label' => 'Hafiz Tests', 'full' => 'Hafiz Weekly Tests', 'icon' => 'gavel', 'count' => $cnt_e],
    'f' => ['letter' => 'F', 'label' => "Muraja'ah", 'full' => "Muraja'ah Sessions", 'icon' => 'book-open', 'count' => $cnt_f],
    'g' => ['letter' => 'G', 'label' => 'Assistance', 'full' => 'Assistance Requests', 'icon' => 'chat', 'count' => $cnt_g],
];
$cnt_total = $cnt_a + $cnt_b + $cnt_c + $cnt_d + $cnt_e + $cnt_f + $cnt_g;
// Default to the first section that actually has work waiting.
$active_tab = 'a';
foreach ($sections as $k => $s) {
    if ($s['count'] > 0) { $active_tab = $k; break; }
}
?>
<div class="page-hero teach-hero animate-rise">
    <h1>Teaching Dashboard</h1>
    <p>Review recitations, prepare lessons and manage live sessions.<?= $cnt_total > 0 ? ' <strong>' . $cnt_total . '</strong> item' . ($cnt_total === 1 ? '' : 's') . ' waiting.' : ' All clear — nothing waiting.' ?></p>
    <div class="tstats">
        <?php foreach ($sections as $key => $s): ?>
            <button type="button" class="tstat s-<?= $key ?><?= $s['count'] > 0 ? ' has' : ' is-zero' ?><?= $key === $active_tab ? ' is-active' : '' ?>" data-tab="<?= $key ?>" aria-label="Show <?= htmlspecialchars($s['full']) ?>">
                <span class="tstat-ico"><?= ui_icon($s['icon'], 18) ?></span>
                <span class="tstat-num"><?= $s['count'] ?></span>
                <span class="tstat-label"><?= htmlspecialchars($s['label']) ?></span>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="ttabs" role="tablist" aria-label="Teaching sections">
        <?php foreach ($sections as $key => $s): ?>
            <button type="button" role="tab" class="ttab<?= $key === $active_tab ? ' is-active' : '' ?>" data-tab="<?= $key ?>">
                <span class="ttab-letter"><?= $s['letter'] ?></span> <?= htmlspecialchars($s['label']) ?>
                <?php if ($s['count'] > 0): ?><span class="nav-badge"><?= $s['count'] ?></span><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php if ($cnt_total > 0): ?>
    <div class="teach-search-row">
        <input id="teachSearch" class="form-input" type="search" placeholder="Search this section by name or email…" autocomplete="off" aria-label="Search cards in the open section">
        <span id="teachSearchCount" class="small text-muted"></span>
    </div>
    <?php endif; ?>
</div>

<?php if (isset($_GET['assist_done'])): ?>
    <div class="alert alert-success animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('check-circle', 18) ?>
        <span style="flex:1;"><strong>Assistance request fulfilled.</strong> The student will now see your recitation and notes on their memorization page.</span>
    </div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('alert', 18) ?>
        <span style="flex:1;"><?= htmlspecialchars($_GET['error']) ?></span>
    </div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('check-circle', 18) ?>
        <span style="flex:1;">Recitation deleted.</span>
    </div>
<?php endif; ?>
<?php if (isset($_GET['request_deleted'])): ?>
    <div class="alert alert-success animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('check-circle', 18) ?>
        <span style="flex:1;">Lesson request deleted.</span>
    </div>
<?php endif; ?>

<!-- =====================
     Section A: Student Submissions for Review
===================== -->
<h2 id="sec-a" class="mt-2 animate-rise d1" style="display:flex;align-items:center;gap:10px;">
    <span class="badge badge-blue">A</span> Student Submissions for Review
    <?php if ($cnt_a > 0): ?><span class="badge badge-count"><?= $cnt_a ?></span><?php endif; ?>
</h2>

<?php if ($recitations && $recitations->num_rows > 0): ?>
    <?php $ri = 0; while ($r = $recitations->fetch_assoc()): $ri++; ?>
        <div class="tcard animate-rise d1" data-sec="a">

            <div class="tcard-head">
                <span class="tavatar"><?= htmlspecialchars(ui_initial($r['name'] ?? '?')) ?></span>
                <div class="tmeta">
                    <h3><?= htmlspecialchars($r['name']) ?></h3>
                    <span><?= htmlspecialchars($r['email']) ?></span>
                </div>
            </div>

            <p class="small">
                <span class="badge badge-green"><?= htmlspecialchars($r['surah_name']) ?></span>
                &nbsp;Verses <?= (int)$r['from_verse'] ?>–<?= (int)$r['to_verse'] ?>
            </p>

            <?php
            $wait_ts = !empty($r['submitted_at']) ? strtotime($r['submitted_at']) : false;
            $wait_h = ($wait_ts !== false) ? max(0, (time() - $wait_ts) / 3600) : null;
            if ($wait_h !== null) {
                $wait_label = $wait_h < 1
                    ? 'waiting ' . max(1, (int)round($wait_h * 60)) . 'm'
                    : ($wait_h < 24
                        ? 'waiting ' . (int)floor($wait_h) . 'h'
                        : 'waiting ' . (int)floor($wait_h / 24) . 'd ' . ((int)floor($wait_h) % 24) . 'h');
            }
            ?>
            <p class="small text-muted" style="margin:2px 0 8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <span><?= ui_icon('clock', 13) ?> Submitted <?= $wait_ts !== false ? date('d M Y, g:i A', $wait_ts) : '—' ?><?= $wait_h !== null ? ' · ' . htmlspecialchars($wait_label) : '' ?></span>
                <?php if ($wait_h !== null && $wait_h >= 48): ?><span class="badge badge-red">Overdue — 48h+</span><?php endif; ?>
            </p>

            <?= media_player_html($r['audio_file'], '../uploads/student_audio/') ?>

            <form method="POST" action="review_recitation.php" enctype="multipart/form-data" style="margin-top:6px;">
                <input type="hidden" name="rec_id" value="<?= (int)$r['rec_id'] ?>">
                <?= csrf_field() ?>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Rating</label>
                        <select class="form-select" name="rating" required>
                            <option value="">--Select--</option>
                            <option>Excellent</option>
                            <option>Very Good</option>
                            <option>Good</option>
                            <option>Fair</option>
                            <option>Needs Improvement</option>
                            <option>Fail</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= ui_icon('mic', 16) ?> Upload Audio Feedback (Optional)</label>
                        <input class="form-input" type="file" name="admin_audio" accept="audio/*">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Feedback / Mistakes</label>
                    <textarea class="form-textarea" name="feedback"><?= htmlspecialchars($r['feedback'] ?? '') ?></textarea>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="btn" type="submit" name="status" value="accepted"><?= ui_icon('check', 16) ?> Accept</button>
                    <button class="btn btn-danger" type="submit" name="status" value="rejected"><?= ui_icon('close', 16) ?> Reject</button>
                </div>
            </form>

            <?php if (!empty($r['admin_audio_feedback'])): ?>
                <p class="small" style="margin:12px 0 4px;"><strong>Existing Audio Feedback:</strong></p>
                <audio controls>
                    <source src="../uploads/admin_feedback/<?= htmlspecialchars($r['admin_audio_feedback']) ?>" type="audio/mpeg">
                    Your browser does not support audio playback.
                </audio>
            <?php endif; ?>

            <div class="left-block">
                <form method="POST" action="delete_recitation.php"
                      onsubmit="return confirm('Delete this recitation permanently?');">
                    <input type="hidden" name="rec_id" value="<?= (int)$r['rec_id'] ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger"><?= ui_icon('trash', 15) ?> Delete Recitation</button>
                </form>
            </div>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="empty animate-rise d1">
        <div class="empty-icon"><?= ui_icon('check-circle', 40) ?></div>
        <div class="empty-title">No pending submissions</div>
        <p class="small" style="margin:0;">Student recitations awaiting your review will appear here.</p>
    </div>
<?php endif; ?>

<!-- =====================
     Section B: New Lesson Requests
===================== -->
<h2 id="sec-b" class="mt-3 animate-rise d2" style="display:flex;align-items:center;gap:10px;">
    <span class="badge badge-gold">B</span> New Lesson Requests
    <?php if ($cnt_b > 0): ?><span class="badge badge-count"><?= $cnt_b ?></span><?php endif; ?>
</h2>

<?php if ($new_requests && $new_requests->num_rows > 0): ?>
<?php while ($row = $new_requests->fetch_assoc()): ?>
<div class="tcard animate-rise d2" data-sec="b">

    <div class="tcard-head">
        <span class="tavatar"><?= htmlspecialchars(ui_initial($row['name'] ?? '?')) ?></span>
        <div class="tmeta">
            <h3><?= htmlspecialchars($row['name']) ?></h3>
            <span><?= htmlspecialchars($row['email']) ?></span>
        </div>
    </div>

    <p class="small">
        <span class="badge badge-green"><?= htmlspecialchars($row['surah_name']) ?></span>
        &nbsp;Verses <?= (int)$row['from_verse'] ?>–<?= (int)$row['to_verse'] ?>
    </p>

    <?php if ($row['device_type'] === 'iphone'): ?>
    <form method="post" enctype="multipart/form-data" action="submit_admin_audio.php">
        <input type="hidden" name="student_id" value="<?= (int)$row['student_id'] ?>">
        <input type="hidden" name="plan_id" value="<?= (int)$row['lesson_id'] ?>">
        <?= csrf_field() ?>
        <div class="form-group">
            <label class="form-label">Upload Lesson Audio</label>
            <input class="file-input" type="file" name="audio" accept="audio/*" required>
        </div>
        <button type="submit" class="btn btn-gold"><?= ui_icon('send', 16) ?> Send Audio</button>
    </form>
    <?php else: ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button class="btn btn-sm" id="recstart_<?= (int)$row['lesson_id'] ?>" onclick="startRecording(<?= (int)$row['lesson_id'] ?>)"><?= ui_icon('mic', 15) ?> Start Recording</button>
        <button class="btn btn-sm btn-ghost" id="recstop_<?= (int)$row['lesson_id'] ?>" onclick="stopRecording(<?= (int)$row['lesson_id'] ?>)" disabled><?= ui_icon('stop', 15) ?> Stop</button>
        <span class="rec-ind" id="recind_<?= (int)$row['lesson_id'] ?>"><span class="rec-dot"></span> REC <span id="rectime_<?= (int)$row['lesson_id'] ?>">0:00</span></span>
    </div>

    <audio id="audio_<?= (int)$row['lesson_id'] ?>" controls style="display:none;width:100%;margin-top:10px;"></audio>
    <p class="teach-status" id="recmsg_<?= (int)$row['lesson_id'] ?>" style="display:none;"></p>

    <button class="btn btn-gold" id="send_<?= (int)$row['lesson_id'] ?>" style="display:none;margin-top:10px;"
    onclick="sendAdminAudio(<?= (int)$row['student_id'] ?>, <?= (int)$row['lesson_id'] ?>)">
    <?= ui_icon('send', 16) ?> Send to Student
    </button>

    <details class="tupload">
        <summary>Or upload an audio file instead</summary>
        <form method="post" enctype="multipart/form-data" action="submit_admin_audio.php">
            <input type="hidden" name="student_id" value="<?= (int)$row['student_id'] ?>">
            <input type="hidden" name="plan_id" value="<?= (int)$row['lesson_id'] ?>">
            <?= csrf_field() ?>
            <div class="form-group" style="margin:4px 0 10px;">
                <input class="file-input" type="file" name="audio" accept="audio/*" required aria-label="Upload lesson audio file">
            </div>
            <button type="submit" class="btn btn-sm btn-gold"><?= ui_icon('send', 15) ?> Send Audio</button>
        </form>
    </details>

    <?php endif; ?>

    <div class="left-block">
        <form method="POST" action="delete_request.php"
        onsubmit="return confirm('Delete this request permanently?');">
            <input type="hidden" name="lesson_id" value="<?= (int)$row['lesson_id'] ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-danger"><?= ui_icon('trash', 15) ?> Delete Request</button>
        </form>
    </div>

</div>
<?php endwhile; ?>
<?php else: ?>
    <div class="empty animate-rise d2">
        <div class="empty-icon"><?= ui_icon('notes', 40) ?></div>
        <div class="empty-title">No new lesson requests</div>
        <p class="small" style="margin:0;">When students request a lesson, it will appear here.</p>
    </div>
<?php endif; ?>

<!-- =====================
     Section C: Live Recitation Requests
===================== -->
<h2 id="sec-c" class="mt-3 animate-rise d3" style="display:flex;align-items:center;gap:10px;">
    <span class="badge badge-blue" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);">C</span> Pending Live Recitation Requests
    <?php if ($cnt_c > 0): ?><span class="badge badge-count"><?= $cnt_c ?></span><?php endif; ?>
</h2>

<?php if ($live_requests && $live_requests->num_rows > 0): ?>
<?php while ($lr = $live_requests->fetch_assoc()): ?>
<div class="tcard animate-rise d3" data-sec="c">

    <div class="tcard-head">
        <span class="tavatar"><?= htmlspecialchars(ui_initial($lr['student_name'] ?? '?')) ?></span>
        <div class="tmeta">
            <h3><?= htmlspecialchars($lr['student_name']) ?></h3>
            <span><?= htmlspecialchars($lr['student_email']) ?></span>
        </div>
    </div>

    <p class="small" style="margin:0 0 12px;">
        <span class="badge badge-green"><?= htmlspecialchars($lr['surah_name']) ?></span><br>
        <span class="text-muted"><?= ui_icon('calendar', 14) ?> <?= htmlspecialchars($lr['preferred_date']) ?></span> ·
        <span class="text-muted"><?= ui_icon('clock', 14) ?> <?= htmlspecialchars($lr['preferred_time']) ?></span>
    </p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button class="btn btn-sm" onclick="handleLiveRequest(<?= (int)$lr['id'] ?>,'accepted',this)"><?= ui_icon('check', 15) ?> Accept</button>
        <button class="btn btn-sm btn-danger" onclick="handleLiveRequest(<?= (int)$lr['id'] ?>,'rejected',this)"><?= ui_icon('close', 15) ?> Reject</button>
        <span class="teach-status" id="livemsg_<?= (int)$lr['id'] ?>" style="display:none;margin:0;"></span>
    </div>

</div>
<?php endwhile; ?>
<?php else: ?>
    <div class="empty animate-rise d3">
        <div class="empty-icon"><?= ui_icon('video', 40) ?></div>
        <div class="empty-title">No live recitation requests</div>
        <p class="small" style="margin:0;">Live session requests from students will appear here.</p>
    </div>
<?php endif; ?>

<!-- =====================
     Section D: Hafiz Revision Sessions
     Header requires the same two tables as the Section D query above, so the
     header can never appear without data (or vice-versa vs the badge).
===================== -->
<?php if (db_table_exists($conn, 'hafiz_sessions') && db_table_exists($conn, 'hafiz_revision')): ?>
<h2 id="sec-d" class="mt-3 animate-rise d4" style="display:flex;align-items:center;gap:10px;">
    <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);">D</span> Hafiz Revision — Review Pages
    <?php if ($cnt_d > 0): ?><span class="badge badge-count"><?= $cnt_d ?></span><?php endif; ?>
</h2>

<?php if (!empty($hafiz_groups)): ?>
<?php foreach ($hafiz_groups as $g): ?>
<div class="tcard animate-rise d4" data-sec="d">

    <div class="tcard-head">
        <span class="tavatar"><?= htmlspecialchars(ui_initial($g['student_name'] ?? '?')) ?></span>
        <div class="tmeta">
            <h3><?= htmlspecialchars($g['student_name']) ?></h3>
            <span><?= htmlspecialchars($g['student_email']) ?></span>
        </div>
    </div>

    <p class="small" style="margin:0 0 12px;">
        Cycle #<?= $g['cycle_no'] ?> · <strong><?= count($g['pages']) ?></strong> page(s) awaiting your verdict
        &nbsp;·&nbsp; Student's next page: <?= $g['current_page'] ?>
    </p>

    <?php $pi = 0; foreach ($g['pages'] as $hs): $pi++; ?>
    <div style="display:flex;flex-direction:column;gap:8px;padding:14px 0;border-top:1px solid var(--border);<?= $pi === 1 ? 'border-top:none;padding-top:0;' : '' ?>">

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <span class="badge badge-green">Page <?= (int)$hs['page_no'] ?></span>
            <?php if ($hs['status'] === 'rejected'): ?>
                <span class="badge badge-red"><?= ui_icon('close', 13) ?> Flagged — Needs Work</span>
            <?php else: ?>
                <span class="badge badge-gold"><?= ui_icon('clock', 13) ?> Awaiting Review</span>
            <?php endif; ?>
            <span class="small text-muted"><?= $hs['session_type'] === 'live' ? 'Live Session' : 'Audio Recording' ?></span>
            <span class="small text-muted"><?= date('d M Y, g:i A', strtotime($hs['submitted_at'])) ?></span>
        </div>

        <?php if ($hs['session_type'] === 'audio' && !empty($hs['audio_file'])): ?>
            <?= media_player_html($hs['audio_file'], '../uploads/student_audio/') ?>
        <?php elseif ($hs['session_type'] === 'live'): ?>
            <p class="small text-muted" style="margin:0;"><?= ui_icon('video', 14) ?> Live recitation session — the student recited off-head during the scheduled call.</p>
        <?php endif; ?>

        <?php if ($hs['status'] === 'rejected' && !empty($hs['rating'])): ?>
            <p class="small" style="margin:0;color:var(--danger);">Previous rating: <?= htmlspecialchars($hs['rating']) ?><?= !empty($hs['feedback']) ? ' · Previous feedback: "' . htmlspecialchars($hs['feedback']) . '"' : '' ?></p>
        <?php endif; ?>

        <form method="POST" action="review_hafiz_session.php" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="session_id" value="<?= (int)$hs['session_id'] ?>">
            <?= csrf_field() ?>

            <div class="form-group" style="flex:2 1 240px;margin:0;">
                <label class="form-label">Error details (optional)</label>
                <textarea class="form-textarea" name="feedback" rows="2" placeholder="Only if you flag the page — e.g. Page 3, line 4, mispronounced word..."><?= htmlspecialchars($hs['feedback'] ?? '') ?></textarea>
            </div>

            <div class="form-group" style="flex:1 1 150px;margin:0;">
                <label class="form-label">Rating (optional)</label>
                <select class="form-select" name="rating">
                    <option value="">--</option>
                    <option>Excellent</option>
                    <option>Very Good</option>
                    <option>Good</option>
                    <option>Fair</option>
                    <option>Needs Improvement</option>
                    <option>Fail</option>
                </select>
            </div>

            <div style="display:flex;gap:8px;">
                <button class="btn" type="submit" name="status" value="accepted"><?= ui_icon('check', 15) ?> Pass</button>
                <button class="btn btn-danger" type="submit" name="status" value="rejected"><?= ui_icon('close', 15) ?> Flag Page</button>
            </div>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
    <div class="empty animate-rise d4">
        <div class="empty-icon"><?= ui_icon('check-circle', 40) ?></div>
        <div class="empty-title">No hafiz pages to review</div>
        <p class="small" style="margin:0;">When a hafiz submits pages and they're awaiting your verdict, they'll appear here by student.</p>
    </div>
<?php endif; ?>
<?php endif; ?>

<!-- =====================
     Section E: Hafiz Weekly Tests
     Header is always shown (with empty-state) so a badge count can never
     point at a missing section.
===================== -->
<h2 id="sec-e" class="mt-3 animate-rise d5" style="display:flex;align-items:center;gap:10px;">
    <span class="badge" style="background:linear-gradient(135deg,#d97706,#f59e0b);">E</span> Hafiz Weekly Tests — Awaiting Review
    <?php if ($cnt_e > 0): ?><span class="badge badge-count"><?= $cnt_e ?></span><?php endif; ?>
</h2>

<?php if ($hafiz_tests && $hafiz_tests->num_rows > 0): ?>
<?php while ($ht = $hafiz_tests->fetch_assoc()): ?>
<div class="tcard animate-rise d5" data-sec="e">

    <div class="tcard-head">
        <span class="tavatar"><?= htmlspecialchars(ui_initial($ht['student_name'] ?? '?')) ?></span>
        <div class="tmeta">
            <h3><?= htmlspecialchars($ht['student_name']) ?></h3>
            <span><?= htmlspecialchars($ht['student_email']) ?></span>
        </div>
    </div>

    <p class="small">
        <span class="badge badge-blue">Week <?= (int)$ht['week_no'] ?></span>
        &nbsp;· <span class="badge badge-gold">Awaiting Review</span>
        &nbsp;· <?= (int)$ht['q_count'] ?> question<?= (int)$ht['q_count'] === 1 ? '' : 's' ?>
        &nbsp;· Started <?= $ht['started_at'] ? date('d M Y, g:i A', strtotime($ht['started_at'])) : '—' ?>
        &nbsp;· Submitted <?= $ht['submitted_at'] ? date('d M Y, g:i A', strtotime($ht['submitted_at'])) : '—' ?>
    </p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <a class="btn btn-gold" href="review_hafiz_test.php?id=<?= (int)$ht['test_id'] ?>"><?= ui_icon('gavel', 16) ?> Review Test</a>
        <form method="POST" action="delete_hafiz_test.php"
              onsubmit="return confirm('Delete this weekly test permanently? The student will need to generate a new one.');">
            <input type="hidden" name="test_id" value="<?= (int)$ht['test_id'] ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-danger"><?= ui_icon('trash', 15) ?> Delete Test</button>
        </form>
    </div>

</div>
<?php endwhile; ?>
<?php else: ?>
    <div class="empty animate-rise d5">
        <div class="empty-icon"><?= ui_icon('check-circle', 40) ?></div>
        <div class="empty-title">No pending weekly tests</div>
        <p class="small" style="margin:0;">Hafiz weekly tests awaiting your review will appear here.</p>
    </div>
<?php endif; ?>

<!-- =====================
     Section F: Qur'an Memorization — Muraja'ah Sessions Awaiting Review
     Header always shown so badge/hero pills always land on a visible section.
===================== -->
<h2 id="sec-f" class="mt-3 animate-rise d5" style="display:flex;align-items:center;gap:10px;">
    <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);">F</span> Qur'an Memorization — Muraja'ah Sessions Awaiting Review
    <?php if ($cnt_f > 0): ?><span class="badge badge-count"><?= $cnt_f ?></span><?php endif; ?>
</h2>

<?php if (!empty($mem_queue)): ?>
<?php foreach ($mem_queue as $mq): ?>
<div class="tcard animate-rise d5" data-sec="f">

    <div class="tcard-head">
        <span class="tavatar"><?= htmlspecialchars(ui_initial($mq['name'] ?? '?')) ?></span>
        <div class="tmeta">
            <h3><?= htmlspecialchars($mq['name'] ?? 'Student') ?></h3>
            <span><?= htmlspecialchars($mq['student_email'] ?? $mq['email'] ?? '') ?></span>
        </div>
    </div>

    <p class="small">
        <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;">
            Pages <?= (int)$mq['start_page'] ?>–<?= (int)$mq['end_page'] ?>
        </span>
        <?php if ($mq['day_session'] !== 'full'): ?>
            &nbsp;· <span class="badge badge-blue"><?= ucfirst($mq['day_session']) ?> Session</span>
        <?php endif; ?>
        &nbsp;· <span class="badge badge-gold"><?= $mq['session_type'] === 'live' ? 'Live via WhatsApp' : ($mq['session_type'] === 'video' ? 'Video' : ($mq['session_type'] === 'inperson' ? 'In-Person' : 'Audio')) ?></span>
        &nbsp;· Submitted <?= $mq['submitted_at'] ? date('d M Y, g:i A', strtotime($mq['submitted_at'])) : '—' ?>
    </p>

    <?php if (in_array($mq['session_type'], ['audio', 'video'], true) && !empty($mq['audio_file'])): ?>
        <div style="margin:10px 0;"><?= media_player_html($mq['audio_file'], '../uploads/student_audio/') ?></div>
    <?php elseif ($mq['session_type'] === 'inperson'): ?>
        <div class="alert alert-info" style="padding:8px 12px;margin:10px 0;">
            <?= ui_icon('user', 15) ?> Student recited this range <strong>in person</strong>. Listen to them, then pass or fail below. A written feedback note is recommended.
        </div>
    <?php endif; ?>

    <form method="POST" action="review_murajaah.php" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="session_id" value="<?= (int)$mq['id'] ?>">
        <?= csrf_field() ?>
        <div style="flex:2;min-width:220px;">
            <label class="form-label">Feedback / Tajweed Notes</label>
            <textarea class="form-input" name="feedback" rows="2" placeholder="Optional notes for the student…"></textarea>
        </div>
        <div style="flex:1;min-width:180px;">
            <label class="form-label">Voice Feedback (optional)</label>
            <input class="form-input" type="file" name="admin_audio" accept="audio/*,.mp3,.m4a,.wav,.ogg,.webm,.aac,.mp4,.m4v,.mov,.3gp">
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-gold" type="submit" name="status" value="passed" onclick="return confirm('Mark this Muraja\'ah session as PASSED? The student will advance.');"><?= ui_icon('check', 15) ?> Pass</button>
            <button class="btn btn-danger" type="submit" name="status" value="failed" onclick="return confirm('Mark this Muraja\'ah session as FAILED? The student will be asked to practice and retake.');"><?= ui_icon('close', 15) ?> Fail</button>
        </div>
    </form>

</div>
<?php endforeach; ?>
<?php else: ?>
    <div class="empty animate-rise d5">
        <div class="empty-icon"><?= ui_icon('check-circle', 40) ?></div>
        <div class="empty-title">No muraja'ah sessions awaiting review</div>
        <p class="small" style="margin:0;">Qur'an memorization submissions will appear here once students submit them.</p>
    </div>
<?php endif; ?>

<!-- =====================
     Section G: Recitation Assistance Requests
     Header always shown so badge/hero pills always land on a visible section.
===================== -->
<h2 id="sec-g" class="mt-3 animate-rise d6" style="display:flex;align-items:center;gap:10px;">
    <span class="badge" style="background:linear-gradient(135deg,#d97706,#f59e0b);">G</span> Recitation Assistance Requests
    <?php if ($cnt_g > 0): ?><span class="badge badge-count"><?= $cnt_g ?></span><?php endif; ?>
</h2>

<?php if (!empty($assistance_requests)): ?>
<?php foreach ($assistance_requests as $ar): ?>
<div class="tcard animate-rise d6" data-sec="g">
    <div class="tcard-head">
        <span class="tavatar"><?= htmlspecialchars(ui_initial($ar['name'] ?? '?')) ?></span>
        <div class="tmeta">
            <h3><?= htmlspecialchars($ar['name'] ?? 'Student') ?></h3>
            <span><?= htmlspecialchars($ar['email'] ?? '') ?></span>
        </div>
    </div>

    <p class="small" style="margin:8px 0 10px;">
        <span class="badge" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;">
            Page <?= (int)$ar['start_page'] ?>
        </span>
        &nbsp;· Day <?= (int)$ar['task_day'] ?>
        &nbsp;· Requested <?= $ar['created_at'] ? date('d M Y, g:i A', strtotime($ar['created_at'])) : '—' ?>
        <?php if (!empty($ar['phone'])): ?>
            <?php
                $wa_msg = "Assalamu alaikum " . ($ar['name'] ?? 'Student') . ", regarding your recitation assistance request for Page " . (int)$ar['start_page'] . " of the Qur'an...";
                $wa_url = "https://wa.me/" . rawurlencode(normalize_phone_to_intl($ar['phone'])) . "?text=" . rawurlencode($wa_msg);
            ?>
            &nbsp; · <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($wa_url) ?>" target="_blank" rel="noopener"><?= ui_icon('send', 14) ?> WhatsApp</a>
        <?php endif; ?>
    </p>

    <?php if (!empty($ar['note'])): ?>
        <blockquote style="margin:0 0 10px;padding:8px 12px;border-left:4px solid var(--gold);background:var(--surface-muted);border-radius:0 8px 8px 0;">
            &ldquo;<?= htmlspecialchars($ar['note']) ?>&rdquo;
        </blockquote>
    <?php endif; ?>

    <form method="POST" action="handle_mem_assistance.php" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="request_id" value="<?= (int)$ar['id'] ?>">
        <?= csrf_field() ?>
        <div style="flex:1;min-width:180px;">
            <label class="form-label">Recite audio for the student (optional)</label>
            <input class="form-input" type="file" name="admin_audio" accept="audio/*,.mp3,.m4a,.wav,.ogg,.m4v,.mov,.3gp">
        </div>
        <div style="flex:2;min-width:220px;">
            <label class="form-label">Notes for the student (optional)</label>
            <textarea class="form-input" name="admin_notes" rows="2" placeholder="e.g. Tajweed tips for Page <?= (int)$ar['start_page'] ?>…"></textarea>
        </div>
        <button class="btn btn-gold" type="submit"><?= ui_icon('check-circle', 15) ?> Mark Fulfilled</button>
    </form>
</div>
<?php endforeach; ?>
<?php else: ?>
    <div class="empty animate-rise d6">
        <div class="empty-icon"><?= ui_icon('check-circle', 40) ?></div>
        <div class="empty-title">No assistance requests</div>
        <p class="small" style="margin:0;">When students ask for help with a page, their request will appear here.</p>
    </div>
<?php endif; ?>

<?php ui_page_end(); ?>

<script src="/assets/js/recorder.js"></script>
<script>
const TEACH_CSRF = '<?= csrf_token() ?>';

/* ---------- Section B: lesson voice recorder (one active recording at a time) ---------- */
const recState = {}; // lessonId -> { recorder, stream, chunks, mime, ext, blob }
let activeRecId = null;
let recTickTimer = null;
let recSecs = 0;
const REC_MAX_MS = 5 * 60 * 1000;
let recMaxTimer = null;

function recEls(id) {
  return {
    start: document.getElementById('recstart_' + id),
    stop: document.getElementById('recstop_' + id),
    ind: document.getElementById('recind_' + id),
    time: document.getElementById('rectime_' + id),
    audio: document.getElementById('audio_' + id),
    send: document.getElementById('send_' + id),
    msg: document.getElementById('recmsg_' + id)
  };
}

function recMsg(id, text, kind) {
  const m = recEls(id).msg;
  if (!m) return;
  m.style.display = text ? 'block' : 'none';
  m.textContent = text || '';
  m.classList.toggle('err', kind === 'err');
  m.classList.toggle('ok', kind === 'ok');
}

function recClock(id) {
  const t = recEls(id).time;
  if (t) t.textContent = Math.floor(recSecs / 60) + ':' + String(recSecs % 60).padStart(2, '0');
}

function recResetButtons(id, recording) {
  const e = recEls(id);
  if (e.start) e.start.disabled = recording;
  if (e.stop) e.stop.disabled = !recording;
  if (e.ind) e.ind.classList.toggle('on', recording);
}

function recClearTimers() {
  if (recTickTimer) { clearInterval(recTickTimer); recTickTimer = null; }
  if (recMaxTimer) { clearTimeout(recMaxTimer); recMaxTimer = null; }
}

function startRecording(id) {
  if (activeRecId !== null && activeRecId !== id) {
    alert('Stop the current recording first before starting a new one.');
    return;
  }
  if (activeRecId === id) return; // already recording
  if (!window.Recorder || !Recorder.supported()) {
    recMsg(id, 'Recording is not available here (browser or insecure connection). Use “Or upload an audio file instead” below.', 'err');
    return;
  }

  const picked = Recorder.pick();
  // Fresh take: hide any previous playback until the new take is ready.
  const e = recEls(id);
  if (e.audio) { e.audio.removeAttribute('src'); e.audio.style.display = 'none'; }
  if (e.send) e.send.style.display = 'none';
  recMsg(id, '', '');

  navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
    const st = { recorder: null, stream: stream, chunks: [], mime: picked.mime, ext: picked.ext, blob: null };
    try {
      st.recorder = Recorder.create(stream, picked.mime);
    } catch (err) {
      stream.getTracks().forEach(t => t.stop());
      alert('Could not start recording.');
      return;
    }
    try { if (st.recorder.mimeType) st.mime = st.recorder.mimeType; } catch (err) {}
    st.recorder.ondataavailable = ev => { if (ev.data && ev.data.size) st.chunks.push(ev.data); };
    st.recorder.onerror = () => {
      recClearTimers();
      stream.getTracks().forEach(t => t.stop());
      activeRecId = null;
      recResetButtons(id, false);
      recMsg(id, 'Recording failed. Please try again.', 'err');
    };
    try {
      st.recorder.start(1000);
    } catch (err) {
      stream.getTracks().forEach(t => t.stop());
      alert('Could not start recording.');
      return;
    }
    recState[id] = st;
    activeRecId = id;
    recSecs = 0;
    recClock(id);
    recResetButtons(id, true);
    recTickTimer = setInterval(() => { recSecs++; recClock(id); }, 1000);
    recMaxTimer = setTimeout(() => {
      if (activeRecId === id) {
        stopRecording(id);
        recMsg(id, 'Stopped automatically after 5 minutes. Review it above, then Send.', 'ok');
      }
    }, REC_MAX_MS);
  }).catch(() => {
    recMsg(id, 'Mic access was denied. Allow microphone permission, or use “Or upload an audio file instead” below.', 'err');
  });
}

function stopRecording(id) {
  const st = recState[id];
  if (!st || !st.recorder) return; // never started (or already stopped) — no crash
  if (activeRecId !== id) return;
  try {
    if (st.recorder.state !== 'recording') return;
  } catch (err) { /* fall through to stop attempt */ }

  st.recorder.onstop = () => {
    recClearTimers();
    activeRecId = null;
    const blob = Recorder.makeBlob(st.chunks, st.recorder, st.mime);
    if (st.stream) st.stream.getTracks().forEach(t => t.stop());
    recResetButtons(id, false);
    if (!blob || blob.size === 0) {
      recMsg(id, 'Recording is empty. Please record again.', 'err');
      return;
    }
    st.blob = blob;
    const e = recEls(id);
    if (e.audio) {
      e.audio.src = URL.createObjectURL(blob);
      e.audio.style.display = 'block';
    }
    if (e.send) e.send.style.display = 'inline-flex';
    recMsg(id, 'Ready — listen above, then tap "Send to Student".', 'ok');
  };
  try {
    st.recorder.stop();
  } catch (err) {
    recClearTimers();
    activeRecId = null;
    recResetButtons(id, false);
  }
}

function sendAdminAudio(studentId, lessonId) {
  const e = recEls(lessonId);
  const st = recState[lessonId];
  if (!st || !st.blob) {
    recMsg(lessonId, 'Record audio first (Start → Stop), then send.', 'err');
    return;
  }
  if (e.send) e.send.disabled = true;
  recMsg(lessonId, 'Sending…', '');
  const fd = new FormData();
  fd.append('audio', st.blob, 'admin_audio_' + lessonId + '.' + (st.ext || 'webm'));
  fd.append('student_id', studentId);
  fd.append('plan_id', lessonId);
  fd.append('csrf_token', TEACH_CSRF);
  fetch('submit_admin_audio.php', { method: 'POST', body: fd })
    .then(res => {
      if (!res.ok) throw new Error('Upload failed (HTTP ' + res.status + ').');
      location.reload();
    })
    .catch(err => {
      if (e.send) e.send.disabled = false;
      recMsg(lessonId, err.message || 'Upload failed. Please try again.', 'err');
    });
}

function handleLiveRequest(id, action, btn) {
  const row = btn ? btn.parentElement : null;
  const btns = row ? row.querySelectorAll('button') : [];
  btns.forEach(b => { b.disabled = true; });
  const msg = document.getElementById('livemsg_' + id);
  if (msg) { msg.style.display = 'inline'; msg.textContent = 'Working…'; }
  const fd = new FormData();
  fd.append('id', id);
  fd.append('action', action);
  fd.append('csrf_token', TEACH_CSRF);
  fetch('handle_live_request.php', {
    method: 'POST',
    body: fd,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.text()).then(res => {
    if (res.trim() === 'OK') { location.reload(); return; }
    throw new Error(res || 'Request failed.');
  })
  .catch(err => {
    btns.forEach(b => { b.disabled = false; });
    if (msg) msg.textContent = err.message || 'Failed. Try again.';
    else alert(err.message || 'Failed. Try again.');
  });
}

/* ---------- Dashboard tabs + scoped search ---------- */
const teachGroups = {}; // a..g -> { head, els }
(function buildGroups() {
  const heads = Array.from(document.querySelectorAll('h2[id^="sec-"]'));
  heads.forEach(h => {
    const m = (h.id || '').match(/^sec-([a-g])$/);
    if (!m) return;
    const next = heads[heads.indexOf(h) + 1] || null;
    const els = [];
    let el = h.nextElementSibling;
    while (el && el !== next) { els.push(el); el = el.nextElementSibling; }
    teachGroups[m[1]] = { head: h, els: els };
  });
})();

let activeTab = 'a';

function applySearch() {
  const input = document.getElementById('teachSearch');
  const count = document.getElementById('teachSearchCount');
  const q = input ? input.value.trim().toLowerCase() : '';
  const g = teachGroups[activeTab];
  if (!g) return;
  let shown = 0, cards = 0;
  g.els.forEach(el => {
    if (!el.classList) return;
    if (el.classList.contains('tcard') || el.classList.contains('card')) {
      cards++;
      const hit = !q || (el.textContent || '').toLowerCase().includes(q);
      el.style.display = hit ? '' : 'none';
      if (hit) shown++;
    } else if (el.classList.contains('empty')) {
      el.style.display = q ? 'none' : '';
    }
  });
  if (count) count.textContent = q ? (shown > 0 ? ('Showing ' + shown + ' of ' + cards) : 'No matches in this section.') : '';
}

function showTab(key) {
  if (!teachGroups[key]) return;
  activeTab = key;
  document.querySelectorAll('[data-tab]').forEach(b => {
    b.classList.toggle('is-active', b.getAttribute('data-tab') === key);
  });
  Object.keys(teachGroups).forEach(k => {
    const g = teachGroups[k], on = (k === key);
    g.head.style.display = on ? '' : 'none';
    g.els.forEach(el => {
      if (!el.classList) return;
      if (el.classList.contains('tcard') || el.classList.contains('card') || el.classList.contains('empty')) {
        el.style.display = on ? '' : 'none';
      }
    });
  });
  applySearch();
  try { history.replaceState(null, '', '#tab-' + key); } catch (e) { /* ignore */ }
}

document.querySelectorAll('[data-tab]').forEach(b => {
  b.addEventListener('click', () => {
    const s = document.getElementById('teachSearch');
    if (s) s.value = '';
    showTab(b.getAttribute('data-tab'));
  });
});

(function initTab() {
  let key = null;
  try {
    const h = window.location.hash || '';
    let m = h.match(/^#tab-([a-g])$/);
    if (m) key = m[1];
    else { m = h.match(/^#sec-([a-g])$/); if (m) key = m[1]; }
  } catch (e) { /* ignore */ }
  const initial = document.querySelector('.ttab.is-active');
  showTab(key || (initial ? initial.getAttribute('data-tab') : 'a'));
  const input = document.getElementById('teachSearch');
  if (input) input.addEventListener('input', applySearch);
})();
</script>

</body>
</html>