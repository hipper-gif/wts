<?php
// includes/cash_functions.php
// 【実テーブル対応版】料金カラム使用の統一
// 修正日: 2025年8月29日
// 修正内容: 実際のテーブル構造に合わせて調整

/**
 * 当日の現金売上を取得
 * ✅ 実テーブル確認: ride_records.total_fare, cash_amount 等の存在確認後に使用
 */
function getTodayCashRevenue($pdo, $date = null) {
    if (!$date) {
        $date = date('Y-m-d');
    }
    
    try {
        // まず、必要なカラムの存在を確認
        $stmt = $pdo->query("DESCRIBE ride_records");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // カラム存在チェック
        $has_total_fare = in_array('total_fare', $columns);
        $has_cash_amount = in_array('cash_amount', $columns);
        $has_card_amount = in_array('card_amount', $columns);
        
        if ($has_total_fare && $has_cash_amount && $has_card_amount) {
            // 理想的なケース：全カラム存在
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
        } else {
            // フォールバック：従来の計算方式
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as ride_count,
                    COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as total_revenue,
                    COALESCE(SUM(CASE WHEN payment_method = '現金' THEN fare + COALESCE(charge, 0) ELSE 0 END), 0) as cash_revenue,
                    COALESCE(SUM(CASE WHEN payment_method = 'カード' THEN fare + COALESCE(charge, 0) ELSE 0 END), 0) as card_revenue,
                    COALESCE(AVG(fare + COALESCE(charge, 0)), 0) as average_fare
                FROM ride_records 
                WHERE ride_date = ?
            ");
        }
        
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        // エラー時のフォールバック
        return [
            'ride_count' => 0,
            'total_revenue' => 0,
            'cash_revenue' => 0,
            'card_revenue' => 0,
            'average_fare' => 0
        ];
    }
}

/**
 * 集金データ保存（実テーブル構造対応）
 * ✅ 実際の cash_count_details テーブル構造に合わせて修正
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
        
        // 実テーブル構造に合わせた新規データ挿入
        $stmt = $pdo->prepare("
            INSERT INTO cash_count_details (
                confirmation_date, driver_id,
                bill_10000, bill_5000, bill_2000, bill_1000,
                coin_500, coin_100, coin_50, coin_10, coin_5, coin_1,
                total_amount, memo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['confirmation_date'],
            $data['driver_id'],
            $data['bill_10000'] ?? 0,
            $data['bill_5000'] ?? 0,
            $data['bill_2000'] ?? 0,
            $data['bill_1000'] ?? 0,
            $data['coin_500'] ?? 0,
            $data['coin_100'] ?? 0,
            $data['coin_50'] ?? 0,
            $data['coin_10'] ?? 0,
            $data['coin_5'] ?? 0,
            $data['coin_1'] ?? 0,
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
 * 基準おつりとの差異計算（実装版）
 * ✅ 仮想カラムではなく、計算による実装
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
 * 金種別基準値取得（運用実態に合わせて調整）
 * ✅ 実際に使用する金種のみに限定
 */
function getBaseChangeBreakdown() {
    return [
        // 実際に使用する金種のみ
        'bill_5000' => ['count' => 1, 'amount' => 5000, 'name' => '5千円札'],
        'bill_1000' => ['count' => 10, 'amount' => 10000, 'name' => '千円札'],
        'coin_500' => ['count' => 3, 'amount' => 1500, 'name' => '500円玉'],
        'coin_100' => ['count' => 11, 'amount' => 1100, 'name' => '100円玉'],
        'coin_50' => ['count' => 5, 'amount' => 250, 'name' => '50円玉'],
        'coin_10' => ['count' => 15, 'amount' => 150, 'name' => '10円玉'],
        
        // 運用では使わないが、テーブル構造上存在するカラム
        'bill_10000' => ['count' => 0, 'amount' => 0, 'name' => '1万円札（未使用）'],
        'bill_2000' => ['count' => 0, 'amount' => 0, 'name' => '2千円札（未使用）'],
        'coin_5' => ['count' => 0, 'amount' => 0, 'name' => '5円玉（未使用）'],
        'coin_1' => ['count' => 0, 'amount' => 0, 'name' => '1円玉（未使用）'],
        
        'total' => ['count' => 45, 'amount' => 18000, 'name' => '基準合計']
    ];
}

