<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/session_check.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

// GETメソッドチェック
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'GETメソッドが必要です']);
    exit;
}

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '有効なIDが指定されていません']);
        exit;
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '書類が見つかりません']);
        exit;
    }

    // ファイルパス解決
    $filePath = dirname(__DIR__) . '/' . $doc['file_path'];
    $realPath = realpath($filePath);
    $uploadsDir = realpath(dirname(__DIR__) . '/uploads/');

    // ディレクトリトラバーサル防止
    if ($realPath === false || $uploadsDir === false || strpos($realPath, $uploadsDir) !== 0) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'ファイルへのアクセスが拒否されました']);
        exit;
    }

    if (!file_exists($realPath)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'ファイルが見つかりません']);
        exit;
    }

    // ダウンロードヘッダー設定
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: attachment; filename="' . $doc['original_filename'] . '"');
    header('Content-Length: ' . filesize($realPath));
    header('Cache-Control: no-cache, must-revalidate');

    readfile($realPath);
    exit;

} catch (Exception $e) {
    error_log("document_download error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}
