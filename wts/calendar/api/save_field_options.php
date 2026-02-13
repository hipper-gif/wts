<?php
// =================================================================
// 予約項目カスタマイズ 保存API
// =================================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../../config/database.php';
require_once '../includes/calendar_functions.php';

if (!isset($_SESSION['user_id'])) {
    sendErrorResponse('認証が必要です', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('POSTメソッドが必要です', 405);
}

$pdo = getDBConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['action'])) {
        sendErrorResponse('無効なリクエストです');
    }

    $action = $input['action'];

    switch ($action) {
        case 'add':
            // 新規追加
            if (empty($input['field_name']) || empty($input['option_value'])) {
                sendErrorResponse('フィールド名と選択肢の値は必須です');
            }

            $label = $input['option_label'] ?? $input['option_value'];

            // 最大sort_order取得
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM reservation_field_options WHERE field_name = ?");
            $stmt->execute([$input['field_name']]);
            $next_order = $stmt->fetch()['next_order'];

            $stmt = $pdo->prepare("INSERT INTO reservation_field_options (field_name, option_value, option_label, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$input['field_name'], $input['option_value'], $label, $next_order]);

            sendSuccessResponse(['id' => $pdo->lastInsertId()], '選択肢を追加しました');
            break;

        case 'update':
            // 更新
            if (empty($input['id'])) {
                sendErrorResponse('IDが必要です');
            }

            $sets = [];
            $params = [];

            if (isset($input['option_label'])) {
                $sets[] = "option_label = ?";
                $params[] = $input['option_label'];
            }
            if (isset($input['option_value'])) {
                $sets[] = "option_value = ?";
                $params[] = $input['option_value'];
            }
            if (isset($input['sort_order'])) {
                $sets[] = "sort_order = ?";
                $params[] = intval($input['sort_order']);
            }
            if (isset($input['is_active'])) {
                $sets[] = "is_active = ?";
                $params[] = intval($input['is_active']);
            }

            if (empty($sets)) {
                sendErrorResponse('更新項目がありません');
            }

            $params[] = intval($input['id']);
            $sql = "UPDATE reservation_field_options SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            sendSuccessResponse([], '選択肢を更新しました');
            break;

        case 'delete':
            // 削除
            if (empty($input['id'])) {
                sendErrorResponse('IDが必要です');
            }

            $stmt = $pdo->prepare("DELETE FROM reservation_field_options WHERE id = ?");
            $stmt->execute([intval($input['id'])]);

            sendSuccessResponse([], '選択肢を削除しました');
            break;

        case 'reorder':
            // 並び替え
            if (empty($input['items']) || !is_array($input['items'])) {
                sendErrorResponse('並び替えデータが必要です');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE reservation_field_options SET sort_order = ? WHERE id = ?");

            foreach ($input['items'] as $index => $id) {
                $stmt->execute([$index + 1, intval($id)]);
            }

            $pdo->commit();
            sendSuccessResponse([], '並び順を更新しました');
            break;

        default:
            sendErrorResponse('不明なアクションです');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("項目保存エラー: " . $e->getMessage());
    sendErrorResponse('保存に失敗しました: ' . $e->getMessage());
}
?>
