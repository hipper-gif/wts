<?php
// 新しいテーブル構造確認スクリプト

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

echo "<h2>新システム テーブル構造確認</h2>\n";
echo "<pre>\n";

try {
    // データベース接続
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    echo "✓ データベース接続成功\n\n";
    
    // 1. 全テーブル一覧確認
    echo "1. 現在のテーブル一覧:\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "  - {$table}\n";
    }
    echo "\n";
    
    // 2. 新システム用テーブルの存在確認
    echo "2. 新システム用テーブル確認:\n";
    $new_tables = ['departure_records', 'arrival_records'];
    foreach ($new_tables as $table) {
        $exists = in_array($table, $tables);
        echo "  - {$table}: " . ($exists ? "✓ 存在" : "❌ 未作成") . "\n";
        
        if ($exists) {
            // テーブル構造確認
            $columns = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll();
            echo "    カラム: ";
            $col_names = array_column($columns, 'Field');
            echo implode(', ', $col_names) . "\n";
        }
    }
    echo "\n";
    
    // 3. ride_recordsテーブルの更新確認
    echo "3. ride_recordsテーブル更新確認:\n";
    if (in_array('ride_records', $tables)) {
        $columns = $pdo->query("SHOW COLUMNS FROM ride_records")->fetchAll();
        $col_names = array_column($columns, 'Field');
        
        $required_new_columns = ['driver_id', 'vehicle_id', 'ride_date', 'is_return_trip', 'original_ride_id', 'charge'];
        foreach ($required_new_columns as $col) {
            $exists = in_array($col, $col_names);
            echo "  - {$col}: " . ($exists ? "✓ 存在" : "❌ 未追加") . "\n";
        }
        
        echo "  全カラム: " . implode(', ', $col_names) . "\n";
    } else {
        echo "  ❌ ride_recordsテーブルが存在しません\n";
    }
    echo "\n";
    
    // 4. vehiclesテーブルの更新確認
    echo "4. vehiclesテーブル更新確認:\n";
    if (in_array('vehicles', $tables)) {
        $columns = $pdo->query("SHOW COLUMNS FROM vehicles")->fetchAll();
        $col_names = array_column($columns, 'Field');
        
        $required_columns = ['vehicle_name', 'status', 'current_mileage'];
        foreach ($required_columns as $col) {
            $exists = in_array($col, $col_names);
            echo "  - {$col}: " . ($exists ? "✓ 存在" : "❌ 未追加") . "\n";
        }
        
        echo "  全カラム: " . implode(', ', $col_names) . "\n";
        
        // 車両データ確認
        $vehicle_data = $pdo->query("SELECT id, vehicle_number, COALESCE(vehicle_name, model, 'なし') as name, COALESCE(status, is_active) as status FROM vehicles LIMIT 3")->fetchAll();
        echo "  サンプルデータ:\n";
        foreach ($vehicle_data as $vehicle) {
            echo "    ID:{$vehicle['id']} {$vehicle['vehicle_number']} - {$vehicle['name']} (ステータス:{$vehicle['status']})\n";
        }
    } else {
        echo "  ❌ vehiclesテーブルが存在しません\n";
    }
    echo "\n";
    
    // 5. usersテーブル確認
    echo "5. usersテーブル確認:\n";
    if (in_array('users', $tables)) {
        $user_data = $pdo->query("SELECT id, name, role, is_active FROM users WHERE is_active = 1 LIMIT 5")->fetchAll();
        echo "  アクティブユーザー:\n";
        foreach ($user_data as $user) {
            echo "    ID:{$user['id']} {$user['name']} ({$user['role']})\n";
        }
    } else {
        echo "  ❌ usersテーブルが存在しません\n";
    }
    echo "\n";
    
    // 6. 必要なテーブル作成状況まとめ
    echo "6. システム構築状況まとめ:\n";
    $departure_exists = in_array('departure_records', $tables);
    $arrival_exists = in_array('arrival_records', $tables);
    $vehicles_updated = in_array('vehicles', $tables);
    $users_exists = in_array('users', $tables);
    
    echo "  基本テーブル: " . ($users_exists && $vehicles_updated ? "✓ OK" : "❌ 要修正") . "\n";
    echo "  出庫システム: " . ($departure_exists ? "✓ OK" : "❌ 要作成") . "\n";
    echo "  入庫システム: " . ($arrival_exists ? "✓ OK" : "❌ 要作成") . "\n";
    
    if (!$departure_exists || !$arrival_exists) {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "⚠️  新システムテーブルが未作成です。\n";
        echo "setup_departure_arrival_system.php を実行してください。\n";
        echo str_repeat("=", 50) . "\n";
    } else {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "✅ 新システムテーブルは作成済みです。\n";
        echo "departure.php, arrival.php, ride_records.php をテストできます。\n";
        echo str_repeat("=", 50) . "\n";
    }
    
} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "\n";
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

<hr>
<h3>次のアクション</h3>
<p><a href="dashboard.php" class="btn btn-primary">ダッシュボードへ</a></p>
<p><a href="setup_departure_arrival_system.php" class="btn btn-warning">新システム構築</a></p>