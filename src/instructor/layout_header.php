<?php
require_once __DIR__ . '/auth.php';
$flash = getFlash();
$isAdmin = currentUserRole() === 'admin';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? APP_NAME) ?> | <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="header-inner">
        <a href="<?= BASE_PATH . ($isAdmin ? '/admin/dashboard.php' : '/instructor/dashboard.php') ?>" class="site-logo">
            🤖 <?= h(APP_NAME) ?>
        </a>
        <nav class="header-nav">
            <?php if ($isAdmin): ?>
                <a href="<?= BASE_PATH ?>/admin/dashboard.php">ダッシュボード</a>
                <a href="<?= BASE_PATH ?>/admin/schedules.php">コマ管理</a>
                <a href="<?= BASE_PATH ?>/admin/users.php">講師管理</a>
            <?php else: ?>
                <a href="<?= BASE_PATH ?>/instructor/dashboard.php">マイページ</a>
                <a href="<?= BASE_PATH ?>/instructor/request.php">出勤希望申請</a>
                <a href="<?= BASE_PATH ?>/instructor/my_shifts.php">確定シフト</a>
            <?php endif; ?>
            <span class="header-user">👤 <?= h(currentUserName()) ?></span>
            <a href="<?= BASE_PATH ?>/auth/logout.php" class="btn-logout">ログアウト</a>
        </nav>
    </div>
</header>

<main class="main-content">

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>">
        <?= h($flash['message']) ?>
    </div>
<?php endif; ?>
