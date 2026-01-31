<?php
// 集金処理チェックのトグルAPI
// cash_collectionsテーブルの集金済み/未集金を切り替え

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

// POSTメソッドのみ
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドのみ許可']);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getDBConnection();

    // JSONデータ取得
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception('入力データが空です');
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSONパースエラー: ' . json_last_error_msg());
    }

    $driver_id = (int)($data['driver_id'] ?? 0);
    $collection_date = $data['collection_date'] ?? '';

    if (!$driver_id || !$collection_date) {
        throw new Exception('driver_id と collection_date は必須です');
    }

    // テーブル自動作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cash_collections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            driver_id INT NOT NULL,
            collection_date DATE NOT NULL,
            is_collected TINYINT(1) NOT NULL DEFAULT 1,
            collected_by INT NULL,
            memo TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_driver_date (driver_id, collection_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 現在の状態を確認
    $check_stmt = $pdo->prepare("
        SELECT id, is_collected FROM cash_collections
        WHERE driver_id = ? AND collection_date = ?
    ");
    $check_stmt->execute([$driver_id, $collection_date]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $new_status = $existing['is_collected'] ? 0 : 1;
        $update_stmt = $pdo->prepare("
            UPDATE cash_collections
            SET is_collected = ?, collected_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_stmt->execute([$new_status, $_SESSION['user_id'], $existing['id']]);
    } else {
        $new_status = 1;
        $insert_stmt = $pdo->prepare("
            INSERT INTO cash_collections (driver_id, collection_date, is_collected, collected_by)
            VALUES (?, ?, 1, ?)
        ");
        $insert_stmt->execute([$driver_id, $collection_date, $_SESSION['user_id']]);
    }

    echo json_encode([
        'success' => true,
        'is_collected' => (bool)$new_status,
        'driver_id' => $driver_id,
        'collection_date' => $collection_date
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
