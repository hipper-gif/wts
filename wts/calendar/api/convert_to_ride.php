<?php
// =================================================================
// 乗車記録変換API
// 
// ファイル: /Smiley/taxi/wts/calendar/api/convert_to_ride.php
// 機能: 完了予約をWTS乗車記録に変換・売上反映
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

// 基盤システム読み込み
require_once '../../functions.php';
require_once '../includes/calendar_functions.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    sendErrorResponse('認証が必要です', 401);
}

// POSTメソッドチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('POSTメソッドが必要です', 405);
}

// データベース接続
$pdo = getDatabaseConnection();

try {
    // 入力データ取得
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendErrorResponse('無効なJSONデータです');
    }
    
    // 必須パラメータチェック
    if (empty($input['reservation_id'])) {
        sendErrorResponse('予約IDが必要です');
    }
    
    $reservation_id = intval($input['reservation_id']);
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'];
    
    // 権限チェック（管理者のみ変換可能）
    if ($user_role !== 'admin' && $user_role !== 'manager') {
        sendErrorResponse('乗車記録変換権限がありません', 403);
    }
    
    // 予約データ取得
    $stmt = $pdo->prepare("
        SELECT r.*, u.full_name as driver_name, v.vehicle_number, v.model 
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.id = ?
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        sendErrorResponse('予約が見つかりません');
    }
    
    // 変換可能性チェック
    if ($reservation['status'] !== '完了') {
        sendErrorResponse('完了ステータスの予約のみ変換可能です');
    }
    
    if ($reservation['ride_record_id']) {
        sendErrorResponse('既に乗車記録に変換済みです');
    }
    
    // 実際料金チェック（変換時は実際料金が必要）
    if (!$reservation['actual_fare'] && !$reservation['estimated_fare']) {
        sendErrorResponse('料金情報が不足しています');
    }
    
    // 乗車記録変換実行
    $conversion_result = convertReservationToRideRecord($reservation_id);
    
    if (!$conversion_result['success']) {
        sendErrorResponse($conversion_result['message']);
    }
    
    // 変換後データ取得
    $ride_record_id = $conversion_result['ride_record_id'];
    $stmt = $pdo->prepare("
        SELECT rr.*, u.full_name as driver_name, v.vehicle_number 
        FROM ride_records rr
        LEFT JOIN users u ON rr.driver_id = u.id
        LEFT JOIN vehicles v ON rr.vehicle_id = v.id
        WHERE rr.id = ?
    ");
    $stmt->execute([$ride_record_id]);
    $ride_record = $stmt->fetch();
    
    // 操作ログ記録
    logConversionAction($user_id, $reservation_id, $ride_record_id, $reservation, $ride_record);
    
    // レスポンス送信
    sendSuccessResponse([
        'ride_record_id' => $ride_record_id,
        'ride_date' => $ride_record['ride_date'],
        'total_amount' => $ride_record['total_amount'],
        'driver_name' => $ride_record['driver_name'],
        'vehicle_number' => $ride_record['vehicle_number'],
        'ride_records_url' => '../ride_records.php?action=edit&id=' . $ride_record_id
    ], '乗車記録への変換が完了しました。売上管理に反映されます。');
    
} catch (Exception $e) {
    error_log("乗車記録変換エラー: " . $e->getMessage());
    sendErrorResponse('変換中にエラーが発生しました: ' . $e->getMessage());
}

/**
 * 変換ログ記録
 */
function logConversionAction($user_id, $reservation_id, $ride_record_id, $reservation_data, $ride_record_data) {
    global $pdo;
    
    try {
        $user_type = $_SESSION['user_role'] === 'partner_company' ? 'partner_company' : 
                    ($_SESSION['user_role'] === 'admin' ? 'admin' : 'driver');
        
        $log_data = [
            'conversion_type' => 'reservation_to_ride',
            'reservation_id' => $reservation_id,
            'ride_record_id' => $ride_record_id,
            'reservation_data' => $reservation_data,
            'ride_record_data' => $ride_record_data
        ];
        
        $sql = "
            INSERT INTO calendar_audit_logs 
            (user_id, user_type, action, target_type, target_id, new_data, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $user_type,
            'convert',
            'reservation',
            $reservation_id,
            json_encode($log_data, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
    } catch (Exception $e) {
        error_log("変換ログ記録エラー: " . $e->getMessage());
    }
}
?>
