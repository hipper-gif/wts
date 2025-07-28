<?php
// usersテーブル構造修正スクリプト（最終版）
// URL: https://tw1nkle.com/Smiley/taxi/wts/fix_users_table_final.php

require_once 'config/database.php';

echo "<h2>🔧 usersテーブル構造修正（最終版）</h2>";
echo "<pre style='background: #f8f9fa; padding: 20px; border-radius: 5px;'>";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4", 
                   "twinklemark_taxi", "Smiley2525");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ データベース接続: 成功\n\n";
    
    // 1. 現在のテーブル構造確認
    echo "=== 現在のusersテーブル構造 ===\n";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $existing_columns = [];
    foreach ($columns as $column) {
        $existing_columns[] = $column['Field'];
        echo "- {$column['Field']} ({$column['Type']})\n";
    }
    echo "\n";
    
    // 2. 必要なカラムを追加
    $required_columns = [
        'name' => 'VARCHAR(100)',
        'role' => 'VARCHAR(50) DEFAULT "運転者"'
    ];
    
    foreach ($required_columns as $column => $definition) {
        if (!in_array($column, $existing_columns)) {
            $sql = "ALTER TABLE users ADD COLUMN {$column} {$definition}";
            $pdo->exec($sql);
            echo "✅ {$column} カラムを追加しました\n";
        } else {
            echo "✅ {$column} カラムは既に存在します\n";
        }
    }
    echo "\n";
    
    // 3. 既存ユーザーにデフォルト値を設定
    echo "=== ユーザーデータの修正 ===\n";
    
    // nameが空のユーザーにデフォルト名を設定
    $pdo->exec("UPDATE users SET name = CONCAT('ユーザー', id) WHERE name IS NULL OR name = ''");
    echo "✅ 空のnameにデフォルト名を設定\n";
    
    // roleが空のユーザーに運転者権限を設定
    $pdo->exec("UPDATE users SET role = '運転者' WHERE role IS NULL OR role = '' OR role = 'user'");
    echo "✅ 空のroleに運転者権限を設定\n";
    
    // 特定のユーザーに適切な名前を設定
    $name_mappings = [
        'admin' => '管理者',
        'driver1' => '運転者1',
        'driver2' => '運転者2',
        'Smiley999' => 'スマイリー管理者',
        'Smiley01' => 'スマイリー運転者1',
        'Smiley02' => 'スマイリー運転者2',
        'Smiley03' => 'スマイリー運転者3',
        'Smiley04' => 'スマイリー運転者4',
        'Smiley05' => 'スマイリー運転者5'
    ];
    
    foreach ($name_mappings as $login_id => $name) {
        $stmt = $pdo->prepare("UPDATE users SET name = ? WHERE login_id = ?");
        $stmt->execute([$name, $login_id]);
    }
    echo "✅ ログインIDベースで適切な名前を設定\n";
    
    // adminユーザーをシステム管理者に設定
    $pdo->exec("UPDATE users SET role = 'システム管理者' WHERE login_id = 'admin'");
    $pdo->exec("UPDATE users SET role = 'システム管理者' WHERE login_id = 'Smiley999'");
    echo "✅ 管理者ユーザーにシステム管理者権限を設定\n\n";
    
    // 4. 修正後の確認
    echo "=== 修正後のユーザー一覧 ===\n";
    $stmt = $pdo->query("SELECT id, name, login_id, role FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        echo "ID: {$user['id']}, 名前: {$user['name']}, ログインID: {$user['login_id']}, 権限: {$user['role']}\n";
    }
    echo "\n";
    
    // 5. ride_records.phpクエリの確認
    echo "=== ride_records.phpクエリテスト ===\n";
    $stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('運転者', 'システム管理者') ORDER BY name");
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "取得件数: " . count($drivers) . "件\n";
    foreach ($drivers as $driver) {
        echo "- ID: {$driver['id']}, 名前: {$driver['name']}\n";
    }
    
    if (count($drivers) > 0) {
        echo "\n✅ 成功！これで運転者リストが表示されます。\n";
    } else {
        echo "\n❌ まだ問題があります。\n";
    }
    
} catch (PDOException $e) {
    echo "❌ エラー: " . $e->getMessage() . "\n";
}

echo "</pre>";

echo "<div style='text-align: center; margin: 20px;'>";
echo "<a href='ride_records.php' style='padding: 15px 25px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; font-size: 16px;'>乗車記録で確認</a>";
echo "</div>";
?>
