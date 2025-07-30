<?php
// デバッグモードを有効にしてエラー詳細を取得
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 SQL エラー詳細デバッグ</h2>";

session_start();
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    echo "✅ データベース接続成功<br>";
    
    // ride_recordsテーブルへの基本的なINSERT テスト
    echo "<h3>📝 INSERT テスト</h3>";
    
    $test_sql = "INSERT INTO ride_records 
        (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
         pickup_location, dropoff_location, fare, charge, transport_category, 
         payment_method, notes, is_return_trip, original_ride_id) 
        VALUES (1, 1, '2025-07-30', '10:00', 1, 'テスト乗車地', 'テスト降車地', 
                1000, 0, '通院', '現金', 'テスト', 0, NULL)";
    
    echo "実行SQL: " . htmlspecialchars($test_sql) . "<br>";
    
    $stmt = $pdo->prepare($test_sql);
    $result = $stmt->execute();
    
    if ($result) {
        echo "✅ INSERT テスト成功<br>";
        
        // 挿入したテストデータを削除
        $last_id = $pdo->lastInsertId();
        $pdo->prepare("DELETE FROM ride_records WHERE id = ?")->execute([$last_id]);
        echo "🗑️ テストデータ削除完了<br>";
    }
    
} catch (PDOException $e) {
    echo "<h3>❌ SQL エラー詳細:</h3>";
    echo "<p><strong>エラーコード:</strong> " . $e->getCode() . "</p>";
    echo "<p><strong>エラーメッセージ:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>エラー発生ファイル:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>エラー発生行:</strong> " . $e->getLine() . "</p>";
    echo "<p><strong>スタックトレース:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// 現在のride_recordsテーブルの構造を再確認
try {
    echo "<h3>📋 現在のテーブル構造:</h3>";
    $stmt = $pdo->query("DESCRIBE ride_records");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "テーブル構造確認エラー: " . $e->getMessage();
}
?>
