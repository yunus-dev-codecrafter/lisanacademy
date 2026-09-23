<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

/* Opportunistic media cleanup: Muraja'ah videos (36h) + completed-cycle audios (24h) */
run_opportunistic_cleanup($conn);

/* Guard: must be a Memorizer */
if (!student_is_memorizing($conn, $student_id)) {
    redirect('dashboard.php');
}

/* Schema check */
if (!mem_engine_installed($conn)) {
    redirect('dashboard.php');
}

/* Self-heal: repair any drifted pointer before computing today's task */
mem_self_heal($conn, $student_id);

/* Gate */
$gate = mem_gate($conn, $student_id);
$mem_state = mem_get_state($conn, $student_id);
$task = $gate['task'] ?: ($mem_state ? mem_compute_task($conn, $mem_state) : null);
$session = $gate['session'] ?? null;
$summary = mem_progress_summary($conn, $student_id);
$statuses = mem_page_statuses($conn, $student_id);
$history = mem_history($conn, $student_id, 12);
$recent_feedback = mem_recent_murajaah_feedback($conn, $student_id, 5);

/* Recitation-assistance request state for today's memorization page */
$assist = null;
if ($gate['state'] === 'memorization' && $task) {
    $assist = mem_assistance_current($conn, $student_id, (int)$task['start_page'], '*');
}

$today_page = ($task && $task['task_type'] === 'memorization') ? (int)$task['start_page'] : 0;
?>
<!DOCTYPE html>
<html>
<head>
<title>Qur'an Memorization</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'memorization', 'Qur\'an Memorization'); ?>
<?= csrf_field() ?>

<div class="page-hero animate-rise">
    <h1>حفظ القرآن الكريم والمراجعة</h1>
    <p>Day by day memorization &amp; Muraja'ah — 604 pages, by design.</p>
</div>

<?php if (isset($_GET['marked'])): ?>
    <div class="alert alert-success animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('check-circle', 18) ?>
        <span style="flex:1;"><strong>Page <?= (int)$_GET['marked'] ?> marked as memorized!</strong> Progress has advanced to your next scheduled task.</span>
    </div>
<?php endif; ?>
<?php if (isset($_GET['assist_requested'])): ?>
    <div class="alert alert-success animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('check-circle', 18) ?>
        <span style="flex:1;"><strong>Assistance request sent!</strong> Your teacher has been notified and will soon recite today's page to you.</span>
    </div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger animate-rise" style="margin-bottom:14px;">
        <?= ui_icon('alert', 18) ?>
        <span style="flex:1;"><?= htmlspecialchars($_GET['error']) ?></span>
    </div>
<?php endif; ?>

<?php if (!in_array($gate['state'], ['celebration', 'memorization', 'pending', 'retake', 'awaiting'], true)): ?>

    <!-- Non-active states: paused / done / blocked -->
    <?php if ($gate['state'] === 'done'): ?>
    <div class="card card-gold animate-rise" style="text-align:center;">
        <div style="font-size:3rem;margin-bottom:10px;">🎉</div>
        <h2 style="margin:0 0 8px;">الحمد لله! Qur'an Memorized!</h2>
        <p class="text-muted" style="margin:0 0 4px;">You have completed the entire Qur'an — all 604 pages memorized &amp; revised.</p>
        <p class="small text-muted" style="margin:0;"><strong>﴿وَلَقَدْ يَسَّرْنَا الْقُرْآنَ لِلذِّكْرِ فَهَلْ مِن مُّدَّكِرٍ﴾</strong></p>
    </div>
    <?php elseif ($gate['state'] === 'paused'): ?>
    <div class="alert alert-info animate-rise">
        <?= ui_icon('pause', 18) ?>
        <span style="flex:1;"><strong>Your memorization journey is paused.</strong> Contact the academy to resume it. Your progress below is preserved.</span>
    </div>
    <?php elseif ($gate['state'] === 'denied' || $gate['state'] === 'unmigrated' || $gate['state'] === 'inactive'): ?>
    <div class="alert alert-warning animate-rise">
        <?= ui_icon('alert', 18) ?>
        <span style="flex:1;"><?= htmlspecialchars($gate['reason']) ?></span>
        <a class="btn btn-gold btn-sm" href="dashboard.php"><?= ui_icon('grid', 15) ?> Go to Dashboard</a>
    </div>
    <?php endif; ?>

