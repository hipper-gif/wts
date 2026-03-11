<?php
// =================================================================
// 介護タクシー予約管理カレンダーシステム - 共通関数
// 
// ファイル: /Smiley/taxi/wts/calendar/includes/calendar_functions.php
// 機能: カレンダー処理・予約管理・データ変換共通関数
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

/**
 * 予約データ取得（FullCalendar形式）
 */
function getReservationsForCalendar($start_date, $end_date, $driver_id = null) {
    global $pdo;
    
    $sql = "
        SELECT
            r.id,
            r.reservation_date,
            r.reservation_time,
            r.client_name,
            r.pickup_location,
            r.dropoff_location,
            r.passenger_count,
            r.service_type,
            r.rental_service,
            r.is_time_critical,
            r.status,
            r.is_return_trip,
            r.parent_reservation_id,
            r.estimated_fare,
            r.actual_fare,
            r.driver_id,
            r.vehicle_id,
            r.entrance_assistance,
            r.disability_card,
            r.care_service_user,
            r.hospital_escort_staff,
            r.dual_assistance_staff,
            r.referrer_type,
            r.referrer_name,
            r.referrer_contact,
            r.payment_method,
            r.special_notes,
            u.name as driver_name,
            v.vehicle_number,
            v.model as vehicle_model,
            pc.company_name as partner_company,
            pc.display_color as company_color
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN partner_companies pc ON r.created_by = pc.id
        WHERE r.reservation_date BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    
    // 運転者フィルター
    if ($driver_id && $driver_id !== 'all') {
        $sql .= " AND r.driver_id = ?";
        $params[] = $driver_id;
    }
    
    
    $sql .= " ORDER BY r.reservation_date, r.reservation_time";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
    
    $events = [];
    foreach ($reservations as $reservation) {
        $events[] = convertReservationToEvent($reservation);
    }
    
    return $events;
}

/**
 * 予約データをFullCalendarイベント形式に変換
 */
function convertReservationToEvent($reservation) {
    // 基本情報
    $event = [
        'id' => $reservation['id'],
        'title' => createEventTitle($reservation),
        'start' => $reservation['reservation_date'] . 'T' . $reservation['reservation_time'],
        'backgroundColor' => getEventColor($reservation),
        'borderColor' => getEventBorderColor($reservation),
        'textColor' => getEventTextColor($reservation),
        'extendedProps' => [
            'reservationId' => $reservation['id'],
            'reservation_date' => $reservation['reservation_date'],
            'reservation_time' => $reservation['reservation_time'],
            'clientName' => $reservation['client_name'],
            'pickupLocation' => $reservation['pickup_location'],
            'dropoffLocation' => $reservation['dropoff_location'],
            'passengerCount' => $reservation['passenger_count'],
            'serviceType' => $reservation['service_type'],
            'rentalService' => $reservation['rental_service'],
            'isTimeCritical' => $reservation['is_time_critical'],
            'status' => $reservation['status'],
            'isReturnTrip' => $reservation['is_return_trip'],
            'parentReservationId' => $reservation['parent_reservation_id'],
            'driverName' => $reservation['driver_name'],
            'driverId' => $reservation['driver_id'],
            'vehicleId' => $reservation['vehicle_id'],
            'vehicleInfo' => $reservation['vehicle_number'] ? $reservation['vehicle_number'] . ' (' . $reservation['vehicle_model'] . ')' : '',
            'partnerCompany' => $reservation['partner_company'],
            'fare' => $reservation['actual_fare'] ?: $reservation['estimated_fare'],
            'estimatedFare' => $reservation['estimated_fare'],
            'actualFare' => $reservation['actual_fare'],
            'entranceAssistance' => $reservation['entrance_assistance'],
            'disabilityCard' => $reservation['disability_card'],
            'careServiceUser' => $reservation['care_service_user'],
            'hospitalEscortStaff' => $reservation['hospital_escort_staff'],
            'dualAssistanceStaff' => $reservation['dual_assistance_staff'],
            'referrerType' => $reservation['referrer_type'],
            'referrerName' => $reservation['referrer_name'],
            'referrerContact' => $reservation['referrer_contact'],
            'paymentMethod' => $reservation['payment_method'],
            'specialNotes' => $reservation['special_notes']
        ]
    ];
    
    // 終了時刻設定（デフォルト1時間）
    $start_time = new DateTime($reservation['reservation_date'] . ' ' . $reservation['reservation_time']);
    $end_time = clone $start_time;
    $end_time->add(new DateInterval('PT1H'));
    $event['end'] = $end_time->format('Y-m-d\TH:i:s');
    
    return $event;
}

/**
 * イベントタイトル作成
 */
