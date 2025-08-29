<?php
// includes/cash_functions.php
// 【緊急修正版】料金カラム使用の統一
// 修正日: 2025年8月28日
// 修正内容: fare_amount → total_fare, cash_amount/card_amount の活用

/**
 * 当日の現金売上を取得
 * ✅ 修正: total_fare, cash_amount 使用
 */
function getTodayCashRevenue($pdo, $date = null) {
    if (!$date) {
        $date = date('Y-m-d');
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as ride_count,
            COALESCE(SUM(total_fare), 0) as total_revenue,
            COALESCE(SUM(cash_amount), 0) as cash_revenue,
            COALESCE(SUM(card_amount), 0) as card_revenue,
            COALESCE(AVG(total_fare), 0) as average_fare
        FROM ride_records 
        WHERE ride_date = ?
    ");
    
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 月次売上集計取得
 * ✅ 修正: total_fare, cash_amount 使用
 */
function getMonthlyCashRevenue($pdo, $year = null, $month = null) {
    if (!$year) $year = date('Y');
    if (!$month) $month = date('n');
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE(ride_date) as date,
            COUNT(*) as daily_ride_count,
            COALESCE(SUM(total_fare), 0) as daily_total,
            COALESCE(SUM(cash_amount), 0) as daily_cash,
            COALESCE(SUM(card_amount), 0) as daily_card,
            COALESCE(AVG(total_fare), 0) as daily_average
        FROM ride_records 
        WHERE YEAR(ride_date) = ? AND MONTH(ride_date) = ?
        GROUP BY DATE(ride_date)
        ORDER BY ride_date DESC
    ");
    
    $stmt->execute([$year, $month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 現金カウントデータ保存
 * ✅ 新規追加: 金種別データ保存機能
 */
function saveCashCount($pdo, $data) {
    try {
        $pdo->beginTransaction();
        
        // 既存データの削除（同日の場合）
        $stmt = $pdo->prepare("
            DELETE FROM cash_count_details 
            WHERE confirmation_date = ? AND driver_id = ?
        ");
        $stmt->execute([$data['confirmation_date'], $data['driver_id']]);
        
        // 新規データ挿入
        $stmt = $pdo->prepare("
            INSERT INTO cash_count_details (
                confirmation_date, driver_id,
                bill_5000, bill_1000, 
                coin_500, coin_100, coin_50, coin_10,
                total_amount, memo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['confirmation_date'],
            $data['driver_id'],
            $data['bill_5000'] ?? 0,
            $data['bill_1000'] ?? 0,
            $data['coin_500'] ?? 0,
            $data['coin_100'] ?? 0,
            $data['coin_50'] ?? 0,
            $data['coin_10'] ?? 0,
            $data['total_amount'] ?? 0,
            $data['memo'] ?? ''
        ]);
        
        $pdo->commit();
        return ['success' => true, 'message' => '集金データを保存しました'];
        
    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'message' => '保存エラー: ' . $e->getMessage()];
    }
}

/**
 * 基準おつりとの差異計算
 * ✅ 新規追加: 運用特化機能
 */
function calculateCashDifference($counted_total, $system_cash_amount) {
    $base_change = 18000; // 基準おつり
    $deposit_amount = $counted_total - $base_change; // 入金額
    $expected_total = $base_change + $system_cash_amount; // 予想金額
    $actual_difference = $counted_total - $expected_total; // 実際差額
    
    return [
        'counted_total' => $counted_total,
        'base_change' => $base_change,
        'deposit_amount' => $deposit_amount,
        'system_cash_amount' => $system_cash_amount,
        'expected_total' => $expected_total,
        'actual_difference' => $actual_difference
    ];
}

/**
 * 金種別基準値取得
 * ✅ 新規追加: 基準おつり管理
 */
function getBaseChangeBreakdown() {
    return [
        'bill_5000' => ['count' => 1, 'amount' => 5000],
        'bill_1000' => ['count' => 10, 'amount' => 10000],
        'coin_500' => ['count' => 3, 'amount' => 1500],
        'coin_100' => ['count' => 11, 'amount' => 1100],
        'coin_50' => ['count' => 5, 'amount' => 250],
        'coin_10' => ['count' => 15, 'amount' => 150],
        'total' => ['count' => 45, 'amount' => 18000]
    ];
}

/**
 * 現金カウント履歴取得
 * ✅ 新規追加: 履歴管理機能
 */
function getCashCountHistory($pdo, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            u.name as driver_name,
            DATE_FORMAT(c.confirmation_date, '%m/%d') as formatted_date
        FROM cash_count_details c
        LEFT JOIN users u ON c.driver_id = u.id
        ORDER BY c.confirmation_date DESC, c.created_at DESC
        LIMIT ?
    ");
    
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 集金バック管理
 * ✅ 新規追加: A/Bバック管理機能
 */
function getCashBagStatus($pdo, $date = null) {
    if (!$date) $date = date('Y-m-d');
    
    // 仮想的なバック管理（将来的にテーブル追加予定）
    return [
        'bag_a' => [
            'status' => 'available',
            'last_used' => $date,
            'base_amount' => 18000
        ],
        'bag_b' => [
            'status' => 'in_use',
            'last_used' => $date,
            'base_amount' => 18000
        ]
    ];
}

/**
 * 当月の集金統計取得
 * ✅ 修正: total_fare 使用
 */
function getMonthlyStatistics($pdo, $year = null, $month = null) {
    if (!$year) $year = date('Y');
    if (!$month) $month = date('n');
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            COUNT(DISTINCT ride_date) as working_days,
            COALESCE(SUM(total_fare), 0) as total_revenue,
            COALESCE(SUM(cash_amount), 0) as total_cash,
            COALESCE(SUM(card_amount), 0) as total_card,
            COALESCE(AVG(total_fare), 0) as average_fare,
            COALESCE(SUM(cash_amount) / NULLIF(COUNT(DISTINCT ride_date), 0), 0) as daily_cash_average
        FROM ride_records 
        WHERE YEAR(ride_date) = ? AND MONTH(ride_date) = ?
    ");
    
    $stmt->execute([$year, $month]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
