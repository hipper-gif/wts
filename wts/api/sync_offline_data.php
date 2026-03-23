<?php
// api/sync_offline_data.php - オフラインデータ一括同期API
// オフライン中にlocalStorageに蓄積されたデータをサーバーに同期

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/session_check.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => '認証が必要です']));
}

validateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'POSTメソッドが必要です']));
}

try {
    $pdo = getDBConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['entries']) || !is_array($input['entries'])) {
        throw new Exception('同期データが不正です');
    }

    $driver_id = (int)$_SESSION['user_id'];
    $results   = [];
    $synced    = 0;
    $failed    = 0;

    foreach ($input['entries'] as $i => $entry) {
        try {
            $date = $entry['confirmation_date'] ?? null;
            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $results[] = ['index' => $i, 'status' => 'skipped', 'reason' => '日付不正'];
                $failed++;
                continue;
            }

            $bill_10000   = max(0, (int)($entry['bill_10000'] ?? 0));
            $bill_5000    = max(0, (int)($entry['bill_5000'] ?? 0));
            $bill_1000    = max(0, (int)($entry['bill_1000'] ?? 0));
            $coin_500     = max(0, (int)($entry['coin_500'] ?? 0));
            $coin_100     = max(0, (int)($entry['coin_100'] ?? 0));
            $coin_50      = max(0, (int)($entry['coin_50'] ?? 0));
            $coin_10      = max(0, (int)($entry['coin_10'] ?? 0));
            $total_amount = max(0, (int)($entry['total_amount'] ?? 0));
            $memo         = $entry['memo'] ?? '';

            $pdo->beginTransaction();

            // UPSERT: 既存なら更新、なければ挿入
            $check = $pdo->prepare("SELECT id FROM cash_count_details WHERE confirmation_date = ? AND driver_id = ?");
            $check->execute([$date, $driver_id]);
            $existing = $check->fetch();

            if ($existing) {
                $stmt = $pdo->prepare("
                    UPDATE cash_count_details SET
                        bill_10000 = ?, bill_5000 = ?, bill_1000 = ?,
                        coin_500 = ?, coin_100 = ?, coin_50 = ?, coin_10 = ?,
                        total_amount = ?, memo = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE confirmation_date = ? AND driver_id = ?
                ");
                $stmt->execute([
                    $bill_10000, $bill_5000, $bill_1000,
                    $coin_500, $coin_100, $coin_50, $coin_10,
                    $total_amount, $memo, $date, $driver_id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cash_count_details (
                        confirmation_date, driver_id,
                        bill_10000, bill_5000, bill_2000, bill_1000,
                        coin_500, coin_100, coin_50, coin_10, coin_5, coin_1,
                        total_amount, memo, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0, 0, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    $date, $driver_id,
                    $bill_10000, $bill_5000, $bill_1000,
                    $coin_500, $coin_100, $coin_50, $coin_10,
                    $total_amount, $memo
                ]);
            }

            $pdo->commit();
            $results[] = ['index' => $i, 'status' => 'synced', 'date' => $date];
            $synced++;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $results[] = ['index' => $i, 'status' => 'failed', 'reason' => 'DB error'];
            $failed++;
            error_log("sync_offline_data entry {$i} error: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'synced'  => $synced,
        'failed'  => $failed,
        'results' => $results
    ]);

} catch (Exception $e) {
    error_log("sync_offline_data error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラー']);
}
?>
