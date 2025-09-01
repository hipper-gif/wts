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

// 統一ヘッダーシステム読み込み
require_once 'includes/header.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ユーザー情報取得
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

// 業務漏れチェック機能
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

    // 3. 18時以降で入庫・乗務後点呼未完了をチェック
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_pre_duty_calls = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_post_duty_calls = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_departures = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute([$today]);
    $today_arrivals = $stmt->fetchColumn();
    
    // 乗車記録の統計データ取得を修正
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_fare), 0) as revenue FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    $today_ride_records = $result ? $result['count'] : 0;
    $today_total_revenue = $result ? $result['revenue'] : 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_fare), 0) as revenue FROM ride_records WHERE ride_date >= ?");
    $stmt->execute([$current_month_start]);
    $result = $stmt->fetch();
    $month_ride_records = $result ? $result['count'] : 0;
    $month_total_revenue = $result ? $result['revenue'] : 0;
    
    $days_in_month = date('j');
    $month_avg_revenue = $days_in_month > 0 ? round($month_total_revenue / $days_in_month) : 0;
    
} catch (Exception $e) {
    error_log("Dashboard alert error: " . $e->getMessage());
    // エラーがあっても処理を続行し、デフォルト値を設定
    $today_pre_duty_calls = 0;
    $today_post_duty_calls = 0;
    $today_departures = 0;
    $today_arrivals = 0;
    $today_ride_records = 0;
    $today_total_revenue = 0;
    $month_ride_records = 0;
    $month_total_revenue = 0;
    $month_avg_revenue = 0;
}

// アラートを優先度でソート
if (!empty($alerts)) {
    usort($alerts, function($a, $b) {
        $priority_order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        return $priority_order[$a['priority']] - $priority_order[$b['priority']];
    });
}

