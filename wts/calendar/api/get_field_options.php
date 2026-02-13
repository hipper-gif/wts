<?php
// =================================================================
// 予約項目カスタマイズ 取得API
// =================================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../../config/database.php';
require_once '../includes/calendar_functions.php';

if (!isset($_SESSION['user_id'])) {
    sendErrorResponse('認証が必要です', 401);
}

$pdo = getDBConnection();

try {
    $field_name = $_GET['field_name'] ?? null;

    $sql = "SELECT id, field_name, option_value, option_label, sort_order, is_active
            FROM reservation_field_options";
    $params = [];

    if ($field_name) {
        $sql .= " WHERE field_name = ?";
        $params[] = $field_name;
    }

    $sql .= " ORDER BY field_name, sort_order, id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // フィールド名ごとにグループ化
    $grouped = [];
    foreach ($options as $opt) {
        $grouped[$opt['field_name']][] = $opt;
    }

    sendSuccessResponse($grouped);

} catch (Exception $e) {
    error_log("項目取得エラー: " . $e->getMessage());
    sendErrorResponse('項目データの取得に失敗しました');
}
?>
