<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/session_check.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

try {
    $pdo = getDBConnection();

    // 期限切れ
    $stmtExpired = $pdo->prepare("
        SELECT id, title, category, expiry_date, related_driver_id, related_vehicle_id
        FROM documents
        WHERE is_active = 1
          AND expiry_date IS NOT NULL
          AND expiry_date < CURDATE()
        ORDER BY expiry_date ASC
    ");
    $stmtExpired->execute();
    $expired = $stmtExpired->fetchAll(PDO::FETCH_ASSOC);

    // 7日以内に期限切れ
    $stmt7days = $pdo->prepare("
        SELECT id, title, category, expiry_date, related_driver_id, related_vehicle_id
        FROM documents
        WHERE is_active = 1
          AND expiry_date IS NOT NULL
          AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY expiry_date ASC
    ");
    $stmt7days->execute();
    $expiring7days = $stmt7days->fetchAll(PDO::FETCH_ASSOC);

    // 7日〜30日以内に期限切れ
    $stmt30days = $pdo->prepare("
        SELECT id, title, category, expiry_date, related_driver_id, related_vehicle_id
        FROM documents
        WHERE is_active = 1
          AND expiry_date IS NOT NULL
          AND expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY expiry_date ASC
    ");
    $stmt30days->execute();
    $expiring30days = $stmt30days->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'alerts' => [
            'expired' => $expired,
            'expiring_7days' => $expiring7days,
            'expiring_30days' => $expiring30days,
        ],
        'counts' => [
            'expired' => count($expired),
            'expiring_7days' => count($expiring7days),
            'expiring_30days' => count($expiring30days),
        ]
    ]);

} catch (Exception $e) {
    error_log("document_alerts error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}
