<?php
session_start();

// データベース接続
try {
    // config/database.phpが存在するかチェック
    if (file_exists('../config/database.php')) {
        require_once '../config/database.php';
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
    echo json_encode(['error' => 'Database connection failed']);
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
    
    // 最新の入庫記録から入庫メーターを取得
    $sql = "SELECT arrival_mileage, arrival_date 
            FROM arrival_records 
            WHERE vehicle_id = ? 
            ORDER BY arrival_date DESC, id DESC 
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$vehicle_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'previous_mileage' => (int)$result['arrival_mileage'],
            'date' => $result['arrival_date']
        ]);
    } else {
        // 入庫記録がない場合は、車両マスタの現在走行距離を取得
        $vehicle_sql = "SELECT current_mileage FROM vehicles WHERE id = ?";
        $vehicle_stmt = $pdo->prepare($vehicle_sql);
        $vehicle_stmt->execute([$vehicle_id]);
        $vehicle_result = $vehicle_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($vehicle_result && $vehicle_result['current_mileage'] > 0) {
            echo json_encode([
                'previous_mileage' => (int)$vehicle_result['current_mileage'],
                'date' => '初期値'
            ]);
        } else {
            echo json_encode([
                'previous_mileage' => null,
                'date' => null
            ]);
        }
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>