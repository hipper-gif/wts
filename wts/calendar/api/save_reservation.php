<?php
// =================================================================
// 予約保存API
// 
// ファイル: /Smiley/taxi/wts/calendar/api/save_reservation.php
// 機能: 新規予約作成・既存予約更新・バリデーション
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

// 基盤システム読み込み
require_once '../../config/database.php';
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
$pdo = getDBConnection();

try {
    // 入力データ取得
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendErrorResponse('無効なJSONデータです');
    }
    
    // 必須項目チェック
    $required_fields = [
        'reservation_date', 'reservation_time', 'client_name',
        'pickup_location', 'dropoff_location', 'service_type'
    ];
    
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            sendErrorResponse("必須項目が不足しています: {$field}");
        }
    }

    // ユーザーID取得
    $user_id = $_SESSION['user_id'];

    // 車両制約チェック
    if (!empty($input['vehicle_id']) && !empty($input['rental_service'])) {
        $constraint_check = checkVehicleConstraints($input['vehicle_id'], $input['rental_service']);
        if (!$constraint_check['valid']) {
            sendErrorResponse($constraint_check['message']);
        }
    }
    
    // 時間重複チェック
    if (!empty($input['driver_id']) || !empty($input['vehicle_id'])) {
        $conflict_check = checkTimeConflict(
            $input['driver_id'] ?? null,
            $input['vehicle_id'] ?? null,
            $input['reservation_date'],
            $input['reservation_time'],
            $input['id'] ?? null
        );
        
        if ($conflict_check['conflict']) {
            sendErrorResponse($conflict_check['message']);
        }
    }
    
    // データ準備
    $reservation_data = [
        'reservation_date' => $input['reservation_date'],
        'reservation_time' => $input['reservation_time'],
        'client_name' => trim($input['client_name']),
        'pickup_location' => trim($input['pickup_location']),
        'dropoff_location' => trim($input['dropoff_location']),
        'passenger_count' => intval($input['passenger_count'] ?? 1),
        'driver_id' => !empty($input['driver_id']) ? intval($input['driver_id']) : null,
        'vehicle_id' => !empty($input['vehicle_id']) ? intval($input['vehicle_id']) : null,
        'service_type' => $input['service_type'],
        'is_time_critical' => isset($input['is_time_critical']) ? 1 : 0,
        'rental_service' => $input['rental_service'] ?? 'なし',
        'entrance_assistance' => isset($input['entrance_assistance']) ? 1 : 0,
        'disability_card' => isset($input['disability_card']) ? 1 : 0,
        'care_service_user' => isset($input['care_service_user']) ? 1 : 0,
        'hospital_escort_staff' => trim($input['hospital_escort_staff'] ?? ''),
        'dual_assistance_staff' => trim($input['dual_assistance_staff'] ?? ''),
        'referrer_type' => $input['referrer_type'] ?? '',
        'referrer_name' => trim($input['referrer_name'] ?? ''),
        'referrer_contact' => trim($input['referrer_contact'] ?? ''),
        'is_return_trip' => intval($input['is_return_trip'] ?? 0),
        'parent_reservation_id' => !empty($input['parent_reservation_id']) ? intval($input['parent_reservation_id']) : null,
        'return_hours_later' => !empty($input['return_hours_later']) ? intval($input['return_hours_later']) : null,
        'estimated_fare' => !empty($input['estimated_fare']) ? intval($input['estimated_fare']) : 0,
        'actual_fare' => !empty($input['actual_fare']) ? intval($input['actual_fare']) : null,
        'payment_method' => $input['payment_method'] ?? '現金',
        'status' => $input['status'] ?? '予約',
        'special_notes' => trim($input['special_notes'] ?? ''),
        'created_by' => $user_id
    ];
    
    // トランザクション開始
    $pdo->beginTransaction();
    
    if (!empty($input['id'])) {
        // 既存予約更新
        $reservation_id = intval($input['id']);
        
        // 既存データ取得（ログ用）
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$reservation_id]);
        $old_data = $stmt->fetch();
        
        if (!$old_data) {
            throw new Exception('更新対象の予約が見つかりません');
        }
        
        
        // 更新実行
        $update_fields = [];
        $update_params = [];
        
        foreach ($reservation_data as $field => $value) {
            if ($field !== 'created_by') { // 作成者は更新しない
                $update_fields[] = "{$field} = ?";
                $update_params[] = $value;
            }
        }
        
        $update_params[] = $reservation_id;
        
        $sql = "UPDATE reservations SET " . implode(', ', $update_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($update_params);
        
        // 操作ログ記録
        logCalendarAction($user_id, 'edit', 'reservation', $reservation_id, $old_data, $reservation_data);
        
        $message = '予約を更新しました';
        
    } else {
        // 新規予約作成
        $fields = array_keys($reservation_data);
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';
        
        $sql = "INSERT INTO reservations (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($reservation_data));
        
        $reservation_id = $pdo->lastInsertId();
        
        // 操作ログ記録
        logCalendarAction($user_id, 'create', 'reservation', $reservation_id, null, $reservation_data);
        
        $message = '予約を作成しました';
    }
    
    // よく使う場所の使用回数更新
    updateLocationUsage($reservation_data['pickup_location']);
    updateLocationUsage($reservation_data['dropoff_location']);
    
    // トランザクションコミット
    $pdo->commit();
    
    // 復路作成可能かチェック
    $can_create_return = !$reservation_data['is_return_trip'] && $reservation_data['service_type'] === 'お迎え';
    
    // レスポンス送信
    sendSuccessResponse([
        'reservation_id' => $reservation_id,
        'can_create_return' => $can_create_return
    ], $message);
    
} catch (Exception $e) {
    // トランザクションロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("予約保存エラー: " . $e->getMessage());
    sendErrorResponse('予約保存中にエラーが発生しました: ' . $e->getMessage());
}

/**
 * カレンダー操作ログ記録
 */
function logCalendarAction($user_id, $action, $target_type, $target_id, $old_data = null, $new_data = null) {
    global $pdo;

    try {
        $user_type = 'user';
        
        $sql = "
            INSERT INTO calendar_audit_logs 
            (user_id, user_type, action, target_type, target_id, old_data, new_data, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $user_type,
            $action,
            $target_type,
            $target_id,
            $old_data ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null,
            $new_data ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
    } catch (Exception $e) {
        error_log("カレンダーログ記録エラー: " . $e->getMessage());
    }
}
?>
