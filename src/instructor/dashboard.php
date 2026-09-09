<?php
require_once __DIR__ . '/../includes/auth.php';
requireInstructor();

$db  = Database::getInstance();
$uid = currentUserId();

// 今後の確定シフト
$stmt = $db->prepare('
    SELECT sa.*, s.date, s.start_time, s.end_time, l.name AS location_name
    FROM shift_assignments sa
    JOIN schedules s ON sa.schedule_id = s.id
    JOIN locations l ON s.location_id = l.id
    WHERE sa.user_id = ? AND s.date >= CURDATE()
    ORDER BY s.date, s.start_time
    LIMIT 10
');
$stmt->execute([$uid]);
$upcomingShifts = $stmt->fetchAll();

// 未処理の申請
$stmt = $db->prepare("
    SELECT sr.*, s.date, s.start_time, s.end_time, l.name AS location_name
    FROM shift_requests sr
    JOIN schedules s ON sr.schedule_id = s.id
    JOIN locations l ON s.location_id = l.id
    WHERE sr.user_id = ? AND sr.status = 'pending' AND s.date >= CURDATE()
    ORDER BY s.date
");
$stmt->execute([$uid]);
$pendingRequests = $stmt->fetchAll();

// 今月の確定シフト数
$stmt = $db->prepare('
    SELECT COUNT(*) FROM shift_assignments sa
    JOIN schedules s ON sa.schedule_id = s.id
    WHERE sa.user_id = ? AND s.date >= ? AND s.date < ?
');
$stmt->execute([$uid, date('Y-m-01'), date('Y-m-01', strtotime('+1 month'))]);
$monthCount = $stmt->fetchColumn();

$pageTitle = 'マイページ';
include __DIR__ . '/../includes/layout_header.php';
?>

<h1 class="page-title">👋 こんにちは、<?= h(currentUserName()) ?>さん</h1>

<div class="dashboard-grid">
    <div class="stat-card">
        <div class="stat-label">今月の確定シフト</div>
        <div class="stat-value"><?= h($monthCount) ?></div>
        <div class="stat-sub">コマ</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">承認待ち申請</div>
        <div class="stat-value" style="color:#d69e2e;"><?= count($pendingRequests) ?></div>
        <div class="stat-sub">件</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">今後の確定シフト</div>
        <div class="stat-value" style="color:#38a169;"><?= count($upcomingShifts) ?></div>
        <div class="stat-sub">件</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

    <!-- 今後の確定シフト -->
    <div class="card">
        <div class="flex-between" style="margin-bottom:0.75rem;">
            <h2 class="card-title" style="margin:0;border:none;padding:0;">✅ 今後の確定シフト</h2>
            <a href="/instructor/my_shifts.php" style="font-size:0.85rem;color:#3182ce;">すべて見る</a>
        </div>
        <?php if (empty($upcomingShifts)): ?>
            <p style="color:#718096;font-size:0.9rem;">確定しているシフトはありません。</p>
        <?php else: ?>
            <ul style="list-style:none;">
                <?php foreach ($upcomingShifts as $s): ?>
                <li style="padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:0.9rem;">
                    <strong><?= h(date('n/j(D)', strtotime($s['date']))) ?></strong>
                    <?= h(substr($s['start_time'],0,5)) ?>〜<?= h(substr($s['end_time'],0,5)) ?>
                    <span style="color:#3182ce;margin-left:4px;"><?= h($s['location_name']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- 承認待ち申請 -->
    <div class="card">
        <div class="flex-between" style="margin-bottom:0.75rem;">
            <h2 class="card-title" style="margin:0;border:none;padding:0;">⏳ 承認待ちの申請</h2>
            <a href="/instructor/request.php" class="btn btn-primary btn-sm">新しく申請</a>
        </div>
        <?php if (empty($pendingRequests)): ?>
            <p style="color:#718096;font-size:0.9rem;">承認待ちの申請はありません。</p>
        <?php else: ?>
            <ul style="list-style:none;">
                <?php foreach ($pendingRequests as $r): ?>
                <li style="padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:0.9rem;">
                    <strong><?= h(date('n/j(D)', strtotime($r['date']))) ?></strong>
                    <?= h(substr($r['start_time'],0,5)) ?>〜<?= h(substr($r['end_time'],0,5)) ?>
                    <span style="color:#3182ce;margin-left:4px;"><?= h($r['location_name']) ?></span>
                    <span class="badge badge-pending" style="margin-left:4px;">審査中</span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
