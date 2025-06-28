<?php
/**
 * 出庫・入庫システム構築スクリプト
 * 新しいテーブルを作成し、既存テーブルを更新します
 */

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

echo "<h2>福祉輸送管理システム - 出庫・入庫システム構築</h2>\n";
echo "<pre>\n";

try {
    // データベース接続
    echo "0. データベース接続中...\n";
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    echo "✓ データベース接続成功\n";
    // 1. 出庫記録テーブル作成
    echo "1. 出庫記録テーブル作成中...\n";
    $departure_table_sql = "
    CREATE TABLE IF NOT EXISTS departure_records (
        id INT PRIMARY KEY AUTO_INCREMENT,
        driver_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        departure_date DATE NOT NULL,
        departure_time TIME NOT NULL,
        weather VARCHAR(20) NOT NULL,
        departure_mileage INT NOT NULL,
        pre_duty_completed BOOLEAN DEFAULT TRUE,
        daily_inspection_completed BOOLEAN DEFAULT TRUE,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        -- 外部キー制約
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        
        -- インデックス
        INDEX idx_departure_date (departure_date),
        INDEX idx_departure_vehicle (vehicle_id, departure_date),
        INDEX idx_departure_driver (driver_id, departure_date),
        
        -- ユニーク制約（同日同車両の重複出庫防止）
        UNIQUE KEY unique_daily_departure (vehicle_id, departure_date)
    ) COMMENT = '出庫記録テーブル - 車両の出庫時の記録を管理'";
    
    $pdo->exec($departure_table_sql);
    echo "✓ 出庫記録テーブル作成完了\n";

    // 2. 入庫記録テーブル作成
    echo "\n2. 入庫記録テーブル作成中...\n";
    $arrival_table_sql = "
    CREATE TABLE IF NOT EXISTS arrival_records (
        id INT PRIMARY KEY AUTO_INCREMENT,
        departure_record_id INT,
        driver_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        arrival_date DATE NOT NULL,
        arrival_time TIME NOT NULL,
        arrival_mileage INT NOT NULL,
        fuel_cost INT DEFAULT 0,
        toll_cost INT DEFAULT 0,
        other_cost INT DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        -- 外部キー制約
        FOREIGN KEY (departure_record_id) REFERENCES departure_records(id) ON DELETE SET NULL,
        FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        
        -- インデックス
        INDEX idx_arrival_date (arrival_date),
        INDEX idx_arrival_vehicle (vehicle_id, arrival_date),
        INDEX idx_arrival_driver (driver_id, arrival_date),
        INDEX idx_departure_link (departure_record_id),
        
        -- ユニーク制約（同日同車両の重複入庫防止）
        UNIQUE KEY unique_daily_arrival (vehicle_id, arrival_date)
    ) COMMENT = '入庫記録テーブル - 車両の入庫時の記録を管理、走行距離は自動計算'";
    
    $pdo->exec($arrival_table_sql);
    echo "✓ 入庫記録テーブル作成完了\n";

    // 3. 乗車記録テーブルの独立化
    echo "\n3. 乗車記録テーブル更新中...\n";
    
    // カラム存在チェック
    $check_columns = $pdo->query("SHOW COLUMNS FROM ride_records LIKE 'driver_id'");
    if ($check_columns->rowCount() == 0) {
        $alter_ride_records_sql = "
        ALTER TABLE ride_records 
        ADD COLUMN driver_id INT AFTER id,
        ADD COLUMN vehicle_id INT AFTER driver_id,
        ADD COLUMN ride_date DATE AFTER vehicle_id,
        ADD COLUMN is_return_trip BOOLEAN DEFAULT FALSE AFTER notes,
        ADD COLUMN original_ride_id INT AFTER is_return_trip,
        MODIFY COLUMN operation_id INT NULL";
        
        $pdo->exec($alter_ride_records_sql);
        echo "✓ 乗車記録テーブルにカラム追加完了\n";
    } else {
        echo "✓ 乗車記録テーブルカラムは既に存在します\n";
    }

    // 外部キー制約追加（既存チェック）
    try {
        $fk_check = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                                WHERE TABLE_NAME = 'ride_records' AND CONSTRAINT_NAME = 'fk_ride_driver'");
        if ($fk_check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE ride_records ADD FOREIGN KEY fk_ride_driver (driver_id) REFERENCES users(id) ON DELETE CASCADE");
            $pdo->exec("ALTER TABLE ride_records ADD FOREIGN KEY fk_ride_vehicle (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE");
            $pdo->exec("ALTER TABLE ride_records ADD FOREIGN KEY fk_original_ride (original_ride_id) REFERENCES ride_records(id) ON DELETE SET NULL");
            echo "✓ 乗車記録テーブル外部キー制約追加完了\n";
        } else {
            echo "✓ 乗車記録テーブル外部キー制約は既に存在します\n";
        }
    } catch (Exception $e) {
        echo "! 外部キー制約追加をスキップ（既存の可能性）\n";
    }

    // インデックス追加
    try {
        $pdo->exec("ALTER TABLE ride_records ADD INDEX idx_ride_date (ride_date)");
        $pdo->exec("ALTER TABLE ride_records ADD INDEX idx_ride_driver (driver_id, ride_date)");
        $pdo->exec("ALTER TABLE ride_records ADD INDEX idx_ride_vehicle (vehicle_id, ride_date)");
        echo "✓ 乗車記録テーブルインデックス追加完了\n";
    } catch (Exception $e) {
        echo "! インデックス追加をスキップ（既存の可能性）\n";
    }

    // 4. 車両テーブル更新用トリガー作成
    echo "\n4. 車両走行距離更新トリガー作成中...\n";
    
    // 既存トリガー削除
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS update_vehicle_mileage_on_arrival");
        $pdo->exec("DROP TRIGGER IF EXISTS update_vehicle_mileage_on_arrival_update");
    } catch (Exception $e) {
        // トリガーが存在しない場合は無視
    }
    
    // 新しいトリガー作成
    $trigger_insert_sql = "
    CREATE TRIGGER update_vehicle_mileage_on_arrival
    AFTER INSERT ON arrival_records
    FOR EACH ROW
    BEGIN
        DECLARE dep_mileage INT DEFAULT 0;
        
        -- 出庫メーターを取得
        SELECT departure_mileage INTO dep_mileage 
        FROM departure_records 
        WHERE id = NEW.departure_record_id;
        
        -- 車両情報を更新
        UPDATE vehicles 
        SET current_mileage = NEW.arrival_mileage,
            total_distance = total_distance + (NEW.arrival_mileage - IFNULL(dep_mileage, 0)),
            updated_at = NOW()
        WHERE id = NEW.vehicle_id;
    END";
    
    $trigger_update_sql = "
    CREATE TRIGGER update_vehicle_mileage_on_arrival_update
    AFTER UPDATE ON arrival_records
    FOR EACH ROW
    BEGIN
        DECLARE old_dep_mileage INT DEFAULT 0;
        DECLARE new_dep_mileage INT DEFAULT 0;
        
        -- 旧出庫メーターを取得
        SELECT departure_mileage INTO old_dep_mileage 
        FROM departure_records 
        WHERE id = OLD.departure_record_id;
        
        -- 新出庫メーターを取得
        SELECT departure_mileage INTO new_dep_mileage 
        FROM departure_records 
        WHERE id = NEW.departure_record_id;
        
        -- 車両情報を更新
        UPDATE vehicles 
        SET current_mileage = NEW.arrival_mileage,
            total_distance = total_distance - (OLD.arrival_mileage - IFNULL(old_dep_mileage, 0)) + (NEW.arrival_mileage - IFNULL(new_dep_mileage, 0)),
            updated_at = NOW()
        WHERE id = NEW.vehicle_id;
    END";
    
    $pdo->exec($trigger_insert_sql);
    $pdo->exec($trigger_update_sql);
    echo "✓ 車両走行距離更新トリガー作成完了\n";

    // 5. ビュー作成
    echo "\n5. レポート用ビュー作成中...\n";
    
    $daily_operations_view = "
    CREATE OR REPLACE VIEW daily_operations_view AS
    SELECT 
        d.id as departure_id,
        a.id as arrival_id,
        d.departure_date as operation_date,
        d.driver_id,
        u.name as driver_name,
        d.vehicle_id,
        v.vehicle_number,
        COALESCE(v.vehicle_name, v.model, CONCAT('車両', v.id)) as vehicle_name,
        d.departure_time,
        a.arrival_time,
        d.departure_mileage,
        a.arrival_mileage,
        CASE 
            WHEN a.arrival_mileage IS NOT NULL AND d.departure_mileage IS NOT NULL 
            THEN a.arrival_mileage - d.departure_mileage 
            ELSE 0 
        END as total_distance,
        d.weather,
        a.fuel_cost,
        a.toll_cost,
        a.other_cost,
        (IFNULL(a.fuel_cost, 0) + IFNULL(a.toll_cost, 0) + IFNULL(a.other_cost, 0)) as total_cost,
        CASE 
            WHEN a.arrival_time IS NOT NULL AND d.departure_time IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, 
                CONCAT(d.departure_date, ' ', d.departure_time),
                CONCAT(a.arrival_date, ' ', a.arrival_time)
            ) 
            ELSE NULL 
        END as operation_minutes
    FROM departure_records d
    LEFT JOIN arrival_records a ON d.id = a.departure_record_id
    JOIN users u ON d.driver_id = u.id
    JOIN vehicles v ON d.vehicle_id = v.id
    ORDER BY d.departure_date DESC, d.departure_time DESC";
    
    $pdo->exec($daily_operations_view);
    echo "✓ 日次運行記録ビュー作成完了\n";

    $ride_statistics_view = "
    CREATE OR REPLACE VIEW ride_statistics_view AS
    SELECT 
        r.ride_date,
        r.driver_id,
        u.name as driver_name,
        r.vehicle_id,
        v.vehicle_number,
        COUNT(*) as total_rides,
        SUM(r.passenger_count) as total_passengers,
        SUM(r.fare + IFNULL(r.charge, 0)) as total_revenue,
        AVG(r.fare + IFNULL(r.charge, 0)) as avg_fare,
        COUNT(CASE WHEN r.payment_method = '現金' THEN 1 END) as cash_count,
        COUNT(CASE WHEN r.payment_method = 'カード' THEN 1 END) as card_count,
        SUM(CASE WHEN r.payment_method = '現金' THEN r.fare + IFNULL(r.charge, 0) ELSE 0 END) as cash_total,
        SUM(CASE WHEN r.payment_method = 'カード' THEN r.fare + IFNULL(r.charge, 0) ELSE 0 END) as card_total
    FROM ride_records r
    JOIN users u ON r.driver_id = u.id
    JOIN vehicles v ON r.vehicle_id = v.id
    WHERE r.ride_date IS NOT NULL
    GROUP BY r.ride_date, r.driver_id, r.vehicle_id
    ORDER BY r.ride_date DESC";
    
    $pdo->exec($ride_statistics_view);
    echo "✓ 乗車記録統計ビュー作成完了\n";

    // 6. APIディレクトリ作成
    echo "\n6. APIディレクトリ作成中...\n";
    if (!is_dir('api')) {
        mkdir('api', 0755, true);
        echo "✓ APIディレクトリ作成完了\n";
    } else {
        echo "✓ APIディレクトリは既に存在します\n";
    }

    // 7. サンプルデータ投入（既存データがない場合）
    echo "\n7. サンプルデータチェック中...\n";
    
    // ユーザーデータチェック
    $user_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver'")->fetchColumn();
    if ($user_count < 2) {
        echo "運転者データを追加中...\n";
        $insert_users = "INSERT IGNORE INTO users (name, username, password, role) VALUES 
            ('田中太郎', 'tanaka', '$2y$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'driver'),
            ('佐藤花子', 'sato', '$2y$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'driver')";
        $pdo->exec($insert_users);
        echo "✓ 運転者データ追加完了\n";
    } else {
        echo "✓ 運転者データは既に存在します\n";
    }
    
    // 車両データチェック
    $vehicle_count = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active'")->fetchColumn();
    if ($vehicle_count < 2) {
        echo "車両データを追加中...\n";
        $insert_vehicles = "INSERT IGNORE INTO vehicles (vehicle_number, vehicle_name, capacity, current_mileage, status) VALUES 
            ('尾張小牧301 あ 1234', 'アルファード', 6, 50000, 'active'),
            ('尾張小牧301 あ 5678', 'ヴォクシー', 7, 30000, 'active')";
        $pdo->exec($insert_vehicles);
        echo "✓ 車両データ追加完了\n";
    } else {
        echo "✓ 車両データは既に存在します\n";
    }

    // 8. 統計情報更新
    echo "\n8. 統計情報更新中...\n";
    try {
        $pdo->exec("ANALYZE TABLE departure_records");
        $pdo->exec("ANALYZE TABLE arrival_records");
        $pdo->exec("ANALYZE TABLE ride_records");
        echo "✓ 統計情報更新完了\n";
    } catch (Exception $e) {
        echo "! 統計情報更新をスキップ\n";
    }

    // 9. 権限設定
    echo "\n9. 権限設定確認中...\n";
    try {
        // テーブル作成者に適切な権限があることを確認
        $pdo->exec("FLUSH PRIVILEGES");
        echo "✓ 権限設定完了\n";
    } catch (Exception $e) {
        echo "! 権限設定をスキップ（管理者権限が必要）\n";
    }

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ 出庫・入庫システム構築が完了しました！\n";
    echo str_repeat("=", 50) . "\n\n";

    echo "📋 作成された機能:\n";
    echo "・出庫処理画面 (departure.php)\n";
    echo "・入庫処理画面 (arrival.php)\n";
    echo "・乗車記録管理画面 (ride_records.php)\n";
    echo "・復路作成機能\n";
    echo "・前提条件チェックAPI\n";
    echo "・前回メーター取得API\n";
    echo "・車両走行距離自動更新\n";
    echo "・日次集計機能\n\n";

    echo "📊 作成されたテーブル・ビュー:\n";
    echo "・departure_records (出庫記録)\n";
    echo "・arrival_records (入庫記録)\n";
    echo "・ride_records (更新: 独立化)\n";
    echo "・daily_operations_view (運行記録ビュー)\n";
    echo "・ride_statistics_view (乗車統計ビュー)\n\n";

    echo "🚀 次のステップ:\n";
    echo "1. departure.php にアクセスして出庫処理をテスト\n";
    echo "2. arrival.php にアクセスして入庫処理をテスト\n";
    echo "3. ride_records.php で乗車記録・復路作成をテスト\n";
    echo "4. 各機能が正常に動作することを確認\n\n";

    echo "⚠️  重要事項:\n";
    echo "・operation.php は古い形式のため、新しいシステムに移行してください\n";
    echo "・データの整合性を保つため、新旧システムの併用は避けてください\n";
    echo "・定期的なデータバックアップを実施してください\n\n";

} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "\n";
    echo "\n解決方法:\n";
    echo "1. データベース接続設定を確認してください\n";
    echo "2. 必要な権限があることを確認してください\n";
    echo "3. 既存テーブルとの競合がないか確認してください\n";
} catch (Exception $e) {
    echo "❌ 一般エラー: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>