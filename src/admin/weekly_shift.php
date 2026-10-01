<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();

// ── 対象期間の決定 ─────────────────────────────────────────
// デフォルト：次の土曜日〜金曜日（7日間）
$today   = new DateTime('today');
$dow     = (int)$today->format('w'); // 0=日 … 6=土
$toSat   = (6 - $dow + 7) % 7;       // 今日が土曜なら0（今日を含む）
$defaultStart = (clone $today)->modify("+{$toSat} days");
$defaultEnd   = (clone $defaultStart)->modify('+6 days');

$startParam = $_GET['start'] ?? $defaultStart->format('Y-m-d');
$endParam   = $_GET['end']   ?? $defaultEnd->format('Y-m-d');

try {
    $rangeStart = new DateTime($startParam);
    $rangeEnd   = new DateTime($endParam);
} catch (Exception $e) {
    $rangeStart = $defaultStart;
    $rangeEnd   = $defaultEnd;
}
if ($rangeEnd < $rangeStart) {
    [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
}

// 前の週・次の週（現在の期間の長さを保ったまま1週間ずらす）
$prevStart = (clone $rangeStart)->modify('-7 days');
$prevEnd   = (clone $rangeEnd)->modify('-7 days');
$nextStart = (clone $rangeStart)->modify('+7 days');
$nextEnd   = (clone $rangeEnd)->modify('+7 days');

// ── データ取得（確定済み担当のみ） ─────────────────────────
$stmt = $db->prepare('
    SELECT
        s.id AS schedule_id,
        s.date,
        s.start_time,
        s.end_time,
        l.name AS location_name,
        l.sort_order,
        u.name AS user_name
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_assignments sa ON sa.schedule_id = s.id
    LEFT JOIN users u ON sa.user_id = u.id AND u.is_active = 1
    WHERE s.date >= ? AND s.date <= ?
    ORDER BY s.date, l.sort_order, s.start_time, u.name
');
$stmt->execute([$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);
$rows = $stmt->fetchAll();

// スケジュール単位（同一コマに複数講師が割り当たる場合をまとめる）
$schedules = [];
foreach ($rows as $r) {
    $id = $r['schedule_id'];
    if (!isset($schedules[$id])) {
        $schedules[$id] = [
            'date'       => $r['date'],
            'start'      => $r['start_time'],
            'end'        => $r['end_time'],
            'location'   => $r['location_name'],
            'sort_order' => $r['sort_order'],
            'names'      => [],
        ];
    }
    if ($r['user_name']) {
        $schedules[$id]['names'][] = $r['user_name'];
    }
}

$byDate = [];
foreach ($schedules as $sc) {
    $byDate[$sc['date']][] = $sc;
}
foreach ($byDate as $date => &$items) {
    usort($items, fn($a, $b) => [$a['sort_order'], $a['start']] <=> [$b['sort_order'], $b['start']]);
}
unset($items);

// ── テキスト生成 ───────────────────────────────────────────
$wdays = ['日', '月', '火', '水', '木', '金', '土'];

$lines = [];
$lines[] = '📅 シフト（' . $rangeStart->format('n/j') . '〜' . $rangeEnd->format('n/j') . '）';
$lines[] = '';

ksort($byDate);
foreach ($byDate as $dateStr => $items) {
    $d = new DateTime($dateStr);

    // 教室単位にまとめる（時間枠は横並び、講師名は重複なしで結合）
    $byLoc = [];
    foreach ($items as $it) {
        $loc = $it['location'];
        if (!isset($byLoc[$loc])) {
            $byLoc[$loc] = ['sort_order' => $it['sort_order'], 'times' => [], 'names' => []];
        }
        $byLoc[$loc]['times'][] = substr($it['start'], 0, 5) . '〜' . substr($it['end'], 0, 5);
        foreach ($it['names'] as $n) {
            if (!in_array($n, $byLoc[$loc]['names'], true)) {
                $byLoc[$loc]['names'][] = $n;
            }
        }
    }
    uasort($byLoc, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

    $lines[] = '■' . $d->format('n/j') . '(' . $wdays[(int)$d->format('w')] . ')';
    foreach ($byLoc as $locName => $locData) {
        $names = $locData['names'] ? implode('、', $locData['names']) : '担当未定';
        $lines[] = $locName . ' ' . implode(' ', $locData['times']) . ' ' . $names;
    }
    $lines[] = '';
}

$shiftText = rtrim(implode("\n", $lines));

$pageTitle = '今週のシフト';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.weekly-wrap { max-width: 680px; }

.week-nav { background: linear-gradient(135deg,#667eea,#764ba2); border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; color: white; }
.week-nav strong { font-size: 1rem; }
.week-nav a { color: white; background: rgba(255,255,255,0.2); border: none; padding: 6px 14px; border-radius: 5px; font-weight: bold; font-size: 0.9rem; text-decoration: none; }
.week-nav a:hover { background: rgba(255,255,255,0.35); }

.range-form { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1rem 1.25rem; margin-top: 1.25rem; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.range-form label { font-size: 0.85rem; color: #718096; font-weight: bold; }
.range-form input[type="date"] { padding: 6px 8px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 0.9rem; }
.range-form button { padding: 6px 16px; background: linear-gradient(135deg,#667eea,#764ba2); color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.9rem; }
.range-form button:hover { opacity: 0.88; }
.range-reset { font-size: 0.8rem; color: #3182ce; text-decoration: none; }

.text-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.25rem; }
.text-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.text-card-head h2 { margin: 0; font-size: 1rem; }
#copyBtn { padding: 6px 16px; background: #38a169; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.9rem; }
#copyBtn:hover { background: #276749; }
#copyBtn.copied { background: #2d3748; }

#shiftText { width: 100%; min-height: 420px; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 6px; font-family: ui-monospace, "SF Mono", Consolas, monospace; font-size: 0.88rem; line-height: 1.6; white-space: pre; resize: vertical; background: #f7fafc; color: #2d3748; }
</style>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">📅 今週のシフト</h1>
</div>
<p style="color:#718096;font-size:0.9rem;margin-top:-0.5rem;margin-bottom:1rem;">LINEにそのまま貼り付けられるテキスト形式で表示します。</p>

<div class="weekly-wrap">
    <div class="week-nav">
        <a href="?start=<?= h($prevStart->format('Y-m-d')) ?>&end=<?= h($prevEnd->format('Y-m-d')) ?>">◀ 前の週</a>
        <strong><?= h($rangeStart->format('Y/n/j')) ?> 〜 <?= h($rangeEnd->format('Y/n/j')) ?></strong>
        <a href="?start=<?= h($nextStart->format('Y-m-d')) ?>&end=<?= h($nextEnd->format('Y-m-d')) ?>">次の週 ▶</a>
    </div>

    <div class="text-card">
        <div class="text-card-head">
            <h2>📝 投稿用テキスト</h2>
            <button type="button" id="copyBtn" onclick="copyShiftText()">コピー</button>
        </div>
        <textarea id="shiftText" readonly onclick="this.select()"><?= h($shiftText) ?></textarea>
    </div>

    <form class="range-form" method="GET">
        <label>開始日</label>
        <input type="date" name="start" value="<?= h($rangeStart->format('Y-m-d')) ?>">
        <label>終了日</label>
        <input type="date" name="end" value="<?= h($rangeEnd->format('Y-m-d')) ?>">
        <button type="submit">表示</button>
        <a class="range-reset" href="<?= BASE_PATH ?>/admin/weekly_shift.php">今週（土〜金）に戻す</a>
    </form>
</div>

<script>
function copyShiftText() {
    const ta = document.getElementById('shiftText');
    const btn = document.getElementById('copyBtn');
    const done = () => {
        btn.textContent = 'コピーしました';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'コピー'; btn.classList.remove('copied'); }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value).then(done).catch(() => {
            ta.select();
            document.execCommand('copy');
            done();
        });
    } else {
        ta.select();
        document.execCommand('copy');
        done();
    }
}
</script>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
