<?php
/**
 * 乗車記録テーブル構造確認・修正スクリプト
 */

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

echo "<h2>乗車記録テーブル構造確認・修正</h2>\n";
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
    echo "✓ データベース接続成功\n\n";

    // 1. ride_recordsテーブルが存在するかチェック
    echo "1. ride_recordsテーブル確認中...\n";
    $table_exists = $pdo->query("SHOW TABLES LIKE 'ride_records'")->rowCount() > 0;
    
    if (!$table_exists) {
        echo "  ❌ ride_recordsテーブルが存在しません\n";
        echo "  先にテーブルを作成してください\n";
        exit();
    }
    echo "  ✓ ride_recordsテーブルが存在します\n";

    // 2. 現在のテーブル構造を確認
    echo "\n2. ride_recordsテーブル構造確認中...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM ride_records")->fetchAll();
    
    echo "現在のカラム:\n";
    $existing_columns = [];
    foreach ($columns as $column) {
        echo "  - " . $column['Field'] . " (" . $column['Type'] . ")\n";
        $existing_columns[] = $column['Field'];
    }
    echo "\n";

    // 3. 必要なカラムをチェックし、存在しない場合は追加
    echo "3. 必要なカラムの確認・追加...\n";
    
    // 標準的な乗車記録に必要なカラム
    $required_columns = [
        'driver_id' => "INT COMMENT '運転者ID'",
        'vehicle_id' => "INT COMMENT '車両ID'", 
        'ride_date' => "DATE COMMENT '乗車日'",
        'ride_time' => "TIME COMMENT '乗車時刻'",
        'passenger_count' => "INT DEFAULT 1 COMMENT '人員数'",
        'pickup_location' => "VARCHAR(200) COMMENT '乗車地'",
        'dropoff_location' => "VARCHAR(200) COMMENT '降車地'",
        'fare' => "INT DEFAULT 0 COMMENT '運賃'",
        'charge' => "INT DEFAULT 0 COMMENT '料金'",
        'transport_category' => "VARCHAR(50) COMMENT '輸送分類'",
        'payment_method' => "VARCHAR(20) DEFAULT '現金' COMMENT '支払方法'",
        'notes' => "TEXT COMMENT '備考'",
        'is_return_trip' => "BOOLEAN DEFAULT FALSE COMMENT '復路フラグ'",
        'original_ride_id' => "INT COMMENT '元乗車記録ID'",
        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];

    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            echo "  + カラム '{$column_name}' を追加中...\n";
            try {
                $alter_sql = "ALTER TABLE ride_records ADD COLUMN {$column_name} {$column_definition}";
                $pdo->exec($alter_sql);
                echo "  ✓ カラム '{$column_name}' 追加完了\n";
            } catch (Exception $e) {
                echo "  ! カラム '{$column_name}' 追加エラー: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  ✓ カラム '{$column_name}' は既に存在します\n";
        }
    }

    // 4. operation_idをNULL許可に変更（独立化のため）
    if (in_array('operation_id', $existing_columns)) {
        echo "\n4. operation_idカラムをNULL許可に変更中...\n";
        try {
            $pdo->exec("ALTER TABLE ride_records MODIFY operation_id INT NULL");
            echo "  ✓ operation_idをNULL許可に変更完了\n";
        } catch (Exception $e) {
            echo "  ! operation_id変更エラー: " . $e->getMessage() . "\n";
        }
    }

    // 5. 外部キー制約の確認・追加
    echo "\n5. 外部キー制約確認・追加中...\n";
    
    $foreign_keys = [
        'fk_ride_driver' => "FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE",
        'fk_ride_vehicle' => "FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE", 
        'fk_original_ride' => "FOREIGN KEY (original_ride_id) REFERENCES ride_records(id) ON DELETE SET NULL"
    ];

    foreach ($foreign_keys as $fk_name => $fk_definition) {
        try {
            $check_fk = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                                    WHERE TABLE_NAME = 'ride_records' AND CONSTRAINT_NAME = '{$fk_name}'")->rowCount();
            if ($check_fk == 0) {
                $pdo->exec("ALTER TABLE ride_records ADD CONSTRAINT {$fk_name} {$fk_definition}");
                echo "  ✓ 外部キー '{$fk_name}' 追加完了\n";
            } else {
                echo "  ✓ 外部キー '{$fk_name}' は既に存在します\n";
            }
        } catch (Exception $e) {
            echo "  ! 外部キー '{$fk_name}' 追加をスキップ: " . $e->getMessage() . "\n";
        }
    }

    // 6. インデックスの追加
    echo "\n6. インデックス確認・追加中...\n";
    
    $indexes = [
        'idx_ride_date' => 'ride_date',
        'idx_ride_driver_date' => 'driver_id, ride_date',
        'idx_ride_vehicle_date' => 'vehicle_id, ride_date',
        'idx_ride_time' => 'ride_time'
    ];

    foreach ($indexes as $index_name => $index_columns) {
        try {
            $check_index = $pdo->query("SHOW INDEX FROM ride_records WHERE Key_name = '{$index_name}'")->rowCount();
            if ($check_index == 0) {
                $pdo->exec("ALTER TABLE ride_records ADD INDEX {$index_name} ({$index_columns})");
                echo "  ✓ インデックス '{$index_name}' 追加完了\n";
            } else {
                echo "  ✓ インデックス '{$index_name}' は既に存在します\n";
            }
        } catch (Exception $e) {
            echo "  ! インデックス '{$index_name}' 追加をスキップ: " . $e->getMessage() . "\n";
        }
    }

    // 7. 既存データの移行（必要に応じて）
    echo "\n7. 既存データの確認・移行...\n";
    
    // ride_dateが空の場合、作成日から推定
    if (in_array('created_at', $existing_columns)) {
        $update_date = $pdo->exec("
            UPDATE ride_records 
            SET ride_date = DATE(created_at) 
            WHERE ride_date IS NULL AND created_at IS NOT NULL
        ");
        if ($update_date > 0) {
            echo "  ✓ ride_dateを {$update_date} 件更新しました\n";
        } else {
            echo "  ✓ ride_dateの更新は不要でした\n";
        }
    }

    // passenger_countが0または空の場合、1に設定
    $update_passenger = $pdo->exec("
        UPDATE ride_records 
        SET passenger_count = 1 
        WHERE passenger_count IS NULL OR passenger_count = 0
    ");
    if ($update_passenger > 0) {
        echo "  ✓ passenger_countを {$update_passenger} 件更新しました\n";
    }

    // chargeがNULLの場合、0に設定
    if (in_array('charge', $existing_columns)) {
        $update_charge = $pdo->exec("
            UPDATE ride_records 
            SET charge = 0 
            WHERE charge IS NULL
        ");
        if ($update_charge > 0) {
            echo "  ✓ chargeを {$update_charge} 件更新しました\n";
        }
    }

    // 8. 更新後のテーブル構造を表示
    echo "\n8. 更新後のride_recordsテーブル構造:\n";
    $columns_after = $pdo->query("SHOW COLUMNS FROM ride_records")->fetchAll();
    foreach ($columns_after as $column) {
        echo "  - " . $column['Field'] . " (" . $column['Type'] . ") " . 
             ($column['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . 
             ($column['Default'] !== null ? " DEFAULT '" . $column['Default'] . "'" : '') . "\n";
    }

    // 9. サンプルデータがあれば表示
    echo "\n9. 現在のride_recordsデータ:\n";
    $ride_count = $pdo->query("SELECT COUNT(*) FROM ride_records")->fetchColumn();
    if ($ride_count > 0) {
        $rides = $pdo->query("SELECT * FROM ride_records ORDER BY id LIMIT 3")->fetchAll();
        foreach ($rides as $ride) {
            echo sprintf("  ID:%d 運転者ID:%s 車両ID:%s 日付:%s 運賃:%s\n",
                $ride['id'],
                $ride['driver_id'] ?? 'なし',
                $ride['vehicle_id'] ?? 'なし', 
                $ride['ride_date'] ?? 'なし',
                $ride['fare'] ?? 'なし'
            );
        }
        if ($ride_count > 3) {
            echo "  ... 他 " . ($ride_count - 3) . " 件\n";
        }
    } else {
        echo "  データなし\n";
    }

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ ride_recordsテーブル修正完了！\n";
    echo "setup_departure_arrival_system.php を再実行してください。\n";
    echo str_repeat("=", 50) . "\n";

} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "\n";
    echo "\n解決方法:\n";
    echo "1. データベース接続設定を確認してください\n";
    echo "2. ride_records テーブルが存在することを確認してください\n";
    echo "3. 必要な権限があることを確認してください\n";
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