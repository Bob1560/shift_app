<?php
// ============================================================
// アプリ設定（環境自動判定 - Docker/ロリポップで修正不要）
// ============================================================

define('APP_NAME',    'ロボット教室 シフト管理');
define('APP_VERSION', '1.0.0');

// セッション設定
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 3600);

// ── BASE_PATH 自動検出 ─────────────────────────────────────
// Docker (localhost:8080)               → BASE_PATH = ''
// ロリポップ (hotaru.holy.jp/shift_app) → BASE_PATH = '/shift_app'
(function() {
    $docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(realpath(dirname(__DIR__)), '/'); // includes/ の親 = src/
    $base    = str_replace($docRoot, '', $appRoot);
    define('BASE_PATH', rtrim($base, '/'));
})();

// ── DB接続 自動判定 ────────────────────────────────────────
// /.dockerenv が存在する = Dockerコンテナ内
(function() {
    $isDocker = file_exists('/.dockerenv') || getenv('DB_HOST') !== false;

    if ($isDocker) {
        // Docker環境
        define('DB_HOST', getenv('DB_HOST') ?: 'db');
        define('DB_NAME', 'shift_db');
        define('DB_USER', 'shift_user');
        define('DB_PASS', 'shift_pass');
    } else {
        // ロリポップ本番環境
        define('DB_HOST', 'mysql403.phy.lolipop.lan');
        define('DB_NAME', 'LAA1629440-shift');
        define('DB_USER', 'LAA1629440');
        define('DB_PASS', '575sEISEI');
    }

    define('DB_CHARSET', 'utf8mb4');
})();

// ── シフト申請設定 ─────────────────────────────────────────
define('DEADLINE_BUFFER_DAYS', 0);

// ── タイムゾーン ───────────────────────────────────────────
date_default_timezone_set('Asia/Tokyo');
