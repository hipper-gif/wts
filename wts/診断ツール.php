<?php
/**
 * データベース不整合診断ツール
 * 現在のDB接続状況とテーブル構造を完全診断
 */

// エラー表示を有効化
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 データベース不整合診断ツール</h1>";
echo "<div style='font-family: Arial; background: #f5f5f5; padding: 20px;'>";

// 1. 設定ファイルの確認
echo "<h2>1. 📄 設定ファイル確認</h2>";
$config_files = [
    'config/database.php',
    'database.php',
    'config.php',
    'db_config.php'
];

foreach ($config_files as $file) {
    if (file_exists($file)) {
        echo "<div style='background: #d4edda; padding: 10px; margin: 5px;'>";
        echo "<strong>✅ 存在: {$file}</strong><br>";
        
        $content = file_get_contents($file);
        if (strpos($content, 'twinklemark_wts') !== false) {
            echo "DB名: twinklemark_wts を確認<br>";
        }
        if (strpos($content, 'twinklemark_taxi') !== false) {
            echo "ユーザー: twinklemark_taxi を確認<br>";
        }
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 10px; margin: 5px;'>";
        echo "<strong>❌ 不存在: {$file}</strong>";
        echo "</div>";
    }
}

// 2. 現在の接続テスト
echo "<h2>2. 🔌 現在のDB接続テスト</h2>";

// 標準的な接続情報
$connections = [
    [
        'name' => '標準接続(twinklemark_wts)',
        'host' => 'localhost',
        'dbname' => 'twinklemark_wts',
        'username' => 'twinklemark_taxi',
        'password' => 'Smiley2525'
    ],
    [
        'name' => '代替接続(smiley)',
        'host' => 'localhost',
        'dbname' => 'smiley',
        'username' => 'twinklemark_taxi',
        'password' => 'Smiley2525'
    ],
    [
        'name' => '代替接続2(twinklemark)',
        'host' => 'localhost',
        'dbname' => 'twinklemark',
        'username' => 'twinklemark_taxi',
        'password' => 'Smiley2525'
    ]
];

$successful_connection = null;

foreach ($connections as $conn) {
    try {
        $pdo = new PDO(
            "mysql:host={$conn['host']};dbname={$conn['dbname']};charset=utf8mb4",
            $conn['username'],
            $conn['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        echo "<div style='background: #d4edda; padding: 10px; margin: 5px;'>";
        echo "<strong>✅ 接続成功: {$conn['name']}</strong><br>";
        
        // テーブル数確認
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "テーブル数: " . count($tables) . "<br>";
        echo "テーブル: " . implode(', ', array_slice($tables, 0, 10));
        if (count($tables) > 10) echo "...";
        echo "</div>";
        
        if (!$successful_connection) {
            $successful_connection = $pdo;
            $successful_db = $conn['dbname'];
        }
        
    } catch (PDOException $e) {
        echo "<div style='background: #f8d7da; padding: 10px; margin: 5px;'>";
        echo "<strong>❌ 接続失敗: {$conn['name']}</strong><br>";
        echo "エラー: " . $e->getMessage();
        echo "</div>";
    }
}

// 3. 重要テーブルの存在確認
if ($successful_connection) {
    echo "<h2>3. 🗃️ 重要テーブル存在確認 (DB: {$successful_db})</h2>";
    
    $required_tables = [
        'users' => 'ユーザー管理',
        'vehicles' => '車両管理',
        'pre_duty_calls' => '乗務前点呼',
        'post_duty_calls' => '乗務後点呼',
        'daily_inspections' => '日常点検',
        'periodic_inspections' => '定期点検',
        'departure_records' => '出庫記録',
        'arrival_records' => '入庫記録',
        'ride_records' => '乗車記録',
        'system_settings' => 'システム設定'
    ];
    
    foreach ($required_tables as $table => $description) {
        try {
            $stmt = $successful_connection->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            echo "<div style='background: #d4edda; padding: 5px; margin: 2px;'>";
            echo "✅ {$table} ({$description}): {$count}件";
            echo "</div>";
        } catch (PDOException $e) {
            echo "<div style='background: #f8d7da; padding: 5px; margin: 2px;'>";
            echo "❌ {$table} ({$description}): 存在しない";
            echo "</div>";
        }
    }
    
    // 4. セッション確認
    echo "<h2>4. 🔐 セッション状況確認</h2>";
    session_start();
    
    if (isset($_SESSION['user_id'])) {
        echo "<div style='background: #d4edda; padding: 10px;'>";
        echo "✅ セッション有効<br>";
        echo "ユーザーID: " . $_SESSION['user_id'] . "<br>";
        
        if (isset($_SESSION['role'])) {
            echo "権限: " . $_SESSION['role'] . "<br>";
        } else {
            echo "⚠️ 権限情報なし<br>";
        }
        
        // ユーザー情報を確認
        try {
            $stmt = $successful_connection->prepare("SELECT name, role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                echo "DB上の名前: " . $user['name'] . "<br>";
                echo "DB上の権限: " . $user['role'] . "<br>";
                
                if (isset($_SESSION['role']) && $_SESSION['role'] !== $user['role']) {
                    echo "<strong style='color: red;'>⚠️ セッション権限とDB権限が不一致！</strong><br>";
                }
            }
        } catch (Exception $e) {
            echo "ユーザー情報取得エラー: " . $e->getMessage();
        }
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 10px;'>";
        echo "⚠️ セッションなし（未ログイン）";
        echo "</div>";
    }
    
    // 5. 最近のデータ確認
    echo "<h2>5. 📊 最近のデータ確認</h2>";
    
    try {
        // 最新の乗車記録
        $stmt = $successful_connection->query("SELECT COUNT(*) FROM ride_records WHERE DATE(created_at) = CURDATE()");
        $today_rides = $stmt->fetchColumn();
        echo "<div style='background: #e7f1ff; padding: 5px; margin: 2px;'>";
        echo "今日の乗車記録: {$today_rides}件";
        echo "</div>";
        
        // 最新の出庫記録
        $stmt = $successful_connection->query("SELECT COUNT(*) FROM departure_records WHERE departure_date = CURDATE()");
        $today_departures = $stmt->fetchColumn();
        echo "<div style='background: #e7f1ff; padding: 5px; margin: 2px;'>";
        echo "今日の出庫記録: {$today_departures}件";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 5px;'>";
        echo "データ確認エラー: " . $e->getMessage();
        echo "</div>";
    }
}

// 6. 推奨アクション
echo "<h2>6. 🛠️ 推奨アクション</h2>";
echo "<div style='background: #fff3cd; padding: 15px;'>";
echo "<h3>即座に実行すべき修正:</h3>";
echo "<ol>";
echo "<li><strong>統一DB設定ファイル作成</strong> - 全ファイルが同じDB設定を使用</li>";
echo "<li><strong>セッション権限修正</strong> - ログアウト→再ログインでセッションリセット</li>";
echo "<li><strong>cash_management.php の設定確認</strong> - 他ファイルと同じDB接続を使用</li>";
echo "<li><strong>ダッシュボード修正</strong> - 正しいDB接続とテーブル参照</li>";
echo "</ol>";
echo "</div>";

echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h1, h2, h3 { color: #333; }
</style>
