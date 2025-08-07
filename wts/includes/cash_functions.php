<?php
/**
 * 集金管理共通関数
 */

// 日次売上データ取得
function getDailySales($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(fare_amount) as total_amount,
            AVG(fare_amount) as avg_amount
        FROM ride_records 
        WHERE DATE(ride_date) = ? 
        GROUP BY payment_method
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 日次合計取得
function getDailyTotal($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            SUM(fare_amount) as total_amount,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card_amount,
            SUM(CASE WHEN payment_method = 'その他' THEN fare_amount ELSE 0 END) as other_amount
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ");
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 月次集計データ取得
function getMonthlySummary($pdo, $month) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(ride_date) as date,
            COUNT(*) as rides,
            SUM(fare_amount) as total,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card
        FROM ride_records 
        WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
        GROUP BY DATE(ride_date)
        ORDER BY date
    ");
    $stmt->execute([$month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 集金確認記録取得
function getCashConfirmation($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT cc.*, u.name as confirmed_by_name
        FROM cash_confirmations cc
        LEFT JOIN users u ON cc.confirmed_by = u.id
        WHERE cc.confirmation_date = ?
    ");
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 期間別売上統計
function getPeriodStatistics($pdo, $start_date, $end_date) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            SUM(fare_amount) as total_amount,
            AVG(fare_amount) as avg_amount,
            MIN(fare_amount) as min_amount,
            MAX(fare_amount) as max_amount,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card_amount
        FROM ride_records 
        WHERE DATE(ride_date) BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 月次比較データ
function getMonthlyComparison($pdo, $months = 6) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(ride_date, '%Y-%m') as month,
            COUNT(*) as rides,
            SUM(fare_amount) as total,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card
        FROM ride_records 
        WHERE ride_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        GROUP BY DATE_FORMAT(ride_date, '%Y-%m')
        ORDER BY month DESC
    ");
    $stmt->execute([$months]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 金額フォーマット
function formatAmount($amount) {
    return '¥' . number_format($amount ?? 0);
}

// 差額の表示クラス
function getDifferenceClass($difference) {
    if ($difference > 0) return 'difference-positive';
    if ($difference < 0) return 'difference-negative';
    return '';
}

// 支払方法アイコン
function getPaymentIcon($payment_method) {
    switch ($payment_method) {
        case '現金':
            return 'fa-money-bill';
        case 'カード':
            return 'fa-credit-card';
        default:
            return 'fa-ellipsis-h';
    }
}
?>
