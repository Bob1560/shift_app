<?php
require_once __DIR__ . '/../includes/auth.php';
requireInstructor();

$db  = Database::getInstance();
$uid = currentUserId();
$errors = [];

// 申請・キャンセル処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $action     = $_POST['action'] ?? '';
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);

        if ($action === 'request' && $scheduleId) {
            // 締切チェック（コマの日付を確認）
            $stmt = $db->prepare('SELECT date FROM schedules WHERE id = ?');
            $stmt->execute([$scheduleId]);
            $schedDate = $stmt->fetchColumn();

            if ($schedDate && $schedDate >= date('Y-m-d')) {
                try {
                    $db->prepare("INSERT INTO shift_requests (user_id, schedule_id, status) VALUES (?, ?, 'pending')")
                       ->execute([$uid, $scheduleId]);
                    setFlash('success', '出勤希望を申請しました。');
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        setFlash('warning', 'すでに申請済みです。');
                    } else {
                        throw $e;
                    }
                }
            } else {
                $errors[] = '過去の日付への申請はできません。';
            }
        } elseif ($action === 'cancel' && $scheduleId) {
            // 確定済みでない場合のみキャンセル可
            $stmt = $db->prepare('SELECT COUNT(*) FROM shift_assignments WHERE user_id = ? AND schedule_id = ?');
            $stmt->execute([$uid, $scheduleId]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = '確定済みのシフトはキャンセルできません。管理者にご連絡ください。';
            } else {
                $db->prepare("UPDATE shift_requests SET status = 'cancelled' WHERE user_id = ? AND schedule_id = ?")
                   ->execute([$uid, $scheduleId]);
                setFlash('info', '申請をキャンセルしました。');
            }
        }
        if (empty($errors)) {
            header('Location: /instructor/request.php?month=' . ($_POST['month'] ?? date('Y-m')));
            exit;
        }
    }
}

// 表示月
$month = $_GET['month'] ?? date('Y-m');
try {
    $startDate = new DateTime($month . '-01');
} catch (Exception $e) {
    $startDate = new DateTime(date('Y-m') . '-01');
}
$endDate = (clone $startDate)->modify('+1 month');

