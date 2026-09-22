<?php
/**
 * Automate validation of the Qur'an Memorization engine schedule.
 *
 * Runs a pure, DB-independent simulation using mem_schedule_slots() and
 * mem_compute_task() (deterministic functions) and asserts the acceptance
 * numbers: 604 memorization days, 169 Muraja'ah days, 2 celebration days,
 * 775 total, half milestone at page 304, and correct split-day transitions.
 *
 * Visit as admin: /config/mem_engine_self_test.php
 */
require_once __DIR__ . '/../auth/auth_check.php';

require_role('admin');

$results = [];
$fail = function (string $name, string $detail, $got, $expected) use (&$results) {
    $results[] = ['name' => $name, 'ok' => false, 'detail' => $detail, 'got' => $got, 'expected' => $expected];
};
$pass = function (string $name, string $detail, $got = '') use (&$results) {
    $results[] = ['name' => $name, 'ok' => true, 'detail' => $detail, 'got' => $got, 'expected' => $got];
};

/* ---------- 1. Build the schedule twice for determinism ---------- */
$slots1 = mem_schedule_slots();
$slots2 = mem_schedule_slots();
if (sha1(serialize($slots1)) === sha1(serialize($slots2))) {
    $pass('Deterministic schedule', 'Two builds are byte-identical');
} else {
    $fail('Deterministic schedule', 'Two builds differ', sha1(serialize($slots1)), sha1(serialize($slots2)));
}

/* ---------- 2. Day counts ---------- */
$total = count($slots1);
$mem_days = 0;
$rev_days = 0;
$celeb_days = 0;
foreach ($slots1 as $slot) {
    if ($slot['task_type'] === 'memorization') $mem_days++;
    if ($slot['task_type'] === 'murajaah') $rev_days++;
    if ($slot['task_type'] === 'celebration') $celeb_days++;
}
$check = function ($name, $got, $expected) use (&$fail, &$pass) {
    if ((int)$got === (int)$expected) $pass($name, "got $got");
    else $fail($name, 'mismatch', $got, $expected);
};
$check('Total scheduled days (775)', $total, 775);
$check('Memorization days (604)', $mem_days, 604);
$check('Muraja\'ah / revision days (169)', $rev_days, 169);
$check('Celebration days (2)', $celeb_days, 2);
if ($mem_days + $rev_days + $celeb_days === $total) {
    $pass('Day arithmetic sums to total', "$mem_days + $rev_days + $celeb_days = $total");
} else {
    $fail('Day arithmetic sums to total', 'sum mismatch', $mem_days + $rev_days + $celeb_days, $total);
}

/* ---------- 3. Every page 1..604 memorized exactly once ---------- */
$memorized_pages = [];
foreach ($slots1 as $slot) {
    if ($slot['task_type'] === 'memorization') $memorized_pages[] = (int)$slot['start'];
}
sort($memorized_pages, SORT_NUMERIC);
if ($memorized_pages === range(1, 604)) {
    $pass('Pages 1–604 each have exactly one memorization task', 'range is sequential 1..604');
} else {
    $missing = array_diff(range(1, 604), $memorized_pages);
    $dupes = array_values(array_diff_assoc($memorized_pages, array_unique($memorized_pages)));
    $fail('Pages 1–604 each have exactly one memorization task',
        'missing: ' . implode(',', array_slice($missing, 0, 10)) . '; dupes: ' . implode(',', array_slice($dupes, 0, 10)),
        count($memorized_pages), 604);
}

/* ---------- 4. Half milestone at page 304 ---------- */
$half_slot = null;
$complete_slot = null;
foreach ($slots1 as $slot) {
    if ($slot['celebrating'] === 'half') $half_slot = $slot;
    if ($slot['celebrating'] === 'complete') $complete_slot = $slot;
}
$revfinal_first = null;
foreach ($slots1 as $slot) {
    if ($slot['seg'] === 'revfinal' && $slot['seg_day'] === 1) $revfinal_first = $slot;
}
if ($half_slot) {
    $pass('Half-Qur\'an celebration day exists', 'day ' . (int)$half_slot['day'] . ' with celebrating=half');
} else {
    $fail('Half-Qur\'an celebration day exists', 'not found', 'none', 'celebrating=half');
}
if ($complete_slot) {
    $pass('Completion celebration day exists', 'day ' . (int)$complete_slot['day'] . ' with celebrating=complete');
} else {
    $fail('Completion celebration day exists', 'not found', 'none', 'celebrating=complete');
}
$half_last_memo = 0;
foreach ($slots1 as $slot) {
    if ($slot['task_type'] === 'memorization' && $slot['start'] <= 304) $half_last_memo = max($half_last_memo, (int)$slot['start']);
}
$check('Half milestone boundary page is 304', $half_last_memo, 304);
$first_half_memo = 0;
foreach ($slots1 as $slot) {
    if ($slot['task_type'] === 'memorization' && $slot['start'] <= 304) $first_half_memo++;
}
$check('Exactly 304 pages in the first half', $first_half_memo, 304);
if ($revfinal_first && (int)$revfinal_first['start'] === 305) {
    $pass('Final Muraja\'ah starts at page 305', 'revfinal day 1 = pages 305–' . (int)$revfinal_first['end']);
} else {
    $fail('Final Muraja\'ah starts at page 305', 'unexpected start', $revfinal_first['start'] ?? 'none', 305);
}

