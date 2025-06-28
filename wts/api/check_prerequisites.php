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
    
    if (!$data || !isset($data['driver_id']) || !isset($data['vehicle_id']) || !isset($data['date'])) {
        throw new Exception('Invalid input data');
    }
    
    $driver_id = $data['driver_id'];
    $vehicle_id = $data['vehicle_id'];
    $date = $data['date'];
    
    // 乗務前点呼完了チェック
    $pre_duty_sql = "SELECT COUNT(*) FROM pre_duty_calls WHERE driver_id = ? AND call_date = ?";
    $pre_duty_stmt = $pdo->prepare($pre_duty_sql);
    $pre_duty_stmt->execute([$driver_id, $date]);
    $pre_duty_completed = $pre_duty_stmt->fetchColumn() > 0;
    
    // 日常点検完了チェック
    $inspection_sql = "SELECT COUNT(*) FROM daily_inspections WHERE vehicle_id = ? AND inspection_date = ?";
    $inspection_stmt = $pdo->prepare($inspection_sql);
    $inspection_stmt->execute([$vehicle_id, $date]);
    $inspection_completed = $inspection_stmt->fetchColumn() > 0;
    
    // 既存の出庫記録チェック
    $departure_sql = "SELECT COUNT(*) FROM departure_records WHERE vehicle_id = ? AND departure_date = ?";
    $departure_stmt = $pdo->prepare($departure_sql);
    $departure_stmt->execute([$vehicle_id, $date]);
    $already_departed = $departure_stmt->fetchColumn() > 0;
    
    // レスポンス
    echo json_encode([
        'pre_duty_completed' => $pre_duty_completed,
        'inspection_completed' => $inspection_completed,
        'already_departed' => $already_departed,
        'can_depart' => $pre_duty_completed && $inspection_completed && !$already_departed
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>