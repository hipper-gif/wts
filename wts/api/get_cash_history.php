<?php
// api/get_cash_history.php
// 集金履歴取得API
// 作成日: 2025年8月28日

header('Content-Type: application/json; charset=utf-8');
session_start();

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

require_once '../config/database.php';

// 関数型データベース接続に対応
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'データベース接続エラー']);
    exit;
}

try {
    // パラメータ取得
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $driver_id = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : null;
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    
    // クエリ組み立て
    $where_conditions = [];
    $params = [];
    
    // 運転者フィルター
    if ($driver_id) {
        $where_conditions[] = "c.driver_id = ?";
        $params[] = $driver_id;
    }
    
    // 期間フィルター
    if ($date_from) {
        $where_conditions[] = "c.confirmation_date >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $where_conditions[] = "c.confirmation_date <= ?";
        $params[] = $date_to;
    }
    
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // メインクエリ
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.confirmation_date,
            c.cash_bag,
            c.bill_5000, c.bill_1000, c.coin_500, c.coin_100, c.coin_50, c.coin_10,
            c.total_amount,
            c.deposit_amount,
            c.system_cash_amount,
            c.actual_difference,
            c.memo,
            c.created_at,
            u.name as driver_name,
            DATE_FORMAT(c.confirmation_date, '%m/%d') as formatted_date,
            DATE_FORMAT(c.created_at, '%H:%i') as formatted_time,
            CASE 
                WHEN c.actual_difference > 0 THEN 'plus'
                WHEN c.actual_difference < 0 THEN 'minus'
                ELSE 'zero'
            END as difference_status
        FROM cash_count_details c
        LEFT JOIN users u ON c.driver_id = u.id
        {$where_clause}
        ORDER BY c.confirmation_date DESC, c.created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $history_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 件数取得
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM cash_count_details c
        LEFT JOIN users u ON c.driver_id = u.id
        {$where_clause}
    ");
    $count_params = array_slice($params, 0, -2); // LIMIT, OFFSETを除く
    $count_stmt->execute($count_params);
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 統計データ取得
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as record_count,
            AVG(total_amount) as avg_total,
            AVG(deposit_amount) as avg_deposit,
            AVG(ABS(actual_difference)) as avg_difference,
            SUM(CASE WHEN actual_difference > 0 THEN 1 ELSE 0 END) as plus_count,
            SUM(CASE WHEN actual_difference < 0 THEN 1 ELSE 0 END) as minus_count,
            SUM(CASE WHEN actual_difference = 0 THEN 1 ELSE 0 END) as zero_count
        FROM cash_count_details c
        {$where_clause}
    ");
    $stats_stmt->execute($count_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // レスポンス作成
    echo json_encode([
        'success' => true,
        'data' => [
            'history' => $history_data,
            'pagination' => [
                'total' => (int)$total_count,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total_count
            ],
            'statistics' => [
                'record_count' => (int)$stats['record_count'],
                'avg_total' => round($stats['avg_total'] ?? 0),
                'avg_deposit' => round($stats['avg_deposit'] ?? 0),
                'avg_difference' => round($stats['avg_difference'] ?? 0),
                'plus_count' => (int)$stats['plus_count'],
                'minus_count' => (int)$stats['minus_count'],
                'zero_count' => (int)$stats['zero_count'],
                'accuracy_rate' => $stats['record_count'] > 0 ? 
                    round(($stats['zero_count'] / $stats['record_count']) * 100, 1) : 0
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データ取得エラー: ' . $e->getMessage(),
        'error_code' => 'FETCH_ERROR'
    ]);
}
?>
