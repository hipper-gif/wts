<?php
// テーブル構造修正スクリプト
// URL: https://tw1nkle.com/Smiley/taxi/wts/fix_table_structure.php

session_start();
require_once 'config/database.php';

echo "<h2>🔧 テーブル構造修正スクリプト</h2>";
echo "<div style='font-family: monospace; background: #f5f5f5; padding: 20px;'>";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4", 
                   "twinklemark_taxi", "Smiley2525");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ データベース接続成功<br><br>";
    
    // 1. 既存テーブル確認
    echo "<strong>📋 既存テーブル確認:</strong><br>";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "- {$table}<br>";
    }
    echo "<br>";
    
    // 2. 不足テーブルの作成
    $missing_tables = [];
    
    // departure_records テーブル
    if (!in_array('departure_records', $tables)) {
        $missing_tables[] = 'departure_records';
        $sql = "CREATE TABLE departure_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            driver_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            departure_date DATE NOT NULL,
            departure_time TIME NOT NULL,
            weather VARCHAR(20),
            departure_mileage INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);
        echo "✅ departure_records テーブル作成完了<br>";
    }
    
    // arrival_records テーブル
    if (!in_array('arrival_records', $tables)) {
        $missing_tables[] = 'arrival_records';
        $sql = "CREATE TABLE arrival_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            departure_record_id INT,
            driver_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            arrival_date DATE NOT NULL,
            arrival_time TIME NOT NULL,
            arrival_mileage INT,
            total_distance INT,
            fuel_cost INT DEFAULT 0,
            highway_cost INT DEFAULT 0,
            other_cost INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (departure_record_id) REFERENCES departure_records(id)
        )";
        $pdo->exec($sql);
        echo "✅ arrival_records テーブル作成完了<br>";
    }
    
    // daily_operations テーブル（互換性のため）が必要な場合は作成
    if (!in_array('daily_operations', $tables)) {
        $missing_tables[] = 'daily_operations';
        $sql = "CREATE TABLE daily_operations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            driver_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            operation_date DATE NOT NULL,
            departure_time TIME,
            return_time TIME,
            weather VARCHAR(20),
            departure_mileage INT,
            return_mileage INT,
            total_distance INT,
            fuel_cost INT DEFAULT 0,
            highway_cost INT DEFAULT 0,
            other_cost INT DEFAULT 0,
            break_location VARCHAR(100),
            break_start_time TIME,
            break_end_time TIME,
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);
        echo "✅ daily_operations テーブル作成完了（互換性）<br>";
    }
    
    // 3. ride_records テーブルの構造確認・修正
    echo "<br><strong>📋 ride_records テーブル構造確認:</strong><br>";
    
    try {
        $stmt = $pdo->query("DESCRIBE ride_records");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $existing_columns = array_column($columns, 'Field');
        echo "既存カラム: " . implode(', ', $existing_columns) . "<br>";
        
        // 必要なカラムを追加
        $required_columns = [
            'driver_id' => 'INT',
            'vehicle_id' => 'INT',
            'ride_date' => 'DATE',
            'transportation_type' => 'VARCHAR(20)',
            'payment_method' => 'VARCHAR(20)'
        ];
        
        foreach ($required_columns as $column => $type) {
            if (!in_array($column, $existing_columns)) {
                $alter_sql = "ALTER TABLE ride_records ADD COLUMN {$column} {$type}";
                if ($column === 'driver_id' || $column === 'vehicle_id') {
                    $alter_sql .= " NOT NULL DEFAULT 1";
                } elseif ($column === 'ride_date') {
                    $alter_sql .= " NOT NULL DEFAULT (CURDATE())";
                } else {
                    $alter_sql .= " DEFAULT NULL";
                }
                $pdo->exec($alter_sql);
                echo "✅ {$column} カラム追加完了<br>";
            }
        }
        
        // operation_id を NULL許可に変更
        if (in_array('operation_id', $existing_columns)) {
            $pdo->exec("ALTER TABLE ride_records MODIFY operation_id INT NULL");
            echo "✅ operation_id カラムをNULL許可に変更<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ ride_records テーブル処理エラー: " . $e->getMessage() . "<br>";
    }
    
    // 4. 最終確認
    echo "<br><strong>🎯 修正完了サマリー:</strong><br>";
    if (empty($missing_tables)) {
        echo "✅ 全ての必要テーブルが存在していました<br>";
    } else {
        echo "✅ 作成したテーブル: " . implode(', ', $missing_tables) . "<br>";
    }
    echo "✅ ride_records テーブル構造修正完了<br>";
    echo "✅ システムは正常に動作するはずです<br><br>";
    
    echo "<strong>🚀 次のステップ:</strong><br>";
    echo "1. <a href='dashboard.php'>ダッシュボード</a>に戻る<br>";
    echo "2. <a href='ride_records.php'>乗車記録</a>をテストする<br>";
    echo "3. エラーが解決されているか確認<br>";
    
} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "<br>";
} catch (Exception $e) {
    echo "❌ システムエラー: " . $e->getMessage() . "<br>";
}

echo "</div>";
echo "<br><a href='dashboard.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>ダッシュボードに戻る</a>";
?>