function createEventTitle($reservation) {
    $title = $reservation['client_name'] . '様';
    
    // 復路マーク
    if ($reservation['is_return_trip']) {
        $title = '🔄 ' . $title;
    }
    
    // サービス種別アイコン
    $icons = [
        'お迎え' => '🚐',
        'お送り' => '🏠',
        '入院' => '🏥',
        '退院' => '🏠',
        '転院' => '🏥',
        'その他' => '📍'
    ];
    $title = ($icons[$reservation['service_type']] ?? '📍') . ' ' . $title;
    
    // レンタルサービス
    if ($reservation['rental_service'] !== 'なし') {
        $rental_icons = [
            '車いす' => '♿',
            'リクライニング' => '🛏️',
            'ストレッチャー' => '🏥'
        ];
        $title .= ' ' . ($rental_icons[$reservation['rental_service']] ?? '');
    }
    
    // 時間厳守マーク
    if (!$reservation['is_time_critical']) {
        $title .= ' (~)';
    }
    
    return $title;
}

/**
 * イベント背景色取得
 */
function getEventColor($reservation) {
    // ステータス別色分け
    $status_colors = [
        '予約' => '#2196F3',      // 青
        '進行中' => '#FF9800',    // オレンジ
        '完了' => '#4CAF50',      // 緑
        'キャンセル' => '#757575'  // グレー
    ];
    
    // 協力会社色優先
    if ($reservation['company_color']) {
        return $reservation['company_color'];
    }
    
    return $status_colors[$reservation['status']] ?? '#2196F3';
}

/**
 * イベント枠線色取得
 */
function getEventBorderColor($reservation) {
    // 復路は破線効果用に濃い色
    if ($reservation['is_return_trip']) {
        return '#1565C0';
    }
    
    // レンタルサービス有りは特別色
    if ($reservation['rental_service'] !== 'なし') {
        return '#D32F2F';
    }
    
    return getEventColor($reservation);
}

/**
 * イベント文字色取得
 */
function getEventTextColor($reservation) {
    // ダークモード対応
    $bg_color = getEventColor($reservation);
    return isLightColor($bg_color) ? '#000000' : '#FFFFFF';
}

/**
 * 色が明るいかどうか判定
 */
function isLightColor($hex_color) {
    $rgb = sscanf($hex_color, "#%02x%02x%02x");
    $brightness = (($rgb[0] * 299) + ($rgb[1] * 587) + ($rgb[2] * 114)) / 1000;
    return $brightness > 155;
}

/**
 * 場所使用回数更新
 */
function updateLocationUsage($location_name, $location_type = 'その他') {
    global $pdo;

    try {
        // usage_countを増加、またはレコード新規作成
        $stmt = $pdo->prepare("SELECT id FROM frequent_locations WHERE location_name = ?");
        $stmt->execute([$location_name]);

        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("UPDATE frequent_locations SET usage_count = usage_count + 1 WHERE location_name = ?");
            $stmt->execute([$location_name]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO frequent_locations (location_name, location_type, usage_count) VALUES (?, ?, 1)");
            $stmt->execute([$location_name, $location_type]);
        }

        return true;
    } catch (Exception $e) {
        error_log("場所使用回数更新エラー: " . $e->getMessage());
        return false;
    }
}

/**
 * 車両制約チェック
 */
function checkVehicleConstraints($vehicle_id, $rental_service) {
    global $pdo;
    
    // 車両情報取得
    $stmt = $pdo->prepare("SELECT model FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        return ['valid' => false, 'message' => '車両が見つかりません'];
    }
    
    // ストレッチャーはハイエースのみ
    if ($rental_service === 'ストレッチャー' && $vehicle['model'] !== 'ハイエース') {
        return [
            'valid' => false, 
            'message' => 'ストレッチャーはハイエースのみ対応可能です。車両を変更してください。'
        ];
    }
    
    return ['valid' => true, 'message' => ''];
}

/**
 * 予約時間重複チェック
 */
function checkTimeConflict($driver_id, $vehicle_id, $reservation_date, $reservation_time, $exclude_id = null) {
    global $pdo;
    
    $sql = "
        SELECT id, client_name, reservation_time 
        FROM reservations 
        WHERE reservation_date = ? 
            AND reservation_time = ?
            AND status != 'キャンセル'
            AND (driver_id = ? OR vehicle_id = ?)";
    
    $params = [$reservation_date, $reservation_time, $driver_id, $vehicle_id];
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflicts = $stmt->fetchAll();
    
    if (!empty($conflicts)) {
        $conflict = $conflicts[0];
        return [
            'conflict' => true,
            'message' => "選択された時刻に既に予約があります。\n予約者: {$conflict['client_name']}様\n時刻: {$conflict['reservation_time']}"
        ];
    }
    
    return ['conflict' => false, 'message' => ''];
}

/**
 * 復路作成データ準備
 */
