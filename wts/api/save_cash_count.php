<?php
// save_cash_count.php - 売上金保存API

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '認証が必要です'
    ]);
    exit;
}

// データベース設定ファイル読み込み
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/session_check.php';

// CSRF検証
validateCsrfToken();

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'POSTメソッドのみ許可されています'
    ]);
    exit;
}

try {
    // データベース接続
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('データベース接続に失敗しました');
    }

    // JSONデータ取得・検証
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception('入力データが空です');
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSONパースエラー: ' . json_last_error_msg());
    }

    // 必須フィールドの検証
    $required_fields = ['confirmation_date'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("必須フィールドが不足: {$field}");
        }
    }

    // データを安全に取得（デフォルト値設定）
    $confirmation_date = $data['confirmation_date'];
    $driver_id = $_SESSION['user_id'];
    $bill_10000 = (int)($data['bill_10000'] ?? 0);
    $bill_5000 = (int)($data['bill_5000'] ?? 0);
    $bill_1000 = (int)($data['bill_1000'] ?? 0);
    $coin_500 = (int)($data['coin_500'] ?? 0);
    $coin_100 = (int)($data['coin_100'] ?? 0);
    $coin_50 = (int)($data['coin_50'] ?? 0);
    $coin_10 = (int)($data['coin_10'] ?? 0);
    $total_amount = (int)($data['total_amount'] ?? 0);
    $memo = $data['memo'] ?? '';

    // トランザクション開始
    $pdo->beginTransaction();

    // 既存データの確認
    $check_stmt = $pdo->prepare("
        SELECT id FROM cash_count_details
        WHERE confirmation_date = ? AND driver_id = ?
    ");
    $check_stmt->execute([$confirmation_date, $driver_id]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // 既存データを更新
        $update_stmt = $pdo->prepare("
            UPDATE cash_count_details SET
                bill_10000 = ?,
                bill_5000 = ?,
                bill_2000 = 0,
                bill_1000 = ?,
                coin_500 = ?,
                coin_100 = ?,
                coin_50 = ?,
                coin_10 = ?,
                coin_5 = 0,
                coin_1 = 0,
                total_amount = ?,
                memo = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE confirmation_date = ? AND driver_id = ?
        ");

        $result = $update_stmt->execute([
            $bill_10000, $bill_5000, $bill_1000, $coin_500, $coin_100,
            $coin_50, $coin_10, $total_amount, $memo,
            $confirmation_date, $driver_id
        ]);

        $message = 'データを正常に更新しました';
        $record_id = $existing['id'];

    } else {
        // 新規データを挿入
        $insert_stmt = $pdo->prepare("
            INSERT INTO cash_count_details (
                confirmation_date, driver_id,
                bill_10000, bill_5000, bill_2000, bill_1000,
                coin_500, coin_100, coin_50, coin_10, coin_5, coin_1,
                total_amount, memo,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0, 0, ?, ?,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $result = $insert_stmt->execute([
            $confirmation_date, $driver_id,
            $bill_10000, $bill_5000, $bill_1000, $coin_500, $coin_100,
            $coin_50, $coin_10, $total_amount, $memo
        ]);

        $message = 'データを正常に保存しました';
        $record_id = $pdo->lastInsertId();
    }

    if (!$result) {
        throw new Exception('データベースへの保存に失敗しました');
    }

    // トランザクションをコミット
    $pdo->commit();

    // 成功レスポンス
    echo json_encode([
        'success' => true,
        'message' => $message,
        'record_id' => $record_id,
        'data' => [
            'confirmation_date' => $confirmation_date,
            'driver_id' => $driver_id,
            'total_amount' => $total_amount
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("save_cash_count DB error: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラーが発生しました'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("save_cash_count error: " . $e->getMessage());

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
