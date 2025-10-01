<?php
// =================================================================
// 空き状況確認API
// 
// ファイル: /Smiley/taxi/wts/calendar/api/get_availability.php
// 機能: 運転者・車両の空き状況確認・配車可能性判定
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

// データベース接続
$pdo = getDatabaseConnection();

try {
    // パラメータ取得
    $date = $_GET['date'] ?? '';
    $time = $_GET['time'] ?? '';
    $rental_service = $_GET['rental_service'] ?? 'なし';
    $exclude_id = $_GET['exclude_id'] ?? null;
    
    // 必須パラメータチェック
    if (!$date || !$time) {
        sendErrorResponse('日付と時刻が必要です');
    }
    
    // 日付・時刻検証
    if (!strtotime($date) || !strtotime($time)) {
        sendErrorResponse('無効な日付または時刻形式です');
    }
    
    // 運転者空き状況取得
    $available_drivers = getAvailableDrivers($date, $time, $exclude_id);
    
    // 車両空き状況取得（レンタルサービス制約考慮）
    $available_vehicles = getAvailableVehicles($date, $time, $rental_service, $exclude_id);
    
    // 時間帯別空き状況取得
    $hourly_availability = getHourlyAvailability($date, $exclude_id);
    
    // レスポンス送信
    sendSuccessResponse([
        'date' => $date,
        'time' => $time,
        'rental_service' => $rental_service,
        'available_drivers' => $available_drivers,
        'available_vehicles' => $available_vehicles,
        'hourly_availability' => $hourly_availability,
        'recommendations' => generateRecommendations($available_drivers, $available_vehicles, $time)
    ]);
    
} catch (Exception $e) {
    error_log("空き状況確認エラー: " . $e->getMessage());
    sendErrorResponse('空き状況確認中にエラーが発生しました');
}

/**
 * 利用可能運転者取得
 */
function getAvailableDrivers($date, $time, $exclude_id = null) {
    global $pdo;
    
    $sql = "
        SELECT 
            u.id,
            u.full_name,
            u.phone,
            COUNT(r.id) as reservations_count,
            GROUP_CONCAT(r.reservation_time ORDER BY r.reservation_time) as reserved_times
        FROM users u
        LEFT JOIN reservations r ON u.id = r.driver_id 
            AND r.reservation_date = ? 
            AND r.status != 'キャンセル'";
    
    $params = [$date];
    
    if ($exclude_id) {
        $sql .= " AND r.id != ?";
        $params[] = $exclude_id;
    }
    
    $sql .= "
        WHERE u.is_driver = 1 AND u.is_active = 1
        GROUP BY u.id, u.full_name, u.phone
        ORDER BY reservations_count ASC, u.full_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $drivers = $stmt->fetchAll();
    
    // 各運転者の指定時刻での空き状況チェック
    foreach ($drivers as &$driver) {
        $driver['is_available'] = !isTimeSlotOccupied($driver['reserved_times'], $time);
        $driver['workload'] = calculateWorkload($driver['reservations_count']);
        $driver['reserved_times_array'] = $driver['reserved_times'] ? explode(',', $driver['reserved_times']) : [];
    }
    
    return $drivers;
}

/**
 * 利用可能車両取得
 */
function getAvailableVehicles($date, $time, $rental_service, $exclude_id = null) {
    global $pdo;
    
    $sql = "
        SELECT 
            v.id,
            v.vehicle_number,
            v.model,
            v.capacity,
            COUNT(r.id) as reservations_count,
            GROUP_CONCAT(r.reservation_time ORDER BY r.reservation_time) as reserved_times,
            SUM(CASE WHEN r.rental_service = 'ストレッチャー' THEN 1 ELSE 0 END) as stretcher_usage
        FROM vehicles v
        LEFT JOIN reservations r ON v.id = r.vehicle_id 
            AND r.reservation_date = ? 
            AND r.status != 'キャンセル'";
    
    $params = [$date];
    
    if ($exclude_id) {
        $sql .= " AND r.id != ?";
        $params[] = $exclude_id;
    }
    
    $sql .= "
        WHERE v.is_active = 1
        GROUP BY v.id, v.vehicle_number, v.model, v.capacity
        ORDER BY reservations_count ASC, v.vehicle_number";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
    
    // 各車両の空き状況・制約チェック
    foreach ($vehicles as &$vehicle) {
        $vehicle['is_available'] = !isTimeSlotOccupied($vehicle['reserved_times'], $time);
        
        // レンタルサービス制約チェック
        $vehicle['supports_rental_service'] = checkRentalServiceSupport($vehicle['model'], $rental_service);
        $vehicle['constraint_message'] = '';
        
        if (!$vehicle['supports_rental_service']) {
            $vehicle['constraint_message'] = "この車両は{$rental_service}に対応していません";
        }
        
        $vehicle['workload'] = calculateWorkload($vehicle['reservations_count']);
        $vehicle['reserved_times_array'] = $vehicle['reserved_times'] ? explode(',', $vehicle['reserved_times']) : [];
    }
    
    return $vehicles;
}

/**
 * 時間帯別空き状況取得
 */
