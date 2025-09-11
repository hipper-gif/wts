<?php
session_start();

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ✅ 修正：permission_levelベースのユーザー情報取得
try {
    $stmt = $pdo->prepare("SELECT name, permission_level, is_driver, is_caller, is_manager FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch();
    
    if (!$user_data) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    
    $user_name = $user_data['name'];
    $user_permission_level = $user_data['permission_level'];
    $is_admin = ($user_permission_level === 'Admin');
    
    // 表示用の役職名を生成
    $user_role_display = '';
    if ($is_admin) {
        $user_role_display = 'システム管理者';
    } else {
        $roles = [];
        if ($user_data['is_driver']) $roles[] = '運転者';
        if ($user_data['is_caller']) $roles[] = '点呼者';
        if ($user_data['is_manager']) $roles[] = '管理者';
        $user_role_display = !empty($roles) ? implode('・', $roles) : '一般ユーザー';
    }
    
} catch (PDOException $e) {
    error_log("User data fetch error: " . $e->getMessage());
    session_destroy();
    header('Location: index.php');
    exit;
}

$today = date('Y-m-d');
$current_time = date('H:i');
$current_hour = date('H');
$current_month_start = date('Y-m-01');

// システム名を取得
$system_name = '福祉輸送管理システム';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) {
        $system_name = $result['setting_value'];
    }
} catch (Exception $e) {
    // デフォルト値を使用
}

// ===== 🔧 売上計算修正：統一されたロジック =====
function calculateRevenue($pdo, $date_condition, $params = []) {
    // ride_recordsテーブルの料金カラム優先順位に基づく計算
    // 1. total_fare（合計料金）が設定されている場合は優先
    // 2. fare + charge（基本料金 + 追加料金）
    // 3. fare_amount（メイン料金）がnon-null の場合
    $sql = "
        SELECT 
            COUNT(*) as ride_count,
            SUM(passenger_count) as total_passengers,
            SUM(
                CASE 
                    WHEN total_fare IS NOT NULL AND total_fare > 0 THEN total_fare
                    WHEN fare IS NOT NULL AND charge IS NOT NULL THEN (fare + charge)
                    WHEN fare IS NOT NULL THEN fare
                    WHEN fare_amount IS NOT NULL THEN fare_amount
                    ELSE 0
                END
            ) as total_revenue,
            ROUND(AVG(
                CASE 
                    WHEN total_fare IS NOT NULL AND total_fare > 0 THEN total_fare
                    WHEN fare IS NOT NULL AND charge IS NOT NULL THEN (fare + charge)
                    WHEN fare IS NOT NULL THEN fare
                    WHEN fare_amount IS NOT NULL THEN fare_amount
                    ELSE 0
                END
            ), 0) as avg_fare
        FROM ride_records 
        WHERE {$date_condition} AND is_sample_data = 0
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

// 今日の統計
$today_stats = calculateRevenue($pdo, "ride_date = ?", [$today]);
$today_ride_records = $today_stats['ride_count'] ?? 0;
$today_total_revenue = $today_stats['total_revenue'] ?? 0;
$today_avg_fare = $today_stats['avg_fare'] ?? 0;
$today_passengers = $today_stats['total_passengers'] ?? 0;

// 当月の統計
$month_stats = calculateRevenue($pdo, "ride_date >= ?", [$current_month_start]);
$month_ride_records = $month_stats['ride_count'] ?? 0;
$month_total_revenue = $month_stats['total_revenue'] ?? 0;
$month_avg_fare = $month_stats['avg_fare'] ?? 0;

// 月平均計算（実稼働日ベース）
try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ride_date) as working_days FROM ride_records WHERE ride_date >= ? AND is_sample_data = 0");
    $stmt->execute([$current_month_start]);
    $working_days_result = $stmt->fetch();
    $working_days = $working_days_result['working_days'] ?? 1;
    $month_avg_daily_revenue = $working_days > 0 ? round($month_total_revenue / $working_days) : 0;
} catch (Exception $e) {
    $working_days = 1;
    $month_avg_daily_revenue = 0;
}

