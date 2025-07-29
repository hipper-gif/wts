<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>roleカラム削除処理</h2>";
    
    // 1. 現在のテーブル構造確認
    echo "<h3>削除前のテーブル構造:</h3>";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>デフォルト値</th></tr>";
    
    $role_exists = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'role') {
            $role_exists = true;
            echo "<tr style='background-color: #ffcccc;'>";
        } else {
            echo "<tr>";
        }
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if (!$role_exists) {
        echo "<p style='color: green;'>✅ roleカラムは既に存在しません。</p>";
        exit;
    }
    
    // 2. roleカラムのデータ確認
    echo "<h3>roleカラムのデータ確認:</h3>";
    $stmt = $pdo->query("SELECT id, name, permission_level, role FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>名前</th><th>permission_level</th><th>role</th><th>整合性</th></tr>";
    
    foreach ($users as $user) {
        $consistent = (strtolower($user['permission_level']) === $user['role']);
        echo "<tr" . ($consistent ? "" : " style='background-color: #ffffcc;'") . ">";
        echo "<td>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td>" . htmlspecialchars($user['permission_level']) . "</td>";
        echo "<td>" . htmlspecialchars($user['role']) . "</td>";
        echo "<td>" . ($consistent ? "✅" : "⚠️不整合") . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 3. 削除確認フォーム
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
        
        echo "<h3>roleカラム削除実行中...</h3>";
        
        // ALTER文実行
        $stmt = $pdo->exec("ALTER TABLE users DROP COLUMN role");
        
        echo "<p style='color: green;'>✅ roleカラムを削除しました。</p>";
        
        // 削除後のテーブル構造確認
        echo "<h3>削除後のテーブル構造:</h3>";
        $stmt = $pdo->query("DESCRIBE users");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>デフォルト値</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h3>✅ 削除完了</h3>";
        echo "<p><strong>次のステップ:</strong></p>";
        echo "<ul>";
        echo "<li>user_management.phpからroleカラム参照を削除</li>";
        echo "<li>他のファイルでroleカラムを使用している箇所をpermission_levelに変更</li>";
        echo "</ul>";
        
    } else {
        // 削除確認フォーム表示
        echo "<h3>⚠️ 削除確認</h3>";
        echo "<p><strong>注意:</strong> roleカラムを削除すると元に戻せません。</p>";
        echo "<p>roleカラムは現在のデータから判断すると、permission_levelと重複しており削除可能です。</p>";
        
        echo "<form method='POST'>";
        echo "<button type='submit' name='confirm_delete' value='1' style='background-color: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; font-size: 16px;' onclick=\"return confirm('本当にroleカラムを削除しますか？この操作は元に戻せません。')\">roleカラムを削除する</button>";
        echo "</form>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
