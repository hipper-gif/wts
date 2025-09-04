<?php
session_start();
header('Content-Type: application/json');

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベース接続エラー']);
    exit;
}

// IDパラメータチェック
$id = $_GET['id'] ?? '';
if (empty($id) || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '有効なIDが指定されていません']);
    exit;
}

try {
    // 売上金確認詳細データ取得
    $stmt = $pdo->prepare("
        SELECT cm.*, u.name as driver_name 
        FROM cash_management cm
        JOIN users u ON cm.driver_id = u.id
        WHERE cm.id = ?
    ");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    
    if (!$record) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'データが見つかりません']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $record
    ]);
    
} catch (Exception $e) {
    error_log("Cash detail API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}
?>