<?php else: ?>

    <!-- =================== C E L E B R A T I O N   D A Y =================== -->
    <?php if ($gate['state'] === 'celebration'): ?>
    <div class="card card-gold animate-rise" style="text-align:center;border:1px solid var(--gold-300, #fbbf24);">
        <div style="font-size:3rem;margin-bottom:10px;"><?= $task['celebrating'] === 'half' ? '🌟' : '🎉' ?></div>
        <?php if ($task['celebrating'] === 'half'): ?>
            <h2 style="margin:0 0 8px;">Half-Qur'an Milestone!</h2>
            <p class="text-muted" style="margin:0 0 16px;">Al-Fatihah through Surah Al-Kahf — <strong>half of the entire Qur'an</strong> is now memorized.</p>
        <?php else: ?>
            <h2 style="margin:0 0 8px;">Completion Celebration!</h2>
            <p class="text-muted" style="margin:0 0 16px;">الحمد لله … You have now completed the entire Qur'an. Ma sha Allah!</p>
        <?php endif; ?>
        <form method="POST" action="submit_memorization.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="acknowledge">
            <p class="small text-muted" style="margin:0 0 12px;">This is a celebration day — no memorization task today. Acknowledge to move to the next stage of your journey.</p>
            <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('gem', 18) ?> Continue My Journey</button>
        </form>
    </div>

    <!-- =================== M E M O R I Z A T I O N   D A Y =================== -->
    <?php elseif ($gate['state'] === 'memorization'): ?>
    <div class="card animate-rise d1" style="border:1px solid rgba(217,119,6,.35);">
        <div class="card-title" style="align-items:center;">
            <h3 style="margin:0;"><?= ui_icon('star', 18) ?> Today's Task — Memorization Day <?= (int)$task['day_number'] ?></h3>
            <span class="badge" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;">&#1581;&#1601;&#1592; Memorization</span>
        </div>
        <div style="text-align:center;padding:14px 0 6px;">
            <div style="font-size:2.6rem;font-weight:800;color:var(--gold-deep);line-height:1.15;">Page <?= (int)$task['start_page'] ?></div>
            <p class="small" style="margin:6px 0 14px;"><?= htmlspecialchars($task['label'] ?: 'Memorize today\'s page from the Mushaf.') ?></p>
            <form method="POST" action="submit_memorization.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="memorized">
                <input type="hidden" name="page" value="<?= (int)$task['start_page'] ?>">
                <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('check-circle', 18) ?> Mark as Memorized</button>
            </form>

            <?php if ($assist): ?>
                <?php if ($assist['status'] === 'pending'): ?>
                    <div class="alert alert-info" style="margin:16px 0 0;text-align:left;">
                        <?= ui_icon('clock', 16) ?>
                        <span style="flex:1;"><strong>Assistance Requested</strong> — your teacher has been notified and will recite Page <?= (int)$task['start_page'] ?> to you. Keep practising in the meantime.</span>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success" style="margin:16px 0 0;text-align:left;">
                        <?= ui_icon('check-circle', 16) ?>
                        <span style="flex:1;"><strong>Teacher Recitation Available for Page <?= (int)$task['start_page'] ?></strong>
                        <?php if (!empty($assist['admin_audio'])): ?>
                            <span style="display:block;margin-top:6px;"><?= media_player_html($assist['admin_audio'], '../uploads/admin_feedback/') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($assist['admin_notes'])): ?>
                            <span class="small" style="display:block;margin-top:6px;">Teacher notes: &ldquo;<?= htmlspecialchars($assist['admin_notes']) ?>&rdquo;</span>
                        <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <button class="btn btn-ghost btn-lg" style="margin-top:14px;" onclick="openAssistModal()"><?= ui_icon('headphones', 18) ?> Need Teacher to Recite This Page? Request Assistance</button>
            <?php endif; ?>

            <p class="small text-muted" style="margin:12px 0 0;">Memorize the page from the Mushaf, then mark it done. You control the pace.</p>
        </div>
    </div>

    <!-- =================== M U R A J A ' A H   D A Y =================== -->
    <?php elseif ($gate['state'] === 'awaiting' || $gate['state'] === 'pending' || $gate['state'] === 'retake'): ?>
    <?php
        $sessions = $task['sessions'];
        $range_hint = 'Pages ' . (int)$task['start_page'] . '–' . (int)$task['end_page'] . ' · ' . (int)$task['page_count'] . ' pages';
        if (count($sessions) > 1):
            $role = $task['day_session'] === 'morning' ? 'Morning' : 'Evening';
            $range_hint = $role . ' Session: ' . $range_hint;
        endif;
    ?>
    <div class="card animate-rise d1" style="border:1px solid rgba(124,58,237,.35);">
        <div class="card-title" style="align-items:center;">
            <h3 style="margin:0;"><?= ui_icon('refresh', 18) ?> Today's Task — Muraja'ah Day <?= (int)$task['day_number'] ?></h3>
            <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;">مراجعة Muraja'ah</span>
        </div>

        <?php if ($gate['state'] === 'pending'): ?>
            <div class="alert alert-info" style="margin:10px 0;">
                <?= ui_icon('clock', 16) ?>
                <span style="flex:1;"><strong>Recitation submitted — awaiting teacher review.</strong> Your teacher will mark this assessment soon. You cannot advance until it is accepted.</span>
            </div>
            <?php if ($session && !empty($session['audio_file'])): ?>
                <p class="small text-muted" style="margin:0 0 8px;">Your submission (<?= date('d M Y, g:i A', strtotime($session['submitted_at'])) ?>):</p>
                <?= media_player_html($session['audio_file'], '../uploads/student_audio/') ?>
            <?php endif; ?>

        <?php elseif ($gate['state'] === 'retake'): ?>
            <div class="alert alert-danger" style="margin:10px 0;">
                <?= ui_icon('alert', 16) ?>
                <span style="flex:1;">
                    <strong>Revision not accepted — please review the feedback, practice <?= $range_hint ?>, and re-submit.</strong>
                    <?php if ($session && !empty($session['feedback'])): ?>
                        <span class="small" style="display:block;margin-top:6px;">Teacher notes: &ldquo;<?= htmlspecialchars($session['feedback']) ?>&rdquo;</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($session && !empty($session['admin_audio_feedback'])): ?>
                <p class="small text-muted" style="margin:0 0 8px;">Teacher audio feedback:</p>
                <?= media_player_html($session['admin_audio_feedback'], '../uploads/admin_feedback/') ?>
            <?php endif; ?>
        <?php endif; ?>

        <div style="text-align:center;padding:14px 0 6px;">
            <div style="font-size:2rem;font-weight:800;color:#7c3aed;line-height:1.2;"><?= $range_hint ?></div>
            <?php if (count($sessions) > 1): ?>
                <p class="small text-muted" style="margin:8px 0 0;">Split session day — recite the <?= $task['day_session'] === 'morning' ? 'Morning' : 'Evening' ?> portion from memory.
                <?php if ($task['day_session'] === 'morning'): ?>Your Evening session unlocks once the teacher passes the Morning one.<?php endif; ?></p>
            <?php else: ?>
                <p class="small text-muted" style="margin:8px 0 0;">Recite this range from memory to your teacher — video, live, or in person.</p>
            <?php endif; ?>

            <?php if ($gate['state'] === 'retake'): ?>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:16px;">
                    <button class="btn btn-lg" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;" onclick="openReciteModal('upload')"><?= ui_icon('video', 18) ?> Retake: Upload Video</button>
                    <button class="btn btn-gold btn-lg" onclick="openReciteModal('live')"><?= ui_icon('send', 18) ?> Retake via WhatsApp</button>
                    <button class="btn btn-lg" onclick="openReciteModal('inperson')"><?= ui_icon('user', 18) ?> Recited In Person</button>
                </div>
            <?php elseif ($gate['state'] === 'awaiting'): ?>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:16px;">
                    <button class="btn btn-lg" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;" onclick="openReciteModal('upload')"><?= ui_icon('video', 18) ?> Upload Video</button>
                    <button class="btn btn-gold btn-lg" onclick="openReciteModal('live')"><?= ui_icon('send', 18) ?> Live via WhatsApp</button>
                    <button class="btn btn-lg" onclick="openReciteModal('inperson')"><?= ui_icon('user', 18) ?> Recited In Person</button>
                </div>
            <?php else: ?>
                <p class="small" style="margin:12px 0 0;color:var(--gold-deep);"><?= ui_icon('clock', 14) ?> Submitted at <?= date('d M Y, g:i A', strtotime($session['submitted_at'])) ?> — awaiting teacher review.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- =================== M I L E S T O N E   B A N N E R S =================== -->
    <?php if ($summary && $summary['milestone'] === '100'): ?>
        <div class="alert alert-success animate-rise d1" style="margin:16px 0 0;"><?= ui_icon('trophy', 18) ?> <span style="flex:1;"><strong>100 pages memorized — milestone reached!</strong> Keep going, half the Qur'an awaits.</span></div>
    <?php elseif ($summary && $summary['milestone'] === 'half'): ?>
        <div class="alert alert-success animate-rise d1" style="margin:16px 0 0;"><?= ui_icon('gem', 18) ?> <span style="flex:1;"><strong>Half of the Qur'an completed (304/604 pages).</strong> Al-Fatihah through Al-Kahf delivered. Ma sha Allah!</span></div>
    <?php elseif ($summary && $summary['milestone'] === 'complete'): ?>
        <div class="alert alert-success animate-rise d1" style="margin:16px 0 0;"><?= ui_icon('trophy', 18) ?> <span style="flex:1;"><strong>Entire Qur'an completed.</strong> الحمد لله رب العالمين!</span></div>
    <?php endif; ?>

    <!-- =================== P R O G R E S S =================== -->
    <?php if ($summary): ?>
    <div class="card animate-rise d2" style="margin-top:16px;">
        <div class="card-title">
            <h3 style="margin:0;"><?= ui_icon('grid', 18) ?> Memorization Progress</h3>
        </div>
        <div class="grid-3">
            <div class="panel" style="margin:0;text-align:center;">
                <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--gold-deep);"><?= (int)$summary['pages_memorized'] ?> / 604</div>
                <div class="small text-muted">Pages Memorized</div>
            </div>
            <div class="panel" style="margin:0;text-align:center;">
                <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--emerald-800);"><?= (int)$summary['pct'] ?>%</div>
                <div class="small text-muted">Overall Progress</div>
            </div>
            <div class="panel" style="margin:0;text-align:center;">
                <div style="font-family:var(--font-display);font-weight:800;font-size:1.25rem;color:var(--emerald-800);"><?= (int)$summary['days_completed'] ?> / <?= (int)$summary['days_total'] ?></div>
                <div class="small text-muted">Days Completed</div>
            </div>
        </div>
        <div class="progress" style="margin-top:12px;">
            <div class="progress-fill" style="width:<?= (int)$summary['pct'] ?>%;background:linear-gradient(90deg,#d97706,#f59e0b);"></div>
            <div class="progress-text"><?= (int)$summary['pct'] ?>%</div>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid var(--border);">
            <span class="small">Block: <strong><?= (int)$summary['current_block'] ?: 'Rev' ?></strong></span>
            <span class="small">100-Page Group: <strong><?= (int)$summary['block100'] ?></strong></span>
            <span class="small">Next assessment: <strong>Day <?= (int)$summary['next_assessment_day'] ?></strong></span>
            <?php if ($summary['next_celebration_day']): ?>
                <span class="small">Next celebration: <strong>Day <?= (int)$summary['next_celebration_day'] ?></strong></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- =================== 604 GRID =================== -->
    <div class="card animate-rise d3" style="margin-top:16px;">
        <div class="card-title">
            <h3 style="margin:0;"><?= ui_icon('grid', 18) ?> Qur'an Grid</h3>
            <span class="small text-muted">Pages 1–604</span>
        </div>

        <div class="mem-grid">
            <?php for ($p = 1; $p <= 604; $p++):
                $cls = 'page-cell page-locked';
                switch ($statuses[$p]) {
                    case 'revised':        $cls = 'page-cell mem-revised'; break;
                    case 'memorized':      $cls = 'page-cell page-done'; break;
                    case 'under_revision': $cls = 'page-cell mem-under'; break;
                    default:               $cls = 'page-cell page-locked';
                }
                if ($p === $today_page) $cls = 'page-cell page-current';
            ?>
                <div class="<?= $cls ?>" title="Page <?= $p ?><?= $p === $today_page ? ' — today' : '' ?>"><?= $p ?></div>
            <?php endfor; ?>
        </div>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
            <span class="small"><span class="cell-swatch mem-revised"></span> Revised</span>
            <span class="small"><span class="cell-swatch page-done"></span> Memorized</span>
            <span class="small"><span class="cell-swatch mem-under"></span> Under Revision</span>
            <span class="small"><span class="cell-swatch page-current"></span> Today</span>
            <span class="small"><span class="cell-swatch page-locked"></span> Not memorized</span>
        </div>
    </div>

    <!-- =================== R E C E N T   M U R A J A ' A H   F E E D B A C K =================== -->
    <?php if (!empty($recent_feedback)): ?>
    <div class="card animate-rise d4" style="margin-top:16px;">
        <div class="card-title">
            <h3 style="margin:0;"><?= ui_icon('chat', 18) ?> Recent Muraja'ah Reviews &amp; Teacher Feedback</h3>
        </div>
        <?php $first = true; foreach ($recent_feedback as $fb): ?>
            <div style="padding:12px 0;border-top:1px solid var(--border);<?= $first ? 'border-top:none;padding-top:0;' : '' ?>">
                <?php $first = false; ?>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;">Day <?= (int)$fb['task_day'] ?></span>
                    <span class="small" style="font-weight:600;">Pages <?= (int)$fb['start_page'] ?>–<?= (int)$fb['end_page'] ?></span>
                    <span class="badge <?= $fb['status'] === 'passed' ? 'badge-green' : 'badge-red' ?>"><?= $fb['status'] === 'passed' ? 'Passed' : 'Needs Work' ?></span>
                    <span class="small text-muted"><?= date('d M Y', strtotime($fb['reviewed_at'] ?: $fb['submitted_at'])) ?></span>
                </div>
                <?php if (!empty($fb['feedback'])): ?>
                    <p class="small" style="margin:8px 0 0;">Teacher notes: &ldquo;<?= htmlspecialchars($fb['feedback']) ?>&rdquo;</p>
                <?php endif; ?>
                <?php if (!empty($fb['admin_audio_feedback'])): ?>
                    <p class="small text-muted" style="margin:8px 0 2px;">Teacher audio feedback:</p>
                    <?= media_player_html($fb['admin_audio_feedback'], '../uploads/admin_feedback/') ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- =================== H I S T O R Y =================== -->
    <?php if (!empty($history)): ?>
    <div class="card animate-rise d4" style="margin-top:16px;">
        <div class="card-title">
            <h3 style="margin:0;"><?= ui_icon('history', 18) ?> Journey Timeline</h3>
        </div>
        <?php foreach ($history as $h): ?>
            <?php
                $ht = $h['task_type'];
                $hres = $h['result'];
                $hspan = ($ht === 'memorization')
                    ? 'Page ' . (int)$h['start_page']
                    : 'Pages ' . (int)$h['start_page'] . '–' . (int)$h['end_page'];
                $hdone = ['completed', 'passed', 'skipped'];
                $ok = in_array($hres, $hdone, true);
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);flex-wrap:wrap;">
                <span class="badge <?= $ht === 'memorization' ? 'badge-gold' : 'badge-blue' ?>">
                    <?= $ht === 'memorization' ? 'Memorization' : 'Muraja&#8217;ah' ?>
                </span>
                <span class="small" style="font-weight:600;"><?= $hspan ?></span>
                <?php if ($h['day_session'] !== 'full'): ?>
                    <span class="small text-muted"><?= ucfirst($h['day_session']) ?> session</span>
                <?php endif; ?>
                <span class="small text-muted">Day <?= (int)$h['task_day'] ?> · <?= date('d M Y', strtotime($h['completed_at'])) ?></span>
                <?php if ($ok): ?>
                    <span class="badge badge-green"><?= $hres === 'skipped' ? 'Skipped' : 'Done' ?></span>
                <?php else: ?>
                    <span class="badge <?= $hres === 'failed' ? 'badge-red' : 'badge-gold' ?>"><?= ucfirst($hres) ?></span>
                    <?php if (!empty($h['notes'])): ?>
                        <span class="small text-muted">&ldquo;<?= htmlspecialchars($h['notes']) ?>&rdquo;</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- =================== A S S I S T   M O D A L =================== -->
