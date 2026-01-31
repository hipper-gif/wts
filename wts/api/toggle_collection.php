<?php
/**
 * 集金処理チェックのトグルAPI
 * POST: 集金済み/未集金を切り替え
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です。ログインし直してください。']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドのみ許可']);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new Exception('データベース接続に失敗しました');
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSONパースエラー: ' . json_last_error_msg() . ' | 入力: ' . substr($input, 0, 200));
    }

    $driver_id = (int)($data['driver_id'] ?? 0);
    $collection_date = $data['collection_date'] ?? '';

    if (!$driver_id || !$collection_date) {
        throw new Exception('driver_id(' . $driver_id . ') と collection_date(' . $collection_date . ') は必須です');
    }

    // テーブル存在確認・自動作成
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
    $stmt = $pdo->prepare("
        SELECT id, is_collected FROM cash_collections
        WHERE driver_id = ? AND collection_date = ?
    ");
    $stmt->execute([$driver_id, $collection_date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // トグル: 既存レコードの is_collected を反転
        $new_status = $existing['is_collected'] ? 0 : 1;
        $stmt = $pdo->prepare("
            UPDATE cash_collections
            SET is_collected = ?, collected_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$new_status, $_SESSION['user_id'], $existing['id']]);
    } else {
        // 新規: 集金済みとして登録
        $new_status = 1;
        $stmt = $pdo->prepare("
            INSERT INTO cash_collections (driver_id, collection_date, is_collected, collected_by)
            VALUES (?, ?, 1, ?)
        ");
        $stmt->execute([$driver_id, $collection_date, $_SESSION['user_id']]);
    }

    echo json_encode([
        'success' => true,
        'is_collected' => (bool)$new_status,
        'driver_id' => $driver_id,
        'collection_date' => $collection_date
    ]);

} catch (PDOException $e) {
    error_log('toggle_collection DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('toggle_collection error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
