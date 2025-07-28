<?php
session_start();

// データベース接続設定
$host = 'localhost';
$dbname = 'twinklemark_wts';
$username = 'twinklemark_taxi';
$password = 'Smiley2525';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>🔍 ride_records テーブル構造確認</h1>";
    echo "<p><strong>実行日時:</strong> " . date('Y-m-d H:i:s') . "</p>";
    
    // ride_records テーブルの構造を詳細確認
    echo "<h3>📋 ride_records テーブル構造</h3>";
    
    $stmt = $pdo->query("DESCRIBE ride_records");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse: collapse; width: 100%; margin: 20px 0;'>";
    echo "<tr style='background-color: #007cba; color: white;'>";
    echo "<th>カラム名</th><th>データ型</th><th>NULL許可</th><th>キー</th><th>デフォルト値</th><th>Extra</th>";
    echo "</tr>";
    
    $existing_columns = [];
    foreach ($columns as $column) {
        $existing_columns[] = $column['Field'];
        echo "<tr>";
        echo "<td><strong>" . $column['Field'] . "</strong></td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?: 'NULL') . "</td>";
        echo "<td>" . $column['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 期待されるカラムと実際のカラムの比較
    echo "<h3>🔍 カラム存在確認</h3>";
    
    $expected_columns = [
        'id', 'driver_id', 'vehicle_id', 'ride_date', 'ride_time',
        'passenger_count', 'pickup_location', 'dropoff_location',
        'fare', 'transportation_type', 'payment_method', 'remarks',
        'created_at', 'updated_at', 'operation_id'
    ];
    
    echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>カラム存在状況</h4>";
    echo "<ul>";
    
    foreach ($expected_columns as $col) {
        if (in_array($col, $existing_columns)) {
            echo "<li style='color: green;'>✅ <strong>$col</strong> - 存在</li>";
        } else {
            echo "<li style='color: red;'>❌ <strong>$col</strong> - 存在しない</li>";
        }
    }
    echo "</ul>";
    echo "</div>";
    
    // 実際のデータサンプルを表示
    echo "<h3>📊 実際のデータサンプル</h3>";
    
    try {
        $stmt = $pdo->query("SELECT * FROM ride_records LIMIT 5");
        $sample_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($sample_data)) {
            echo "<p>テーブル内のデータ例（最新5件）:</p>";
            echo "<div style='overflow-x: auto;'>";
            echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; font-size: 12px;'>";
            
            // ヘッダー
            echo "<tr style='background-color: #f0f0f0;'>";
            foreach (array_keys($sample_data[0]) as $header) {
                echo "<th>$header</th>";
            }
            echo "</tr>";
            
            // データ
            foreach ($sample_data as $row) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars($value ?: 'NULL') . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        } else {
            echo "<p style='color: #856404; background-color: #fff3cd; padding: 10px; border-radius: 5px;'>テーブルにデータがありません</p>";
        }
        
    } catch (PDOException $e) {
        echo "<p style='color: red;'>データ取得エラー: " . $e->getMessage() . "</p>";
    }
    
    // CREATE TABLE 文の生成（推定）
    echo "<h3>🛠 推定されるテーブル構造</h3>";
    echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px;'>";
    echo "<p>現在の構造に基づいた CREATE TABLE 文:</p>";
    echo "<pre><code>";
    
    echo "CREATE TABLE ride_records (\n";
    foreach ($columns as $column) {
        $line = "  " . $column['Field'] . " " . $column['Type'];
        if ($column['Null'] === 'NO') $line .= " NOT NULL";
        if ($column['Default'] !== null) $line .= " DEFAULT '" . $column['Default'] . "'";
        if ($column['Extra']) $line .= " " . strtoupper($column['Extra']);
        echo $line . ",\n";
    }
    echo "  PRIMARY KEY (id)\n";
    echo ");";
    
    echo "</code></pre>";
    echo "</div>";
    
    // 修正提案
    echo "<h3>🔧 修正提案</h3>";
    echo "<div style='background-color: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px;'>";
    echo "<h4>Option 1: 不足カラムを追加</h4>";
    echo "<p>以下のカラムが不足している場合、追加することができます：</p>";
    echo "<pre><code>";
    
    $missing_columns = array_diff($expected_columns, $existing_columns);
    foreach ($missing_columns as $missing_col) {
        switch ($missing_col) {
            case 'remarks':
                echo "ALTER TABLE ride_records ADD COLUMN remarks TEXT COMMENT '備考';\n";
                break;
            case 'transportation_type':
                echo "ALTER TABLE ride_records ADD COLUMN transportation_type VARCHAR(50) DEFAULT '通院' COMMENT '輸送分類';\n";
                break;
            case 'payment_method':
                echo "ALTER TABLE ride_records ADD COLUMN payment_method VARCHAR(50) DEFAULT '現金' COMMENT '支払方法';\n";
                break;
            case 'created_at':
                echo "ALTER TABLE ride_records ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時';\n";
                break;
            case 'updated_at':
                echo "ALTER TABLE ride_records ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時';\n";
                break;
        }
    }
    
    echo "</code></pre>";
    echo "</div>";
    
    echo "<div style='background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-top: 10px;'>";
    echo "<h4>Option 2: コードを現在の構造に合わせて修正</h4>";
    echo "<p>存在しないカラムを参照しないように、PHP コードを修正します。</p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>❌ データベース接続エラー</h2>";
    echo "<p>エラー詳細: " . $e->getMessage() . "</p>";
}
?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background-color: #f5f5f5;
}

h1, h3, h4 {
    color: #333;
}

table {
    background-color: white;
}

th {
    text-align: left;
}

pre {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}

code {
    font-family: monospace;
    font-size: 14px;
}
</style>
