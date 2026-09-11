<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();
$errors = [];

// シフト確定・解除・却下処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $action     = $_POST['action'] ?? '';
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $userId     = (int)($_POST['user_id'] ?? 0);
        $retMonth   = $_POST['ret_month'] ?? date('Y-m');

        if ($action === 'assign' && $scheduleId && $userId) {
            $stmt = $db->prepare('INSERT IGNORE INTO shift_assignments (user_id, schedule_id, assigned_by) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $scheduleId, currentUserId()]);
            $db->prepare("UPDATE shift_requests SET status = 'approved' WHERE user_id = ? AND schedule_id = ?")
               ->execute([$userId, $scheduleId]);
            setFlash('success', 'シフトを確定しました。');
        } elseif ($action === 'unassign' && $scheduleId && $userId) {
            $db->prepare('DELETE FROM shift_assignments WHERE user_id = ? AND schedule_id = ?')->execute([$userId, $scheduleId]);
            $db->prepare("UPDATE shift_requests SET status = 'pending' WHERE user_id = ? AND schedule_id = ?")->execute([$userId, $scheduleId]);
            setFlash('info', 'シフトの確定を解除しました。');
        } elseif ($action === 'reject' && $scheduleId && $userId) {
            $db->prepare("UPDATE shift_requests SET status = 'rejected' WHERE user_id = ? AND schedule_id = ?")
               ->execute([$userId, $scheduleId]);
            setFlash('info', '申請を却下しました。');
        }
        redirect('/admin/shift_adjust.php?schedule_id=' . $scheduleId . '&month=' . urlencode($retMonth));
    }
}

// 表示月（GETパラメータまたはデフォルト）
$selectedScheduleId = (int)($_GET['schedule_id'] ?? 0);

// 表示月の決定：schedule_id があればそのコマの月、なければ GET の month、なければ今月
if ($selectedScheduleId) {
    $stmp = $db->prepare('SELECT date FROM schedules WHERE id = ?');
    $stmp->execute([$selectedScheduleId]);
    $tmpDate = $stmp->fetchColumn();
    $month = $tmpDate ? substr($tmpDate, 0, 7) : ($_GET['month'] ?? date('Y-m'));
} else {
    $month = $_GET['month'] ?? date('Y-m');
}

try {
    $monthDt = new DateTime($month . '-01');
} catch (Exception $e) {
    $monthDt = new DateTime(date('Y-m') . '-01');
    $month   = $monthDt->format('Y-m');
}
$prevMonth  = (clone $monthDt)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $monthDt)->modify('+1 month')->format('Y-m');
$monthStart = $monthDt->format('Y-m-d');
$monthEnd   = (clone $monthDt)->modify('+1 month')->format('Y-m-d');

// 月内コマ一覧（サイドバー）
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

// 選択されたコマの詳細
$schedule = null;
if ($selectedScheduleId) {
    $stmt = $db->prepare('SELECT s.*, l.name AS location_name FROM schedules s JOIN locations l ON s.location_id = l.id WHERE s.id = ?');
    $stmt->execute([$selectedScheduleId]);
    $schedule = $stmt->fetch();
}

// 希望申請一覧
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
    $stmt->execute([$selectedScheduleId]);
    $requests = $stmt->fetchAll();
}

// 確定済み講師
$assigned = [];
if ($schedule) {
    $stmt = $db->prepare('SELECT sa.*, u.name AS user_name FROM shift_assignments sa JOIN users u ON sa.user_id = u.id WHERE sa.schedule_id = ?');
    $stmt->execute([$selectedScheduleId]);
    $assigned = $stmt->fetchAll();
}

