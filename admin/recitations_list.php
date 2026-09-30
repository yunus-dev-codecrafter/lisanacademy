<?php
require '../config/security/helpers.php';
require_role('admin');
require '../auth/auth_check.php';
require '../config/db.php';

/* ── Build optional UNION branches ────────────────────────────────────────── */
$hafiz_union = '';
if (db_table_exists($conn, 'hafiz_sessions')) {
    $hafiz_union = "
    UNION ALL
    (
        SELECT hs.id AS recitation_id,
               CONVERT(hs.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
               CONVERT(hs.rating USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rating,
               CONVERT(hs.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
               CONVERT(u.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_name,
               CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_email,
               NULL AS lesson_id,
               CONCAT('Hafiz — Page ', hs.page_no) COLLATE utf8mb4_unicode_ci AS surah_name,
               hs.page_no AS from_verse, hs.page_no AS to_verse,
               'Hafiz Revision' COLLATE utf8mb4_unicode_ci AS rec_type,
               hs.submitted_at AS submitted_at
        FROM hafiz_sessions hs
        JOIN users u ON u.id = hs.student_id
    )";
}

$murajaah_union = '';
if (db_table_exists($conn, 'quran_murajaah_sessions')) {
    $murajaah_union = "
    UNION ALL
    (
        SELECT qms.id AS recitation_id,
               CONVERT(qms.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
               NULL AS rating,
               CONVERT(qms.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
               CONVERT(u.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_name,
               CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_email,
               NULL AS lesson_id,
               CONCAT(\"Muraja'ah — Day \", qms.task_day) COLLATE utf8mb4_unicode_ci AS surah_name,
               qms.start_page AS from_verse, qms.end_page AS to_verse,
               \"Muraja'ah\" COLLATE utf8mb4_unicode_ci AS rec_type,
               qms.submitted_at AS submitted_at
        FROM quran_murajaah_sessions qms
        JOIN users u ON u.id = qms.student_id
    )";
}

/* ── Main query with UNIONs ─────────────────────────────────────────────────
   Every string column carries an explicit COLLATE so the UNION never fails
   with "Illegal mix of collations" when legacy tables (latin1/utf8/general_ci)
   are combined with newer utf8mb4 tables or with string literals such as
   'Hafiz — Page …' (the em-dash forces a collation coercion). */
$recitations = $conn->query("
    (
        SELECT sr.id AS recitation_id,
               CONVERT(sr.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
               CONVERT(sr.rating USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rating,
               CONVERT(sr.feedback USING utf8mb4) COLLATE utf8mb4_unicode_ci AS feedback,
               CONVERT(u.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_name,
               CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS student_email,
               l.id AS lesson_id, CONVERT(s.name_en USING utf8mb4) COLLATE utf8mb4_unicode_ci AS surah_name,
               l.from_verse, l.to_verse,
               'Standard' COLLATE utf8mb4_unicode_ci AS rec_type,
               sr.submitted_at AS submitted_at
        FROM student_recitation sr
        JOIN users u ON u.id = sr.student_id
        JOIN lessons l ON l.id = sr.learning_plan_id
        JOIN surahs s ON s.id = l.surah_id
        WHERE sr.student_deleted = 0
    )
    $hafiz_union
    $murajaah_union
    ORDER BY submitted_at DESC, recitation_id DESC
");

$rows = [];
if ($recitations) while ($r = $recitations->fetch_assoc()) $rows[] = $r;

/* Stats (single pass) */
$counts = ['total' => count($rows), 'Standard' => 0, 'Hafiz Revision' => 0, "Muraja'ah" => 0, 'pending' => 0];
foreach ($rows as $r) {
    $t = $r['rec_type'] ?? 'Standard';
    if (isset($counts[$t])) $counts[$t]++;
    if (strtolower((string)$r['status']) === 'pending') $counts['pending']++;
}

$deleted = (int)($_GET['deleted'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Recitations</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= ui_css() ?>
    <style>
        .rec-toolbar{position:sticky;top:0;z-index:20;background:var(--bg, #fff);padding:12px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
        .rec-search{flex:1;min-width:200px;position:relative;}
        .rec-search input{width:100%;padding-left:38px;}
        .rec-search .rec-search-ico{position:absolute;left:12px;top:50%;transform:translateY(-50%);display:flex;color:var(--text-muted);}
        .pill-group{display:flex;gap:6px;flex-wrap:wrap;}
        .pill-btn{border:1px solid var(--border);background:var(--panel-bg,#f9fafb);border-radius:999px;padding:6px 14px;font-size:.82rem;cursor:pointer;font-weight:600;color:var(--text-muted);}
        .pill-btn.active{background:var(--emerald-800,#065f46);border-color:var(--emerald-800,#065f46);color:#fff;}
        .pill-btn.danger-active{background:var(--danger,#dc2626);border-color:var(--danger,#dc2626);color:#fff;}
        .rec-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-top:14px;}
        .rec-card{position:relative;display:flex;flex-direction:column;gap:10px;}
        .rec-card.selected{outline:2px solid var(--danger,#dc2626);}
        .rec-top{display:flex;align-items:center;gap:10px;}
        .rec-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.05rem;background:linear-gradient(135deg,var(--emerald-700,#047857),var(--gold,#c9a24b));color:#fff;flex-shrink:0;}
        .rec-who{flex:1;min-width:0;}
        .rec-who strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .rec-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
        .rec-range{font-size:.85rem;color:var(--text-muted);}
        .rec-feedback{font-size:.83rem;background:var(--panel-bg,#f9fafb);border:1px solid var(--border);border-radius:8px;padding:8px 10px;max-height:88px;overflow:auto;}
        .rec-foot{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;border-top:1px solid var(--border);padding-top:10px;}
        .rec-check{position:absolute;top:10px;right:10px;width:20px;height:20px;accent-color:var(--danger,#dc2626);cursor:pointer;}
        .bulk-bar{display:none;align-items:center;gap:10px;flex-wrap:wrap;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:10px 14px;margin-top:12px;}
        .bulk-bar.show{display:flex;}
        #noResults{display:none;text-align:center;padding:34px 0;color:var(--text-muted);}
    </style>
</head>
<?php ui_page_start('admin', 'recitations', 'Recitations', 'Review'); ?>
<?= csrf_field() ?>

<div class="page-hero animate-rise">
    <h1>Student Recitations</h1>
    <p>Every submission in one place — standard, Hafiz revision, and memorizer Muraja'ah — with delete controls on each.</p>
</div>

<?php if ($deleted > 0): ?>
    <div class="alert alert-success animate-rise">
        <?= ui_icon('check-circle', 16) ?>
        <span style="flex:1;"><strong><?= $deleted ?></strong> recitation<?= $deleted === 1 ? '' : 's' ?> deleted (files removed).</span>
    </div>
<?php endif; ?>

<div class="stat-grid animate-rise d1">
    <div class="stat-card stat-green">
        <span class="stat-ico"><?= ui_icon('notes', 22) ?></span>
        <span class="stat-label">Total</span>
        <span class="stat-value"><?= $counts['total'] ?></span>
        <span class="stat-sub"><?= $counts['pending'] ?> awaiting review</span>
    </div>
    <div class="stat-card">
        <span class="stat-ico"><?= ui_icon('book', 22) ?></span>
        <span class="stat-label">Standard</span>
        <span class="stat-value"><?= $counts['Standard'] ?></span>
        <span class="stat-sub">Learner recitations</span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-ico"><?= ui_icon('book-open', 22) ?></span>
        <span class="stat-label">Hafiz Revision</span>
        <span class="stat-value"><?= $counts['Hafiz Revision'] ?></span>
        <span class="stat-sub">Page recitations</span>
    </div>
    <div class="stat-card stat-gold">
        <span class="stat-ico"><?= ui_icon('star', 22) ?></span>
        <span class="stat-label">Muraja'ah</span>
        <span class="stat-value"><?= $counts["Muraja'ah"] ?></span>
        <span class="stat-sub">Memorizer reviews</span>
    </div>
</div>

<?php if (empty($rows)): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('notes', 40) ?></div>
        <div class="empty-title">No recitation requests found</div>
    </div>
<?php else: ?>

<div class="card animate-rise d1" style="margin-top:14px;">
    <div class="rec-toolbar" style="position:static;padding:0;">
        <div class="rec-search">
            <span class="rec-search-ico"><?= ui_icon('search', 16) ?></span>
            <input class="form-input" type="search" id="recSearch" placeholder="Search student, email, or surah…" autocomplete="off">
        </div>
        <div class="pill-group" id="typePills">
            <button type="button" class="pill-btn active" data-type="all">All</button>
            <button type="button" class="pill-btn" data-type="Standard">Standard</button>
            <button type="button" class="pill-btn" data-type="Hafiz Revision">Hafiz</button>
            <button type="button" class="pill-btn" data-type="Muraja'ah">Muraja'ah</button>
        </div>
        <div class="pill-group" id="statusPills">
            <button type="button" class="pill-btn active" data-status="all">Any status</button>
            <button type="button" class="pill-btn" data-status="pending">Pending</button>
            <button type="button" class="pill-btn" data-status="accepted">Accepted</button>
            <button type="button" class="pill-btn" data-status="rejected">Rejected</button>
        </div>
        <label class="small text-muted" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="selectAll" style="width:16px;height:16px;accent-color:var(--danger,#dc2626);"> Select all
        </label>
        <button type="button" class="btn btn-sm btn-danger" onclick="openDeleteAllModal()"><?= ui_icon('trash', 14) ?> Delete All…</button>
    </div>
    <div class="bulk-bar" id="bulkBar">
        <strong id="bulkCount">0 selected</strong>
        <button type="button" class="btn btn-sm btn-danger" onclick="submitSelected()"><?= ui_icon('trash', 14) ?> Delete Selected</button>
        <button type="button" class="btn btn-sm btn-ghost" onclick="clearSelection()">Clear</button>
    </div>
</div>

<form method="POST" action="delete_recitation.php" id="bulkForm" onsubmit="return confirmBulk(event);">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_selected">
    <input type="hidden" name="return_to" value="recitations_list">
    <div class="rec-grid animate-rise d2" id="recGrid">
        <?php foreach ($rows as $r):
            $type_label = $r['rec_type'] ?? 'Standard';
            $type_class = match ($type_label) {
                'Hafiz Revision' => 'badge-blue',
                "Muraja'ah"      => 'badge-gold',
                default          => 'badge-grey',
            };
            $st = strtolower((string)$r['status']);
            $pill = in_array($st, ['accepted', 'passed'], true) ? 'badge-green'
                : (in_array($st, ['rejected', 'failed'], true) ? 'badge-red' : 'badge-gold');
            $initial = function_exists('mb_substr') ? mb_substr((string)$r['student_name'], 0, 1) : substr((string)$r['student_name'], 0, 1);
            $range = $type_label === 'Hafiz Revision'
                ? 'Page ' . (int)$r['from_verse']
                : ($type_label === "Muraja'ah"
                    ? 'Pages ' . (int)$r['from_verse'] . '–' . (int)$r['to_verse']
                    : 'Verses ' . (int)$r['from_verse'] . '–' . (int)$r['to_verse']);
            $value = $type_label . ':' . (int)$r['recitation_id'];
            $submitted = !empty($r['submitted_at']) ? date('d M Y, g:i A', strtotime($r['submitted_at'])) : '—';
        ?>
        <div class="card rec-card"
             data-type="<?= htmlspecialchars($type_label) ?>"
             data-status="<?= htmlspecialchars($st) ?>"
             data-search="<?= htmlspecialchars(strtolower($r['student_name'] . ' ' . $r['student_email'] . ' ' . $r['surah_name'])) ?>">
            <input class="rec-check" type="checkbox" name="items[]" value="<?= htmlspecialchars($value) ?>" onchange="updateBulk()" title="Select for bulk delete">
            <div class="rec-top">
                <div class="rec-avatar"><?= htmlspecialchars(strtoupper($initial !== '' ? $initial : 'U')) ?></div>
                <div class="rec-who">
                    <strong><?= htmlspecialchars($r['student_name']) ?></strong>
                    <span class="small text-muted"><?= htmlspecialchars($r['student_email']) ?></span>
                </div>
            </div>
            <div class="rec-meta">
                <span class="badge <?= $type_class ?>"><?= htmlspecialchars($type_label) ?></span>
                <span class="badge <?= $pill ?>"><?= htmlspecialchars($r['status']) ?></span>
                <span class="badge badge-grey">#<?= (int)$r['recitation_id'] ?></span>
            </div>
            <div>
                <strong><?= htmlspecialchars($r['surah_name']) ?></strong>
                <div class="rec-range"><?= htmlspecialchars($range) ?> · <?= htmlspecialchars($submitted) ?></div>
            </div>
            <?php if (!empty($r['rating'])): ?>
                <div class="small">Rating: <strong><?= htmlspecialchars($r['rating']) ?></strong></div>
            <?php endif; ?>
            <?php if (trim((string)$r['feedback']) !== ''): ?>
                <div class="rec-feedback"><?= nl2br(htmlspecialchars((string)$r['feedback'])) ?></div>
            <?php endif; ?>
            <div class="rec-foot">
                <span class="small text-muted"><?= $type_label === 'Standard' ? 'Soft-delete (recoverable via DB)' : 'Permanent delete + file cleanup' ?></span>
                <button type="button" class="btn btn-sm btn-danger" onclick="deleteOne(this)" data-type="<?= htmlspecialchars($type_label) ?>" data-id="<?= (int)$r['recitation_id'] ?>"><?= ui_icon('trash', 14) ?> Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div id="noResults" class="card">No recitations match your filters.</div>
</form>

<!-- Single-delete helper form -->
<form method="POST" action="delete_recitation.php" id="singleDeleteForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="recitations_list">
    <input type="hidden" name="rec_id" id="singleRecId" value="">
    <input type="hidden" name="rec_type" id="singleRecType" value="">
</form>

<!-- Delete-all modal -->
<div class="modal" id="deleteAllModal">
    <div class="modal-content" style="max-width:460px;">
        <span class="modal-close" onclick="closeDeleteAllModal()">&times;</span>
        <h3 style="margin:0 0 6px;color:var(--danger);">Delete ALL Recitations?</h3>
        <p class="small text-muted" style="margin:0 0 14px;">This permanently removes files and (for Hafiz/Muraja'ah) database rows within the scope below. Standard recitations are soft-deleted. This cannot be undone.</p>
        <form method="POST" action="delete_recitation.php" onsubmit="return confirm('Last chance — really delete ALL recitations in this scope?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_all">
            <input type="hidden" name="return_to" value="recitations_list">
            <div class="form-group">
                <label class="form-label">Type scope</label>
                <select class="form-input" name="scope_type">
                    <option value="all">All types</option>
                    <option value="standard">Standard only</option>
                    <option value="hafiz">Hafiz Revision only</option>
                    <option value="murajaah">Muraja'ah only</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status scope</label>
                <select class="form-input" name="scope_status">
                    <option value="all">Any status</option>
                    <option value="pending">Pending only</option>
                    <option value="accepted">Accepted / Passed only</option>
                    <option value="rejected">Rejected / Failed only</option>
                </select>
            </div>
            <button class="btn btn-danger btn-block" type="submit"><?= ui_icon('trash', 16) ?> Delete All In Scope</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php ui_page_end(); ?>

<script>
var activeType = 'all';
var activeStatus = 'all';

function bindPills(id, attr, cb) {
    var wrap = document.getElementById(id);
    if (!wrap) return;
    wrap.querySelectorAll('.pill-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            wrap.querySelectorAll('.pill-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            cb(btn.getAttribute(attr));
            applyFilters();
        });
    });
}
bindPills('typePills', 'data-type', function (v) { activeType = v; });
bindPills('statusPills', 'data-status', function (v) { activeStatus = v; });

var searchInput = document.getElementById('recSearch');
if (searchInput) searchInput.addEventListener('input', applyFilters);

function normStatus(s) {
    s = (s || '').toLowerCase();
    if (s === 'passed') return 'accepted';
    if (s === 'failed') return 'rejected';
    return s;
}

function applyFilters() {
    var q = searchInput ? searchInput.value.toLowerCase().trim() : '';
    var cards = document.querySelectorAll('#recGrid .rec-card');
    var visible = 0;
    cards.forEach(function (card) {
        var okType = activeType === 'all' || card.getAttribute('data-type') === activeType;
        var okStatus = activeStatus === 'all' || normStatus(card.getAttribute('data-status')) === activeStatus;
        var hay = card.getAttribute('data-search') || '';
        var okSearch = q === '' || hay.indexOf(q) !== -1;
        var show = okType && okStatus && okSearch;
        card.style.display = show ? '' : 'none';
        if (!show) {
            var cb = card.querySelector('.rec-check');
            if (cb && cb.checked) { cb.checked = false; card.classList.remove('selected'); }
        } else visible++;
    });
    document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
    updateBulk();
}

function updateBulk() {
    var checked = document.querySelectorAll('#recGrid .rec-check:checked');
    var bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked.length + ' selected';
    bar.classList.toggle('show', checked.length > 0);
    document.querySelectorAll('#recGrid .rec-card').forEach(function (card) {
        var cb = card.querySelector('.rec-check');
        card.classList.toggle('selected', !!(cb && cb.checked));
    });
    var all = document.getElementById('selectAll');
    var visible = Array.prototype.filter.call(document.querySelectorAll('#recGrid .rec-card'), function (c) { return c.style.display !== 'none'; });
    if (all) all.checked = visible.length > 0 && visible.every(function (c) { var cb = c.querySelector('.rec-check'); return cb && cb.checked; });
}

var selectAll = document.getElementById('selectAll');
if (selectAll) selectAll.addEventListener('change', function () {
    document.querySelectorAll('#recGrid .rec-card').forEach(function (card) {
        if (card.style.display === 'none') return;
        var cb = card.querySelector('.rec-check');
        if (cb) cb.checked = selectAll.checked;
    });
    updateBulk();
});

function clearSelection() {
    document.querySelectorAll('#recGrid .rec-check:checked').forEach(function (cb) { cb.checked = false; });
    var all = document.getElementById('selectAll');
    if (all) all.checked = false;
    updateBulk();
}

function submitSelected() {
    var n = document.querySelectorAll('#recGrid .rec-check:checked').length;
    if (n === 0) return alert('Select at least one recitation first.');
    if (!confirm('Delete ' + n + ' selected recitation' + (n === 1 ? '' : 's') + '? Files will be removed.')) return;
    document.getElementById('bulkForm').submit();
}

function confirmBulk(e) {
    var n = document.querySelectorAll('#recGrid .rec-check:checked').length;
    if (n === 0) { e.preventDefault(); alert('Select at least one recitation first.'); return false; }
    return true;
}

function deleteOne(btn) {
    var id = btn.getAttribute('data-id');
    var type = btn.getAttribute('data-type');
    if (!confirm('Delete this ' + type + ' recitation #' + id + '? Its audio file will also be removed.')) return;
    document.getElementById('singleRecId').value = id;
    document.getElementById('singleRecType').value = type;
    document.getElementById('singleDeleteForm').submit();
}

function openDeleteAllModal() {
    document.getElementById('deleteAllModal').classList.add('open');
}
function closeDeleteAllModal() {
    document.getElementById('deleteAllModal').classList.remove('open');
}
document.addEventListener('click', function (e) {
    var m = document.getElementById('deleteAllModal');
    if (m && m.classList.contains('open') && e.target === m) closeDeleteAllModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDeleteAllModal();
});
</script>

</body>
</html>
