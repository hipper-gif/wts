<?php
// 正しい運転者取得修正スクリプト
// URL: https://tw1nkle.com/Smiley/taxi/wts/fix_drivers_correct.php

require_once 'config/database.php';

echo "<h2>🔧 正しい運転者取得修正</h2>";
echo "<pre style='background: #f8f9fa; padding: 20px; border-radius: 5px;'>";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4", 
                   "twinklemark_taxi", "Smiley2525");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ データベース接続: 成功\n\n";
    
    // 1. 現在のusersテーブル構造確認
    echo "=== usersテーブル構造確認 ===\n";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $existing_columns = array_column($columns, 'Field');
    foreach ($columns as $column) {
        echo "- {$column['Field']} ({$column['Type']})\n";
    }
    echo "\n";
    
    // 2. 必要なカラムを追加
    $required_columns = [
        'is_driver' => 'TINYINT(1) DEFAULT 0',
        'is_active' => 'TINYINT(1) DEFAULT 1'
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
    
    // 3. 既存ユーザーの設定
    echo "=== ユーザーデータの修正 ===\n";
    
    // 全ユーザーをアクティブに設定
    $pdo->exec("UPDATE users SET is_active = 1 WHERE is_active IS NULL");
    echo "✅ 全ユーザーをアクティブに設定\n";
    
    // 特定のlogin_idのユーザーを運転者に設定
    $driver_patterns = ['driver1', 'driver2', 'Smiley01', 'Smiley02', 'Smiley03', 'Smiley04', 'Smiley05'];
    foreach ($driver_patterns as $pattern) {
        $stmt = $pdo->prepare("UPDATE users SET is_driver = 1, role = 'driver' WHERE login_id = ?");
        $stmt->execute([$pattern]);
    }
    echo "✅ 運転者パターンのユーザーにis_driver=1を設定\n";
    
    // 管理者ユーザーの設定
    $admin_patterns = ['admin', 'Smiley999'];
    foreach ($admin_patterns as $pattern) {
        $stmt = $pdo->prepare("UPDATE users SET role = 'admin', is_driver = 1 WHERE login_id = ?");
        $stmt->execute([$pattern]);
    }
    echo "✅ 管理者ユーザーにrole='admin', is_driver=1を設定\n";
    
    // nameが空の場合の設定
    $stmt = $pdo->query("SELECT id, login_id, name FROM users WHERE name IS NULL OR name = ''");
    $empty_name_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($empty_name_users as $user) {
        $name_map = [
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
        
        $new_name = $name_map[$user['login_id']] ?? 'ユーザー' . $user['id'];
        $stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
        $stmt->execute([$new_name, $user['id']]);
    }
    echo "✅ 空のnameフィールドに適切な名前を設定\n\n";
    
    // 4. 修正後の確認
    echo "=== 修正後のユーザー一覧 ===\n";
    $stmt = $pdo->query("SELECT id, name, login_id, role, is_driver, is_active FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        $driver_flag = $user['is_driver'] ? '○' : '×';
        $active_flag = $user['is_active'] ? '○' : '×';
        echo "ID: {$user['id']}, 名前: {$user['name']}, ログインID: {$user['login_id']}, ";
        echo "権限: {$user['role']}, 運転者: {$driver_flag}, アクティブ: {$active_flag}\n";
    }
    echo "\n";
    
    // 5. GitHubと同じクエリをテスト
    echo "=== GitHubと同じクエリをテスト ===\n";
    $github_sql = "SELECT id, name FROM users WHERE (role IN ('driver', 'admin') OR is_driver = 1) AND is_active = 1 ORDER BY name";
    echo "実行SQL: {$github_sql}\n\n";
    
    $stmt = $pdo->query($github_sql);
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "取得件数: " . count($drivers) . "件\n";
    if (count($drivers) > 0) {
        echo "取得されたユーザー:\n";
        foreach ($drivers as $driver) {
            echo "- ID: {$driver['id']}, 名前: {$driver['name']}\n";
        }
        echo "\n✅ 成功！これで運転者リストが表示されます。\n";
    } else {
        echo "\n❌ まだ問題があります。追加の修正が必要です。\n";
    }
    
} catch (PDOException $e) {
    echo "❌ エラー: " . $e->getMessage() . "\n";
}

echo "</pre>";

echo "<div style='text-align: center; margin: 20px;'>";
echo "<a href='ride_records.php' style='padding: 15px 25px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; font-size: 16px;'>乗車記録で確認</a>";
echo "</div>";
?>
