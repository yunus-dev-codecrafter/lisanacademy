<?php
/* =========================================================
   verify.php — Public certificate verification (no login).
   Scanned from the QR code printed on official certificates.
   ========================================================= */
require_once __DIR__ . '/config/security/session.php';
require_once __DIR__ . '/config/security/helpers.php';
require_once __DIR__ . '/config/db.php';

$no    = isset($_GET['no']) ? trim((string)$_GET['no']) : '';
$found = false;
$cert  = null;

if ($no !== '') {
    try {
        $stmt = $conn->prepare("
            SELECT c.certificate_no, c.issued_at, u.name
            FROM certificates c
            JOIN users u ON u.id = c.student_id
            WHERE c.certificate_no = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $no);
        $stmt->execute();
        $cert  = $stmt->get_result()->fetch_assoc();
        $found = (bool)$cert;
    } catch (Throwable $e) {
        $cert = null;
        $found = false;
    }
}

$issue_display = ($found && !empty($cert['issued_at']))
    ? date('j F Y', strtotime($cert['issued_at']))
    : '—';

$status  = $no === '' ? 'empty' : ($found ? 'valid' : 'invalid');
$title   = $status === 'valid'   ? 'Certificate Verified'
         : ($status === 'invalid' ? 'Certificate Not Found'
         : 'Certificate Verification');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#064e3b">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png">
<title><?= htmlspecialchars($title) ?> — Lisanun Mubeen Academy</title>
<style>
  :root{
    --emerald-950:#022c22;--emerald-900:#063B2E;--emerald-800:#065f46;
    --gold:#C9A13B;--gold-light:#E8CE83;--gold-deep:#8F6F1F;
    --ivory:#F8F5EC;--text:#202522;--muted:#6b7280;
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Segoe UI',system-ui,-apple-system,sans-serif;color:var(--text);
    background:radial-gradient(60mm 26mm at 0 0,rgba(201,162,75,.12),transparent 70%),
               radial-gradient(50mm 26mm at 100% 100%,rgba(6,59,46,.08),transparent 70%),
               var(--ivory);
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
  }
  .card{
    background:#fff;border:1.2mm solid var(--gold);
    border-radius:16px;max-width:440px;width:100%;padding:34px 30px;text-align:center;
    box-shadow:0 18px 40px rgba(15,23,42,.14);
  }
  .seal{width:74px;height:74px;margin:0 auto 18px;border-radius:50%;
    background:linear-gradient(135deg,#0B4A3A,#052C23);color:var(--gold-light);
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 0 0 3px var(--gold), 0 0 0 6px rgba(201,162,75,.35);
  }
  .seal svg{width:38px;height:38px}
  h1{font-size:1.35rem;color:var(--emerald-900);letter-spacing:.02em}
  .academy{margin-top:6px;font-size:.78rem;letter-spacing:.22em;text-transform:uppercase;color:var(--gold-deep);font-weight:700}
  .msg{margin-top:14px;font-size:.95rem;color:var(--muted);line-height:1.6}
  .details{
    margin-top:20px;background:var(--ivory);border:1px solid rgba(201,162,75,.4);
    border-radius:12px;padding:18px 16px;
  }
  .details .lbl{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:700}
  .details .name{font-size:1.3rem;font-weight:800;color:var(--emerald-900);margin-top:4px;word-break:break-word}
  .details .row{display:flex;justify-content:space-between;gap:12px;font-size:.85rem;margin-top:10px;border-top:1px dashed rgba(201,162,75,.5);padding-top:10px;color:var(--text)}
  .details .row span{color:var(--muted)}
  .badge{
    display:inline-flex;align-items:center;gap:8px;margin-top:16px;padding:8px 18px;border-radius:999px;
    font-weight:800;font-size:.85rem;letter-spacing:.05em;
  }
  .badge.valid{background:#ECFDF5;color:#047857;border:1.5px solid #10B981}
  .badge.invalid{background:#FEF2F2;color:#B91C1C;border:1.5px solid #F87171}
  .back{display:inline-block;margin-top:20px;color:var(--gold-deep);font-weight:700;font-size:.85rem;text-decoration:none}
  .back:hover{text-decoration:underline}
</style>
</head>
<body>
  <div class="card">
    <div class="seal">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <?php if ($status === 'valid'): ?>
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        <?php else: ?>
          <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        <?php endif; ?>
      </svg>
    </div>

    <div class="academy">Lisanun Mubeen Academy</div>
    <h1><?= htmlspecialchars($title) ?></h1>

    <?php if ($status === 'valid'): ?>
      <p class="msg">This is to certify that the holder has been verified as a graduate of the Glorious Qur'an.</p>
      <div class="details">
        <div class="lbl">Issued to</div>
        <div class="name"><?= htmlspecialchars($cert['name']) ?></div>
        <div class="row"><span>Certificate No.</span><strong><?= htmlspecialchars($cert['certificate_no']) ?></strong></div>
        <div class="row"><span>Date Issued</span><strong><?= htmlspecialchars($issue_display) ?></strong></div>
      </div>
      <div class="badge valid"><?= htmlspecialchars($cert['certificate_no']) ?> · Verified</div>
    <?php elseif ($status === 'invalid'): ?>
      <p class="msg">No official certificate matches the number supplied. Please double-check the certificate number and try again.</p>
      <div class="badge invalid">Not Found</div>
    <?php else: ?>
      <p class="msg">Scan a certificate QR code or enter its certificate number to verify its authenticity.</p>
    <?php endif; ?>

    <a class="back" href="/">&larr; Return to Lisanun Mubeen Academy</a>
  </div>
</body>
</html>