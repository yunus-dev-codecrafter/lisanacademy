<?php
require '../config/security/helpers.php';
require '../auth/auth_check.php';
require '../config/db.php';

$is_admin = ($_SESSION['role'] ?? '') === 'admin';

if ($is_admin) {
    $student_id = (int)($_GET['id'] ?? 0);
    if ($student_id <= 0) redirect('/admin/students.php');
} else {
    require_role('student');
    $student_id = (int)$_SESSION['user_id'];
}

/* ================= STUDENT BIO ================= */
$stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) redirect('/auth/login.php');

/* ================= COMPLETED SURAHS ================= */
$completed = [];
$done = 0;
$total = 0;
try {
    $res = $conn->query("SELECT COUNT(*) c FROM surahs");
    $total = (int)$res->fetch_assoc()['c'];

    $stmt = $conn->prepare("
        SELECT s.id, s.name_en, s.name_ar, s.total_verses
        FROM student_learning sl
        JOIN surahs s ON s.id = sl.surah_id
        WHERE sl.student_id = ? AND sl.status = 'completed'
        ORDER BY s.id ASC
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $completed[] = $row;
    }
    $done = count($completed);
} catch (Throwable $e) {
    $done = 0;
}

/* Record the moment of 100% completion (starts the 7-day auto-delete clock). */
mark_graduated_if_due($conn, $student_id);

$percent = $total ? round(($done / $total) * 100) : 0;

