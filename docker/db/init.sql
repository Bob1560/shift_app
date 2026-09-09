SET NAMES utf8mb4;
SET time_zone = '+09:00';

USE shift_db;

-- ユーザーテーブル
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    totp_secret VARCHAR(64) DEFAULT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('admin', 'instructor') NOT NULL DEFAULT 'instructor',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- パスワードリセットテーブル
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 教室・勤務場所テーブル
CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- コマ（開催枠）テーブル
CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_id INT NOT NULL,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    required_staff_count INT NOT NULL DEFAULT 1,
    note TEXT DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_date (date),
    INDEX idx_location_date (location_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 出勤希望申請テーブル
CREATE TABLE IF NOT EXISTS shift_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    schedule_id INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_schedule (user_id, schedule_id),
    INDEX idx_user_id (user_id),
    INDEX idx_schedule_id (schedule_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- シフト確定テーブル
CREATE TABLE IF NOT EXISTS shift_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    schedule_id INT NOT NULL,
    assigned_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id),
    UNIQUE KEY uq_assignment (user_id, schedule_id),
    INDEX idx_schedule_id (schedule_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 申請締切テーブル
CREATE TABLE IF NOT EXISTS request_deadlines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_month DATE NOT NULL COMMENT '対象月（月初日）',
    deadline_at DATETIME NOT NULL COMMENT '締切日時',
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uq_target_month (target_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 初期データ =====

-- 教室・勤務場所
INSERT INTO locations (name, sort_order) VALUES
    ('小野教室', 1),
    ('郭教室', 2),
    ('桑名教室', 3),
    ('四日市教室', 4),
    ('愛西教室', 5),
    ('イベント', 6),
    ('ZOOM会議', 7),
    ('ロボフェス', 8);

-- 管理者アカウント（パスワード: Admin1234! ）
-- 本番環境では必ず変更すること
INSERT INTO users (name, email, password_hash, role) VALUES
    ('赤坂', 'root2', '$2y$12$GKl3aGHlMHm0QVLKBqVcOuK3p2EKhQHjLmhYu2Y1lJqSUMkRY3VQK', 'admin'),
    ('吉田', 'root1', '$2y$12$GKl3aGHlMHm0QVLKBqVcOuK3p2EKhQHjLmhYu2Y1lJqSUMkRY3VQK', 'admin');

-- 講師アカウント（パスワード: Pass1234! ）
-- 本番環境では各自に変更させること
INSERT INTO users (name, email, password_hash, role) VALUES
    ('浅井', 'asai@example.com',     '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor'),
    ('森本', 'morimoto@example.com', '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor'),
    ('森澤', 'morisawa@example.com', '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor'),
    ('榎本', 'enomoto@example.com',  '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor'),
    ('あんな', 'anna@example.com',   '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor'),
    ('野田', 'noda@example.com',     '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor'),
    ('北川', 'kitagawa@example.com', '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor'),
    ('渡邊', 'watanabe@example.com', '$2y$12$8qh/TKQxkPNhS5tJaS9FbehH9oR5RBRJc5T3L6Yf7AAnLxDq9JW2G', 'instructor');
