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

    // 会場は「体験」コマのみ設定できる（既存の有効な教室から選択）
    $venueId = (int)($_POST['venue_location_id'] ?? 0);
    if ($venueId) {
        $stmt = $db->prepare('SELECT name FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $isTrial = $stmt->fetchColumn() === '体験';
        $stmt = $db->prepare('SELECT COUNT(*) FROM locations WHERE id = ? AND is_active = 1');
        $stmt->execute([$venueId]);
        if (!$isTrial || !$stmt->fetchColumn()) $venueId = 0;
    }

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
        INSERT INTO schedules (location_id, venue_location_id, date, start_time, end_time, required_staff_count, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$locationId, $venueId ?: null, $date, $startTime, $endTime, $requiredCount, $note ?: null, currentUserId()]);
    
    $newScheduleId = $db->lastInsertId();
    rememberEditedMonth($date);

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

    $stmt = $db->prepare('SELECT date FROM schedules WHERE id = ?');
    $stmt->execute([$scheduleId]);
    $deletedDate = (string)$stmt->fetchColumn();

    $db->prepare('DELETE FROM schedules WHERE id = ?')->execute([$scheduleId]);
    rememberEditedMonth($deletedDate);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// 教室単位の削除処理（その日のその教室のコマをまとめて削除）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_location') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '不正なリクエストです。']);
        exit;
    }

    $locationId = (int)($_POST['location_id'] ?? 0);
    $date       = $_POST['date'] ?? '';
    if (!$locationId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '必須項目が不足しています。']);
        exit;
    }

    $stmt = $db->prepare('
        SELECT COUNT(*) FROM shift_assignments sa
        JOIN schedules s ON sa.schedule_id = s.id
        WHERE s.location_id = ? AND s.date = ?
    ');
    $stmt->execute([$locationId, $date]);
    if ($stmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '確定済みシフトがあるため、この教室は削除できません。']);
        exit;
    }

    $stmt = $db->prepare('DELETE FROM schedules WHERE location_id = ? AND date = ?');
    $stmt->execute([$locationId, $date]);
    rememberEditedMonth($date);
    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => '不正なアクション']);
