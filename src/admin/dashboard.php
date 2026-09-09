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
    SELECT s.*, l.name AS location_name,
           COUNT(DISTINCT sa.id) AS assigned_count,
           COUNT(DISTINCT sr.id) AS request_count,
           GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ", ") AS assigned_names
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_assignments sa ON sa.schedule_id = s.id
    LEFT JOIN users u ON sa.user_id = u.id
    LEFT JOIN shift_requests sr ON sr.schedule_id = s.id AND sr.status = "pending"
    WHERE s.date >= ? AND s.date < ?
    GROUP BY s.id
    ORDER BY s.date, s.start_time
');
$stmt->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$allSchedules = $stmt->fetchAll();

$schedulesByDate = [];
$datesByRequest = [];
foreach ($allSchedules as $s) {
    if (!isset($schedulesByDate[$s['date']])) {
        $schedulesByDate[$s['date']] = [];
        $datesByRequest[$s['date']] = false;
    }
    $schedulesByDate[$s['date']][] = $s;
    if ($s['request_count'] > 0) {
        $datesByRequest[$s['date']] = true;
    }
}

$locations = $db->query('SELECT * FROM locations WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$allInstructors = $db->query('SELECT id, name, email FROM users WHERE role = "instructor" AND is_active = 1 ORDER BY name')->fetchAll();

$pageTitle = '管理者ダッシュボード';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
.dashboard-layout { display: grid; grid-template-columns: 1fr 380px; gap: 2rem; margin-bottom: 3rem; }
.calendar-wrapper { background: white; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.15); overflow: hidden; }
.calendar-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; display: flex; justify-content: space-between; align-items: center; }
.calendar-title { font-size: 2rem; font-weight: 700; margin: 0; letter-spacing: 0.5px; }
.calendar-nav { display: flex; gap: 1rem; align-items: center; }
.calendar-nav button { background: rgba(255,255,255,0.2); color: white; border: none; padding: 0.6rem 1rem; border-radius: 0.5rem; cursor: pointer; font-weight: 600; font-size: 1.1rem; transition: all 0.3s ease; }
.calendar-nav button:hover { background: rgba(255,255,255,0.3); transform: scale(1.05); }
.calendar-month-label { font-weight: 600; min-width: 100px; text-align: center; font-size: 0.95rem; }
.weekdays { display: grid; grid-template-columns: repeat(7, 1fr); background: linear-gradient(135deg, #f5f7fa 0%, #f0f4ff 100%); border-bottom: 2px solid #e2e8f0; }
.weekday { padding: 1.2rem; text-align: center; font-weight: 700; color: #667eea; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 1px; }
.calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; background: #e2e8f0; padding: 2px; min-height: 550px; }
.calendar-cell { background: white; padding: 1rem; min-height: 120px; cursor: pointer; transition: all 0.3s ease; border: 3px solid transparent; position: relative; overflow: hidden; }
.calendar-cell::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, transparent 0%, rgba(102,126,234,0.05) 100%); pointer-events: none; }
.calendar-cell:hover { background: linear-gradient(135deg, #f8faff 0%, #f0f4ff 100%); transform: translateY(-2px); box-shadow: 0 8px 16px rgba(102,126,234,0.1); }
.calendar-cell.other-month { background: #f7fafc; opacity: 0.4; }
.calendar-cell.today { background: linear-gradient(135deg, #fef5e7 0%, #fef3c7 100%); border-color: #f59e0b; box-shadow: inset 0 0 0 2px #fcd34d; }
.calendar-cell.has-requests { background: linear-gradient(135deg, #fef5e7 0%, #fde68a 100%) !important; border-color: #f59e0b !important; }
.calendar-cell.has-requests:hover { box-shadow: 0 8px 20px rgba(245,158,11,0.2); }
.cell-date { font-weight: 700; color: #2d3748; margin-bottom: 0.6rem; font-size: 1.1rem; position: relative; z-index: 1; }
.cell-schedules { display: flex; flex-direction: column; gap: 0.4rem; position: relative; z-index: 1; }
.schedule-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.4rem 0.6rem; border-radius: 0.35rem; font-size: 0.7rem; font-weight: 600; overflow: hidden; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(102,126,234,0.25); white-space: normal; line-height: 1.3; }
.schedule-badge:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(102,126,234,0.35); }
.schedule-badge.has-assigned { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); box-shadow: 0 4px 12px rgba(72,187,120,0.3); }
.schedule-badge.has-assigned:hover { box-shadow: 0 6px 16px rgba(72,187,120,0.4); }
.schedule-instructors { font-size: 0.65rem; color: #fff; margin-top: 0.1rem; opacity: 0.95; font-weight: 500; }
.side-panel { background: white; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.15); padding: 1.8rem; max-height: 800px; overflow-y: auto; position: sticky; top: 120px; }
.panel-title { font-size: 1.1rem; font-weight: 700; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.8rem; border-bottom: 3px solid #e2e8f0; letter-spacing: 0.3px; }
.panel-empty { text-align: center; color: #a0aec0; padding: 3rem 1rem; font-size: 1rem; }
.schedule-info { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.2rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 8px 24px rgba(102,126,234,0.25); }
.info-row { display: flex; justify-content: space-between; margin-bottom: 0.6rem; font-size: 0.9rem; }
.info-label { opacity: 0.9; font-weight: 500; }
.info-value { font-weight: 700; }
.instructors-section { margin-bottom: 1.5rem; }
.instructors-list { background: linear-gradient(135deg, #f7fafc 0%, #f0f4ff 100%); border-radius: 0.75rem; padding: 1rem; margin-bottom: 1rem; border: 1px solid #e2e8f0; }
.instructor-item { display: flex; justify-content: space-between; align-items: center; padding: 0.7rem; background: white; border-radius: 0.5rem; margin-bottom: 0.5rem; font-size: 0.9rem; transition: all 0.2s ease; }
.instructor-item:last-child { margin-bottom: 0; }
.instructor-item:hover { box-shadow: 0 4px 12px rgba(102,126,234,0.1); transform: translateX(2px); }
.instructor-name { font-weight: 600; color: #2d3748; }
.remove-btn { background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%); color: white; border: none; padding: 0.35rem 0.6rem; border-radius: 0.35rem; cursor: pointer; font-size: 0.75rem; font-weight: 600; transition: all 0.2s ease; box-shadow: 0 4px 8px rgba(245,101,101,0.2); }
.remove-btn:hover { transform: scale(1.05); box-shadow: 0 6px 12px rgba(245,101,101,0.3); }
.assignment-section { margin-bottom: 1.5rem; }
.form-group { margin-bottom: 0.8rem; }
.form-group label { display: block; font-size: 0.85rem; font-weight: 700; color: #2d3748; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.5px; }
.form-group select, .form-group input { width: 100%; padding: 0.65rem; border: 2px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.85rem; transition: all 0.3s ease; }
.form-group select:focus, .form-group input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
.form-group select:disabled { background-color: #f0f4ff; color: #a0aec0; cursor: not-allowed; }
.btn-assign { width: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.85rem; border-radius: 0.6rem; font-weight: 700; cursor: pointer; transition: all 0.3s ease; margin-bottom: 0.5rem; font-size: 0.95rem; letter-spacing: 0.3px; box-shadow: 0 8px 16px rgba(102,126,234,0.3); }
.btn-assign:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(102,126,234,0.4); }
.btn-assign:disabled { background: linear-gradient(135deg, #cbd5e0 0%, #a0aec0 100%); cursor: not-allowed; box-shadow: none; transform: none; }
.btn-assign:disabled:hover { transform: none; }
.stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; margin-bottom: 1rem; }
.stat-item { background: linear-gradient(135deg, #f0f4ff 0%, #e5ecff 100%); padding: 0.9rem; border-radius: 0.6rem; text-align: center; border-left: 4px solid #667eea; transition: all 0.3s ease; }
.stat-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.1); }
.stat-label { font-size: 0.75rem; color: #718096; margin-bottom: 0.3rem; font-weight: 600; text-transform: uppercase; }
.stat-value { font-size: 1.6rem; font-weight: 700; color: #667eea; }
.info-box { background: linear-gradient(135deg, #f0f4ff 0%, #e5ecff 100%); padding: 0.9rem; border-radius: 0.6rem; margin-bottom: 1rem; font-size: 0.85rem; color: #4a5568; border-left: 4px solid #667eea; font-weight: 500; }
.capacity-warning { background: linear-gradient(135deg, #fed7d7 0%, #fbb6ce 100%); color: #c53030; padding: 0.8rem; border-radius: 0.6rem; margin-bottom: 1rem; font-size: 0.85rem; border-left: 4px solid #f56565; font-weight: 600; }
.capacity-info { background: linear-gradient(135deg, #c6f6d5 0%, #a8e6c1 100%); color: #22543d; padding: 0.8rem; border-radius: 0.6rem; margin-bottom: 1rem; font-size: 0.85rem; border-left: 4px solid #38a169; font-weight: 600; }
@media (max-width: 1200px) { .dashboard-layout { grid-template-columns: 1fr; } .side-panel { position: static; max-height: none; } }
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: #f0f4ff; border-radius: 10px; }
::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: #a0aec0; }
</style>

<div style="margin-bottom: 2.5rem;">
    <h1 style="margin: 0; color: white; font-size: 2.2rem; font-weight: 700; letter-spacing: 0.5px;">📊 シフト管理ダッシュボード</h1>
    <p style="color: rgba(255,255,255,0.8); margin: 0.75rem 0 0; font-size: 1.05rem;">カレンダーから日付を選択してシフトを管理します</p>
</div>

<div class="dashboard-layout">
    <div class="calendar-wrapper">
        <div class="calendar-header">
            <h2 class="calendar-title">📅 <?= h($currentDate->format('Y年n月')) ?></h2>
            <div class="calendar-nav">
                <button onclick="window.location.href='?month=<?= h($prevMonth) ?>'">◀</button>
                <span class="calendar-month-label"><?= h($currentDate->format('M y')) ?></span>
                <button onclick="window.location.href='?month=<?= h($nextMonth) ?>'">▶</button>
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
                $hasRequests = $datesByRequest[$dateStr] ?? false;
            ?>
                <div class="calendar-cell <?= $isToday ? 'today' : '' ?><?= $hasRequests ? ' has-requests' : '' ?>" 
                     onclick="selectDate('<?= h($dateStr) ?>')">
                    <div class="cell-date"><?= $day ?></div>
                    <div class="cell-schedules">
                        <?php foreach (array_slice($daySchedules, 0, 2) as $s): ?>
                            <div class="schedule-badge <?= $s['assigned_count'] > 0 ? 'has-assigned' : '' ?>" onclick="selectSchedule(event, <?= h($s['id']) ?>)">
                                <div style="font-weight:700;">📍 <?= h(substr($s['location_name'], 0, 6)) ?></div>
                                <div>🕐 <?= h(substr($s['start_time'], 0, 5)) ?></div>
                                <?php if ($s['assigned_names']): ?>
                                    <div class="schedule-instructors">👥 <?= h(substr($s['assigned_names'], 0, 12)) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($daySchedules) > 2): ?>
                            <div class="schedule-badge" style="opacity: 0.6;">
                                +<?= count($daySchedules) - 2 ?> 他
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="side-panel">
        <div id="panelContent" class="panel-empty">
            <p>📍 カレンダーから日付またはコマを選択してください</p>
        </div>
    </div>
</div>

<script>
let selectedScheduleId = null;

function selectDate(dateStr) {
    const schedules = <?= json_encode($schedulesByDate) ?>;
    const daySchedules = schedules[dateStr] || [];
    if (daySchedules.length === 0) return;
    if (daySchedules.length === 1) { selectSchedule(null, daySchedules[0].id); return; }
    let html = '<div class="panel-title">📅 ' + dateStr + ' のコマ</div><div style="display:flex;flex-direction:column;gap:0.5rem;">';
    daySchedules.forEach(s => {
        html += '<div style="background:linear-gradient(135deg, #f0f4ff 0%, #e5ecff 100%);padding:1rem;border-radius:0.75rem;cursor:pointer;border:1px solid #e2e8f0;" onclick="selectSchedule(null, ' + s.id + ')">';
        html += '<div style="font-weight:700;color:#667eea;">🕐 ' + s.start_time.substring(0,5) + ' - ' + s.end_time.substring(0,5) + '</div>';
        html += '<div style="font-size:0.9rem;color:#718096;margin-top:0.3rem;">📍 ' + s.location_name + '</div>';
        if (s.assigned_names) html += '<div style="font-size:0.8rem;color:#48bb78;margin-top:0.5rem;font-weight:600;">👥 ' + s.assigned_names + '</div>';
        html += '</div>';
    });
    html += '</div>';
    document.getElementById('panelContent').innerHTML = html;
}

function selectSchedule(e, scheduleId) {
    if (e) e.stopPropagation();
    selectedScheduleId = scheduleId;
    const schedules = <?= json_encode($allSchedules) ?>;
    const schedule = schedules.find(s => s.id == scheduleId);
    if (!schedule) return;
    let html = '<div class="schedule-info">';
    html += '<div class="info-row"><div class="info-label">📅 日付</div><div class="info-value">' + new Date(schedule.date).toLocaleDateString('ja-JP') + '</div></div>';
    html += '<div class="info-row"><div class="info-label">🕐 時間</div><div class="info-value">' + schedule.start_time.substring(0,5) + ' - ' + schedule.end_time.substring(0,5) + '</div></div>';
    html += '<div class="info-row"><div class="info-label">📍 場所</div><div class="info-value">' + schedule.location_name + '</div></div>';
    html += '<div class="info-row"><div class="info-label">👥 必要人数</div><div class="info-value">' + schedule.required_staff_count + '名</div></div></div>';
    html += '<div class="stats-row"><div class="stat-item"><div class="stat-label">希望申請</div><div class="stat-value">' + schedule.request_count + '</div></div>';
    html += '<div class="stat-item"><div class="stat-label">割当済み</div><div class="stat-value">' + schedule.assigned_count + '</div></div></div>';
    html += '<div class="info-box">✨ 希望を申請した講師から優先して選択します</div>';
    html += '<div class="instructors-section"><div class="panel-title">✅ 割り当て済み講師</div><div class="instructors-list" id="assignedList"></div></div>';
    html += '<div class="assignment-section"><div class="panel-title">➕ 講師を選択</div><div id="capacityMsg"></div>';
    html += '<form id="assignForm" onsubmit="assignInstructor(event)"><div class="form-group"><select name="instructor_id" id="instructorSelect" required><option value="">講師を選択</option></select></div>';
    html += '<button type="submit" class="btn-assign" id="assignBtn">割り当てる</button></form></div>';
    document.getElementById('panelContent').innerHTML = html;
    loadInstructorOptions(scheduleId);
    loadAssignedInstructors(scheduleId);
}

function loadInstructorOptions(scheduleId) {
    fetch('/admin/api_schedule.php?action=get_requests&schedule_id=' + scheduleId)
        .then(r => r.json())
        .then(data => {
            let selectHtml = '<option value="">講師を選択</option>';
            if (data.requested.length > 0) {
                selectHtml += '<optgroup label="📝 希望申請者（優先）">';
                data.requested.forEach(inst => { selectHtml += '<option value="' + inst.id + '">✓ ' + inst.name + '</option>'; });
                selectHtml += '</optgroup>';
            }
            if (data.all.length > 0) {
                selectHtml += '<optgroup label="👥 全講師（強制定期）">';
                data.all.forEach(inst => { selectHtml += '<option value="' + inst.id + '">' + inst.name + '</option>'; });
                selectHtml += '</optgroup>';
            }
            document.getElementById('instructorSelect').innerHTML = selectHtml;
        });
}

function loadAssignedInstructors(scheduleId) {
    fetch('/admin/api_schedule.php?action=get_assigned&schedule_id=' + scheduleId)
        .then(r => r.json())
        .then(data => {
            const assigned = data.assigned || [];
            const isFull = data.isFull || false;
            const count = data.count || 0;
            let html = '';
            if (assigned.length === 0) {
                html = '<p style="color:#a0aec0;text-align:center;font-weight:500;">まだ割り当てられていません</p>';
            } else {
                assigned.forEach(inst => {
                    html += '<div class="instructor-item"><div><div class="instructor-name">' + inst.name + '</div><div style="font-size:0.8rem;color:#a0aec0;">' + inst.email + '</div></div>';
                    html += '<button type="button" class="remove-btn" onclick="removeInstructor(' + inst.assignment_id + ')">削除</button></div>';
                });
            }
            document.getElementById('assignedList').innerHTML = html;
            let msgHtml = '';
            if (isFull) {
                msgHtml = '<div class="capacity-warning">⚠️ 講師の割り当ては最大3名までです</div>';
                document.getElementById('instructorSelect').disabled = true;
                document.getElementById('assignBtn').disabled = true;
            } else if (count > 0) {
                msgHtml = '<div class="capacity-info">✓ ' + count + '/3人の講師が割り当てられています</div>';
                document.getElementById('instructorSelect').disabled = false;
                document.getElementById('assignBtn').disabled = false;
            } else {
                document.getElementById('instructorSelect').disabled = false;
                document.getElementById('assignBtn').disabled = false;
            }
            document.getElementById('capacityMsg').innerHTML = msgHtml;
        });
}

function assignInstructor(e) {
    e.preventDefault();
    const formData = new FormData();
    formData.append('action', 'assign');
    formData.append('schedule_id', selectedScheduleId);
    formData.append('instructor_id', document.querySelector('[name="instructor_id"]').value);
    formData.append('csrf_token', '<?= h(generateCsrfToken()) ?>');
    fetch('/admin/api_schedule.php', {method: 'POST', body: formData})
        .then(r => r.json())
        .then(data => {
            if (data.success) { loadAssignedInstructors(selectedScheduleId); location.reload(); }
            else { alert(data.error || 'エラーが発生しました'); }
        });
}

function removeInstructor(assignmentId) {
    if (!confirm('この講師の割り当てを削除しますか？')) return;
    const formData = new FormData();
    formData.append('action', 'remove');
    formData.append('assignment_id', assignmentId);
    formData.append('csrf_token', '<?= h(generateCsrfToken()) ?>');
    fetch('/admin/api_schedule.php', {method: 'POST', body: formData})
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); else alert(data.error || 'エラーが発生しました'); });
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
