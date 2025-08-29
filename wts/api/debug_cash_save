<?php
// デバッグ用の集金保存API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 詳細なエラーログを有効化
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();

// デバッグ情報を収集
$debug_info = [
    'session_check' => isset($_SESSION['user_id']) ? 'OK' : 'NG',
    'user_id' => $_SESSION['user_id'] ?? 'なし',
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'なし'
];

// OPTIONSリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['debug' => $debug_info, 'message' => 'OPTIONS request processed']);
    exit;
}

try {
    // ログインチェック
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false, 
            'message' => '認証が必要です',
            'debug' => $debug_info
        ]);
        exit;
    }
    
    // データベース接続テスト
    $debug_info['db_config_exists'] = file_exists('../config/database.php') ? 'あり' : 'なし';
    
    require_once '../config/database.php';
    
    // getDBConnection関数の存在確認
    $debug_info['getDBConnection_exists'] = function_exists('getDBConnection') ? 'あり' : 'なし';
    
    $pdo = getDBConnection();
    $debug_info['db_connection'] = $pdo ? 'OK' : 'NG';
    
    // テーブル存在確認
    $table_check = $pdo->query("SHOW TABLES LIKE 'cash_count_details'");
    $debug_info['table_exists'] = $table_check->rowCount() > 0 ? 'あり' : 'なし';
    
    if ($debug_info['table_exists'] === 'あり') {
        // テーブル構造確認
        $columns = $pdo->query("DESCRIBE cash_count_details")->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['table_columns'] = array_column($columns, 'Field');
    }
    
    // JSONデータ取得
    $input = file_get_contents('php://input');
    $debug_info['raw_input'] = substr($input, 0, 200); // 最初の200文字のみ
    
    $data = json_decode($input, true);
    $debug_info['json_decode_error'] = json_last_error_msg();
    $debug_info['received_data'] = $data;
    
    if (!$data) {
        throw new Exception('JSONデコードエラー: ' . json_last_error_msg());
    }
    
    // 必須フィールドチェック
    if (!isset($data['confirmation_date']) || !isset($data['driver_id'])) {
        throw new Exception('必須フィールドが不足: ' . 
            (isset($data['confirmation_date']) ? '' : 'confirmation_date ') .
            (isset($data['driver_id']) ? '' : 'driver_id')
        );
    }
    
    // 既存データ確認
    $check_stmt = $pdo->prepare("
        SELECT id FROM cash_count_details 
        WHERE confirmation_date = ? AND driver_id = ?
    ");
    $check_stmt->execute([$data['confirmation_date'], $data['driver_id']]);
    $existing = $check_stmt->fetch();
    $debug_info['existing_record'] = $existing ? '存在' : '新規';
    
    // 実際のデータ保存を実行
    if ($existing) {
        // 更新
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
        
        $result = $stmt->execute([
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
        
        $debug_info['sql_operation'] = 'UPDATE';
        $debug_info['sql_result'] = $result ? 'OK' : 'NG';
        $debug_info['affected_rows'] = $stmt->rowCount();
        
    } else {
        // 挿入
        $stmt = $pdo->prepare("
            INSERT INTO cash_count_details (
                confirmation_date, driver_id, 
                bill_5000, bill_1000, coin_500, coin_100, coin_50, coin_10,
                total_amount, memo, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");
        
        $result = $stmt->execute([
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
        
        $debug_info['sql_operation'] = 'INSERT';
        $debug_info['sql_result'] = $result ? 'OK' : 'NG';
        $debug_info['affected_rows'] = $stmt->rowCount();
        $debug_info['last_insert_id'] = $pdo->lastInsertId();
    }
    
    echo json_encode([
        'success' => true,
        'message' => '集金データを正常に保存しました',
        'debug' => $debug_info,
        'data' => $data
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラー: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'debug' => $debug_info ?? [],
        'sql_state' => $e->errorInfo[0] ?? 'unknown'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $debug_info ?? []
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PHPエラー: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'debug' => $debug_info ?? []
    ]);
}
?>