// 先月比較
try {
    $last_month_start = date('Y-m-01', strtotime('-1 month'));
    $last_month_end = date('Y-m-t', strtotime('-1 month'));
    $this_month_start = date('Y-m-01');
    $this_month_end = date('Y-m-t');
    
    $last_month_stats = calculateRevenue($pdo, "ride_date BETWEEN ? AND ?", [$last_month_start, $last_month_end]);
    $this_month_stats = calculateRevenue($pdo, "ride_date BETWEEN ? AND ?", [$this_month_start, $this_month_end]);
    
    $last_month_revenue = $last_month_stats['total_revenue'] ?? 0;
    $this_month_revenue = $this_month_stats['total_revenue'] ?? 0;
    
    $revenue_difference = $this_month_revenue - $last_month_revenue;
    $revenue_percentage = $last_month_revenue > 0 ? round(($revenue_difference / $last_month_revenue) * 100, 1) : 0;
    $revenue_trend = $revenue_difference >= 0 ? 'up' : 'down';
} catch (Exception $e) {
    $revenue_difference = 0;
    $revenue_percentage = 0;
    $revenue_trend = 'neutral';
}

// 業務漏れチェック機能（改善版）
$alerts = [];

try {
    // 1. 乗務前点呼未実施で乗車記録がある運転者をチェック
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.driver_id, u.name as driver_name, COUNT(r.id) as ride_count
        FROM ride_records r
        JOIN users u ON r.driver_id = u.id
        LEFT JOIN pre_duty_calls pdc ON r.driver_id = pdc.driver_id AND r.ride_date = pdc.call_date AND pdc.is_completed = TRUE
        WHERE r.ride_date = ? AND pdc.id IS NULL
        GROUP BY r.driver_id, u.name
    ");
    $stmt->execute([$today]);
    $no_pre_duty_with_rides = $stmt->fetchAll();
    
    foreach ($no_pre_duty_with_rides as $driver) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'title' => '乗務前点呼未実施',
            'message' => "運転者「{$driver['driver_name']}」が乗務前点呼を行わずに乗車記録（{$driver['ride_count']}件）を登録しています。",
            'action' => 'pre_duty_call.php',
            'action_text' => '乗務前点呼を実施'
        ];
    }

    // 2. 出庫処理または日常点検未実施で乗車記録がある車両をチェック
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.vehicle_id, v.vehicle_number, r.driver_id, u.name as driver_name, 
               COUNT(r.id) as ride_count,
               MAX(CASE WHEN dr.id IS NULL THEN 0 ELSE 1 END) as has_departure,
               MAX(CASE WHEN di.id IS NULL THEN 0 ELSE 1 END) as has_daily_inspection
        FROM ride_records r
        JOIN vehicles v ON r.vehicle_id = v.id
        JOIN users u ON r.driver_id = u.id
        LEFT JOIN departure_records dr ON r.vehicle_id = dr.vehicle_id AND r.ride_date = dr.departure_date AND r.driver_id = dr.driver_id
        LEFT JOIN daily_inspections di ON r.vehicle_id = di.vehicle_id AND r.ride_date = di.inspection_date AND r.driver_id = di.driver_id
        WHERE r.ride_date = ?
        GROUP BY r.vehicle_id, v.vehicle_number, r.driver_id, u.name
        HAVING has_departure = 0 OR has_daily_inspection = 0
    ");
    $stmt->execute([$today]);
    $incomplete_prep_with_rides = $stmt->fetchAll();
    
    foreach ($incomplete_prep_with_rides as $vehicle) {
        $missing_items = [];
        if (!$vehicle['has_departure']) $missing_items[] = '出庫処理';
        if (!$vehicle['has_daily_inspection']) $missing_items[] = '日常点検';
        
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-car-crash',
            'title' => '必須処理未実施',
            'message' => "運転者「{$vehicle['driver_name']}」が車両「{$vehicle['vehicle_number']}」で" . implode('・', $missing_items) . "を行わずに乗車記録（{$vehicle['ride_count']}件）を登録しています。",
            'action' => $vehicle['has_departure'] ? 'daily_inspection.php' : 'departure.php',
            'action_text' => $missing_items[0] . 'を実施'
        ];
    }

    // 3. 18時以降で入庫・乗務後点呼未完了をチェック（営業時間終了後）
    if ($current_hour >= 18) {
        // 未入庫車両をチェック
        $stmt = $pdo->prepare("
            SELECT dr.vehicle_id, v.vehicle_number, u.name as driver_name, dr.departure_time
            FROM departure_records dr
            JOIN vehicles v ON dr.vehicle_id = v.id
            JOIN users u ON dr.driver_id = u.id
            LEFT JOIN arrival_records ar ON dr.vehicle_id = ar.vehicle_id AND dr.departure_date = ar.arrival_date
            WHERE dr.departure_date = ? AND ar.id IS NULL
        ");
        $stmt->execute([$today]);
        $not_arrived_vehicles = $stmt->fetchAll();
        
        foreach ($not_arrived_vehicles as $vehicle) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-clock',
                'title' => '入庫処理未完了',
                'message' => "車両「{$vehicle['vehicle_number']}」（運転者：{$vehicle['driver_name']}）が18時以降も入庫処理を完了していません。出庫時刻：{$vehicle['departure_time']}",
                'action' => 'arrival.php',
                'action_text' => '入庫処理を実施'
            ];
        }
        
        // 乗務後点呼未実施をチェック
        $stmt = $pdo->prepare("
            SELECT DISTINCT dr.driver_id, u.name as driver_name
            FROM departure_records dr
            JOIN users u ON dr.driver_id = u.id
            LEFT JOIN post_duty_calls pdc ON dr.driver_id = pdc.driver_id AND dr.departure_date = pdc.call_date AND pdc.is_completed = TRUE
            WHERE dr.departure_date = ? AND pdc.id IS NULL
        ");
        $stmt->execute([$today]);
        $no_post_duty = $stmt->fetchAll();
        
        foreach ($no_post_duty as $driver) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-user-clock',
                'title' => '乗務後点呼未実施',
                'message' => "運転者「{$driver['driver_name']}」が18時以降も乗務後点呼を完了していません。",
                'action' => 'post_duty_call.php',
                'action_text' => '乗務後点呼を実施'
            ];
        }
    }

    // 今日の統計データ
    // 今日の乗務前点呼完了数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_pre_duty_calls = $stmt->fetchColumn();
    
    // 今日の乗務後点呼完了数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_post_duty_calls = $stmt->fetchColumn();
    
    // 今日の出庫記録数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_departures = $stmt->fetchColumn();
    
    // 今日の入庫記録数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute([$today]);
    $today_arrivals = $stmt->fetchColumn();

} catch (Exception $e) {
    error_log("Dashboard alert error: " . $e->getMessage());
}

