<?php
/**
 * 福祉輸送管理システム - ユーザー権限修正スクリプト
 * 既存テーブル構造を活用した権限設計の修正
 */

require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>福祉輸送管理システム - ユーザー権限修正</h2>";
    echo "<div style='font-family: monospace; background: #f5f5f5; padding: 20px;'>";
    
    // Step 1: 現在のテーブル構造確認
    echo "<h3>Step 1: 現在のusersテーブル構造確認</h3>";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Step 2: role カラムの修正（必要な場合のみ）
    echo "<h3>Step 2: role カラムの確認・修正</h3>";
    
    // 現在のroleカラムの値を確認
    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    $roleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "現在のrole値:<br>";
    foreach ($roleData as $role) {
        echo "- {$role['role']}: {$role['count']}人<br>";
    }
    
    // roleカラムがENUMでない場合は修正
    $needRoleModify = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'role' && !strpos($column['Type'], 'enum')) {
            $needRoleModify = true;
            break;
        }
    }
    
    if ($needRoleModify) {
        echo "<br>role カラムをENUMに修正中...<br>";
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'user') NOT NULL DEFAULT 'user'");
        echo "✅ role カラムをENUM('admin', 'user')に修正完了<br>";
    } else {
        echo "<br>✅ role カラムは適切な形式です<br>";
    }
    
    // Step 3: 業務属性カラムの追加
    echo "<h3>Step 3: 業務属性カラムの追加</h3>";
    
    $attributeColumns = ['is_driver', 'is_caller', 'is_inspector'];
    $existingColumns = array_column($columns, 'Field');
    
    foreach ($attributeColumns as $column) {
        if (!in_array($column, $existingColumns)) {
            echo "{$column} カラムを追加中...<br>";
            $default = ($column === 'is_driver') ? 'TRUE' : 'FALSE';
            $pdo->exec("ALTER TABLE users ADD COLUMN {$column} BOOLEAN DEFAULT {$default} AFTER role");
            echo "✅ {$column} カラム追加完了<br>";
        } else {
            echo "✅ {$column} カラムは既に存在します<br>";
        }
    }
    
    // Step 4: 既存データの移行
    echo "<h3>Step 4: 既存データの移行</h3>";
    
    // 管理者権限の統一
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE role IN ('システム管理者', '管理者')");
    $stmt->execute();
    $adminUpdated = $stmt->rowCount();
    echo "管理者権限統一: {$adminUpdated}件<br>";
    
    // ユーザー権限の統一
    $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE role NOT IN ('admin')");
    $stmt->execute();
    $userUpdated = $stmt->rowCount();
    echo "ユーザー権限統一: {$userUpdated}件<br>";
    
    // 業務属性の初期設定
    echo "<br>業務属性の初期設定中...<br>";
    
    // 全員に運転者属性を付与（既にデフォルトでTRUE）
    $stmt = $pdo->prepare("UPDATE users SET is_driver = TRUE");
    $stmt->execute();
    echo "✅ 全ユーザーに運転者属性を設定<br>";
    
    // 管理者には点呼者・点検者属性も付与
    $stmt = $pdo->prepare("UPDATE users SET is_caller = TRUE, is_inspector = TRUE WHERE role = 'admin'");
    $stmt->execute();
    $adminAttrUpdated = $stmt->rowCount();
    echo "✅ 管理者{$adminAttrUpdated}人に点呼者・点検者属性を設定<br>";
    
    // Step 5: 修正後の確認
    echo "<h3>Step 5: 修正後の確認</h3>";
    
    $stmt = $pdo->query("
        SELECT name, role,
               CASE WHEN is_driver THEN '運転者 ' ELSE '' END as driver_attr,
               CASE WHEN is_caller THEN '点呼者 ' ELSE '' END as caller_attr,
               CASE WHEN is_inspector THEN '点検者 ' ELSE '' END as inspector_attr
        FROM users 
        ORDER BY role DESC, name
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>ユーザー名</th><th>権限</th><th>業務属性</th></tr>";
    foreach ($users as $user) {
        $attributes = $user['driver_attr'] . $user['caller_attr'] . $user['inspector_attr'];
        $roleDisplay = $user['role'] === 'admin' ? '管理者' : 'ユーザー';
        echo "<tr>";
        echo "<td>{$user['name']}</td>";
        echo "<td>{$roleDisplay}</td>";
        echo "<td>{$attributes}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Step 6: 修正後のテーブル構造表示
    echo "<h3>Step 6: 修正後のテーブル構造</h3>";
    $stmt = $pdo->query("DESCRIBE users");
    $newColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($newColumns as $column) {
        $style = in_array($column['Field'], ['role', 'is_driver', 'is_caller', 'is_inspector']) 
                ? 'background-color: #e8f5e8;' : '';
        echo "<tr style='{$style}'>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>🎉 権限修正完了！</h3>";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;'>";
    echo "<strong>修正内容:</strong><br>";
    echo "✅ role カラム: ENUM('admin', 'user') に統一<br>";
    echo "✅ is_driver カラム: 運転者属性（全員TRUE）<br>";
    echo "✅ is_caller カラム: 点呼者属性（管理者TRUE）<br>";
    echo "✅ is_inspector カラム: 点検者属性（管理者TRUE）<br>";
    echo "<br><strong>今後の使用方法:</strong><br>";
    echo "• 権限チェック: \$_SESSION['role'] === 'admin'<br>";
    echo "• 運転者リスト: WHERE is_driver = TRUE<br>";
    echo "• 点呼者リスト: WHERE is_caller = TRUE<br>";
    echo "• 点検者リスト: WHERE is_inspector = TRUE<br>";
    echo "</div>";
    
    echo "<div style='background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; margin: 10px 0;'>";
    echo "<strong>⚠️ 次のステップ:</strong><br>";
    echo "1. user_management.php でユーザー編集画面を更新<br>";
    echo "2. 各機能のリスト表示関数を更新<br>";
    echo "3. 権限チェック処理の確認<br>";
    echo "</div>";
    
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='color: red; background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px;'>";
    echo "<strong>❌ エラーが発生しました:</strong><br>";
    echo htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>
