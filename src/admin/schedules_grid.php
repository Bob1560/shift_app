<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();

$monthParam = (string)($_GET['month'] ?? '');
try {
    $month = new DateTime(preg_match('/^\d{4}-\d{2}$/', $monthParam) ? $monthParam . '-01' : lastEditedMonth() . '-01');
} catch (Exception $e) {
    $month = new DateTime(date('Y-m-01'));
}
$monthKey = $month->format('Y-m');
$from = $month->format('Y-m-01');
$to = (clone $month)->modify('first day of next month')->format('Y-m-d');

$locNames = [];
$venueNames = [];
foreach ($db->query('SELECT name FROM locations WHERE is_active = 1 ORDER BY sort_order')->fetchAll() as $l) {
    $locNames[] = $l['name'];
    if (mb_substr($l['name'], -2) === '教室') {
        $venueNames[] = $l['name'];
    }
}

// 時間コマの選択肢（schedules.php の時間帯と同じ。終了は開始の90分後）
$slots = [];
foreach (['10:00', '13:00', '14:45', '15:00'] as $s) {
    $slots[] = $s . '〜' . date('H:i', strtotime("2000-01-01 $s") + 90 * 60);
}

$stmt = $db->prepare('
    SELECT s.date, l.name AS loc, v.name AS venue,
           TIME_FORMAT(s.start_time, "%H:%i") AS st, TIME_FORMAT(s.end_time, "%H:%i") AS en,
           s.required_staff_count AS req, s.note
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN locations v ON s.venue_location_id = v.id
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date, l.sort_order, s.start_time, s.id
');
$stmt->execute([$from, $to]);
$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $rows[] = ['date' => $r['date'], 'loc' => $r['loc'], 'slot' => $r['st'] . '〜' . $r['en'],
               'req' => (int)$r['req'], 'venue' => $r['venue'] ?? '', 'note' => $r['note'] ?? ''];
}

$cfg = [
    'month' => $monthKey,
    'first' => $from,
    'last' => $month->format('Y-m-t'),
    'year' => (int)$month->format('Y'),
    'locs' => $locNames,
    'venues' => $venueNames,
    'slots' => $slots,
    'trial' => '体験',
    'rows' => $rows,
    'csrf' => generateCsrfToken(),
    'api' => BASE_PATH . '/admin/schedules_excel.php',
    'weekdays' => ['日', '月', '火', '水', '木', '金', '土'],
];

$prev = (clone $month)->modify('-1 month')->format('Y-m');
$next = (clone $month)->modify('+1 month')->format('Y-m');
$backUrl = BASE_PATH . '/admin/schedules.php?month=' . $monthKey;

