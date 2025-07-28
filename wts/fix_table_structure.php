<?php
// テーブル構造修正・確認スクリプト
// データベース接続
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    echo "<h2>🔧 福祉輸送管理システム - テーブル構造修正スクリプト</h2>";
    echo "<p>実行日時: " . date('Y-m-d H:i:s') . "</p><hr>";
    
    // 1. ride_recordsテーブル構造確認
    echo "<h3>📋 1. ride_recordsテーブル構造確認</h3>";
    
    $check_table_sql = "SHOW COLUMNS FROM ride_records";
    $stmt = $pdo->prepare($check_table_sql);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div style='background:#f8f9fa; padding:15px; border-radius:8px; margin:10px 0;'>";
    echo "<h4>📊 現在のカラム一覧:</h4>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>キー</th><th>デフォルト</th></tr>";
    
    $existing_columns = [];
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td><strong>" . $column['Field'] . "</strong></td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
        $existing_columns[] = $column['Field'];
    }
    echo "</table>";
    echo "</div>";
    
    // 2. 必要なカラムの定義
    $required_columns = [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'driver_id' => 'INT NOT NULL',
        'vehicle_id' => 'INT NOT NULL',
        'ride_date' => 'DATE NOT NULL',
        'ride_time' => 'TIME NOT NULL',
        'passenger_count' => 'INT DEFAULT 1',
        'pickup_location' => 'VARCHAR(255) NOT NULL',
        'dropoff_location' => 'VARCHAR(255) NOT NULL',
        'fare' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
        'charge' => 'DECIMAL(8,2) DEFAULT 0',
        'transport_category' => 'VARCHAR(50) NOT NULL',
        'payment_method' => 'VARCHAR(20) NOT NULL DEFAULT \'現金\'',
        'notes' => 'TEXT',
        'is_return_trip' => 'TINYINT(1) DEFAULT 0',
        'original_ride_id' => 'INT NULL',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];
    
    echo "<h3>🔨 2. カラム追加・修正処理</h3>";
    
    $added_columns = [];
    $error_columns = [];
    
    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            try {
                $add_column_sql = "ALTER TABLE ride_records ADD COLUMN `{$column_name}` {$column_definition}";
                $pdo->exec($add_column_sql);
                $added_columns[] = $column_name;
                echo "<div style='color: green; background: #d4edda; padding: 8px; margin: 4px 0; border-radius: 4px;'>";
                echo "✅ カラム追加成功: <strong>{$column_name}</strong> ({$column_definition})";
                echo "</div>";
            } catch (Exception $e) {
                $error_columns[] = $column_name . ": " . $e->getMessage();
                echo "<div style='color: red; background: #f8d7da; padding: 8px; margin: 4px 0; border-radius: 4px;'>";
                echo "❌ カラム追加失敗: <strong>{$column_name}</strong><br>エラー: " . $e->getMessage();
                echo "</div>";
            }
        } else {
            echo "<div style='color: blue; background: #cce5ff; padding: 8px; margin: 4px 0; border-radius: 4px;'>";
            echo "ℹ️ カラム既存: <strong>{$column_name}</strong>";
            echo "</div>";
        }
    }
    
    // 3. インデックス追加
    echo "<h3>🔗 3. インデックス作成</h3>";
    
    $indexes = [
        'idx_ride_date' => 'CREATE INDEX idx_ride_date ON ride_records (ride_date)',
        'idx_driver_id' => 'CREATE INDEX idx_driver_id ON ride_records (driver_id)',
        'idx_vehicle_id' => 'CREATE INDEX idx_vehicle_id ON ride_records (vehicle_id)',
        'idx_ride_datetime' => 'CREATE INDEX idx_ride_datetime ON ride_records (ride_date, ride_time)'
    ];
    
    foreach ($indexes as $index_name => $index_sql) {
        try {
            $pdo->exec($index_sql);
            echo "<div style='color: green; background: #d4edda; padding: 8px; margin: 4px 0; border-radius: 4px;'>";
            echo "✅ インデックス作成成功: <strong>{$index_name}</strong>";
            echo "</div>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "<div style='color: blue; background: #cce5ff; padding: 8px; margin: 4px 0; border-radius: 4px;'>";
                echo "ℹ️ インデックス既存: <strong>{$index_name}</strong>";
                echo "</div>";
            } else {
                echo "<div style='color: red; background: #f8d7da; padding: 8px; margin: 4px 0; border-radius: 4px;'>";
                echo "❌ インデックス作成失敗: <strong>{$index_name}</strong><br>エラー: " . $e->getMessage();
                echo "</div>";
            }
        }
    }
    
    // 4. 修正後のテーブル構造確認
    echo "<h3>✨ 4. 修正後のテーブル構造</h3>";
    
    $final_check_sql = "SHOW COLUMNS FROM ride_records";
    $stmt = $pdo->prepare($final_check_sql);
    $stmt->execute();
    $final_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div style='background:#e8f5e8; padding:15px; border-radius:8px; margin:10px 0;'>";
    echo "<h4>📊 修正後のカラム一覧:</h4>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>キー</th><th>デフォルト</th></tr>";
    
    foreach ($final_columns as $column) {
        $is_new = in_array($column['Field'], $added_columns);
        $row_style = $is_new ? "background-color: #ffffcc;" : "";
        
        echo "<tr style='{$row_style}'>";
        echo "<td><strong>" . $column['Field'] . "</strong>" . ($is_new ? " 🆕" : "") . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
    
    // 5. サンプルデータの確認
    echo "<h3>📋 5. データ確認</h3>";
    
    try {
        $count_sql = "SELECT COUNT(*) as total FROM ride_records";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<div style='background:#f0f8ff; padding:15px; border-radius:8px; margin:10px 0;'>";
        echo "<h4>📊 データ統計:</h4>";
        echo "<p><strong>総レコード数:</strong> " . $count['total'] . " 件</p>";
        
        if ($count['total'] > 0) {
            $sample_sql = "SELECT 
                id, driver_id, vehicle_id, ride_date, ride_time, 
                pickup_location, dropoff_location, fare, charge, 
                transport_category, payment_method, is_return_trip 
                FROM ride_records 
                ORDER BY created_at DESC 
                LIMIT 3";
            $stmt = $pdo->prepare($sample_sql);
            $stmt->execute();
            $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h5>🔍 最新データサンプル:</h5>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
            echo "<tr><th>ID</th><th>日付</th><th>時刻</th><th>乗車地→降車地</th><th>運賃</th><th>復路</th></tr>";
            
            foreach ($samples as $sample) {
                echo "<tr>";
                echo "<td>" . $sample['id'] . "</td>";
                echo "<td>" . $sample['ride_date'] . "</td>";
                echo "<td>" . $sample['ride_time'] . "</td>";
                echo "<td>" . $sample['pickup_location'] . "→" . $sample['dropoff_location'] . "</td>";
                echo "<td>¥" . number_format($sample['fare'] + $sample['charge']) . "</td>";
                echo "<td>" . ($sample['is_return_trip'] ? '復路' : '往路') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='color: red; background: #f8d7da; padding: 8px; margin: 4px 0; border-radius: 4px;'>";
        echo "❌ データ確認エラー: " . $e->getMessage();
        echo "</div>";
    }
    
    // 6. 結果サマリー
    echo "<h3>📄 6. 実行結果サマリー</h3>";
    echo "<div style='background:#f8f9fa; padding:20px; border-radius:8px; border-left:5px solid #007bff;'>";
    echo "<h4>✅ 修正完了</h4>";
    echo "<ul>";
    echo "<li><strong>追加されたカラム数:</strong> " . count($added_columns) . " 個</li>";
    
    if (!empty($added_columns)) {
        echo "<li><strong>追加カラム:</strong> " . implode(', ', $added_columns) . "</li>";
    }
    
    if (!empty($error_columns)) {
        echo "<li><strong>エラーカラム:</strong> " . count($error_columns) . " 個</li>";
        foreach ($error_columns as $error) {
            echo "<li style='color: red;'>❌ " . $error . "</li>";
        }
    }
    
    echo "<li><strong>総カラム数:</strong> " . count($final_columns) . " 個</li>";
    echo "</ul>";
    
    echo "<h5>🚀 次のステップ:</h5>";
    echo "<ol>";
    echo "<li>✅ テーブル構造修正完了</li>";
    echo "<li>🔄 修正版ride_records.phpをアップロード</li>";
    echo "<li>🧪 システム動作テスト実行</li>";
    echo "<li>✨ 乗車記録登録テスト</li>";
    echo "</ol>";
    echo "</div>";
    
    // 7. テストクエリ実行
    echo "<h3>🧪 7. テストクエリ実行</h3>";
    
    try {
        $test_sql = "SELECT 
            COUNT(*) as total_rides,
            COALESCE(SUM(passenger_count), 0) as total_passengers,
            COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as total_revenue
            FROM ride_records 
            WHERE ride_date >= CURDATE() - INTERVAL 7 DAY";
        
        $stmt = $pdo->prepare($test_sql);
        $stmt->execute();
        $test_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<div style='color: green; background: #d4edda; padding: 15px; margin: 10px 0; border-radius: 8px;'>";
        echo "<h4>✅ テストクエリ成功</h4>";
        echo "<p><strong>過去7日間の統計:</strong></p>";
        echo "<ul>";
        echo "<li>総乗車回数: " . $test_result['total_rides'] . " 回</li>";
        echo "<li>総乗車人数: " . $test_result['total_passengers'] . " 名</li>";
        echo "<li>総売上: ¥" . number_format($test_result['total_revenue']) . "</li>";
        echo "</ul>";
        echo "<p><strong>✅ ride_records.phpで使用するクエリが正常に動作することを確認しました。</strong></p>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='color: red; background: #f8d7da; padding: 15px; margin: 10px 0; border-radius: 8px;'>";
        echo "<h4>❌ テストクエリ失敗</h4>";
        echo "<p>エラー: " . $e->getMessage() . "</p>";
        echo "<p>⚠️ さらなる修正が必要です。</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red; background: #f8d7da; padding: 15px; margin: 10px 0; border-radius: 8px;'>";
    echo "<h3>❌ スクリプト実行エラー</h3>";
    echo "<p>エラー: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background-color: #f8f9fa;
}

h2, h3, h4, h5 {
    color: #2c3e50;
    margin-top: 20px;
}

table {
    margin: 10px 0;
    font-size: 14px;
}

th {
    background-color: #3498db;
    color: white;
    padding: 8px;
}

td {
    padding: 6px 8px;
    border: 1px solid #ddd;
}

.success {
    color: #27ae60;
    background-color: #d5f4e6;
}

.error {
    color: #e74c3c;
    background-color: #fadbd8;
}

.info {
    color: #3498db;
    background-color: #d6eaf8;
}
</style>
