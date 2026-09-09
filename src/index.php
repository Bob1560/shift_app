<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . (currentUserRole() === 'admin' ? '/admin/dashboard.php' : '/instructor/dashboard.php'));
} else {
    header('Location: /auth/login.php');
}
exit;
