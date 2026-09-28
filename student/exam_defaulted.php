<?php
require_once __DIR__ . '/../config/security/session.php';
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /auth/login.php");
    exit;
}

$whatsapp = setting($conn, 'whatsapp_number', '2348029979040');
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<meta name="theme-color" content="#064e3b">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png">
<title>Exam Required</title>
<link rel="stylesheet" href="/assets/CSS/base.css">
<link rel="stylesheet" href="/assets/CSS/components.css">
<link rel="stylesheet" href="/assets/CSS/layout.css">
<link rel="stylesheet" href="/assets/CSS/icons.css">
</head>
<body>

<div class="center-screen">
    <div class="card animate-rise" style="max-width:460px;text-align:center;padding:34px;border-top:5px solid var(--danger);">
        <div style="font-size:2.6rem;"><?= ui_icon('lock', 38) ?></div>
        <h2 style="color:var(--danger);">Exam Outstanding — Normal Lessons Paused</h2>

        <p>
            Your normal lessons are paused because you have an outstanding examination
            from the last term. You can continue once your exam is <strong>accepted</strong>
            (3 mistakes or fewer).
        </p>

        <p style="text-align:left;margin:0 0 10px;">
            <strong>If you did not participate</strong> in the exam term: pay the sum of
            <strong>₦500</strong> to the academy. Once the admin confirms your payment, the exam
            opens for <strong>you only</strong> while other students continue their normal lessons.
        </p>

        <p style="text-align:left;margin:0;">
            <strong>If you already submitted but were rejected:</strong> no fee is needed —
            simply open the Exam page below and retake it.
        </p>

        <p class="small" style="text-align:left;margin:12px 0 16px;">
            <strong>Acceptance criteria:</strong> maximum of <strong>3 mistakes</strong> allowed in your
            entire recitation. If more than 3 mistakes are found, you must retake the exam.
        </p>

        <a class="btn btn-block btn-gold"
           href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>?text=<?= rawurlencode('Assalamualaikum, I have an outstanding examination. I want to pay the N500 exam fee (if I missed the term) to reopen my exam.') ?>"
           target="_blank">
           <?= ui_icon('phone', 16) ?> Pay ₦500 via WhatsApp
        </a>

        <a class="btn btn-block" style="margin-top:10px;" href="exam.php"><?= ui_icon('notes', 16) ?> View Exam Page</a>

        <a class="small text-muted" style="display:block;margin-top:14px;" href="/auth/logout.php">Logout</a>
    </div>
</div>

</body>
</html>