<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

header('Content-Type: application/json');

$db     = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// -------------------------------------------------------
// GET: コマに紐づく希望申請者と全講師を取得
// -------------------------------------------------------
if ($action === 'get_requests') {
    $scheduleId = (int)($_GET['schedule_id'] ?? 0);
    if (!$scheduleId) { http_response_code(400); echo json_encode(['error' => 'schedule_idを指定してください']); exit; }

    // このコマに希望申請した講師（pending）
    $stmt = $db->prepare('
        SELECT u.id, u.name
        FROM users u
        JOIN shift_requests sr ON sr.user_id = u.id
        WHERE sr.schedule_id = ? AND sr.status = "pending" AND u.is_active = 1
        ORDER BY u.name
    ');
    $stmt->execute([$scheduleId]);
    $requested = $stmt->fetchAll();

    // 全講師
    $stmt = $db->query('SELECT id, name FROM users WHERE role = "instructor" AND is_active = 1 ORDER BY name');
    $all  = $stmt->fetchAll();

    echo json_encode(['requested' => $requested, 'all' => $all]);
    exit;
}

// -------------------------------------------------------
// GET: コマに確定済みの講師一覧を取得
// -------------------------------------------------------
if ($action === 'get_assigned') {
    $scheduleId = (int)($_GET['schedule_id'] ?? 0);
    if (!$scheduleId) { http_response_code(400); echo json_encode(['error' => 'schedule_idを指定してください']); exit; }

    $stmt = $db->prepare('
        SELECT u.id, u.name, sa.id AS assignment_id
        FROM shift_assignments sa
        JOIN users u ON sa.user_id = u.id
        WHERE sa.schedule_id = ?
        ORDER BY u.name
    ');
    $stmt->execute([$scheduleId]);
    $assigned = $stmt->fetchAll();

    echo json_encode(['assigned' => $assigned, 'count' => count($assigned)]);
    exit;
}

// -------------------------------------------------------
// POST: 講師をコマに割り当て
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'assign') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); echo json_encode(['error' => '不正なリクエストです']); exit;
    }

    $scheduleId   = (int)($_POST['schedule_id'] ?? 0);
    $instructorId = (int)($_POST['instructor_id'] ?? 0);

    if (!$scheduleId || !$instructorId) {
        http_response_code(400); echo json_encode(['error' => '必須項目が不足しています']); exit;
    }

    // コマの存在確認
    $stmt = $db->prepare('SELECT id FROM schedules WHERE id = ?');
    $stmt->execute([$scheduleId]);
    if (!$stmt->fetch()) {
        http_response_code(400); echo json_encode(['error' => 'コマが存在しません']); exit;
    }

    // 重複チェック（同じコマに同じ講師）
    $stmt = $db->prepare('SELECT COUNT(*) FROM shift_assignments WHERE schedule_id = ? AND user_id = ?');
    $stmt->execute([$scheduleId, $instructorId]);
    if ($stmt->fetchColumn() > 0) {
        http_response_code(400); echo json_encode(['error' => 'この講師はすでにこのコマに割り当てられています']); exit;
    }

    // 挿入
    $stmt = $db->prepare('INSERT INTO shift_assignments (user_id, schedule_id, assigned_by) VALUES (?, ?, ?)');
    $stmt->execute([$instructorId, $scheduleId, currentUserId()]);
    $newId = $db->lastInsertId();

    // 希望申請があればapprovedに更新
    $db->prepare("UPDATE shift_requests SET status = 'approved' WHERE user_id = ? AND schedule_id = ? AND status = 'pending'")
       ->execute([$instructorId, $scheduleId]);

    echo json_encode(['success' => true, 'assignment_id' => (int)$newId]);
    exit;
}

// -------------------------------------------------------
// POST: 割り当て削除
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'remove') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); echo json_encode(['error' => '不正なリクエストです']); exit;
    }

    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    if (!$assignmentId) {
        http_response_code(400); echo json_encode(['error' => 'IDが不正です']); exit;
    }

    // 関連する申請をpendingに戻す
    $stmt = $db->prepare('SELECT user_id, schedule_id FROM shift_assignments WHERE id = ?');
    $stmt->execute([$assignmentId]);
    $sa = $stmt->fetch();
    if ($sa) {
        $db->prepare("UPDATE shift_requests SET status = 'pending' WHERE user_id = ? AND schedule_id = ? AND status = 'approved'")
           ->execute([$sa['user_id'], $sa['schedule_id']]);
    }

    $db->prepare('DELETE FROM shift_assignments WHERE id = ?')->execute([$assignmentId]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => '不正なアクション: ' . htmlspecialchars($action)]);