/* ---------- 5. Section 3 (201–304) exact 7-day schedule ---------- */
$sec3 = [];
foreach ($slots1 as $slot) {
    if ($slot['seg'] === 'revhalf' && $slot['seg_day'] >= 1 && $slot['seg_day'] <= 7) $sec3[$slot['seg_day']] = $slot;
}
$expected_sec3 = [
    1 => [201, 225], 2 => [226, 250], 3 => [251, 275], 4 => [276, 304],
    5 => [201, 250], 6 => [251, 304], 7 => [201, 304],
];
$sec3_ok = true;
$sec3_detail = [];
foreach ($expected_sec3 as $d => $range) {
    $s = $sec3[$d] ?? null;
    $ok = $s && (int)$s['start'] === $range[0] && (int)$s['end'] === $range[1];
    if (!$ok) $sec3_ok = false;
    $sec3_detail[] = "Day $d: " . ($s ? (int)$s['start'] . '–' . (int)$s['end'] : 'MISSING');
}
$page_nos = [1 => 25, 2 => 25, 3 => 25, 4 => 29, 5 => 50, 6 => 54, 7 => 104];
foreach ($page_nos as $d => $n) {
    $s = $sec3[$d] ?? null;
    $span = $s ? (int)$s['end'] - (int)$s['start'] + 1 : 0;
    if ($span !== $n) $sec3_ok = false;
}
if ($sec3_ok) {
    $pass('Section 3 executes the exact 7-day schedule (25/25/25/29/50/54/104)', implode(' · ', $sec3_detail));
} else {
    $fail('Section 3 executes the exact 7-day schedule (25/25/25/29/50/54/104)', implode(' · ', $sec3_detail), 'mismatch', '25/25/25/29/50/54/104');
}

/* ---------- 6. Split days transition morning → evening → next day ---------- */
$split_days = [];
foreach ($slots1 as $day => $slot) {
    if (count($slot['sessions']) === 2) $split_days[] = $day;
}
$check('Split (morning/evening) revision days present', count($split_days), 12);
$split_ok = true;
$split_detail = [];
foreach ($split_days as $d) {
    $slot = $slots1[$d];
    $morning = $slot['sessions'][0];
    $evening = $slot['sessions'][1];
    $state_m = ['student_id' => 0, 'day_count' => $d - 1, 'current_session' => 'morning', 'pages_memorized' => 0, 'milestone' => 'none'];
    $state_e = ['student_id' => 0, 'day_count' => $d - 1, 'current_session' => 'evening', 'pages_memorized' => 0, 'milestone' => 'none'];
    $tm = mem_compute_task($conn, $state_m);
    $te = mem_compute_task($conn, $state_e);
    $ok_m = $tm['day_session'] === 'morning' && $tm['start_page'] === $morning['start'] && $tm['end_page'] === $morning['end'];
    $ok_e = $te['day_session'] === 'evening' && $te['start_page'] === $evening['start'] && $te['end_page'] === $evening['end'];
    if (!$ok_m || !$ok_e) $split_ok = false;
    $split_detail[] = "Day $d: morning {$morning['start']}–{$morning['end']} / evening {$evening['start']}–{$evening['end']}";
}
if ($split_ok) {
    $pass('All split days resolve morning→evening sessions correctly', $split_detail ? implode(' · ', $split_detail) : '');
} else {
    $fail('All split days resolve morning→evening sessions correctly', implode(' · ', $split_detail), 'mismatch', 'wordy');
}

