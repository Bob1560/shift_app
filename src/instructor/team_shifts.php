<?php
require_once __DIR__ . '/../includes/auth.php';
requireInstructor();

$db = Database::getInstance();

$month = $_GET['month'] ?? date('Y-m');
try {
    $startDate = new DateTime($month . '-01');
} catch (Exception $e) {
    $startDate = new DateTime(date('Y-m') . '-01');
}
$endDate     = (clone $startDate)->modify('+1 month');
$firstDow    = (int)$startDate->format('w');
$daysInMonth = (int)$startDate->format('t');

// 月内の全コマ
$stmt = $db->prepare('
    SELECT
        s.id AS schedule_id,
        s.date,
        s.start_time,
        s.end_time,
        s.required_staff_count,
        l.name AS location_name,
        l.sort_order
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date, l.sort_order, s.start_time
');
$stmt->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$scheduleRows = $stmt->fetchAll();

// 確定済み（全講師）
$stmt = $db->prepare('
    SELECT sa.schedule_id, u.name AS user_name
    FROM shift_assignments sa
    JOIN schedules s ON sa.schedule_id = s.id
    JOIN users u ON sa.user_id = u.id
    WHERE s.date >= ? AND s.date < ? AND u.is_active = 1
    ORDER BY u.name
');
$stmt->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$assignedBySchedule = [];
foreach ($stmt->fetchAll() as $r) {
    $assignedBySchedule[$r['schedule_id']][] = $r['user_name'];
}

// 申請中・承認済み（全講師）
$stmt = $db->prepare("
    SELECT sr.schedule_id, u.name AS user_name, sr.status
    FROM shift_requests sr
    JOIN schedules s ON sr.schedule_id = s.id
    JOIN users u ON sr.user_id = u.id
    WHERE s.date >= ? AND s.date < ? AND u.is_active = 1 AND sr.status IN ('pending', 'approved')
    ORDER BY u.name
");
$stmt->execute([$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$pendingBySchedule = [];
foreach ($stmt->fetchAll() as $r) {
    $pendingBySchedule[$r['schedule_id']][] = ['name' => $r['user_name'], 'status' => $r['status']];
}

// $byDate[$date][$locName] = ['slots' => [ ['start','end','required','assigned'=>[names],'pending'=>[{name,status}]] ]]
$byDate = [];
foreach ($scheduleRows as $r) {
    $d   = $r['date'];
    $loc = $r['location_name'];
    if (!isset($byDate[$d][$loc])) {
        $byDate[$d][$loc] = ['slots' => []];
    }
    $assigned = $assignedBySchedule[$r['schedule_id']] ?? [];
    $byDate[$d][$loc]['slots'][] = [
        'start'     => $r['start_time'],
        'end'       => $r['end_time'],
        'required'  => (int)$r['required_staff_count'],
        'assigned'  => $assigned,
        'pending'   => $pendingBySchedule[$r['schedule_id']] ?? [],
    ];
    // 教室チップの色判定用：いずれかのコマが未充足なら not-full
    if (!isset($byDate[$d][$loc]['hasPending'])) $byDate[$d][$loc]['hasPending'] = false;
    if (!isset($byDate[$d][$loc]['fullyStaffed'])) $byDate[$d][$loc]['fullyStaffed'] = true;
    if (!empty($pendingBySchedule[$r['schedule_id']])) $byDate[$d][$loc]['hasPending'] = true;
    if (count($assigned) < (int)$r['required_staff_count']) $byDate[$d][$loc]['fullyStaffed'] = false;
}

$pageTitle = '全講師の状況';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.team-wrap { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; align-items: start; }

/* カレンダー */
.cal-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; }
.cal-month-nav { background: linear-gradient(135deg,#319795,#285e61); color: white; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; }
.cal-month-nav strong { font-size: 1.15rem; }
.mnav { background: rgba(255,255,255,0.2); color: white; border: none; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.95rem; }
.mnav:hover { background: rgba(255,255,255,0.35); }
.weekdays { display: grid; grid-template-columns: repeat(7,1fr); background: #e6fffa; border-bottom: 1px solid #b2f5ea; }
.weekday { padding: 6px; text-align: center; font-size: 0.78rem; font-weight: bold; color: #285e61; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 1px; background: #e2e8f0; padding: 1px; }
.cal-cell { background: white; padding: 5px; min-height: 82px; cursor: pointer; transition: background 0.12s; }
.cal-cell:hover { background: #e6fffa; }
.cal-cell.today { background: #fef9e7; }
.cal-cell.selected { outline: 2px solid #319795; outline-offset: -2px; background: #e6fffa; }
.cal-cell.other-month { background: #fafbfc; opacity: 0.35; cursor: default; }
.cal-cell.no-shift { cursor: default; }
.cal-cell.no-shift:hover { background: white; }
.cell-day { font-size: 0.75rem; font-weight: bold; color: #2d3748; margin-bottom: 3px; }
.cell-day.sun { color: #e53e3e; }
.cell-day.sat { color: #3182ce; }

/* 教室チップ */
.loc-chip {
    display: block;
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
.loc-chip.full    { background: linear-gradient(135deg, #38a169, #276749); }  /* 必要人数充足 */
.loc-chip.short   { background: linear-gradient(135deg, #ed8936, #c05621); }  /* 人数不足 or 申請中あり */
.loc-chip:hover { opacity: 0.82; }

/* 凡例 */
.legend { display: flex; gap: 12px; flex-wrap: wrap; font-size: 0.78rem; color: #718096; padding: 8px 12px; border-top: 1px solid #e2e8f0; }
.legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }

/* サイドパネル */
.side-panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.25rem; position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow-y: auto; }
.panel-empty { text-align: center; color: #a0aec0; padding: 3rem 1rem; font-size: 0.9rem; line-height: 1.8; }
.panel-date-title { font-size: 1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #b2f5ea; }

/* 教室ブロック */
.loc-block { border: 1px solid #b2f5ea; border-radius: 8px; margin-bottom: 0.85rem; overflow: hidden; }
.loc-block-header { background: linear-gradient(135deg, #319795, #285e61); color: white; padding: 8px 12px; font-weight: bold; font-size: 0.88rem; }
.loc-block-body { padding: 10px 12px; }

/* 時間枠バッジ（横並び・簡略表示） */
.slots-label { font-size: 0.72rem; font-weight: bold; color: #718096; margin-bottom: 5px; letter-spacing: 0.04em; }
.slot-list { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 12px; }
.slot-badge { background: #e6fffa; color: #285e61; font-size: 0.78rem; font-weight: bold; padding: 3px 8px; border-radius: 4px; }

.person-row { font-size: 0.82rem; margin-bottom: 3px; }
.person-row .label { color: #718096; font-weight: bold; margin-right: 4px; }
.person-list { color: #2d3748; }
.person-empty { color: #a0aec0; }

@media (max-width: 960px) {
    .team-wrap { grid-template-columns: 1fr; }
    .side-panel { position: static; max-height: none; }
}
</style>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">👥 全講師の状況</h1>
</div>
<p style="color:#718096;font-size:0.9rem;margin-top:-0.5rem;margin-bottom:1rem;">他の講師の申請・確定状況を確認できます（閲覧のみ・編集はできません）</p>

<div class="team-wrap">
    <!-- カレンダー -->
    <div class="cal-card">
        <div class="cal-month-nav">
            <button class="mnav" onclick="location.href='?month=<?= h((clone $startDate)->modify('-1 month')->format('Y-m')) ?>'">◀</button>
            <strong><?= h($startDate->format('Y年n月')) ?></strong>
            <button class="mnav" onclick="location.href='?month=<?= h($endDate->format('Y-m')) ?>'">▶</button>
        </div>

        <div class="weekdays">
            <?php foreach (['日','月','火','水','木','金','土'] as $i => $d): ?>
                <div class="weekday" style="color:<?= $i===0?'#e53e3e':($i===6?'#3182ce':'#285e61') ?>"><?= $d ?></div>
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
                        $chipClass = ($locData['fullyStaffed'] && !$locData['hasPending']) ? 'full' : 'short';
                    ?>
                        <button class="loc-chip <?= $chipClass ?>"
                                onclick="event.stopPropagation(); selectDate('<?= h($dateStr) ?>', '<?= h($locName) ?>')"
                                title="<?= h($locName) ?>"><?= h(mb_substr($locName, 0, 6)) ?></button>
                    <?php
                        $shown++;
                    endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>

        <!-- 凡例 -->
        <div class="legend">
            <span><span class="legend-dot" style="background:#38a169;"></span>必要人数が確定済み</span>
            <span><span class="legend-dot" style="background:#ed8936;"></span>申請中あり／人数不足</span>
        </div>
    </div>

    <!-- サイドパネル -->
    <div class="side-panel" id="sidePanel">
        <div class="panel-empty">
            <p>📍 日付または教室名を<br>クリックして詳細を確認</p>
        </div>
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

    let html = '<div class="panel-date-title">👥 ' + esc(dt) + '</div>';

    Object.keys(byLoc).forEach(locName => {
        const locData = byLoc[locName];
        const slots = locData.slots.slice().sort((a,b) => a.start.localeCompare(b.start));

        // 時間枠バッジ（横並び・簡略表示：申請ページと同じ形式）
        const slotBadges = slots
            .map(s => '<span class="slot-badge">' + esc(s.start.substring(0,5)) + '〜' + esc(s.end.substring(0,5)) + '</span>')
            .join('');

        // 確定・申請中は教室単位でまとめて表示
        let assignedNames = [];
        let pendingEntries = [];
        slots.forEach(s => {
            s.assigned.forEach(n => { if (!assignedNames.includes(n)) assignedNames.push(n); });
            s.pending.forEach(p => pendingEntries.push(p));
        });

        const assignedHtml = assignedNames.length
            ? '<span class="person-list">' + assignedNames.map(esc).join('、') + '</span>'
            : '<span class="person-empty">未確定</span>';
        const pendingHtml = pendingEntries.length
            ? '<span class="person-list">' + pendingEntries.map(p => esc(p.name) + (p.status === 'approved' ? '（承認済）' : '（審査中）')).join('、') + '</span>'
            : '<span class="person-empty">なし</span>';

        html += `
        <div class="loc-block" id="locblock-${esc(locName)}">
            <div class="loc-block-header">📍 ${esc(locName)}</div>
            <div class="loc-block-body">
                <div class="slots-label">時間枠</div>
                <div class="slot-list">${slotBadges}</div>
                <div class="person-row"><span class="label">✅ 確定:</span>${assignedHtml}</div>
                <div class="person-row"><span class="label">⏳ 申請中:</span>${pendingHtml}</div>
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
