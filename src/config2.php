<?php
// ===== アプリケーション設定 =====

define('APP_NAME', 'ロボット教室 シフト管理');
define('APP_VERSION', '1.0.0');


// セッション設定（セキュリティ強化）
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 3600); // 1時間

// ===== データベース接続設定 =====
// 変更前（Docker用）
// define('DB_HOST', 'db');
// define('DB_NAME', 'shift_db');
// define('DB_USER', 'shift_user');
// define('DB_PASS', 'shift_pass');
// define('DB_CHARSET', 'utf8mb4');
// define('BASE_URL', 'http://localhost:8080');

// 変更後（ロリポップ用）
define('DB_HOST', 'mysql403.phy.lolipop.lan');   // ← メモしたサーバー名
define('DB_NAME', 'LAA1629440-shift');       // ← メモしたDB名
define('DB_USER', 'LAA1629440');       // ← メモしたユーザー名
define('DB_PASS', '575Seisei');
define('BASE_URL', 'https://hotaru.holy.jp/shift_app');

// ===== シフト申請設定 =====
define('DEADLINE_BUFFER_DAYS', 0); // 締切当日まで申請可能

// ===== タイムゾーン =====
date_default_timezone_set('Asia/Tokyo');
