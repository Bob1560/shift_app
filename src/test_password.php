<?php
$stored = '$2y$12$GKl3aGHlMHm0QVLKBqVcOuK3p2EKhQHjLmhYu2Y1lJqSUMkRY3VQK';
$input = 'Admin1234!';
$result = password_verify($input, $stored);
echo $result ? 'PASS - パスワードは正しいです' : 'FAIL - パスワードが一致しません';
