<?php
session_start();
header('Content-Type: application/json');

// データベース接続
require_once '../config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '認証が必要です']);
    exit();
}

try {
    $pdo = getDBConnection();
    
    // POSTデータ取得
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['driver_id']) || !isset($input['date'])) {
        http_response_code(400);
        echo json_encode(['error' => '必要なパラメータが不足しています']);
        exit();
    }
    
    $driver_id = (int)$input['driver_id'];
    $date = $input['date'];
    
    // 日常点検完了確認（記録の存在をチェック）
    $daily_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM daily_inspections 
        WHERE driver_id = ? AND inspection_date = ?
    ");
    $daily_stmt->execute([$driver_id, $date]);
    $daily_completed = $daily_stmt->fetchColumn() > 0;

    // 乗務前点呼完了確認（is_completedカラム使用可能性を考慮）
    $preduty_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pre_duty_calls 
        WHERE driver_id = ? AND call_date = ?
        AND (is_completed = 1 OR is_completed IS NULL)
    ");
    $preduty_stmt->execute([$driver_id, $date]);
    $preduty_completed = $preduty_stmt->fetchColumn() > 0;

    echo json_encode([
        'daily_inspection' => $daily_completed,
        'pre_duty_call' => $preduty_completed,
        'can_proceed' => $daily_completed && $preduty_completed
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラーが発生しました']);
    error_log("Prerequisites API Error: " . $e->getMessage());
}
?>
