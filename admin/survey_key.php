<?php
require '../config/security/helpers.php';
require_role('admin');
include '../auth/auth_check.php';
include '../config/db.php';
require_once '../config/security/impersonate.php';

$key_set = survey_key_hash($conn) !== '';
$error   = '';
$saved   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $new     = $_POST['new_key'] ?? '';
    $confirm = $_POST['confirm_key'] ?? '';

    if ($new === '' || strlen($new) < 6) {
        $error = 'The survey key must be at least 6 characters long.';
    } elseif ($new !== $confirm) {
        $error = 'The two entries do not match.';
    } else {
        set_survey_key_hash($conn, password_hash($new, PASSWORD_BCRYPT));
        $saved = true;
        $key_set = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Survey Key</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Survey Key', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Admin Survey Key</h1>
    <p>This password gates the &ldquo;Login as Student&rdquo; feature. The admin must enter it
       before surveying any student account. It is stored hashed and is never shown or logged.</p>
</div>

<div class="card animate-rise d1" style="max-width:560px;">
    <?php if ($key_set): ?>
        <div class="alert alert-info"><?= ui_icon('check-circle', 16) ?> A survey key is currently set.</div>
    <?php else: ?>
        <div class="alert alert-warning"><?= ui_icon('alert', 16) ?> No survey key is set — impersonation is currently disabled.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($saved): ?>
        <div class="alert alert-success"><?= ui_icon('check-circle', 16) ?> Survey key saved. You can now use &ldquo;Login as Student&rdquo;.</div>
    <?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <div class="form-group">
            <label class="form-label" for="new_key">New Survey Key</label>
            <input class="form-input" type="password" id="new_key" name="new_key" required autocomplete="new-password" placeholder="At least 6 characters">
        </div>
        <div class="form-group">
            <label class="form-label" for="confirm_key">Confirm Survey Key</label>
            <input class="form-input" type="password" id="confirm_key" name="confirm_key" required autocomplete="new-password" placeholder="Re-enter the key">
        </div>
        <button class="btn btn-gold btn-block btn-lg" type="submit"><?= ui_icon('lock', 16) ?> Save Survey Key</button>
    </form>
</div>

<?php ui_page_end(); ?>

</body>
</html>
