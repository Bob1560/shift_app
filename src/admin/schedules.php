<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();

$month = $_GET['month'] ?? date('Y-m');
try {
    $currentDate = new DateTime($month . '-01');
} catch (Exception $e) {
    $currentDate = new DateTime(date('Y-m') . '-01');
}

$prevMonth = (clone $currentDate)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $currentDate)->modify('+1 month')->format('Y-m');

$firstDay    = (int)$currentDate->format('w');
$daysInMonth = (int)$currentDate->format('t');
$endDate     = (clone $currentDate)->modify('+1 month');

$stmt = $db->prepare('
    SELECT s.*, l.name AS location_name
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date, l.sort_order, s.start_time
');
$stmt->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$allSchedules = $stmt->fetchAll();

// 日付→教室→コマ のネスト構造に整理
$schedulesByDate = [];
foreach ($allSchedules as $s) {
    $d   = $s['date'];
    $loc = $s['location_name'];
    if (!isset($schedulesByDate[$d])) $schedulesByDate[$d] = [];
    if (!isset($schedulesByDate[$d][$loc])) $schedulesByDate[$d][$loc] = [];
    $schedulesByDate[$d][$loc][] = $s;
}

$locations = $db->query('SELECT * FROM locations WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

$pageTitle = 'コマ管理';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.page-wrap { display: grid; grid-template-columns: 1fr 420px; gap: 1.5rem; margin-bottom: 2rem; }
.calendar-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; }
.cal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
.cal-title { font-size: 1.3rem; font-weight: bold; margin: 0; }
.nav-btn { background: rgba(255,255,255,0.2); color: white; border: none; padding: 0.4rem 0.8rem; cursor: pointer; border-radius: 4px; font-weight: bold; }
.nav-btn:hover { background: rgba(255,255,255,0.35); }
.weekdays { display: grid; grid-template-columns: repeat(7, 1fr); background: #f0f4ff; border-bottom: 1px solid #e2e8f0; }
.weekday { padding: 0.5rem; text-align: center; font-weight: bold; font-size: 0.8rem; color: #667eea; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e2e8f0; }
.cal-cell { background: white; padding: 6px; min-height: 90px; cursor: pointer; transition: background 0.15s; }
.cal-cell:hover { background: #f8faff; }
.cal-cell.other-month { background: #fafbfc; opacity: 0.45; cursor: default; }
.cal-cell.today { background: #fef9e7; }
.cell-day { font-size: 0.8rem; font-weight: bold; color: #2d3748; margin-bottom: 3px; }
.cell-day.sun { color: #e53e3e; }
.cell-day.sat { color: #3182ce; }
/* 教室名バッジ（重複なし） */
.loc-chip {
    display: block;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 0.62rem;
    font-weight: bold;
    padding: 2px 5px;
    border-radius: 3px;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
}
.loc-chip:hover { opacity: 0.85; }
/* サイドパネル */
.side-panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.25rem; position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow-y: auto; }
.panel-empty { text-align: center; color: #a0aec0; padding: 3rem 1rem; }
.panel-date-title { font-size: 1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; }
.loc-section { margin-bottom: 1rem; }
.loc-label { font-weight: bold; color: #667eea; font-size: 0.85rem; margin-bottom: 4px; }
.time-row { display: flex; align-items: center; justify-content: space-between; background: #f8faff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 6px 10px; margin-bottom: 4px; font-size: 0.85rem; }
.time-row .t { font-weight: bold; color: #2d3748; }
.del-btn { background: #f56565; color: white; border: none; padding: 2px 8px; border-radius: 3px; cursor: pointer; font-size: 0.75rem; }
.del-btn:hover { background: #e53e3e; }
.add-btn { width: 100%; padding: 0.65rem; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 0.75rem; }
.add-btn:hover { opacity: 0.9; }
/* モーダル */
.modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.modal-bg.open { display: flex; }
.modal-box { background: white; padding: 2rem; border-radius: 10px; max-width: 480px; width: 90%; max-height: 80vh; overflow-y: auto; }
.modal-title { font-size: 1.1rem; font-weight: bold; color: #2d3748; margin-bottom: 1.25rem; }
.modal-close { float: right; background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #a0aec0; line-height: 1; }
.form-label { display: block; font-weight: bold; font-size: 0.85rem; color: #2d3748; margin-bottom: 6px; }
.checks { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.check-item { display: flex; align-items: center; gap: 4px; font-size: 0.88rem; }
.form-input { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 6px; font-size: 0.9rem; }
.form-group { margin-bottom: 1rem; }
.submit-btn { width: 100%; padding: 0.75rem; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 0.5rem; }
.submit-btn:hover { opacity: 0.9; }
@media (max-width: 1024px) { .page-wrap { grid-template-columns: 1fr; } }
</style>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">📋 コマ管理</h1>
</div>

<div class="page-wrap">
    <!-- カレンダー -->
    <div class="calendar-card">
        <div class="cal-header">
            <div class="cal-title">📅 <?= h($currentDate->format('Y年n月')) ?></div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <button class="nav-btn" onclick="location.href='?month=<?= h($prevMonth) ?>'">◀</button>
                <button class="nav-btn" onclick="location.href='?month=<?= h($nextMonth) ?>'">▶</button>
            </div>
        </div>

        <div class="weekdays">
            <?php foreach (['日','月','火','水','木','金','土'] as $i => $d): ?>
                <div class="weekday" style="<?= $i===0?'color:#e53e3e':($i===6?'color:#3182ce':'') ?>"><?= $d ?></div>
            <?php endforeach; ?>
        </div>

        <div class="cal-grid">
            <?php for ($i = 0; $i < $firstDay; $i++): ?>
                <div class="cal-cell other-month"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $dow     = ($firstDay + $day - 1) % 7;
                $dateStr = $currentDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isToday = ($dateStr === date('Y-m-d'));
                $dayLocs = $schedulesByDate[$dateStr] ?? [];
            ?>
                <div class="cal-cell <?= $isToday ? 'today' : '' ?>" onclick="selectDate('<?= h($dateStr) ?>')">
                    <div class="cell-day <?= $dow===0?'sun':($dow===6?'sat':'') ?>"><?= $day ?></div>
                    <?php foreach ($dayLocs as $locName => $slots): ?>
                        <span class="loc-chip"
                              onclick="event.stopPropagation(); selectDate('<?= h($dateStr) ?>', '<?= h($locName) ?>')"
                              title="<?= h($locName) ?>"><?= h(mb_substr($locName, 0, 5)) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- サイドパネル -->
    <div class="side-panel" id="sidePanel">
        <div class="panel-empty">
            <p>📍 日付またはコマをクリック</p>
        </div>
    </div>
</div>

<!-- コマ追加モーダル -->
<div id="modal" class="modal-bg">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">×</button>
        <div class="modal-title">📅 <span id="modalDateLabel"></span> にコマを追加</div>
        <form onsubmit="addSchedules(event)">
            <input type="hidden" id="csrfToken" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" id="dateInput">

            <div class="form-group">
                <label class="form-label">教室 <span style="color:red">*</span></label>
                <div class="checks" id="locChecks"></div>
            </div>
            <div class="form-group">
                <label class="form-label">時間帯 <span style="color:red">*</span></label>
                <div class="checks" id="timeChecks"></div>
            </div>
            <div class="form-group">
                <label class="form-label">必要人数</label>
                <input type="number" class="form-input" name="required_staff_count" value="1" min="1" max="10" style="max-width:100px;">
            </div>
            <div class="form-group">
                <label class="form-label">備考</label>
                <input type="text" class="form-input" name="note" placeholder="教材準備必要など" maxlength="200">
            </div>
            <button type="submit" class="submit-btn">追加する</button>
        </form>
    </div>
</div>

<script>
const TIMES = [
    {val:'10:00:00', lbl:'10:00'},
    {val:'13:00:00', lbl:'13:00'},
    {val:'14:45:00', lbl:'14:45'},
    {val:'15:00:00', lbl:'15:00'}
];
const BASE_PATH = <?= json_encode(BASE_PATH) ?>;
const LOCS      = <?= json_encode($locations) ?>;
const BY_DATE   = <?= json_encode($schedulesByDate) ?>;   // {date: {locName: [schedule,...]}}
const ALL_SCH   = <?= json_encode($allSchedules) ?>;
const CSRF      = <?= json_encode(generateCsrfToken()) ?>;

// 日付選択（教室名で絞り込みも可）
function selectDate(dateStr, focusLoc) {
    const byLoc = BY_DATE[dateStr] || {};
    const dtLabel = new Date(dateStr + 'T00:00:00').toLocaleDateString('ja-JP', {year:'numeric',month:'long',day:'numeric',weekday:'short'});

    let html = '<div class="panel-date-title">📅 ' + dtLabel + '</div>';

    const locNames = Object.keys(byLoc);
    if (locNames.length === 0) {
        html += '<p style="color:#a0aec0;text-align:center;padding:1.5rem 0;">コマがありません</p>';
    } else {
        locNames.forEach(locName => {
            const open = !focusLoc || focusLoc === locName;
            html += '<div class="loc-section">';
            html += '<div class="loc-label" style="cursor:pointer;" onclick="toggleSection(this)">▾ 📍 ' + escHtml(locName) + '</div>';
            html += '<div class="loc-slots" style="display:' + (open ? 'block' : 'none') + ';">';
            byLoc[locName].forEach(s => {
                html += '<div class="time-row">'
                      + '<span class="t">' + s.start_time.substring(0,5) + '〜' + s.end_time.substring(0,5) + '</span>'
                      + '<button class="del-btn" onclick="delSchedule(' + s.id + ')">削除</button>'
                      + '</div>';
            });
            html += '</div></div>';
        });
    }

    html += '<button class="add-btn" onclick="openModal(\'' + dateStr + '\')">➕ コマを追加</button>';
    document.getElementById('sidePanel').innerHTML = html;

    // 指定教室を自動スクロール
    if (focusLoc) {
        setTimeout(() => {
            const labels = document.querySelectorAll('.loc-label');
            labels.forEach(el => {
                if (el.textContent.includes(focusLoc)) el.scrollIntoView({behavior:'smooth', block:'nearest'});
            });
        }, 50);
    }
}

function toggleSection(labelEl) {
    const slots = labelEl.nextElementSibling;
    if (slots.style.display === 'none') {
        slots.style.display = 'block';
        labelEl.textContent = labelEl.textContent.replace('▸','▾');
    } else {
        slots.style.display = 'none';
        labelEl.textContent = labelEl.textContent.replace('▾','▸');
    }
}

function openModal(dateStr) {
    const dt = new Date(dateStr + 'T00:00:00').toLocaleDateString('ja-JP', {month:'long', day:'numeric'});
    document.getElementById('modalDateLabel').textContent = dt;
    document.getElementById('dateInput').value = dateStr;

    let lh = '';
    LOCS.forEach(l => {
        lh += '<div class="check-item"><input type="checkbox" name="loc" value="' + l.id + '" id="l' + l.id + '"><label for="l' + l.id + '">' + escHtml(l.name) + '</label></div>';
    });
    document.getElementById('locChecks').innerHTML = lh;

    let th = '';
    TIMES.forEach(t => {
        const id = 't' + t.val.replace(/:/g,'');
        th += '<div class="check-item"><input type="checkbox" name="time" value="' + t.val + '" id="' + id + '"><label for="' + id + '">' + t.lbl + '</label></div>';
    });
    document.getElementById('timeChecks').innerHTML = th;

    document.getElementById('modal').classList.add('open');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

function addSchedules(e) {
    e.preventDefault();
    const dateStr = document.getElementById('dateInput').value;
    const ls = Array.from(document.querySelectorAll('input[name="loc"]:checked')).map(x => x.value);
    const ts = Array.from(document.querySelectorAll('input[name="time"]:checked')).map(x => x.value);

    if (ls.length === 0 || ts.length === 0) { alert('教室と時間帯を選択してください'); return; }

    const rc = document.querySelector('input[name="required_staff_count"]').value;
    const nt = document.querySelector('input[name="note"]').value;
    let done = 0, total = ls.length * ts.length;

    ls.forEach(l => {
        ts.forEach(t => {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', 'add');
            fd.append('date', dateStr);
            fd.append('location_id', l);
            fd.append('start_time', t);
            fd.append('required_staff_count', rc);
            fd.append('note', nt);
            fetch(BASE_PATH + '/admin/schedules_api.php', {method:'POST', body:fd})
                .then(r => r.json())
                .then(() => { if (++done === total) { closeModal(); location.reload(); } });
        });
    });
}

function delSchedule(id) {
    if (!confirm('このコマを削除しますか？')) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete');
    fd.append('schedule_id', id);
    fetch(BASE_PATH + '/admin/schedules_api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.error || '削除できませんでした');
        });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('modal').addEventListener('click', e => {
    if (e.target.id === 'modal') closeModal();
});
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>