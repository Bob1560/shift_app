<?php
require_once __DIR__ . '/../includes/auth.php';
requireInstructor();

$db  = Database::getInstance();
$uid = currentUserId();
$errors = [];

// -------------------------------------------------------
// POST: 申請・キャンセル（日付×教室の代表schedule_idで操作）
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $action     = $_POST['action'] ?? '';
        $scheduleId = (int)($_POST['schedule_id'] ?? 0); // 代表コマのID
        $retMonth   = $_POST['month'] ?? date('Y-m');

        if ($action === 'request' && $scheduleId) {
            // コマの日付確認
            $stmt = $db->prepare('SELECT date FROM schedules WHERE id = ?');
            $stmt->execute([$scheduleId]);
            $schedDate = $stmt->fetchColumn();

            if (!$schedDate || $schedDate < date('Y-m-d')) {
                $errors[] = '過去の日付への申請はできません。';
            } else {
                try {
                    $db->prepare("INSERT INTO shift_requests (user_id, schedule_id, status) VALUES (?, ?, 'pending')")
                       ->execute([$uid, $scheduleId]);
                    setFlash('success', '出勤希望を申請しました。');
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        setFlash('warning', 'すでに申請済みです。');
                    } else { throw $e; }
                }
            }
        } elseif ($action === 'cancel' && $scheduleId) {
            // 確定済みならキャンセル不可
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
            redirect('/instructor/request.php?month=' . urlencode($retMonth));
        }
    }
}

// -------------------------------------------------------
// データ取得：日付×教室 単位に集約
// -------------------------------------------------------
$month = $_GET['month'] ?? date('Y-m');
try {
    $startDate = new DateTime($month . '-01');
} catch (Exception $e) {
    $startDate = new DateTime(date('Y-m') . '-01');
}
$endDate     = (clone $startDate)->modify('+1 month');
$firstDow    = (int)$startDate->format('w');
$daysInMonth = (int)$startDate->format('t');

