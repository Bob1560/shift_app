<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(currentUserRole() === 'admin' ? '/admin/dashboard.php' : '/instructor/dashboard.php');
} else {
    redirect('/auth/login.php');
}
