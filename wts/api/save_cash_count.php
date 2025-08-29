<?php
// api/save_cash_count.php
// 集金データ保存API
// 作成日: 2025年8月28日

header('Content-Type: application/json; charset=utf-8');
session_start();

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/cash_functions.php';

// POSTデータのみ受付
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドのみ対応']);
    exit;
}

try {
    // JSONデータを取得
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('無効なJSONデータです');
    }
    
    // 必須データの検証（実テーブル対応版）
    $required_fields = ['confirmation_date', 'driver_id', 'total_amount'];
    
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("必須項目が不足しています: {$field}");
        }
    }
    
    // データ準備（実テーブル構造対応）
    $save_data = [
        'confirmation_date' => $input['confirmation_date'],
        'driver_id' => $input['driver_id'],
        'bill_10000' => (int)($input['bill_10000'] ?? 0),
        'bill_5000' => (int)($input['bill_5000'] ?? 0),
        'bill_2000' => (int)($input['bill_2000'] ?? 0),
        'bill_1000' => (int)($input['bill_1000'] ?? 0),
        'coin_500' => (int)($input['coin_500'] ?? 0),
        'coin_100' => (int)($input['coin_100'] ?? 0),
        'coin_50' => (int)($input['coin_50'] ?? 0),
        'coin_10' => (int)($input['coin_10'] ?? 0),
        'coin_5' => (int)($input['coin_5'] ?? 0),
        'coin_1' => (int)($input['coin_1'] ?? 0),
        'total_amount' => (int)$input['total_amount'],
        'memo' => $input['memo'] ?? ''
    ];
    
    // データベース保存（実テーブル構造対応）
    $pdo->beginTransaction();
    
    try {
        // 既存データの削除（同日・同運転者）
        $stmt = $pdo->prepare("
            DELETE FROM cash_count_details 
            WHERE confirmation_date = ? AND driver_id = ?
        ");
        $stmt->execute([$save_data['confirmation_date'], $save_data['driver_id']]);
        
        // 新規データ挿入（実テーブル構造対応）
        $stmt = $pdo->prepare("
            INSERT INTO cash_count_details (
                confirmation_date, driver_id,
                bill_10000, bill_5000, bill_2000, bill_1000,
                coin_500, coin_100, coin_50, coin_10, coin_5, coin_1,
                total_amount, memo
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        
        $stmt->execute([
            $save_data['confirmation_date'],
            $save_data['driver_id'],
            $save_data['bill_10000'],
            $save_data['bill_5000'],
            $save_data['bill_2000'],
            $save_data['bill_1000'],
            $save_data['coin_500'],
            $save_data['coin_100'],
            $save_data['coin_50'],
            $save_data['coin_10'],
            $save_data['coin_5'],
            $save_data['coin_1'],
            $save_data['total_amount'],
            $save_data['memo']
        ]);
        
        $cash_count_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // 差額計算（保存後に実行）
        $base_change = 18000;
        $deposit_amount = $save_data['total_amount'] - $base_change;
        $system_cash_amount = $input['system_cash_amount'] ?? 0;
        $expected_total = $base_change + $system_cash_amount;
        $actual_difference = $save_data['total_amount'] - $expected_total;
        
        // 成功レスポンス
        echo json_encode([
            'success' => true,
            'message' => '集金データを保存しました',
            'data' => [
                'id' => $cash_count_id,
                'total_amount' => $save_data['total_amount'],
                'deposit_amount' => $deposit_amount,
                'difference' => $actual_difference,
                'saved_at' => date('Y-m-d H:i:s')
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception('データベース保存エラー: ' . $e->getMessage());
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'SAVE_ERROR'
    ]);
}
?>