<?php if ($gate['state'] === 'memorization' && !$assist && $task): ?>
<div class="modal" id="assistModal">
    <div class="modal-content" style="max-width:460px;">
        <span class="modal-close" onclick="closeAssistModal()">&times;</span>
        <h3 style="margin:0 0 6px;">Request Recitation Assistance</h3>
        <p class="small text-muted" style="margin:0 0 14px;">Your teacher will be notified and can recite today's page (Page <?= (int)$task['start_page'] ?>) to you — over a call or with an audio recitation.</p>
        <textarea id="assistNote" class="form-textarea" rows="3" placeholder="Any specific verses or tajweed questions? (optional)"></textarea>
        <div id="assistProgress" style="display:none;" class="small text-muted">Sending request...</div>
        <button class="btn btn-gold btn-block" style="margin-top:12px;" onclick="sendAssist()"><?= ui_icon('send', 16) ?> Send Request</button>
    </div>
</div>
<?php endif; ?>

<!-- =================== M U R A J A ' A H   M O D A L =================== -->
<?php if (in_array($gate['state'], ['awaiting', 'retake'], true)): ?>
<div class="modal" id="reciteModal">
    <div class="modal-content" style="max-width:480px;">
        <span class="modal-close" onclick="closeReciteModal()">&times;</span>
        <h3 style="margin:0 0 6px;">Muraja'ah: <?= $range_hint ?></h3>
        <p class="small text-muted" style="margin:0 0 16px;" id="reciteModeText">Choose how you want to recite this range from memory.</p>

        <div id="uploadPanel" style="display:none;">
            <p class="small" style="margin:0 0 12px;">Record a VIDEO of yourself reciting <?= $range_hint ?> from memory — sitting at a distance where your <strong>face and surroundings are fully visible</strong>.</p>
            <div class="alert alert-warning" style="margin:0 0 10px;padding:8px 10px;"><?= ui_icon('alert', 14) ?> Audio-only and selfie recordings are strictly rejected. Supported: MP4, MOV, M4V, 3GP.</div>
            <div class="form-group">
                <label class="form-label">Video File</label>
                <input class="form-input" type="file" id="uploadFile" accept=".mp4,.mov,.m4v,.3gp,.3gpp" required>
            </div>
            <video id="uploadVideoPreview" controls playsinline style="display:none;width:100%;max-width:400px;border-radius:8px;background:#000;margin-top:8px;"></video>
            <div id="uploadProgress2" style="display:none;" class="small text-muted">Uploading...</div>
            <button class="btn btn-gold btn-block" style="margin-top:10px;" onclick="submitUpload()"><?= ui_icon('upload', 16) ?> Upload Video Recitation</button>
        </div>

        <div id="livePanel" style="display:none;">
            <p class="small" style="margin:0 0 12px;">You will be redirected to WhatsApp to schedule a live Muraja'ah assessment with your teacher.</p>
            <div class="form-group">
                <label class="form-label">Preferred Day</label>
                <input class="form-input" type="date" id="liveDay" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Preferred Time</label>
                <input class="form-input" type="time" id="liveTime" required>
            </div>
            <button class="btn btn-gold btn-block" onclick="sendLive()"><?= ui_icon('send', 16) ?> Schedule via WhatsApp</button>
        </div>

        <div id="inpersonPanel" style="display:none;">
            <p class="small" style="margin:0 0 12px;">You will recite <?= $range_hint ?> directly to your teacher during a class or in-person session.</p>
            <div class="alert alert-info" style="margin:0 0 10px;padding:8px 10px;"><?= ui_icon('info', 14) ?> Once submitted, your teacher will confirm and mark this Muraja'ah complete.</div>
            <button class="btn btn-gold btn-block" onclick="submitInPerson()"><?= ui_icon('check-circle', 16) ?> I recited / will recite in person</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php ui_page_end(); ?>

