<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();
$errors = [];

$scheduleId = (int)($_GET['id'] ?? 0);

if (!$scheduleId) {
    redirect('/admin/schedules_calendar.php');
}

// コマ情報取得
$stmt = $db->prepare('
    SELECT s.*, l.name AS location_name
    FROM schedules s
    JOIN locations l ON s.location_id = l.id
    WHERE s.id = ?
');
$stmt->execute([$scheduleId]);
$schedule = $stmt->fetch();

if (!$schedule) {
    redirect('/admin/schedules_calendar.php');
}

// 割り当て済みの講師を取得
$stmt = $db->prepare('
    SELECT u.*, sa.id AS assignment_id
    FROM users u
    JOIN shift_assignments sa ON sa.user_id = u.id
    WHERE sa.schedule_id = ? AND u.is_active = 1
    ORDER BY u.name
');
$stmt->execute([$scheduleId]);
$assignedInstructors = $stmt->fetchAll();

// 希望申請を取得
$stmt = $db->prepare('
    SELECT sr.*, u.name, u.email
    FROM shift_requests sr
    JOIN users u ON sr.user_id = u.id
    WHERE sr.schedule_id = ?
    ORDER BY sr.status DESC, u.name
');
$stmt->execute([$scheduleId]);
$requests = $stmt->fetchAll();

// 割り当て講師削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_instructor') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $db->prepare('DELETE FROM shift_assignments WHERE id = ?')->execute([$assignmentId]);
        setFlash('success', '講師の割り当てを削除しました。');
        redirect('/admin/schedule_detail.php?id=' . $scheduleId);
    }
}

// コマ削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM shift_assignments WHERE schedule_id = ?');
        $stmt->execute([$scheduleId]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = '確定済みシフトがあるコマは削除できません。';
        } else {
            $db->prepare('DELETE FROM schedules WHERE id = ?')->execute([$scheduleId]);
            setFlash('success', 'コマを削除しました。');
            redirect('/admin/schedules_calendar.php');
        }
    }
}

$pageTitle = 'コマの詳細';
include __DIR__ . '/../includes/layout_header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
    <h1 class="page-title" style="margin:0;">📋 コマの詳細</h1>
    <a href="<?= BASE_PATH ?>/admin/schedules_calendar.php" class="btn btn-secondary">戻る</a>
</div>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<!-- コマ情報 -->
<div class="card">
    <h2 class="card-title">📍 コマ情報</h2>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;">
        <div>
            <div style="color:#718096;font-size:0.9rem;">日付</div>
            <div style="font-weight:bold;font-size:1.1rem;"><?= h(date('Y年n月j日(D)', strtotime($schedule['date']))) ?></div>
        </div>
        <div>
            <div style="color:#718096;font-size:0.9rem;">時間</div>
            <div style="font-weight:bold;font-size:1.1rem;"><?= h(substr($schedule['start_time'], 0, 5)) ?>〜<?= h(substr($schedule['end_time'], 0, 5)) ?></div>
        </div>
        <div>
            <div style="color:#718096;font-size:0.9rem;">場所</div>
            <div style="font-weight:bold;font-size:1.1rem;"><?= h($schedule['location_name']) ?></div>
        </div>
        <div>
            <div style="color:#718096;font-size:0.9rem;">必要人数</div>
            <div style="font-weight:bold;font-size:1.1rem;"><?= h($schedule['required_staff_count']) ?>名</div>
        </div>
        <?php if ($schedule['note']): ?>
        <div style="grid-column:1/-1;">
            <div style="color:#718096;font-size:0.9rem;">備考</div>
            <div style="font-weight:500;"><?= h($schedule['note']) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 割り当て済み講師 -->
<div class="card">
    <h2 class="card-title">✅ 割り当て済み講師（<?= count($assignedInstructors) ?>名）</h2>
    <?php if (empty($assignedInstructors)): ?>
        <p style="color:#718096;text-align:center;padding:1.5rem;">まだ講師が割り当てられていません。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>名前</th>
                        <th>メール</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignedInstructors as $inst): ?>
                    <tr>
                        <td><?= h($inst['name']) ?></td>
                        <td><?= h($inst['email']) ?></td>
                        <td>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('この講師の割り当てを削除しますか？');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="remove_instructor">
                                <input type="hidden" name="assignment_id" value="<?= h($inst['assignment_id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">削除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 希望申請 -->
<div class="card">
    <h2 class="card-title">📝 希望申請（<?= count($requests) ?>件）</h2>
    <?php if (empty($requests)): ?>
        <p style="color:#718096;text-align:center;padding:1.5rem;">希望申請がありません。</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>名前</th>
                        <th>ステータス</th>
                        <th>申請日時</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><?= h($req['name']) ?></td>
                        <td>
                            <?php
                                $statusLabel = [
                                    'pending' => '✋ 保留中',
                                    'approved' => '✅ 承認',
                                    'rejected' => '❌ 却下',
                                    'cancelled' => '🚫 キャンセル'
                                ];
                                $statusClass = [
                                    'pending' => 'badge-pending',
                                    'approved' => 'badge-approved',
                                    'rejected' => 'badge-danger',
                                    'cancelled' => 'badge-secondary'
                                ];
                            ?>
                            <span class="badge <?= $statusClass[$req['status']] ?>"><?= $statusLabel[$req['status']] ?></span>
                        </td>
                        <td><?= h(date('m/d H:i', strtotime($req['created_at']))) ?></td>
                        <td>
                            <a href="<?= BASE_PATH ?>/admin/shift_adjust.php?schedule_id=<?= h($scheduleId) ?>" class="btn btn-secondary btn-sm">詳細</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 削除ボタン -->
<?php if (empty($assignedInstructors)): ?>
<div class="card" style="background:#fef5e7;border-left:4px solid #f39c12;">
    <h3 style="margin-top:0;color:#d68910;">⚠️ 危険ゾーン</h3>
    <p style="color:#5d4037;margin-bottom:1rem;">このコマにはまだ割り当て済み講師がいません。削除することができます。</p>
    <form method="POST" style="display:inline;"
          onsubmit="return confirm('このコマを削除してもよろしいですか？確認申請も削除されます。');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger">コマを削除</button>
    </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
