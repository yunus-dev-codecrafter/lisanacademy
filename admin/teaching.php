<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

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
?>
<!DOCTYPE html>
<html>
<head>
<title>Teaching Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'teaching', 'Teaching', 'Dashboard'); ?>

<div class="page-hero animate-rise">
    <h1>Teaching Dashboard</h1>
    <p>Review recitations, prepare lessons and manage live sessions.</p>
</div>

<!-- =====================
     Section A: Student Submissions for Review
===================== -->
<h2 class="mt-2 animate-rise d1" style="display:flex;align-items:center;gap:10px;">
    <span class="badge badge-blue">A</span> Student Submissions for Review
</h2>

<?php if ($recitations && $recitations->num_rows > 0): ?>
    <?php $ri = 0; while ($r = $recitations->fetch_assoc()): $ri++; ?>
        <div class="card animate-rise d1">

            <div class="card-title" style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;">
                <h3 style="margin:0;"><?= htmlspecialchars($r['name']) ?></h3>
                <span class="small text-muted"><?= htmlspecialchars($r['email']) ?></span>
            </div>

            <p class="small">
                <span class="badge badge-green"><?= htmlspecialchars($r['surah_name']) ?></span>
                &nbsp;Verses <?= (int)$r['from_verse'] ?>–<?= (int)$r['to_verse'] ?>
            </p>

            <audio controls src="../uploads/student_audio/<?= htmlspecialchars($r['audio_file']) ?>"></audio>

            <form method="POST" action="review_recitation.php" enctype="multipart/form-data" style="margin-top:6px;">
                <input type="hidden" name="rec_id" value="<?= (int)$r['rec_id'] ?>">

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
                    <button type="submit" class="btn btn-sm btn-danger btn-ghost" style="background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);"><?= ui_icon('trash', 15) ?> Delete Recitation</button>
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
<h2 class="mt-3 animate-rise d2" style="display:flex;align-items:center;gap:10px;">
    <span class="badge badge-gold">B</span> New Lesson Requests
</h2>

<?php if ($new_requests && $new_requests->num_rows > 0): ?>
<?php while ($row = $new_requests->fetch_assoc()): ?>
<div class="card animate-rise d2">

    <div class="card-title" style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;">
        <h3 style="margin:0;"><?= htmlspecialchars($row['name']) ?></h3>
        <span class="small text-muted"><?= htmlspecialchars($row['email']) ?></span>
    </div>

    <p class="small">
        <span class="badge badge-green"><?= htmlspecialchars($row['surah_name']) ?></span>
        &nbsp;Verses <?= (int)$row['from_verse'] ?>–<?= (int)$row['to_verse'] ?>
    </p>

    <?php if ($row['device_type'] === 'iphone'): ?>
    <form method="post" enctype="multipart/form-data" action="submit_admin_audio.php">
        <input type="hidden" name="student_id" value="<?= (int)$row['student_id'] ?>">
        <input type="hidden" name="plan_id" value="<?= (int)$row['lesson_id'] ?>">
        <div class="form-group">
            <label class="form-label">Upload Lesson Audio</label>
            <input class="file-input" type="file" name="audio" accept="audio/*" required>
        </div>
        <button type="submit" class="btn btn-gold"><?= ui_icon('send', 16) ?> Send Audio</button>
    </form>
    <?php else: ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn btn-sm" onclick="startRecording(<?= (int)$row['lesson_id'] ?>)"><?= ui_icon('mic', 15) ?> Start Recording</button>
        <button class="btn btn-sm btn-ghost" onclick="stopRecording(<?= (int)$row['lesson_id'] ?>)"><?= ui_icon('stop', 15) ?> Stop</button>
    </div>

    <audio id="audio_<?= (int)$row['lesson_id'] ?>" controls></audio>

    <button class="btn btn-gold" id="send_<?= (int)$row['lesson_id'] ?>"
    onclick="sendAdminAudio(<?= (int)$row['student_id'] ?>, <?= (int)$row['lesson_id'] ?>)">
    <?= ui_icon('send', 16) ?> Send to Student
    </button>

    <?php endif; ?>

    <div class="left-block">
        <form method="POST" action="delete_request.php"
        onsubmit="return confirm('Delete this request permanently?');">
            <input type="hidden" name="lesson_id" value="<?= (int)$row['lesson_id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);"><?= ui_icon('trash', 15) ?> Delete Request</button>
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
<h2 class="mt-3 animate-rise d3" style="display:flex;align-items:center;gap:10px;">
    <span class="badge badge-blue" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);">C</span> Pending Live Recitation Requests
</h2>

