<?php
/**
 * 入庫記録テーブル構造確認・修正スクリプト
 */

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

echo "<h2>入庫記録テーブル構造確認・修正</h2>\n";
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

    // 1. arrival_recordsテーブルが存在するかチェック
    echo "1. arrival_recordsテーブル確認中...\n";
    $table_exists = $pdo->query("SHOW TABLES LIKE 'arrival_records'")->rowCount() > 0;
    
    if (!$table_exists) {
        echo "  ❌ arrival_recordsテーブルが存在しません\n";
        echo "  setup_departure_arrival_system.php を先に実行してください\n";
        exit();
    }
    echo "  ✓ arrival_recordsテーブルが存在します\n";

    // 2. 現在のテーブル構造を確認
    echo "\n2. arrival_recordsテーブル構造確認中...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM arrival_records")->fetchAll();
    
    echo "現在のカラム:\n";
    $existing_columns = [];
    foreach ($columns as $column) {
        echo "  - " . $column['Field'] . " (" . $column['Type'] . ")\n";
        $existing_columns[] = $column['Field'];
    }
    echo "\n";

    // 3. 必要なカラムをチェックし、存在しない場合は追加
    echo "3. 必要なカラムの確認・追加...\n";
    
    $required_columns = [
        'fuel_cost' => "INT DEFAULT 0 COMMENT '燃料代'",
        'toll_cost' => "INT DEFAULT 0 COMMENT '高速道路等料金'", 
        'other_cost' => "INT DEFAULT 0 COMMENT 'その他料金'",
        'notes' => "TEXT COMMENT '備考'"
    ];

    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            echo "  + カラム '{$column_name}' を追加中...\n";
            $alter_sql = "ALTER TABLE arrival_records ADD COLUMN {$column_name} {$column_definition}";
            $pdo->exec($alter_sql);
            echo "  ✓ カラム '{$column_name}' 追加完了\n";
        } else {
            echo "  ✓ カラム '{$column_name}' は既に存在します\n";
        }
    }

    // 4. arrival_dateカラムが存在しない場合は追加
    if (!in_array('arrival_date', $existing_columns)) {
        echo "  + カラム 'arrival_date' を追加中...\n";
        $alter_sql = "ALTER TABLE arrival_records ADD COLUMN arrival_date DATE NOT NULL AFTER vehicle_id";
        $pdo->exec($alter_sql);
        
        // 既存データがあれば、arrival_timeから日付を推定
        $update_sql = "UPDATE arrival_records SET arrival_date = CURDATE() WHERE arrival_date IS NULL";
        $pdo->exec($update_sql);
        echo "  ✓ カラム 'arrival_date' 追加完了\n";
    } else {
        echo "  ✓ カラム 'arrival_date' は既に存在します\n";
    }

    // 5. インデックスの追加
    echo "\n4. インデックス確認・追加中...\n";
    
    $indexes = [
        'idx_arrival_date' => 'arrival_date',
        'idx_arrival_vehicle_date' => 'vehicle_id, arrival_date',
        'idx_arrival_driver_date' => 'driver_id, arrival_date'
    ];

    foreach ($indexes as $index_name => $index_columns) {
        try {
            $check_index = $pdo->query("SHOW INDEX FROM arrival_records WHERE Key_name = '{$index_name}'")->rowCount();
            if ($check_index == 0) {
                $pdo->exec("ALTER TABLE arrival_records ADD INDEX {$index_name} ({$index_columns})");
                echo "  ✓ インデックス '{$index_name}' 追加完了\n";
            } else {
                echo "  ✓ インデックス '{$index_name}' は既に存在します\n";
            }
        } catch (Exception $e) {
            echo "  ! インデックス '{$index_name}' 追加をスキップ: " . $e->getMessage() . "\n";
        }
    }

    // 6. 更新後のテーブル構造を表示
    echo "\n5. 更新後のarrival_recordsテーブル構造:\n";
    $columns_after = $pdo->query("SHOW COLUMNS FROM arrival_records")->fetchAll();
    foreach ($columns_after as $column) {
        echo "  - " . $column['Field'] . " (" . $column['Type'] . ") " . 
             ($column['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . 
             ($column['Default'] !== null ? " DEFAULT '" . $column['Default'] . "'" : '') . "\n";
    }

    // 7. サンプルデータがあれば表示
    echo "\n6. 現在のarrival_recordsデータ:\n";
    $arrival_count = $pdo->query("SELECT COUNT(*) FROM arrival_records")->fetchColumn();
    if ($arrival_count > 0) {
        $arrivals = $pdo->query("SELECT * FROM arrival_records ORDER BY id LIMIT 5")->fetchAll();
        foreach ($arrivals as $arrival) {
            echo sprintf("  ID:%d 運転者ID:%d 車両ID:%d 日時:%s %s\n",
                $arrival['id'],
                $arrival['driver_id'],
                $arrival['vehicle_id'],
                $arrival['arrival_date'] ?? 'なし',
                $arrival['arrival_time']
            );
        }
        if ($arrival_count > 5) {
            echo "  ... 他 " . ($arrival_count - 5) . " 件\n";
        }
    } else {
        echo "  データなし（これは正常です）\n";
    }

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ arrival_recordsテーブル修正完了！\n";
    echo "setup_departure_arrival_system.php を再実行してください。\n";
    echo str_repeat("=", 50) . "\n";

} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "\n";
    echo "\n解決方法:\n";
    echo "1. データベース接続設定を確認してください\n";
    echo "2. arrival_records テーブルが存在することを確認してください\n";
    echo "3. 必要な権限があることを確認してください\n";
} catch (Exception $e) {
    echo "❌ 一般エラー: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #28a745; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>