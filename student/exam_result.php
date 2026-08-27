<?php
require '../config/security/helpers.php';
require '../auth/auth_check.php';
require '../config/db.php';

$is_admin = ($_SESSION['role'] ?? '') === 'admin';

if ($is_admin) {
    $student_id = (int)($_GET['student'] ?? 0);
    $attempt_id = (int)($_GET['attempt'] ?? 0);
    if ($attempt_id <= 0 || $student_id <= 0) redirect('/admin/exams.php');
} else {
    require_role('student');
    $student_id = (int)$_SESSION['user_id'];
    $attempt_id = (int)($_GET['attempt'] ?? 0);
    if ($attempt_id <= 0) redirect('exam.php');
}

/* ============ FETCH ATTEMPT ============ */
$stmt = $conn->prepare("
    SELECT e.*, u.name, u.email
    FROM exam_attempts e
    JOIN users u ON u.id = e.student_id
    WHERE e.id = ? AND e.student_id = ? AND e.status IN ('approved','rejected')
");
$stmt->bind_param("ii", $attempt_id, $student_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();

if (!$attempt) redirect($is_admin ? '/admin/exams.php' : 'exam.php');

$answers = [];
$stmt = $conn->prepare("
    SELECT a.*, s.name_en AS surah_name, s.name_ar AS surah_name_ar
    FROM exam_answers a
    JOIN surahs s ON s.id = a.surah_id
    WHERE a.attempt_id = ?
    ORDER BY a.id ASC
");
$stmt->bind_param("i", $attempt_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $answers[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Exam Result — <?= htmlspecialchars($attempt['name']) ?></title>
<?= ui_css() ?>
<style>
    .result-sheet{
        background:#fff;max-width:900px;margin:0 auto;padding:10px;
        border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);
    }
    .result-border{
        border:3px double var(--gold);border-radius:14px;padding:30px 36px;background:#fff;
    }
    .result-header{text-align:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:16px;}
    .result-logo{width:74px;height:74px;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;}
    .result-logo img{width:74px;height:74px;object-fit:contain;display:block;}
    .result-header h1{font-size:1.3rem;margin:0 0 2px;}
    .result-header p{margin:0;color:var(--text-muted);font-size:.85rem;}
    .result-title{
        text-align:center;font-family:var(--font-display);font-weight:800;font-size:1.4rem;
        letter-spacing:.16em;color:var(--gold-deep);text-transform:uppercase;margin:4px 0 2px;
    }
    .result-name{text-align:center;font-size:1.55rem;margin:6px 0 2px;color:var(--emerald-900);}
    .result-email{text-align:center;color:var(--text-muted);font-size:.85rem;margin:0 0 8px;}
    .result-overall{
        display:flex;justify-content:center;align-items:center;gap:20px;flex-wrap:wrap;
        background:linear-gradient(135deg,var(--emerald-900),var(--emerald-700));color:#fff;
        border-radius:var(--radius);padding:14px;margin:0 0 14px;
    }
    .result-overall .num{font-family:var(--font-display);font-size:1.6rem;font-weight:800;}
    .result-overall .lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;opacity:.9;}
    table.result-table{width:100%;border-collapse:collapse;font-size:.86rem;margin-bottom:14px;}
    table.result-table th,table.result-table td{border:1px solid var(--border);padding:7px 10px;text-align:left;}
    table.result-table th{background:var(--surface-muted);}
    .result-signatures{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin-top:22px;}
    .result-signature{text-align:center;flex:1;}
    .result-sig-img{max-width:150px;max-height:60px;object-fit:contain;display:block;margin:0 auto 6px;}
    .sig-line{width:180px;max-width:100%;border-bottom:2px solid var(--text);margin:0 auto 8px;}
    .result-signature span{font-size:.82rem;color:var(--text-muted);}
    .no-print{max-width:900px;margin:0 auto 18px;display:flex;gap:10px;flex-wrap:wrap;}
    @media print{
        body{background:#fff;}
        .sidebar,.topbar,.footer,.no-print{display:none !important;}
        .main-area{margin:0 !important;}
        .main-content{padding:0 !important;}
        @page{size:A4 portrait;margin:8mm;}
        .result-sheet{
            box-shadow:none;margin:0;max-width:100%;padding:0;
            break-inside:avoid;page-break-inside:avoid;
            -webkit-print-color-adjust:exact;print-color-adjust:exact;
        }
        .result-border{
            display:flex;flex-direction:column;
            border:3px double var(--gold);
            -webkit-print-color-adjust:exact;print-color-adjust:exact;
            padding:6mm 8mm;
        }
        .result-header{padding-bottom:6px;margin-bottom:8px;}
        .result-logo{width:54px;height:54px;margin:0 auto 6px;}
        .result-logo img{width:54px;height:54px;}
        .result-header h1{font-size:1.05rem;}
        .result-header p{font-size:.75rem;}
        .result-title{font-size:1.15rem;margin:2px 0 0;}
        .result-name{font-size:1.25rem;margin:4px 0 0;}
        .result-email{margin:0 0 4px;}
        .result-overall{padding:7px 10px;margin:0 0 8px;gap:14px;}
        .result-overall .num{font-size:1.2rem;}
        table.result-table{font-size:.72rem;margin-bottom:8px;}
        table.result-table th,table.result-table td{padding:3px 6px;}
        .result-sig-img{max-width:110px;max-height:44px;}
        .result-signatures{margin-top:auto;padding-top:10px;}
    }
</style>
</head>
<?php ui_page_start($is_admin ? 'admin' : 'student', $is_admin ? 'exams' : 'exam', 'Exam Result', 'Result Sheet'); ?>

<div class="animate-rise">
    <div class="no-print">
        <button class="btn btn-gold btn-lg" onclick="window.print()"><?= ui_icon('upload', 17) ?> Print / Save as PDF</button>
        <a class="btn btn-ghost" href="<?= $is_admin ? 'exams.php' : 'exam.php' ?>"><?= ui_icon('arrow-left', 16) ?> Back</a>
    </div>

    <div class="result-sheet">
        <div class="result-border">
            <div class="result-header">
                <div class="result-logo"><img src="/logo/logo.jpg" alt="Lisanun Mubeen Academy"></div>
                <h1>LISANUN MUBEEN ACADEMY</h1>
                <p>Come learn and let the Qur’an be your life companion</p>
            </div>

            <div class="result-title">Examination Result</div>

            <?php if (!empty($attempt['term_id'])): ?>
                <p style="text-align:center;color:var(--text-muted);font-size:.85rem;margin:0 0 4px;"><?= htmlspecialchars(exam_term_label($attempt['term_id'], $conn)) ?></p>
            <?php endif; ?>

            <h2 class="result-name"><?= htmlspecialchars($attempt['name']) ?></h2>
            <p class="result-email"><?= htmlspecialchars($attempt['email']) ?></p>

            <div class="result-overall">
                <div style="text-align:center;">
                    <div class="num"><?= htmlspecialchars($attempt['overall_rating'] ?? '—') ?></div>
                    <div class="lbl">Overall Rating</div>
                </div>
                <div style="text-align:center;">
                    <div class="num"><?= (int)$attempt['overall_score'] ?>/100</div>
                    <div class="lbl">Score</div>
                </div>
                <div style="text-align:center;">
                    <div class="num"><?= count($answers) ?>/<?= (int)($attempt['question_count'] ?: max(3, count($answers))) ?></div>
                    <div class="lbl">Questions</div>
                </div>
                <div style="text-align:center;">
                    <div class="num"><?= date('d M Y', strtotime($attempt['submitted_at'])) ?></div>
                    <div class="lbl">Date</div>
                </div>
            </div>

            <table class="result-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Question</th>
                        <th>Rating</th>
                        <th>Feedback</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($answers as $i => $a): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                Recite Surah <?= htmlspecialchars($a['surah_name']) ?>
                                <?php $ar = arabic_text($a['surah_name_ar'] ?? ''); if ($ar !== ''): ?><span class="arabic"><?= htmlspecialchars($ar) ?></span><?php endif; ?>
                                from verse <?= (int)$a['from_verse'] ?> to <?= (int)$a['to_verse'] ?>
                            </td>
                            <td><strong><?= htmlspecialchars($a['rating'] ?? '—') ?></strong></td>
                            <td><?= nl2br(htmlspecialchars($a['feedback'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!empty($attempt['overall_feedback'])): ?>
                <p style="margin:0;"><strong>Teacher’s overall feedback:</strong><br><?= nl2br(htmlspecialchars($attempt['overall_feedback'])) ?></p>
            <?php endif; ?>

            <div class="result-signatures">
                <div class="result-signature">
                    <img class="result-sig-img" src="/signature/file_000000003dd881f481e869e1a9a29a78.png" alt="Signature">
                    <div class="sig-line"></div>
                    <span>Academy Administrator</span>
                </div>
                <div class="result-signature">
                    <img class="result-sig-img" src="/signature/file_000000003dd881f481e869e1a9a29a78.png" alt="Signature">
                    <div class="sig-line"></div>
                    <span>Head of Studies</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php ui_page_end(); ?>

</body>
</html>
