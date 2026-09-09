<?php
// ===== アプリケーション設定 =====

define('APP_NAME', 'ロボット教室 シフト管理');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost:8080');

// セッション設定（セキュリティ強化）
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 3600); // 1時間

// ===== データベース接続設定 =====
define('DB_HOST', 'db');
define('DB_NAME', 'shift_db');
define('DB_USER', 'shift_user');
define('DB_PASS', 'shift_pass');
define('DB_CHARSET', 'utf8mb4');

// ===== シフト申請設定 =====
define('DEADLINE_BUFFER_DAYS', 0); // 締切当日まで申請可能

// ===== タイムゾーン =====
date_default_timezone_set('Asia/Tokyo');
