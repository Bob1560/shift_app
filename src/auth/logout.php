<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

session_unset();
session_destroy();

redirect('/auth/login.php?logout=1');
