<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();
$errors = [];

// コマ追加処理（AJAX用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => '不正なリクエストです。']);
        exit;
    }
    
    $locationId    = (int)($_POST['location_id'] ?? 0);
    $date          = $_POST['date'] ?? '';
    $startTime     = $_POST['start_time'] ?? '';
    $endTime       = $_POST['end_time'] ?? '';
    $requiredCount = (int)($_POST['required_staff_count'] ?? 1);
    $note          = trim($_POST['note'] ?? '');

    if (!$locationId || !$date || !$startTime || !$endTime) {
        http_response_code(400);
        echo json_encode(['error' => '必須項目を入力してください。']);
        exit;
    } elseif ($startTime >= $endTime) {
        http_response_code(400);
        echo json_encode(['error' => '終了時刻は開始時刻より後に設定してください。']);
        exit;
    }
    
    $stmt = $db->prepare('
        INSERT INTO schedules (location_id, date, start_time, end_time, required_staff_count, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$locationId, $date, $startTime, $endTime, $requiredCount, $note ?: null, currentUserId()]);
    
    echo json_encode(['success' => true, 'message' => 'コマを追加しました。']);
    exit;
}

// コマ削除処理（AJAX用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => '不正なリクエストです。']);
        exit;
    }
    
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);
    
    $stmt = $db->prepare('SELECT COUNT(*) FROM shift_assignments WHERE schedule_id = ?');
    $stmt->execute([$scheduleId]);
    if ($stmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['error' => '確定済みシフトがあるコマは削除できません。']);
        exit;
    }
    
    $db->prepare('DELETE FROM schedules WHERE id = ?')->execute([$scheduleId]);
    echo json_encode(['success' => true, 'message' => 'コマを削除しました。']);
    exit;
}

// 表示月
$month = $_GET['month'] ?? date('Y-m');
try {
    $startDate = new DateTime($month . '-01');
} catch (Exception $e) {
    $startDate = new DateTime(date('Y-m') . '-01');
}
$endDate = (clone $startDate)->modify('+1 month');

// カレンダー作成用
$firstDay = (int)$startDate->format('w'); // 0=日,1=月...
$daysInMonth = (int)$startDate->format('t');

// コマ一覧取得（日付ごと）
$stmt = $db->prepare('
    SELECT s.*, l.name AS location_name,
           GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ", ") AS assigned_names
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_assignments sa ON sa.schedule_id = s.id
    LEFT JOIN users u ON sa.user_id = u.id
    WHERE s.date >= ? AND s.date < ?
    GROUP BY s.id
    ORDER BY s.date, s.start_time
');
$stmt->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
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

$pageTitle = 'コマ管理（カレンダー）';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
.calendar-container {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: #e2e8f0;
    border: 1px solid #cbd5e0;
    margin-bottom: 2rem;
}

.calendar-header {
    background: #2d3748;
    color: white;
    padding: 0.75rem;
    text-align: center;
    font-weight: bold;
    font-size: 0.9rem;
}

.calendar-day {
    background: white;
    min-height: 120px;
    padding: 0.5rem;
    position: relative;
}

.calendar-day.other-month {
    background: #f7fafc;
    color: #cbd5e0;
}

.calendar-day-number {
    font-weight: bold;
    margin-bottom: 0.25rem;
    font-size: 0.9rem;
}

.calendar-day.today {
    background: #fef5e7;
    border: 2px solid #f39c12;
}

