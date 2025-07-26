<?php
session_start();

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

$today = date('Y-m-d');
$current_month_start = date('Y-m-01');

echo "<h1>🔍 ダッシュボード集計デバッグ</h1>";
echo "<p>今日: {$today}</p>";
echo "<p>今月開始: {$current_month_start}</p>";

echo "<hr>";

// 1. ride_recordsテーブルの構造確認
echo "<h2>1. ride_recordsテーブル構造</h2>";
try {
    $stmt = $pdo->query("DESCRIBE ride_records");
    $columns = $stmt->fetchAll();
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>カラム名</th><th>型</th><th>NULL</th><th>キー</th><th>デフォルト</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";

// 2. 全乗車記録の確認
echo "<h2>2. 全乗車記録データ</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_count FROM ride_records");
    $total_records = $stmt->fetchColumn();
    echo "<p><strong>総レコード数: {$total_records}</strong></p>";
    
    if ($total_records > 0) {
        $stmt = $pdo->query("
            SELECT ride_date, COUNT(*) as count, 
                   SUM(fare) as total_fare, 
                   SUM(charge) as total_charge,
                   SUM(fare + charge) as total_amount
            FROM ride_records 
            GROUP BY ride_date 
            ORDER BY ride_date DESC 
            LIMIT 10
        ");
        $daily_data = $stmt->fetchAll();
        
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>日付</th><th>件数</th><th>運賃合計</th><th>料金合計</th><th>売上合計</th></tr>";
        foreach ($daily_data as $row) {
            echo "<tr>";
            echo "<td>{$row['ride_date']}</td>";
            echo "<td>{$row['count']}</td>";
            echo "<td>¥" . number_format($row['total_fare']) . "</td>";
            echo "<td>¥" . number_format($row['total_charge']) . "</td>";
            echo "<td>¥" . number_format($row['total_amount']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";

// 3. 今日の集計
echo "<h2>3. 今日の集計 ({$today})</h2>";
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               COALESCE(SUM(fare), 0) as total_fare,
               COALESCE(SUM(charge), 0) as total_charge,
               COALESCE(SUM(fare + charge), 0) as revenue 
        FROM ride_records 
        WHERE ride_date = ?
    ");
    $stmt->execute([$today]);
    $today_result = $stmt->fetch();
    
    echo "<ul>";
    echo "<li>乗車件数: {$today_result['count']}</li>";
    echo "<li>運賃合計: ¥" . number_format($today_result['total_fare']) . "</li>";
    echo "<li>料金合計: ¥" . number_format($today_result['total_charge']) . "</li>";
    echo "<li><strong>今日の売上: ¥" . number_format($today_result['revenue']) . "</strong></li>";
    echo "</ul>";
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";

// 4. 今月の集計
echo "<h2>4. 今月の集計 ({$current_month_start}以降)</h2>";
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               COALESCE(SUM(fare), 0) as total_fare,
               COALESCE(SUM(charge), 0) as total_charge,
               COALESCE(SUM(fare + charge), 0) as revenue 
        FROM ride_records 
        WHERE ride_date >= ?
    ");
    $stmt->execute([$current_month_start]);
    $month_result = $stmt->fetch();
    
    // 経過日数計算
    $days_in_month = date('j'); // 今日の日付
    $month_avg_revenue = $days_in_month > 0 ? round($month_result['revenue'] / $days_in_month) : 0;
    
    echo "<ul>";
    echo "<li>乗車件数: {$month_result['count']}</li>";
    echo "<li>運賃合計: ¥" . number_format($month_result['total_fare']) . "</li>";
    echo "<li>料金合計: ¥" . number_format($month_result['total_charge']) . "</li>";
    echo "<li><strong>今月の売上: ¥" . number_format($month_result['revenue']) . "</strong></li>";
    echo "<li>経過日数: {$days_in_month}日</li>";
    echo "<li><strong>1日平均: ¥" . number_format($month_avg_revenue) . "</strong></li>";
    echo "</ul>";
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";

// 5. 月別集計
echo "<h2>5. 月別売上推移</h2>";
try {
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(ride_date, '%Y-%m') as month,
            COUNT(*) as count,
            SUM(fare + charge) as revenue
        FROM ride_records 
        GROUP BY DATE_FORMAT(ride_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $monthly_data = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>月</th><th>件数</th><th>売上</th></tr>";
    foreach ($monthly_data as $row) {
        echo "<tr>";
        echo "<td>{$row['month']}</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>¥" . number_format($row['revenue']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";

// 6. 最新の乗車記録サンプル
echo "<h2>6. 最新の乗車記録サンプル</h2>";
try {
    $stmt = $pdo->query("
        SELECT r.ride_date, r.ride_time, r.fare, r.charge, 
               (r.fare + r.charge) as total_amount,
               r.pickup_location, r.dropoff_location,
               u.name as driver_name, v.vehicle_number
        FROM ride_records r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.ride_date DESC, r.ride_time DESC
        LIMIT 5
    ");
    $sample_records = $stmt->fetchAll();
    
    if (!empty($sample_records)) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>日付</th><th>時刻</th><th>運転者</th><th>車両</th><th>乗車地</th><th>降車地</th><th>運賃</th><th>料金</th><th>合計</th></tr>";
        foreach ($sample_records as $record) {
            echo "<tr>";
            echo "<td>{$record['ride_date']}</td>";
            echo "<td>{$record['ride_time']}</td>";
            echo "<td>" . htmlspecialchars($record['driver_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($record['vehicle_number'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($record['pickup_location']) . "</td>";
            echo "<td>" . htmlspecialchars($record['dropoff_location']) . "</td>";
            echo "<td>¥" . number_format($record['fare']) . "</td>";
            echo "<td>¥" . number_format($record['charge']) . "</td>";
            echo "<td><strong>¥" . number_format($record['total_amount']) . "</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'><strong>乗車記録が1件もありません！</strong></p>";
    }
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";

// 7. データベースの問題診断
echo "<h2>7. データベース診断</h2>";

// カラムの存在確認
$required_columns = ['fare', 'charge', 'ride_date', 'driver_id', 'vehicle_id'];
$missing_columns = [];

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM ride_records");
    $existing_columns = array_column($stmt->fetchAll(), 'Field');
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $existing_columns)) {
            $missing_columns[] = $col;
        }
    }
    
    if (empty($missing_columns)) {
        echo "<p style='color: green;'>✅ 必要なカラムはすべて存在しています</p>";
    } else {
        echo "<p style='color: red;'>❌ 不足しているカラム: " . implode(', ', $missing_columns) . "</p>";
    }
    
    // NULL値の確認
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN fare IS NULL THEN 1 END) as null_fare,
            COUNT(CASE WHEN charge IS NULL THEN 1 END) as null_charge,
            COUNT(CASE WHEN ride_date IS NULL THEN 1 END) as null_date
        FROM ride_records
    ");
    $null_check = $stmt->fetch();
    
    echo "<h3>NULL値チェック</h3>";
    echo "<ul>";
    echo "<li>総レコード数: {$null_check['total']}</li>";
    echo "<li>fare がNULL: {$null_check['null_fare']}</li>";
    echo "<li>charge がNULL: {$null_check['null_charge']}</li>";
    echo "<li>ride_date がNULL: {$null_check['null_date']}</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";
echo "<h2>8. 結論と推奨アクション</h2>";

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records");
    $total_records = $stmt->fetchColumn();
    
    if ($total_records == 0) {
        echo "<p style='color: red; font-size: 18px;'><strong>❌ 問題発見: 乗車記録が1件もありません！</strong></p>";
        echo "<p>解決方法:</p>";
        echo "<ul>";
        echo "<li>1. <a href='ride_records.php'>乗車記録管理</a>で実際の乗車記録を入力してください</li>";
        echo "<li>2. テストデータを投入したい場合は、サンプルデータ作成スクリプトを実行してください</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: green;'>✅ 乗車記録は存在します ({$total_records}件)</p>";
        echo "<p>月間実績の計算ロジックは正常です。</p>";
        echo "<p>もし数字がおかしく見える場合は、期待値と実際の計算結果を比較してください。</p>";
    }
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}

echo "<hr>";
echo "<p><a href='dashboard.php'>元のダッシュボードに戻る</a></p>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { width: 100%; margin: 10px 0; }
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
th { background-color: #f2f2f2; }
hr { margin: 20px 0; }
h1 { color: #333; }
h2 { color: #666; }
</style>
