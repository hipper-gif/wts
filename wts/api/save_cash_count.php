<?php
header('Content-Type: application/json');
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    
    // JSONデータ取得
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('無効なJSONデータです');
    }
    
    // 必須フィールドチェック
    if (!isset($data['confirmation_date']) || !isset($data['driver_id'])) {
        throw new Exception('必須フィールドが不足しています');
    }
    
    // データベースに保存
    $stmt = $pdo->prepare("
        INSERT INTO cash_count_details (
            confirmation_date, driver_id, 
            bill_5000, bill_1000, coin_500, coin_100, coin_50, coin_10,
            total_amount, memo, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            bill_5000 = VALUES(bill_5000),
            bill_1000 = VALUES(bill_1000),
            coin_500 = VALUES(coin_500),
            coin_100 = VALUES(coin_100),
            coin_50 = VALUES(coin_50),
            coin_10 = VALUES(coin_10),
            total_amount = VALUES(total_amount),
            memo = VALUES(memo),
            updated_at = NOW()
    ");
    
    $stmt->execute([
        $data['confirmation_date'],
        $data['driver_id'],
        $data['bill_5000'] ?? 0,
        $data['bill_1000'] ?? 0,
        $data['coin_500'] ?? 0,
        $data['coin_100'] ?? 0,
        $data['coin_50'] ?? 0,
        $data['coin_10'] ?? 0,
        $data['total_amount'] ?? 0,
        $data['memo'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => '集金データを正常に保存しました',
        'data' => $data
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラー: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