<?php if ($live_requests && $live_requests->num_rows > 0): ?>
<?php while ($lr = $live_requests->fetch_assoc()): ?>
<div class="card animate-rise d3">

    <div class="card-title" style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;">
        <h3 style="margin:0;"><?= htmlspecialchars($lr['student_name']) ?></h3>
        <span class="small text-muted"><?= htmlspecialchars($lr['student_email']) ?></span>
    </div>

    <p class="small" style="margin:0 0 12px;">
        <span class="badge badge-green"><?= htmlspecialchars($lr['surah_name']) ?></span><br>
        <span class="text-muted"><?= ui_icon('calendar', 14) ?> <?= htmlspecialchars($lr['preferred_date']) ?></span> ·
        <span class="text-muted"><?= ui_icon('clock', 14) ?> <?= htmlspecialchars($lr['preferred_time']) ?></span>
    </p>

    <div style="display:flex;gap:10px;">
        <button class="btn btn-sm" onclick="handleLiveRequest(<?= (int)$lr['id'] ?>,'accepted')"><?= ui_icon('check', 15) ?> Accept</button>
        <button class="btn btn-sm btn-danger" onclick="handleLiveRequest(<?= (int)$lr['id'] ?>,'rejected')"><?= ui_icon('close', 15) ?> Reject</button>
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
===================== -->
<?php if (db_table_exists($conn, 'hafiz_sessions')): ?>
<h2 class="mt-3 animate-rise d4" style="display:flex;align-items:center;gap:10px;">
    <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);">D</span> Hafiz Revision — Review Pages
</h2>

<?php if (!empty($hafiz_groups)): ?>
<?php foreach ($hafiz_groups as $g): ?>
<div class="card animate-rise d4">

    <div class="card-title" style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;">
        <h3 style="margin:0;"><?= htmlspecialchars($g['student_name']) ?></h3>
        <span class="small text-muted"><?= htmlspecialchars($g['student_email']) ?></span>
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
            <audio controls preload="none" src="../uploads/student_audio/<?= htmlspecialchars($hs['audio_file']) ?>"></audio>
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
===================== -->
<?php if ($hafiz_tests): ?>
<h2 class="mt-3 animate-rise d5" style="display:flex;align-items:center;gap:10px;">
    <span class="badge" style="background:linear-gradient(135deg,#d97706,#f59e0b);">E</span> Hafiz Weekly Tests — Awaiting Review
</h2>

<?php if ($hafiz_tests->num_rows > 0): ?>
<?php while ($ht = $hafiz_tests->fetch_assoc()): ?>
<div class="card animate-rise d5">

    <div class="card-title" style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;">
        <h3 style="margin:0;"><?= htmlspecialchars($ht['student_name']) ?></h3>
        <span class="small text-muted"><?= htmlspecialchars($ht['student_email']) ?></span>
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
            <button type="submit" class="btn btn-sm" style="background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);"><?= ui_icon('trash', 15) ?> Delete Test</button>
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
<?php endif; ?>

<?php ui_page_end(); ?>

<script>
let mediaRecorder=null;
const recordedBlobs={};
const recordedStreams={};

const MIME = (window.MediaRecorder && (
    MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' :
    MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : ''
)) || '';
const REC_EXT = MIME === 'audio/mp4' ? 'm4a' : 'webm';

function startRecording(id){
navigator.mediaDevices.getUserMedia({audio:true}).then(stream=>{
recordedBlobs[id]=[];
recordedStreams[id]=stream;
mediaRecorder=new MediaRecorder(stream, MIME ? {mimeType:MIME} : undefined);
mediaRecorder.ondataavailable=e=>e.data.size&&recordedBlobs[id].push(e.data);
mediaRecorder.start();
}).catch(()=>alert('Mic access denied. You can upload an audio file instead.'));
}

function stopRecording(id){
mediaRecorder.onstop=()=>{
const blob=new Blob(recordedBlobs[id],{type: MIME || 'audio/webm'});
document.getElementById('audio_'+id).src=URL.createObjectURL(blob);
document.getElementById('send_'+id).style.display='inline-flex';
recordedBlobs[id]=blob;
if(recordedStreams[id]) recordedStreams[id].getTracks().forEach(t=>t.stop());
};
mediaRecorder.stop();
}

function sendAdminAudio(studentId, lessonId){
const fd=new FormData();
fd.append('audio',recordedBlobs[lessonId],'admin_audio_'+lessonId+'.'+REC_EXT);
fd.append('student_id',studentId);
fd.append('plan_id',lessonId);
fetch('submit_admin_audio.php',{method:'POST',body:fd}).then(()=>location.reload());
}

function handleLiveRequest(id,action){
const fd=new FormData();
fd.append('id',id);
fd.append('action',action);
fetch('handle_live_request.php',{method:'POST',body:fd})
.then(r=>r.text()).then(res=>{
if(res.trim()==='OK')location.reload();
else alert(res);
});
}
</script>

</body>
</html>