// アラートを優先度でソート
usort($alerts, function($a, $b) {
    $priority_order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    return $priority_order[$a['priority']] - $priority_order[$b['priority']];
});
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - <?= htmlspecialchars($system_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* 🎯 売上表示専用スタイル - 最優先で目立つように */
        .revenue-showcase {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .revenue-showcase::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }
        
        .revenue-main {
            font-size: 3rem;
            font-weight: 700;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            margin-bottom: 0.5rem;
        }
        
        .revenue-label {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }
        
        .revenue-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
        }
        
        .revenue-stat {
            text-align: center;
        }
        
        .revenue-stat-number {
            font-size: 1.8rem;
            font-weight: 600;
            display: block;
        }
        
        .revenue-stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .comparison-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 600;
        }
        
        .comparison-badge.positive {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        
        .comparison-badge.negative {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        /* アラート専用スタイル */
        .alerts-section {
            margin-bottom: 2rem;
        }
        
        .alert-item {
            border-radius: 12px;
            border: none;
            margin-bottom: 1rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            animation: slideIn 0.5s ease-out;
        }
        
        .alert-critical {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-left: 5px solid #a71e2a;
        }
        
        .alert-high {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #212529;
            border-left: 5px solid #d39e00;
        }
        
        .alert-medium {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-left: 5px solid #0e6674;
        }
        
        .alert-low {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            border-left: 5px solid #495057;
        }
        
        .alert-item .alert-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
        }
        
        .alert-title {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        
        .alert-message {
            margin-bottom: 1rem;
            line-height: 1.4;
        }
        
        .alert-action {
            text-align: right;
        }
        
        .alert-action .btn {
            font-weight: 600;
            border-radius: 20px;
            padding: 0.5rem 1.5rem;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* 統計カード */
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
            transition: transform 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0;
        }
        
        /* クイックアクションボタン */
        .quick-action-group {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .quick-action-btn {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1rem;
            text-decoration: none;
            color: #333;
            display: block;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
            min-height: 80px;
            position: relative;
            overflow: hidden;
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.15);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        
        .quick-action-icon {
            font-size: 1.8rem;
            margin-right: 1rem;
        }
        
        .quick-action-content {
            display: flex;
            align-items: center;
        }
        
        .quick-action-text h6 {
            margin: 0;
            font-weight: 600;
        }
        
        .quick-action-text small {
            color: #6c757d;
            font-size: 0.75rem;
        }
        
        .text-purple { color: #6f42c1; }
        .text-orange { color: #fd7e14; }
        
        @media (max-width: 768px) {
            .revenue-main {
                font-size: 2.5rem;
            }
            .revenue-stat-number {
                font-size: 1.4rem;
            }
            .stats-number {
                font-size: 2rem;
            }
            .quick-action-btn {
                padding: 0.8rem;
                min-height: 70px;
            }
            .header h1 {
                font-size: 1.3rem;
            }
            .alert-item {
                padding: 1rem;
            }
            .alert-icon {
                font-size: 1.2rem !important;
            }
            .quick-action-icon {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-taxi me-2"></i><?= htmlspecialchars($system_name) ?></h1>
                    <div class="user-info">
                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($user_name) ?> 
                        (<?= htmlspecialchars($user_role_display) ?>)
                        | <?= date('Y年n月j日 (D)', strtotime($today)) ?> <?= $current_time ?>
                    </div>
                </div>
                <div class="col-auto">
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container mt-4">
        <!-- 🎯 売上情報ショーケース（最優先表示） -->
        <div class="revenue-showcase">
            <div class="row">
                <div class="col-md-8">
                    <div class="revenue-main">¥<?= number_format($today_total_revenue) ?></div>
                    <div class="revenue-label">
                        <i class="fas fa-calendar-day me-2"></i>今日の売上
                        <?php if ($today_ride_records > 0): ?>
                            <span class="ms-2 opacity-75"><?= $today_ride_records ?>回 | 平均¥<?= number_format($today_avg_fare) ?>/回</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="revenue-details">
                        <div class="revenue-stat">
                            <span class="revenue-stat-number">¥<?= number_format($month_total_revenue) ?></span>
                            <span class="revenue-stat-label">今月累計 (<?= $month_ride_records ?>回)</span>
                        </div>
                        <div class="revenue-stat">
                            <span class="revenue-stat-number">¥<?= number_format($month_avg_daily_revenue) ?></span>
                            <span class="revenue-stat-label">日平均 (<?= $working_days ?>日稼働)</span>
                        </div>
                        <div class="revenue-stat">
                            <span class="revenue-stat-number"><?= $today_passengers ?></span>
                            <span class="revenue-stat-label">今日の乗客数</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <?php if ($revenue_percentage != 0): ?>
                        <div class="comparison-badge <?= $revenue_trend === 'up' ? 'positive' : 'negative' ?>">
                            <i class="fas fa-arrow-<?= $revenue_trend === 'up' ? 'up' : 'down' ?> me-1"></i>
                            先月比 <?= $revenue_trend === 'up' ? '+' : '' ?><?= number_format($revenue_difference) ?>円
                            <br>
                            <small>(<?= $revenue_trend === 'up' ? '+' : '' ?><?= $revenue_percentage ?>%)</small>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <a href="ride_records.php" class="btn btn-light btn-lg">
                            <i class="fas fa-plus me-2"></i>乗車記録を追加
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 業務漏れアラート（最優先表示） -->
        <?php if (!empty($alerts)): ?>
        <div class="alerts-section">
            <h4><i class="fas fa-exclamation-triangle me-2 text-danger"></i>重要なお知らせ・業務漏れ確認</h4>
            <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-item alert-<?= $alert['priority'] ?> <?= $alert['priority'] === 'critical' ? 'pulse' : '' ?>">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <i class="<?= $alert['icon'] ?> alert-icon"></i>
                    </div>
                    <div class="col">
                        <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
                        <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
                    </div>
                    <?php if ($alert['action']): ?>
                    <div class="col-auto alert-action">
                        <a href="<?= $alert['action'] ?>" class="btn btn-light">
                            <i class="fas fa-arrow-right me-1"></i><?= htmlspecialchars($alert['action_text']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- 今日の業務状況 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="stats-card">
                    <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>業務状況</h5>
                    <div class="row text-center">
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-primary"><?= $today_departures ?></div>
                            <div class="stats-label">今日の出庫</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-success"><?= $today_ride_records ?></div>
                            <div class="stats-label">今日の乗車</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-<?= ($today_departures - $today_arrivals > 0) ? 'danger' : 'success' ?>">
                                <?= $today_departures - $today_arrivals ?>
                            </div>
                            <div class="stats-label">未入庫</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-info"><?= $today_pre_duty_calls ?>/<?= $today_post_duty_calls ?></div>
                            <div class="stats-label">乗務前/後点呼</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- クイックアクション -->
        <div class="row">
            <!-- 運転者向け：1日の流れに沿った業務 -->
            <div class="col-lg-6">
                <div class="quick-action-group">
                    <h5><i class="fas fa-route me-2"></i>運転業務（1日の流れ）</h5>
                    
                    <a href="daily_inspection.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-secondary">
                                <i class="fas fa-tools"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>1. 日常点検</h6>
                                <small>最初に実施（法定義務）</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="pre_duty_call.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-warning">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>2. 乗務前点呼</h6>
                                <small>日常点検後に実施</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="departure.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-primary">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>3. 出庫処理</h6>
                                <small>点呼・点検完了後</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="ride_records.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-success">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>4. 乗車記録</h6>
                                <small>営業中随時入力</small>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- 1日の終了業務と管理業務 -->
            <div class="col-lg-6">
                <div class="quick-action-group">
                    <h5><i class="fas fa-moon me-2"></i>終業・管理業務</h5>
                    
                    <a href="arrival.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-info">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>入庫処理</h6>
                                <small>営業終了時に実施</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="post_duty_call.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-danger">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>乗務後点呼</h6>
                                <small>入庫後に実施</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="periodic_inspection.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-purple">
                                <i class="fas fa-wrench"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>定期点検</h6>
                                <small>3ヶ月ごと</small>
                            </div>
                        </div>
                    </a>

                    <?php if ($is_admin): ?>
                    <a href="cash_management.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-success">
                                <i class="fas fa-calculator"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>集金管理</h6>
                                <small>売上・現金管理</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="master_menu.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-orange">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>マスタ管理</h6>
                                <small>システム設定</small>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 今日の業務進捗ガイド -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="stats-card">
                    <h5 class="mb-3"><i class="fas fa-tasks me-2"></i>今日の業務進捗ガイド</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="progress-guide">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_departures > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-tools"></i>
                                            <small>点検・点呼</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_departures > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-sign-out-alt"></i>
                                            <small>出庫</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_ride_records > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-users"></i>
                                            <small>営業</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_arrivals > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-sign-in-alt"></i>
                                            <small>終業</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="next-action">
                                <?php if ($today_departures == 0): ?>
                                    <h6 class="text-primary">次の作業</h6>
                                    <p class="mb-1"><strong>日常点検</strong> を実施してください</p>
                                    <small class="text-muted">その後、乗務前点呼→出庫の順番です</small>
                                <?php elseif ($today_arrivals == 0): ?>
                                    <h6 class="text-success">営業中</h6>
                                    <p class="mb-1">お疲れ様です！</p>
                                    <small class="text-muted">乗車記録の入力をお忘れなく</small>
                                <?php elseif ($today_post_duty_calls == 0): ?>
                                    <h6 class="text-warning">終業処理</h6>
                                    <p class="mb-1"><strong>乗務後点呼</strong> を実施してください</p>
                                    <small class="text-muted">本日の業務完了まであと少しです</small>
                                <?php else: ?>
                                    <h6 class="text-success">業務完了</h6>
                                    <p class="mb-1">本日もお疲れ様でした！</p>
                                    <small class="text-muted">明日もよろしくお願いします</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📊 料金データ確認パネル（管理者のみ表示） -->
        <?php if ($is_admin): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="stats-card">
                    <h5 class="mb-3">
                        <i class="fas fa-database me-2"></i>料金データ確認
                        <small class="text-muted">（管理者のみ表示）</small>
                    </h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6>今日のデータ詳細</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td>乗車記録数</td>
                                    <td class="text-end"><?= $today_ride_records ?> 件</td>
                                </tr>
                                <tr>
                                    <td>総売上（統一ロジック）</td>
                                    <td class="text-end">¥<?= number_format($today_total_revenue) ?></td>
                                </tr>
                                <tr>
                                    <td>平均単価</td>
                                    <td class="text-end">¥<?= number_format($today_avg_fare) ?></td>
                                </tr>
                                <tr>
                                    <td>乗客数</td>
                                    <td class="text-end"><?= $today_passengers ?> 名</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>計算ロジック説明</h6>
                            <div class="alert alert-info">
                                <small>
                                    <strong>料金計算優先順位:</strong><br>
                                    1. total_fare（合計料金）<br>
                                    2. fare + charge（基本＋追加）<br>
                                    3. fare（基本料金のみ）<br>
                                    4. fare_amount（メイン料金）
                                </small>
                            </div>
                            <a href="ride_records.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-table me-1"></i>詳細データを確認
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <style>
        .progress-guide {
            padding: 1rem 0;
        }
        
        .progress-step {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .progress-step.completed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .progress-step.pending {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px dashed #dee2e6;
        }
        
        .progress-step i {
            font-size: 1.5rem;
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .progress-step small {
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .next-action {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1.5rem;
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
        }
        
        .next-action h6 {
            margin-bottom: 1rem;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 5分ごとにページを自動更新してアラートを更新
        setInterval(function() {
            window.location.reload();
        }, 300000); // 5分 = 300000ms

        // アラートが存在する場合、ブラウザ通知を表示（ユーザーの許可が必要）
        <?php if (!empty($alerts) && in_array('critical', array_column($alerts, 'priority'))): ?>
        if (Notification.permission === "granted") {
            new Notification("重要な業務漏れがあります", {
                body: "<?= isset($alerts[0]) ? htmlspecialchars($alerts[0]['message']) : '' ?>",
                icon: "/favicon.ico"
            });
        } else if (Notification.permission !== "denied") {
            Notification.requestPermission().then(function (permission) {
                if (permission === "granted") {
                    new Notification("重要な業務漏れがあります", {
                        body: "<?= isset($alerts[0]) ? htmlspecialchars($alerts[0]['message']) : '' ?>",
                        icon: "/favicon.ico"
                    });
                }
            });
        }
        <?php endif; ?>

        // 業務進捗の可視化アニメーション
        document.addEventListener('DOMContentLoaded', function() {
            const steps = document.querySelectorAll('.progress-step');
            steps.forEach((step, index) => {
                setTimeout(() => {
                    step.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        step.style.transform = 'scale(1)';
                    }, 200);
                }, index * 100);
            });

            // 売上表示のアニメーション
            const revenueMain = document.querySelector('.revenue-main');
            if (revenueMain) {
                revenueMain.style.opacity = '0';
                revenueMain.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    revenueMain.style.transition = 'all 0.8s ease';
                    revenueMain.style.opacity = '1';
                    revenueMain.style.transform = 'translateY(0)';
                }, 100);
            }
        });

        // 開発者用：料金データデバッグ（Console）
        <?php if ($is_admin): ?>
        console.log('=== 福祉輸送管理システム 料金データデバッグ ===');
        console.log('今日の統計:', {
            乗車記録数: <?= $today_ride_records ?>,
            売上総額: <?= $today_total_revenue ?>,
            平均単価: <?= $today_avg_fare ?>,
            乗客総数: <?= $today_passengers ?>
        });
        console.log('今月の統計:', {
            乗車記録数: <?= $month_ride_records ?>,
            売上総額: <?= $month_total_revenue ?>,
            稼働日数: <?= $working_days ?>,
            日平均売上: <?= $month_avg_daily_revenue ?>
        });
        console.log('先月比較:', {
            差額: <?= $revenue_difference ?>,
            パーセンテージ: '<?= $revenue_percentage ?>%',
            トレンド: '<?= $revenue_trend ?>'
        });
        <?php endif; ?>
    </script>
</body>
</html>
