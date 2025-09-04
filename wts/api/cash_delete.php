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

// 権限チェック（管理者のみ削除可能）
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || $user['permission_level'] !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '削除権限がありません']);
        exit;
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベース接続エラー']);
    exit;
}

// リクエストメソッドチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドが必要です']);
    exit;
}

// リクエストデータ取得
$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? '';

if (empty($id) || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '有効なIDが指定されていません']);
    exit;
}

try {
    // 削除前にデータが存在することを確認
    $stmt = $pdo->prepare("SELECT id FROM cash_management WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'データが見つかりません']);
        exit;
    }
    
    // 削除実行
    $stmt = $pdo->prepare("DELETE FROM cash_management WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => '売上金確認記録を削除しました'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '削除に失敗しました']);
    }
    
} catch (Exception $e) {
    error_log("Cash delete API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}
?>
