<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

/* Guard: must be Hafiz */
if (!student_is_hafiz($conn, $student_id)) {
    redirect('dashboard.php');
}

/* Schema check */
if (!db_table_exists($conn, 'hafiz_revision') || !db_table_exists($conn, 'hafiz_sessions')) {
    redirect('dashboard.php');
}

/* Check week transition */
$week_transition = hafiz_check_week_transition($conn, $student_id);

/* Fetch active revision */
$revision = hafiz_get_active_revision($conn, $student_id);

if (!$revision) {
    // No active revision — this shouldn't happen for a Hafiz, but handle gracefully
    $can_recite = false;
    $can_recite_reason = 'You do not have an active revision cycle. Please contact the admin.';
    $current_page = 1;
    $week_no = 1;
    $pages_this_week = 0;
    $pages_remaining = 20;
    $completed_cycles = hafiz_completed_cycles_count($conn, $student_id);
    $pending_session = null;
    $recent_sessions = [];
} else {
    $current_page = (int)$revision['current_page'];
    $week_no = hafiz_current_week_no($revision);
    $revision_id = (int)$revision['id'];

    $pages_this_week = hafiz_pages_this_week($conn, $revision_id, $week_no);
    $pages_remaining = max(0, 20 - $pages_this_week);
    $weekly_remaining = max(0, 21 - $pages_this_week);
    $completed_cycles = hafiz_completed_cycles_count($conn, $student_id);

    // Check if can recite
    $can_check = hafiz_can_recite($conn, $student_id);
    $can_recite = $can_check['ok'];
    $can_recite_reason = $can_check['reason'];

    // Get pending session if any
    $pending_session = null;
    $stmt = $conn->prepare("SELECT * FROM hafiz_sessions WHERE student_id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $pending_session = $stmt->get_result()->fetch_assoc();

    // Recent sessions
    $stmt = $conn->prepare("
        SELECT hs.*, 
            CASE 
                WHEN hs.status = 'accepted' THEN 'accepted'
                WHEN hs.status = 'rejected' THEN 'rejected'
                ELSE 'pending'
            END as display_status
        FROM hafiz_sessions hs
        WHERE hs.student_id = ?
        ORDER BY hs.submitted_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $recent_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$progress_pct = $current_page > 1 ? round((($current_page - 1) / 604) * 100) : 0;
$week_started_at = $revision['week_started_at'] ?? date('Y-m-d H:i:s');
$friday = hafiz_friday_boundary($week_started_at);
$hours_until_friday = max(0, round((strtotime($friday) - time()) / 3600, 1));

// Check which pages are completed
$completed_pages = [];
if ($revision) {
    $stmt = $conn->prepare("SELECT page_no FROM hafiz_sessions WHERE revision_id = ? AND status = 'accepted' ORDER BY page_no ASC");
    $stmt->bind_param("i", $revision_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $completed_pages[] = (int)$r['page_no'];
    }
}

// WhatsApp number for live recitation
$whatsapp_number = setting($conn, 'whatsapp_number', '2348029979040');
?>
<!DOCTYPE html>
<html>
<head>
<title>Qur'an Revision</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'dashboard', 'Qur\'an Revision'); ?>
<?= csrf_field() ?>

<div class="page-hero animate-rise">
    <h1>Qur'an Revision</h1>
    <p>Recite from memory, page by page.</p>
</div>

<?php if ($week_transition['action'] === 'reset'): ?>
<div class="alert alert-danger animate-rise">
    <?= ui_icon('alert', 18) ?>
    <span style="flex:1;"><strong>Weekly target not met.</strong> Your progress has been reset to page 1. Start your revision cycle again.</span>
</div>
<?php endif; ?>

<?php if ($revision && $current_page > 604): ?>
<div class="card card-gold animate-rise" style="text-align:center;">
    <div style="font-size:3rem;margin-bottom:10px;">🎉</div>
    <h2 style="margin:0 0 8px;">Masha'Allah! Cycle Complete!</h2>
    <p class="text-muted" style="margin:0 0 16px;">You have completed all 604 pages of the Qur'an. You have completed <strong><?= $completed_cycles ?></strong> cycle(s).</p>
    <form method="POST" action="submit_hafiz_session.php" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start_new_cycle">
        <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('refresh', 18) ?> Start New Revision Cycle</button>
    </form>
</div>
<?php else: ?>

<!-- Progress Overview -->
<div class="card animate-rise d1">
    <div class="card-title" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">Cycle #<?= (int)($revision['cycle_no'] ?? 1) ?></h3>
        <?php if ($completed_cycles > 0): ?>
            <span class="badge badge-blue"><?= $completed_cycles ?> previous cycle(s) completed</span>
        <?php endif; ?>
    </div>

    <div class="grid-3" style="margin:14px 0 4px;">
        <div class="panel" style="margin:0;text-align:center;">
            <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--emerald-800);"><?= $current_page - 1 ?> / 604</div>
            <div class="small text-muted">Pages Completed</div>
        </div>
        <div class="panel" style="margin:0;text-align:center;">
            <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--gold-deep);"><?= $progress_pct ?>%</div>
            <div class="small text-muted">Overall Progress</div>
        </div>
        <div class="panel" style="margin:0;text-align:center;">
            <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--emerald-800);"><?= $week_no ?> / 30</div>
            <div class="small text-muted">Week Number</div>
        </div>
    </div>

    <div class="progress" style="margin-top:6px;">
        <div class="progress-fill" style="width:<?=$progress_pct?>%"></div>
        <div class="progress-text"><?=$progress_pct?>%</div>
    </div>

    <!-- Weekly Progress -->
    <div style="margin-top:14px;padding:12px;background:var(--panel-bg);border-radius:var(--radius);border:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
            <strong>Week <?= $week_no ?> Target</strong>
            <span class="small text-muted">Week ends: Friday 11:59 PM (<?= $hours_until_friday ?>h left)</span>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
            <span class="small"><strong><?= $pages_this_week ?></strong> / 20 pages this week</span>
            <span class="small <?= $pages_remaining > 0 ? 'text-muted' : 'badge badge-green' ?>">
                <?= $pages_remaining > 0 ? $pages_remaining . ' more needed' : 'Target met!' ?>
            </span>
            <span class="small text-muted">You can do <?= $weekly_remaining ?> more this week</span>
        </div>
        <?php if ($revision && (int)$revision['skip_approved']): ?>
            <div class="small" style="margin-top:8px;color:var(--emerald-700);"><?= ui_icon('check-circle', 14) ?> Admin has approved you to skip this week.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Current Page Card -->
