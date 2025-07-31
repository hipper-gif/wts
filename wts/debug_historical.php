<?php
// debug_historical.php - デバッグ用簡易版
session_start();

// エラー表示を有効化
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>デバッグ: 過去データ入力機能</h1>";

// Step 1: データベース接続テスト
echo "<h2>Step 1: データベース接続テスト</h2>";
try {
    require_once 'config/database.php';
    echo "✅ データベース接続: OK<br>";
} catch (Exception $e) {
    echo "❌ データベース接続エラー: " . $e->getMessage() . "<br>";
    exit;
}

// Step 2: 共通関数読み込みテスト
echo "<h2>Step 2: 共通関数読み込みテスト</h2>";
try {
    require_once 'includes/historical_common.php';
    echo "✅ 共通関数読み込み: OK<br>";
} catch (Exception $e) {
    echo "❌ 共通関数読み込みエラー: " . $e->getMessage() . "<br>";
    exit;
}

// Step 3: テーブル構造確認
echo "<h2>Step 3: テーブル構造確認</h2>";
try {
    // usersテーブル
    $stmt = $pdo->query("DESCRIBE users");
    $user_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ usersテーブルのカラム: " . implode(', ', $user_columns) . "<br>";
    
    // vehiclesテーブル
    $stmt = $pdo->query("DESCRIBE vehicles");
    $vehicle_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ vehiclesテーブルのカラム: " . implode(', ', $vehicle_columns) . "<br>";
    
    // daily_inspectionsテーブル
    $stmt = $pdo->query("DESCRIBE daily_inspections");
    $inspection_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ daily_inspectionsテーブルのカラム: " . implode(', ', $inspection_columns) . "<br>";
    
} catch (Exception $e) {
    echo "❌ テーブル構造確認エラー: " . $e->getMessage() . "<br>";
}

// Step 4: データ取得テスト
echo "<h2>Step 4: データ取得テスト</h2>";
try {
    $users = getUsersByRole($pdo, 'driver');
    echo "✅ ユーザー取得: " . count($users) . "件<br>";
    foreach ($users as $user) {
        echo "　- ID: {$user['id']}, 名前: {$user['name']}<br>";
    }
    
    $vehicles = getVehicles($pdo);
    echo "✅ 車両取得: " . count($vehicles) . "件<br>";
    foreach ($vehicles as $vehicle) {
        echo "　- ID: {$vehicle['id']}, ";
        if (isset($vehicle['vehicle_number'])) {
            echo "車両番号: {$vehicle['vehicle_number']}, ";
        }
        if (isset($vehicle['model'])) {
            echo "車種: {$vehicle['model']}";
        }
        echo "<br>";
    }
    
} catch (Exception $e) {
    echo "❌ データ取得エラー: " . $e->getMessage() . "<br>";
}

// Step 5: 日付生成テスト
echo "<h2>Step 5: 日付生成テスト</h2>";
try {
    $start_date = '2024-01-01';
    $end_date = '2024-01-07';
    $business_dates = generateBusinessDates($start_date, $end_date);
    echo "✅ 営業日生成: " . count($business_dates) . "日<br>";
    echo "　- " . implode(', ', $business_dates) . "<br>";
    
} catch (Exception $e) {
    echo "❌ 日付生成エラー: " . $e->getMessage() . "<br>";
}

// Step 6: 既存データ確認テスト
echo "<h2>Step 6: 既存データ確認テスト</h2>";
try {
    if (!empty($vehicles)) {
        $vehicle_id = $vehicles[0]['id'];
        $existing_dates = checkExistingData($pdo, 'daily_inspections', 'inspection_date', 
            $business_dates, ['vehicle_id' => $vehicle_id]);
        echo "✅ 既存データ確認: " . count($existing_dates) . "件<br>";
        if (!empty($existing_dates)) {
            echo "　- " . implode(', ', $existing_dates) . "<br>";
        }
    }
} catch (Exception $e) {
    echo "❌ 既存データ確認エラー: " . $e->getMessage() . "<br>";
}

echo "<h2>✅ デバッグ完了</h2>";
echo "<p>すべてのテストが通過した場合、過去データ入力機能の基本機能は正常です。</p>";

// 簡易入力フォーム
if (!empty($users) && !empty($vehicles)) {
    echo "<h2>Step 7: 簡易入力フォーム</h2>";
    echo '<form method="POST" style="border: 1px solid #ccc; padding: 20px; margin: 20px 0;">';
    echo '<h3>過去データ入力テスト</h3>';
    
    echo '<label>開始日:</label><br>';
    echo '<input type="date" name="start_date" value="2024-01-01"><br><br>';
    
    echo '<label>終了日:</label><br>';
    echo '<input type="date" name="end_date" value="2024-01-07"><br><br>';
    
    echo '<label>車両:</label><br>';
    echo '<select name="vehicle_id">';
    foreach ($vehicles as $vehicle) {
        $display_name = "ID: {$vehicle['id']}";
        if (isset($vehicle['vehicle_number'])) {
            $display_name = $vehicle['vehicle_number'];
        }
        echo "<option value=\"{$vehicle['id']}\">{$display_name}</option>";
    }
    echo '</select><br><br>';
    
    echo '<label>点検者:</label><br>';
    echo '<select name="inspector_id">';
    foreach ($users as $user) {
        echo "<option value=\"{$user['id']}\">{$user['name']}</option>";
    }
    echo '</select><br><br>';
    
    echo '<input type="submit" name="test_generate" value="テストデータ生成">';
    echo '</form>';
    
    // フォーム処理
    if (isset($_POST['test_generate'])) {
        echo "<h3>テストデータ生成結果</h3>";
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $vehicle_id = $_POST['vehicle_id'];
        $inspector_id = $_POST['inspector_id'];
        
        echo "期間: {$start_date} ～ {$end_date}<br>";
        echo "車両ID: {$vehicle_id}<br>";
        echo "点検者ID: {$inspector_id}<br>";
        
        $business_dates = generateBusinessDates($start_date, $end_date);
        $existing_dates = checkExistingData($pdo, 'daily_inspections', 'inspection_date', 
            $business_dates, ['vehicle_id' => $vehicle_id]);
        $date_categories = categorizeDates($business_dates, $existing_dates);
        
        echo "<br><strong>生成対象日:</strong><br>";
        foreach ($date_categories['missing'] as $date) {
            echo "　- {$date} (" . formatDateJapanese($date) . ")<br>";
        }
        
        if (!empty($date_categories['existing'])) {
            echo "<br><strong>既存データ (スキップ):</strong><br>";
            foreach ($date_categories['existing'] as $date) {
                echo "　- {$date}<br>";
            }
        }
    }
}
?>
