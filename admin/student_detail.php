<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_role('admin');

$student_id = (int)($_GET['id'] ?? 0);
if ($student_id <= 0) exit('Invalid student ID');

/* ================= STUDENT ================= */
$stmt = $conn->prepare("
  SELECT id, name, email, suspended, blocked" . (db_column_exists($conn, 'users', 'hafiz') ? ", hafiz" : "") . "
  FROM users
  WHERE id=? AND role='student'
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) exit('Student not found');

$is_hafiz = db_column_exists($conn, 'users', 'hafiz') && (int)($student['hafiz'] ?? 0) === 1;

/* ========== ACTIVENESS ========== */
$active = $conn->query("
  SELECT COUNT(*) c
  FROM lessons
  WHERE student_id=$student_id
")->fetch_assoc()['c'];

/* ========== COMPLETED SURAHS ========== */
$completed = [];
$res = $conn->query("
  SELECT sl.surah_id
  FROM student_learning sl
  WHERE sl.student_id=$student_id
  AND sl.status='completed'
");
while ($r = $res->fetch_assoc()) {
    $completed[] = (int)$r['surah_id'];
}

/* ========== ALL SURAHS ========== */
$surahs = $conn->query("SELECT id, name_en FROM surahs ORDER BY id");
?>
<link rel="stylesheet" href="/assets/CSS/base.css">
<link rel="stylesheet" href="/assets/CSS/components.css">

<div style="padding:6px 0;">

    <?php if (isset($_GET['password_reset'])): ?>
        <div class="alert alert-success" style="margin-bottom:12px;"><?= ui_icon('check-circle', 16) ?> Password updated. Share the new password with the student privately.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger" style="margin-bottom:12px;"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <h2 style="margin:0 0 4px;"><?=htmlspecialchars($student['name'])?></h2>
    <p class="small text-muted" style="margin:0 0 12px;"><?=htmlspecialchars($student['email'])?></p>
    <?php if ($is_hafiz): ?>
        <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;">Hafiz</span>
    <?php endif; ?>
    <span class="badge badge-green">Activeness: <?=$active?> lesson requests</span>

    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

    <!-- Hafiz Designation -->
    <?php if (db_column_exists($conn, 'users', 'hafiz')): ?>
    <div class="form-group">
        <label class="form-label">Student Type Designation</label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($is_hafiz): ?>
                <form method="POST" action="set_hafiz.php" style="flex:1;min-width:150px;" onsubmit="return confirm('Remove Hafiz designation? This student will revert to the standard learner flow.');">
                    <input type="hidden" name="student_id" value="<?=$student_id?>">
                    <input type="hidden" name="action" value="unset">
                    <?= csrf_field() ?>
                    <button class="btn btn-block btn-danger" type="submit"><?= ui_icon('close', 15) ?> Remove Hafiz Designation</button>
                </form>
            <?php else: ?>
                <form method="POST" action="set_hafiz.php" style="flex:1;min-width:150px;" onsubmit="return confirm('Designate this student as Hafiz? They will be switched to the Qur&#8217;an revision flow.');">
                    <input type="hidden" name="student_id" value="<?=$student_id?>">
                    <input type="hidden" name="action" value="set">
                    <?= csrf_field() ?>
                    <button class="btn btn-block" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;" type="submit"><?= ui_icon('book', 15) ?> Designate as Hafiz</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="small text-muted" style="margin:6px 0 0;">Hafiz students revise from memory instead of learning new material.</p>
    </div>
    <?php endif; ?>

    <!-- Hafiz Revision Progress -->
    <?php if ($is_hafiz && db_table_exists($conn, 'hafiz_revision')): ?>
    <?php
    $rev = hafiz_get_active_revision($conn, $student_id);
    $completed_cycles = hafiz_completed_cycles_count($conn, $student_id);
    ?>
    <div class="form-group">
        <label class="form-label">Hafiz Revision Progress</label>
        <?php if ($rev): ?>
            <?php
            $juz_no = hafiz_current_week_no($rev);
            $pages = hafiz_pages_this_week($conn, (int)$rev['id']);
            $juz_p = hafiz_juz_progress($conn, (int)$rev['id'], $juz_no);
            $juz_test = hafiz_get_latest_test($conn, (int)$rev['id'], $juz_no);
            ?>
            <div class="panel" style="margin:0;">
                <p class="small" style="margin:0 0 8px;"><strong>Cycle #<?= (int)$rev['cycle_no'] ?></strong> · Juz <?= $juz_no ?> · Page <?= (int)$rev['current_page'] ?> / 604</p>
                <p class="small" style="margin:0 0 8px;">This week: <?= $pages ?> / 24 pages · Juz <?= $juz_no ?> accepted: <?= $juz_p['accepted'] ?>/<?= $juz_p['total'] ?> pages</p>
                <?php if ($juz_test): ?>
                    <p class="small" style="margin:0 0 8px;">Juz <?= $juz_no ?> test:
                        <?php if ($juz_test['status'] === 'passed'): ?>
                            <span style="color:var(--emerald-700);">Passed</span>
                        <?php elseif ($juz_test['status'] === 'submitted'): ?>
                            <span style="color:var(--gold-deep);">Awaiting review</span>
                        <?php elseif ($juz_test['status'] === 'failed'): ?>
                            <span style="color:var(--danger);">Failed — retake required</span>
                        <?php else: ?>
                            <?= ucfirst($juz_test['status']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <p class="small" style="margin:0;"><strong>Completed cycles:</strong> <?= $completed_cycles ?></p>
            </div>
        <?php else: ?>
            <p class="small text-muted" style="margin:0;">No active revision cycle. The student needs to start one from their dashboard.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

    <!-- Edit Name (only admin can change a student's name) -->
    <div class="form-group">
        <label class="form-label">Edit Student Name</label>
        <form method="POST" action="update_student_name.php" style="display:flex;gap:8px;align-items:center;">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?=$student_id?>">
            <input class="form-input" type="text" name="name" value="<?=htmlspecialchars($student['name'])?>" required style="flex:1;min-width:0;">
            <button class="btn btn-sm" type="submit"><?= ui_icon('check', 15) ?> Save Name</button>
        </form>
        <p class="small text-muted" style="margin:6px 0 0;">Only request/change this when the student asks.</p>
    </div>

    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

    <!-- Reset Password -->
    <div class="form-group">
        <label class="form-label">Reset Password</label>
        <form method="POST" action="reset_password.php" style="display:flex;flex-direction:column;gap:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?=$student_id?>">
            <input class="form-input" type="text" name="password" placeholder="New password (at least 6 characters)" required minlength="6" autocomplete="off">
            <button class="btn btn-block" type="submit"><?= ui_icon('lock', 15) ?> Set New Password</button>
        </form>
        <p class="small text-muted" style="margin:6px 0 0;">Use this if the student cannot log in. Share the new password privately — old logins stop working immediately.</p>
    </div>

    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

    <!-- Completed Surahs -->
    <div class="form-group">
        <label class="form-label">Completed Surahs</label>
        <div class="panel" style="max-height:180px;overflow-y:auto;margin:0;">
            <form method="POST" action="update_completed_surahs.php">
                <input type="hidden" name="student_id" value="<?=$student_id?>">
                <?= csrf_field() ?>
                <?php while($s = $surahs->fetch_assoc()): ?>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:7px;font-size:.9rem;cursor:pointer;">
                        <input type="checkbox"
                               name="surahs[]"
                               value="<?=$s['id']?>"
                               style="accent-color:var(--emerald-600);"
                               <?=in_array($s['id'],$completed)?'checked':''?>>
                        <?=htmlspecialchars($s['name_en'])?>
                    </label>
                <?php endwhile; ?>
                <button class="btn btn-sm btn-block" style="margin-top:10px;">Save Completed Surahs</button>
            </form>
        </div>
    </div>

    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

    <!-- Access Control -->
    <div class="form-group" style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="POST" action="suspend_student.php" onsubmit="return confirm('Toggle suspend for this student?');" style="flex:1;min-width:150px;">
            <input type="hidden" name="id" value="<?=$student_id?>">
            <?= csrf_field() ?>
            <button class="btn btn-block <?= $student['suspended'] ? '' : 'btn-danger' ?>" style="<?= $student['suspended'] ? 'background:#7a5a15;' : '' ?>">
                <?= $student['suspended'] ? 'Unsuspend Student' : 'Suspend Student' ?>
            </button>
        </form>

        <form method="POST" action="block_student.php" onsubmit="return confirm('Toggle block for this student?');" style="flex:1;min-width:150px;">
            <input type="hidden" name="id" value="<?=$student_id?>">
            <?= csrf_field() ?>
            <button class="btn btn-block <?= $student['blocked'] ? '' : 'btn-danger' ?>" style="<?= $student['blocked'] ? 'background:#7c5a15;' : '' ?>">
                <?= $student['blocked'] ? 'Unblock Student' : 'Block (Fees)' ?>
            </button>
        </form>
    </div>

    <form method="POST" action="delete_student.php"
          onsubmit="return confirm('Warning: this will permanently delete this student and ALL their data. Continue?');" style="margin-top:14px;">
        <input type="hidden" name="id" value="<?=$student_id?>">
        <?= csrf_field() ?>
        <button class="btn btn-danger btn-block"><?= ui_icon('trash', 16) ?> Delete Student (Permanent)</button>
    </form>

    <a class="btn btn-gold btn-block" style="margin-top:14px;" href="/student/certificate.php?id=<?=$student_id?>" target="_blank">
        <?= ui_icon('gem', 16) ?> View Student Certificate
    </a>

    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0;">

    <!-- Survey / Login as this Student -->
    <div class="form-group">
        <label class="form-label">Survey — Login as this Student</label>
        <form method="POST" action="/admin/impersonate.php">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?=$student_id?>">
            <input class="form-input" type="password" name="survey_key" placeholder="Admin survey key" required autocomplete="off" style="margin-bottom:8px;">
            <button class="btn btn-gold btn-block" type="submit"><?= ui_icon('eye', 16) ?> Login as this Student</button>
        </form>
        <p class="small text-muted" style="margin:6px 0 0;">Opens this student&rsquo;s account in survey mode so you can experience their journey. Requires the admin survey key.</p>
    </div>
</div>