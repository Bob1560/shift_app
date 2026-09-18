<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/auth.php';

// すでにログイン済みならリダイレクト
if (isLoggedIn()) {
    redirect(currentUserRole() === 'admin' ? '/admin/dashboard.php' : '/instructor/dashboard.php');
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF検証
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email)) {
            $errors[] = 'メールアドレスを入力してください。';
        } else {
            $db   = Database::getInstance();
            $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // 2FA有効な場合は2FA確認画面へ
                if ($user['totp_enabled']) {
                    $_SESSION['pre_2fa_user_id'] = $user['id'];
                    redirect('/auth/totp_verify.php');
                }
                // ログイン完了
                session_regenerate_id(true);
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['user_role']     = $user['role'];
                $_SESSION['user_name']     = $user['name'];
                $_SESSION['last_activity'] = time();

                $redirect = $user['role'] === 'admin' ? '/admin/dashboard.php' : '/instructor/dashboard.php';
                redirect($redirect);
            } else {
                $errors[] = 'メールアドレスまたはパスワードが間違っています。';
                // ブルートフォース対策の遅延
                sleep(1);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">🤖</div>
        <h1 class="login-title"><?= h(APP_NAME) ?></h1>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="alert alert-warning">セッションがタイムアウトしました。再度ログインしてください。</div>
        <?php endif; ?>
        <?php if (isset($_GET['logout'])): ?>
            <div class="alert alert-info">ログアウトしました。</div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= h($e) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="<?= BASE_PATH ?>/auth/login.php" novalidate>
            <?= csrfField() ?>
            <div class="form-group">
                <label for="email">メールアドレス</label>
                <input type="email" id="email" name="email"
                       class="form-control" value="<?= h($email) ?>"
                       autocomplete="email" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-2">ログイン</button>
        </form>
        <div class="text-center mt-2">
            <a href="<?= BASE_PATH ?>/auth/forgot_password.php" style="font-size:0.85rem;color:#3182ce;">パスワードをお忘れですか？</a>
        </div>
    </div>
</div>
</body>
</html>