// 月内の全コマ + 自分の申請状況 + 割り当て状況
$stmt = $db->prepare('
    SELECT
        s.id          AS schedule_id,
        s.date,
        s.start_time,
        s.end_time,
        l.id          AS location_id,
        l.name        AS location_name,
        l.sort_order,
        sr.id         AS request_id,
        sr.status     AS my_status,
        (SELECT COUNT(*) FROM shift_assignments sa
         WHERE sa.user_id = ? AND sa.schedule_id = s.id) AS is_assigned
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_requests sr ON sr.schedule_id = s.id AND sr.user_id = ?
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date, l.sort_order, s.start_time
');
$stmt->execute([$uid, $uid, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$rows = $stmt->fetchAll();

// $byDate[$date][$locName] = [
//   'rep_schedule_id' => 代表コマID（最初のコマ）
//   'location_name'   => 教室名
//   'slots'           => [ ['start'=>..,'end'=>..], ... ]  時間枠（表示のみ）
//   'my_status'       => 'pending'|'approved'|'cancelled'|'rejected'|null
//   'is_assigned'     => 0|1
// ]
$byDate = [];
foreach ($rows as $r) {
    $d   = $r['date'];
    $loc = $r['location_name'];
    if (!isset($byDate[$d][$loc])) {
        $byDate[$d][$loc] = [
            'rep_schedule_id' => $r['schedule_id'], // 最初のコマを代表に
            'location_name'   => $loc,
            'slots'           => [],
            'my_status'       => $r['my_status'],
            'is_assigned'     => (int)$r['is_assigned'],
        ];
    }
    $byDate[$d][$loc]['slots'][] = [
        'start' => $r['start_time'],
        'end'   => $r['end_time'],
    ];
    // 確定済みフラグは OR で集約
    if ($r['is_assigned']) $byDate[$d][$loc]['is_assigned'] = 1;
    // ステータスも優先度順に更新（confirmed > pending > null）
    if (!$byDate[$d][$loc]['my_status'] && $r['my_status']) {
        $byDate[$d][$loc]['my_status'] = $r['my_status'];
    }
}

$pageTitle = '出勤希望申請';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.req-wrap { display: grid; grid-template-columns: 1fr 360px; gap: 1.5rem; align-items: start; }

/* カレンダー */
.cal-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; }
.cal-month-nav { background: linear-gradient(135deg,#667eea,#764ba2); color: white; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; }
.cal-month-nav strong { font-size: 1.15rem; }
.mnav { background: rgba(255,255,255,0.2); color: white; border: none; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.95rem; }
.mnav:hover { background: rgba(255,255,255,0.35); }
.weekdays { display: grid; grid-template-columns: repeat(7,1fr); background: #f0f4ff; border-bottom: 1px solid #e2e8f0; }
.weekday { padding: 6px; text-align: center; font-size: 0.78rem; font-weight: bold; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 1px; background: #e2e8f0; padding: 1px; }
.cal-cell { background: white; padding: 5px; min-height: 80px; }
.cal-cell.past { background: #fafbfc; }
.cal-cell.today { background: #fef9e7; }
.cal-cell.selected { outline: 2px solid #667eea; outline-offset: -2px; }
.cal-cell.other-month { background: #fafbfc; opacity: 0.35; }
.cell-day { font-size: 0.75rem; font-weight: bold; color: #2d3748; margin-bottom: 3px; }
.cell-day.sun { color: #e53e3e; }
.cell-day.sat { color: #3182ce; }

/* 教室チップ */
.loc-chip {
    display: block;
    font-size: 0.6rem;
    font-weight: bold;
    padding: 2px 5px;
    border-radius: 3px;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
    border: none;
    width: 100%;
    text-align: left;
    color: white;
}
.loc-chip.available  { background: #a0aec0; }          /* 未申請 */
.loc-chip.pending    { background: #ed8936; }           /* 申請中 */
.loc-chip.confirmed  { background: #38a169; }           /* 確定済み */
.loc-chip.past       { background: #cbd5e0; cursor: default; }  /* 過去 */
.loc-chip:not(.past):hover { opacity: 0.82; }

/* 凡例 */
.legend { display: flex; gap: 12px; flex-wrap: wrap; font-size: 0.78rem; color: #718096; padding: 8px 12px; border-top: 1px solid #e2e8f0; }
.legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }

/* サイドパネル */
.side-panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.25rem; position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow-y: auto; }
.panel-empty { text-align: center; color: #a0aec0; padding: 3rem 1rem; font-size: 0.9rem; line-height: 1.8; }
.panel-date-title { font-size: 1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; }

/* 教室ブロック */
.loc-block { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 1rem; overflow: hidden; }
.loc-block-header { padding: 8px 12px; font-weight: bold; font-size: 0.9rem; color: white; }
.loc-block-header.available { background: #a0aec0; }
.loc-block-header.pending   { background: #ed8936; }
.loc-block-header.confirmed { background: #38a169; }
.loc-block-header.past      { background: #cbd5e0; }
.loc-block-body { padding: 10px 12px; }

/* 時間枠バッジ（表示のみ） */
.slots-label { font-size: 0.72rem; font-weight: bold; color: #718096; margin-bottom: 5px; letter-spacing: 0.04em; }
.slot-list { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 12px; }
.slot-badge { background: #ebf8ff; color: #2b6cb0; font-size: 0.78rem; font-weight: bold; padding: 3px 8px; border-radius: 4px; }

/* ステータス表示 */
.status-row { margin-bottom: 10px; font-size: 0.85rem; }
.status-badge { display: inline-block; padding: 3px 10px; border-radius: 5px; font-weight: bold; font-size: 0.82rem; }
.status-badge.pending   { background: #fef3c7; color: #92400e; }
.status-badge.confirmed { background: #c6f6d5; color: #276749; }
.status-badge.cancelled { background: #f7fafc; color: #718096; }
.status-badge.rejected  { background: #fff5f5; color: #c53030; }

/* アクションボタン */
.action-btn { width: 100%; padding: 0.65rem; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.9rem; }
.action-btn.request { background: linear-gradient(135deg,#667eea,#764ba2); color: white; }
.action-btn.cancel  { background: #fed7d7; color: #c53030; }
.action-btn:hover { opacity: 0.88; }

@media (max-width: 960px) { .req-wrap { grid-template-columns: 1fr; } .side-panel { position: static; max-height: none; } }
</style>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">📅 出勤希望申請</h1>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="req-wrap">
    <!-- カレンダー -->
    <div class="cal-card">
        <div class="cal-month-nav">
            <button class="mnav" onclick="location.href='?month=<?= h((clone $startDate)->modify('-1 month')->format('Y-m')) ?>'">◀</button>
            <strong><?= h($startDate->format('Y年n月')) ?></strong>
            <button class="mnav" onclick="location.href='?month=<?= h($endDate->format('Y-m')) ?>'">▶</button>
        </div>

        <div class="weekdays">
            <?php foreach (['日','月','火','水','木','金','土'] as $i => $d): ?>
                <div class="weekday" style="color:<?= $i===0?'#e53e3e':($i===6?'#3182ce':'#667eea') ?>"><?= $d ?></div>
            <?php endforeach; ?>
        </div>

        <div class="cal-grid">
            <?php for ($i = 0; $i < $firstDow; $i++): ?>
                <div class="cal-cell other-month"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $dow     = ($firstDow + $day - 1) % 7;
                $dateStr = $startDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isPast  = $dateStr < date('Y-m-d');
                $isToday = $dateStr === date('Y-m-d');
                $dayLocs = $byDate[$dateStr] ?? [];
                $cellClass = $isPast ? 'past' : ($isToday ? 'today' : '');
            ?>
                <div class="cal-cell <?= $cellClass ?>" id="cell-<?= $dateStr ?>"
                     onclick="selectDate('<?= h($dateStr) ?>', <?= $isPast?'true':'false' ?>)">
                    <div class="cell-day <?= $dow===0?'sun':($dow===6?'sat':'') ?>"><?= $day ?></div>
                    <?php
                    $shown = 0;
                    foreach ($dayLocs as $locName => $locData):
                        if ($shown >= 3) { echo '<div style="font-size:0.58rem;color:#a0aec0;">+'.(count($dayLocs)-3).'</div>'; break; }
                        if ($isPast) {
                            $chipClass = 'past';
                        } elseif ($locData['is_assigned']) {
                            $chipClass = 'confirmed';
                        } elseif ($locData['my_status'] === 'pending') {
                            $chipClass = 'pending';
                        } else {
                            $chipClass = 'available';
                        }
                    ?>
                        <button class="loc-chip <?= $chipClass ?>"
                                <?= $isPast ? 'disabled' : '' ?>
                                onclick="event.stopPropagation(); selectDate('<?= h($dateStr) ?>', <?= $isPast?'true':'false' ?>, '<?= h($locName) ?>')"
                                title="<?= h($locName) ?>"><?= h(mb_substr($locName, 0, 6)) ?></button>
                    <?php
                        $shown++;
                    endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>

        <!-- 凡例 -->
        <div class="legend">
            <span><span class="legend-dot" style="background:#a0aec0;"></span>未申請</span>
            <span><span class="legend-dot" style="background:#ed8936;"></span>申請中</span>
            <span><span class="legend-dot" style="background:#38a169;"></span>確定済み</span>
        </div>
    </div>

    <!-- サイドパネル -->
    <div class="side-panel" id="sidePanel">
        <div class="panel-empty">
            <p>📍 教室名をクリックすると<br>時間枠と申請状況を確認できます</p>
        </div>
    </div>
</div>

<!-- 申請フォーム（hidden） -->
<form id="actionForm" method="POST" action="<?= BASE_PATH ?>/instructor/request.php">
    <?= csrfField() ?>
    <input type="hidden" name="month" value="<?= h($month) ?>">
    <input type="hidden" name="action" id="formAction">
    <input type="hidden" name="schedule_id" id="formScheduleId">
</form>

<script>
const BY_DATE = <?= json_encode($byDate, JSON_UNESCAPED_UNICODE) ?>;
let currentDateStr = null;

function selectDate(dateStr, isPast, focusLoc) {
    currentDateStr = dateStr;

    // ハイライト
    document.querySelectorAll('.cal-cell.selected').forEach(el => el.classList.remove('selected'));
    const cell = document.getElementById('cell-' + dateStr);
    if (cell) cell.classList.add('selected');

    const byLoc = BY_DATE[dateStr] || {};
    const dt = new Date(dateStr + 'T00:00:00')
        .toLocaleDateString('ja-JP', {year:'numeric', month:'long', day:'numeric', weekday:'short'});

    let html = '<div class="panel-date-title">📅 ' + esc(dt) + (isPast ? ' <span style="color:#a0aec0;font-size:0.8rem;">（過去）</span>' : '') + '</div>';

    const locNames = Object.keys(byLoc);
    if (locNames.length === 0) {
        html += '<p style="color:#a0aec0;text-align:center;padding:2rem 0;">この日のコマはありません</p>';
    } else {
        locNames.forEach(locName => {
            html += buildLocBlock(locName, byLoc[locName], isPast);
        });
    }

    document.getElementById('sidePanel').innerHTML = html;

    // 指定教室へスクロール
    if (focusLoc) {
        const el = document.getElementById('locblock-' + CSS.escape(focusLoc));
        if (el) el.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
}

function buildLocBlock(locName, locData, isPast) {
    // ステータス判定
    let statusKey, statusLabel, headerClass;
    if (locData.is_assigned) {
        statusKey = 'confirmed'; statusLabel = '✅ 担当確定済み'; headerClass = 'confirmed';
    } else if (locData.my_status === 'pending') {
        statusKey = 'pending'; statusLabel = '⏳ 申請中'; headerClass = 'pending';
    } else if (locData.my_status === 'approved') {
        statusKey = 'confirmed'; statusLabel = '✅ 承認済み'; headerClass = 'confirmed';
    } else if (locData.my_status === 'rejected') {
        statusKey = 'rejected'; statusLabel = '✕ 却下'; headerClass = 'available';
    } else if (locData.my_status === 'cancelled') {
        statusKey = 'cancelled'; statusLabel = '− キャンセル済み'; headerClass = 'available';
    } else {
        statusKey = 'available'; statusLabel = '未申請'; headerClass = 'available';
    }
    if (isPast) headerClass = 'past';

    // 時間枠バッジ
    let slotBadges = locData.slots
        .slice().sort((a,b) => a.start.localeCompare(b.start))
        .map(s => '<span class="slot-badge">' + esc(s.start.substring(0,5)) + '〜' + esc(s.end.substring(0,5)) + '</span>')
        .join('');

    // ステータス行
    let statusHtml = '';
    if (!isPast && locData.my_status) {
        statusHtml = '<div class="status-row">状況：<span class="status-badge ' + statusKey + '">' + statusLabel + '</span></div>';
    }

    // アクションボタン
    let actionHtml = '';
    if (!isPast) {
        if (locData.is_assigned || locData.my_status === 'approved') {
            actionHtml = '<div style="color:#38a169;font-weight:bold;font-size:0.9rem;text-align:center;padding:6px 0;">✅ 担当が確定しています</div>';
        } else if (locData.my_status === 'pending') {
            actionHtml = '<button class="action-btn cancel" onclick="submitAction(\'cancel\',' + locData.rep_schedule_id + ')">申請をキャンセルする</button>';
        } else {
            // 未申請 or キャンセル済み or 却下
            actionHtml = '<button class="action-btn request" onclick="submitAction(\'request\',' + locData.rep_schedule_id + ')">この教室に出勤希望を申請する</button>';
        }
    }

    return `
    <div class="loc-block" id="locblock-${esc(locName)}">
        <div class="loc-block-header ${headerClass}">📍 ${esc(locName)}</div>
        <div class="loc-block-body">
            <div class="slots-label">時間枠</div>
            <div class="slot-list">${slotBadges || '<span style="color:#a0aec0;font-size:0.8rem;">なし</span>'}</div>
            ${statusHtml}
            ${actionHtml}
        </div>
    </div>`;
}

function submitAction(action, scheduleId) {
    document.getElementById('formAction').value = action;
    document.getElementById('formScheduleId').value = scheduleId;
    document.getElementById('actionForm').submit();
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
