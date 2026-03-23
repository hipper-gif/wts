<?php
// =================================================================
// 顧客予約履歴取得API
//
// ファイル: /Smiley/taxi/wts/calendar/api/get_customer_history.php
// 機能: 顧客の過去予約履歴を取得（ページネーション対応）
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2026年3月11日
// =================================================================

header('Content-Type: application/json; charset=utf-8');

// 基盤システム読み込み
require_once '../../config/database.php';
require_once '../includes/calendar_functions.php';
require_once dirname(__DIR__, 2) . '/includes/session_check.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    sendErrorResponse('認証が必要です', 401);
}

// データベース接続
$pdo = getDBConnection();

try {
    // パラメータ取得
    $customer_id = $_GET['customer_id'] ?? null;

    if (!$customer_id) {
        sendErrorResponse('顧客IDが必要です');
    }

    $customer_id = intval($customer_id);
    $page = max(1, min(10000, intval($_GET['page'] ?? 1)));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // 顧客存在チェック
    $stmt = $pdo->prepare("SELECT id, name, name_kana FROM customers WHERE id = ? AND is_active = 1");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        sendErrorResponse('顧客が見つかりません', 404);
    }

    // 総件数取得
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $total = intval($stmt->fetchColumn());

    // 予約履歴取得（日付降順）
    $stmt = $pdo->prepare("
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
            r.payment_method,
            r.special_notes,
            r.driver_id,
            r.vehicle_id,
            r.created_at,
            u.name as driver_name,
            v.vehicle_number,
            v.model as vehicle_model
        FROM reservations r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.customer_id = ?
        ORDER BY r.reservation_date DESC, r.reservation_time DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$customer_id, $limit, $offset]);
    $reservations = $stmt->fetchAll();

    // HTMLエスケープ
    foreach ($reservations as &$reservation) {
        $string_fields = [
            'client_name', 'pickup_location', 'dropoff_location',
            'service_type', 'rental_service', 'status',
            'payment_method', 'special_notes',
            'driver_name', 'vehicle_number', 'vehicle_model'
        ];
        foreach ($string_fields as $field) {
            if (isset($reservation[$field]) && $reservation[$field] !== null) {
                $reservation[$field] = htmlspecialchars($reservation[$field], ENT_QUOTES, 'UTF-8');
            }
        }
    }
    unset($reservation);

    // レスポンス送信
    sendSuccessResponse([
        'customer' => [
            'id' => $customer['id'],
            'name' => htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'),
            'name_kana' => htmlspecialchars($customer['name_kana'] ?? '', ENT_QUOTES, 'UTF-8')
        ],
        'reservations' => $reservations,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ]
    ]);

} catch (Exception $e) {
    error_log("顧客予約履歴取得エラー: " . $e->getMessage());
    sendErrorResponse('データ取得中にエラーが発生しました');
}
?>
