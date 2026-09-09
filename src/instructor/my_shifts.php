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
$endDate = (clone $startDate)->modify('+1 month');

// 確定シフト一覧
$stmt = $db->prepare('
    SELECT sa.*, s.date, s.start_time, s.end_time, s.note, l.name AS location_name
    FROM shift_assignments sa
    JOIN schedules s ON sa.schedule_id = s.id
    JOIN locations l ON s.location_id = l.id
    WHERE sa.user_id = ? AND s.date >= ? AND s.date < ?
    ORDER BY s.date, s.start_time
');
$stmt->execute([$uid, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$shifts = $stmt->fetchAll();

$pageTitle = '確定シフト一覧';
include __DIR__ . '/../includes/layout_header.php';
?>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">📆 確定シフト一覧</h1>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <a href="?month=<?= h((clone $startDate)->modify('-1 month')->format('Y-m')) ?>" class="btn btn-secondary btn-sm">◀ 前月</a>
        <strong><?= h($startDate->format('Y年n月')) ?></strong>
        <a href="?month=<?= h($endDate->format('Y-m')) ?>" class="btn btn-secondary btn-sm">翌月 ▶</a>
    </div>
</div>

<div class="card">
    <?php if (empty($shifts)): ?>
        <p style="color:#718096;text-align:center;padding:3rem 0;">
            <?= h($startDate->format('Y年n月')) ?> の確定シフトはありません。<br>
            <a href="/instructor/request.php?month=<?= h($month) ?>" class="btn btn-primary mt-2">出勤希望を申請する</a>
        </p>
    <?php else: ?>
        <p style="color:#718096;font-size:0.9rem;margin-bottom:1rem;">
            <?= h($startDate->format('Y年n月')) ?> の確定シフト: <strong><?= count($shifts) ?>コマ</strong>
        </p>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>日付</th>
                        <th>場所</th>
                        <th>時間</th>
                        <th>備考</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shifts as $s): ?>
                    <tr>
                        <td>
                            <strong><?= h(date('Y年n月j日', strtotime($s['date']))) ?></strong>
                            <span style="color:#718096;font-size:0.85rem;">
                                （<?= h(date('D', strtotime($s['date']))) ?>）
                            </span>
                        </td>
                        <td>
                            <span style="color:#2b6cb0;font-weight:bold;"><?= h($s['location_name']) ?></span>
                        </td>
                        <td>
                            <?= h(substr($s['start_time'],0,5)) ?> 〜 <?= h(substr($s['end_time'],0,5)) ?>
                        </td>
                        <td style="font-size:0.85rem;color:#718096;">
                            <?= h($s['note'] ?? '') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
