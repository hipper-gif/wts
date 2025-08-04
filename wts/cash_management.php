<?php
// cash_management.php - バグ修正版
// 修正内容: fare_amount を (fare + charge) に変更

// 日次売上データ取得（修正版）
function getDailySales($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(fare + charge) as total_amount,  -- ✅ 修正: fare_amount → (fare + charge)
            AVG(fare + charge) as avg_amount     -- ✅ 修正: fare_amount → (fare + charge)
        FROM ride_records 
        WHERE DATE(ride_date) = ? 
        GROUP BY payment_method
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 日次合計取得（修正版）
function getDailyTotal($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            SUM(fare + charge) as total_amount,  -- ✅ 修正: fare_amount → (fare + charge)
            SUM(CASE WHEN payment_method = '現金' THEN fare + charge ELSE 0 END) as cash_amount,      -- ✅ 修正
            SUM(CASE WHEN payment_method = 'カード' THEN fare + charge ELSE 0 END) as card_amount,   -- ✅ 修正
            SUM(CASE WHEN payment_method = 'その他' THEN fare + charge ELSE 0 END) as other_amount   -- ✅ 修正
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ");
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 月次集計データ取得（修正版）
function getMonthlySummary($pdo, $month) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(ride_date) as date,
            COUNT(*) as rides,
            SUM(fare + charge) as total,  -- ✅ 修正: fare_amount → (fare + charge)
            SUM(CASE WHEN payment_method = '現金' THEN fare + charge ELSE 0 END) as cash,   -- ✅ 修正
            SUM(CASE WHEN payment_method = 'カード' THEN fare + charge ELSE 0 END) as card  -- ✅ 修正
        FROM ride_records 
        WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
        GROUP BY DATE(ride_date)
        ORDER BY date
    ");
    $stmt->execute([$month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
🔧 修正内容:
1. getDailySales(): fare_amount → (fare + charge)
2. getDailyTotal(): 全ての fare_amount → (fare + charge)  
3. getMonthlySummary(): 全ての fare_amount → (fare + charge)

✅ 効果:
- 集金管理で正しい金額が表示される
- 現金・カード別集計が正確になる
- 月次レポートの数値が正しくなる
*/
?>