// この月のコマ取得
$stmt = $db->prepare('
    SELECT s.*, l.name AS location_name,
           sr.status AS my_status, sr.id AS request_id,
           (SELECT COUNT(*) FROM shift_assignments sa WHERE sa.user_id = ? AND sa.schedule_id = s.id) AS is_assigned
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_requests sr ON sr.schedule_id = s.id AND sr.user_id = ?
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date, s.start_time
');
$stmt->execute([$uid, $uid, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$schedules = $stmt->fetchAll();

// 日付→コマのマップ
$byDate = [];
foreach ($schedules as $s) {
    $byDate[$s['date']][] = $s;
}

// カレンダー用：月初の曜日と日数
$firstDow = (int)$startDate->format('w'); // 0=日
$daysInMonth = (int)$startDate->format('t');

$pageTitle = '出勤希望申請';
include __DIR__ . '/../includes/layout_header.php';
?>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">📅 出勤希望申請</h1>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <a href="?month=<?= h((clone $startDate)->modify('-1 month')->format('Y-m')) ?>" class="btn btn-secondary btn-sm">◀ 前月</a>
        <strong><?= h($startDate->format('Y年n月')) ?></strong>
        <a href="?month=<?= h($endDate->format('Y-m')) ?>" class="btn btn-secondary btn-sm">翌月 ▶</a>
    </div>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<!-- 凡例 -->
<div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;font-size:0.85rem;">
    <span><span class="cal-event available" style="display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;"></span>申請可能</span>
    <span><span class="cal-event requested" style="display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;"></span>申請中</span>
    <span><span class="cal-event confirmed" style="display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;"></span>確定済み</span>
</div>

<div class="card">
    <div class="calendar-wrap">
        <table class="calendar">
            <thead>
                <tr>
                    <?php
                    $dayNames = ['日','月','火','水','木','金','土'];
                    foreach ($dayNames as $i => $dn):
                    ?>
                    <th style="color:<?= $i===0?'#e53e3e':($i===6?'#3182ce':'') ?>"><?= $dn ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $day = 1;
                $rows = ceil(($daysInMonth + $firstDow) / 7);
                for ($row = 0; $row < $rows; $row++):
                ?>
                <tr>
                    <?php for ($col = 0; $col < 7; $col++):
                        $cellDay = $row * 7 + $col - $firstDow + 1;
                        $isCurrentMonth = ($cellDay >= 1 && $cellDay <= $daysInMonth);
                        $dateStr = $isCurrentMonth ? $startDate->format('Y-m-') . sprintf('%02d', $cellDay) : '';
                        $isToday = $dateStr === date('Y-m-d');
                        $isPast  = $dateStr && $dateStr < date('Y-m-d');
                        $classes = [];
                        if (!$isCurrentMonth) $classes[] = 'other-month';
                        if ($isToday) $classes[] = 'today';
                        if ($col === 0) $classes[] = 'sunday';
                        if ($col === 6) $classes[] = 'saturday';
                    ?>
                    <td class="<?= implode(' ', $classes) ?>">
                        <?php if ($isCurrentMonth): ?>
                            <span class="day-num"><?= $cellDay ?></span>
                            <?php if (!$isPast && isset($byDate[$dateStr])): ?>
                                <?php foreach ($byDate[$dateStr] as $s): ?>
                                    <?php
                                    if ($s['is_assigned']) {
                                        $cls = 'confirmed';
                                        $label = '✅ ' . h($s['location_name']);
                                    } elseif ($s['my_status'] === 'pending') {
                                        $cls = 'requested';
                                        $label = '⏳ ' . h($s['location_name']);
                                    } elseif ($s['my_status'] === 'approved') {
                                        $cls = 'confirmed';
                                        $label = '✅ ' . h($s['location_name']);
                                    } elseif ($s['my_status'] === 'cancelled' || $s['my_status'] === 'rejected') {
                                        $cls = '';
                                        $label = h($s['location_name']);
                                    } else {
                                        $cls = 'available';
                                        $label = h($s['location_name']);
                                    }
                                    ?>
                                    <a href="#" class="cal-event <?= $cls ?>"
                                       onclick="showScheduleModal(<?= $s['id'] ?>, '<?= h(addslashes($s['location_name'])) ?>', '<?= h($dateStr) ?>', '<?= h(substr($s['start_time'],0,5)) ?>', '<?= h(substr($s['end_time'],0,5)) ?>', '<?= h($s['my_status'] ?? '') ?>', <?= $s['is_assigned'] ? 'true' : 'false' ?>); return false;"
                                       title="<?= h($s['location_name']) ?> <?= h(substr($s['start_time'],0,5)) ?>〜<?= h(substr($s['end_time'],0,5)) ?>">
                                        <?= $label ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php elseif ($isPast && isset($byDate[$dateStr])): ?>
                                <?php foreach ($byDate[$dateStr] as $s): ?>
                                    <span class="cal-event" style="background:#f7fafc;color:#a0aec0;cursor:default;">
                                        <?= h($s['location_name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php endfor; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- コマ詳細モーダル -->
<div id="schedule-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:2rem;width:100%;max-width:420px;margin:1rem;">
        <h3 id="modal-title" style="margin-bottom:1rem;color:#1a365d;"></h3>
        <div id="modal-info" style="font-size:0.9rem;color:#4a5568;margin-bottom:1.5rem;"></div>
        <div id="modal-actions" style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">閉じる</button>
        </div>
    </div>
</div>

<form id="action-form" method="POST" action="/instructor/request.php">
    <?= csrfField() ?>
    <input type="hidden" name="month" value="<?= h($month) ?>">
    <input type="hidden" name="action" id="form-action">
    <input type="hidden" name="schedule_id" id="form-schedule-id">
</form>

<script>
function showScheduleModal(id, loc, date, start, end, status, isAssigned) {
    document.getElementById('modal-title').textContent = loc;
    document.getElementById('modal-info').innerHTML =
        '📅 ' + date + '<br>🕐 ' + start + ' 〜 ' + end;

    const actions = document.getElementById('modal-actions');
    actions.innerHTML = '<button type="button" class="btn btn-secondary" onclick="closeModal()">閉じる</button>';

    if (isAssigned) {
        actions.innerHTML += '<span style="color:#38a169;font-weight:bold;padding:8px;">✅ 確定済み</span>';
    } else if (status === 'pending') {
        actions.innerHTML += `<button type="button" class="btn btn-warning"
            onclick="submitAction('cancel', ${id})">申請をキャンセル</button>`;
    } else if (!status || status === 'cancelled' || status === 'rejected') {
        actions.innerHTML += `<button type="button" class="btn btn-primary"
            onclick="submitAction('request', ${id})">出勤希望を申請する</button>`;
    }

    document.getElementById('schedule-modal').style.display = 'flex';
}

function submitAction(action, id) {
    document.getElementById('form-action').value = action;
    document.getElementById('form-schedule-id').value = id;
    document.getElementById('action-form').submit();
}

function closeModal() {
    document.getElementById('schedule-modal').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
