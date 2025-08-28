<?php
/**
 * 集金管理共通関数 - 修正版
 * 
 * ✅ 正しいカラム使用:
 * - total_fare: 合計料金（メイン集計対象）
 * - cash_amount: 現金支払分
 * - card_amount: カード支払分
 * - payment_method: '現金' または 'カード'
 * 
 * ❌ 使用禁止:
 * - fare_amount: 存在しないカラム（エラーの原因）
 */

// 日次売上データ取得
function getDailySales($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                payment_method,
                COUNT(*) as count,
                SUM(total_fare) as total_amount,
                AVG(total_fare) as avg_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ? 
            GROUP BY payment_method
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getDailySales error: " . $e->getMessage());
        return [];
    }
}

// 日次合計取得
function getDailyTotal($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_rides,
                SUM(total_fare) as total_amount,
                SUM(cash_amount) as cash_amount,
                SUM(card_amount) as card_amount,
                SUM(CASE WHEN payment_method NOT IN ('現金', 'カード') THEN total_fare ELSE 0 END) as other_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ?
        ");
        $stmt->execute([$date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // NULL値を0に変換
        if ($result) {
            foreach ($result as $key => $value) {
                if ($value === null) {
                    $result[$key] = 0;
                }
            }
        }
        
        return $result ?: [
            'total_rides' => 0,
            'total_amount' => 0,
            'cash_amount' => 0,
            'card_amount' => 0,
            'other_amount' => 0
        ];
    } catch (PDOException $e) {
        error_log("getDailyTotal error: " . $e->getMessage());
        return [
            'total_rides' => 0,
            'total_amount' => 0,
            'cash_amount' => 0,
            'card_amount' => 0,
            'other_amount' => 0
        ];
    }
}

// 月次集計データ取得
function getMonthlySummary($pdo, $month) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(ride_date) as date,
                COUNT(*) as rides,
                SUM(total_fare) as total,
                SUM(cash_amount) as cash,
                SUM(card_amount) as card
            FROM ride_records 
            WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
            GROUP BY DATE(ride_date)
            ORDER BY date
        ");
        $stmt->execute([$month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getMonthlySummary error: " . $e->getMessage());
        return [];
    }
}

// 集金確認記録取得
function getCashConfirmation($pdo, $date) {
    try {
        // テーブル存在確認
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'cash_confirmations'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            // テーブルが存在しない場合はnullを返す
            return null;
        }
        
        $stmt = $pdo->prepare("
            SELECT cc.*, u.name as confirmed_by_name
            FROM cash_confirmations cc
            LEFT JOIN users u ON cc.confirmed_by = u.id
            WHERE cc.confirmation_date = ?
        ");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getCashConfirmation error: " . $e->getMessage());
        return null;
    }
}

// 期間別売上統計
function getPeriodStatistics($pdo, $start_date, $end_date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_rides,
                SUM(total_fare) as total_amount,
                AVG(total_fare) as avg_amount,
                MIN(total_fare) as min_amount,
                MAX(total_fare) as max_amount,
                SUM(cash_amount) as cash_amount,
                SUM(card_amount) as card_amount
            FROM ride_records 
            WHERE DATE(ride_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // NULL値を0に変換
        if ($result) {
            foreach ($result as $key => $value) {
                if ($value === null) {
                    $result[$key] = 0;
                }
            }
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("getPeriodStatistics error: " . $e->getMessage());
        return [
            'total_rides' => 0,
            'total_amount' => 0,
            'avg_amount' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'cash_amount' => 0,
            'card_amount' => 0
        ];
    }
}

// 月次比較データ
function getMonthlyComparison($pdo, $months = 6) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(ride_date, '%Y-%m') as month,
                COUNT(*) as rides,
                SUM(total_fare) as total,
                SUM(cash_amount) as cash,
                SUM(card_amount) as card
            FROM ride_records 
            WHERE ride_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(ride_date, '%Y-%m')
            ORDER BY month DESC
        ");
        $stmt->execute([$months]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getMonthlyComparison error: " . $e->getMessage());
        return [];
    }
}