function prepareReturnTripData($parent_reservation) {
    return [
        'reservation_date' => $parent_reservation['reservation_date'],
        'reservation_time' => calculateReturnTime($parent_reservation['reservation_time']),
        'client_name' => $parent_reservation['client_name'],
        'pickup_location' => $parent_reservation['dropoff_location'], // 入れ替え
        'dropoff_location' => $parent_reservation['pickup_location'], // 入れ替え
        'passenger_count' => $parent_reservation['passenger_count'],
        'driver_id' => $parent_reservation['driver_id'],
        'vehicle_id' => $parent_reservation['vehicle_id'],
        'service_type' => getReturnServiceType($parent_reservation['service_type']),
        'is_time_critical' => 0, // 復路は目安時間
        'rental_service' => $parent_reservation['rental_service'],
        'entrance_assistance' => $parent_reservation['entrance_assistance'],
        'disability_card' => $parent_reservation['disability_card'],
        'care_service_user' => $parent_reservation['care_service_user'],
        'referrer_type' => $parent_reservation['referrer_type'],
        'referrer_name' => $parent_reservation['referrer_name'],
        'referrer_contact' => $parent_reservation['referrer_contact'],
        'is_return_trip' => 1,
        'parent_reservation_id' => $parent_reservation['id'],
        'estimated_fare' => $parent_reservation['estimated_fare'],
        'payment_method' => $parent_reservation['payment_method']
    ];
}

/**
 * 復路時刻計算（3時間後がデフォルト）
 */
function calculateReturnTime($original_time, $hours_later = 3) {
    $time = new DateTime($original_time);
    $time->add(new DateInterval("PT{$hours_later}H"));
    return $time->format('H:i:s');
}

/**
 * 復路サービス種別変換
 */
function getReturnServiceType($original_service_type) {
    $conversions = [
        'お迎え' => 'お送り',
        'お送り' => 'お迎え',
        '入院' => '退院',
        '退院' => '入院',
        '転院' => '転院',
        'その他' => 'その他'
    ];
    
    return $conversions[$original_service_type] ?? 'その他';
}

/**
 * WTS乗車記録変換
 */
function convertReservationToRideRecord($reservation_id) {
    global $pdo;
    
    try {
        // 予約データ取得
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
        
        if (!$reservation) {
            throw new Exception('予約が見つかりません');
        }
        
        if ($reservation['status'] !== '完了') {
            throw new Exception('完了ステータスの予約のみ変換可能です');
        }
        
        // 既に変換済みチェック
        if ($reservation['ride_record_id']) {
            throw new Exception('既に乗車記録に変換済みです');
        }
        
        // 乗車記録作成
        $ride_data = [
            'ride_date' => $reservation['reservation_date'],
            'ride_time' => !empty($reservation['reservation_time']) ? $reservation['reservation_time'] : date('H:i'),
            'passenger_count' => $reservation['passenger_count'],
            'boarding_location' => $reservation['pickup_location'],
            'alighting_location' => $reservation['dropoff_location'],
            'fare' => $reservation['actual_fare'] ?: $reservation['estimated_fare'],
            'charge' => 0,
            'total_amount' => $reservation['actual_fare'] ?: $reservation['estimated_fare'],
            'payment_method' => $reservation['payment_method'],
            'driver_id' => $reservation['driver_id'],
            'vehicle_id' => $reservation['vehicle_id'],
            'transportation_type' => convertServiceTypeToTransportationType($reservation['service_type']),
            'is_return_trip' => $reservation['is_return_trip'],
            'original_ride_id' => $reservation['parent_reservation_id'],
            'remarks' => $reservation['special_notes'],
            'is_sample_data' => 0
        ];
        
        $sql = "
            INSERT INTO ride_records 
            (ride_date, ride_time, passenger_count, boarding_location, alighting_location, 
             fare, charge, total_amount, payment_method, driver_id, vehicle_id, 
             transportation_type, is_return_trip, original_ride_id, remarks, is_sample_data)
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = array_values($ride_data);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $ride_record_id = $pdo->lastInsertId();
        
        // 予約テーブルのride_record_id更新
        $stmt = $pdo->prepare("UPDATE reservations SET ride_record_id = ? WHERE id = ?");
        $stmt->execute([$ride_record_id, $reservation_id]);
        
        return [
            'success' => true,
            'ride_record_id' => $ride_record_id,
            'message' => '乗車記録への変換が完了しました'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '変換エラー: ' . $e->getMessage()
        ];
    }
}

/**
 * サービス種別→輸送種別変換
 */
function convertServiceTypeToTransportationType($service_type) {
    $conversions = [
        'お迎え' => '通院',
        'お送り' => '通院',
        '入院' => '施設入所',
        '退院' => '外出等',
        '転院' => '転院',
        'その他' => 'その他'
    ];
    
    return $conversions[$service_type] ?? 'その他';
}

/**
 * JSONレスポンス送信
 */
function sendJsonResponse($data, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * エラーレスポンス送信
 */
function sendErrorResponse($message, $http_code = 400) {
    sendJsonResponse([
        'success' => false,
        'message' => $message
    ], $http_code);
}

/**
 * 成功レスポンス送信
 */
function sendSuccessResponse($data = [], $message = '') {
    $response = ['success' => true];
    
    if ($message) {
        $response['message'] = $message;
    }
    
    $response['data'] = $data;
    
    sendJsonResponse($response);
}
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
?>