function getHourlyAvailability($date, $exclude_id = null) {
    global $pdo;
    
    $sql = "
        SELECT 
            HOUR(r.reservation_time) as hour,
            COUNT(DISTINCT r.driver_id) as busy_drivers,
            COUNT(DISTINCT r.vehicle_id) as busy_vehicles,
            COUNT(r.id) as total_reservations
        FROM reservations r
        WHERE r.reservation_date = ? 
            AND r.status != 'キャンセル'";
    
    $params = [$date];
    
    if ($exclude_id) {
        $sql .= " AND r.id != ?";
        $params[] = $exclude_id;
    }
    
    $sql .= " GROUP BY HOUR(r.reservation_time) ORDER BY hour";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $hourly_data = $stmt->fetchAll();
    
    // 総運転者数・車両数取得
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_driver = 1 AND is_active = 1");
    $total_drivers = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1");
    $total_vehicles = $stmt->fetchColumn();
    
    // 8時-18時の時間帯別データ作成
    $availability = [];
    for ($hour = 8; $hour <= 18; $hour++) {
        $hour_data = array_filter($hourly_data, function($data) use ($hour) {
            return $data['hour'] == $hour;
        });
        
        $busy_drivers = !empty($hour_data) ? reset($hour_data)['busy_drivers'] : 0;
        $busy_vehicles = !empty($hour_data) ? reset($hour_data)['busy_vehicles'] : 0;
        $total_reservations = !empty($hour_data) ? reset($hour_data)['total_reservations'] : 0;
        
        $availability[] = [
            'hour' => $hour,
            'time_slot' => sprintf('%02d:00-%02d:00', $hour, $hour + 1),
            'available_drivers' => $total_drivers - $busy_drivers,
            'available_vehicles' => $total_vehicles - $busy_vehicles,
            'total_reservations' => $total_reservations,
            'congestion_level' => calculateCongestionLevel($total_reservations, $total_drivers)
        ];
    }
    
    return $availability;
}

/**
 * 時間スロット使用中チェック
 */
function isTimeSlotOccupied($reserved_times_string, $target_time) {
    if (!$reserved_times_string) {
        return false;
    }
    
    $reserved_times = explode(',', $reserved_times_string);
    $target_timestamp = strtotime($target_time);
    
    foreach ($reserved_times as $reserved_time) {
        $reserved_timestamp = strtotime(trim($reserved_time));
        
        // 1時間の余裕を見る（前後30分）
        $buffer = 30 * 60; // 30分 = 1800秒
        
        if (abs($target_timestamp - $reserved_timestamp) < $buffer) {
            return true;
        }
    }
    
    return false;
}

/**
 * レンタルサービス対応チェック
 */
function checkRentalServiceSupport($vehicle_model, $rental_service) {
    // ストレッチャーはハイエースのみ
    if ($rental_service === 'ストレッチャー') {
        return $vehicle_model === 'ハイエース';
    }
    
    // 車いす・リクライニングは全車両対応
    return true;
}

/**
 * 作業負荷計算
 */
function calculateWorkload($reservations_count) {
    if ($reservations_count == 0) return '軽';
    if ($reservations_count <= 3) return '普通';
    if ($reservations_count <= 6) return '多';
    return '過多';
}

/**
 * 混雑レベル計算
 */
function calculateCongestionLevel($reservations_count, $total_drivers) {
    if ($reservations_count == 0) return '空き';
    
    $ratio = $reservations_count / $total_drivers;
    
    if ($ratio < 0.5) return '空き';
    if ($ratio < 0.8) return '普通';
    if ($ratio < 1.0) return '混雑';
    return '満車';
}

/**
 * 推奨配車生成
 */
function generateRecommendations($drivers, $vehicles, $time) {
    $recommendations = [];
    
    // 最適な運転者推奨
    $available_drivers = array_filter($drivers, function($d) { return $d['is_available']; });
    usort($available_drivers, function($a, $b) {
        return $a['reservations_count'] - $b['reservations_count'];
    });
    
    // 最適な車両推奨
    $available_vehicles = array_filter($vehicles, function($v) { 
        return $v['is_available'] && $v['supports_rental_service']; 
    });
    usort($available_vehicles, function($a, $b) {
        return $a['reservations_count'] - $b['reservations_count'];
    });
    
    if (!empty($available_drivers)) {
        $recommendations['best_driver'] = [
            'id' => $available_drivers[0]['id'],
            'name' => $available_drivers[0]['full_name'],
            'reason' => '作業負荷が最も軽い運転者です'
        ];
    }
    
    if (!empty($available_vehicles)) {
        $recommendations['best_vehicle'] = [
            'id' => $available_vehicles[0]['id'],
            'vehicle_number' => $available_vehicles[0]['vehicle_number'],
            'model' => $available_vehicles[0]['model'],
            'reason' => '使用頻度が最も低い車両です'
        ];
    }
    
    // 混雑回避推奨
    $time_hour = intval(date('H', strtotime($time)));
    if (in_array($time_hour, [9, 12, 15])) { // 混雑しやすい時間帯
        $recommendations['time_suggestion'] = [
            'alternative_times' => [
                date('H:i', strtotime($time . ' +30 minutes')),
                date('H:i', strtotime($time . ' -30 minutes'))
            ],
            'reason' => 'この時間帯は混雑しやすいため、前後の時間もご検討ください'
        ];
    }
    
    return $recommendations;
}
?>
