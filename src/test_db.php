<?php
// ======== MySQL 接続テスト ========

// ★ ローカル用（XAMPP）
$local = [
    'host' => 'localhost',
    'dbname' => 'robofes',
    'user' => 'root',
    'pass' => ''
];

// ★ ロリポップ用（本番）
$production = [
    'host' => 'mysql403.phy.lolipop.lan',   // ロリポップのDBホスト
    'dbname' => 'LAA1629440-shift',         // あなたのDB名
    'user' => 'LAA1629440',                 // あなたのDBユーザー名
    'pass' => '575Seisei'
];

// ★ どちらを使うか切り替え（必要に応じて変更）
$config = $production;   // ← 本番をテストしたい場合
// $config = $local;      // ← ローカルをテストしたい場合

// ======== 接続処理 ========
try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";

    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "✅ MySQL 接続成功！<br>";
    echo "接続先：{$config['host']} / DB：{$config['dbname']}";

} catch (PDOException $e) {
    echo "❌ MySQL 接続失敗<br>";
    echo "エラー内容： " . $e->getMessage();
}
?>