<script>
let currentMode = '';

function openAssistModal() {
    var m = document.getElementById('assistModal');
    if (m) m.classList.add('open');
}

function closeAssistModal() {
    var m = document.getElementById('assistModal');
    if (m) m.classList.remove('open');
}

function sendAssist() {
    var note = document.getElementById('assistNote') ? document.getElementById('assistNote').value : '';
    var fd = new FormData();
    fd.append('action', 'assist');
    fd.append('note', note);
    fd.append('ajax', '1');
    var csrfInput = document.querySelector('[name=csrf_token]');
    if (csrfInput) fd.append('csrf_token', csrfInput.value);

    document.getElementById('assistProgress').style.display = 'block';

    fetch('submit_memorization.php', {method: 'POST', body: fd})
        .then(function(r) { return r.text(); })
        .then(function(res) {
            if (res.trim() === 'OK') { location.reload(); }
            else { alert(res); document.getElementById('assistProgress').style.display = 'none'; }
        })
        .catch(function() { alert('Request failed. Please try again.'); document.getElementById('assistProgress').style.display = 'none'; });
}

function openReciteModal(mode) {
    currentMode = mode;
    document.getElementById('reciteModal').classList.add('open');
    document.getElementById('uploadPanel').style.display = mode === 'upload' ? 'block' : 'none';
    document.getElementById('livePanel').style.display = mode === 'live' ? 'block' : 'none';
    document.getElementById('inpersonPanel').style.display = mode === 'inperson' ? 'block' : 'none';
    if (mode === 'upload') {
        document.getElementById('uploadFile').value = '';
        document.getElementById('uploadVideoPreview').style.display = 'none';
        document.getElementById('uploadProgress2').style.display = 'none';
    }
    document.getElementById('reciteModeText').textContent = mode === 'live'
        ? 'Schedule a live Muraja\'ah assessment via WhatsApp.'
        : (mode === 'upload')
            ? 'Upload a video of this range recited from memory.'
            : (mode === 'inperson')
                ? 'Recite this range to your teacher in person.'
                : 'Choose how you want to recite this range from memory.';
}

