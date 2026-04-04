<?php
// =================================================================
// 顧客削除API（論理削除）
//
// ファイル: /Smiley/taxi/wts/calendar/api/delete_customer.php
// 機能: 顧客マスタの論理削除（is_active = 0）
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2026年3月11日
// =================================================================

header('Content-Type: application/json; charset=utf-8');

// 基盤システム読み込み
require_once '../../config/database.php';
require_once '../includes/calendar_functions.php';
require_once dirname(__DIR__, 2) . '/includes/session_check.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    sendErrorResponse('認証が必要です', 401);
}

// CSRF検証
validateCsrfToken();

// POSTメソッドチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('POSTメソッドが必要です', 405);
}

// データベース接続
$pdo = getDBConnection();

try {
    // 入力データ取得
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['id'])) {
        sendErrorResponse('顧客IDが必要です');
    }

    $customer_id = intval($input['id']);
    $user_id = $_SESSION['user_id'];

    // 既存データ取得（ログ用）
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND is_active = 1");
    $stmt->execute([$customer_id]);
    $old_data = $stmt->fetch();

    if (!$old_data) {
        sendErrorResponse('顧客が見つかりません', 404);
    }

    // トランザクション開始
    $pdo->beginTransaction();

    // 論理削除（is_active = 0）
    $stmt = $pdo->prepare("UPDATE customers SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$customer_id]);

    // 操作ログ記録
    logCustomerAction($user_id, 'delete', 'customer', $customer_id, $old_data, null);

    // トランザクションコミット
    $pdo->commit();

    // レスポンス送信
    sendSuccessResponse(['customer_id' => $customer_id], '顧客を削除しました');

} catch (Exception $e) {
    // トランザクションロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("顧客削除エラー: " . $e->getMessage());
    sendErrorResponse('顧客削除中にエラーが発生しました');
}

/**
 * 顧客操作ログ記録（統一関数のラッパー）
 */
function logCustomerAction($user_id, $action, $target_type, $target_id, $old_data = null, $new_data = null) {
    logCalendarAudit($user_id, $action, $target_type, $target_id, $old_data, $new_data);
}
?>
