<?php
session_start();
header('Content-Type: application/json');

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'データベース接続エラー']);
    exit;
}

$today = date('Y-m-d');
$current_hour = date('H');
$user_id = $_SESSION['user_id'];
$alerts = [];

try {
    // 業務進捗チェック
    $flow_status = [];
    
    // 1. 日常点検
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['daily_inspection'] = $stmt->fetchColumn() > 0;
    
    // 2. 乗務前点呼
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND driver_id = ? AND is_completed = TRUE");
    $stmt->execute([$today, $user_id]);
    $flow_status['pre_duty_call'] = $stmt->fetchColumn() > 0;
    
    // 3. 出庫
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['departure'] = $stmt->fetchColumn() > 0;
    
    // 4. 乗車記録
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records WHERE ride_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['ride_records'] = $stmt->fetchColumn() > 0;
    
    // 5. 入庫
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['arrival'] = $stmt->fetchColumn() > 0;
    
    // 6. 乗務後点呼
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND driver_id = ? AND is_completed = TRUE");
    $stmt->execute([$today, $user_id]);
    $flow_status['post_duty_call'] = $stmt->fetchColumn() > 0;
    
    // 7. 売上金確認
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_management WHERE confirmation_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['cash_confirmation'] = $stmt->fetchColumn() > 0;
    
    // 業務フロー違反チェック
    if ($flow_status['ride_records'] && !$flow_status['daily_inspection']) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'message' => '日常点検を実施せずに乗車記録が登録されています。',
            'action' => 'daily_inspection.php',
            'action_text' => '日常点検を実施'
        ];
    }
    
    if ($flow_status['ride_records'] && !$flow_status['pre_duty_call']) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'message' => '乗務前点呼を実施せずに乗車記録が登録されています。',
            'action' => 'pre_duty_call.php',
            'action_text' => '乗務前点呼を実施'
        ];
    }
    
    if ($flow_status['ride_records'] && !$flow_status['departure']) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'message' => '出庫処理を実施せずに乗車記録が登録されています。',
            'action' => 'departure.php',
            'action_text' => '出庫処理を実施'
        ];
    }
    
    // 18時以降の終業チェック
    if ($current_hour >= 18) {
        if ($flow_status['departure'] && !$flow_status['arrival']) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-clock',
                'message' => '18時以降も入庫処理を完了していません。',
                'action' => 'arrival.php',
                'action_text' => '入庫処理を実施'
            ];
        }
        
        if ($flow_status['arrival'] && !$flow_status['post_duty_call']) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-user-clock',
                'message' => '入庫後、乗務後点呼を完了していません。',
                'action' => 'post_duty_call.php',
                'action_text' => '乗務後点呼を実施'
            ];
        }
        
        if ($flow_status['ride_records'] && !$flow_status['cash_confirmation']) {
            $alerts[] = [
                'type' => 'info',
                'priority' => 'medium',
                'icon' => 'fas fa-yen-sign',
                'message' => '本日の売上金確認がまだ完了していません。',
                'action' => 'cash_management.php',
                'action_text' => '売上金確認を実施'
            ];
        }
    }
    
    // アラートを優先度でソート
    usort($alerts, function($a, $b) {
        $priority_order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        return $priority_order[$a['priority']] - $priority_order[$b['priority']];
    });
    
    echo json_encode([
        'success' => true,
        'alerts' => $alerts,
        'flow_status' => $flow_status,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Dashboard alerts API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}
?> json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo
