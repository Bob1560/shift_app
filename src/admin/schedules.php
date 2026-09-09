<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();
$errors = [];

// 表示月
$month = $_GET['month'] ?? date('Y-m');
try {
    $currentDate = new DateTime($month . '-01');
} catch (Exception $e) {
    $currentDate = new DateTime(date('Y-m') . '-01');
}

$prevMonth = (clone $currentDate)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $currentDate)->modify('+1 month')->format('Y-m');

// カレンダー用の日付情報
$firstDay = (int)$currentDate->format('w');
$daysInMonth = (int)$currentDate->format('t');
$endDate = (clone $currentDate)->modify('+1 month');

// 全スケジュール取得
$stmt = $db->prepare('
    SELECT s.*, l.name AS location_name,
           COUNT(DISTINCT sa.id) AS assigned_count,
           COUNT(DISTINCT sr.id) AS request_count
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_assignments sa ON sa.schedule_id = s.id
    LEFT JOIN shift_requests sr ON sr.schedule_id = s.id AND sr.status = "pending"
    WHERE s.date >= ? AND s.date < ?
    GROUP BY s.id
    ORDER BY s.date, s.start_time
');
$stmt->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$allSchedules = $stmt->fetchAll();

// 日付ごとにグループ化
$schedulesByDate = [];
foreach ($allSchedules as $s) {
    if (!isset($schedulesByDate[$s['date']])) {
        $schedulesByDate[$s['date']] = [];
    }
    $schedulesByDate[$s['date']][] = $s;
}

// 場所一覧
$locations = $db->query('SELECT * FROM locations WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

$pageTitle = 'コマ管理';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.management-layout { display: grid; grid-template-columns: 1fr 400px; gap: 1.5rem; margin-bottom: 2rem; }
.calendar-wrapper { background: white; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }
.calendar-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; }
.calendar-title { font-size: 1.5rem; font-weight: bold; margin: 0; }
.calendar-nav { display: flex; gap: 0.5rem; align-items: center; }
.calendar-nav button { background: rgba(255,255,255,0.2); color: white; border: none; padding: 0.5rem 0.75rem; border-radius: 0.25rem; cursor: pointer; font-weight: bold; }
.calendar-nav button:hover { background: rgba(255,255,255,0.3); }
.calendar-month-label { font-weight: bold; min-width: 120px; text-align: center; }
.weekdays { display: grid; grid-template-columns: repeat(7, 1fr); background: #f7fafc; border-bottom: 2px solid #e2e8f0; }
.weekday { padding: 1rem; text-align: center; font-weight: bold; color: #4a5568; font-size: 0.9rem; }
.calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e2e8f0; padding: 1px; min-height: 500px; }
.calendar-cell { background: white; padding: 0.75rem; min-height: 100px; cursor: pointer; transition: background 0.2s; border: 2px solid transparent; }
.calendar-cell:hover { background: #f0f4ff; }
.calendar-cell.other-month { background: #f7fafc; color: #cbd5e0; }
.calendar-cell.today { background: #fef5e7; border-color: #f39c12; }
.cell-date { font-weight: bold; color: #2d3748; margin-bottom: 0.5rem; font-size: 0.9rem; }
.cell-schedules { display: flex; flex-direction: column; gap: 0.25rem; }
.schedule-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.7rem; font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
.schedule-badge:hover { transform: scale(1.05); }
.side-panel { background: white; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1.5rem; max-height: 700px; overflow-y: auto; position: sticky; top: 100px; }
.panel-title { font-size: 1.1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; }
.panel-empty { text-align: center; color: #a0aec0; padding: 2rem 1rem; }
.schedule-info { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
.info-row { display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9rem; }
.btn-action { width: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.75rem; border-radius: 0.5rem; font-weight: bold; cursor: pointer; margin-bottom: 0.5rem; }
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
.form-group { margin-bottom: 0.75rem; }
.form-group label { display: block; font-size: 0.85rem; font-weight: bold; color: #2d3748; margin-bottom: 0.25rem; }
.form-group select, .form-group input { width: 100%; padding: 0.5rem; border: 1px solid #cbd5e0; border-radius: 0.25rem; font-size: 0.85rem; }
.form-group input[type="checkbox"] { width: auto; margin-right: 0.5rem; }
.checkbox-group { display: flex; flex-wrap: wrap; gap: 1rem; padding: 0.5rem 0; }
.checkbox-item { display: flex; align-items: center; }
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
.modal-content { background: white; margin: 5% auto; padding: 2rem; width: 90%; max-width: 600px; border-radius: 0.5rem; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-height: 80vh; overflow-y: auto; }
.modal-close { float: right; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #718096; }
@media (max-width: 1200px) { .management-layout { grid-template-columns: 1fr; } .side-panel { position: static; max-height: none; } }
</style>

<div style="margin-bottom: 2rem;">
    <h1 style="margin: 0; color: #2d3748;">📋 コマ管理</h1>
    <p style="color: #718096; margin: 0.5rem 0 0;">カレンダーから日付を選択してコマを追加・削除します</p>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="management-layout">
    <!-- カレンダー -->
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
            ?>
                <div class="calendar-cell <?= $isToday ? 'today' : '' ?>" onclick="selectDate('<?= h($dateStr) ?>')">
                    <div class="cell-date"><?= $day ?></div>
                    <div class="cell-schedules">
                        <?php foreach (array_slice($daySchedules, 0, 2) as $s): ?>
                            <div class="schedule-badge" onclick="selectSchedule(event, <?= h($s['id']) ?>)">
                                🕐 <?= h(substr($s['start_time'], 0, 5)) ?> <?= h($s['location_name']) ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($daySchedules) > 2): ?>
                            <div class="schedule-badge" style="opacity: 0.6;">+<?= count($daySchedules) - 2 ?> 他</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- 右パネル -->
    <div class="side-panel">
        <div id="panelContent" class="panel-empty">
            <p>📍 カレンダーから日付を選択してください</p>
        </div>
    </div>
</div>

<!-- モーダル：コマ追加 -->
<div id="addScheduleModal" class="modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <h2 style="margin-top:0;">📅 <span id="modalDateDisplay"></span> にコマを追加</h2>
        <form id="addScheduleForm" onsubmit="submitForm(event)">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" id="formDate" name="date">
            
            <div class="form-group">
                <label>教室を選択 <span style="color:red">*</span></label>
                <div class="checkbox-group" id="locationCheckboxes"></div>
            </div>

            <div class="form-group">
                <label>時間帯を選択 <span style="color:red">*</span></label>
                <div class="checkbox-group" id="timeCheckboxes"></div>
            </div>

            <div class="form-group">
                <label>必要人数</label>
                <input type="number" name="required_staff_count" value="1" min="1" max="10">
            </div>

            <div class="form-group">
                <label>備考</label>
                <input type="text" name="note" maxlength="200" placeholder="例）教材準備必要">
            </div>

            <button type="submit" class="btn-action">コマを追加</button>
        </form>
    </div>
</div>

<script>
const timeSlots = [
    {value: '10:00:00', label: '10:00'},
    {value: '13:00:00', label: '13:00'},
    {value: '15:00:00', label: '15:00'},
    {value: '18:00:00', label: '18:00'}
];
const locations = <?= json_encode($locations) ?>;
const schedulesByDate = <?= json_encode($schedulesByDate) ?>;
const allSchedules = <?= json_encode($allSchedules) ?>;

function selectDate(dateStr) {
    const daySchedules = schedulesByDate[dateStr] || [];
    
    if (daySchedules.length === 0) {
        showAddModal(dateStr);
        return;
    }
    
    if (daySchedules.length === 1) {
        selectSchedule(null, daySchedules[0].id);
        return;
    }
    
    let html = '<div class="panel-title">📅 ' + dateStr + ' のコマ</div>';
    html += '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
    daySchedules.forEach(s => {
        html += '<div style="background:#f0f4ff;padding:1rem;border-radius:0.5rem;cursor:pointer;" onclick="selectSchedule(null, ' + s.id + ')">';
        html += '<div style="font-weight:bold;color:#667eea;">' + s.start_time.substring(0,5) + ' - ' + s.end_time.substring(0,5) + '</div>';
        html += '<div style="font-size:0.9rem;color:#718096;">' + s.location_name + '</div>';
        html += '</div>';
    });
    html += '</div>';
    html += '<button class="btn-action" onclick="showAddModal(\'' + dateStr + '\')" style="margin-top:1rem;">➕ コマを追加</button>';
    document.getElementById('panelContent').innerHTML = html;
}

function selectSchedule(e, scheduleId) {
    if (e) e.stopPropagation();
    const schedule = allSchedules.find(s => s.id == scheduleId);
    if (!schedule) return;
    
    let html = '<div class="schedule-info">';
    html += '<div class="info-row"><div>日付</div><div>' + new Date(schedule.date).toLocaleDateString('ja-JP') + '</div></div>';
    html += '<div class="info-row"><div>時間</div><div>' + schedule.start_time.substring(0,5) + ' - ' + schedule.end_time.substring(0,5) + '</div></div>';
    html += '<div class="info-row"><div>場所</div><div>' + schedule.location_name + '</div></div>';
    html += '<div class="info-row"><div>必要人数</div><div>' + schedule.required_staff_count + '名</div></div>';
    if (schedule.note) html += '<div class="info-row"><div>備考</div><div style="font-size:0.85rem;">' + schedule.note + '</div></div>';
    html += '</div>';
    html += '<button class="btn-action" onclick="deleteSchedule(' + scheduleId + ')">🗑️ コマを削除</button>';
    document.getElementById('panelContent').innerHTML = html;
}

function showAddModal(dateStr) {
    const dateObj = new Date(dateStr);
    document.getElementById('modalDateDisplay').textContent = dateObj.toLocaleDateString('ja-JP');
    document.getElementById('formDate').value = dateStr;
    
    // 教室チェックボックス
    let locHtml = '';
    locations.forEach(loc => {
        locHtml += '<div class="checkbox-item">';
        locHtml += '<input type="checkbox" name="location_ids" value="' + loc.id + '" id="loc_' + loc.id + '">';
        locHtml += '<label for="loc_' + loc.id + '" style="margin: 0; font-weight: normal;">' + loc.name + '</label>';
        locHtml += '</div>';
    });
    document.getElementById('locationCheckboxes').innerHTML = locHtml;
    
    // 時間帯チェックボックス
    let timeHtml = '';
    timeSlots.forEach(slot => {
        timeHtml += '<div class="checkbox-item">';
        timeHtml += '<input type="checkbox" name="time_slots" value="' + slot.value + '" id="time_' + slot.value + '">';
        timeHtml += '<label for="time_' + slot.value + '" style="margin: 0; font-weight: normal;">' + slot.label + '</label>';
        timeHtml += '</div>';
    });
    document.getElementById('timeCheckboxes').innerHTML = timeHtml;
    
    document.getElementById('addScheduleModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('addScheduleModal').style.display = 'none';
}

function submitForm(e) {
    e.preventDefault();
    const dateStr = document.getElementById('formDate').value;
    const locationIds = Array.from(document.querySelectorAll('input[name="location_ids"]:checked')).map(x => x.value);
    const timeSlotValues = Array.from(document.querySelectorAll('input[name="time_slots"]:checked')).map(x => x.value);
    
    if (locationIds.length === 0 || timeSlotValues.length === 0) {
        alert('教室と時間帯を1つ以上選択してください');
        return;
    }
    
    const requiredCount = document.querySelector('input[name="required_staff_count"]').value;
    const note = document.querySelector('input[name="note"]').value;
    
    // 各組み合わせでコマを追加
    let completed = 0;
    let total = locationIds.length * timeSlotValues.length;
    
    locationIds.forEach(locId => {
        timeSlotValues.forEach(timeSlot => {
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            formData.append('action', 'add');
            formData.append('date', dateStr);
            formData.append('location_id', locId);
            formData.append('start_time', timeSlot);
            formData.append('required_staff_count', requiredCount);
            formData.append('note', note);
            
            fetch('/admin/schedules_api.php', {method: 'POST', body: formData})
                .then(r => r.json())
                .then(data => {
                    completed++;
                    if (completed === total) {
                        alert('コマを追加しました');
                        closeModal();
                        location.reload();
                    }
                })
                .catch(err => console.error('Error:', err));
        });
    });
}

function deleteSchedule(scheduleId) {
    if (!confirm('このコマを削除してもよろしいですか？')) return;
    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    formData.append('action', 'delete');
    formData.append('schedule_id', scheduleId);
    
    fetch('/admin/schedules_api.php', {method: 'POST', body: formData})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('コマを削除しました');
                location.reload();
            } else alert(data.error || 'エラー');
        });
}

window.addEventListener('click', (e) => {
    const modal = document.getElementById('addScheduleModal');
    if (e.target === modal) closeModal();
});
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
