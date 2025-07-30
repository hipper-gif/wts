<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>🔧 完全修正確認スクリプト</h2>";
    
    // Step 1: daily_operationsテーブルの現在の構造確認
    echo "<h3>Step 1: daily_operationsテーブル構造確認</h3>";
    $stmt = $pdo->query("DESCRIBE daily_operations");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $required_columns = ['total_trips', 'total_passengers', 'total_revenue'];
    $existing_columns = array_column($columns, 'Field');
    
    echo "<p>既存カラム: " . implode(', ', $existing_columns) . "</p>";
    
    $missing_columns = array_diff($required_columns, $existing_columns);
    
    if (!empty($missing_columns)) {
        echo "<p style='color:red;'>⚠️ 不足カラム: " . implode(', ', $missing_columns) . "</p>";
        echo "<p>📝 以下のSQLを実行してください:</p>";
        echo "<pre style='background:#f0f0f0; padding:10px;'>";
        
        foreach ($missing_columns as $column) {
            switch ($column) {
                case 'total_trips':
                    echo "ALTER TABLE daily_operations ADD COLUMN total_trips INT DEFAULT 0;\n";
                    break;
                case 'total_passengers':
                    echo "ALTER TABLE daily_operations ADD COLUMN total_passengers INT DEFAULT 0;\n";
                    break;
                case 'total_revenue':
                    echo "ALTER TABLE daily_operations ADD COLUMN total_revenue DECIMAL(10,2) DEFAULT 0.00;\n";
                    break;
            }
        }
        echo "</pre>";
        
        // 自動修正を試行
        echo "<p>🔄 自動修正を試行中...</p>";
        foreach ($missing_columns as $column) {
            try {
                switch ($column) {
                    case 'total_trips':
                        $pdo->exec("ALTER TABLE daily_operations ADD COLUMN total_trips INT DEFAULT 0");
                        echo "✅ total_trips カラムを追加しました<br>";
                        break;
                    case 'total_passengers':
                        $pdo->exec("ALTER TABLE daily_operations ADD COLUMN total_passengers INT DEFAULT 0");
                        echo "✅ total_passengers カラムを追加しました<br>";
                        break;
                    case 'total_revenue':
                        $pdo->exec("ALTER TABLE daily_operations ADD COLUMN total_revenue DECIMAL(10,2) DEFAULT 0.00");
                        echo "✅ total_revenue カラムを追加しました<br>";
                        break;
                }
            } catch (Exception $e) {
                echo "<p style='color:red;'>❌ {$column} カラム追加エラー: " . $e->getMessage() . "</p>";
            }
        }
    } else {
        echo "<p style='color:green;'>✅ 必要なカラムは全て存在しています</p>";
    }
    
    // Step 2: 新規登録テスト
    echo "<h3>Step 2: 新規登録テスト</h3>";
    
    // まず、テスト用のdaily_operationsレコードを作成
    $operation_sql = "INSERT INTO daily_operations 
        (driver_id, vehicle_id, operation_date, departure_time, total_trips, total_passengers, total_revenue) 
        VALUES (1, 1, '2025-07-30', '09:00', 0, 0, 0.00)";
    
    try {
        $stmt = $pdo->prepare($operation_sql);
        $stmt->execute();
        $operation_id = $pdo->lastInsertId();
        echo "✅ テスト用daily_operations作成成功 (ID: {$operation_id})<br>";
        
        // ride_recordsにINSERTテスト
        $ride_sql = "INSERT INTO ride_records 
            (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
             pickup_location, dropoff_location, fare, charge, transport_category, 
             payment_method, notes, is_return_trip, original_ride_id, operation_id) 
            VALUES (1, 1, '2025-07-30', '10:00', 1, 'テスト乗車地', 'テスト降車地', 
                    1000, 0, '通院', '現金', 'テスト', 0, NULL, ?)";
        
        $stmt = $pdo->prepare($ride_sql);
        $result = $stmt->execute([$operation_id]);
        
        if ($result) {
            echo "✅ ride_records INSERT テスト成功！<br>";
            
            // テストデータを削除
            $ride_id = $pdo->lastInsertId();
            $pdo->prepare("DELETE FROM ride_records WHERE id = ?")->execute([$ride_id]);
            $pdo->prepare("DELETE FROM daily_operations WHERE id = ?")->execute([$operation_id]);
            echo "🗑️ テストデータ削除完了<br>";
            
            echo "<h3>🎉 修正完了！</h3>";
            echo "<p>✅ ride_records.php での新規登録が正常に動作するはずです</p>";
            echo "<p>🔗 <a href='ride_records.php' target='_blank'>ride_records.php でテストしてください</a></p>";
            
        } else {
            echo "❌ ride_records INSERT テスト失敗<br>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ テストエラー: " . $e->getMessage() . "</p>";
    }
    
    // Step 3: 現在のトリガー状況確認
    echo "<h3>Step 3: 現在のトリガー状況</h3>";
    $stmt = $pdo->query("SHOW TRIGGERS LIKE 'ride_records'");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>ℹ️ ride_recordsに関連するトリガーはありません</p>";
        echo "<p>💡 必要に応じて後でトリガーを再作成できます</p>";
    } else {
        echo "<p>📋 現在のトリガー:</p>";
        foreach ($triggers as $trigger) {
            echo "<li>" . htmlspecialchars($trigger['Trigger']) . " (" . htmlspecialchars($trigger['Event']) . ")</li>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'>エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
