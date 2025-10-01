<?php
// =================================================================
// 予約処理共通関数
// 
// ファイル: /Smiley/taxi/wts/calendar/includes/reservation_functions.php
// 機能: 予約CRUD操作・ビジネスロジック・検証処理
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

/**
 * 予約データ詳細取得
 */
function getReservationDetails($reservation_id) {
    global $pdo;
    
    $sql = "
        SELECT 
            r.*,
            u.full_name as driver_name,
            u.phone as driver_phone,
            v.vehicle_number,
            v.model as vehicle_model,
            v.capacity as vehicle_capacity,
            pc.company_name as partner_company,
            pc.display_color as company_color,
            pr.client_name as parent_client_name,
            pr.reservation_date as parent_date,
            pr.reservation_time as parent_time,
            rr.id as ride_record_id,
            rr.total_amount as ride_total_amount
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN partner_companies pc ON r.created_by = pc.id
        LEFT JOIN reservations pr ON r.parent_reservation_id = pr.id
        LEFT JOIN ride_records rr ON r.ride_record_id = rr.id
        WHERE r.id = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reservation_id]);
    
    return $stmt->fetch();
}

/**
 * 予約作成
 */
function createReservation($data, $user_id) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // データ前処理
        $processed_data = preprocessReservationData($data, $user_id);
        
        // 重複チェック
        if (checkReservationDuplicates($processed_data)) {
            throw new Exception('同じ時刻に既に予約が存在します');
        }
        
        // 制約チェック
        validateReservationConstraints($processed_data);
        
        // 予約挿入
        $reservation_id = insertReservationRecord($processed_data);
        
        // 関連処理
        handlePostReservationTasks($reservation_id, $processed_data);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'reservation_id' => $reservation_id,
            'can_create_return' => canCreateReturnTrip($processed_data)
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 予約更新
 */
function updateReservation($reservation_id, $data, $user_id) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // 既存データ取得
        $existing = getReservationDetails($reservation_id);
        if (!$existing) {
            throw new Exception('更新対象の予約が見つかりません');
        }
        
        // 権限チェック
        validateUpdatePermission($existing, $user_id);
        
        // データ前処理
        $processed_data = preprocessReservationData($data, $user_id, $reservation_id);
        
        // 制約チェック
        validateReservationConstraints($processed_data, $reservation_id);
        
        // 更新実行
        updateReservationRecord($reservation_id, $processed_data);
        
        // 変更ログ記録
        logReservationChange($reservation_id, $existing, $processed_data, $user_id);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'reservation_id' => $reservation_id
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 予約削除（キャンセル）
 */
