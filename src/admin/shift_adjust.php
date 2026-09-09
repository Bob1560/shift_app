<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();
$errors = [];

// シフト確定・解除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $action     = $_POST['action'] ?? '';
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $userId     = (int)($_POST['user_id'] ?? 0);

        if ($action === 'assign' && $scheduleId && $userId) {
            // シフト確定
            $stmt = $db->prepare('
                INSERT IGNORE INTO shift_assignments (user_id, schedule_id, assigned_by)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$userId, $scheduleId, currentUserId()]);
            // 申請ステータスを承認に変更
            $db->prepare("UPDATE shift_requests SET status = 'approved' WHERE user_id = ? AND schedule_id = ?")
               ->execute([$userId, $scheduleId]);
            setFlash('success', 'シフトを確定しました。');
        } elseif ($action === 'unassign' && $scheduleId && $userId) {
            // シフト解除
            $db->prepare('DELETE FROM shift_assignments WHERE user_id = ? AND schedule_id = ?')
               ->execute([$userId, $scheduleId]);
            $db->prepare("UPDATE shift_requests SET status = 'pending' WHERE user_id = ? AND schedule_id = ?")
               ->execute([$userId, $scheduleId]);
            setFlash('info', 'シフトの確定を解除しました。');
        } elseif ($action === 'reject' && $scheduleId && $userId) {
            // 申請却下
            $db->prepare("UPDATE shift_requests SET status = 'rejected' WHERE user_id = ? AND schedule_id = ?")
               ->execute([$userId, $scheduleId]);
            setFlash('info', '申請を却下しました。');
        }
        header('Location: /admin/shift_adjust.php?schedule_id=' . $scheduleId);
        exit;
    }
}

// 対象コマ
$scheduleId = (int)($_GET['schedule_id'] ?? 0);
$schedule   = null;