<div class="card animate-rise d2">
    <div class="card-title">
        <h3 style="margin:0;"><?= ui_icon('book-open', 18) ?> Next Page to Recite</h3>
    </div>

    <?php if ($pending_session): ?>
        <div class="alert alert-info" style="margin:0;">
            <?= ui_icon('clock', 16) ?>
            <span style="flex:1;">You have a pending recitation for <strong>Page <?= (int)$pending_session['page_no'] ?></strong> awaiting teacher review. Please wait.</span>
        </div>
    <?php elseif ($can_recite): ?>
        <div style="text-align:center;padding:20px 0;">
            <div style="font-size:2.5rem;font-weight:800;color:var(--emerald-700);margin-bottom:8px;">Page <?= $current_page ?></div>
            <p class="small text-muted" style="margin:0 0 16px;">Click below to start reciting this page from memory.</p>

            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <button class="btn btn-gold btn-lg" onclick="openReciteModal('live')"><?= ui_icon('video', 18) ?> Live via WhatsApp</button>
                <button class="btn btn-lg" onclick="openReciteModal('audio')"><?= ui_icon('mic', 18) ?> Record Audio</button>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" style="margin:0;">
            <?= ui_icon('alert', 16) ?>
            <span style="flex:1;"><?= htmlspecialchars($can_recite_reason) ?></span>
        </div>
    <?php endif; ?>
</div>

<!-- Page Grid -->
<div class="card animate-rise d3">
    <div class="card-title">
        <h3 style="margin:0;"><?= ui_icon('grid', 18) ?> Page Grid</h3>
        <span class="small text-muted">Pages 1–604</span>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:3px;padding:10px 0;">
        <?php
        $page_status = [];
        foreach ($completed_pages as $p) {
            $page_status[$p] = 'completed';
        }
        if ($revision && $current_page <= 604) {
            $page_status[$current_page] = 'current';
        }
        for ($i = 1; $i <= 604; $i++):
            $class = 'page-cell page-locked';
            if (isset($page_status[$i]) && $page_status[$i] === 'completed') {
                $class = 'page-cell page-done';
            } elseif (isset($page_status[$i]) && $page_status[$i] === 'current') {
                $class = 'page-cell page-current';
            }
        ?>
            <div class="<?= $class ?>" title="Page <?= $i ?>"><?= $i ?></div>
        <?php endfor; ?>
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
        <span class="small"><span class="page-cell page-done" style="display:inline-flex;width:18px;height:18px;font-size:0.6rem;vertical-align:middle;"></span> Completed</span>
        <span class="small"><span class="page-cell page-current" style="display:inline-flex;width:18px;height:18px;font-size:0.6rem;vertical-align:middle;"></span> Current</span>
        <span class="small"><span class="page-cell page-locked" style="display:inline-flex;width:18px;height:18px;font-size:0.6rem;vertical-align:middle;"></span> Locked</span>
    </div>