$pageTitle = '表で編集';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
.grid-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 0.5rem; }
.grid-head .page-title { margin: 0; }
.grid-nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.grid-nav a { padding: 6px 12px; border-radius: 6px; background: #edf2f7; color: #2d3748; text-decoration: none; font-weight: bold; font-size: 0.85rem; }
.grid-help { font-size: 0.85rem; color: #4a5568; margin: 0 0 0.75rem; line-height: 1.7; }
.grid-wrap { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: auto; max-height: calc(100vh - 260px); min-height: 260px; }
table.grid { border-collapse: separate; border-spacing: 0; width: 100%; min-width: 860px; font-size: 0.9rem; }
.grid th { position: sticky; top: 0; z-index: 2; background: #667eea; color: #fff; padding: 8px 6px; font-size: 0.82rem; white-space: nowrap; }
.grid td { border-bottom: 1px solid #e2e8f0; border-right: 1px solid #edf2f7; padding: 0; background: #fff; }
.grid tr:nth-child(even) td { background: #fafbff; }
.grid td.rn { width: 38px; text-align: center; color: #a0aec0; font-size: 0.78rem; background: #f7fafc !important; }
.grid input, .grid select { width: 100%; border: 2px solid transparent; background: transparent; padding: 7px 6px; font: inherit; color: #2d3748; border-radius: 0; box-sizing: border-box; }
.grid input:focus, .grid select:focus { outline: none; border-color: #667eea; background: #fff; }
.grid input:disabled, .grid select:disabled { background: #f1f2f4; color: #a0aec0; }
.grid .bad { border-color: #e53e3e; background: #fff5f5; }
.grid tr.rowerr td { background: #fff5f5 !important; }
.grid td.ops { width: 112px; white-space: nowrap; text-align: center; padding: 0 4px; }
.grid td.ops button { border: none; border-radius: 4px; padding: 5px 8px; font-size: 0.78rem; font-weight: bold; cursor: pointer; margin: 0 1px; }
.grid .dup { background: #ebf8ff; color: #2b6cb0; }
.grid .del { background: #fed7d7; color: #c53030; }
.grid-bar { position: sticky; bottom: 0; z-index: 5; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: #fff; border-top: 1px solid #e2e8f0; padding: 10px 12px; margin-top: 0.75rem; border-radius: 8px; box-shadow: 0 -2px 8px rgba(0,0,0,0.06); }
.gbtn { border: none; border-radius: 6px; padding: 9px 16px; font-weight: bold; font-size: 0.9rem; cursor: pointer; background: #edf2f7; color: #2d3748; }
.gbtn.primary { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
.gbtn:disabled { opacity: 0.6; cursor: wait; }
#gridStat { font-size: 0.82rem; color: #718096; }
/* 共通スクリプトは .alert を数秒で消すため、エラー枠には別のクラス名を使う */
.grid-errors { margin-bottom: 0.75rem; padding: 0.75rem 1rem; border-radius: 6px; background: #fed7d7; color: #822727; border: 1px solid #feb2b2; font-size: 0.9rem; }
#gridErrors ul { margin: 6px 0 0; padding-left: 1.2rem; }
.gm-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; display: none; align-items: center; justify-content: center; }
.gm-bg.open { display: flex; }
.gm-box { background: #fff; border-radius: 10px; padding: 1.5rem; width: min(520px, 92vw); max-height: 85vh; overflow-y: auto; }
.gm-box h2 { margin: 0 0 1rem; font-size: 1.1rem; }
.gm-tiles { display: flex; gap: 10px; margin-bottom: 1rem; }
.gm-tile { flex: 1; text-align: center; background: #f7fafc; border-radius: 8px; padding: 10px 4px; }
.gm-tile b { display: block; font-size: 1.5rem; }
.gm-tile span { font-size: 0.78rem; color: #718096; }
.gm-del { background: #fff5f5; border-left: 4px solid #e53e3e; border-radius: 4px; padding: 8px 12px; font-size: 0.85rem; margin-bottom: 1rem; }
.gm-del ul { margin: 4px 0 0; padding-left: 1.1rem; }
.gm-actions { display: flex; gap: 10px; justify-content: flex-end; }
</style>

<div class="grid-head">
    <h1 class="page-title">📋 表で編集 — <?= h($month->format('Y年n月')) ?></h1>
    <div class="grid-nav">
        <a href="?month=<?= h($prev) ?>" data-nav>◀ 前の月</a>
        <a href="?month=<?= h($next) ?>" data-nav>次の月 ▶</a>
        <a href="<?= h($backUrl) ?>" data-nav>日程管理へ戻る</a>
    </div>
</div>
<p class="grid-help">
    Excelのように表で編集して、<b>「保存」</b>を押すと、この月の日程がこの表の内容に置き換わります（行を空にすると、そのコマは削除されます）。<br>
    Excelでコピーした範囲（日付・教室・時間コマ・必要人数・会場・備考の順）を、表のセルに<b>貼り付ける</b>こともできます。
    「複製」は、その行をすぐ下にコピーします。
</p>

<div id="gridErrors" class="grid-errors" hidden></div>

<div class="grid-wrap">
    <table class="grid">
        <thead>
            <tr><th></th><th>日付</th><th>教室</th><th>時間コマ</th><th>必要人数</th><th>会場（体験のみ）</th><th>備考</th><th></th></tr>
        </thead>
        <tbody id="gridBody"></tbody>
    </table>
</div>

<div class="grid-bar">
    <button type="button" class="gbtn" id="btnAddRow">＋ 行を追加</button>
    <button type="button" class="gbtn" id="btnCopy">📋 表をコピー（Excelに貼り付け用）</button>
    <button type="button" class="gbtn primary" id="btnSave">💾 保存（データベースに反映）</button>
    <span id="gridStat"></span>
</div>

<div class="gm-bg" id="confirmModal">
    <div class="gm-box">
        <h2>💾 <?= h($month->format('Y年n月')) ?>の日程を保存します</h2>
        <div class="gm-tiles" id="gmTiles"></div>
        <div class="gm-del" id="gmDel" hidden></div>
        <p style="font-size:0.8rem;color:#718096;margin:0 0 1rem;">保存すると、この月の日程はいまの表の内容に置き換わります。</p>
        <div class="gm-actions">
            <button type="button" class="gbtn" id="gmCancel">キャンセル</button>
            <button type="button" class="gbtn primary" id="gmOk">保存する</button>
        </div>
    </div>
</div>

<script>
(function () {
    const CFG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const COLS = ['date', 'loc', 'slot', 'req', 'venue', 'note'];
    const $ = (s) => document.querySelector(s);
    const body = $('#gridBody');
    let dirty = false;

    const norm = (s) => String(s == null ? '' : s).normalize('NFKC').replace(/　/g, ' ').trim();
    const pad = (n) => String(n).padStart(2, '0');
    const rows = () => Array.from(body.querySelectorAll('tr'));
    const ctl = (tr, col) => tr.querySelector('[data-col="' + col + '"]');

    function ensureOption(sel, val) {
        if (val && !Array.from(sel.options).some((o) => o.value === val)) sel.add(new Option(val, val));
    }
    function fillSelect(sel, items) {
        sel.add(new Option('', ''));
        items.forEach((v) => sel.add(new Option(v, v)));
    }
    function setBad(el, raw) { el.classList.add('bad'); el.title = '認識できませんでした: ' + raw; }
    function clearBad(el) { el.classList.remove('bad'); el.removeAttribute('title'); }
    function markDirty() { dirty = true; updateStat(); }

    function addRow(data, afterTr) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td class="rn"></td>'
            + '<td><input type="date" data-col="date" min="' + CFG.first + '" max="' + CFG.last + '"></td>'
            + '<td><select data-col="loc"></select></td>'
            + '<td><select data-col="slot"></select></td>'
            + '<td><input type="number" data-col="req" min="1" max="10" placeholder="1" inputmode="numeric"></td>'
            + '<td><select data-col="venue" disabled></select></td>'
            + '<td><input type="text" data-col="note" maxlength="200"></td>'
            + '<td class="ops"><button type="button" class="dup">複製</button><button type="button" class="del">✕</button></td>';
        fillSelect(ctl(tr, 'loc'), CFG.locs);
        fillSelect(ctl(tr, 'slot'), CFG.slots);
        fillSelect(ctl(tr, 'venue'), CFG.venues);
        if (afterTr) afterTr.after(tr); else body.appendChild(tr);
        if (data) {
            ensureOption(ctl(tr, 'loc'), data.loc);
            ensureOption(ctl(tr, 'slot'), data.slot);
            ctl(tr, 'date').value = data.date || '';
            ctl(tr, 'loc').value = data.loc || '';
            ctl(tr, 'slot').value = data.slot || '';
            ctl(tr, 'req').value = data.req || '';
            syncVenue(tr);
            if (data.venue && !ctl(tr, 'venue').disabled) ctl(tr, 'venue').value = data.venue;
            ctl(tr, 'note').value = data.note || '';
        }
        renumber();
        return tr;
    }

    function syncVenue(tr) {
        const v = ctl(tr, 'venue'), isTrial = ctl(tr, 'loc').value === CFG.trial;
        v.disabled = !isTrial;
        if (!isTrial) v.value = '';
    }

    function renumber() {
        rows().forEach((tr, i) => { tr.querySelector('.rn').textContent = i + 1; });
        updateStat();
    }

    function getData(tr) {
        const d = {};
        COLS.forEach((c) => { d[c] = ctl(tr, c).value; });
        return d;
    }
    const isBlank = (d) => !d.date && !d.loc && !d.slot;

    function updateStat() {
        const n = rows().filter((tr) => !isBlank(getData(tr))).length;
        $('#gridStat').textContent = n + '件' + (dirty ? '（未保存の変更があります）' : '');
    }

    // ── 貼り付け（Excelでコピーした範囲） ──
    function parseDate(raw) {
        const s = norm(raw);
        let m = s.match(/^(\d{4})[-\/.年](\d{1,2})[-\/.月](\d{1,2})日?(\(.*\))?$/), y, mo, d;
        if (m) { y = +m[1]; mo = +m[2]; d = +m[3]; }
        else if ((m = s.match(/^(\d{1,2})[\/月](\d{1,2})日?(\(.*\))?$/))) { y = CFG.year; mo = +m[1]; d = +m[2]; }
        else return null;
        const dt = new Date(y, mo - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
        const iso = y + '-' + pad(mo) + '-' + pad(d);
        return iso >= CFG.first && iso <= CFG.last ? iso : null;
    }
    function parseSlot(raw) {
        const s = norm(raw).replace(/[〜~–—ー−\-]/g, '~');
        const m = s.match(/^(\d{1,2}):(\d{2})(?:\s*~\s*(\d{1,2}):(\d{2}))?$/);
        if (!m) return null;
        const a = +m[1] * 60 + +m[2], b = m[3] != null ? +m[3] * 60 + +m[4] : a + 90;
        if (+m[1] > 23 || +m[2] > 59 || b <= a || b >= 1440) return null;
        const f = (x) => pad(Math.floor(x / 60)) + ':' + pad(x % 60);
        return f(a) + '〜' + f(b);
    }
    function setCell(tr, col, raw) {
        const el = ctl(tr, col), v = norm(raw);
        clearBad(el);
        if (col === 'date') {
            if (v === '') { el.value = ''; return true; }
            const iso = parseDate(v);
            if (iso) { el.value = iso; return true; }
        } else if (col === 'loc' || col === 'venue') {
            if (col === 'venue' && el.disabled) return true;
            if (v === '') { el.value = ''; if (col === 'loc') syncVenue(tr); return true; }
            const opt = Array.from(el.options).find((o) => o.value === v);
            if (opt) { el.value = v; if (col === 'loc') syncVenue(tr); return true; }
        } else if (col === 'slot') {
            if (v === '') { el.value = ''; return true; }
            const label = parseSlot(v);
            if (label) { ensureOption(el, label); el.value = label; return true; }
        } else if (col === 'req') {
            if (v === '') { el.value = ''; return true; }
            if (/^\d+$/.test(v) && +v >= 1 && +v <= 10) { el.value = v; return true; }
        } else if (col === 'note') {
            if (String(raw).length <= 200) { el.value = String(raw).trim(); return true; }
        }
        setBad(el, raw);
        return false;
    }

    body.addEventListener('paste', function (e) {
        const t = e.target;
        if (!t.matches || !t.matches('[data-col]')) return;
        const text = (e.clipboardData || window.clipboardData).getData('text');
        const trimmed = text.replace(/[\r\n]+$/, '');
        if (!/[\t\r\n]/.test(trimmed) && t.tagName === 'INPUT' && t.type === 'text') return;
        e.preventDefault();
        const lines = trimmed.replace(/\r\n/g, '\n').split('\n').map((l) => l.split('\t'));
        const start = rows().indexOf(t.closest('tr')), c0 = COLS.indexOf(t.dataset.col);
        let bad = 0;
        lines.forEach((cells, li) => {
            const tr = rows()[start + li] || addRow();
            cells.forEach((v, ci) => { if (COLS[c0 + ci] && !setCell(tr, COLS[c0 + ci], v)) bad++; });
        });
        markDirty();
        if (bad) showErrors([{ index: null, message: '貼り付けた内容のうち ' + bad + ' 個のセルを認識できませんでした（赤い枠）。直してから保存してください。' }]);
        else hideErrors();
    });

    // ── 行の操作 ──
    body.addEventListener('click', function (e) {
        const tr = e.target.closest('tr');
        if (!tr) return;
        if (e.target.classList.contains('del')) { tr.remove(); renumber(); markDirty(); }
        if (e.target.classList.contains('dup')) { const n = addRow(getData(tr), tr); markDirty(); const c = ctl(n, 'slot'); if (c) c.focus(); }
    });
    body.addEventListener('change', function (e) {
        const el = e.target, tr = el.closest('tr');
        if (!tr || !el.dataset.col) return;
        clearBad(el);
        if (el.dataset.col === 'loc') syncVenue(tr);
        markDirty();
    });
    body.addEventListener('input', function (e) { if (e.target.dataset && e.target.dataset.col) { clearBad(e.target); markDirty(); } });
    body.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
        e.preventDefault();
        const tr = e.target.closest('tr'), idx = rows().indexOf(tr);
        const next = rows()[idx + 1] || addRow();
        const c = ctl(next, e.target.dataset.col);
        if (c && !c.disabled) c.focus();
    });
    $('#btnAddRow').addEventListener('click', function () { const tr = addRow(); ctl(tr, 'date').focus(); });

    // ── コピー（Excelに貼り付け用） ──
    $('#btnCopy').addEventListener('click', async function () {
        const lines = rows().map(getData).filter((d) => !isBlank(d)).map((d) => [
            d.date.replace(/-/g, '/'), d.loc, d.slot, d.req, d.venue, d.note.replace(/[\t\r\n]+/g, ' '),
        ].join('\t'));
        const text = lines.join('\n');
        try { await navigator.clipboard.writeText(text); }
        catch (err) { const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); }
        $('#gridStat').textContent = lines.length + '件をコピーしました。Excelに貼り付けできます。';
    });

    // ── 保存 ──
    function showErrors(list) {
        const box = $('#gridErrors');
        rows().forEach((tr) => tr.classList.remove('rowerr'));
        let first = null;
        list.forEach((er) => {
            if (er.index != null && rows()[er.index]) { rows()[er.index].classList.add('rowerr'); first = first || rows()[er.index]; }
        });
        box.innerHTML = '<b>保存できません（データはまだ変更されていません）</b><ul></ul>';
        list.slice(0, 30).forEach((er) => {
            const li = document.createElement('li');
            li.textContent = (er.index != null ? (er.index + 1) + '行目: ' : '') + er.message;
            box.querySelector('ul').appendChild(li);
        });
        if (list.length > 30) { const li = document.createElement('li'); li.textContent = '…ほか' + (list.length - 30) + '件'; box.querySelector('ul').appendChild(li); }
        box.hidden = false;
        (first || box).scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function hideErrors() { $('#gridErrors').hidden = true; rows().forEach((tr) => tr.classList.remove('rowerr')); }

    async function post(action) {
        const fd = new FormData();
        fd.append('csrf_token', CFG.csrf);
        fd.append('action', action);
        fd.append('month', CFG.month);
        fd.append('rows', JSON.stringify(rows().map(getData)));
        const r = await fetch(CFG.api, { method: 'POST', body: fd, credentials: 'same-origin' });
        return r.json();
    }

    let pending = null;
    $('#btnSave').addEventListener('click', async function () {
        const btn = this;
        if (document.querySelector('.grid .bad')) {
            showErrors([{ index: null, message: '認識できないセル（赤い枠）があります。直してから保存してください。' }]);
            return;
        }
        hideErrors();
        btn.disabled = true;
        try {
            const res = await post('grid_check');
            if (!res.ok) { showErrors(res.errors || [{ index: null, message: '確認に失敗しました。' }]); return; }
            const t = res.tiles;
            if (!t.insert && !t.update && !t.delete) { dirty = false; updateStat(); $('#gridStat').textContent = '変更はありません（データベースと同じ内容です）。'; return; }
            $('#gmTiles').innerHTML = [['追加', t.insert, '#38a169'], ['変更', t.update, '#3182ce'], ['削除', t.delete, '#e53e3e'], ['変更なし', t.same, '#4a5568']]
                .map((x) => '<div class="gm-tile"><b style="color:' + x[2] + '">' + x[1] + '</b><span>' + x[0] + '</span></div>').join('');
            const del = $('#gmDel');
            if (res.deletes && res.deletes.length) {
                del.hidden = false;
                del.innerHTML = '<b>🗑 削除される日程</b><ul></ul>';
                res.deletes.forEach((d) => { const li = document.createElement('li'); li.textContent = d.label + (d.requests ? '（希望申請 ' + d.requests + '件も消えます）' : ''); del.querySelector('ul').appendChild(li); });
            } else del.hidden = true;
            $('#confirmModal').classList.add('open');
        } catch (err) {
            showErrors([{ index: null, message: '通信に失敗しました。もう一度お試しください。' }]);
        } finally { btn.disabled = false; }
    });
    $('#gmCancel').addEventListener('click', () => $('#confirmModal').classList.remove('open'));
    $('#gmOk').addEventListener('click', async function () {
        this.disabled = true;
        try {
            const res = await post('grid_apply');
            if (res.ok) { dirty = false; location.href = res.redirect; return; }
            $('#confirmModal').classList.remove('open');
            showErrors(res.errors || [{ index: null, message: '保存に失敗しました。' }]);
        } catch (err) {
            $('#confirmModal').classList.remove('open');
            showErrors([{ index: null, message: '通信に失敗しました。もう一度お試しください。' }]);
        } finally { this.disabled = false; }
    });

    // ── 未保存のまま離れるときの確認 ──
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    document.querySelectorAll('[data-nav]').forEach((a) => a.addEventListener('click', function (e) {
        if (dirty && !confirm('保存していない変更があります。移動しますか？')) e.preventDefault();
        else dirty = false;
    }));

    CFG.rows.forEach((r) => addRow(r));
    for (let i = 0; i < 8; i++) addRow();
    dirty = false;
    updateStat();
})();
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
