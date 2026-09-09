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

$requestsByDate = [];
$stmt = $db->prepare('
    SELECT DISTINCT s.date, COUNT(sr.id) AS cnt
    FROM schedules s
    LEFT JOIN shift_requests sr ON sr.schedule_id = s.id AND sr.status = "pending"
    WHERE s.date >= ? AND s.date < ?
    GROUP BY s.date
');
$stmt->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
foreach ($stmt->fetchAll() as $row) {
    $requestsByDate[$row['date']] = $row['cnt'];
}

$assignedByDate = [];
$stmt = $db->prepare('
    SELECT sa.date, GROUP_CONCAT(u.name SEPARATOR ", ") AS names, COUNT(u.id) AS cnt
    FROM shift_assignments sa
    JOIN users u ON sa.user_id = u.id
    WHERE sa.date >= ? AND sa.date < ?
    GROUP BY sa.date
');
$stmt->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
foreach ($stmt->fetchAll() as $row) {
    $assignedByDate[$row['date']] = ['names' => $row['names'], 'count' => $row['cnt']];
}

$schedulesByDate = [];
foreach ($allSchedules as $s) {
    if (!isset($schedulesByDate[$s['date']])) {
        $schedulesByDate[$s['date']] = [];
    }
    $schedulesByDate[$s['date']][] = $s;
}

$pageTitle = '管理者ダッシュボード';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
.dashboard-layout { display: grid; grid-template-columns: 1fr 380px; gap: 2rem; margin: 2rem; margin-bottom: 3rem; }
.calendar-wrapper { background: white; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.15); overflow: hidden; }
.calendar-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; display: flex; justify-content: space-between; align-items: center; }
.calendar-title { font-size: 2rem; font-weight: 700; margin: 0; }
.calendar-nav button { background: rgba(255,255,255,0.2); color: white; border: none; padding: 0.6rem 1rem; cursor: pointer; border-radius: 0.5rem; font-weight: 600; }
.calendar-nav button:hover { background: rgba(255,255,255,0.3); }
.weekdays { display: grid; grid-template-columns: repeat(7, 1fr); background: #f0f4ff; border-bottom: 1px solid #e2e8f0; }
.weekday { padding: 0.75rem; text-align: center; font-weight: bold; color: #667eea; }
.calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e2e8f0; padding: 1px; }
.calendar-cell { background: white; padding: 0.75rem; min-height: 100px; cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
.calendar-cell:hover { background: #f8faff; }
.calendar-cell.today { background: #fef5e7; border-color: #f39c12; }
.calendar-cell.has-requests { background: #fef5e7 !important; }
.calendar-cell.other-month { background: #fafbfc; opacity: 0.5; cursor: default; }
.cell-date { font-weight: bold; color: #2d3748; margin-bottom: 0.5rem; }
.cell-instructors { background: #c6f6d5; color: #22543d; padding: 0.3rem 0.5rem; border-radius: 0.3rem; font-size: 0.65rem; font-weight: 600; margin-bottom: 0.3rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cell-schedules { display: flex; flex-direction: column; gap: 0.3rem; }
.schedule-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.3rem 0.5rem; border-radius: 0.25rem; font-size: 0.65rem; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.side-panel { background: white; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.15); padding: 1.5rem; max-height: 800px; overflow-y: auto; }
.panel-title { font-size: 1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; }
.panel-empty { text-align: center; color: #a0aec0; padding: 2rem 1rem; }
.date-info { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
.info-row { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 0.5rem; }
.schedules-list { background: #f7fafc; border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 1.5rem; border: 1px solid #e2e8f0; }
.schedule-item { background: white; padding: 0.6rem; border-radius: 0.4rem; margin-bottom: 0.4rem; cursor: pointer; border: 1px solid #e2e8f0; }
.schedule-item:last-child { margin-bottom: 0; }
.schedule-time { font-weight: bold; color: #667eea; font-size: 0.9rem; }
.schedule-location { font-size: 0.8rem; color: #718096; margin-top: 0.2rem; }
.instructors-list { background: #f7fafc; border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 1.5rem; border: 1px solid #e2e8f0; }
.instructor-item { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem; background: white; border-radius: 0.4rem; margin-bottom: 0.4rem; border: 1px solid #e2e8f0; font-size: 0.9rem; }
.instructor-item:last-child { margin-bottom: 0; }
.instructor-name { font-weight: 600; }
.remove-btn { background: #f56565; color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 0.3rem; cursor: pointer; font-size: 0.75rem; }
.form-group { margin-bottom: 0.75rem; }
.form-group label { display: block; font-weight: bold; color: #2d3748; margin-bottom: 0.3rem; font-size: 0.85rem; }
.form-group select { width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 0.4rem; font-size: 0.85rem; }
.btn-assign { width: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.7rem; border-radius: 0.5rem; font-weight: bold; cursor: pointer; margin-top: 0.5rem; }
.btn-assign:hover { transform: translateY(-2px); }
.btn-assign:disabled { background: #cbd5e0; cursor: not-allowed; }
.capacity-info { background: #c6f6d5; color: #22543d; padding: 0.7rem; border-radius: 0.4rem; margin-bottom: 0.75rem; font-size: 0.85rem; }
.capacity-warning { background: #fed7d7; color: #c53030; padding: 0.7rem; border-radius: 0.4rem; margin-bottom: 0.75rem; font-size: 0.85rem; }
@media (max-width: 1024px) { .dashboard-layout { grid-template-columns: 1fr; } }
</style>

<div style="margin: 2rem; margin-bottom: 0;">
    <h1 style="margin: 0; color: white; font-size: 2rem;">📊 シフト管理ダッシュボード</h1>
</div>

<div class="dashboard-layout">
    <div class="calendar-wrapper">
        <div class="calendar-header">
            <h2 class="calendar-title">📅 <?= h($currentDate->format('Y年n月')) ?></h2>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <button onclick="location.href='?month=<?= h($prevMonth) ?>'">◀</button>
                <span style="font-weight: bold; min-width: 80px; text-align: center;"><?= h($currentDate->format('M y')) ?></span>
                <button onclick="location.href='?month=<?= h($nextMonth) ?>'">▶</button>
            </div>
        </div>

        <div class="weekdays">
            <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $day): ?>
                <div class="weekday"><?= $day ?></div>
            <?php endforeach; ?>
        </div>

        <div class="calendar-grid">
            <?php for ($i = 0; $i < $firstDay; $i++): ?>
                <div class="calendar-cell other-month"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $dateStr = $currentDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isToday = ($dateStr === date('Y-m-d'));
                $daySchedules = $schedulesByDate[$dateStr] ?? [];
                $hasRequests = ($requestsByDate[$dateStr] ?? 0) > 0;
                $assigned = $assignedByDate[$dateStr] ?? null;
            ?>
                <div class="calendar-cell <?= $isToday ? 'today' : '' ?><?= $hasRequests ? ' has-requests' : '' ?>" onclick="selectDate('<?= h($dateStr) ?>')">
                    <div class="cell-date"><?= $day ?></div>
                    <?php if ($assigned): ?>
                        <div class="cell-instructors" title="<?= h($assigned['names']) ?>">👥 <?= h(substr($assigned['names'], 0, 12)) ?></div>
                    <?php endif; ?>
                    <div class="cell-schedules">
                        <?php foreach (array_slice($daySchedules, 0, 2) as $s): ?>
                            <div class="schedule-badge" onclick="selectSchedule(event, <?= (int)$s['id'] ?>)" title="<?= h($s['location_name']) ?>">
                                🕐 <?= h(substr($s['start_time'], 0, 5)) ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($daySchedules) > 2): ?>
                            <div class="schedule-badge" style="opacity: 0.6;">+<?= count($daySchedules) - 2 ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="side-panel">
        <div id="panelContent" class="panel-empty">
            <p>📍 日付またはコマを選択</p>
        </div>
    </div>
</div>

<script>
let selectedDate = null;
const schedulesByDate = <?= json_encode($schedulesByDate) ?>;
const allSchedules = <?= json_encode($allSchedules) ?>;

function selectDate(dateStr) {
    selectedDate = dateStr;
    const daySchedules = schedulesByDate[dateStr] || [];
    let html = '<div class="date-info"><div class="info-row"><span>📅 日付</span><span>' + new Date(dateStr).toLocaleDateString('ja-JP') + '</span></div>';
    html += '<div class="info-row"><span>📍 コマ数</span><span>' + daySchedules.length + '件</span></div></div>';
    
    if (daySchedules.length > 0) {
        html += '<div class="panel-title">📍 この日のコマ</div><div class="schedules-list">';
        daySchedules.forEach(s => {
            html += '<div class="schedule-item" onclick="selectSchedule(null, ' + s.id + ')"><div class="schedule-time">' + s.start_time.substring(0,5) + ' - ' + s.end_time.substring(0,5) + '</div><div class="schedule-location">' + s.location_name + '</div></div>';
        });
        html += '</div>';
    }
    
    html += '<div class="panel-title">👥 講師割り当て</div><div class="instructors-list" id="assignedList"><p style="color:#a0aec0;text-align:center;">読み込み中...</p></div>';
    html += '<div class="panel-title">➕ 講師を追加</div><div id="capacityMsg"></div><form onsubmit="assignInstructor(event)"><div class="form-group"><select id="instructorSelect"><option>読み込み中...</option></select></div><button type="submit" class="btn-assign" id="assignBtn">割り当てる</button></form>';
    
    document.getElementById('panelContent').innerHTML = html;
    
    setTimeout(() => {
        loadInstructorOptions(dateStr);
        loadAssignedInstructors(dateStr);
    }, 50);
}

function selectSchedule(e, id) {
    if (e) e.stopPropagation();
    const s = allSchedules.find(x => x.id == id);
    if (!s) return;
    let html = '<div class="date-info"><div class="info-row"><span>📅</span><span>' + new Date(s.date).toLocaleDateString('ja-JP') + '</span></div>';
    html += '<div class="info-row"><span>🕐</span><span>' + s.start_time.substring(0,5) + ' - ' + s.end_time.substring(0,5) + '</span></div>';
    html += '<div class="info-row"><span>📍</span><span>' + s.location_name + '</span></div></div>';
    html += '<button class="btn-assign" onclick="selectDate(\'' + s.date + '\')">📅 この日全体を管理</button>';
    document.getElementById('panelContent').innerHTML = html;
}

function loadInstructorOptions(dateStr) {
    fetch('/admin/api_schedule.php?action=get_requests&date=' + encodeURIComponent(dateStr))
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('instructorSelect');
            if (!sel) return;
            let html = '<option value="">講師を選択</option>';
            if (data.requested && data.requested.length > 0) {
                html += '<optgroup label="📝 希望申請者">';
                data.requested.forEach(i => { html += '<option value="' + i.id + '">✓ ' + i.name + '</option>'; });
                html += '</optgroup>';
            }
            if (data.all && data.all.length > 0) {
                html += '<optgroup label="👥 全講師">';
                data.all.forEach(i => { html += '<option value="' + i.id + '">' + i.name + '</option>'; });
                html += '</optgroup>';
            }
            sel.innerHTML = html;
        })
        .catch(e => console.error(e));
}

function loadAssignedInstructors(dateStr) {
    fetch('/admin/api_schedule.php?action=get_assigned&date=' + encodeURIComponent(dateStr))
        .then(r => r.json())
        .then(data => {
            const assigned = data.assigned || [];
            const isFull = data.isFull || false;
            const cnt = data.count || 0;
            
            let html = '';
            if (assigned.length === 0) {
                html = '<p style="color:#a0aec0;text-align:center;">割り当てなし</p>';
            } else {
                assigned.forEach(i => {
                    html += '<div class="instructor-item"><div>' + i.name + '</div><button type="button" class="remove-btn" onclick="removeInstructor(' + i.assignment_id + ')">削除</button></div>';
                });
            }
            const lst = document.getElementById('assignedList');
            if (lst) lst.innerHTML = html;
            
            let msg = '';
            if (isFull) {
                msg = '<div class="capacity-warning">⚠️ 3名まで</div>';
            } else if (cnt > 0) {
                msg = '<div class="capacity-info">✓ ' + cnt + '/3</div>';
            }
            const cmsg = document.getElementById('capacityMsg');
            if (cmsg) cmsg.innerHTML = msg;
            
            const sel = document.getElementById('instructorSelect');
            const btn = document.getElementById('assignBtn');
            if (sel) sel.disabled = isFull;
            if (btn) btn.disabled = isFull;
        })
        .catch(e => console.error(e));
}

function assignInstructor(e) {
    e.preventDefault();
    if (!selectedDate) return;
    const sel = document.getElementById('instructorSelect');
    if (!sel || !sel.value) return;
    
    const fd = new FormData();
    fd.append('action', 'assign');
    fd.append('date', selectedDate);
    fd.append('instructor_id', sel.value);
    fd.append('csrf_token', '<?= h(generateCsrfToken()) ?>');
    
    fetch('/admin/api_schedule.php', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.error || 'エラー');
        });
}

function removeInstructor(id) {
    if (!confirm('削除しますか？')) return;
    const fd = new FormData();
    fd.append('action', 'remove');
    fd.append('assignment_id', id);
    fd.append('csrf_token', '<?= h(generateCsrfToken()) ?>');
    
    fetch('/admin/api_schedule.php', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
        });
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
