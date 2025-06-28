<?php
// データベース接続テスト
require_once 'config/database.php';

echo "<h2>データベース接続テスト</h2>";

try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✅ データベース接続成功！</p>";
    
    // テーブル一覧を取得
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<h3>作成済みテーブル一覧：</h3>";
    echo "<ul>";
    foreach($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
    // 初期データ確認
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $userCount = $stmt->fetchColumn();
    echo "<p>登録ユーザー数: $userCount 人</p>";
    
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings LIMIT 5");
    $settings = $stmt->fetchAll();
    echo "<h3>システム設定（最初の5件）：</h3>";
    echo "<ul>";
    foreach($settings as $setting) {
        echo "<li>{$setting['setting_key']}: {$setting['setting_value']}</li>";
    }
    echo "</ul>";
    
} catch(Exception $e) {
    echo "<p style='color: red;'>❌ エラー: " . $e->getMessage() . "</p>";
}
?>