# ロボット教室 講師シフト管理システム

## 技術スタック
- PHP 8.2 + Apache
- MySQL 8.0
- phpMyAdmin
- Docker / docker-compose

## 起動方法

```bash
cd shift-app
docker-compose up -d
```

## アクセス先

| URL | 用途 |
|-----|------|
| http://localhost:8080 | アプリ本体 |
| http://localhost:8081 | phpMyAdmin |

## 初期アカウント

### 管理者
| 氏名 | メールアドレス | パスワード |
|------|-------------|-----------|
| 赤坂 | akasaka@example.com | Admin1234! |
| 吉田 | yoshida@example.com | Admin1234! |

### 講師
| 氏名 | メールアドレス | パスワード |
|------|-------------|-----------|
| 浅井 | asai@example.com | Pass1234! |
| 森本 | morimoto@example.com | Pass1234! |
| 森澤 | morisawa@example.com | Pass1234! |
| 榎本 | enomoto@example.com | Pass1234! |
| あんな | anna@example.com | Pass1234! |
| 野田 | noda@example.com | Pass1234! |
| 北川 | kitagawa@example.com | Pass1234! |
| 渡邊 | watanabe@example.com | Pass1234! |

> ⚠️ 本番環境では必ず全パスワードを変更してください。

## ディレクトリ構成

```
shift-app/
├── docker-compose.yml
├── docker/
│   ├── web/Dockerfile       # PHP 8.2 + Apache
│   └── db/init.sql          # DB初期化SQL（テーブル+初期データ）
└── src/                     # Webルート
    ├── index.php            # ルートリダイレクト
    ├── .htaccess
    ├── includes/
    │   ├── config.php       # アプリ設定・DB接続情報
    │   ├── Database.php     # PDOシングルトン
    │   ├── auth.php         # 認証・CSRF・セッション管理
    │   ├── layout_header.php
    │   └── layout_footer.php
    ├── auth/
    │   ├── login.php
    │   └── logout.php
    ├── admin/
    │   ├── dashboard.php    # 管理者ダッシュボード
    │   ├── schedules.php    # コマ管理
    │   ├── shift_adjust.php # シフト調整・確定
    │   └── users.php        # 講師アカウント管理
    ├── instructor/
    │   ├── dashboard.php    # 講師マイページ
    │   ├── request.php      # 出勤希望申請（カレンダー）
    │   └── my_shifts.php    # 確定シフト確認
    └── assets/
        ├── css/style.css
        └── js/main.js
```

## 機能一覧

### 共通
- メール + パスワード認証
- CSRF トークン保護
- セッションタイムアウト（30分）
- XSS エスケープ（htmlspecialchars）
- PDO プリペアドステートメント

### 管理者
- ダッシュボード（今月の統計 + 直近コマ一覧）
- コマ管理（追加・削除）
- シフト調整（希望申請の承認・却下・確定・解除）
- 講師アカウント管理（追加・有効/無効・パスワードリセット）

### 講師
- マイページ（確定シフト・申請状況サマリ）
- 出勤希望申請（月別カレンダー + モーダル操作）
- 確定シフト一覧（月別）

## 今後の実装予定
- [ ] 2段階認証（TOTP / Google Authenticator）
- [ ] パスワードリセット（メール送信）
- [ ] 申請締切日設定
- [ ] メール通知（シフト確定時）
- [ ] CSV エクスポート
