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
    
    if (!$data || !isset($data['driver_id']) || !isset($data['vehicle_id']) || !isset($data['date'])) {
        throw new Exception('Invalid input data');
    }
    
    $driver_id = $data['driver_id'];
    $vehicle_id = $data['vehicle_id'];
    $date = $data['date'];
    
    // 乗務前点呼完了チェック（複数条件で確認）
    $pre_duty_sql = "SELECT COUNT(*) as count, 
                            MAX(call_time) as latest_time,
                            MAX(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) as completed_flag
                     FROM pre_duty_calls 
                     WHERE driver_id = ? AND call_date = ?";
    $pre_duty_stmt = $pdo->prepare($pre_duty_sql);
    $pre_duty_stmt->execute([$driver_id, $date]);
    $pre_duty_result = $pre_duty_stmt->fetch();
    $pre_duty_completed = ($pre_duty_result['count'] > 0);
    
    // 日常点検完了チェック
    $inspection_sql = "SELECT COUNT(*) as count,
                              MAX(created_at) as latest_time
                       FROM daily_inspections 
                       WHERE vehicle_id = ? AND inspection_date = ?";
    $inspection_stmt = $pdo->prepare($inspection_sql);
    $inspection_stmt->execute([$vehicle_id, $date]);
    $inspection_result = $inspection_stmt->fetch();
    $inspection_completed = ($inspection_result['count'] > 0);
    
    // 既存の出庫記録チェック
    $departure_sql = "SELECT COUNT(*) as count,
                             MAX(departure_time) as latest_time
                      FROM departure_records 
                      WHERE vehicle_id = ? AND departure_date = ?";
    $departure_stmt = $pdo->prepare($departure_sql);
    $departure_stmt->execute([$vehicle_id, $date]);
    $departure_result = $departure_stmt->fetch();
    $already_departed = ($departure_result['count'] > 0);
    
    // 運転者情報取得
    $driver_sql = "SELECT name FROM users WHERE id = ?";
    $driver_stmt = $pdo->prepare($driver_sql);
    $driver_stmt->execute([$driver_id]);
    $driver_info = $driver_stmt->fetch();
    
    // 車両情報取得
    $vehicle_sql = "SELECT vehicle_number, vehicle_name FROM vehicles WHERE id = ?";
    $vehicle_stmt = $pdo->prepare($vehicle_sql);
    $vehicle_stmt->execute([$vehicle_id]);
    $vehicle_info = $vehicle_stmt->fetch();
    
    // レスポンス
    echo json_encode([
        'success' => true,
        'pre_duty_completed' => $pre_duty_completed,
        'inspection_completed' => $inspection_completed,
        'already_departed' => $already_departed,
        'can_depart' => $pre_duty_completed && $inspection_completed && !$already_departed,
        'details' => [
            'pre_duty_count' => $pre_duty_result['count'],
            'pre_duty_latest' => $pre_duty_result['latest_time'],
            'inspection_count' => $inspection_result['count'],
            'inspection_latest' => $inspection_result['latest_time'],
            'departure_count' => $departure_result['count'],
            'departure_latest' => $departure_result['latest_time'],
        ],
        'driver_name' => $driver_info['name'] ?? '不明',
        'vehicle_info' => $vehicle_info['vehicle_number'] ?? '不明',
        'check_date' => $date
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'pre_duty_completed' => false,
        'inspection_completed' => false,
        'already_departed' => false,
        'can_depart' => false
    ]);
}
?>