// 簡易版日次集計（最小限の情報）
function getSimpleDailyTotal($pdo, $date) {
    try {
        // まず基本的なクエリで試行
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_rides,
                COALESCE(SUM(total_fare), 0) as total_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ?
        ");
        $stmt->execute([$date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 現金・カード別集計（cash_amount, card_amountがある場合）
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(cash_amount), 0) as cash_amount,
                    COALESCE(SUM(card_amount), 0) as card_amount
                FROM ride_records 
                WHERE DATE(ride_date) = ?
            ");
            $stmt->execute([$date]);
            $payment_result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payment_result) {
                $result['cash_amount'] = $payment_result['cash_amount'];
                $result['card_amount'] = $payment_result['card_amount'];
            }
        } catch (PDOException $e) {
            // cash_amount, card_amountがない場合はpayment_methodで計算
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN payment_method = '現金' THEN total_fare ELSE 0 END), 0) as cash_amount,
                    COALESCE(SUM(CASE WHEN payment_method = 'カード' THEN total_fare ELSE 0 END), 0) as card_amount
                FROM ride_records 
                WHERE DATE(ride_date) = ?
            ");
            $stmt->execute([$date]);
            $payment_result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payment_result) {
                $result['cash_amount'] = $payment_result['cash_amount'];
                $result['card_amount'] = $payment_result['card_amount'];
            } else {
                $result['cash_amount'] = 0;
                $result['card_amount'] = 0;
            }
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("getSimpleDailyTotal error: " . $e->getMessage());
        return [
            'total_rides' => 0,
            'total_amount' => 0,
            'cash_amount' => 0,
            'card_amount' => 0
        ];
    }
}

// 運転者別売上集計
function getDriverSales($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.driver_id,
                u.name as driver_name,
                COUNT(*) as rides,
                SUM(r.total_fare) as total_amount,
                SUM(r.cash_amount) as cash_amount,
                SUM(r.card_amount) as card_amount
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE(r.ride_date) = ?
            GROUP BY r.driver_id, u.name
            ORDER BY total_amount DESC
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getDriverSales error: " . $e->getMessage());
        return [];
    }
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

// 集金確認テーブル作成（存在しない場合）
function createCashConfirmationTable($pdo) {
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS cash_confirmations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                confirmation_date DATE NOT NULL,
                expected_cash DECIMAL(10,2) DEFAULT 0,
                actual_cash DECIMAL(10,2) DEFAULT 0,
                difference DECIMAL(10,2) DEFAULT 0,
                confirmed_by INT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_date (confirmation_date),
                KEY idx_confirmed_by (confirmed_by),
                FOREIGN KEY (confirmed_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("createCashConfirmationTable error: " . $e->getMessage());
        return false;
    }
}

// 集金確認記録保存
function saveCashConfirmation($pdo, $date, $expected_cash, $actual_cash, $user_id, $notes = '') {
    try {
        // テーブルが存在しない場合は作成
        createCashConfirmationTable($pdo);
        
        $difference = $actual_cash - $expected_cash;
        
        $stmt = $pdo->prepare("
            INSERT INTO cash_confirmations 
            (confirmation_date, expected_cash, actual_cash, difference, confirmed_by, notes) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            expected_cash = VALUES(expected_cash),
            actual_cash = VALUES(actual_cash),
            difference = VALUES(difference),
            confirmed_by = VALUES(confirmed_by),
            notes = VALUES(notes),
            updated_at = CURRENT_TIMESTAMP
        ");
        
        return $stmt->execute([$date, $expected_cash, $actual_cash, $difference, $user_id, $notes]);
    } catch (PDOException $e) {
        error_log("saveCashConfirmation error: " . $e->getMessage());
        return false;
    }
}
?>
