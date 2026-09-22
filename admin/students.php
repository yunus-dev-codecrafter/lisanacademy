<?php
require '../config/security/helpers.php';
require_role('admin');
include '../auth/auth_check.php';
include '../config/db.php';

/* Fetch all students */
$hafiz_col = db_column_exists($conn, 'users', 'hafiz');
$mem_col = db_column_exists($conn, 'users', 'memorizing');
$students = $conn->query("
    SELECT id, name, email, blocked, suspended" . ($hafiz_col ? ", hafiz" : "") . ($mem_col ? ", memorizing" : "") . "
    FROM users
    WHERE role='student'
    ORDER BY name ASC
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Manage Students</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'students', 'Students', 'Management'); ?>
<?= csrf_field() ?>

<div class="page-hero animate-rise">
    <h1>Students Management</h1>
    <p>View, message and manage access for every student.</p>
</div>

<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <a class="btn btn-gold" href="add_student.php"><?= ui_icon('user', 16) ?> Add New Student</a>
    <?php if ($hafiz_col): ?>
    <button class="btn btn-ghost filter-type" data-filter="all" onclick="filterByType('all')">All</button>
    <button class="btn btn-ghost filter-type" data-filter="hafiz" onclick="filterByType('hafiz')"><?= ui_icon('book', 14) ?> Hafiz</button>
    <?php if ($mem_col): ?>
    <button class="btn btn-ghost filter-type" data-filter="memorizer" onclick="filterByType('memorizer')"><?= ui_icon('star', 14) ?> Memorizer</button>
    <?php endif; ?>
    <button class="btn btn-ghost filter-type" data-filter="non-hafiz" onclick="filterByType('non-hafiz')">Non-Hafiz</button>
    <?php endif; ?>
    <div style="flex:1;min-width:220px;position:relative;">
        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);display:flex;color:var(--text-muted);pointer-events:none;"><?= ui_icon('search', 16) ?></span>
        <input class="form-input" type="search" id="studentSearch" placeholder="Search students by name or email…" autocomplete="off" style="padding-left:38px;">
    </div>
</div>

<div class="alert alert-info" id="noSearchResults" style="display:none;">
    <?= ui_icon('info', 16) ?> No students match your search. Try a different name or email.
</div>

<div class="grid-3" id="studentsGrid">
<?php while($s = $students->fetch_assoc()): ?>
<div class="card card-hover animate-rise student-card"
     data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>"
     data-email="<?= htmlspecialchars(strtolower($s['email'])) ?>"
     data-hafiz="<?= $hafiz_col ? (int)($s['hafiz'] ?? 0) : 0 ?>"
     data-memorizing="<?= $mem_col ? (int)($s['memorizing'] ?? 0) : 0 ?>">
    <h3 style="margin:0 0 4px;"><?=htmlspecialchars($s['name'])?></h3>
    <p class="small text-muted" style="margin:0 0 12px;word-break:break-word;"><?=htmlspecialchars($s['email'])?></p>

    <p class="small" style="margin:0 0 14px;">
        <?php if ($hafiz_col && (int)($s['hafiz'] ?? 0) === 1): ?>
            <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;">Hafiz</span>
        <?php endif; ?>
        <?php if ($mem_col && (int)($s['memorizing'] ?? 0) === 1): ?>
            <span class="badge" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;">Memorizer</span>
        <?php endif; ?>
        <?php if ($s['blocked']): ?>
            <span class="badge badge-red">Blocked</span>
        <?php endif; ?>
        <?php if ($s['suspended']): ?>
            <span class="badge badge-red">Suspended</span>
        <?php endif; ?>
        <?php if (!$s['blocked'] && !$s['suspended']): ?>
            <span class="badge badge-green">Active</span>
        <?php endif; ?>
    </p>

    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <button class="btn btn-sm btn-outline" onclick="openModal(<?= $s['id']?>)">View</button>
        <button class="btn btn-sm <?= $s['blocked'] ? 'btn-ghost' : 'btn-danger' ?>" onclick="blockStudent(<?= $s['id']?>, <?= $s['blocked'] ? 'true' : 'false' ?>)"><?= $s['blocked'] ? 'Unblock' : 'Block' ?></button>
        <button class="btn btn-sm <?= $s['suspended'] ? 'btn-ghost' : 'btn-danger' ?>" onclick="suspendStudent(<?= $s['id']?>, <?= $s['suspended'] ? 'true' : 'false' ?>)"><?= $s['suspended'] ? 'Unsuspend' : 'Suspend' ?></button>
        <button class="btn btn-sm btn-ghost" onclick="window.location.href='message_student.php?id=<?= (int)$s['id'] ?>'">Message</button>
        <button class="btn btn-sm btn-gold" onclick="loginAs(<?= (int)$s['id'] ?>)">Login as</button>
    </div>
</div>
<?php endwhile; ?>
</div>

<!-- Modal for Student Details -->
<div class="modal" id="studentModal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal()">×</span>
        <div id="modalBody"></div>
    </div>
</div>

<?php ui_page_end(); ?>

<script>
function openModal(id){
    fetch('student_detail.php?id='+id)
    .then(r=>r.text())
    .then(html=>{
        document.getElementById('modalBody').innerHTML = html;
        document.getElementById('studentModal').classList.add('open');
    });
}

function closeModal(){
    document.getElementById('studentModal').classList.remove('open');
}

