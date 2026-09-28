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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta charset="UTF-8">
<meta name="theme-color" content="#064e3b">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png">
<title>Access Restricted</title>
<link rel="stylesheet" href="/assets/CSS/base.css">
<link rel="stylesheet" href="/assets/CSS/components.css">
<link rel="stylesheet" href="/assets/CSS/layout.css">
<link rel="stylesheet" href="/assets/CSS/icons.css">
</head>
<body>

<div class="center-screen">
    <div class="card card-gold animate-rise" style="max-width:440px;text-align:center;padding:34px;">
        <div style="font-size:2.6rem;"><?= ui_icon('clock', 38) ?></div>
        <h2>Access Temporarily Restricted</h2>

        <p>
            Your access to learning has been paused for the current term
            due to pending school fees.
        </p>

        <p class="small text-muted">
            Please contact the academy to complete your payment
            and regain access to your learning plan.
        </p>

        <a class="btn btn-block"
           href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>?text=Assalamualaikum,%20I%20want%20to%20pay%20my%20school%20fees%20for%20this%20term."
           target="_blank">
           <?= ui_icon('phone', 16) ?> Contact on WhatsApp
        </a>
    </div>
</div>

</body>
</html>