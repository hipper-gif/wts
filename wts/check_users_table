<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>usersテーブル構造確認</h2>";
    
    // テーブル構造を取得
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>カラム一覧:</h3>";
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
    
    // 現在のデータも表示
    echo "<h3>現在のデータ（最初の5件）:</h3>";
    $stmt = $pdo->query("SELECT * FROM users LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($users)) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        
        // ヘッダー行
        echo "<tr>";
        foreach (array_keys($users[0]) as $column) {
            echo "<th>" . htmlspecialchars($column) . "</th>";
        }
        echo "</tr>";
        
        // データ行
        foreach ($users as $user) {
            echo "<tr>";
            foreach ($user as $value) {
                echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 不足カラムの確認
    echo "<h3>不足している可能性のあるカラム:</h3>";
    $required_columns = ['login_id', 'password', 'is_active', 'created_at', 'updated_at'];
    $existing_columns = array_column($columns, 'Field');
    
    $missing_columns = array_diff($required_columns, $existing_columns);
    
    if (empty($missing_columns)) {
        echo "<p style='color: green;'>✅ 必要なカラムはすべて存在します。</p>";
    } else {
        echo "<p style='color: red;'>❌ 以下のカラムが不足しています:</p>";
        echo "<ul>";
        foreach ($missing_columns as $column) {
            echo "<li>" . htmlspecialchars($column) . "</li>";
        }
        echo "</ul>";
        
        // ALTER文を生成
        echo "<h4>必要なALTER文:</h4>";
        echo "<pre>";
        foreach ($missing_columns as $column) {
            switch ($column) {
                case 'login_id':
                    echo "ALTER TABLE users ADD COLUMN login_id VARCHAR(50) UNIQUE;\n";
                    break;
                case 'password':
                    echo "ALTER TABLE users ADD COLUMN password VARCHAR(255);\n";
                    break;
                case 'is_active':
                    echo "ALTER TABLE users ADD COLUMN is_active BOOLEAN DEFAULT TRUE;\n";
                    break;
                case 'created_at':
                    echo "ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;\n";
                    break;
                case 'updated_at':
                    echo "ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;\n";
                    break;
            }
        }
        echo "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