// 統計データ配列
$stats_today = [
    [
        'value' => $today_departures,
        'label' => '今日の出庫',
        'icon' => 'truck-pickup',
        'color' => 'primary'
    ],
    [
        'value' => $today_ride_records,
        'label' => '今日の乗車',
        'icon' => 'car',
        'color' => 'success'
    ],
    [
        'value' => max(0, $today_departures - $today_arrivals),
        'label' => '未入庫',
        'icon' => 'exclamation-triangle',
        'color' => ($today_departures - $today_arrivals > 0) ? 'danger' : 'success'
    ],
    [
        'value' => $today_pre_duty_calls . '/' . $today_post_duty_calls,
        'label' => '乗務前/後点呼',
        'icon' => 'clipboard-check',
        'color' => 'info'
    ]
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - 福祉輸送管理システム</title>
    
    <!-- Bootstrap 5.3.0 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6.0.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- 統一ヘッダーCSS -->
    <link rel="stylesheet" href="css/header-unified.css">
    
    <style>
        /* ダッシュボード固有のスタイル */
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
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
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
        
        /* 売上カード（見やすい配色に修正） */
        .revenue-card {
            background: var(--bg-primary);
            color: var(--text-primary);
            border: 1px solid var(--success);
            border-left: 4px solid var(--success);
        }
        
        .revenue-card h6 {
            color: var(--success);
            font-weight: 600;
        }
        
        .revenue-card h2 {
            color: var(--success);
        }
        
        .revenue-card small {
            color: var(--text-secondary);
        }
        
        .revenue-month-card {
            background: var(--bg-primary);
            color: var(--text-primary);
            border: 1px solid var(--accent);
            border-left: 4px solid var(--accent);
        }
        
        .revenue-month-card h6 {
            color: var(--accent);
            font-weight: 600;
        }
        
        .revenue-month-card h2 {
            color: var(--accent);
        }
        
        .revenue-month-card small {
            color: var(--text-secondary);
        }
        
        /* クイックアクション */
        .quick-action-btn {
            background: var(--white);
            border: 2px solid var(--medium-gray);
            border-radius: var(--border-radius-lg);
            padding: 1rem;
            text-decoration: none;
            color: var(--text-primary);
            display: block;
            margin-bottom: 0.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 80px;
            position: relative;
            overflow: hidden;
        }
        
        .quick-action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.1), transparent);
            transition: left 0.5s;
        }
        
        .quick-action-btn:hover::before {
            left: 100%;
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary-start);
            color: var(--primary-start);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            text-decoration: none;
        }
        
        .quick-action-content {
            display: flex;
            align-items: center;
        }
        
        .quick-action-icon {
            font-size: 1.8rem;
            margin-right: 1rem;
        }
        
        .quick-action-text h6 {
            margin: 0;
            font-weight: 600;
        }
        
        .quick-action-text small {
            color: var(--text-secondary);
            font-size: 0.75rem;
        }
        
        /* 業務進捗ガイド（視認性改善） */
        .progress-step {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .progress-step.completed {
            background: var(--success);
            color: white;
            border: 1px solid var(--success);
        }
        
        .progress-step.pending {
            background: var(--bg-primary);
            color: var(--text-secondary);
            border: 2px dashed var(--border-medium);
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
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--accent);
            border: 1px solid var(--border-light);
        }
        
        .next-action h6 {
            color: var(--text-primary);
            margin-bottom: 1rem;
        }
        
        .next-action p {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .next-action small {
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <?php
    // 統一システムヘッダー（ヘッダー関数が存在する場合のみ実行）
    if (function_exists('renderSystemHeader')) {
        echo renderSystemHeader($user_name, $user_role_display, 'dashboard');
        echo renderPageHeader('tachometer-alt', 'ダッシュボード', 'システム全体の状況');
    } else {
        // フォールバック：従来のヘッダー
        ?>
        <div class="header" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; padding: 1rem;">
            <div class="container">
                <h1><i class="fas fa-taxi me-2"></i>福祉輸送管理システム</h1>
                <div class="user-info">
                    <?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_role_display) ?>) 
                    | <?= date('Y年n月j日 (D)') ?> <?= $current_time ?>
                    <a href="logout.php" class="btn btn-outline-light btn-sm ms-3">ログアウト</a>
                </div>
            </div>
        </div>
        <?php
    }
    ?>
    
    <div class="container-fluid mt-4">
        <!-- 業務漏れアラート -->
        <?php if (!empty($alerts)): ?>
        <div class="alerts-section">
            <?php 
            if (function_exists('renderSectionHeader')) {
                echo renderSectionHeader('exclamation-triangle', '重要なお知らせ・業務漏れ確認');
            } else {
                echo '<h4><i class="fas fa-exclamation-triangle me-2 text-danger"></i>重要なお知らせ・業務漏れ確認</h4>';
            }
            ?>
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
                    <div class="col-auto">
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
        <?php 
        if (function_exists('renderSectionHeader')) {
            echo renderSectionHeader('chart-bar', '今日の業務状況');
            echo renderStatsCards($stats_today);
        } else {
            // フォールバック：従来の統計表示
            ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2"></i>今日の業務状況</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 col-md-3">
                            <h3 class="text-primary"><?= $today_departures ?></h3>
                            <p class="small">今日の出庫</p>
                        </div>
                        <div class="col-6 col-md-3">
                            <h3 class="text-success"><?= $today_ride_records ?></h3>
                            <p class="small">今日の乗車</p>
                        </div>
                        <div class="col-6 col-md-3">
                            <h3 class="text-<?= ($today_departures - $today_arrivals > 0) ? 'danger' : 'success' ?>">
                                <?= max(0, $today_departures - $today_arrivals) ?>
                            </h3>
                            <p class="small">未入庫</p>
                        </div>
                        <div class="col-6 col-md-3">
                            <h3 class="text-info"><?= $today_pre_duty_calls ?>/<?= $today_post_duty_calls ?></h3>
                            <p class="small">乗務前/後点呼</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>
        
        <!-- 売上情報 -->
        <?php 
        if (function_exists('renderSectionHeader')) {
            echo renderSectionHeader('yen-sign', '売上実績');
        } else {
            echo '<h5><i class="fas fa-yen-sign me-2"></i>売上実績</h5>';
        }
        ?>
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card revenue-card">
                    <div class="card-body text-center">
                        <h6 class="mb-2"><i class="fas fa-yen-sign me-2"></i>今日の売上</h6>
                        <h2 class="mb-1">¥<?= number_format($today_total_revenue) ?></h2>
                        <small><?= $today_ride_records ?>回の乗車</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card revenue-month-card">
                    <div class="card-body text-center">
                        <h6 class="mb-2"><i class="fas fa-calendar-alt me-2"></i>今月の売上</h6>
                        <h2 class="mb-1">¥<?= number_format($month_total_revenue) ?></h2>
                        <small><?= $month_ride_records ?>回の乗車</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="mb-2"><i class="fas fa-chart-bar me-2"></i>月平均</h6>
                        <h2 class="mb-1 text-secondary">¥<?= number_format($month_avg_revenue) ?></h2>
                        <small class="text-muted">1日あたり平均売上</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- クイックアクション -->
        <?php 
        if (function_exists('renderSectionHeader')) {
            echo renderSectionHeader('route', '業務メニュー');
        } else {
            echo '<h5><i class="fas fa-route me-2"></i>業務メニュー</h5>';
        }
        ?>
        <div class="row">
            <!-- 運転者向け：1日の流れに沿った業務 -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-route me-2"></i>運転業務（1日の流れ）</h5>
                    </div>
                    <div class="card-body">
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
            </div>

            <!-- 1日の終了業務と管理業務 -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-moon me-2"></i>終業・管理業務</h5>
                    </div>
                    <div class="card-body">
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
                                <div class="quick-action-icon" style="color: #6366f1;">
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
                        
                        <a href="user_management.php" class="quick-action-btn">
                            <div class="quick-action-content">
                                <div class="quick-action-icon" style="color: #fd7e14;">
                                    <i class="fas fa-cogs"></i>
                                </div>
                                <div class="quick-action-text">
                                    <h6>システム管理</h6>
                                    <small>ユーザー・車両管理</small>
                                </div>
                            </div>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 今日の業務進捗ガイド -->
        <?php 
        if (function_exists('renderSectionHeader')) {
            echo renderSectionHeader('tasks', '今日の業務進捗ガイド');
        } else {
            echo '<h5><i class="fas fa-tasks me-2"></i>今日の業務進捗ガイド</h5>';
        }
        ?>
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
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

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 5分ごとにページを自動更新してアラートを更新
        setInterval(function() {
            window.location.reload();
        }, 300000); // 5分 = 300000ms

        // アラートが存在する場合、ブラウザ通知を表示
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

            // ヘッダー関数の存在確認（デバッグ用）
            console.log('統一ヘッダーシステム: ' + (typeof renderSystemHeader !== 'undefined' ? '適用済み' : 'フォールバック使用'));
            
            // 統計カードのアニメーション
            const statCards = document.querySelectorAll('.stat-card, .card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.animation = 'fadeInUp 0.6s ease-out';
                }, index * 100);
            });
        });

        // CSS変数が使用可能かチェック
        if (CSS.supports('color', 'var(--primary-start)')) {
            console.log('CSS変数サポート: OK');
        } else {
            console.log('CSS変数サポート: 古いブラウザ');
        }
    </script>

    <style>
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</body>
</html>