</div>

<!-- Skip Week Request -->
<?php if ($revision && !$revision['skip_approved'] && $pages_this_week < 20): ?>
<div class="card animate-rise d4">
    <div class="card-title">
        <h3 style="margin:0;"><?= ui_icon('alert', 18) ?> Request to Skip This Week</h3>
    </div>
    <p class="small text-muted" style="margin:0 0 12px;">If you cannot meet the 20-page weekly target, you can request admin approval to skip. Without approval, missing the weekly target will reset your progress.</p>
    <form method="POST" action="submit_hafiz_session.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request_skip">
        <button class="btn btn-danger" type="submit" onclick="return confirm('Are you sure you want to request to skip this week?');"><?= ui_icon('alert', 16) ?> Request Week Skip</button>
    </form>
</div>
<?php endif; ?>

<!-- Recent Sessions -->
<?php if (!empty($recent_sessions)): ?>
<div class="card animate-rise d5">
    <div class="card-title">
        <h3 style="margin:0;"><?= ui_icon('history', 18) ?> Recent Sessions</h3>
    </div>

    <?php foreach ($recent_sessions as $s): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);flex-wrap:wrap;">
            <span class="badge badge-green">Page <?= (int)$s['page_no'] ?></span>
            <span class="small text-muted"><?= htmlspecialchars($s['session_type'] === 'live' ? 'Live' : 'Audio') ?></span>
            <span class="small text-muted"><?= date('d M Y, g:i A', strtotime($s['submitted_at'])) ?></span>
            <?php if ($s['status'] === 'accepted'): ?>
                <span class="badge badge-green">Accepted</span>
                <?php if (!empty($s['rating'])): ?>
                    <span class="small"><?= htmlspecialchars($s['rating']) ?></span>
                <?php endif; ?>
            <?php elseif ($s['status'] === 'rejected'): ?>
                <span class="badge badge-red">Rejected</span>
                <?php if (!empty($s['feedback'])): ?>
                    <span class="small text-muted">"<?= htmlspecialchars($s['feedback']) ?>"</span>
                <?php endif; ?>
                <span class="small" style="color:var(--danger);">Please re-recite this page.</span>
            <?php else: ?>
                <span class="badge badge-gold">Pending</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Recite Modal -->
<div class="modal" id="reciteModal">
    <div class="modal-content" style="max-width:480px;">
        <span class="modal-close" onclick="closeReciteModal()">&times;</span>
        <h3 style="margin:0 0 6px;">Recite Page <?= $current_page ?></h3>
        <p class="small text-muted" style="margin:0 0 16px;" id="reciteModeText">Choose how you want to recite.</p>

        <!-- Audio Recording -->
        <div id="audioPanel" style="display:none;">
            <p class="small" style="margin:0 0 12px;">Record yourself reciting Page <?= $current_page ?> from memory.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                <button class="btn" type="button" id="startRecBtn" onclick="startRecording()"><?= ui_icon('mic', 16) ?> Start Recording</button>
                <button class="btn btn-danger" type="button" id="stopRecBtn" onclick="stopRecording()" disabled><?= ui_icon('stop', 16) ?> Stop</button>
            </div>
            <audio id="recAudio" controls style="display:none;width:100%;"></audio>
            <div id="uploadProgress" style="display:none;" class="small text-muted">Uploading...</div>
            <button class="btn btn-gold btn-block" id="sendAudioBtn" style="display:none;margin-top:10px;" onclick="sendAudio()"><?= ui_icon('send', 16) ?> Submit Recitation</button>
        </div>

        <!-- Live via WhatsApp -->
        <div id="livePanel" style="display:none;">
            <p class="small" style="margin:0 0 12px;">You will be redirected to WhatsApp to schedule a live recitation with your teacher.</p>
            <div class="form-group">
                <label class="form-label">Preferred Day</label>
                <input class="form-input" type="date" id="liveDay" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Preferred Time</label>
                <input class="form-input" type="time" id="liveTime" required>
            </div>
            <button class="btn btn-gold btn-block" onclick="sendLive()"><?= ui_icon('send', 16) ?> Schedule via WhatsApp</button>
        </div>
    </div>
</div>