if ($scheduleId) {
    $stmt = $db->prepare('
        SELECT s.*, l.name AS location_name
        FROM schedules s JOIN locations l ON s.location_id = l.id
        WHERE s.id = ?
    ');
    $stmt->execute([$scheduleId]);
    $schedule = $stmt->fetch();
}

// 希望申請一覧（このコマ）
$requests = [];
if ($schedule) {
    $stmt = $db->prepare('
        SELECT sr.*, u.name AS user_name, u.email,
               (SELECT COUNT(*) FROM shift_assignments sa WHERE sa.user_id = sr.user_id AND sa.schedule_id = sr.schedule_id) AS is_assigned
        FROM shift_requests sr
        JOIN users u ON sr.user_id = u.id
        WHERE sr.schedule_id = ?
        ORDER BY sr.created_at
    ');
    $stmt->execute([$scheduleId]);
    $requests = $stmt->fetchAll();
}

// 確定済み講師
$assigned = [];
if ($schedule) {
    $stmt = $db->prepare('
        SELECT sa.*, u.name AS user_name
        FROM shift_assignments sa
        JOIN users u ON sa.user_id = u.id
        WHERE sa.schedule_id = ?
    ');
    $stmt->execute([$scheduleId]);
    $assigned = $stmt->fetchAll();
}

// 月のコマ一覧（サイドバー用）
$month      = $schedule ? substr($schedule['date'], 0, 7) : date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-d', strtotime($monthStart . ' +1 month'));

$stmt = $db->prepare('
    SELECT s.id, s.date, s.start_time, l.name AS location_name,
           COUNT(sa.id) AS assigned_count, s.required_staff_count
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    LEFT JOIN shift_assignments sa ON sa.schedule_id = s.id
    WHERE s.date >= ? AND s.date < ?
    GROUP BY s.id
    ORDER BY s.date, s.start_time
');
$stmt->execute([$monthStart, $monthEnd]);
$monthSchedules = $stmt->fetchAll();

$pageTitle = 'シフト調整';
include __DIR__ . '/../includes/layout_header.php';
?>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">🔧 シフト調整</h1>
    <a href="/admin/schedules.php?month=<?= h($month) ?>" class="btn btn-secondary btn-sm">← コマ一覧へ</a>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:240px 1fr;gap:1.5rem;align-items:start;">

    <!-- コマ選択サイドバー -->
    <div class="card" style="padding:1rem;">
        <h3 style="font-size:0.95rem;font-weight:bold;color:#2b6cb0;margin-bottom:0.75rem;">
            <?= h(date('Y年n月', strtotime($monthStart))) ?> のコマ
        </h3>
        <?php if (empty($monthSchedules)): ?>
            <p style="font-size:0.85rem;color:#718096;">コマがありません</p>
        <?php else: ?>
            <ul style="list-style:none;font-size:0.85rem;">
                <?php foreach ($monthSchedules as $ms): ?>
                <li style="margin-bottom:4px;">
                    <a href="?schedule_id=<?= h($ms['id']) ?>"
                       style="display:block;padding:6px 8px;border-radius:4px;text-decoration:none;
                              color:<?= $ms['id'] == $scheduleId ? '#fff' : '#2d3748' ?>;
                              background:<?= $ms['id'] == $scheduleId ? '#3182ce' : '#f7fafc' ?>;">
                        <?= h(date('n/j', strtotime($ms['date']))) ?>
                        <?= h(substr($ms['start_time'],0,5)) ?>
                        <?= h($ms['location_name']) ?>
                        <span style="float:right;opacity:0.7;"><?= $ms['assigned_count'] ?>/<?= $ms['required_staff_count'] ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- メインエリア -->
    <div>
        <?php if (!$schedule): ?>
            <div class="card">
                <p style="color:#718096;text-align:center;padding:3rem 0;">左のリストからコマを選択してください。</p>
            </div>
        <?php else: ?>
            <!-- コマ情報 -->
            <div class="card">
                <h2 class="card-title">
                    <?= h($schedule['location_name']) ?> —
                    <?= h(date('Y年n月j日(D)', strtotime($schedule['date']))) ?>
                    <?= h(substr($schedule['start_time'],0,5)) ?>〜<?= h(substr($schedule['end_time'],0,5)) ?>
                </h2>
                <p style="font-size:0.9rem;color:#4a5568;">
                    必要人数: <strong><?= h($schedule['required_staff_count']) ?>名</strong>　
                    確定人数: <strong style="color:<?= count($assigned) >= $schedule['required_staff_count'] ? '#38a169' : '#e53e3e' ?>">
                        <?= count($assigned) ?>名
                    </strong>
                    <?php if ($schedule['note']): ?>
                        　備考: <?= h($schedule['note']) ?>
                    <?php endif; ?>
                </p>
            </div>

            <!-- 確定済み講師 -->
            <div class="card">
                <h2 class="card-title">✅ 確定済み講師（<?= count($assigned) ?>名）</h2>
                <?php if (empty($assigned)): ?>
                    <p style="color:#718096;">まだ確定されていません。</p>
                <?php else: ?>
                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                        <?php foreach ($assigned as $a): ?>
                        <div style="display:flex;align-items:center;gap:6px;background:#c6f6d5;padding:6px 12px;border-radius:6px;">
                            <span style="font-weight:bold;color:#276749;"><?= h($a['user_name']) ?></span>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('<?= h($a['user_name']) ?>さんの確定を解除しますか？');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="unassign">
                                <input type="hidden" name="schedule_id" value="<?= h($scheduleId) ?>">
                                <input type="hidden" name="user_id" value="<?= h($a['user_id']) ?>">
                                <button type="submit" style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:0.8rem;">✕</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 希望申請一覧 -->
            <div class="card">
                <h2 class="card-title">📋 出勤希望申請（<?= count($requests) ?>件）</h2>
                <?php if (empty($requests)): ?>
                    <p style="color:#718096;">希望申請はありません。</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>講師名</th>
                                    <th>ステータス</th>
                                    <th>申請日時</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $r): ?>
                                <tr>
                                    <td><strong><?= h($r['user_name']) ?></strong></td>
                                    <td>
                                        <?php if ($r['is_assigned']): ?>
                                            <span class="badge badge-approved">確定済み</span>
                                        <?php elseif ($r['status'] === 'approved'): ?>
                                            <span class="badge badge-approved">承認</span>
                                        <?php elseif ($r['status'] === 'rejected'): ?>
                                            <span class="badge badge-rejected">却下</span>
                                        <?php elseif ($r['status'] === 'cancelled'): ?>
                                            <span class="badge badge-cancelled">キャンセル</span>
                                        <?php else: ?>
                                            <span class="badge badge-pending">未処理</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.85rem;"><?= h(date('n/j H:i', strtotime($r['created_at']))) ?></td>
                                    <td style="white-space:nowrap;">
                                        <?php if (!$r['is_assigned'] && $r['status'] !== 'rejected' && $r['status'] !== 'cancelled'): ?>
                                        <form method="POST" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="assign">
                                            <input type="hidden" name="schedule_id" value="<?= h($scheduleId) ?>">
                                            <input type="hidden" name="user_id" value="<?= h($r['user_id']) ?>">
                                            <button type="submit" class="btn btn-success btn-sm">確定</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="schedule_id" value="<?= h($scheduleId) ?>">
                                            <input type="hidden" name="user_id" value="<?= h($r['user_id']) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('却下しますか？')">却下</button>
                                        </form>
                                        <?php else: ?>
                                            <span style="color:#a0aec0;font-size:0.85rem;">処理済み</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
