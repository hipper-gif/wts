<?php
// =================================================================
// 顧客データ取得API
//
// ファイル: /Smiley/taxi/wts/calendar/api/get_customers.php
// 機能: 顧客マスタ検索・一覧取得・単一取得
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

// データベース接続
$pdo = getDBConnection();

try {
    // パラメータ取得
    $id = $_GET['id'] ?? null;
    $q = $_GET['q'] ?? null;
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if ($id) {
        // ===== 単一顧客取得（ID指定） =====
        $customer_id = intval($id);

        $stmt = $pdo->prepare("
            SELECT * FROM customers WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();

        if (!$customer) {
            sendErrorResponse('顧客が見つかりません', 404);
        }

        // よく使う行き先を取得
        $stmt = $pdo->prepare("
            SELECT id, location_name, address, location_type, sort_order, notes
            FROM frequent_destinations
            WHERE customer_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$customer_id]);
        $destinations = $stmt->fetchAll();

        $customer['frequent_destinations'] = $destinations;

        // HTMLエスケープ
        $customer = escapeCustomer($customer);

        sendSuccessResponse($customer);

    } elseif ($q) {
        // ===== オートコンプリート検索 =====
        $search_term = '%' . $q . '%';

        $stmt = $pdo->prepare("
            SELECT id, name, name_kana, phone, address,
                   care_level, mobility_type, default_pickup_location, default_dropoff_location
            FROM customers
            WHERE is_active = 1
              AND (name LIKE ? OR name_kana LIKE ? OR phone LIKE ?)
            ORDER BY name_kana ASC
            LIMIT 10
        ");
        $stmt->execute([$search_term, $search_term, $search_term]);
        $customers = $stmt->fetchAll();

        // 各顧客のよく使う行き先も取得
        foreach ($customers as &$customer) {
            $stmt = $pdo->prepare("
                SELECT id, location_name, address, location_type, sort_order, notes
                FROM frequent_destinations
                WHERE customer_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute([$customer['id']]);
            $customer['frequent_destinations'] = $stmt->fetchAll();

            $customer = escapeCustomer($customer);
        }
        unset($customer);

        sendSuccessResponse($customers);

    } else {
        // ===== 全アクティブ顧客一覧（ページネーション付き） =====

        // 総件数取得
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE is_active = 1");
        $stmt->execute();
        $total = intval($stmt->fetchColumn());

        // データ取得
        $stmt = $pdo->prepare("
            SELECT id, name, name_kana, phone, phone_secondary, email,
                   postal_code, address, address_detail,
                   care_level, disability_type, mobility_type, wheelchair_type,
                   default_pickup_location, default_dropoff_location,
                   emergency_contact_name, emergency_contact_phone,
                   notes, created_at, updated_at
            FROM customers
            WHERE is_active = 1
            ORDER BY name_kana ASC, id ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $customers = $stmt->fetchAll();

        // 各顧客のよく使う行き先も取得
        foreach ($customers as &$customer) {
            $stmt = $pdo->prepare("
                SELECT id, location_name, address, location_type, sort_order, notes
                FROM frequent_destinations
                WHERE customer_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute([$customer['id']]);
            $customer['frequent_destinations'] = $stmt->fetchAll();

            $customer = escapeCustomer($customer);
        }
        unset($customer);

        sendSuccessResponse([
            'customers' => $customers,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }

} catch (Exception $e) {
    error_log("顧客データ取得エラー: " . $e->getMessage());
    sendErrorResponse('データ取得中にエラーが発生しました');
}

/**
 * 顧客データのHTMLエスケープ
 */
function escapeCustomer($customer) {
    $string_fields = [
        'name', 'name_kana', 'phone', 'phone_secondary', 'email',
        'postal_code', 'address', 'address_detail',
        'care_level', 'disability_type', 'mobility_type', 'wheelchair_type',
        'default_pickup_location', 'default_dropoff_location',
        'emergency_contact_name', 'emergency_contact_phone', 'notes'
    ];

    foreach ($string_fields as $field) {
        if (isset($customer[$field]) && $customer[$field] !== null) {
            $customer[$field] = htmlspecialchars($customer[$field], ENT_QUOTES, 'UTF-8');
        }
    }

    // よく使う行き先もエスケープ
    if (isset($customer['frequent_destinations'])) {
        foreach ($customer['frequent_destinations'] as &$dest) {
            $dest_fields = ['location_name', 'address', 'location_type', 'notes'];
            foreach ($dest_fields as $field) {
                if (isset($dest[$field]) && $dest[$field] !== null) {
                    $dest[$field] = htmlspecialchars($dest[$field], ENT_QUOTES, 'UTF-8');
                }
            }
        }
        unset($dest);
    }

    return $customer;
}
?>
