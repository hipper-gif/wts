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

    // 権限チェック: 管理者以外は自分が作成した予約のみ削除可能
    if ($_SESSION['user_role'] !== 'admin' && $old_data['created_by'] != $_SESSION['user_id']) {
        sendErrorResponse('この予約を削除する権限がありません', 403);
    }

    // トランザクション開始
    $pdo->beginTransaction();

    // 復路が存在する場合は先に削除
    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE parent_reservation_id = ?");
    $stmt->execute([$reservation_id]);
    $child_reservations = $stmt->fetchAll();

    foreach ($child_reservations as $child) {
        $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$child['id']]);
        logCalendarAction($user_id, 'delete', 'reservation', $child['id'], null, null);
    }

    // 本体削除
    $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);

    // 操作ログ記録
    logCalendarAction($user_id, 'delete', 'reservation', $reservation_id, $old_data, null);

    // トランザクションコミット
    $pdo->commit();

    // レスポンス送信
    $deleted_children_count = count($child_reservations);
    $msg = $deleted_children_count > 0
        ? '予約と復路（' . $deleted_children_count . '件）を削除しました'
        : '予約を削除しました';
    sendSuccessResponse(['deleted_children_count' => $deleted_children_count], $msg);

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
