<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add Student</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'students', 'Add Student', 'Students'); ?>

<div style="max-width:520px;" class="animate-rise">
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger"><?=htmlspecialchars($_GET['error'])?></div>
    <?php endif; ?>

    <div class="card" style="padding:26px;">
        <h2 style="margin-top:0;">Add New Student</h2>

        <form method="POST" action="save_student.php">
            <div class="form-group">
                <label class="form-label" for="name">Full Name</label>
                <input class="form-input" type="text" id="name" name="name" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input class="form-input" type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="phone">Phone Number (optional)</label>
                <input class="form-input" type="tel" id="phone" name="phone" maxlength="30" placeholder="Used to link a friend's invite">
                <span class="small text-muted">If this student was invited by a friend, enter the phone number the friend submitted — it links the invite.</span>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input class="form-input" type="password" id="password" name="password" required>
            </div>

            <?php if (db_column_exists($conn, 'users', 'hafiz')): ?>
            <div class="form-group">
                <label class="form-label" for="hafiz">Student Type</label>
                <select class="form-select" id="hafiz" name="hafiz">
                    <option value="0" selected>Non-Hafiz (Learner)</option>
                    <option value="1">Hafiz (Revision Mode)</option>
                </select>
                <span class="small text-muted">Hafiz students revise from memory instead of learning new material.</span>
            </div>
            <?php endif; ?>

            <button class="btn btn-block" style="margin-top:6px;">Add Student</button>
        </form>
    </div>
</div>

<?php ui_page_end(); ?>

</body>
</html>