/* Submit every form inside the modal via AJAX so the page never
   navigates away to the bare student_detail.php fragment. The modal
   is re-fetched afterwards so it stays open with fresh data. */
var modalBody = document.getElementById('modalBody');
if (modalBody) {
    modalBody.addEventListener('submit', function(e){
        var form = e.target && e.target.tagName === 'FORM' ? e.target : (e.target.closest ? e.target.closest('form') : null);
        if (!form) return;
        if (e.defaultPrevented) return; /* confirm dialog was cancelled */
        e.preventDefault();

        var isDelete   = (form.getAttribute('action') || '').indexOf('delete_student.php') !== -1;
        /* Impersonation must navigate the whole window, not be AJAX-submitted
           into the modal — so let these forms submit normally. */
        if ((form.getAttribute('action') || '').indexOf('impersonate.php') !== -1) return;
        var idField    = form.querySelector('[name=student_id]') || form.querySelector('[name=id]');
        var studentId  = idField ? idField.value : '';
        var btn        = form.querySelector('button[type=submit]');
        var origText   = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Working…'; }

        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ return r.text(); })
        .then(function(){
            if (isDelete) { closeModal(); location.reload(); return; }
            if (!studentId) { if (btn) { btn.disabled = false; btn.textContent = origText; } return; }
            return fetch('student_detail.php?id=' + encodeURIComponent(studentId))
                .then(function(r){ return r.text(); })
                .then(function(html){ modalBody.innerHTML = html; });
        })
        .catch(function(){
            if (btn) { btn.disabled = false; btn.textContent = origText; }
            alert('Action failed. Please try again.');
        });
    });
}

document.addEventListener('click', function(e){
    var modal = document.getElementById('studentModal');
    if (modal && modal.classList.contains('open') && e.target === modal) closeModal();
});

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeModal();
});

function blockStudent(id, currentlyBlocked){
    var csrfInput = document.querySelector('[name=csrf_token]');
    var csrfToken = csrfInput ? csrfInput.value : '';
    if(confirm(currentlyBlocked ? 'Unblock this student?' : 'Block this student? They will see payment/contact page on login.')){
        fetch('block_student.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({id:id,csrf_token:csrfToken})})
        .then(r=>r.text()).then(alert).then(()=>location.reload());
    }
}

function suspendStudent(id, currentlySuspended){
    var csrfInput = document.querySelector('[name=csrf_token]');
    var csrfToken = csrfInput ? csrfInput.value : '';
    if(confirm(currentlySuspended ? 'Unsuspend this student?' : 'Suspend this student? They will not be able to login.')){
        fetch('suspend_student.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({id:id,csrf_token:csrfToken})})
        .then(r=>r.text()).then(alert).then(()=>location.reload());
    }
}

function messageStudent(studentId){
    if(!studentId) return alert('Invalid student ID');
    window.open('/admin/message_student.php?id=' + studentId, '_blank');
}

function loginAs(studentId){
    var key = prompt('Enter the admin survey key to login as this student:');
    if (key === null || key.trim() === '') return;
    var csrfInput = document.querySelector('[name=csrf_token]');
    var token = csrfInput ? csrfInput.value : '';
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/impersonate.php';
    function addHidden(name, value){
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = name; i.value = value;
        form.appendChild(i);
    }
    addHidden('student_id', studentId);
    addHidden('survey_key', key);
    addHidden('csrf_token', token);
    document.body.appendChild(form);
    form.submit();
}

/* =========================
   SEARCH / FILTER STUDENTS
========================= */
var searchInput = document.getElementById('studentSearch');
var studentsGrid = document.getElementById('studentsGrid');
var activeTypeFilter = 'all';

function filterStudents() {
    var q = (searchInput ? searchInput.value : '').toLowerCase().trim();
    var cards = studentsGrid ? studentsGrid.querySelectorAll('.student-card') : [];
    var visible = 0;
    cards.forEach(function (card) {
        var name = (card.getAttribute('data-name') || '').toLowerCase();
        var email = (card.getAttribute('data-email') || '').toLowerCase();
        var isHafiz = card.getAttribute('data-hafiz') === '1';
        var isMemorizer = (card.getAttribute('data-memorizing') || '0') === '1';
        var matchSearch = q === '' || name.indexOf(q) !== -1 || email.indexOf(q) !== -1;
        var matchType = activeTypeFilter === 'all'
            || (activeTypeFilter === 'hafiz' && isHafiz)
            || (activeTypeFilter === 'memorizer' && isMemorizer)
            || (activeTypeFilter === 'non-hafiz' && !isHafiz && !isMemorizer);
        card.style.display = (matchSearch && matchType) ? '' : 'none';
        if (matchSearch && matchType) visible++;
    });
    var empty = document.getElementById('noSearchResults');
    if (empty) empty.style.display = (q !== '' && visible === 0) ? 'flex' : 'none';
}

function filterByType(type) {
    activeTypeFilter = type;
    document.querySelectorAll('.filter-type').forEach(function(btn) {
        btn.classList.toggle('btn-gold', btn.getAttribute('data-filter') === type);
    });
    filterStudents();
}

if (searchInput) {
    searchInput.addEventListener('input', filterStudents);
    searchInput.addEventListener('search', filterStudents);
}
/* Set 'All' as active on load */
filterByType('all');
</script>

</body>
</html>