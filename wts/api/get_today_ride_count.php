<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once dirname(__DIR__) . '/includes/session_check.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'ログインが必要です']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'GETメソッドが必要です']));
}

try {
    $pdo = getDBConnection();
    $user_id = (int)$_SESSION['user_id'];
    $today   = date('Y-m-d');

    // 管理者は全ドライバーの合計、一般ユーザーは自分の分のみ
    $user_stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();
    $is_admin = ($user && $user['permission_level'] === 'Admin');

    if ($is_admin) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records WHERE ride_date = ?");
        $stmt->execute([$today]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records WHERE ride_date = ? AND driver_id = ?");
        $stmt->execute([$today, $user_id]);
    }

    $count = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'count' => $count, 'date' => $today]);

} catch (Exception $e) {
    error_log("get_today_ride_count error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データ取得エラー']);
}
?>