.schedule-item {
    background: #e8f4f8;
    border-left: 3px solid #3182ce;
    padding: 0.35rem;
    margin: 0.25rem 0;
    font-size: 0.75rem;
    border-radius: 2px;
    cursor: pointer;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.schedule-item:hover {
    background: #bee3f8;
}

.schedule-time {
    font-weight: bold;
    color: #2d3748;
}

.schedule-location {
    color: #4a5568;
}

.schedule-instructors {
    color: #667eea;
    font-size: 0.7rem;
    margin-top: 0.1rem;
}

.add-schedule-btn {
    position: absolute;
    top: 2px;
    right: 2px;
    background: #48bb78;
    color: white;
    border: none;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    padding: 0;
    cursor: pointer;
    font-size: 0.8rem;
    display: none;
}

.calendar-day:hover .add-schedule-btn {
    display: block;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal.show {
    display: block;
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 2rem;
    border-radius: 0.5rem;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #718096;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.25rem;
}
</style>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">📅 コマ管理（カレンダー）</h1>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <a href="?month=<?= h((clone $startDate)->modify('-1 month')->format('Y-m')) ?>" class="btn btn-secondary btn-sm">◀ 前月</a>
        <strong><?= h($startDate->format('Y年n月')) ?></strong>
        <a href="?month=<?= h($endDate->format('Y-m')) ?>" class="btn btn-secondary btn-sm">翌月 ▶</a>
        <a href="<?= BASE_PATH ?>/admin/schedules.php" class="btn btn-secondary btn-sm">リスト表示</a>
    </div>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<!-- カレンダー -->
<div class="card">
    <div class="calendar-container">
        <!-- 曜日ヘッダー -->
        <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $day): ?>
            <div class="calendar-header"><?= $day ?></div>
        <?php endforeach; ?>

        <!-- 他月の日付（埋め） -->
        <?php for ($i = 0; $i < $firstDay; $i++): ?>
            <div class="calendar-day other-month"></div>
        <?php endfor; ?>

        <!-- 当月の日付 -->
        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $dateStr = $startDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
            $isToday = ($dateStr === date('Y-m-d'));
            $daySchedules = $schedulesByDate[$dateStr] ?? [];
        ?>
            <div class="calendar-day <?= $isToday ? 'today' : '' ?>">
                <div class="calendar-day-number"><?= $day ?></div>
                <?php foreach ($daySchedules as $s): ?>
                    <div class="schedule-item" onclick="editSchedule(<?= h($s['id']) ?>, '<?= h($s['date']) ?>')">
                        <div class="schedule-time"><?= h(substr($s['start_time'], 0, 5)) ?></div>
                        <div class="schedule-location"><?= h($s['location_name']) ?></div>
                        <?php if ($s['assigned_names']): ?>
                            <div class="schedule-instructors">👥 <?= h($s['assigned_names']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button class="add-schedule-btn" onclick="openAddModal('<?= $dateStr ?>')">+</button>
            </div>
        <?php endfor; ?>
    </div>
</div>

<!-- コマ追加モーダル -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>コマを追加</h2>
            <button class="modal-close" onclick="closeAddModal()">✕</button>
        </div>
        <form id="addForm">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" id="modalDate" name="date">

            <div class="form-group">
                <label>場所 <span style="color:red">*</span></label>
                <select name="location_id" class="form-control" required>
                    <option value="">選択してください</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= h($loc['id']) ?>"><?= h($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label>開始時刻 <span style="color:red">*</span></label>
                    <select name="start_time" class="form-control" required>
                        <option value="">選択</option>
                        <option value="10:00:00">10:00</option>
                        <option value="13:00:00">13:00</option>
                        <option value="14:45:00">14:45</option>
                        <option value="15:00:00">15:00</option>
                        <option value="18:00:00">18:00</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>終了時刻 <span style="color:red">*</span></label>
                    <select name="end_time" class="form-control" required>
                        <option value="">選択</option>
                        <option value="12:00:00">12:00</option>
                        <option value="15:00:00">15:00</option>
                        <option value="16:45:00">16:45</option>
                        <option value="17:00:00">17:00</option>
                        <option value="20:00:00">20:00</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>必要人数</label>
                <input type="number" name="required_staff_count" class="form-control" value="1" min="1" max="10">
            </div>

            <div class="form-group">
                <label>備考</label>
                <input type="text" name="note" class="form-control" maxlength="200" placeholder="例）教材準備必要">
            </div>

            <div id="modalError" style="display:none;" class="alert alert-error"></div>

            <div style="display:flex;gap:0.5rem;">
                <button type="submit" class="btn btn-primary">追加</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">キャンセル</button>
            </div>
        </form>
    </div>
</div>

<!-- コマ編集・削除モーダル -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>コマの詳細</h2>
            <button class="modal-close" onclick="closeEditModal()">✕</button>
        </div>
        <div id="editContent"></div>
    </div>
</div>

<script>
const BASE_PATH = <?= json_encode(BASE_PATH) ?>;
function openAddModal(dateStr) {
    document.getElementById('modalDate').value = dateStr;
    document.getElementById('addForm').reset();
    document.getElementById('modalError').style.display = 'none';
    document.getElementById('addModal').classList.add('show');
}

function closeAddModal() {
    document.getElementById('addModal').classList.remove('show');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
}

document.getElementById('addForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(document.getElementById('addForm'));
    
    try {
        const response = await fetch(BASE_PATH + '/admin/schedules_calendar.php', {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            const data = await response.json();
            alert(data.message);
            closeAddModal();
            location.reload();
        } else {
            const data = await response.json();
            document.getElementById('modalError').textContent = data.error;
            document.getElementById('modalError').style.display = 'block';
        }
    } catch (error) {
        alert('エラーが発生しました: ' + error.message);
    }
});

function editSchedule(scheduleId, dateStr) {
    // 詳細ページへリダイレクト
    window.location.href = BASE_PATH + '/admin/schedule_detail.php?id=' + scheduleId;
}

window.addEventListener('click', (e) => {
    const addModal = document.getElementById('addModal');
    if (e.target === addModal) {
        closeAddModal();
    }
    const editModal = document.getElementById('editModal');
    if (e.target === editModal) {
        closeEditModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
