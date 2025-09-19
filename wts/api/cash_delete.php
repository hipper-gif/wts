<?php
// 最小限版: api/cash_delete.php（緊急回避策）

session_start();
header('Content-Type: application/json');

try {
    // 基本チェック
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '認証が必要です']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POSTメソッドが必要です']);
        exit;
    }

    // データ取得
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id) || !is_numeric($id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '有効なIDが指定されていません']);
        exit;
    }

    // データベース接続（最もシンプル）
    $dsn = "mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4";
    $pdo = new PDO($dsn, 'twinklemark_taxi', 'Smiley2525', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 権限チェック（簡略化）
    $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || $user['permission_level'] !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '削除権限がありません']);
        exit;
    }

    // データ存在確認
    $stmt = $pdo->prepare("SELECT id, confirmation_date FROM cash_management WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    
    if (!$record) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'データが見つかりません（ID: ' . $id . '）']);
        exit;
    }

    // 削除実行（cash_managementテーブル）
    $stmt = $pdo->prepare("DELETE FROM cash_management WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true, 
            'message' => '売上金確認記録を削除しました',
            'deleted_id' => $id,
            'deleted_date' => $record['confirmation_date']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '削除に失敗しました']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'エラー: ' . $e->getMessage()]);
}
?>