/* ---------- 7. Task after a split day advances to a full session ---------- */
$first_split = $split_days[0] ?? null;
if ($first_split) {
    $next_slot = $slots1[$first_split + 1];
    $state_next = ['student_id' => 0, 'day_count' => $first_split, 'current_session' => 'full', 'pages_memorized' => 0, 'milestone' => 'none'];
    $tn = mem_compute_task($conn, $state_next);
    $ok_next = (int)$tn['day_number'] === ($first_split + 1)
        && (int)$tn['start_page'] === (int)$next_slot['start']
        && (int)$tn['end_page'] === (int)$next_slot['end'];
    if ($ok_next) {
        $pass('Day after a split session advances by exactly one day',
            'Day ' . ($first_split + 1) . ' = ' . (int)$next_slot['start'] . '–' . (int)$next_slot['end'] . ' (' . $next_slot['seg'] . ' seg_day ' . $next_slot['seg_day'] . ')');
    } else {
        $fail('Day after a split session advances by exactly one day', 'unexpected',
            'day ' . (int)$tn['day_number'] . ' -> ' . (int)$tn['start_page'] . '–' . (int)$tn['end_page'],
            'day ' . ($first_split + 1) . ' -> ' . (int)$next_slot['start'] . '–' . (int)$next_slot['end']);
    }
}

/* ---------- 8. Block 12 (276–304, 29 pages) is strictly 29 memo + 5 murajaah ---------- */
$b12_memo = 0;
$b12_mraj = 0;
foreach ($slots1 as $slot) {
    if ($slot['block'] === 12) {
        if ($slot['task_type'] === 'memorization') $b12_memo++;
        if ($slot['task_type'] === 'murajaah') $b12_mraj++;
    }
}
$check('Block 12 is 29 memorization pages (276–304)', $b12_memo, 29);
$check('Block 12 has 5 Muraja\'ah days (34-day block)', $b12_mraj, 5);

/* ---------- 9. Every Muraja'ah range is inside 1..604 ---------- */
$range_ok = true;
$bad_ranges = [];
foreach ($slots1 as $slot) {
    if ($slot['task_type'] !== 'murajaah') continue;
    if ((int)$slot['start'] < 1 || (int)$slot['end'] > 604 || (int)$slot['start'] > (int)$slot['end']) {
        $range_ok = false;
        $bad_ranges[] = $slot['day'] . ':' . $slot['start'] . '-' . $slot['end'];
    }
}
if ($range_ok) $pass('All Muraja\'ah ranges are within pages 1–604', count($bad_ranges) . ' invalid');
else $fail('All Muraja\'ah ranges are within pages 1–604', implode(', ', $bad_ranges), count($bad_ranges), 0);

$passed = count(array_filter($results, function ($r) { return $r['ok']; }));
?>
<!DOCTYPE html>
<html>
<head>
<title>Memorization Engine Self-Test</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Engine Self-Test', 'Memorization'); ?>

<div class="page-hero animate-rise">
    <h1>Memorization Engine Self-Test</h1>
    <p><?= $passed ?> / <?= count($results) ?> assertions passed</p>
</div>

<div class="stat-grid animate-rise" style="margin-bottom:16px;">
    <div class="stat-card stat-green">
        <span class="stat-ico"><?= ui_icon('check-circle', 22) ?></span>
        <span class="stat-label">Passed</span>
        <span class="stat-value"><?= $passed ?></span>
    </div>
    <div class="stat-card stat-red">
        <span class="stat-ico"><?= ui_icon('close', 22) ?></span>
        <span class="stat-label">Failed</span>
        <span class="stat-value"><?= count($results) - $passed ?></span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-ico"><?= ui_icon('grid', 22) ?></span>
        <span class="stat-label">Total Days</span>
        <span class="stat-value"><?= $total ?></span>
        <span class="stat-sub">604 memo · 169 Muraja'ah · 2 celebrations</span>
    </div>
</div>

<?php foreach ($results as $r): ?>
    <div class="panel" style="margin:0 0 8px;padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="badge <?= $r['ok'] ? 'badge-green' : 'badge-red' ?>"><?= $r['ok'] ? 'PASS' : 'FAIL' ?></span>
        <strong style="flex:1;min-width:200px;"><?= htmlspecialchars($r['name']) ?></strong>
        <span class="small text-muted"><?= htmlspecialchars($r['detail']) ?></span>
    </div>
<?php endforeach; ?>

<?php ui_page_end(); ?>
</body>
</html>