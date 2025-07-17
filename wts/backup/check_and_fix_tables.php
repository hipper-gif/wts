<?php
require_once 'config/database.php';

echo "<h2>福祉輸送管理システム - テーブル構造確認・修正</h2>";

try {
    $pdo = getDBConnection();
    
    echo "<h3>1. 既存テーブル確認</h3>";
    
    // 既存テーブル一覧
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
    echo "<h3>2. 必要テーブルの構造確認・作成</h3>";
    
    // departure_records テーブル確認・作成
    echo "<h4>departure_records テーブル</h4>";
    if (!in_array('departure_records', $tables)) {
        echo "<p class='text-warning'>departure_records テーブルが存在しません。作成します...</p>";
        
        $sql = "CREATE TABLE departure_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            driver_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            departure_date DATE NOT NULL,
            departure_time TIME NOT NULL,
            weather VARCHAR(20),
            departure_mileage INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (driver_id) REFERENCES users(id),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
            UNIQUE KEY unique_vehicle_date (vehicle_id, departure_date)
        )";
        
        $pdo->exec($sql);
        echo "<p class='text-success'>✅ departure_records テーブルを作成しました</p>";
    } else {
        echo "<p class='text-success'>✅ departure_records テーブルは既に存在します</p>";
    }
    
    // arrival_records テーブル確認・作成
    echo "<h4>arrival_records テーブル</h4>";
    if (!in_array('arrival_records', $tables)) {
        echo "<p class='text-warning'>arrival_records テーブルが存在しません。作成します...</p>";
        
        $sql = "CREATE TABLE arrival_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            departure_record_id INT,
            driver_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            arrival_date DATE NOT NULL,
            arrival_time TIME NOT NULL,
            arrival_mileage INT,
            total_distance INT,
            fuel_cost DECIMAL(8,2) DEFAULT 0.00,
            toll_cost DECIMAL(8,2) DEFAULT 0.00,
            other_cost DECIMAL(8,2) DEFAULT 0.00,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (departure_record_id) REFERENCES departure_records(id),
            FOREIGN KEY (driver_id) REFERENCES users(id),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
            UNIQUE KEY unique_vehicle_date (vehicle_id, arrival_date)
        )";
        
        $pdo->exec($sql);
        echo "<p class='text-success'>✅ arrival_records テーブルを作成しました</p>";
    } else {
        echo "<p class='text-success'>✅ arrival_records テーブルは既に存在します</p>";
    }
    
    // ride_records テーブル構造確認
    echo "<h4>ride_records テーブル構造確認</h4>";
    $stmt = $pdo->query("DESCRIBE ride_records");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $required_columns = ['driver_id', 'vehicle_id', 'ride_date'];
    $missing_columns = [];
    
    $existing_columns = array_column($columns, 'Field');
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $existing_columns)) {
            $missing_columns[] = $col;
        }
    }
    
    if (!empty($missing_columns)) {
        echo "<p class='text-warning'>ride_records テーブルに不足カラムがあります。追加します...</p>";
        
        foreach ($missing_columns as $col) {
            switch ($col) {
                case 'driver_id':
                    $pdo->exec("ALTER TABLE ride_records ADD COLUMN driver_id INT NOT NULL DEFAULT 1");
                    echo "<p>✅ driver_id カラムを追加しました</p>";
                    break;
                case 'vehicle_id':
                    $pdo->exec("ALTER TABLE ride_records ADD COLUMN vehicle_id INT NOT NULL DEFAULT 1");
                    echo "<p>✅ vehicle_id カラムを追加しました</p>";
                    break;
                case 'ride_date':
                    $pdo->exec("ALTER TABLE ride_records ADD COLUMN ride_date DATE NOT NULL DEFAULT CURDATE()");
                    echo "<p>✅ ride_date カラムを追加しました</p>";
                    break;
            }
        }
        
        // operation_id をNULL許可に変更
        $pdo->exec("ALTER TABLE ride_records MODIFY operation_id INT NULL");
        echo "<p>✅ operation_id をNULL許可に変更しました</p>";
    } else {
        echo "<p class='text-success'>✅ ride_records テーブル構造は正常です</p>";
    }
    
    // vehicles テーブルにcurrent_mileage確認
    echo "<h4>vehicles テーブル構造確認</h4>";
    $stmt = $pdo->query("DESCRIBE vehicles");
    $vehicle_columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    
    if (!in_array('current_mileage', $vehicle_columns)) {
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN current_mileage INT DEFAULT 0");
        echo "<p class='text-success'>✅ vehicles テーブルに current_mileage カラムを追加しました</p>";
    } else {
        echo "<p class='text-success'>✅ vehicles テーブルの current_mileage カラムは存在します</p>";
    }
    
    if (!in_array('is_active', $vehicle_columns)) {
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN is_active BOOLEAN DEFAULT TRUE");
        echo "<p class='text-success'>✅ vehicles テーブルに is_active カラムを追加しました</p>";
    } else {
        echo "<p class='text-success'>✅ vehicles テーブルの is_active カラムは存在します</p>";
    }
    
    // users テーブルにis_driver, is_caller, is_admin確認
    echo "<h4>users テーブル構造確認</h4>";
    $stmt = $pdo->query("DESCRIBE users");
    $user_columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    
    $user_required_columns = ['is_driver', 'is_caller', 'is_admin'];
    foreach ($user_required_columns as $col) {
        if (!in_array($col, $user_columns)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $col BOOLEAN DEFAULT FALSE");
            echo "<p class='text-success'>✅ users テーブルに $col カラムを追加しました</p>";
        }
    }
    
    echo "<h3>3. データ整合性チェック</h3>";
    
    // 既存の乗車記録にdriver_id, vehicle_idが設定されていない場合のデフォルト値設定
    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE driver_id = 0 OR vehicle_id = 0");
    $invalid_count = $stmt->fetchColumn();
    
    if ($invalid_count > 0) {
        echo "<p class='text-warning'>無効なdriver_id/vehicle_idを持つレコードが {$invalid_count} 件あります</p>";
        
        // デフォルト値で更新（最初のuser/vehicleを使用）
        $stmt = $pdo->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1");
        $default_user = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT id FROM vehicles WHERE is_active = 1 ORDER BY id LIMIT 1");
        $default_vehicle = $stmt->fetchColumn();
        
        if ($default_user && $default_vehicle) {
            $pdo->exec("UPDATE ride_records SET driver_id = $default_user WHERE driver_id = 0");
            $pdo->exec("UPDATE ride_records SET vehicle_id = $default_vehicle WHERE vehicle_id = 0");
            echo "<p class='text-success'>✅ デフォルト値を設定しました</p>";
        }
    } else {
        echo "<p class='text-success'>✅ データ整合性に問題ありません</p>";
    }
    
    echo "<h3>4. テーブル一覧（最新）</h3>";
    $stmt = $pdo->query("SHOW TABLES");
    $final_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<ul>";
    foreach ($final_tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "<li><strong>$table</strong> - {$count} レコード</li>";
    }
    echo "</ul>";
    
    echo "<h3>✅ テーブル構造の確認・修正が完了しました</h3>";
    echo "<p>出庫処理画面で前提条件チェックが正常に動作するはずです。</p>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>エラー: " . $e->getMessage() . "</div>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
.text-success { color: #28a745; }
.text-warning { color: #ffc107; }
.text-danger { color: #dc3545; }
.alert { padding: 10px; margin: 10px 0; border-radius: 5px; }
.alert-danger { background-color: #f8d7da; border: 1px solid #f5c6cb; }
</style>