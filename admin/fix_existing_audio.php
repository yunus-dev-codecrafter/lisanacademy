<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('admin');

@set_time_limit(600);

/* Only these directories can be touched — a whitelist keeps the page safe
   to run even if a <form> is tampered with. */
$dirs = array(
    'student_audio'    => __DIR__ . '/../uploads/student_audio/',
    'exam_audio'       => __DIR__ . '/../uploads/exam_audio/',
    'hafiz_test_audio' => __DIR__ . '/../uploads/hafiz_test_audio/',
    'admin_audio'      => __DIR__ . '/../uploads/admin_audio/',
    'admin_feedback'   => __DIR__ . '/../uploads/admin_feedback/',
);

/* ---- Handle a "fix one file" POST --------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $dirKey = $_POST['dir'] ?? '';
    $file   = basename((string)($_POST['file'] ?? ''));

    if (!isset($dirs[$dirKey]) || $file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
        $_SESSION['audiofix_flash'] = array('danger', 'Invalid directory or filename.');
        redirect('fix_existing_audio.php');
    }

    $path = $dirs[$dirKey] . $file;
    if (!is_file($path)) {
        $_SESSION['audiofix_flash'] = array('danger', 'File not found: ' . htmlspecialchars($file));
        redirect('fix_existing_audio.php');
    }

    $result = audio_fix_file($path);
    if ($result['changed']) {
        $_SESSION['audiofix_flash'] = array('success', $file . ' — ' . $result['note']);
    } else {
        $_SESSION['audiofix_flash'] = array('warning', $file . ' — ' . $result['note']);
    }

    redirect('fix_existing_audio.php');
}

$flash = $_SESSION['audiofix_flash'] ?? null;
unset($_SESSION['audiofix_flash']);

/* ---- Build the report --------------------------------------------- */
$reports = array();
$total   = 0;
$problem = 0;
foreach ($dirs as $key => $dirPath) {
    $items = array();
    if (is_dir($dirPath)) {
        $files = @scandir($dirPath);
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                if ($f === '.htaccess') continue;
                $path = $dirPath . $f;
                if (!is_file($path)) continue;
                $items[] = audio_analyze_file($path);
                $total++;
                if ($items[count($items) - 1]['type'] === '?') $problem++;
            }
        }
    }
    usort($items, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    $reports[$key] = $items;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Repair Old Audio — Admin</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Repair Old Audio', 'Audio'); ?>

<div class="page-hero animate-rise">
    <h1><?= ui_icon('wrench', 24) ?> Repair Old Audio</h1>
    <p class="small text-muted" style="margin:6px 0 0;max-width:760px;">
        Fixes recordings saved <em>before</em> the audio system was hardened.
        MP4/M4A files have their playback metadata moved to the front (faststart),
        so they start instantly instead of hanging on a spinner. Files you recorded
        with the in-app button already go through this automatically.
    </p>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash[0]) ?> animate-rise"><?= ui_icon('info', 16) ?> <?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>

<div class="card animate-rise d1">
    <div class="card-title"><h3 style="margin:0;"><?= ui_icon('mic', 17) ?> What needs attention</h3></div>
    <ul class="small" style="margin:8px 0 0;padding-left:18px;line-height:1.7;">
        <li><strong>M4A/MP4 “moov at end”</strong> — hang on “pause/loading”. Click <em>Fix</em> (or <em>Fix All</em>) to repair.</li>
        <li><strong>Videos (.mov/.mp4 camera recordings)</strong> — these play in the <em>video</em> player below (audio track audible). If marked “moov at end”, click <em>Fix</em> so they start instantly.</li>
        <li><strong>WebM / Ogg / AAC</strong> — play on most Android/Chrome/Firefox but not on every iPhone. Recording through the app avoids these going forward.</li>
    </ul>
    <p class="small text-muted" style="margin:10px 0 0;"><strong><?= $total ?></strong> files scanned.
        <?php if ($problem): ?><span style="color:var(--danger);"><?= $problem ?> unreadable.</span><?php endif; ?></p>
</div>