function cancelReservation($reservation_id, $user_id, $reason = '') {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // 既存データ取得
        $existing = getReservationDetails($reservation_id);
        if (!$existing) {
            throw new Exception('キャンセル対象の予約が見つかりません');
        }
        
        // 権限チェック
        validateUpdatePermission($existing, $user_id);
        
        // キャンセル可能性チェック
        if (!canCancelReservation($existing)) {
            throw new Exception('この予約はキャンセルできません');
        }
        
        // ステータス更新
        $stmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'キャンセル', 
                special_notes = CONCAT(COALESCE(special_notes, ''), '\nキャンセル理由: ', ?)
            WHERE id = ?
        ");
        $stmt->execute([$reason, $reservation_id]);
        
        // 復路も自動キャンセル
        if (!$existing['is_return_trip']) {
            cancelChildReservations($reservation_id, $user_id);
        }
        
        // キャンセルログ記録
        logReservationCancellation($reservation_id, $existing, $user_id, $reason);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'reservation_id' => $reservation_id
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 復路予約作成
 */
function createReturnTrip($parent_reservation_id, $hours_later = 3, $custom_time = null, $user_id = null) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // 親予約取得
        $parent = getReservationDetails($parent_reservation_id);
        if (!$parent) {
            throw new Exception('親予約が見つかりません');
        }
        
        // 復路作成可能性チェック
        if (!canCreateReturnTrip($parent)) {
            throw new Exception('この予約からは復路を作成できません');
        }
        
        // 既存復路チェック
        if (hasExistingReturnTrip($parent_reservation_id)) {
            throw new Exception('復路は既に作成済みです');
        }
        
        // 復路データ準備
        $return_data = prepareReturnTripData($parent);
        
        // 時刻設定
        if ($custom_time) {
            $return_data['reservation_time'] = $custom_time;
        } else {
            $return_data['reservation_time'] = calculateReturnTime($parent['reservation_time'], $hours_later);
        }
        
        $return_data['return_hours_later'] = $hours_later;
        $return_data['created_by'] = $user_id ?: $parent['created_by'];
        
        // 制約チェック
        validateReservationConstraints($return_data);
        
        // 復路挿入
        $return_reservation_id = insertReservationRecord($return_data);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'return_reservation_id' => $return_reservation_id,
            'return_time' => $return_data['reservation_time']
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 予約検索
 */
function searchReservations($filters = [], $limit = 100, $offset = 0) {
    global $pdo;
    
    $sql = "
        SELECT 
            r.*,
            u.full_name as driver_name,
            v.vehicle_number,
            v.model as vehicle_model,
            pc.company_name as partner_company
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN partner_companies pc ON r.created_by = pc.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // フィルター適用
    if (!empty($filters['date_from'])) {
        $sql .= " AND r.reservation_date >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND r.reservation_date <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($filters['driver_id'])) {
        $sql .= " AND r.driver_id = ?";
        $params[] = $filters['driver_id'];
    }
    
    if (!empty($filters['vehicle_id'])) {
        $sql .= " AND r.vehicle_id = ?";
        $params[] = $filters['vehicle_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND r.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['service_type'])) {
        $sql .= " AND r.service_type = ?";
        $params[] = $filters['service_type'];
    }
    
    if (!empty($filters['client_name'])) {
        $sql .= " AND r.client_name LIKE ?";
        $params[] = '%' . $filters['client_name'] . '%';
    }
    
    if (!empty($filters['rental_service']) && $filters['rental_service'] !== 'all') {
        $sql .= " AND r.rental_service = ?";
        $params[] = $filters['rental_service'];
    }
    
    // 協力会社フィルター
    if (!empty($filters['created_by'])) {
        $sql .= " AND r.created_by = ?";
        $params[] = $filters['created_by'];
    }
    
    $sql .= " ORDER BY r.reservation_date DESC, r.reservation_time DESC";
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll();
}

/**
 * 予約統計取得
 */
function getReservationStatistics($date_from = null, $date_to = null) {
    global $pdo;
    
    if (!$date_from) {
        $date_from = date('Y-m-01'); // 今月の開始
    }
    if (!$date_to) {
        $date_to = date('Y-m-t'); // 今月の終了
    }
    
    $stats = [];
    
    // 基本統計
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_reservations,
            COUNT(CASE WHEN status = '予約' THEN 1 END) as pending_reservations,
            COUNT(CASE WHEN status = '進行中' THEN 1 END) as in_progress_reservations,
            COUNT(CASE WHEN status = '完了' THEN 1 END) as completed_reservations,
            COUNT(CASE WHEN status = 'キャンセル' THEN 1 END) as cancelled_reservations,
            COUNT(CASE WHEN is_return_trip = 1 THEN 1 END) as return_trips,
            SUM(passenger_count) as total_passengers,
            AVG(passenger_count) as avg_passengers,
            SUM(COALESCE(actual_fare, estimated_fare, 0)) as total_revenue
        FROM reservations 
        WHERE reservation_date BETWEEN ? AND ?
    ");
    $stmt->execute([$date_from, $date_to]);
    $stats['basic'] = $stmt->fetch();
    
    // サービス種別統計
    $stmt = $pdo->prepare("
        SELECT 
            service_type,
            COUNT(*) as count,
            AVG(COALESCE(actual_fare, estimated_fare, 0)) as avg_fare
        FROM reservations 
        WHERE reservation_date BETWEEN ? AND ?
        GROUP BY service_type
        ORDER BY count DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $stats['service_types'] = $stmt->fetchAll();
    
    // レンタルサービス統計
    $stmt = $pdo->prepare("
        SELECT 
            rental_service,
            COUNT(*) as count
        FROM reservations 
        WHERE reservation_date BETWEEN ? AND ?
        GROUP BY rental_service
        ORDER BY count DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $stats['rental_services'] = $stmt->fetchAll();
    
    // 運転者別統計
    $stmt = $pdo->prepare("
        SELECT 
            u.full_name as driver_name,
            COUNT(r.id) as reservation_count,
            SUM(r.passenger_count) as total_passengers,
            SUM(COALESCE(r.actual_fare, r.estimated_fare, 0)) as total_revenue
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        WHERE r.reservation_date BETWEEN ? AND ?
            AND r.driver_id IS NOT NULL
        GROUP BY r.driver_id, u.full_name
        ORDER BY reservation_count DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $stats['drivers'] = $stmt->fetchAll();
    
    // 日別統計
    $stmt = $pdo->prepare("
        SELECT 
            reservation_date,
            COUNT(*) as reservation_count,
            SUM(passenger_count) as passenger_count,
            SUM(COALESCE(actual_fare, estimated_fare, 0)) as daily_revenue
        FROM reservations 
        WHERE reservation_date BETWEEN ? AND ?
        GROUP BY reservation_date
        ORDER BY reservation_date
    ");
    $stmt->execute([$date_from, $date_to]);
    $stats['daily'] = $stmt->fetchAll();
    
    return $stats;
}

// =================================================================
// 内部ヘルパー関数
// =================================================================

/**
 * 予約データ前処理
 */
function preprocessReservationData($data, $user_id, $reservation_id = null) {
    // 基本データ設定
    $processed = [
        'reservation_date' => $data['reservation_date'],
        'reservation_time' => $data['reservation_time'],
        'client_name' => trim($data['client_name']),
        'pickup_location' => trim($data['pickup_location']),
        'dropoff_location' => trim($data['dropoff_location']),
        'passenger_count' => intval($data['passenger_count'] ?? 1),
        'driver_id' => !empty($data['driver_id']) ? intval($data['driver_id']) : null,
        'vehicle_id' => !empty($data['vehicle_id']) ? intval($data['vehicle_id']) : null,
        'service_type' => $data['service_type'],
        'is_time_critical' => isset($data['is_time_critical']) ? 1 : 0,
        'rental_service' => $data['rental_service'] ?? 'なし',
        'entrance_assistance' => isset($data['entrance_assistance']) ? 1 : 0,
        'disability_card' => isset($data['disability_card']) ? 1 : 0,
        'care_service_user' => isset($data['care_service_user']) ? 1 : 0,
        'hospital_escort_staff' => trim($data['hospital_escort_staff'] ?? ''),
        'dual_assistance_staff' => trim($data['dual_assistance_staff'] ?? ''),
        'referrer_type' => $data['referrer_type'],
        'referrer_name' => trim($data['referrer_name']),
        'referrer_contact' => trim($data['referrer_contact'] ?? ''),
        'is_return_trip' => intval($data['is_return_trip'] ?? 0),
        'parent_reservation_id' => !empty($data['parent_reservation_id']) ? intval($data['parent_reservation_id']) : null,
        'return_hours_later' => !empty($data['return_hours_later']) ? intval($data['return_hours_later']) : null,
        'estimated_fare' => !empty($data['estimated_fare']) ? intval($data['estimated_fare']) : 0,
        'actual_fare' => !empty($data['actual_fare']) ? intval($data['actual_fare']) : null,
        'payment_method' => $data['payment_method'] ?? '現金',
        'status' => $data['status'] ?? '予約',
        'special_notes' => trim($data['special_notes'] ?? ''),
        'created_by' => $user_id
    ];
    
    // 新規作成時のみ設定する項目
    if (!$reservation_id) {
        $processed['ride_record_id'] = null;
    }
    
    return $processed;
}

/**
 * 重複チェック
 */
function checkReservationDuplicates($data, $exclude_id = null) {
    global $pdo;
    
    $sql = "
        SELECT COUNT(*) FROM reservations 
        WHERE client_name = ? 
            AND reservation_date = ? 
            AND reservation_time = ?
            AND status != 'キャンセル'
    ";
    
    $params = [$data['client_name'], $data['reservation_date'], $data['reservation_time']];
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchColumn() > 0;
}

/**
 * 制約検証
 */
function validateReservationConstraints($data, $exclude_id = null) {
    // 車両制約チェック
    if ($data['vehicle_id'] && $data['rental_service'] !== 'なし') {
        $constraint_check = checkVehicleConstraints($data['vehicle_id'], $data['rental_service']);
        if (!$constraint_check['valid']) {
            throw new Exception($constraint_check['message']);
        }
    }
    
    // 時間重複チェック
    if ($data['driver_id'] || $data['vehicle_id']) {
        $conflict_check = checkTimeConflict(
            $data['driver_id'],
            $data['vehicle_id'],
            $data['reservation_date'],
            $data['reservation_time'],
            $exclude_id
        );
        
        if ($conflict_check['conflict']) {
            throw new Exception($conflict_check['message']);
        }
    }
    
    // 営業時間チェック
    $hour = intval(date('H', strtotime($data['reservation_time'])));
    if ($hour < 7 || $hour > 19) {
        throw new Exception('営業時間外です（7:00-19:00）');
    }
    
    // 過去日チェック
    $reservation_date = new DateTime($data['reservation_date']);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    if ($reservation_date < $today) {
        throw new Exception('過去の日付には予約できません');
    }
}

/**
 * 予約レコード挿入
 */
function insertReservationRecord($data) {
    global $pdo;
    
    $fields = array_keys($data);
    $placeholders = str_repeat('?,', count($fields) - 1) . '?';
    
    $sql = "INSERT INTO reservations (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    
    return $pdo->lastInsertId();
}

/**
 * 予約レコード更新
 */
function updateReservationRecord($reservation_id, $data) {
    global $pdo;
    
    $update_fields = [];
    $update_params = [];
    
    foreach ($data as $field => $value) {
        if ($field !== 'created_by') { // 作成者は更新しない
            $update_fields[] = "{$field} = ?";
            $update_params[] = $value;
        }
    }
    
    $update_params[] = $reservation_id;
    
    $sql = "UPDATE reservations SET " . implode(', ', $update_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($update_params);
}

/**
 * 予約後処理
 */
function handlePostReservationTasks($reservation_id, $data) {
    // よく使う場所の使用回数更新
    updateLocationUsage($data['pickup_location']);
    updateLocationUsage($data['dropoff_location']);
    
    // 追加の業務ロジックがあればここに追加
}

/**
 * 更新権限チェック
 */
function validateUpdatePermission($existing, $user_id) {
    $user_role = $_SESSION['user_role'] ?? '';
    
    // 管理者は全て編集可能
    if ($user_role === 'admin') {
        return true;
    }
    
    // 協力会社は自社予約のみ編集可能
    if ($user_role === 'partner_company') {
        if ($existing['created_by'] != $user_id) {
            throw new Exception('他社の予約は編集できません');
        }
    }
    
    return true;
}

/**
 * キャンセル可能性チェック
 */
function canCancelReservation($reservation) {
    // 既にキャンセル済み
    if ($reservation['status'] === 'キャンセル') {
        return false;
    }
    
    // 乗車記録に変換済み
    if ($reservation['ride_record_id']) {
        return false;
    }
    
    return true;
}

/**
 * 子予約キャンセル
 */
function cancelChildReservations($parent_reservation_id, $user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE reservations 
        SET status = 'キャンセル',
            special_notes = CONCAT(COALESCE(special_notes, ''), '\n往路キャンセルに伴う自動キャンセル')
        WHERE parent_reservation_id = ? AND status != 'キャンセル'
    ");
    $stmt->execute([$parent_reservation_id]);
}

/**
 * 復路作成可能性チェック
 */
function canCreateReturnTrip($reservation) {
    // 復路は作成不可
    if ($reservation['is_return_trip']) {
        return false;
    }
    
    // お迎え以外は作成不可
    if ($reservation['service_type'] !== 'お迎え') {
        return false;
    }
    
    return true;
}

/**
 * 既存復路チェック
 */
function hasExistingReturnTrip($parent_reservation_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE parent_reservation_id = ?");
    $stmt->execute([$parent_reservation_id]);
    
    return $stmt->fetchColumn() > 0;
}

/**
 * 変更ログ記録
 */
function logReservationChange($reservation_id, $old_data, $new_data, $user_id) {
    global $pdo;
    
    try {
        $user_type = $_SESSION['user_role'] === 'partner_company' ? 'partner_company' : 
                    ($_SESSION['user_role'] === 'admin' ? 'admin' : 'driver');
        
        $sql = "
            INSERT INTO calendar_audit_logs 
            (user_id, user_type, action, target_type, target_id, old_data, new_data, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $user_type,
            'edit',
            'reservation',
            $reservation_id,
            json_encode($old_data, JSON_UNESCAPED_UNICODE),
            json_encode($new_data, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
    } catch (Exception $e) {
        error_log("予約変更ログ記録エラー: " . $e->getMessage());
    }
}

/**
 * キャンセルログ記録
 */
function logReservationCancellation($reservation_id, $reservation_data, $user_id, $reason) {
    global $pdo;
    
    try {
        $user_type = $_SESSION['user_role'] === 'partner_company' ? 'partner_company' : 
                    ($_SESSION['user_role'] === 'admin' ? 'admin' : 'driver');
        
        $log_data = [
            'action' => 'cancellation',
            'reservation_data' => $reservation_data,
            'cancellation_reason' => $reason
        ];
        
        $sql = "
            INSERT INTO calendar_audit_logs 
            (user_id, user_type, action, target_type, target_id, old_data, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $user_type,
            'cancel',
            'reservation',
            $reservation_id,
            json_encode($log_data, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
    } catch (Exception $e) {
        error_log("予約キャンセルログ記録エラー: " . $e->getMessage());
    }
}
?>
