<?php
/**
 * 乗務記録の内訳取得API
 * GET: driver_id, start_date, end_date を指定して乗車記録を取得
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getDBConnection();

    $driver_id = $_GET['driver_id'] ?? '';
    $start_date = $_GET['start_date'] ?? date('Y-m-d');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');

    if (!$driver_id) {
        throw new Exception('driver_id は必須です');
    }

    $sql = "SELECT
                r.id,
                r.ride_date,
                r.ride_time,
                r.pickup_location,
                r.dropoff_location,
                r.passenger_count,
                COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0)) as total_fare,
                r.fare,
                COALESCE(r.charge, 0) as charge,
                r.payment_method,
                COALESCE(r.cash_amount, 0) as cash_amount,
                COALESCE(r.card_amount, 0) as card_amount
            FROM ride_records r
            WHERE r.driver_id = ?
              AND r.ride_date BETWEEN ? AND ?
            ORDER BY r.ride_date, r.ride_time";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$driver_id, $start_date, $end_date]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'records' => $records,
        'count' => count($records)
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
