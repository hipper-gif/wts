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

// Admin権限チェック
if ($_SESSION['permission_level'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '管理者権限が必要です']);
    exit;
}

// POSTメソッドチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドが必要です']);
    exit;
}

// CSRF検証
validateCsrfToken();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    // $_POSTからもフォールバック
    if ($id <= 0) {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    }

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '有効なIDが指定されていません']);
        exit;
    }

    $pdo = getDBConnection();

    // 存在確認
    $stmt = $pdo->prepare("SELECT id FROM documents WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '書類が見つかりません']);
        exit;
    }

    // 論理削除 (物理ファイルは残す)
    $stmt = $pdo->prepare("UPDATE documents SET is_active = 0, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("document_delete error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}