<style>
.page-cell {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid var(--border);
    transition: all 0.15s;
}
.page-done {
    background: var(--emerald-100, #d1fae5);
    color: var(--emerald-700, #047857);
    border-color: var(--emerald-300, #6ee7b7);
}
.page-current {
    background: var(--gold-light, #fef3c7);
    color: var(--gold-deep, #b45309);
    border-color: var(--gold-300, #fbbf24);
    font-weight: 800;
    animation: pulse 2s infinite;
}
.page-locked {
    background: var(--panel-bg, #f9fafb);
    color: var(--text-muted, #9ca3af);
}
@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.3); }
    50% { box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1); }
}
</style>

<?php ui_page_end(); ?>

<script>
let currentMode = '';
let mediaRecorder = null;
let audioBlobs = [];
let recordedBlob = null;

function openReciteModal(mode) {
    currentMode = mode;
    document.getElementById('reciteModal').classList.add('open');
    document.getElementById('audioPanel').style.display = mode === 'audio' ? 'block' : 'none';
    document.getElementById('livePanel').style.display = mode === 'live' ? 'block' : 'none';
    document.getElementById('reciteModeText').textContent = mode === 'live'
        ? 'Schedule a live recitation session via WhatsApp.'
        : 'Record yourself reciting this page from memory.';
}

function closeReciteModal() {
    document.getElementById('reciteModal').classList.remove('open');
}

document.addEventListener('click', function(e) {
    var m = document.getElementById('reciteModal');
    if (m && m.classList.contains('open') && e.target === m) closeReciteModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeReciteModal();
});

/* Audio Recording */
function startRecording() {
    navigator.mediaDevices.getUserMedia({audio: true}).then(function(stream) {
        audioBlobs = [];
        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = function(e) { if (e.data.size > 0) audioBlobs.push(e.data); };
        mediaRecorder.onstop = function() {
            var blob = new Blob(audioBlobs, {type: 'audio/webm'});
            recordedBlob = blob;
            var audio = document.getElementById('recAudio');
            audio.src = URL.createObjectURL(blob);
            audio.style.display = 'block';
            document.getElementById('sendAudioBtn').style.display = 'inline-flex';
            stream.getTracks().forEach(function(t) { t.stop(); });
        };
        mediaRecorder.start();
        document.getElementById('startRecBtn').disabled = true;
        document.getElementById('stopRecBtn').disabled = false;
    }).catch(function() {
        alert('Microphone access denied. Please allow microphone access and try again.');
    });
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    document.getElementById('startRecBtn').disabled = false;
    document.getElementById('stopRecBtn').disabled = true;
}

function sendAudio() {
    if (!recordedBlob) return alert('Please record your recitation first.');
    var fd = new FormData();
    fd.append('action', 'audio');
    fd.append('page_no', <?= $current_page ?>);
    fd.append('revision_id', <?= (int)($revision['id'] ?? 0) ?>);
    fd.append('audio', recordedBlob, 'page_<?= $current_page ?>.webm');
    var csrfInput = document.querySelector('[name=csrf_token]');
    if (csrfInput) fd.append('csrf_token', csrfInput.value);

    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('sendAudioBtn').disabled = true;

    fetch('submit_hafiz_session.php', {method: 'POST', body: fd})
        .then(function(r) { return r.text(); })
        .then(function(res) {
            if (res.trim() === 'OK') {
                location.reload();
            } else {
                alert(res);
                document.getElementById('uploadProgress').style.display = 'none';
                document.getElementById('sendAudioBtn').disabled = false;
            }
        })
        .catch(function() {
            alert('Upload failed. Please try again.');
            document.getElementById('uploadProgress').style.display = 'none';
            document.getElementById('sendAudioBtn').disabled = false;
        });
}

function sendLive() {
    var day = document.getElementById('liveDay').value;
    var time = document.getElementById('liveTime').value;
    if (!day || !time) return alert('Please select a day and time.');

    var csrfInput = document.querySelector('[name=csrf_token]');
    var token = csrfInput ? csrfInput.value : '';

    var fd = new URLSearchParams();
    fd.append('action', 'live');
    fd.append('page_no', <?= $current_page ?>);
    fd.append('revision_id', <?= (int)($revision['id'] ?? 0) ?>);
    fd.append('day', day);
    fd.append('time', time);
    fd.append('csrf_token', token);

    fetch('submit_hafiz_session.php', {method: 'POST', body: fd})
        .then(function(r) { return r.text(); })
        .then(function(res) {
            if (res.trim().indexOf('OK') === 0) {
                var whatsappUrl = res.replace('OK|', '');
                window.location.href = whatsappUrl;
            } else {
                alert(res);
            }
        })
        .catch(function() { alert('Request failed. Please try again.'); });
}
</script>

</body>
</html>