$pageTitle = 'シフト調整';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
* { box-sizing: border-box; }
.adjust-wrap { display: grid; grid-template-columns: 260px 1fr; gap: 1.5rem; align-items: start; }
/* サイドバー */
.sidebar-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1rem; position: sticky; top: 70px; max-height: calc(100vh - 90px); overflow-y: auto; }
.sidebar-month-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e2e8f0; }
.sidebar-month-title { font-size: 0.95rem; font-weight: bold; color: #2b6cb0; }
.mnav-btn { background: #ebf8ff; color: #2b6cb0; border: none; padding: 3px 9px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 0.85rem; }
.mnav-btn:hover { background: #bee3f8; }
.sched-link { display: block; padding: 6px 8px; border-radius: 5px; text-decoration: none; margin-bottom: 3px; font-size: 0.82rem; transition: background 0.15s; }
.sched-link:hover { background: #ebf8ff; }
.sched-link.active { background: #3182ce; color: #fff !important; }
.sched-link .date-part { font-weight: bold; }
.sched-link .count-badge { float: right; font-size: 0.75rem; opacity: 0.8; }
/* メイン */
.main-area .card { margin-bottom: 1rem; }
.info-grid { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 0.9rem; }
.info-label { color: #718096; font-weight: bold; }
.info-val { color: #2d3748; }
.assigned-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 0.5rem; }
.chip { display: flex; align-items: center; gap: 6px; background: #c6f6d5; padding: 5px 10px; border-radius: 6px; }
.chip-name { font-weight: bold; color: #276749; font-size: 0.9rem; }
.chip-rm { background: none; border: none; cursor: pointer; color: #e53e3e; font-size: 0.8rem; padding: 0; }
@media (max-width: 900px) { .adjust-wrap { grid-template-columns: 1fr; } .sidebar-card { position: static; max-height: none; } }
</style>

<div class="flex-between mb-2">
    <h1 class="page-title" style="margin:0;">🔧 シフト調整</h1>
    <a href="<?= BASE_PATH ?>/admin/schedules.php?month=<?= h($month) ?>" class="btn btn-secondary btn-sm">← コマ管理へ</a>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="adjust-wrap">
    <!-- サイドバー：コマ選択 -->
    <div class="sidebar-card">
        <!-- 月切り替え -->
        <div class="sidebar-month-nav">
            <a href="?month=<?= h($prevMonth) ?>" class="mnav-btn">◀</a>
            <span class="sidebar-month-title"><?= h($monthDt->format('Y年n月')) ?></span>
            <a href="?month=<?= h($nextMonth) ?>" class="mnav-btn">▶</a>
        </div>

        <?php if (empty($monthSchedules)): ?>
            <p style="font-size:0.85rem;color:#718096;text-align:center;padding:1rem 0;">コマがありません</p>
        <?php else: ?>
            <?php
            $prevDate = '';
            foreach ($monthSchedules as $ms):
                $dateLabel = date('n/j(D)', strtotime($ms['date']));
                if ($ms['date'] !== $prevDate) {
                    if ($prevDate !== '') echo '<div style="margin-bottom:2px;"></div>';
                    echo '<div style="font-size:0.75rem;font-weight:bold;color:#4a5568;padding:3px 2px;margin-top:6px;border-bottom:1px solid #e2e8f0;">' . h($dateLabel) . '</div>';
                    $prevDate = $ms['date'];
                }
                $isActive  = $ms['id'] == $selectedScheduleId;
                $isFull    = $ms['assigned_count'] >= $ms['required_staff_count'];
                $linkColor = $isActive ? '' : ($isFull ? 'color:#38a169;' : 'color:#2d3748;');
            ?>
                <a href="?schedule_id=<?= h($ms['id']) ?>&month=<?= h($month) ?>"
                   class="sched-link <?= $isActive ? 'active' : '' ?>"
                   style="<?= $linkColor ?>">
                    <span><?= h(substr($ms['start_time'],0,5)) ?> <?= h($ms['location_name']) ?></span>
                    <span class="count-badge"><?= $ms['assigned_count'] ?>/<?= $ms['required_staff_count'] ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- メインエリア -->
    <div class="main-area">
        <?php if (!$schedule): ?>
            <div class="card">
                <p style="color:#718096;text-align:center;padding:3rem 0;">← 左のリストからコマを選択してください。</p>
            </div>
        <?php else: ?>
            <!-- コマ情報 -->
            <div class="card">
                <h2 class="card-title">📍 コマ情報</h2>
                <div class="info-grid">
                    <span class="info-label">日付</span>
                    <span class="info-val"><?= h(date('Y年n月j日(D)', strtotime($schedule['date']))) ?></span>
                    <span class="info-label">時間</span>
                    <span class="info-val"><?= h(substr($schedule['start_time'],0,5)) ?>〜<?= h(substr($schedule['end_time'],0,5)) ?></span>
                    <span class="info-label">場所</span>
                    <span class="info-val"><?= h($schedule['location_name']) ?></span>
                    <span class="info-label">必要人数</span>
                    <span class="info-val">
                        <span style="color:<?= count($assigned) >= $schedule['required_staff_count'] ? '#38a169' : '#e53e3e' ?>;font-weight:bold;">
                            <?= count($assigned) ?>
                        </span> / <?= h($schedule['required_staff_count']) ?>名
                    </span>
                    <?php if ($schedule['note']): ?>
                    <span class="info-label">備考</span>
                    <span class="info-val"><?= h($schedule['note']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 確定済み講師 -->
            <div class="card">
                <h2 class="card-title">✅ 確定済み講師（<?= count($assigned) ?>名）</h2>
                <?php if (empty($assigned)): ?>
                    <p style="color:#718096;">まだ確定されていません。</p>
                <?php else: ?>
                    <div class="assigned-chips">
                        <?php foreach ($assigned as $a): ?>
                        <div class="chip">
                            <span class="chip-name"><?= h($a['user_name']) ?></span>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('<?= h($a['user_name']) ?>さんの確定を解除しますか？')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="unassign">
                                <input type="hidden" name="schedule_id" value="<?= h($selectedScheduleId) ?>">
                                <input type="hidden" name="user_id" value="<?= h($a['user_id']) ?>">
                                <input type="hidden" name="ret_month" value="<?= h($month) ?>">
                                <button type="submit" class="chip-rm">✕</button>
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
                                            <input type="hidden" name="schedule_id" value="<?= h($selectedScheduleId) ?>">
                                            <input type="hidden" name="user_id" value="<?= h($r['user_id']) ?>">
                                            <input type="hidden" name="ret_month" value="<?= h($month) ?>">
                                            <button type="submit" class="btn btn-success btn-sm">確定</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="schedule_id" value="<?= h($selectedScheduleId) ?>">
                                            <input type="hidden" name="user_id" value="<?= h($r['user_id']) ?>">
                                            <input type="hidden" name="ret_month" value="<?= h($month) ?>">
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
