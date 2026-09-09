<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 日付の希望申請者と全講師を取得（日付ベース）
if ($action === 'get_requests') {
    $date = $_GET['date'] ?? '';
    
    if (!$date) {
        http_response_code(400);
        echo json_encode(['error' => '日付を指定してください']);
        exit;
    }
    
    // この日付に対して希望を申請した講師
    $stmt = $db->prepare('
        SELECT DISTINCT u.id, u.name, u.email
        FROM users u
        JOIN shift_requests sr ON sr.user_id = u.id
        JOIN schedules s ON sr.schedule_id = s.id
        WHERE s.date = ? AND sr.status = "pending" AND u.is_active = 1
        ORDER BY u.name
    ');
    $stmt->execute([$date]);
    $requested = $stmt->fetchAll();
    
    // 全講師
    $stmt = $db->query('SELECT id, name, email FROM users WHERE role = "instructor" AND is_active = 1 ORDER BY name');
    $all = $stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode(['requested' => $requested, 'all' => $all]);
    exit;
}

// 割り当て済み講師取得（日付ベース）
if ($action === 'get_assigned') {
    $date = $_GET['date'] ?? '';
    
    if (!$date) {
        http_response_code(400);
        echo json_encode(['error' => '日付を指定してください']);
        exit;
    }
    
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.email, sa.id AS assignment_id
        FROM users u
        JOIN shift_assignments sa ON sa.user_id = u.id
        WHERE sa.date = ?
        ORDER BY u.name
    ');
    $stmt->execute([$date]);
    $assigned = $stmt->fetchAll();
    $isFull = count($assigned) >= 3;
    
    header('Content-Type: application/json');
    echo json_encode(['assigned' => $assigned, 'isFull' => $isFull, 'count' => count($assigned)]);
    exit;
}

// 講師割り当て（日付ベース）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'assign') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => '不正なリクエストです']);
        exit;
    }
    
    $date = $_POST['date'] ?? '';
    $instructorId = (int)($_POST['instructor_id'] ?? 0);
    
    if (!$date || !$instructorId) {
        http_response_code(400);
        echo json_encode(['error' => '必須項目を確認してください']);
        exit;
    }
    
    // 既に割り当てられているか確認
    $stmt = $db->prepare('SELECT COUNT(*) FROM shift_assignments WHERE date = ? AND user_id = ?');
    $stmt->execute([$date, $instructorId]);
    if ($stmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'この講師は既にこの日に割り当てられています']);
        exit;
    }
    
    // 最大3人までの制限確認
    $stmt = $db->prepare('SELECT COUNT(*) FROM shift_assignments WHERE date = ?');
    $stmt->execute([$date]);
    $assignedCount = (int)$stmt->fetchColumn();
    
    if ($assignedCount >= 3) {
        http_response_code(400);
        echo json_encode(['error' => '講師の割り当ては最大3名までです']);
        exit;
    }
    
    $stmt = $db->prepare('
        INSERT INTO shift_assignments (date, user_id, assigned_by)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$date, $instructorId, currentUserId()]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// 講師割り当て削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'remove') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => '不正なリクエストです']);
        exit;
    }
    
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    
    $db->prepare('DELETE FROM shift_assignments WHERE id = ?')->execute([$assignmentId]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => '不正なアクション']);
