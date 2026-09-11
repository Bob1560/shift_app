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

$prevMonth   = (clone $currentDate)->modify('-1 month')->format('Y-m');
$nextMonth   = (clone $currentDate)->modify('+1 month')->format('Y-m');
$firstDay    = (int)$currentDate->format('w');
$daysInMonth = (int)$currentDate->format('t');
$endDate     = (clone $currentDate)->modify('+1 month');

// コマ・担当講師・希望申請者を一括取得
$stmt = $db->prepare('
    SELECT
        s.id        AS schedule_id,
        s.date,
        s.start_time,
        s.end_time,
        l.id        AS location_id,
        l.name      AS location_name,
        l.sort_order,
        sa.id       AS assignment_id,
        u_a.id      AS assigned_id,
        u_a.name    AS assigned_name
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_assignments sa ON sa.schedule_id = (
        SELECT s2.id FROM schedules s2
        WHERE s2.location_id = s.location_id AND s2.date = s.date
        ORDER BY s2.start_time LIMIT 1
    )
    LEFT JOIN users u_a ON sa.user_id = u_a.id
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date, l.sort_order, s.start_time
');
$stmt->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$rows = $stmt->fetchAll();

// 希望申請者（pending）を日付×教室単位で取得
$stmt2 = $db->prepare('
    SELECT s.date, l.name AS location_name, u.id AS user_id, u.name AS user_name
    FROM shift_requests sr
    JOIN schedules s  ON sr.schedule_id = s.id
    JOIN locations l  ON s.location_id  = l.id
    JOIN users u      ON sr.user_id     = u.id
    WHERE sr.status = "pending" AND s.date >= ? AND s.date < ?
    ORDER BY s.date, l.sort_order, u.name
');
$stmt2->execute([$currentDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$requestRows = $stmt2->fetchAll();

// $byDate[$date][$locName] = [
//   'location_id' => int,
//   'slots'       => [['schedule_id'=>int,'start'=>str,'end'=>str], ...],
//   'instructors' => [['id'=>int,'name'=>str,'assignment_id'=>int], ...],
//   'requesters'  => [['id'=>int,'name'=>str], ...],
// ]
$byDate = [];
foreach ($rows as $r) {
    $d   = $r['date'];
    $loc = $r['location_name'];
    if (!isset($byDate[$d][$loc])) {
        $byDate[$d][$loc] = [
            'location_id' => $r['location_id'],
            'slots'       => [],
            'instructors' => [],
            'requesters'  => [],
        ];
    }
    // コマ（重複なし）
    $exists = false;
    foreach ($byDate[$d][$loc]['slots'] as $sl) {
        if ($sl['schedule_id'] === $r['schedule_id']) { $exists = true; break; }
    }
    if (!$exists) {
        $byDate[$d][$loc]['slots'][] = [
            'schedule_id' => $r['schedule_id'],
            'start'       => $r['start_time'],
            'end'         => $r['end_time'],
        ];
    }
    // 担当講師（重複なし）
    if ($r['assigned_id']) {
        $exists = false;
        foreach ($byDate[$d][$loc]['instructors'] as $ins) {
            if ($ins['assignment_id'] === $r['assignment_id']) { $exists = true; break; }
        }
        if (!$exists) {
            $byDate[$d][$loc]['instructors'][] = [
                'id'            => $r['assigned_id'],
                'name'          => $r['assigned_name'],
                'assignment_id' => $r['assignment_id'],
            ];
        }
    }
}
// 希望申請者をマージ（重複なし）
foreach ($requestRows as $r) {
    $d   = $r['date'];
    $loc = $r['location_name'];
    if (!isset($byDate[$d][$loc])) continue;
    $exists = false;
    foreach ($byDate[$d][$loc]['requesters'] as $rq) {
        if ($rq['id'] === $r['user_id']) { $exists = true; break; }
    }
    if (!$exists) {
        $byDate[$d][$loc]['requesters'][] = [
            'id'   => $r['user_id'],
            'name' => $r['user_name'],
        ];
    }
}

// 全講師リスト
$allInstructors = $db->query(
    'SELECT id, name FROM users WHERE role="instructor" AND is_active=1 ORDER BY name'
)->fetchAll();

$pageTitle = '管理者ダッシュボード';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
.dash-wrap { display: grid; grid-template-columns: 1fr 420px; gap: 1.5rem; margin: 1.5rem; margin-bottom: 3rem; }

/* カレンダー */
.cal-card { background: white; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); overflow: hidden; }
.cal-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
.cal-title { font-size: 1.5rem; font-weight: 700; margin: 0; }
.nav-btn { background: rgba(255,255,255,0.2); color: white; border: none; padding: 6px 14px; cursor: pointer; border-radius: 6px; font-weight: 600; }
.nav-btn:hover { background: rgba(255,255,255,0.35); }
.weekdays { display: grid; grid-template-columns: repeat(7,1fr); background: #f0f4ff; border-bottom: 1px solid #e2e8f0; }
.weekday { padding: 8px; text-align: center; font-weight: bold; font-size: 0.78rem; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 1px; background: #e2e8f0; padding: 1px; }

/* セルは可変高さ＝教室数に応じて伸びる */
.cal-cell { background: white; padding: 5px; min-height: 80px; cursor: pointer; transition: background 0.12s; }
.cal-cell:hover { background: #f0f4ff; }
.cal-cell.today { background: #fef9e7; }
.cal-cell.other-month { background: #fafbfc; opacity: 0.4; cursor: default; }
.cal-cell.selected { outline: 2px solid #667eea; outline-offset: -2px; background: #f0f4ff; }
.cell-day { font-size: 0.75rem; font-weight: bold; color: #2d3748; margin-bottom: 3px; }
.cell-day.sun { color: #e53e3e; }
.cell-day.sat { color: #3182ce; }

/* 教室チップ：全件表示（上限撤廃） */
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
    cursor: pointer;
    border: none;
    width: 100%;
    text-align: left;
    background: #a0aec0;
}
.loc-chip.assigned  { background: linear-gradient(135deg, #38a169, #276749); }
.loc-chip.requested { background: linear-gradient(135deg, #ed8936, #c05621); }
.loc-chip.partial   { background: linear-gradient(135deg, #667eea, #764ba2); }
.loc-chip:hover { opacity: 0.82; }
.chip-instr { font-weight: normal; opacity: 0.9; font-size: 0.58rem; }

/* 凡例 */
.legend { padding: 8px 12px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; flex-wrap: wrap; font-size: 0.74rem; color: #718096; }
.ldot { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 3px; vertical-align: middle; }

/* サイドパネル */
.side-panel { background: white; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); padding: 1.25rem; position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow-y: auto; }
.panel-empty { text-align: center; color: #a0aec0; padding: 3rem 1rem; font-size: 0.9rem; line-height: 1.8; }
.panel-date-title { font-size: 1rem; font-weight: bold; color: #2d3748; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; }

/* 教室ブロック */
.loc-block { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 1rem; overflow: hidden; }
.loc-block-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 8px 12px; font-weight: bold; font-size: 0.88rem; }
.loc-block-body { padding: 10px 12px; }
.sec-label { font-size: 0.72rem; font-weight: bold; color: #718096; margin-bottom: 5px; letter-spacing: 0.04em; }

/* 時間枠バッジ */
.slot-list { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 10px; }
.slot-badge { background: #ebf8ff; color: #2b6cb0; font-size: 0.76rem; font-weight: bold; padding: 2px 7px; border-radius: 4px; }

/* 担当講師チップ */
.instr-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; min-height: 22px; }
.instr-chip { display: flex; align-items: center; gap: 3px; background: #c6f6d5; color: #276749; font-size: 0.8rem; font-weight: bold; padding: 2px 8px; border-radius: 4px; }
.instr-chip .rm { background: none; border: none; cursor: pointer; color: #e53e3e; font-size: 0.82rem; padding: 0; line-height: 1; }
.no-instr { font-size: 0.78rem; color: #a0aec0; }

/* 希望申請者リスト */
.req-list { margin-bottom: 10px; }
.req-item { display: flex; align-items: center; justify-content: space-between; padding: 4px 8px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 4px; margin-bottom: 3px; font-size: 0.8rem; }
.req-item .rname { font-weight: bold; color: #92400e; }
.req-assign-btn { padding: 2px 8px; background: #38a169; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 0.75rem; font-weight: bold; }
.req-assign-btn:hover { background: #276749; }
.no-req { font-size: 0.78rem; color: #a0aec0; }

/* 講師選択・割り当て */
.assign-row { display: flex; gap: 6px; margin-top: 4px; }
.assign-row select { flex: 1; padding: 5px 8px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 0.82rem; }
.assign-row button { padding: 5px 12px; background: linear-gradient(135deg,#667eea,#764ba2); color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 0.82rem; font-weight: bold; white-space: nowrap; }
.assign-row button:hover { opacity: 0.88; }

@media (max-width: 1100px) { .dash-wrap { grid-template-columns: 1fr; } }
</style>

<div style="margin: 1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
    <h1 style="margin:0;color:white;font-size:1.75rem;">📊 シフト管理ダッシュボード</h1>
</div>

<div class="dash-wrap">
    <!-- カレンダー -->
    <div class="cal-card">
        <div class="cal-header">
            <h2 class="cal-title">📅 <?= h($currentDate->format('Y年n月')) ?></h2>
            <div style="display:flex;gap:8px;">
                <button class="nav-btn" onclick="location.href='?month=<?= h($prevMonth) ?>'">◀</button>
                <button class="nav-btn" onclick="location.href='?month=<?= h($nextMonth) ?>'">▶</button>
            </div>
        </div>

        <div class="weekdays">
            <?php foreach (['日','月','火','水','木','金','土'] as $i => $d): ?>
                <div class="weekday" style="color:<?= $i===0?'#e53e3e':($i===6?'#3182ce':'#667eea') ?>"><?= $d ?></div>
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
                $dayLocs = $byDate[$dateStr] ?? [];
            ?>
                <div class="cal-cell <?= $isToday?'today':'' ?>" id="cell-<?= $dateStr ?>"
                     onclick="selectDate('<?= h($dateStr) ?>')">
                    <div class="cell-day <?= $dow===0?'sun':($dow===6?'sat':'') ?>"><?= $day ?></div>
                    <?php
                    // 全件表示（上限なし）
                    foreach ($dayLocs as $locName => $locData):
                        $hasInstr = !empty($locData['instructors']);
                        $hasReq   = !empty($locData['requesters']);
                        $chipClass = $hasInstr ? 'assigned' : ($hasReq ? 'requested' : 'partial');
                    ?>
                        <button class="loc-chip <?= $chipClass ?>"
                                onclick="event.stopPropagation(); selectDate('<?= h($dateStr) ?>','<?= h($locName) ?>')"
                                title="<?= h($locName) ?>">
                            <?= h(mb_substr($locName,0,6)) ?>
                            <?php if ($hasInstr):
                                $names = array_map(fn($i) => mb_substr($i['name'],0,3), $locData['instructors']);
                            ?>
                            <span class="chip-instr"> <?= h(implode('・', $names)) ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>

        <!-- 凡例 -->
        <div class="legend">
            <span><span class="ldot" style="background:#38a169;"></span>担当あり</span>
            <span><span class="ldot" style="background:#ed8936;"></span>申請者あり</span>
            <span><span class="ldot" style="background:#667eea;"></span>未割り当て</span>
        </div>
    </div>

    <!-- サイドパネル -->
    <div class="side-panel" id="sidePanel">
        <div class="panel-empty">
            <p>📍 日付または教室名をクリック<br>時間枠・担当講師・申請者を<br>確認・編集できます</p>
        </div>
    </div>
</div>

<script>
const BASE_PATH = <?= json_encode(BASE_PATH) ?>;
const BY_DATE     = <?= json_encode($byDate, JSON_UNESCAPED_UNICODE) ?>;
const INSTRUCTORS = <?= json_encode($allInstructors, JSON_UNESCAPED_UNICODE) ?>;
const CSRF        = <?= json_encode(generateCsrfToken()) ?>;
let currentDateStr = null;

function selectDate(dateStr, focusLoc) {
    currentDateStr = dateStr;

    document.querySelectorAll('.cal-cell.selected').forEach(el => el.classList.remove('selected'));
    const cell = document.getElementById('cell-' + dateStr);
    if (cell) cell.classList.add('selected');

    const byLoc = BY_DATE[dateStr] || {};
    const dt = new Date(dateStr + 'T00:00:00')
        .toLocaleDateString('ja-JP',{year:'numeric',month:'long',day:'numeric',weekday:'short'});

    let html = '<div class="panel-date-title">📅 ' + esc(dt) + '</div>';

    const locNames = Object.keys(byLoc);
    if (locNames.length === 0) {
        html += '<p style="color:#a0aec0;text-align:center;padding:2rem 0;">この日のコマはありません</p>';
    } else {
        locNames.forEach(locName => {
            html += buildLocBlock(dateStr, locName, byLoc[locName]);
        });
    }

    document.getElementById('sidePanel').innerHTML = html;

    if (focusLoc) {
        const el = document.getElementById('lb-' + CSS.escape(focusLoc));
        if (el) el.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
}

function buildLocBlock(dateStr, locName, locData) {
    // 時間枠バッジ
    const slotBadges = locData.slots
        .slice().sort((a,b) => a.start.localeCompare(b.start))
        .map(s => '<span class="slot-badge">' + esc(s.start.substring(0,5)) + '〜' + esc(s.end.substring(0,5)) + '</span>')
        .join('');

    // 担当講師チップ
    let instrHtml = '';
    if (locData.instructors.length === 0) {
        instrHtml = '<span class="no-instr">未割り当て</span>';
    } else {
        locData.instructors.forEach(i => {
            instrHtml += '<span class="instr-chip">' + esc(i.name)
                + '<button class="rm" title="担当を外す"'
                + ' onclick="removeAssign(' + i.assignment_id + ',\'' + esc(dateStr) + '\',\'' + esc(locName) + '\')">✕</button>'
                + '</span>';
        });
    }

    // 希望申請者リスト（申請者ボタンから直接割り当て可能）
    let reqHtml = '';
    if (locData.requesters.length === 0) {
        reqHtml = '<span class="no-req">申請者なし</span>';
    } else {
        locData.requesters.forEach(r => {
            // すでに担当に入っているか
            const already = locData.instructors.some(i => i.id === r.id);
            if (!already) {
                reqHtml += '<div class="req-item">'
                    + '<span class="rname">⏳ ' + esc(r.name) + '</span>'
                    + '<button class="req-assign-btn" onclick="assignFromRequest(\'' + esc(dateStr) + '\',\'' + esc(locName) + '\',' + r.id + ')">担当にする</button>'
                    + '</div>';
            }
        });
        if (!reqHtml) reqHtml = '<span class="no-req">全員担当済み</span>';
    }

    // 代表schedule_id（最初のコマ）
    const repId = locData.slots.length > 0
        ? locData.slots.slice().sort((a,b) => a.start.localeCompare(b.start))[0].schedule_id
        : 0;

    // 全講師プルダウン
    let options = '<option value="">全講師から選択</option>';
    INSTRUCTORS.forEach(i => {
        options += '<option value="' + i.id + '">' + esc(i.name) + '</option>';
    });

    return `
    <div class="loc-block" id="lb-${esc(locName)}">
        <div class="loc-block-header">📍 ${esc(locName)}</div>
        <div class="loc-block-body">
            <div class="sec-label">時間枠</div>
            <div class="slot-list">${slotBadges || '<span class="no-instr">なし</span>'}</div>

            <div class="sec-label">担当講師</div>
            <div class="instr-chips" id="instrs-${esc(locName)}">${instrHtml}</div>

            <div class="sec-label">希望申請者</div>
            <div class="req-list">${reqHtml}</div>

            <div class="sec-label">全講師から割り当て</div>
            <div class="assign-row">
                <select id="sel-${esc(locName)}">${options}</select>
                <button onclick="assignInstructor(${repId},'${esc(dateStr)}','${esc(locName)}')">割り当て</button>
            </div>
        </div>
    </div>`;
}

// 申請者リストから直接割り当て
function assignFromRequest(dateStr, locName, instructorId) {
    const repId = BY_DATE[dateStr][locName].slots
        .slice().sort((a,b) => a.start.localeCompare(b.start))[0].schedule_id;
    doAssign(repId, dateStr, locName, instructorId);
}

// 全講師プルダウンから割り当て
function assignInstructor(scheduleId, dateStr, locName) {
    const sel = document.getElementById('sel-' + locName);
    if (!sel || !sel.value) { alert('講師を選択してください'); return; }
    doAssign(scheduleId, dateStr, locName, parseInt(sel.value));
}

function doAssign(scheduleId, dateStr, locName, instructorId) {
    const fd = new FormData();
    fd.append('action', 'assign');
    fd.append('schedule_id', scheduleId);
    fd.append('instructor_id', instructorId);
    fd.append('csrf_token', CSRF);
    fetch(BASE_PATH + '/admin/api_schedule.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const name = INSTRUCTORS.find(i => i.id == instructorId)?.name || '';
                BY_DATE[dateStr][locName].instructors.push({
                    id: instructorId, name: name, assignment_id: data.assignment_id
                });
                refreshChip(dateStr, locName);
                selectDate(dateStr, locName);
            } else {
                alert(data.error || 'エラーが発生しました');
            }
        });
}

function removeAssign(assignmentId, dateStr, locName) {
    if (!confirm('担当を外しますか？')) return;
    const fd = new FormData();
    fd.append('action', 'remove');
    fd.append('assignment_id', assignmentId);
    fd.append('csrf_token', CSRF);
    fetch(BASE_PATH + '/admin/api_schedule.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                BY_DATE[dateStr][locName].instructors =
                    BY_DATE[dateStr][locName].instructors.filter(i => i.assignment_id !== assignmentId);
                refreshChip(dateStr, locName);
                selectDate(dateStr, locName);
            }
        });
}

function refreshChip(dateStr, locName) {
    const cell = document.getElementById('cell-' + dateStr);
    if (!cell) return;
    cell.querySelectorAll('.loc-chip').forEach(chip => {
        if (chip.title === locName) {
            const loc = BY_DATE[dateStr][locName];
            const hasInstr = loc.instructors.length > 0;
            const hasReq   = loc.requesters.length > 0;
            chip.className = 'loc-chip ' + (hasInstr ? 'assigned' : hasReq ? 'requested' : 'partial');
            // 講師名を更新
            const shortLoc = locName.substring(0, 6);
            if (hasInstr) {
                const names = loc.instructors.map(i => i.name.substring(0, 3)).join('・');
                chip.innerHTML = esc(shortLoc) + ' <span class="chip-instr">' + esc(names) + '</span>';
            } else {
                chip.textContent = shortLoc;
            }
        }
    });
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