function closeReciteModal() {
    document.getElementById('reciteModal').classList.remove('open');
}

document.addEventListener('click', function(e) {
    var m = document.getElementById('reciteModal');
    if (m && m.classList.contains('open') && e.target === m) closeReciteModal();
    var a = document.getElementById('assistModal');
    if (a && a.classList.contains('open') && e.target === a) closeAssistModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeReciteModal(); closeAssistModal(); }
});

function submitInPerson() {
    var fd = new FormData();
    fd.append('action', 'murajaah');
    fd.append('session_type', 'inperson');
    var csrfInput = document.querySelector('[name=csrf_token]');
    if (csrfInput) fd.append('csrf_token', csrfInput.value);

    fetch('submit_memorization.php', {method: 'POST', body: fd})
        .then(function(r) { return r.text(); })
        .then(function(res) {
            if (res.trim() === 'OK') { location.reload(); }
            else { alert(res); }
        })
        .catch(function() { alert('Request failed. Please try again.'); });
}

function submitUpload() {
    var fileInput = document.getElementById('uploadFile');
    if (!fileInput.files || fileInput.files.length === 0) return alert('Please choose a video file first.');
    var file = fileInput.files[0];
    if ((file.type && file.type.indexOf('audio/') === 0) || /\.(mp3|m4a|webm|ogg|wav|aac)$/i.test(file.name)) {
        return alert('Audio files are not accepted for Muraja\'ah. Please upload a VIDEO (.mp4 / .mov / .m4v / .3gp).');
    }
    var fd = new FormData();
    fd.append('action', 'murajaah');
    fd.append('session_type', 'video');
    fd.append('audio', file, file.name);
    var csrfInput = document.querySelector('[name=csrf_token]');
    if (csrfInput) fd.append('csrf_token', csrfInput.value);

    document.getElementById('uploadProgress2').style.display = 'block';
    document.getElementById('uploadProgress2').textContent = 'Uploading ' + file.name + '...';

    fetch('submit_memorization.php', {method: 'POST', body: fd})
        .then(function(r) { return r.text(); })
        .then(function(res) {
            if (res.trim() === 'OK') { location.reload(); }
            else { alert(res); document.getElementById('uploadProgress2').style.display = 'none'; }
        })
        .catch(function() { alert('Upload failed. Please try again.'); document.getElementById('uploadProgress2').style.display = 'none'; });
}

