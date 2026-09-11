<?php
// ============================================================
// アプリ設定（環境自動判定 - Docker/本番で修正不要）
// ============================================================

// BASE_PATH 自動検出
// Docker (localhost:8080)           → BASE_PATH = ''
// ロイロ (hotaru.holu.jp/shift_app) → BASE_PATH = '/shift_app'
(function() {
    $docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(realpath(dirname(__DIR__)), '/'); // includes/の親 = src/
    $base    = str_replace($docRoot, '', $appRoot);
    define('BASE_PATH', rtrim($base, '/'));
})();

// DB接続 自動判定（Docker: 'db' / ロイロ: 'localhost'）
(function() {
    define('DB_NAME',    'shift_db');
    define('DB_USER',    'shift_user');
    define('DB_PASS',    'shift_pass');
    define('DB_CHARSET', 'utf8mb4');
    $isDocker = getenv('DB_HOST') !== false || file_exists('/.dockerenv');
    define('DB_HOST', $isDocker ? (getenv('DB_HOST') ?: 'db') : 'localhost');
})();

define('APP_NAME',         'ロボット教室 シフト管理');
define('SESSION_LIFETIME', 1800);
date_default_timezone_set('Asia/Tokyo');