<?php $cardIdx = 1; foreach ($reports as $key => $items): $cardIdx++; ?>
<div class="card animate-rise d<?= min(5, $cardIdx) ?>" style="margin-top:12px;">
    <div class="card-title">
        <h3 style="margin:0;"><?= ui_icon('folder', 17) ?> uploads/<?= htmlspecialchars($key) ?>/ <span class="badge badge-grey"><?= count($items) ?> files</span></h3>
    </div>

    <?php if (!$items): ?>
        <p class="small text-muted" style="margin:8px 0 0;">No files in this folder.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.86rem;" class="small">
        <thead>
        <tr style="text-align:left;color:var(--muted,#666);">
            <th style="padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.1);">File</th>
            <th style="padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.1);">Size</th>
            <th style="padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.1);">Type</th>
            <th style="padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.1);">Status</th>
            <th style="padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.1);"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $r): ?>
            <?php $fixable = ($r['type'] === 'mp4' && $r['slow']); ?>
            <tr style="border-bottom:1px solid rgba(0,0,0,.06);">
                <td style="padding:6px 8px;word-break:break-all;max-width:300px;"><?= htmlspecialchars($r['name']) ?></td>
                <td style="padding:6px 8px;white-space:nowrap;"><?= number_format($r['size'] / 1048576, 2) ?> MB</td>
                <td style="padding:6px 8px;">
                    <?php if ($r['type'] === '?'): ?>
                        <span class="badge badge-red">unknown</span>
                    <?php elseif ($r['video']): ?>
                        <span class="badge badge-blue">video</span>
                    <?php else: ?>
                        <span class="badge badge-blue"><?= htmlspecialchars($r['type']) ?></span>
                    <?php endif; ?>
                </td>
                <td style="padding:6px 8px;">
                    <?php if ($r['type'] === '?'): ?>
                        <span class="badge badge-red">unreadable</span>
                        <span class="small text-muted"><?= htmlspecialchars($r['note']) ?></span>
                    <?php elseif ($fixable): ?>
                        <span class="badge badge-gold">moov at end — needs faststart</span>
                    <?php elseif ($r['video']): ?>
                        <span class="small text-muted">video — plays in video player</span>
                    <?php else: ?>
                        <span class="small text-muted"><?= htmlspecialchars($r['note'] ?: 'fine') ?></span>
                    <?php endif; ?>
                </td>
                <td style="padding:6px 8px;white-space:nowrap;text-align:right;">
                    <?php if ($fixable): ?>
                        <form method="POST" style="display:inline-block;margin:0;" data-fixform>
                            <?= csrf_field() ?>
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($key) ?>">
                            <input type="hidden" name="file" value="<?= htmlspecialchars($r['name']) ?>">
                            <button type="submit" class="btn btn-sm" data-fixbtn data-dir="<?= htmlspecialchars($key) ?>" data-file="<?= htmlspecialchars($r['name']) ?>"><?= ui_icon('wrench', 13) ?> Fix</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-ghost" href="../uploads/<?= htmlspecialchars($key) ?>/<?= rawurlencode($r['name']) ?>" download>Download</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card animate-rise d5" style="margin-top:12px;">
    <div class="card-title"><h3 style="margin:0;"><?= ui_icon('refresh', 17) ?> Fix everything at once</h3></div>
    <p class="small" style="margin:6px 0 10px;">
        Fixes every “moov at end” M4A/MP4/MOV file across all folders, one request per file
        (safe on shared hosting, no ffmpeg needed).
    </p>
    <button id="fixAllBtn" class="btn btn-gold" type="button"><?= ui_icon('wrench', 16) ?> Fix All Fixable Files</button>
    <span id="fixAllStatus" class="small text-muted" style="margin-left:10px;"></span>
</div>

<div style="margin-top:14px;">
    <a class="btn btn-ghost" href="teaching.php"><?= ui_icon('arrow-left', 16) ?> Back to Teaching</a>
</div>

<?php ui_page_end(); ?>
<script>
(function () {
    var status = document.getElementById('fixAllStatus');
    var btn = document.getElementById('fixAllBtn');
    if (!btn) return;

    var csrf = document.querySelector('[data-fixform] input[name=csrf_token]');
    var csrfVal = csrf ? csrf.value : '';
    var targets = Array.prototype.slice.call(document.querySelectorAll('[data-fixbtn]')).map(function (b) {
        return { dir: b.getAttribute('data-dir'), file: b.getAttribute('data-file') };
    });
    var done = 0;

    function run(i) {
        if (i >= targets.length) {
            status.textContent = 'Done. Reloading…';
            setTimeout(function () { location.reload(); }, 600);
            return;
        }
        var fd = new FormData();
        fd.append('csrf_token', csrfVal);
        fd.append('dir', targets[i].dir);
        fd.append('file', targets[i].file);
        status.textContent = 'Fixing ' + (i + 1) + ' of ' + targets.length + '…';
        window.fetch('fix_existing_audio.php', { method: 'POST', body: fd })
            .catch(function () {})
            .then(function () { run(i + 1); });
    }

    btn.addEventListener('click', function () {
        if (targets.length === 0) {
            status.textContent = 'Nothing to fix.';
            return;
        }
        if (!window.confirm('Fix ' + targets.length + ' files now? This can take a minute.')) return;
        btn.disabled = true;
        run(0);
    });
})();
</script>

</body>
</html>