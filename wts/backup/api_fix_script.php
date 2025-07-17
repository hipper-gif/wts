<?php
// APIパス問題修正スクリプト
// departure.php と arrival.php のJavaScript内のパス問題を修正

echo "<h2>APIパス問題修正スクリプト</h2>";

$files_to_fix = [
    'departure.php',
    'arrival.php'
];

foreach ($files_to_fix as $filename) {
    if (file_exists($filename)) {
        echo "<h3>{$filename} を修正中...</h3>";
        
        $content = file_get_contents($filename);
        
        // 相対パスを絶対パスに修正
        $search_patterns = [
            "fetch('api/check_prerequisites.php'" => "fetch('/Smiley/taxi/wts/api/check_prerequisites.php'",
            "fetch('api/get_previous_mileage.php'" => "fetch('/Smiley/taxi/wts/api/get_previous_mileage.php'",
            'fetch("api/check_prerequisites.php"' => 'fetch("/Smiley/taxi/wts/api/check_prerequisites.php"',
            'fetch("api/get_previous_mileage.php"' => 'fetch("/Smiley/taxi/wts/api/get_previous_mileage.php"'
        ];
        
        $changes_made = 0;
        foreach ($search_patterns as $search => $replace) {
            if (strpos($content, $search) !== false) {
                $content = str_replace($search, $replace, $content);
                $changes_made++;
                echo "<p>✓ 修正: {$search} → {$replace}</p>";
            }
        }
        
        if ($changes_made > 0) {
            // バックアップを作成
            $backup_filename = $filename . '.backup.' . date('Y-m-d-H-i-s');
            copy($filename, $backup_filename);
            echo "<p>📁 バックアップ作成: {$backup_filename}</p>";
            
            // 修正版を保存
            file_put_contents($filename, $content);
            echo "<p>💾 修正版保存完了</p>";
        } else {
            echo "<p>ℹ️ 修正が必要な箇所が見つかりませんでした</p>";
        }
        
        echo "<hr>";
    } else {
        echo "<p>⚠️ {$filename} が見つかりません</p>";
    }
}

echo "<h3>APIディレクトリの確認</h3>";
if (!is_dir('api')) {
    echo "<p>📁 APIディレクトリを作成します...</p>";
    mkdir('api', 0755, true);
    echo "<p>✓ APIディレクトリ作成完了</p>";
} else {
    echo "<p>✓ APIディレクトリは既に存在します</p>";
}

// 必要なAPIファイルを作成
$api_files = [
    'api/check_prerequisites.php' => '<?php
// 前提条件チェックAPI
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "認証が必要です"]);
    exit;
}

require_once "../config/database.php";

$driver_id = $_GET["driver_id"] ?? null;
$vehicle_id = $_GET["vehicle_id"] ?? null;
$date = $_GET["date"] ?? date("Y-m-d");

if (!$driver_id || !$vehicle_id) {
    http_response_code(400);
    echo json_encode(["error" => "必須パラメータが不足しています"]);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // 乗務前点呼チェック
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? AND is_completed = TRUE");
    $stmt->execute([$driver_id, $date]);
    $pre_duty_completed = $stmt->fetchColumn() > 0;
    
    // 日常点検チェック
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE vehicle_id = ? AND inspection_date = ?");
    $stmt->execute([$vehicle_id, $date]);
    $daily_inspection_completed = $stmt->fetchColumn() > 0;
    
    echo json_encode([
        "pre_duty_completed" => $pre_duty_completed,
        "daily_inspection_completed" => $daily_inspection_completed,
        "can_proceed" => $pre_duty_completed && $daily_inspection_completed
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "データベースエラー"]);
}
?>',

    'api/get_previous_mileage.php' => '<?php
// 前回走行距離取得API
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "認証が必要です"]);
    exit;
}

require_once "../config/database.php";

$vehicle_id = $_GET["vehicle_id"] ?? null;

if (!$vehicle_id) {
    http_response_code(400);
    echo json_encode(["error" => "車両IDが必要です"]);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // 車両の現在走行距離を取得
    $stmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    $current_mileage = $stmt->fetchColumn();
    
    // 最新の入庫記録から走行距離を取得（より正確）
    $stmt = $pdo->prepare("
        SELECT arrival_mileage 
        FROM arrival_records 
        WHERE vehicle_id = ? 
        ORDER BY arrival_date DESC, arrival_time DESC 
        LIMIT 1
    ");
    $stmt->execute([$vehicle_id]);
    $latest_mileage = $stmt->fetchColumn();
    
    $mileage = $latest_mileage ?: $current_mileage ?: 0;
    
    echo json_encode([
        "mileage" => (int)$mileage,
        "current_mileage" => (int)$current_mileage,
        "latest_mileage" => (int)$latest_mileage
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "データベースエラー"]);
}
?>'
];

foreach ($api_files as $filepath => $content) {
    if (!file_exists($filepath)) {
        echo "<p>📄 作成中: {$filepath}</p>";
        file_put_contents($filepath, $content);
        echo "<p>✓ 作成完了</p>";
    } else {
        echo "<p>ℹ️ {$filepath} は既に存在します</p>";
    }
}

echo "<h3>修正完了</h3>";
echo "<p>✅ APIパス問題の修正が完了しました</p>";
echo "<p>🔄 ブラウザを更新して動作確認してください</p>";
?>

<style>
body { 
    font-family: Arial, sans-serif; 
    margin: 20px; 
    background-color: #f8f9fa;
}
h2, h3 { 
    color: #333; 
    border-bottom: 2px solid #007bff;
    padding-bottom: 5px;
}
p { 
    margin: 5px 0; 
    padding: 5px;
    background: white;
    border-left: 4px solid #007bff;
    border-radius: 4px;
}
hr { 
    border: none; 
    border-top: 1px solid #ddd; 
    margin: 20px 0; 
}
</style>