document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'uploadFile') {
        var file = e.target.files[0];
        var preview = document.getElementById('uploadVideoPreview');
        if (!file) return;
        if (file.type && file.type.indexOf('audio/') === 0) {
            alert('Audio files are not accepted for Muraja\'ah. Please select a video file.');
            e.target.value = '';
            if (preview) preview.style.display = 'none';
            return;
        }
        if (preview) { preview.src = URL.createObjectURL(file); preview.style.display = 'block'; }
    }
});

function sendLive() {
    var day = document.getElementById('liveDay').value;
    var time = document.getElementById('liveTime').value;
    if (!day || !time) return alert('Please select a day and time.');

    var csrfInput = document.querySelector('[name=csrf_token]');
    var token = csrfInput ? csrfInput.value : '';

    var fd = new URLSearchParams();
    fd.append('action', 'murajaah');
    fd.append('session_type', 'live');
    fd.append('day', day);
    fd.append('time', time);
    fd.append('csrf_token', token);

    fetch('submit_memorization.php', {method: 'POST', body: fd})
        .then(function(r) { return r.text(); })
        .then(function(res) {
            if (res.trim().indexOf('OK|') === 0) {
                var whatsappUrl = res.replace('OK|', '');
                window.location.href = whatsappUrl;
            } else { alert(res); }
        })
        .catch(function() { alert('Request failed. Please try again.'); });
}
</script>

</body>
</html>