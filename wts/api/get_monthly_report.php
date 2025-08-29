<?php
header('Content-Type: application/json');
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

require_once '../config/database.php';

try {
    $pdo = getDBConnection();
    
    // パラメータ取得
    $year = $_GET['year'] ?? date('Y');
    $month = $_GET['month'] ?? date('n');
    $driver_id = $_GET['driver_id'] ?? null;
    
    // 月次集計SQL
    $sql = "SELECT 
                DATE_FORMAT(ride_date, '%Y-%m-%d') as date,
                DATE_FORMAT(ride_date, '%m/%d') as display_date,
                DAYOFWEEK(ride_date) as day_of_week,
                SUM(total_fare) as daily_total,
                SUM(cash_amount) as cash_total,
                SUM(card_amount) as card_total,
                COUNT(*) as ride_count,
                GROUP_CONCAT(DISTINCT driver_id) as drivers
            FROM ride_records 
            WHERE YEAR(ride_date) = ? AND MONTH(ride_date) = ?";
    
    $params = [$year, $month];
    
    // 運転者フィルタ
    if ($driver_id) {
        $sql .= " AND driver_id = ?";
        $params[] = $driver_id;
    }
    
    $sql .= " GROUP BY DATE(ride_date) ORDER BY date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 曜日名変換
    $day_names = ['', '日', '月', '火', '水', '木', '金', '土'];
    
    // データ整形
    $formatted_data = array_map(function($row) use ($day_names) {
        $cash_rate = $row['daily_total'] > 0 ? ($row['cash_total'] / $row['daily_total']) * 100 : 0;
        
        return [
            'date' => $row['date'],
            'display_date' => $row['display_date'] . '(' . $day_names[$row['day_of_week']] . ')',
            'daily_total' => (int)$row['daily_total'],
            'cash_total' => (int)$row['cash_total'],
            'card_total' => (int)$row['card_total'],
            'ride_count' => (int)$row['ride_count'],
            'cash_rate' => round($cash_rate, 1),
            'drivers' => $row['drivers']
        ];
    }, $daily_data);
    
    // 月間合計
    $monthly_summary = [
        'total_revenue' => array_sum(array_column($formatted_data, 'daily_total')),
        'total_cash' => array_sum(array_column($formatted_data, 'cash_total')),
        'total_card' => array_sum(array_column($formatted_data, 'card_total')),
        'total_rides' => array_sum(array_column($formatted_data, 'ride_count')),
        'working_days' => count($formatted_data)
    ];
    
    if ($monthly_summary['total_revenue'] > 0) {
        $monthly_summary['avg_per_day'] = round($monthly_summary['total_revenue'] / $monthly_summary['working_days']);
        $monthly_summary['avg_per_ride'] = round($monthly_summary['total_revenue'] / $monthly_summary['total_rides']);
        $monthly_summary['overall_cash_rate'] = round(($monthly_summary['total_cash'] / $monthly_summary['total_revenue']) * 100, 1);
    } else {
        $monthly_summary['avg_per_day'] = 0;
        $monthly_summary['avg_per_ride'] = 0;
        $monthly_summary['overall_cash_rate'] = 0;
    }
    
    // 運転者別集計（複数運転者の場合）
    $driver_summary = [];
    if (!$driver_id) {
        $driver_sql = "SELECT 
                        u.NAME as driver_name,
                        r.driver_id,
                        SUM(r.total_fare) as total_revenue,
                        SUM(r.cash_amount) as cash_total,
                        SUM(r.card_amount) as card_total,
                        COUNT(*) as ride_count
                    FROM ride_records r
                    JOIN users u ON r.driver_id = u.id
                    WHERE YEAR(r.ride_date) = ? AND MONTH(r.ride_date) = ?
                    GROUP BY r.driver_id, u.NAME
                    ORDER BY total_revenue DESC";
        
        $stmt = $pdo->prepare($driver_sql);
        $stmt->execute([$year, $month]);
        $driver_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $driver_summary = array_map(function($row) {
            $cash_rate = $row['total_revenue'] > 0 ? ($row['cash_total'] / $row['total_revenue']) * 100 : 0;
            return [
                'driver_id' => $row['driver_id'],
                'driver_name' => $row['driver_name'],
                'total_revenue' => (int)$row['total_revenue'],
                'cash_total' => (int)$row['cash_total'],
                'card_total' => (int)$row['card_total'],
                'ride_count' => (int)$row['ride_count'],
                'cash_rate' => round($cash_rate, 1)
            ];
        }, $driver_summary);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'year' => $year,
            'month' => $month,
            'daily_data' => $formatted_data,
            'monthly_summary' => $monthly_summary,
            'driver_summary' => $driver_summary
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラー: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
