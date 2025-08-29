// 挿入
        $stmt = $pdo->prepare("
            INSERT INTO cash_count_details (
                confirmation_date, driver_id, 
                bill_10000, bill_5000, bill_1000, coin_500, coin_100, coin_50, coin_10,
                total_amount, memo, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");
        
        $result = $stmt->execute([
            $data['confirmation_date'],
            $data['driver_<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONSリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// エラー表示を有効化
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    
    // 既存データの確認と削除（UNIQUE制約対応）
    $check_stmt = $pdo->prepare("
        SELECT id FROM cash_count_details 
        WHERE confirmation_date = ? AND driver_id = ?
    ");
    $check_stmt->execute([$data['confirmation_date'], $data['driver_id']]);
    $existing = $check_stmt->fetch();
    
    if ($existing) {
        // 既存データを更新
        $stmt = $pdo->prepare("
            UPDATE cash_count_details SET
                bill_5000 = ?,
                bill_1000 = ?,
                coin_500 = ?,
                coin_100 = ?,
                coin_50 = ?,
                coin_10 = ?,
                total_amount = ?,
                memo = ?,
                updated_at = NOW()
            WHERE confirmation_date = ? AND driver_id = ?
        ");
        
        $stmt->execute([
            $data['bill_5000'] ?? 0,
            $data['bill_1000'] ?? 0,
            $data['coin_500'] ?? 0,
            $data['coin_100'] ?? 0,
            $data['coin_50'] ?? 0,
            $data['coin_10'] ?? 0,
            $data['total_amount'] ?? 0,
            $data['memo'] ?? '',
            $data['confirmation_date'],
            $data['driver_id']
        ]);
        
        $message = '集金データを更新しました';
    } else {
        // 新規データを挿入
        $stmt = $pdo->prepare("
            INSERT INTO cash_count_details (
                confirmation_date, driver_id, 
                bill_5000, bill_1000, coin_500, coin_100, coin_50, coin_10,
                total_amount, memo, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
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
        
        $message = '集金データを新規保存しました';
    }
    
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