/**
 * 現金カウント履歴取得（実テーブル対応）
 */
function getCashCountHistory($pdo, $limit = 10, $driver_id = null, $date_from = null, $date_to = null) {
    $where_conditions = [];
    $params = [];
    
    if ($driver_id) {
        $where_conditions[] = "c.driver_id = ?";
        $params[] = $driver_id;
    }
    
    if ($date_from) {
        $where_conditions[] = "c.confirmation_date >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_conditions[] = "c.confirmation_date <= ?";
        $params[] = $date_to;
    }
    
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            u.name as driver_name,
            DATE_FORMAT(c.confirmation_date, '%m/%d') as formatted_date,
            DATE_FORMAT(c.created_at, '%H:%i') as formatted_time
        FROM cash_count_details c
        LEFT JOIN users u ON c.driver_id = u.id
        {$where_clause}
        ORDER BY c.confirmation_date DESC, c.created_at DESC
        LIMIT ?
    ");
    
    $params[] = $limit;
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 計算フィールドを追加
    foreach ($results as &$record) {
        $system_cash = getTodayCashRevenue($pdo, $record['confirmation_date'])['cash_revenue'];
        $difference_calc = calculateCashDifference($record['total_amount'], $system_cash);
        
        $record['deposit_amount'] = $difference_calc['deposit_amount'];
        $record['system_cash_amount'] = $difference_calc['system_cash_amount'];
        $record['actual_difference'] = $difference_calc['actual_difference'];
        $record['difference_status'] = $difference_calc['actual_difference'] > 0 ? 'plus' : 
                                     ($difference_calc['actual_difference'] < 0 ? 'minus' : 'zero');
    }
    
    return $results;
}

/**
 * テーブル構造確認関数
 * ✅ デバッグ・確認用
 */
function checkTableStructure($pdo, $table_name) {
    try {
        $stmt = $pdo->prepare("DESCRIBE {$table_name}");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * 月次統計取得（安全版）
 */
function getMonthlyStatistics($pdo, $year = null, $month = null) {
    if (!$year) $year = date('Y');
    if (!$month) $month = date('n');
    
    try {
        // カラム存在確認
        $columns = $pdo->query("DESCRIBE ride_records")->fetchAll(PDO::FETCH_COLUMN);
        $has_total_fare = in_array('total_fare', $columns);
        
        if ($has_total_fare) {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_rides,
                    COUNT(DISTINCT ride_date) as working_days,
                    COALESCE(SUM(total_fare), 0) as total_revenue,
                    COALESCE(SUM(cash_amount), 0) as total_cash,
                    COALESCE(SUM(card_amount), 0) as total_card,
                    COALESCE(AVG(total_fare), 0) as average_fare
                FROM ride_records 
                WHERE YEAR(ride_date) = ? AND MONTH(ride_date) = ?
            ");
        } else {
            // フォールバック計算
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_rides,
                    COUNT(DISTINCT ride_date) as working_days,
                    COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as total_revenue,
                    COALESCE(SUM(CASE WHEN payment_method = '現金' THEN fare + COALESCE(charge, 0) ELSE 0 END), 0) as total_cash,
                    COALESCE(SUM(CASE WHEN payment_method = 'カード' THEN fare + COALESCE(charge, 0) ELSE 0 END), 0) as total_card,
                    COALESCE(AVG(fare + COALESCE(charge, 0)), 0) as average_fare
                FROM ride_records 
                WHERE YEAR(ride_date) = ? AND MONTH(ride_date) = ?
            ");
        }
        
        $stmt->execute([$year, $month]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return [
            'total_rides' => 0,
            'working_days' => 0,
            'total_revenue' => 0,
            'total_cash' => 0,
            'total_card' => 0,
            'average_fare' => 0
        ];
    }
}
?>
