<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();

function redirect(string $path): void {
    header('Location: ' . BASE_PATH . $path);
    exit;
}
}

// ===== セッション・認証ユーティリティ =====

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('/auth/login.php');
    }
    // セッションタイムアウトチェック（30分）
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        session_unset();
        session_destroy();
        redirect('/auth/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

function requireAdmin(): void {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        redirect('/instructor/dashboard.php');
    }
}

function requireInstructor(): void {
    requireLogin();
    // 管理者も講師ページにアクセス可能
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentUserRole(): string {
    return $_SESSION['user_role'] ?? '';
}

function currentUserName(): string {
    return $_SESSION['user_name'] ?? '';
}

// ===== CSRF対策 =====

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . h($token) . '">';
}

// ===== XSSエスケープ =====

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ===== ユーザー取得 =====

function getUserById(int $id): ?array {
    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ===== フラッシュメッセージ =====

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
