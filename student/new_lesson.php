<?php
require '../config/security/helpers.php';
require '../auth/auth_check.php';
require '../config/db.php';
require_role('student');

$student_id = (int)$_SESSION['user_id'];

if (student_is_hafiz($conn, $student_id)) {
    header("Location: hafiz_revision.php");
    exit;
}

$exam_mode = student_in_exam($conn, $student_id);
$locked    = student_exam_locked($conn, $student_id);

$device_row = $conn->query("SELECT device_type FROM users WHERE id = $student_id")->fetch_assoc();
$device_type = $device_row['device_type'] ?? 'android';

$audio_q = $conn->query("
    SELECT 
        aa.id AS audio_id,
        aa.audio_file,
        aa.acknowledged,
        aa.sent_at,
        l.id AS lesson_id,
        l.from_verse,
        l.to_verse,
        s.name_en AS surah_name
    FROM admin_audio aa
    JOIN lessons l ON l.id = aa.learning_plan_id
    JOIN surahs s ON s.id = l.surah_id
    WHERE aa.student_id = $student_id
    ORDER BY aa.sent_at DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<title>My Lessons</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'learning', 'My Lessons', 'New Lesson'); ?>
<?= csrf_field() ?>

<div class="page-hero animate-rise">
    <h1>Your Lessons</h1>
    <p>Listen, practice and record your recitation for review.</p>
</div>

<?php if ($locked): ?>
<div class="alert alert-danger animate-rise">
    <?= ui_icon('lock', 18) ?>
    <span style="flex:1;"><strong>You have an outstanding exam from the last term.</strong> Recording is locked until your exam is accepted (and any ₦500 fee is paid). You can still listen to your lessons.</span>
    <a class="btn btn-gold btn-sm" href="exam.php"><?= ui_icon('notes', 15) ?> Pay / View Exam</a>
</div>
<?php endif; ?>

<?php if($audio_q->num_rows === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('headphones', 40) ?></div>
        <div class="empty-title">No lessons yet</div>
        <p class="small" style="margin:0;">When your teacher sends a lesson it will appear here.</p>
    </div>
<?php endif; ?>

<?php while($a = $audio_q->fetch_assoc()): ?>
<div class="card animate-rise d1">
    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3 style="margin:0;"><?= htmlspecialchars($a['surah_name']) ?></h3>
        <span class="badge <?= $a['acknowledged'] ? 'badge-green' : 'badge-gold' ?>"><?= $a['acknowledged'] ? 'Completed' : 'New' ?></span>
    </div>
    <p class="small text-muted" style="margin-top:2px;">Verses <?= (int)$a['from_verse'] ?> – <?= (int)$a['to_verse'] ?></p>

    <div id="admin_audio_<?= $a['lesson_id'] ?>">
        <audio controls src="../uploads/admin_audio/<?= htmlspecialchars($a['audio_file']) ?>"></audio>
        <?php if(!$a['acknowledged']): ?>
            <button class="btn btn-gold btn-sm" onclick="markCompleted(<?= $a['audio_id'] ?>, <?= $a['lesson_id'] ?>)"><?= ui_icon('check', 14) ?> Mark as completed</button>
        <?php endif; ?>
    </div>

    <div id="recorder_<?= $a['lesson_id'] ?>" class="<?= $a['acknowledged'] ? '' : 'hidden' ?>" style="margin-top:12px;">
        <?php if ($exam_mode): ?>
            <div class="alert alert-warning" style="margin:0;"><?= ui_icon('alert', 16) ?> Recitation submission is paused while exam mode is active.</div>
        <?php elseif ($locked): ?>
            <div class="alert alert-danger" style="margin:0;"><?= ui_icon('lock', 16) ?> Recording is locked until you pay the ₦500 fee and your exam is accepted.</div>
        <?php else: ?>
        <div class="panel">
            <?php if ($device_type === 'iphone'): ?>
                <strong>Upload your recitation:</strong>
                <form method="post" enctype="multipart/form-data" action="submit_recitation.php" style="margin-top:10px;">
                    <input type="file" name="audio" accept="audio/*" required>
                    <input type="hidden" name="learning_plan_id" value="<?= (int)$a['lesson_id'] ?>">
                    <button type="submit" class="btn btn-sm" style="margin-top:10px;"><?= ui_icon('upload', 15) ?> Upload Recitation</button>
                </form>
            <?php else: ?>
                <strong>Record your recitation:</strong>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
                    <button class="btn btn-sm" onclick="startRecording(<?= $a['lesson_id'] ?>)"><?= ui_icon('mic', 15) ?> Start Recording</button>
                    <button class="btn btn-sm btn-ghost" onclick="stopRecording(<?= $a['lesson_id'] ?>)"><?= ui_icon('stop', 15) ?> Stop</button>
                </div>
                <audio id="preview_<?= $a['lesson_id'] ?>" controls class="hidden" style="margin-top:12px;"></audio>
                <button class="btn btn-gold btn-sm" style="margin-top:12px;" onclick="sendRecitation(<?= $a['lesson_id'] ?>)"><?= ui_icon('send', 15) ?> Send Recitation</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endwhile; ?>

<?php ui_page_end(); ?>

<script>
let recorders={},chunks={},blobs={},streams={};

const MIME = (window.MediaRecorder && (
    MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' :
    MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : ''
)) || '';
const REC_EXT = MIME === 'audio/mp4' ? 'm4a' : 'webm';

function markCompleted(audioId,lessonId){
    const fd=new FormData(); const csrfInput=document.querySelector('[name=csrf_token]');
    fd.append('audio_id',audioId);
    if(csrfInput) fd.append('csrf_token',csrfInput.value);
    fetch('acknowledge_admin_audio.php',{method:'POST',body:fd})
    .then(r=>r.text())
    .then(res=>{
        if(res.trim()==='OK'){
            document.getElementById('admin_audio_'+lessonId).style.display='none';
            document.getElementById('recorder_'+lessonId).classList.remove('hidden');
        }else alert(res);
    });
}

function startRecording(id){
    chunks[id]=[];
    navigator.mediaDevices.getUserMedia({audio:true})
    .then(s=>{
        streams[id]=s;
        recorders[id]=new MediaRecorder(s, MIME ? {mimeType:MIME} : undefined);
        recorders[id].ondataavailable=e=>chunks[id].push(e.data);
        recorders[id].start(); alert('Recording started');
    }).catch(()=>alert('Mic access denied. You can upload an audio file instead.'));
}

function stopRecording(id){
    if(!recorders[id])return alert('Not recording');
    recorders[id].onstop=()=>{
        const b=new Blob(chunks[id],{type: MIME || 'audio/webm'});
        blobs[id]=b;
        const a=document.getElementById('preview_'+id);
        a.src=URL.createObjectURL(b);
        a.classList.remove('hidden');
        if(streams[id]) streams[id].getTracks().forEach(t=>t.stop());
    };
    recorders[id].stop();
}

function sendRecitation(id){
    if(!blobs[id])return alert('No recording found.');
    const fd=new FormData();
    fd.append('audio',blobs[id],'recitation_'+id+'.'+REC_EXT);
    fd.append('learning_plan_id',id);
    fetch('submit_recitation.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.text())
    .then(res=>{
        if(res.trim()==='OK'){ location.reload(); }
        else alert(res);
    })
    .catch(()=>alert('Submission failed. Please check your connection and try again.'));
}
</script>
</body>
</html>