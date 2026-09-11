<?php
require_once __DIR__ . '/../includes/auth.php';
requireInstructor();

$db  = Database::getInstance();
$uid = currentUserId();

$month = $_GET['month'] ?? date('Y-m');
try {
    $startDate = new DateTime($month . '-01');
} catch (Exception $e) {
    $startDate = new DateTime(date('Y-m') . '-01');
}
$endDate     = (clone $startDate)->modify('+1 month');
$firstDow    = (int)$startDate->format('w');
$daysInMonth = (int)$startDate->format('t');

// 確定シフト取得（教室×日付単位に集約）
$stmt = $db->prepare('
    SELECT
        s.date,
        s.start_time,
        s.end_time,
        s.note,
        l.name     AS location_name,
        l.sort_order
    FROM shift_assignments sa
    JOIN schedules s  ON sa.schedule_id = s.id
    JOIN locations l  ON s.location_id  = l.id
    WHERE sa.user_id = ? AND s.date >= ? AND s.date < ?
    ORDER BY s.date, l.sort_order, s.start_time
');
$stmt->execute([$uid, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$rows = $stmt->fetchAll();

// $byDate[$date][$locName] = ['slots' => [['start'=>..,'end'=>..,'note'=>..], ...]]
$byDate = [];
foreach ($rows as $r) {
    $d   = $r['date'];
    $loc = $r['location_name'];
    if (!isset($byDate[$d][$loc])) {
        $byDate[$d][$loc] = ['slots' => []];
    }
    $byDate[$d][$loc]['slots'][] = [
        'start' => $r['start_time'],
        'end'   => $r['end_time'],
        'note'  => $r['note'] ?? '',
    ];
}

// 月の合計コマ数
$totalSlots = array_sum(array_map(fn($locs) =>
    array_sum(array_map(fn($l) => count($l['slots']), $locs)), $byDate));

$pageTitle = '確定シフト一覧';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.shift-wrap { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; align-items: start; }

/* カレンダー */
.cal-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; }
.cal-month-nav { background: linear-gradient(135deg,#38a169,#276749); color: white; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; }
.cal-month-nav strong { font-size: 1.15rem; }
.mnav { background: rgba(255,255,255,0.2); color: white; border: none; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.95rem; }
.mnav:hover { background: rgba(255,255,255,0.35); }
.weekdays { display: grid; grid-template-columns: repeat(7,1fr); background: #f0fff4; border-bottom: 1px solid #c6f6d5; }
.weekday { padding: 6px; text-align: center; font-size: 0.78rem; font-weight: bold; color: #276749; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 1px; background: #e2e8f0; padding: 1px; }
.cal-cell { background: white; padding: 5px; min-height: 82px; cursor: pointer; transition: background 0.12s; }
.cal-cell:hover { background: #f0fff4; }
.cal-cell.today { background: #fef9e7; }
.cal-cell.selected { outline: 2px solid #38a169; outline-offset: -2px; background: #f0fff4; }
.cal-cell.other-month { background: #fafbfc; opacity: 0.35; cursor: default; }
.cal-cell.no-shift { cursor: default; }
.cal-cell.no-shift:hover { background: white; }
.cell-day { font-size: 0.75rem; font-weight: bold; color: #2d3748; margin-bottom: 3px; }
.cell-day.sun { color: #e53e3e; }
.cell-day.sat { color: #3182ce; }

/* 教室チップ（確定済み） */
.loc-chip {
    display: block;
    background: linear-gradient(135deg, #38a169, #276749);
    color: white;
    font-size: 0.6rem;
    font-weight: bold;
    padding: 2px 5px;
    border-radius: 3px;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    border: none;
    width: 100%;
    text-align: left;
    cursor: pointer;
}
.loc-chip:hover { opacity: 0.82; }

/* サマリーバー */
.summary-bar { padding: 8px 14px; background: #f0fff4; border-top: 1px solid #c6f6d5; font-size: 0.8rem; color: #276749; font-weight: bold; }

/* サイドパネル */
.side-panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.25rem; position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow-y: auto; }
.panel-empty { text-align: center; color: #a0aec0; padding: 3rem 1rem; font-size: 0.9rem; line-height: 1.8; }
.panel-date-title { font-size: 1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #c6f6d5; }

/* 教室ブロック */
.loc-block { border: 1px solid #c6f6d5; border-radius: 8px; margin-bottom: 0.85rem; overflow: hidden; }
.loc-block-header { background: linear-gradient(135deg, #38a169, #276749); color: white; padding: 8px 12px; font-weight: bold; font-size: 0.88rem; }
.loc-block-body { padding: 10px 12px; }

/* 時間枠 */
.slots-label { font-size: 0.72rem; font-weight: bold; color: #718096; margin-bottom: 5px; letter-spacing: 0.04em; }
.slot-item { display: flex; align-items: center; gap: 8px; padding: 5px 8px; background: #f0fff4; border-radius: 5px; margin-bottom: 4px; font-size: 0.85rem; }
.slot-time { font-weight: bold; color: #276749; }
.slot-note { color: #718096; font-size: 0.78rem; }

/* 申請ページへのリンク */
.req-link { display: block; text-align: center; margin-top: 1rem; padding: 0.65rem; background: #ebf8ff; color: #2b6cb0; border-radius: 6px; font-size: 0.85rem; font-weight: bold; text-decoration: none; }
.req-link:hover { background: #bee3f8; }

@media (max-width: 960px) {
    .shift-wrap { grid-template-columns: 1fr; }
    .side-panel { position: static; max-height: none; }
}
</style>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">📆 確定シフト一覧</h1>
</div>

<div class="shift-wrap">
    <!-- カレンダー -->
    <div class="cal-card">
        <div class="cal-month-nav">
            <button class="mnav" onclick="location.href='?month=<?= h((clone $startDate)->modify('-1 month')->format('Y-m')) ?>'">◀</button>
            <strong><?= h($startDate->format('Y年n月')) ?></strong>
            <button class="mnav" onclick="location.href='?month=<?= h($endDate->format('Y-m')) ?>'">▶</button>
        </div>

        <div class="weekdays">
            <?php foreach (['日','月','火','水','木','金','土'] as $i => $d): ?>
                <div class="weekday" style="color:<?= $i===0?'#e53e3e':($i===6?'#3182ce':'#276749') ?>"><?= $d ?></div>
            <?php endforeach; ?>
        </div>

        <div class="cal-grid">
            <?php for ($i = 0; $i < $firstDow; $i++): ?>
                <div class="cal-cell other-month"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $dow     = ($firstDow + $day - 1) % 7;
                $dateStr = $startDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isToday = ($dateStr === date('Y-m-d'));
                $dayLocs = $byDate[$dateStr] ?? [];
                $hasShift = !empty($dayLocs);
            ?>
                <div class="cal-cell <?= $isToday?'today':'' ?> <?= !$hasShift?'no-shift':'' ?>"
                     id="cell-<?= $dateStr ?>"
                     onclick="<?= $hasShift ? "selectDate('".h($dateStr)."')" : '' ?>">
                    <div class="cell-day <?= $dow===0?'sun':($dow===6?'sat':'') ?>"><?= $day ?></div>
                    <?php
                    $shown = 0;
                    foreach ($dayLocs as $locName => $locData):
                        if ($shown >= 3) { echo '<div style="font-size:0.58rem;color:#a0aec0;">+'.(count($dayLocs)-3).'</div>'; break; }
                    ?>
                        <button class="loc-chip"
                                onclick="event.stopPropagation(); selectDate('<?= h($dateStr) ?>', '<?= h($locName) ?>')"
                                title="<?= h($locName) ?>"><?= h(mb_substr($locName, 0, 6)) ?></button>
                    <?php
                        $shown++;
                    endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>

        <!-- サマリー -->
        <div class="summary-bar">
            ✅ <?= h($startDate->format('Y年n月')) ?> の確定シフト：
            <?= count($byDate) ?>日・<?= $totalSlots ?>コマ
        </div>
    </div>

    <!-- サイドパネル -->
    <div class="side-panel" id="sidePanel">
        <?php if (empty($byDate)): ?>
            <div class="panel-empty">
                <p>📭 <?= h($startDate->format('Y年n月')) ?> の<br>確定シフトはありません</p>
                <a href="<?= BASE_PATH ?>/instructor/request.php?month=<?= h($month) ?>" class="req-link">出勤希望を申請する →</a>
            </div>
        <?php else: ?>
            <div class="panel-empty">
                <p>📍 日付または教室名を<br>クリックして詳細を確認</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const BY_DATE = <?= json_encode($byDate, JSON_UNESCAPED_UNICODE) ?>;

function selectDate(dateStr, focusLoc) {
    // ハイライト
    document.querySelectorAll('.cal-cell.selected').forEach(el => el.classList.remove('selected'));
    const cell = document.getElementById('cell-' + dateStr);
    if (cell) cell.classList.add('selected');

    const byLoc = BY_DATE[dateStr] || {};
    const dt = new Date(dateStr + 'T00:00:00')
        .toLocaleDateString('ja-JP', {year:'numeric', month:'long', day:'numeric', weekday:'short'});

    let html = '<div class="panel-date-title">✅ ' + esc(dt) + '</div>';

    Object.keys(byLoc).forEach(locName => {
        const locData = byLoc[locName];
        let slotsHtml = '';
        locData.slots
            .slice().sort((a,b) => a.start.localeCompare(b.start))
            .forEach(s => {
                slotsHtml += '<div class="slot-item">'
                    + '<span class="slot-time">🕐 ' + esc(s.start.substring(0,5)) + '〜' + esc(s.end.substring(0,5)) + '</span>'
                    + (s.note ? '<span class="slot-note">📝 ' + esc(s.note) + '</span>' : '')
                    + '</div>';
            });

        html += `
        <div class="loc-block" id="locblock-${esc(locName)}">
            <div class="loc-block-header">📍 ${esc(locName)}</div>
            <div class="loc-block-body">
                <div class="slots-label">時間枠</div>
                ${slotsHtml}
            </div>
        </div>`;
    });

    document.getElementById('sidePanel').innerHTML = html;

    // 指定教室へスクロール
    if (focusLoc) {
        const el = document.getElementById('locblock-' + CSS.escape(focusLoc));
        if (el) el.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
