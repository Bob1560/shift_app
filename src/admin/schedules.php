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

$firstDay = (int)$currentDate->format('w');
$daysInMonth = (int)$currentDate->format('t');
$endDate = (clone $currentDate)->modify('+1 month');

$stmt = $db->prepare('
    SELECT s.*, l.name AS location_name
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date, s.location_id, s.start_time
');
$stmt->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$allSchedules = $stmt->fetchAll();

$schedulesByDate = [];
foreach ($allSchedules as $s) {
    if (!isset($schedulesByDate[$s['date']])) {
        $schedulesByDate[$s['date']] = [];
    }
    $schedulesByDate[$s['date']][] = $s;
}

$locations = $db->query('SELECT * FROM locations WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

$pageTitle = 'コマ管理';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.container { display: grid; grid-template-columns: 1fr 400px; gap: 2rem; margin: 2rem; }
.calendar { background: white; border-radius: 0.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.cal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; }
.cal-title { font-size: 1.5rem; font-weight: bold; margin: 0; }
.nav-btn { background: rgba(255,255,255,0.2); color: white; border: none; padding: 0.5rem 0.75rem; cursor: pointer; border-radius: 0.25rem; font-weight: bold; }
.nav-btn:hover { background: rgba(255,255,255,0.3); }
.weekdays { display: grid; grid-template-columns: repeat(7, 1fr); background: #f0f4ff; border-bottom: 1px solid #e2e8f0; }
.weekday { padding: 0.75rem; text-align: center; font-weight: bold; color: #667eea; }
.grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e2e8f0; padding: 1px; }
.cell { background: white; padding: 0.75rem; min-height: 100px; cursor: pointer; transition: all 0.2s; }
.cell:hover { background: #f8faff; }
.cell.other-month { background: #fafbfc; opacity: 0.5; cursor: default; }
.cell.today { background: #fef5e7; border: 2px solid #f39c12; }
.cell-day { font-weight: bold; color: #2d3748; margin-bottom: 0.5rem; }
.cell-items { display: flex; flex-direction: column; gap: 0.3rem; }
.item-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.3rem 0.5rem; border-radius: 0.25rem; font-size: 0.65rem; font-weight: bold; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-more { background: #cbd5e0; color: white; padding: 0.3rem 0.5rem; border-radius: 0.25rem; font-size: 0.65rem; font-weight: bold; }
.side { background: white; border-radius: 0.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 1.5rem; }
.panel-empty { text-align: center; color: #a0aec0; padding: 3rem 1rem; }
.panel-title { font-size: 1.1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e2e8f0; }
.loc-group { margin-bottom: 1.5rem; }
.loc-name { font-weight: bold; color: #667eea; margin-bottom: 0.5rem; font-size: 0.95rem; }
.time-item { background: #f8faff; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 0.5rem; cursor: pointer; border: 1px solid #e2e8f0; font-size: 0.9rem; }
.time-item:hover { background: #f0f4ff; border-color: #667eea; }
.time-display { font-weight: bold; color: #667eea; }
.detail-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; }
.detail-label { color: #718096; font-weight: 500; }
.detail-val { color: #2d3748; font-weight: bold; }
.btn { width: 100%; padding: 0.75rem; border: none; border-radius: 0.5rem; font-weight: bold; cursor: pointer; margin-top: 0.5rem; }
.btn-add { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
.btn-add:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
.btn-del { background: #f56565; color: white; }
.btn-del:hover { background: #e53e3e; }
.modal { display: none; position: fixed; z-index: 1000; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
.modal.show { display: flex; }
.modal-box { background: white; padding: 2rem; border-radius: 0.75rem; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: bold; color: #2d3748; margin-bottom: 1.5rem; }
.form-group { margin-bottom: 1rem; }
.form-label { display: block; font-weight: bold; color: #2d3748; margin-bottom: 0.5rem; font-size: 0.9rem; }
.checks { display: flex; flex-wrap: wrap; gap: 1rem; }
.check-item { display: flex; align-items: center; }
.check-item input { margin-right: 0.5rem; }
.check-item label { margin: 0; font-size: 0.9rem; }
.form-input { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e0; border-radius: 0.5rem; font-size: 0.9rem; }
.btn-submit { width: 100%; padding: 0.75rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 0.5rem; font-weight: bold; cursor: pointer; margin-top: 1rem; }
.btn-submit:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
.btn-close { float: right; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #a0aec0; }
@media (max-width: 1024px) { .container { grid-template-columns: 1fr; } }
</style>

<div style="margin-bottom: 2rem;">
    <h1 style="margin: 0; color: #2d3748;">📋 コマ管理</h1>
</div>

<div class="container">
    <div class="calendar">
        <div class="cal-header">
            <div class="cal-title">📅 <?= h($currentDate->format('Y年n月')) ?></div>
            <div>
                <button class="nav-btn" onclick="location.href='?month=<?= h($prevMonth) ?>'">◀</button>
                <span style="font-weight:bold;margin:0 1rem;display:inline-block;min-width:100px;text-align:center;"><?= h($currentDate->format('M y')) ?></span>
                <button class="nav-btn" onclick="location.href='?month=<?= h($nextMonth) ?>'">▶</button>
            </div>
        </div>

        <div class="weekdays">
            <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $day): ?>
                <div class="weekday"><?= $day ?></div>
            <?php endforeach; ?>
        </div>

        <div class="grid">
            <?php for ($i = 0; $i < $firstDay; $i++): ?>
                <div class="cell other-month"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $dateStr = $currentDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isToday = ($dateStr === date('Y-m-d'));
                $daySchedules = $schedulesByDate[$dateStr] ?? [];
            ?>
                <div class="cell <?= $isToday ? 'today' : '' ?>" onclick="showDate('<?= h($dateStr) ?>')">
                    <div class="cell-day"><?= $day ?></div>
                    <div class="cell-items">
                        <?php foreach (array_slice($daySchedules, 0, 2) as $s): ?>
                            <div class="item-badge" onclick="showSchedule(event, <?= (int)$s['id'] ?>)" title="<?= h($s['location_name']) ?>">
                                <?= h(substr($s['location_name'], 0, 6)) ?> <?= h(substr($s['start_time'], 0, 5)) ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($daySchedules) > 2): ?>
                            <div class="item-more">+<?= count($daySchedules) - 2 ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="side">
        <div id="panel" class="panel-empty">
            <p>📍 日付またはコマを選択</p>
        </div>
    </div>
</div>

<div id="modal" class="modal">
    <div class="modal-box">
        <button class="btn-close" onclick="closeModal()">&times;</button>
        <div class="modal-title">📅 <span id="modalDate"></span> にコマを追加</div>
        <form onsubmit="addSchedules(event)">
            <input type="hidden" id="csrf" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" id="dateInput">

            <div class="form-group">
                <label class="form-label">教室 <span style="color:red">*</span></label>
                <div class="checks" id="locs"></div>
            </div>

            <div class="form-group">
                <label class="form-label">時間帯 <span style="color:red">*</span></label>
                <div class="checks" id="times"></div>
            </div>

            <div class="form-group">
                <label class="form-label">必要人数</label>
                <input type="number" class="form-input" name="required_staff_count" value="1" min="1" max="10">
            </div>

            <div class="form-group">
                <label class="form-label">備考</label>
                <input type="text" class="form-input" name="note" placeholder="教材準備必要" maxlength="200">
            </div>

            <button type="submit" class="btn-submit">追加する</button>
        </form>
    </div>
</div>

<script>
const times = [{val:'10:00:00',lbl:'10:00'},{val:'13:00:00',lbl:'13:00'},{val:'14:45:00',lbl:'14:45'},{val:'15:00:00',lbl:'15:00'}];
const locs = <?= json_encode($locations) ?>;
const byDate = <?= json_encode($schedulesByDate) ?>;
const allSch = <?= json_encode($allSchedules) ?>;

function groupByLocation(schedules) {
    const groups = {};
    schedules.forEach(s => {
        if (!groups[s.location_name]) {
            groups[s.location_name] = [];
        }
        groups[s.location_name].push(s);
    });
    return groups;
}

function showDate(d) {
    const sches = byDate[d] || [];
    const dt = new Date(d).toLocaleDateString('ja-JP');
    let h = '<div class="panel-title">📅 ' + dt + '</div>';
    
    if (sches.length === 0) {
        h += '<p style="color:#a0aec0;text-align:center;margin:2rem 0;">コマなし</p>';
    } else {
        const groups = groupByLocation(sches);
        h += '<div>';
        Object.keys(groups).forEach(locName => {
            h += '<div class="loc-group">';
            h += '<div class="loc-name">📍 ' + locName + '</div>';
            groups[locName].forEach(s => {
                h += '<div class="time-item" onclick="showSchedule(null, ' + s.id + ')">';
                h += '<div class="time-display">' + s.start_time.substring(0,5) + ' - ' + s.end_time.substring(0,5) + '</div>';
                h += '</div>';
            });
            h += '</div>';
        });
        h += '</div>';
    }
    h += '<button class="btn btn-add" onclick="openModal(\'' + d + '\')">➕ コマを追加</button>';
    document.getElementById('panel').innerHTML = h;
}

function showSchedule(e, id) {
    if (e) e.stopPropagation();
    const s = allSch.find(x => x.id == id);
    if (!s) return;
    let h = '<div class="panel-title">📅 コマの詳細</div>';
    h += '<div class="detail-row"><span class="detail-label">日付</span><span class="detail-val">' + new Date(s.date).toLocaleDateString('ja-JP') + '</span></div>';
    h += '<div class="detail-row"><span class="detail-label">時間</span><span class="detail-val">' + s.start_time.substring(0,5) + ' - ' + s.end_time.substring(0,5) + '</span></div>';
    h += '<div class="detail-row"><span class="detail-label">場所</span><span class="detail-val">' + s.location_name + '</span></div>';
    h += '<div class="detail-row"><span class="detail-label">必要人数</span><span class="detail-val">' + s.required_staff_count + '名</span></div>';
    if (s.note) h += '<div class="detail-row"><span class="detail-label">備考</span><span class="detail-val" style="font-size:0.85rem;">' + s.note + '</span></div>';
    h += '<button class="btn btn-del" onclick="delSchedule(' + id + ')">🗑️ 削除</button>';
    h += '<button class="btn btn-add" onclick="openModal(\'' + s.date + '\')">➕ 追加</button>';
    document.getElementById('panel').innerHTML = h;
}

function openModal(d) {
    const dt = new Date(d).toLocaleDateString('ja-JP');
    document.getElementById('modalDate').textContent = dt;
    document.getElementById('dateInput').value = d;
    
    let lh = '';
    locs.forEach(l => {
        lh += '<div class="check-item"><input type="checkbox" name="loc" value="' + l.id + '" id="l' + l.id + '"><label for="l' + l.id + '">' + l.name + '</label></div>';
    });
    document.getElementById('locs').innerHTML = lh;
    
    let th = '';
    times.forEach(t => {
        th += '<div class="check-item"><input type="checkbox" name="time" value="' + t.val + '" id="t' + t.val.replace(/:/g, '') + '"><label for="t' + t.val.replace(/:/g, '') + '">' + t.lbl + '</label></div>';
    });
    document.getElementById('times').innerHTML = th;
    
    document.getElementById('modal').classList.add('show');
}

function closeModal() {
    document.getElementById('modal').classList.remove('show');
}

function addSchedules(e) {
    e.preventDefault();
    const d = document.getElementById('dateInput').value;
    const ls = Array.from(document.querySelectorAll('input[name="loc"]:checked')).map(x => x.value);
    const ts = Array.from(document.querySelectorAll('input[name="time"]:checked')).map(x => x.value);
    
    if (ls.length === 0 || ts.length === 0) {
        alert('教室と時間帯を選択');
        return;
    }
    
    const csrf = document.getElementById('csrf').value;
    const rc = document.querySelector('input[name="required_staff_count"]').value;
    const nt = document.querySelector('input[name="note"]').value;
    let done = 0;
    const tot = ls.length * ts.length;
    
    ls.forEach(l => {
        ts.forEach(t => {
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'add');
            fd.append('date', d);
            fd.append('location_id', l);
            fd.append('start_time', t);
            fd.append('required_staff_count', rc);
            fd.append('note', nt);
            
            fetch('/admin/schedules_api.php', {method:'POST', body:fd})
                .then(r => r.json())
                .then(data => {
                    done++;
                    if (done === tot) {
                        alert('追加完了');
                        closeModal();
                        location.reload();
                    }
                });
        });
    });
}

function delSchedule(id) {
    if (!confirm('削除しますか?')) return;
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrf').value);
    fd.append('action', 'delete');
    fd.append('schedule_id', id);
    
    fetch('/admin/schedules_api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('削除完了');
                location.reload();
            }
        });
}

document.getElementById('modal').addEventListener('click', e => {
    if (e.target.id === 'modal') closeModal();
});
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
