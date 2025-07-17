<?php
session_start();

// データベース接続
try {
    // config/database.phpが存在するかチェック
    if (file_exists('config/database.php')) {
        require_once 'config/database.php';
        $pdo = getDBConnection();
    } else {
        // 直接データベース接続
        define('DB_HOST', 'localhost');
        define('DB_NAME', 'twinklemark_wts');
        define('DB_USER', 'twinklemark_taxi');
        define('DB_PASS', 'Smiley2525');
        define('DB_CHARSET', 'utf8mb4');
        
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Content-Type設定
header('Content-Type: application/json');

try {
    // JSONデータを取得
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['vehicle_id'])) {
        throw new Exception('Invalid input data');
    }
    
    $vehicle_id = $data['vehicle_id'];
    
    // 1. 最新の入庫記録から入庫メーターを取得
    $arrival_sql = "SELECT arrival_mileage, arrival_date 
                    FROM arrival_records 
                    WHERE vehicle_id = ? 
                    ORDER BY arrival_date DESC, id DESC 
                    LIMIT 1";
    $arrival_stmt = $pdo->prepare($arrival_sql);
    $arrival_stmt->execute([$vehicle_id]);
    $arrival_result = $arrival_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. 最新の出庫記録も確認
    $departure_sql = "SELECT departure_mileage, departure_date 
                      FROM departure_records 
                      WHERE vehicle_id = ? 
                      ORDER BY departure_date DESC, id DESC 
                      LIMIT 1";
    $departure_stmt = $pdo->prepare($departure_sql);
    $departure_stmt->execute([$vehicle_id]);
    $departure_result = $departure_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. 車両マスタの現在走行距離も取得
    $vehicle_sql = "SELECT current_mileage, vehicle_number, vehicle_name 
                    FROM vehicles 
                    WHERE id = ? AND is_active = 1";
    $vehicle_stmt = $pdo->prepare($vehicle_sql);
    $vehicle_stmt->execute([$vehicle_id]);
    $vehicle_result = $vehicle_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vehicle_result) {
        throw new Exception('Vehicle not found');
    }
    
    // 最新の記録を判定
    $latest_mileage = null;
    $latest_date = null;
    $record_source = 'master';
    
    if ($arrival_result && $departure_result) {
        // 両方存在する場合、より新しい日付を採用
        if ($arrival_result['arrival_date'] >= $departure_result['departure_date']) {
            $latest_mileage = $arrival_result['arrival_mileage'];
            $latest_date = $arrival_result['arrival_date'];
            $record_source = 'arrival';
        } else {
            $latest_mileage = $departure_result['departure_mileage'];
            $latest_date = $departure_result['departure_date'];
            $record_source = 'departure';
        }
    } elseif ($arrival_result) {
        $latest_mileage = $arrival_result['arrival_mileage'];
        $latest_date = $arrival_result['arrival_date'];
        $record_source = 'arrival';
    } elseif ($departure_result) {
        $latest_mileage = $departure_result['departure_mileage'];
        $latest_date = $departure_result['departure_date'];
        $record_source = 'departure';
    }
    
    // 車両マスタの値も比較して最新を確保
    if (!$latest_mileage || $vehicle_result['current_mileage'] > $latest_mileage) {
        $latest_mileage = $vehicle_result['current_mileage'];
        $latest_date = '車両マスタ';
        $record_source = 'master';
    }
    
    if ($latest_mileage && $latest_mileage > 0) {
        echo json_encode([
            'success' => true,
            'previous_mileage' => (int)$latest_mileage,
            'date' => $latest_date,
            'source' => $record_source,
            'vehicle_number' => $vehicle_result['vehicle_number'],
            'vehicle_name' => $vehicle_result['vehicle_name'],
            'formatted_mileage' => number_format($latest_mileage)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'previous_mileage' => null,
            'date' => null,
            'message' => '走行距離データがありません',
            'vehicle_number' => $vehicle_result['vehicle_number'],
            'vehicle_name' => $vehicle_result['vehicle_name']
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>