/* ================= EXAM PERFORMANCE (latest approved attempt) ================= */
$exam_perf = null;
$cert_term_label = '';
try {
    $stmt = $conn->prepare("
        SELECT overall_rating, overall_score, term_id
        FROM exam_attempts
        WHERE student_id = ? AND status = 'approved'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if ($r) {
        $exam_perf = [
            'rating' => (string)($r['overall_rating'] ?? ''),
            'score'  => (int)($r['overall_score'] ?? 0),
        ];
        if (!empty($r['term_id'])) {
            $cert_term_label = exam_term_label((int)$r['term_id'], $conn);
        }
    }
} catch (Throwable $e) {
    $exam_perf = null;
}

/* ================= CERTIFICATE NUMBER ================= */
$cert_no = '';
$issued_at = date('F d, Y');
try {
    $stmt = $conn->prepare("SELECT certificate_no, issued_at FROM certificates WHERE student_id = ? LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $cert = $stmt->get_result()->fetch_assoc();

    if ($cert) {
        $cert_no = $cert['certificate_no'];
        $issued_at = (!empty($cert['issued_at']))
            ? date('F d, Y', strtotime($cert['issued_at']))
            : date('F d, Y');
    } else {
        $stmt = $conn->prepare("INSERT INTO certificates (student_id, certificate_no) VALUES (?, 'PENDING')");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $cert_id = $conn->insert_id;
        $cert_no = 'LMA-' . date('Y') . '-' . str_pad($cert_id, 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("UPDATE certificates SET certificate_no = ? WHERE id = ?");
        $stmt->bind_param("si", $cert_no, $cert_id);
        $stmt->execute();
    }
} catch (Throwable $e) {
    /* certificates table not created yet — use a stable transient number */
    $cert_no = 'LMA-' . date('Y') . '-' . str_pad($student_id, 4, '0', STR_PAD_LEFT);
}

$pageRole   = $is_admin ? 'admin' : 'student';
$pageActive = $is_admin ? 'students' : 'certificate';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Certificate — <?= htmlspecialchars($student['name']) ?></title>
<?= ui_css() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;0,800;0,900;1,500;1,600&display=swap" rel="stylesheet">
<style>
    /* =========================================================
       LISANUN MUBEEN ACADEMY — OFFICIAL CERTIFICATE
       Classic elegant serif · deep Islamic green + metallic gold
       · ivory ground · A4 portrait · single page
       ========================================================= */
    .cert-sheet{
        background:linear-gradient(135deg,#0d4a38 0%,#0b3d2e 50%,#083428 100%);
        max-width:210mm;
        margin:0 auto;
        padding:5mm;
        border-radius:3mm;
        box-shadow:0 24px 60px rgba(4,44,33,.35);
        position:relative;
    }
    .cert-border{
        position:relative;
        background:
            radial-gradient(80mm 40mm at 100% 0%, rgba(201,162,75,.08), transparent 60%),
            radial-gradient(70mm 40mm at 0% 100%, rgba(11,61,46,.06), transparent 60%),
            #faf8f1;
        border:0.6mm solid #c9a24b;
        outline:0.25mm solid #e3c98b;
        outline-offset:-2mm;
        border-radius:2mm;
        padding:12mm 14mm 9mm;
        min-height:281mm;
        display:flex;flex-direction:column;
        overflow:hidden;
    }
    /* inner hairline gold frame */
    .cert-border::before{
        content:"";position:absolute;inset:3.2mm;pointer-events:none;
        border:0.25mm solid rgba(201,162,75,.65);
        border-radius:1.5mm;
    }
    /* corner diamond ornaments */
    .cert-corner{
        position:absolute;width:4.5mm;height:4.5mm;z-index:2;pointer-events:none;
        transform:rotate(45deg);
        background:linear-gradient(135deg,#e3c98b,#a87e2f);
        box-shadow:0 0 0 0.4mm rgba(11,61,46,.9), 0 0 0 0.8mm #c9a24b;
    }
    .cert-corner.tl{top:5.4mm;left:5.4mm}
    .cert-corner.tr{top:5.4mm;right:5.4mm}
    .cert-corner.bl{bottom:5.4mm;left:5.4mm}
    .cert-corner.br{bottom:5.4mm;right:5.4mm}

    /* ---------- HEADER ---------- */
    .cert-header{text-align:center;position:relative;z-index:1;padding-bottom:4mm;}
    .cert-logo{
        width:21mm;height:21mm;margin:0 auto 2.6mm;border-radius:50%;
        padding:1.2mm;background:linear-gradient(135deg,#e3c98b,#c9a24b,#a87e2f);
        box-shadow:0 0 0 0.4mm #0b3d2e, 0 0 0 0.8mm #e3c98b;
    }
    .cert-logo img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;background:#fff;}
    .cert-header h1{
        font-family:'Playfair Display',serif;font-weight:800;font-size:6.2mm;line-height:1.15;
        letter-spacing:.22em;text-transform:uppercase;color:#063b2e;margin:0;
    }
    .cert-tagline{
        display:inline-flex;align-items:center;gap:3mm;margin-top:2mm;
        font-family:'Playfair Display',serif;font-style:italic;font-weight:600;
        font-size:3.4mm;letter-spacing:.18em;text-transform:uppercase;color:#a87e2f;
    }
    .cert-tagline::before,.cert-tagline::after{content:"";width:14mm;height:0.3mm;background:linear-gradient(90deg,#c9a24b,transparent);}
    .cert-tagline::before{background:linear-gradient(90deg,transparent,#c9a24b);}

    /* ---------- METADATA ---------- */
    .cert-meta{
        display:flex;justify-content:space-between;align-items:baseline;gap:4mm;flex-wrap:wrap;
        position:relative;z-index:1;
        border-top:0.25mm solid rgba(201,162,75,.55);padding-top:2.6mm;margin-top:4mm;
    }
    .cert-meta span{letter-spacing:.06em;text-transform:uppercase;font-size:3mm;color:#6b7280;font-weight:600;}
    .cert-meta strong{color:#202522;letter-spacing:.04em;font-weight:700;text-transform:none;font-size:3.4mm;}

    /* ---------- TITLE ---------- */
    .cert-title{
        display:flex;align-items:center;justify-content:center;gap:5mm;margin-top:6mm;
        text-align:center;position:relative;z-index:1;
        font-family:'Playfair Display',serif;font-weight:900;font-size:9.5mm;line-height:1.05;
        letter-spacing:.18em;text-transform:uppercase;color:#a87e2f;
    }
    .cert-title::before,.cert-title::after{content:"";width:24mm;height:0.4mm;background:linear-gradient(90deg,transparent,#c9a24b);}
    .cert-title::after{background:linear-gradient(90deg,#c9a24b,transparent);}
    .cert-title-sub{
        text-align:center;position:relative;z-index:1;margin-top:2mm;
        font-family:'Playfair Display',serif;font-weight:700;font-size:4.6mm;
        letter-spacing:.5em;text-transform:uppercase;color:#063b2e;padding-left:.5em;
    }

    /* ---------- RECIPIENT ---------- */
    .cert-caption{
        text-align:center;position:relative;z-index:1;margin:6mm 0 1.5mm;
        font-family:'Playfair Display',serif;font-style:italic;font-size:4.2mm;color:#6b7280;
    }
    .cert-name{
        text-align:center;position:relative;z-index:1;
        font-family:'Playfair Display',serif;font-weight:800;font-size:9mm;line-height:1.15;
        letter-spacing:.04em;color:#063b2e;margin:0;padding-bottom:2.4mm;
        border-bottom:0.3mm solid #c9a24b;display:block;width:100%;word-break:break-word;
    }
    .cert-email{
        text-align:center;position:relative;z-index:1;
        color:#6b7280;font-size:3.4mm;margin:2mm 0 4mm;letter-spacing:.03em;
    }

    /* ---------- BODY ---------- */
    .cert-body{
        text-align:center;max-width:150mm;margin:3mm auto 0;position:relative;z-index:1;
        font-family:'Playfair Display',serif;font-size:4mm;line-height:1.9;color:#202522;
    }
    .cert-progress{max-width:110mm;margin:5mm auto 0;position:relative;z-index:1;}
    .cert-progress .progress{height:4mm;border-radius:2mm;}
    .cert-progress .progress-fill{border-radius:2mm;}
    .cert-progress .progress-text{font-size:3mm;font-weight:600;letter-spacing:.05em;}

    /* ---------- AYAH ---------- */
    .cert-ayah{
        text-align:center;margin-top:5mm;padding-top:4mm;position:relative;z-index:1;
        border-top:0.25mm solid rgba(201,162,75,.55);
        font-style:italic;color:#6b7280;font-size:3.4mm;font-family:'Playfair Display',serif;
    }
    .cert-ayah .arabic{
        font-family:'Amiri','Scheherazade New',serif;font-size:7mm;font-weight:700;
        color:#a87e2f;line-height:1.5;direction:rtl;unicode-bidi:embed;
    }

    /* ---------- SIGNATURES ---------- */
    .cert-signatures{
        display:flex;justify-content:space-between;align-items:flex-end;gap:10mm;
        margin-top:auto;position:relative;z-index:1;padding-top:6mm;
    }
    .cert-signature{text-align:center;flex:1;min-width:0;}
    .sig-ar{
        font-family:'Amiri','Scheherazade New',serif;font-size:4.4mm;font-weight:700;
        color:#202522;margin-bottom:1mm;
    }
    .cert-sig-img{max-width:34mm;max-height:14mm;object-fit:contain;margin:0 auto 1.6mm;display:block;opacity:.95;}
    .sig-line{
        width:40mm;max-width:100%;height:0.4mm;
        background:linear-gradient(90deg,#e3c98b,#202522,#e3c98b);margin:0 auto 2mm;
    }
    .cert-signature span{font-size:3.4mm;color:#202522;letter-spacing:.05em;font-weight:600;word-break:break-word;}

    .cert-actions{max-width:210mm;margin:0 auto 6mm;display:flex;gap:3mm;flex-wrap:wrap;}

    /* ---------- PRINT ---------- */
    @media print{
        html,body{height:auto;min-height:0;background:#fff;}
        .sidebar,.topbar,.footer,.no-print,.cert-actions{display:none !important;}
        .main-area{margin:0 !important;min-height:0 !important;display:block !important;}
        .main-content{padding:0 !important;margin:0 !important;}
        .animate-rise{animation:none !important;}
        .cert-sheet{
            box-shadow:none;margin:0;max-width:100%;padding:0;
            border-radius:0;background:#fff;
            break-inside:avoid;page-break-inside:avoid;
        }
        .cert-border{
            border:0.6mm solid #c9a24b;border-radius:0;outline:0;overflow:visible;
            background:#faf8f1;
            -webkit-print-color-adjust:exact;print-color-adjust:exact;
            padding:8mm 12mm;
            min-height:250mm;height:auto;
        }
        .cert-border::before,.cert-corner{display:none;}
        @page{size:A4 portrait;margin:8mm;}
    }

    /* ---------- SCREEN RESPONSIVE ---------- */
    @media (max-width:820px){.cert-sheet{max-width:100%;}}
    @media (max-width:560px){
        .cert-border{padding:8mm 6mm;min-height:0;}
        .cert-name{font-size:7mm;}
        .cert-title{font-size:7mm;letter-spacing:.1em;}
        .cert-title::before,.cert-title::after{width:10mm;}
        .cert-signatures{flex-direction:column;gap:6mm;}
    }
</style>
</head>
<?php ui_page_start($pageRole, $pageActive, 'Certificate', 'Achievement'); ?>

<div class="animate-rise">
    <div class="cert-actions no-print">
        <button type="button" class="btn btn-gold btn-lg" onclick="event.preventDefault(); window.print(); return false;"><?= ui_icon('upload', 17) ?> Print / Save as PDF</button>
        <a class="btn btn-ghost" href="<?= $is_admin ? '/admin/students.php' : 'completed_surahs.php' ?>"><?= ui_icon('arrow-left', 16) ?> Back</a>
    </div>

    <?php if ($done === 0): ?>
        <div class="empty">
            <div class="empty-icon"><?= ui_icon('gem', 40) ?></div>
            <div class="empty-title">No surahs completed yet</div>
            <p class="small" style="margin:0;">Your certificate will be available once you complete at least one surah.</p>
        </div>
    <?php else: ?>
        <div class="cert-sheet">
            <div class="cert-border">
                <span class="cert-corner tl" aria-hidden="true"></span>
                <span class="cert-corner tr" aria-hidden="true"></span>
                <span class="cert-corner bl" aria-hidden="true"></span>
                <span class="cert-corner br" aria-hidden="true"></span>

                <div class="cert-header">
                    <div class="cert-logo"><img src="/logo/logo.jpg" alt="Lisanun Mubeen Academy"></div>
                    <h1>LISANUN MUBEEN ACADEMY</h1>
                    <p class="cert-tagline">Come learn and let the Qur’an be your life companion</p>
                </div>

                <div class="cert-meta">
                    <span>Certificate No: <strong><?= htmlspecialchars($cert_no) ?></strong></span>
                    <span>Date Issued: <strong><?= htmlspecialchars($issued_at) ?></strong></span>
                    <?php if ($cert_term_label !== ''): ?>
                        <span>Academic Term: <strong><?= htmlspecialchars($cert_term_label) ?></strong></span>
                    <?php endif; ?>
                </div>

                <div class="cert-title">Certificate</div>
                <div class="cert-title-sub">Of Completion</div>
                <p class="cert-caption">This is to certify that</p>
                <h2 class="cert-name"><?= htmlspecialchars($student['name']) ?></h2>
                <p class="cert-email"><?= htmlspecialchars($student['email']) ?></p>

                <p class="cert-body">
                    has successfully completed the recitation of
                    <strong><?= $done ?></strong> of <strong><?= $total ?></strong>
                    surahs of the Glorious Qur’an
                    <?php if ($exam_perf): ?>
                        with an examination performance of
                        <strong><?= htmlspecialchars($exam_perf['rating']) ?> (<?= $exam_perf['score'] ?>%)</strong>,
                    <?php else: ?>
                        and is progressing steadily in Qur’anic recitation,
                    <?php endif; ?>
                    demonstrating dedication and proficiency. May Allah accept this noble effort and make the Qur’an a companion
                    for the student in this life and the next.
                </p>

                <div class="cert-progress">
                    <div class="progress">
                        <div class="progress-fill gold" style="width:<?= $percent ?>%"></div>
                        <div class="progress-text"><?= $done ?> of <?= $total ?> surahs completed (<?= $percent ?>%)</div>
                    </div>
                </div>

                <div class="cert-ayah">
                    <div class="arabic" dir="rtl">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</div>
                    “The most beloved deeds to Allah are those done consistently, even if they are small.”
                </div>

                <div class="cert-signatures">
                    <div class="cert-signature">
                        <div class="sig-ar" dir="rtl">الأستاذ</div>
                        <img class="cert-sig-img" src="/signature/file_000000003dd881f481e869e1a9a29a78.png" alt="Signature">
                        <div class="sig-line"></div>
                        <span>Academy Administrator</span>
                    </div>
                    <div class="cert-signature">
                        <div class="sig-ar" dir="rtl">مدير الدراسات</div>
                        <img class="cert-sig-img" src="/signature/file_000000003dd881f481e869e1a9a29a78.png" alt="Signature">
                        <div class="sig-line"></div>
                        <span>Head of Studies</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php ui_page_end(); ?>

</body>
</html>
