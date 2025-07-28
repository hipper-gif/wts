<?php
session_start();

echo "<h1>🔍 total_trips コード参照スキャナー</h1>";
echo "<p><strong>実行日時:</strong> " . date('Y-m-d H:i:s') . "</p>";

// スキャン対象ファイル
$files_to_scan = [
    'dashboard.php',
    'ride_records.php', 
    'user_management.php',
    'vehicle_management.php',
    'arrival.php',
    'departure.php',
    'pre_duty_call.php',
    'post_duty_call.php',
    'daily_inspection.php',
    'periodic_inspection.php',
    'operation.php',
    'index.php',
    'logout.php'
];

$total_trips_found = false;

echo "<h3>📋 total_trips 参照箇所の詳細検索</h3>";

foreach ($files_to_scan as $filename) {
    if (file_exists($filename)) {
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        $file_has_reference = false;
        
        echo "<h4>📄 ファイル: $filename</h4>";
        
        foreach ($lines as $line_number => $line) {
            if (stripos($line, 'total_trips') !== false) {
                if (!$file_has_reference) {
                    echo "<div style='background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0;'>";
                    $file_has_reference = true;
                    $total_trips_found = true;
                }
                
                $actual_line_number = $line_number + 1;
                echo "<p><strong>行 $actual_line_number:</strong> <code>" . htmlspecialchars(trim($line)) . "</code></p>";
            }
        }
        
        if ($file_has_reference) {
            echo "</div>";
        } else {
            echo "<p style='color: green;'>✅ total_trips の参照なし</p>";
        }
    } else {
        echo "<h4>📄 ファイル: $filename</h4>";
        echo "<p style='color: gray;'>📄 ファイルが存在しません</p>";
    }
}

if (!$total_trips_found) {
    echo "<div style='background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>✅ 結果: total_trips の参照は見つかりませんでした</h3>";
    echo "<p>コードレベルでは問題ないようです。データベース側または他の要因を確認してください。</p>";
    echo "</div>";
} else {
    echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>⚠️ 結果: total_trips の参照が見つかりました</h3>";
    echo "<p>上記の箇所を修正する必要があります。</p>";
    echo "</div>";
}

// 修正提案の表示
echo "<h3>🔧 修正提案</h3>";
echo "<div style='background-color: #e7f3ff; padding: 15px; border-left: 4px solid #007cba; margin: 20px 0;'>";

echo "<h4>パターン1: SELECT クエリの修正</h4>";
echo "<p><strong>修正前:</strong></p>";
echo "<pre><code>SELECT id, name, total_trips FROM users</code></pre>";
echo "<p><strong>修正後:</strong></p>";
echo "<pre><code>SELECT 
    id, 
    name, 
    (SELECT COUNT(*) FROM ride_records WHERE driver_id = users.id) as total_trips 
FROM users</code></pre>";

echo "<h4>パターン2: UPDATE クエリの削除</h4>";
echo "<p><strong>修正前:</strong></p>";
echo "<pre><code>UPDATE users SET total_trips = ? WHERE id = ?</code></pre>";
echo "<p><strong>修正後:</strong></p>";
echo "<pre><code>// total_trips カラムの更新をスキップ（動的計算のため不要）</code></pre>";

echo "<h4>パターン3: INSERT クエリの修正</h4>";
echo "<p><strong>修正前:</strong></p>";
echo "<pre><code>INSERT INTO users (name, role, total_trips) VALUES (?, ?, ?)</code></pre>";
echo "<p><strong>修正後:</strong></p>";
echo "<pre><code>INSERT INTO users (name, role) VALUES (?, ?)</code></pre>";

echo "</div>";

// エラーが発生している可能性のある処理を特定
echo "<h3>🚨 エラー発生の可能性が高い処理</h3>";
echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px;'>";
echo "<ol>";
echo "<li><strong>乗車記録新規登録時</strong> - users または vehicles テーブルの total_trips 更新処理</li>";
echo "<li><strong>ダッシュボード表示時</strong> - 統計情報取得での total_trips 参照</li>";
echo "<li><strong>ユーザー一覧表示時</strong> - users テーブルの total_trips カラム参照</li>";
echo "<li><strong>車両一覧表示時</strong> - vehicles テーブルの total_trips カラム参照</li>";
echo "</ol>";
echo "</div>";

// 即座に実行できる修正コードを提供
echo "<h3>⚡ 即座実行可能な修正コード</h3>";
echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px;'>";
echo "<p>以下のコードをコピーして該当ファイルに適用してください：</p>";

echo "<h4>✅ 安全な統計取得関数</h4>";
echo "<pre><code>";
echo htmlspecialchars('
// 安全な統計取得（total_trips カラム不要）
function getSafeUserStats($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.role,
            (SELECT COUNT(*) FROM ride_records WHERE driver_id = u.id) as total_trips,
            (SELECT SUM(fare) FROM ride_records WHERE driver_id = u.id) as total_revenue
        FROM users u 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 安全な車両統計取得
function getSafeVehicleStats($pdo, $vehicle_id) {
    $stmt = $pdo->prepare("
        SELECT 
            v.id,
            v.vehicle_number,
            (SELECT COUNT(*) FROM ride_records WHERE vehicle_id = v.id) as total_trips,
            (SELECT SUM(fare) FROM ride_records WHERE vehicle_id = v.id) as total_revenue
        FROM vehicles v 
        WHERE v.id = ?
    ");
    $stmt->execute([$vehicle_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 乗車記録登録（total_trips 更新なし）
function addRideRecord($pdo, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO ride_records (
            driver_id, vehicle_id, ride_date, ride_time,
            passenger_count, pickup_location, dropoff_location,
            fare, transportation_type, payment_method, remarks
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    return $stmt->execute([
        $data[\'driver_id\'], $data[\'vehicle_id\'], $data[\'ride_date\'], $data[\'ride_time\'],
        $data[\'passenger_count\'], $data[\'pickup_location\'], $data[\'dropoff_location\'],
        $data[\'fare\'], $data[\'transportation_type\'], $data[\'payment_method\'], $data[\'remarks\']
    ]);
}
');
echo "</code></pre>";
echo "</div>";

echo "<hr>";
echo "<p><strong>スキャン完了時刻:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><strong>次のステップ:</strong> 上記で見つかった参照箇所を修正してください。</p>";
?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background-color: #f5f5f5;
}

h1, h3, h4 {
    color: #333;
}

pre {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
    border: 1px solid #dee2e6;
}

code {
    font-family: 'Courier New', monospace;
    font-size: 14px;
}

ol, ul {
    padding-left: 20px;
}

li {
    margin: 5px 0;
}
</style>
