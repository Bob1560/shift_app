<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = Database::getInstance();

// コマ追加処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '不正なリクエストです。']);
        exit;
    }
    
    $locationId    = (int)($_POST['location_id'] ?? 0);
    $date          = $_POST['date'] ?? '';
    $startTime     = $_POST['start_time'] ?? '';
    $requiredCount = (int)($_POST['required_staff_count'] ?? 1);
    $note          = trim($_POST['note'] ?? '');

    // デバッグ: POSTデータの確認
    error_log("POST data - locationId: $locationId, date: $date, startTime: $startTime");

    if (!$locationId || !$date || !$startTime) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '必須項目を入力してください。(locationId=' . $locationId . ', date=' . $date . ', startTime=' . $startTime . ')']);
        exit;
    }
    
    // 終了時刻を開始時刻から90分後に自動設定
    try {
        // 時刻文字列を秒単位で計算
        $timeParts = explode(':', $startTime);
        $hours = (int)$timeParts[0];
        $minutes = (int)($timeParts[1] ?? 0);
        
        // 90分を追加
        $totalMinutes = $hours * 60 + $minutes + 90;
        $endHours = intdiv($totalMinutes, 60);
        $endMinutes = $totalMinutes % 60;
        
        // 24時間を超える場合の処理
        if ($endHours >= 24) {
            $endHours = $endHours % 24;
        }
        
        $endTime = sprintf('%02d:%02d:00', $endHours, $endMinutes);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '時刻計算エラー: ' . $e->getMessage()]);
        exit;
    }
    
    $stmt = $db->prepare('
        INSERT INTO schedules (location_id, date, start_time, end_time, required_staff_count, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$locationId, $date, $startTime, $endTime, $requiredCount, $note ?: null, currentUserId()]);
    
    $newScheduleId = $db->lastInsertId();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'schedule_id' => $newScheduleId]);
    exit;
}

// コマ削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => '不正なリクエストです。']);
        exit;
    }
    
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);
    $stmt = $db->prepare('SELECT COUNT(*) FROM shift_assignments WHERE schedule_id = ?');
    $stmt->execute([$scheduleId]);
    if ($stmt->fetchColumn() > 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => '確定済みシフトがあるコマは削除できません。']);
        exit;
    }
    
    $db->prepare('DELETE FROM schedules WHERE id = ?')->execute([$scheduleId]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => '不正なアクション']);
