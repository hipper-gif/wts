<?php
/**
 * データベース構造確認・修正スクリプト
 * ride_recordsテーブルのtotal_trips問題を解消
 */

require_once 'config/database.php';

echo "<h2>🔧 データベース構造確認・修正スクリプト</h2>";

try {
    // 1. 現在のride_recordsテーブル構造を確認
    echo "<h3>1. 現在のride_recordsテーブル構造</h3>";
    $stmt = $pdo->query("DESCRIBE ride_records");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='margin: 10px 0;'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL可</th><th>キー</th><th>デフォルト値</th></tr>";
    
    $has_total_trips = false;
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
        
        if ($column['Field'] === 'total_trips') {
            $has_total_trips = true;
        }
    }
    echo "</table>";
    
    // 2. total_tripsカラムの存在確認
    echo "<h3>2. total_tripsカラムの状況</h3>";
    if ($has_total_trips) {
        echo "<p style='color: orange;'>⚠️ total_tripsカラムが存在します。</p>";
        echo "<p>このカラムは集計値なので削除を推奨します。</p>";
        
        // total_tripsカラムのデータを確認
        $stmt = $pdo->query("SELECT COUNT(*) as count, SUM(CASE WHEN total_trips IS NOT NULL THEN 1 ELSE 0 END) as non_null_count FROM ride_records");
        $data_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<p>総レコード数: {$data_info['count']}</p>";
        echo "<p>total_tripsに値があるレコード数: {$data_info['non_null_count']}</p>";
        
    } else {
        echo "<p style='color: green;'>✅ total_tripsカラムは存在しません（正常）</p>";
    }
    
    // 3. 推奨されるテーブル構造
    echo "<h3>3. 推奨されるride_recordsテーブル構造</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px;'>";
    echo "CREATE TABLE ride_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    driver_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    ride_date DATE NOT NULL,
    ride_time TIME NOT NULL,
    passenger_count INT DEFAULT 1,
    pickup_location VARCHAR(255) NOT NULL,
    dropoff_location VARCHAR(255) NOT NULL,
    fare DECIMAL(10,2) NOT NULL,
    payment_method ENUM('現金','カード','その他') DEFAULT '現金',
    transportation_type ENUM('通院','外出等','退院','転院','施設入所','その他') DEFAULT '通院',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_driver_date (driver_id, ride_date),
    INDEX idx_vehicle_date (vehicle_id, ride_date),
    INDEX idx_ride_date (ride_date),
    
    FOREIGN KEY (driver_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
);";
    echo "</pre>";
    
    // 4. 集計関数のサンプル
    echo "<h3>4. 集計値の動的計算例</h3>";
    
    // 今日の統計を計算
    $today = date('Y-m-d');
    
    $stats_query = "
        SELECT 
            COUNT(*) as total_trips,
            SUM(fare) as total_fare,
            SUM(passenger_count) as total_passengers,
            AVG(fare) as avg_fare,
            COUNT(DISTINCT driver_id) as active_drivers,
            COUNT(DISTINCT vehicle_id) as active_vehicles
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ";
    
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute([$today]);
    $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div style='background: #e8f5e8; padding: 15px; margin: 10px 0;'>";
    echo "<h4>📊 今日（{$today}）の実績</h4>";
    echo "<ul>";
    echo "<li><strong>総乗車回数:</strong> {$today_stats['total_trips']}回</li>";
    echo "<li><strong>総売上:</strong> ¥" . number_format($today_stats['total_fare']) . "</li>";
    echo "<li><strong>総乗客数:</strong> {$today_stats['total_passengers']}名</li>";
    echo "<li><strong>平均料金:</strong> ¥" . number_format($today_stats['avg_fare']) . "</li>";
    echo "<li><strong>稼働運転者数:</strong> {$today_stats['active_drivers']}名</li>";
    echo "<li><strong>稼働車両数:</strong> {$today_stats['active_vehicles']}台</li>";
    echo "</ul>";
    echo "</div>";
    
    // 5. 運転者別統計
    echo "<h3>5. 運転者別今日の実績</h3>";
    $driver_stats_query = "
        SELECT 
            u.name as driver_name,
            COUNT(*) as trip_count,
            SUM(r.fare) as total_fare,
            SUM(r.passenger_count) as total_passengers,
            AVG(r.fare) as avg_fare
        FROM ride_records r
        JOIN users u ON r.driver_id = u.id
        WHERE DATE(r.ride_date) = ?
        GROUP BY r.driver_id, u.name
        ORDER BY trip_count DESC
    ";
    
    $stmt = $pdo->prepare($driver_stats_query);
    $stmt->execute([$today]);
    $driver_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($driver_stats) > 0) {
        echo "<table border='1' style='margin: 10px 0;'>";
        echo "<tr><th>運転者</th><th>乗車回数</th><th>売上</th><th>乗客数</th><th>平均料金</th></tr>";
        foreach ($driver_stats as $stat) {
            echo "<tr>";
            echo "<td>{$stat['driver_name']}</td>";
            echo "<td>{$stat['trip_count']}回</td>";
            echo "<td>¥" . number_format($stat['total_fare']) . "</td>";
            echo "<td>{$stat['total_passengers']}名</td>";
            echo "<td>¥" . number_format($stat['avg_fare']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>今日の乗車記録はありません。</p>";
    }
    
    // 6. 修正推奨事項
    echo "<h3>6. 🎯 修正推奨事項</h3>";
    echo "<div style='background: #fff3cd; padding: 15px; margin: 10px 0; border-left: 4px solid #ffc107;'>";
    echo "<h4>immediate Actions（即座実行）:</h4>";
    echo "<ol>";
    echo "<li><strong>ride_records.phpの修正:</strong> INSERT文からtotal_tripsを除去</li>";
    echo "<li><strong>集計関数の実装:</strong> 動的計算による統計値取得</li>";
    echo "<li><strong>既存コードの点検:</strong> total_trips参照箇所の修正</li>";
    echo "</ol>";
    
    echo "<h4>Optional Actions（任意実行）:</h4>";
    echo "<ul>";
    if ($has_total_trips) {
        echo "<li>total_tripsカラムの削除（データ整合性確認後）</li>";
    }
    echo "<li>インデックスの最適化</li>";
    echo "<li>外部キー制約の追加</li>";
    echo "</ul>";
    echo "</div>";
    
    // 7. 修正用SQLスクリプト（オプション）
    if ($has_total_trips) {
        echo "<h3>7. 🗑️ total_tripsカラム削除スクリプト（注意して実行）</h3>";
        echo "<div style='background: #f8d7da; padding: 15px; margin: 10px 0; border-left: 4px solid #dc3545;'>";
        echo "<p><strong>⚠️ 警告:</strong> 以下のSQLは既存データに影響します。必ずバックアップを取ってから実行してください。</p>";
        echo "<pre>";
        echo "-- バックアップ作成\n";
        echo "CREATE TABLE ride_records_backup AS SELECT * FROM ride_records;\n\n";
        echo "-- total_tripsカラムを削除\n";
        echo "ALTER TABLE ride_records DROP COLUMN total_trips;\n\n";
        echo "-- 削除確認\n";
        echo "DESCRIBE ride_records;";
        echo "</pre>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>エラー: " . $e->getMessage() . "</p>";
}
?>

<style>
table {
    border-collapse: collapse;
    width: 100%;
}
th, td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
}
th {
    background-color: #f2f2f2;
}
</style>
