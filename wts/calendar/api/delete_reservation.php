<?php
// =================================================================
// 予約削除API
//
// ファイル: /Smiley/taxi/wts/calendar/api/delete_reservation.php
// 機能: 予約データの削除
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年11月19日
// =================================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

// 基盤システム読み込み
require_once '../../config/database.php';
require_once '../includes/calendar_functions.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    sendErrorResponse('認証が必要です', 401);
}

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
        sendErrorResponse('予約IDが必要です');
    }

    $reservation_id = intval($input['id']);
    $user_id = $_SESSION['user_id'];

    // 既存データ取得（ログ用）
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);
    $old_data = $stmt->fetch();

    if (!$old_data) {
        sendErrorResponse('予約が見つかりません');
    }

    // トランザクション開始
    $pdo->beginTransaction();

    // 削除実行
    $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);

    // 操作ログ記録
    logCalendarAction($user_id, 'delete', 'reservation', $reservation_id, $old_data, null);

    // トランザクションコミット
    $pdo->commit();

    // レスポンス送信
    sendSuccessResponse([], '予約を削除しました');

} catch (Exception $e) {
    // トランザクションロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("予約削除エラー: " . $e->getMessage());
    sendErrorResponse('予約削除中にエラーが発生しました: ' . $e->getMessage());
}

/**
 * カレンダー操作ログ記録
 */
function logCalendarAction($user_id, $action, $target_type, $target_id, $old_data = null, $new_data = null) {
    global $pdo;

    try {
        $user_type = 'user';

        $sql = "
            INSERT INTO calendar_audit_logs
            (user_id, user_type, action, target_type, target_id, old_data, new_data, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $user_type,
            $action,
            $target_type,
            $target_id,
            $old_data ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null,
            $new_data ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

    } catch (Exception $e) {
        error_log("カレンダーログ記録エラー: " . $e->getMessage());
    }
}
?>
