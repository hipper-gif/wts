<?php
// =================================================================
// 顧客保存API
//
// ファイル: /Smiley/taxi/wts/calendar/api/save_customer.php
// 機能: 顧客マスタの新規作成・更新
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

    if (!$input) {
        sendErrorResponse('無効なJSONデータです');
    }

    // 必須項目チェック
    if (empty($input['name'])) {
        sendErrorResponse('必須項目が不足しています: name');
    }

    // 移動形態バリデーション
    $allowed_mobility = ['independent', 'wheelchair', 'stretcher', 'walker'];
    $mobility_type = $input['mobility_type'] ?? 'independent';
    if (!in_array($mobility_type, $allowed_mobility, true)) {
        sendErrorResponse('無効な移動形態です。許可値: ' . implode(', ', $allowed_mobility));
    }

    // ユーザーID取得
    $user_id = $_SESSION['user_id'];

    // データ準備
    $customer_data = [
        'name' => trim($input['name']),
        'name_kana' => trim($input['name_kana'] ?? ''),
        'phone' => trim($input['phone'] ?? ''),
        'phone_secondary' => trim($input['phone_secondary'] ?? ''),
        'email' => trim($input['email'] ?? ''),
        'postal_code' => trim($input['postal_code'] ?? ''),
        'address' => trim($input['address'] ?? ''),
        'address_detail' => trim($input['address_detail'] ?? ''),
        'care_level' => trim($input['care_level'] ?? ''),
        'disability_type' => trim($input['disability_type'] ?? ''),
        'mobility_type' => $mobility_type,
        'wheelchair_type' => trim($input['wheelchair_type'] ?? ''),
        'default_pickup_location' => trim($input['default_pickup_location'] ?? ''),
        'default_dropoff_location' => trim($input['default_dropoff_location'] ?? ''),
        'emergency_contact_name' => trim($input['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => trim($input['emergency_contact_phone'] ?? ''),
        'notes' => trim($input['notes'] ?? ''),
    ];

    // トランザクション開始
    $pdo->beginTransaction();

    if (!empty($input['id'])) {
        // ===== 既存顧客更新 =====
        $customer_id = intval($input['id']);

        // 既存データ確認
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND is_active = 1");
        $stmt->execute([$customer_id]);
        $old_data = $stmt->fetch();

        if (!$old_data) {
            throw new Exception('更新対象の顧客が見つかりません');
        }

        // 更新実行
        $update_fields = [];
        $update_params = [];

        foreach ($customer_data as $field => $value) {
            $update_fields[] = "{$field} = ?";
            $update_params[] = $value;
        }

        $update_params[] = $customer_id;

        $sql = "UPDATE customers SET " . implode(', ', $update_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($update_params);

        // 操作ログ記録
        logCustomerAction($user_id, 'edit', 'customer', $customer_id, $old_data, $customer_data);

        $message = '顧客情報を更新しました';

    } else {
        // ===== 新規顧客作成 =====
        $customer_data['created_by'] = $user_id;

        $fields = array_keys($customer_data);
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';

        $sql = "INSERT INTO customers (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($customer_data));

        $customer_id = $pdo->lastInsertId();

        // 操作ログ記録
        logCustomerAction($user_id, 'create', 'customer', $customer_id, null, $customer_data);

        $message = '顧客を登録しました';
    }

    // よく使う行き先の保存（指定がある場合）
    if (isset($input['frequent_destinations']) && is_array($input['frequent_destinations'])) {
        // 既存の行き先を削除して再登録
        $stmt = $pdo->prepare("DELETE FROM frequent_destinations WHERE customer_id = ?");
        $stmt->execute([$customer_id]);

        $allowed_location_types = ['hospital', 'dialysis', 'facility', 'home', 'other'];

        foreach ($input['frequent_destinations'] as $index => $dest) {
            if (empty($dest['location_name'])) {
                continue;
            }

            $location_type = $dest['location_type'] ?? 'other';
            if (!in_array($location_type, $allowed_location_types, true)) {
                $location_type = 'other';
            }

            $stmt = $pdo->prepare("
                INSERT INTO frequent_destinations (customer_id, location_name, address, location_type, sort_order, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $customer_id,
                trim($dest['location_name']),
                trim($dest['address'] ?? ''),
                $location_type,
                intval($dest['sort_order'] ?? $index),
                trim($dest['notes'] ?? '')
            ]);
        }
    }

    // トランザクションコミット
    $pdo->commit();

    // 保存された顧客データを取得して返す
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $saved_customer = $stmt->fetch();

    // よく使う行き先も取得
    $stmt = $pdo->prepare("
        SELECT id, location_name, address, location_type, sort_order, notes
        FROM frequent_destinations
        WHERE customer_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$customer_id]);
    $saved_customer['frequent_destinations'] = $stmt->fetchAll();

    // レスポンス送信
    sendSuccessResponse([
        'customer_id' => $customer_id,
        'customer' => $saved_customer
    ], $message);

} catch (Exception $e) {
    // トランザクションロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("顧客保存エラー: " . $e->getMessage());
    sendErrorResponse('顧客保存中にエラーが発生しました');
}

/**
 * 顧客操作ログ記録（統一関数のラッパー）
 */
function logCustomerAction($user_id, $action, $target_type, $target_id, $old_data = null, $new_data = null) {
    logCalendarAudit($user_id, $action, $target_type, $target_id, $old_data, $new_data);
}
?>
