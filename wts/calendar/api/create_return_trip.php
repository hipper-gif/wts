<?php
// =================================================================
// 復路作成API
// 
// ファイル: /Smiley/taxi/wts/calendar/api/create_return_trip.php
// 機能: 往路から復路自動作成・時間計算・データ引き継ぎ
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
    
    // 必須パラメータチェック
    if (empty($input['parent_reservation_id'])) {
        sendErrorResponse('親予約IDが必要です');
    }
    
    $parent_reservation_id = intval($input['parent_reservation_id']);
    $hours_later = intval($input['hours_later'] ?? 3);
    $custom_time = $input['custom_time'] ?? null;
    
    // 権限チェック
    $user_id = $_SESSION['user_id'];
    
    // 親予約データ取得
    $stmt = $pdo->prepare("
        SELECT r.*, u.name as driver_name, v.vehicle_number, v.model
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.id = ?
    ");
    $stmt->execute([$parent_reservation_id]);
    $parent_reservation = $stmt->fetch();
    
    if (!$parent_reservation) {
        sendErrorResponse('親予約が見つかりません');
    }
    
    // 権限チェック（協力会社は自社予約のみ）
    if (false) {
        $stmt = $pdo->prepare("SELECT access_level FROM partner_companies WHERE id = ?");
        $stmt->execute([$user_id]);
        $company = $stmt->fetch();
        
        if (!$company || $company['access_level'] === '閲覧のみ') {
            sendErrorResponse('復路作成権限がありません', 403);
        }
        
        if ($parent_reservation['created_by'] != $user_id) {
            sendErrorResponse('他社の予約から復路は作成できません', 403);
        }
    }
    
    // 復路作成可能性チェック
    if ($parent_reservation['is_return_trip']) {
        sendErrorResponse('復路から復路は作成できません');
    }
    
    // 既存復路チェック
    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE parent_reservation_id = ?");
    $stmt->execute([$parent_reservation_id]);
    $existing_return = $stmt->fetch();
    
    if ($existing_return) {
        sendErrorResponse('この予約の復路は既に作成済みです');
    }
    
    // 復路データ準備
    $return_data = prepareReturnTripData($parent_reservation);
    
    // カスタム時刻設定
    if ($custom_time) {
        $return_data['reservation_time'] = $custom_time;
    } else {
        $return_data['reservation_time'] = calculateReturnTime($parent_reservation['reservation_time'], $hours_later);
    }
    
    $return_data['return_hours_later'] = $hours_later;
    $return_data['created_by'] = $user_id;
    
    // 時間重複チェック
    if ($parent_reservation['driver_id'] || $parent_reservation['vehicle_id']) {
        $conflict_check = checkTimeConflict(
            $parent_reservation['driver_id'],
            $parent_reservation['vehicle_id'],
            $return_data['reservation_date'],
            $return_data['reservation_time']
        );
        
        if ($conflict_check['conflict']) {
            sendErrorResponse('復路作成時刻で重複があります: ' . $conflict_check['message']);
        }
    }
    
    // トランザクション開始
    $pdo->beginTransaction();
    
    // 復路予約作成
    $fields = array_keys($return_data);
    $placeholders = str_repeat('?,', count($fields) - 1) . '?';
    
    $sql = "INSERT INTO reservations (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($return_data));
    
    $return_reservation_id = $pdo->lastInsertId();
    
    // 操作ログ記録
    logReturnTripAction($user_id, $parent_reservation_id, $return_reservation_id, $return_data);
    
    // トランザクションコミット
    $pdo->commit();
    
    // 復路予約データ取得（レスポンス用）
    $stmt = $pdo->prepare("
        SELECT r.*, u.name as driver_name, v.vehicle_number, v.model
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.id = ?
    ");
    $stmt->execute([$return_reservation_id]);
    $return_reservation = $stmt->fetch();
    
    // レスポンス送信
    sendSuccessResponse([
        'return_reservation_id' => $return_reservation_id,
        'return_time' => $return_data['reservation_time'],
        'pickup_location' => $return_data['pickup_location'],
        'dropoff_location' => $return_data['dropoff_location'],
        'service_type' => $return_data['service_type'],
        'driver_name' => $return_reservation['driver_name'],
        'vehicle_info' => $return_reservation['vehicle_number'] . ' (' . $return_reservation['model'] . ')',
        'estimated_fare' => $return_data['estimated_fare'],
        'calendar_event' => convertReservationToEvent($return_reservation)
    ], '復路を作成しました');
    
} catch (Exception $e) {
    // トランザクションロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("復路作成エラー: " . $e->getMessage());
    sendErrorResponse('復路作成中にエラーが発生しました: ' . $e->getMessage());
}

/**
 * 復路作成ログ記録
 */
function logReturnTripAction($user_id, $parent_id, $return_id, $return_data) {
    global $pdo;
    
    try {
        $user_type = false ? 'partner_company' : 
                    (false ? 'admin' : 'user');
        
        $log_data = [
            'parent_reservation_id' => $parent_id,
            'return_reservation_id' => $return_id,
            'action' => 'create_return_trip',
            'return_data' => $return_data
        ];
        
        $sql = "
            INSERT INTO calendar_audit_logs 
            (user_id, user_type, action, target_type, target_id, new_data, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $user_type,
            'create',
            'reservation',
            $return_id,
            json_encode($log_data, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
    } catch (Exception $e) {
        error_log("復路作成ログ記録エラー: " . $e->getMessage());
    }
}
?>
