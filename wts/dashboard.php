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

// 新権限システム対応：セッションから基本情報取得
$user_name = $_SESSION['user_name'] ?? '不明なユーザー';
$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$current_time = date('H:i');
$current_hour = date('H');

// 当月・前月の開始日
$current_month_start = date('Y-m-01');
$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end = date('Y-m-t', strtotime('-1 month'));

// 新権限システム：データベースからユーザー情報を取得
$permission_level = 'User'; // デフォルト
$user_permissions = [
    'is_driver' => false,
    'is_caller' => false, 
    'is_mechanic' => false,
    'is_manager' => false
];

try {
    $stmt = $pdo->prepare("SELECT permission_level, is_driver, is_caller, is_mechanic, is_manager FROM users WHERE id = ? AND active = TRUE");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    if ($user_data) {
        $permission_level = $user_data['permission_level'] ?: 'User';
        $user_permissions = [
            'is_driver' => (bool)$user_data['is_driver'],
            'is_caller' => (bool)$user_data['is_caller'],
            'is_mechanic' => (bool)$user_data['is_mechanic'],
            'is_manager' => (bool)$user_data['is_manager']
        ];
    }
} catch (Exception $e) {
    error_log("User permission error: " . $e->getMessage());
    // デフォルト値を使用して継続
}

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

// 改善されたアラートシステム
$alerts = [];

try {
    // 1. 【改善】実際に法令違反リスクがある場合のみアラート
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            r.driver_id, u.name as driver_name, v.vehicle_number,
            COUNT(r.id) as ride_count,
            CASE WHEN pdc.id IS NULL THEN 0 ELSE 1 END as has_pre_duty,
            CASE WHEN di.id IS NULL THEN 0 ELSE 1 END as has_daily_inspection,
            CASE WHEN dr.id IS NULL THEN 0 ELSE 1 END as has_departure
        FROM ride_records r
        JOIN users u ON r.driver_id = u.id
        JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN pre_duty_calls pdc ON r.driver_id = pdc.driver_id AND r.ride_date = pdc.call_date AND pdc.is_completed = TRUE
        LEFT JOIN daily_inspections di ON r.vehicle_id = di.vehicle_id AND r.ride_date = di.inspection_date
        LEFT JOIN departure_records dr ON r.driver_id = dr.driver_id AND r.vehicle_id = dr.vehicle_id AND r.ride_date = dr.departure_date
        WHERE r.ride_date = ?
        GROUP BY r.driver_id, u.name, v.vehicle_number
        HAVING (has_pre_duty = 0 OR has_daily_inspection = 0 OR has_departure = 0) AND ride_count > 0
    ");
    $stmt->execute([$today]);
    $critical_violations = $stmt->fetchAll();
    
    foreach ($critical_violations as $violation) {
        $missing_items = [];
        if (!$violation['has_daily_inspection']) $missing_items[] = '日常点検';
        if (!$violation['has_pre_duty']) $missing_items[] = '乗務前点呼';
        if (!$violation['has_departure']) $missing_items[] = '出庫処理';
        
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'title' => '法令違反の可能性',
            'message' => "運転者「{$violation['driver_name']}」が" . implode('・', $missing_items) . "未実施で営業（{$violation['ride_count']}件の乗車記録）を行っています。",
            'action' => 'daily_inspection.php',
            'action_text' => '必須処理を実施'
        ];
    }

    // 2. 【改善】長時間未入庫のみアラート（18時以降かつ6時間以上経過）
    if ($current_hour >= 18) {
        $stmt = $pdo->prepare("
            SELECT dr.vehicle_id, v.vehicle_number, u.name as driver_name, 
                   dr.departure_time, 
                   TIMESTAMPDIFF(HOUR, CONCAT(dr.departure_date, ' ', dr.departure_time), NOW()) as hours_elapsed
            FROM departure_records dr
            JOIN vehicles v ON dr.vehicle_id = v.id
            JOIN users u ON dr.driver_id = u.id
            LEFT JOIN arrival_records ar ON dr.vehicle_id = ar.vehicle_id AND dr.departure_date = ar.arrival_date
            WHERE dr.departure_date = ? AND ar.id IS NULL
            AND TIMESTAMPDIFF(HOUR, CONCAT(dr.departure_date, ' ', dr.departure_time), NOW()) >= 6
        ");
        $stmt->execute([$today]);
        $long_not_arrived = $stmt->fetchAll();
        
        foreach ($long_not_arrived as $vehicle) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-clock',
                'title' => '長時間未入庫',
                'message' => "車両「{$vehicle['vehicle_number']}」（運転者：{$vehicle['driver_name']}）が出庫から{$vehicle['hours_elapsed']}時間経過しても入庫していません。",
                'action' => 'arrival.php',
                'action_text' => '入庫処理を実施'
            ];
        }
    }

    // 今日の統計データ取得
    // 乗務前点呼
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_pre_duty_calls = $stmt->fetchColumn() ?: 0;
    
    // 乗務後点呼
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_post_duty_calls = $stmt->fetchColumn() ?: 0;
    
    // 出庫・入庫記録
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_departures = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute([$today]);
    $today_arrivals = $stmt->fetchColumn() ?: 0;
    
    // 今日の乗車記録と売上
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    $today_ride_records = $result ? (int)$result['count'] : 0;
    $today_total_revenue = $result ? (int)$result['revenue'] : 0;

    // 当月の売上データ
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date >= ?");
    $stmt->execute([$current_month_start]);
    $result = $stmt->fetch();
    $month_ride_records = $result ? (int)$result['count'] : 0;
    $month_total_revenue = $result ? (int)$result['revenue'] : 0;

    // 前月の売上データ
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date >= ? AND ride_date <= ?");
    $stmt->execute([$last_month_start, $last_month_end]);
    $result = $stmt->fetch();
    $last_month_revenue = $result ? (int)$result['revenue'] : 0;

    // 売上増減率計算
    $revenue_change_percent = 0;
    if ($last_month_revenue > 0) {
        $revenue_change_percent = round((($month_total_revenue - $last_month_revenue) / $last_month_revenue) * 100, 1);
    }
    
    // 平均売上計算
    $days_in_month = (int)date('j');
    $month_avg_revenue = $days_in_month > 0 ? round($month_total_revenue / $days_in_month) : 0;
    
    // 日常点検完了状況
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date = ?");
    $stmt->execute([$today]);
    $today_daily_inspections = $stmt->fetchColumn() ?: 0;
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    
    // エラー時のデフォルト値
    $today_pre_duty_calls = $today_post_duty_calls = $today_departures = $today_arrivals = 0;
    $today_ride_records = $today_total_revenue = $month_ride_records = $month_total_revenue = 0;
    $last_month_revenue = $revenue_change_percent = $month_avg_revenue = $today_daily_inspections = 0;
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* 改善されたアラートスタイル */
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
        
        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* 売上表示の大幅改善 */
        .revenue-dashboard {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .revenue-card-large {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .revenue-number-large {
            font-size: 3rem;
            font-weight: 700;
            margin: 0;
        }
        
        .revenue-change {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 0.5rem;
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
        
        /* 権限別メニューセクション */
        .menu-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .menu-section h5 {
            color: #333;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .menu-btn {
            display: block;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1rem;
            text-decoration: none;
            color: #333;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
            min-height: 70px;
        }
        
        .menu-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.15);
        }
        
        .menu-btn-content {
            display: flex;
            align-items: center;
        }
        
        .menu-btn-icon {
            font-size: 1.8rem;
            margin-right: 1rem;
            min-width: 2.5rem;
        }
        
        .menu-btn-text h6 {
            margin: 0;
            font-weight: 600;
        }
        
        .menu-btn-text small {
            color: #6c757d;
            font-size: 0.75rem;
        }
        
        /* 色分け */
        .text-purple { color: #6f42c1; }
        .text-orange { color: #fd7e14; }
        .text-teal { color: #20c997; }
        
        /* チャート表示エリア */
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 1rem;
        }
        
        /* バッジスタイル */
        .permission-badge {
            font-size: 0.7rem;
            margin-left: 0.25rem;
        }
        
        @media (max-width: 768px) {
            .revenue-number-large {
                font-size: 2.5rem;
            }
            .stats-number {
                font-size: 2rem;
            }
            .menu-btn {
                padding: 0.8rem;
                min-height: 60px;
            }
            .menu-btn-icon {
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
                        (<?= $permission_level === 'Admin' ? 'システム管理者' : 'ユーザー' ?>)
                        <?php if ($user_permissions['is_driver']): ?>
                            <span class="badge bg-primary permission-badge">運転者</span>
                        <?php endif; ?>
                        <?php if ($user_permissions['is_caller']): ?>
                            <span class="badge bg-warning permission-badge">点呼者</span>
                        <?php endif; ?>
                        <?php if ($user_permissions['is_manager']): ?>
                            <span class="badge bg-success permission-badge">管理者</span>
                        <?php endif; ?>
                        <br class="d-md-none"><span class="d-none d-md-inline">| </span><?= date('Y年n月j日 (D)', strtotime($today)) ?> <?= $current_time ?>
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
        <!-- 重要なアラート（改善版：本当に重要なもののみ） -->
        <?php if (!empty($alerts)): ?>
        <div class="alerts-section">
            <h4><i class="fas fa-exclamation-triangle me-2 text-danger"></i>重要な業務確認</h4>
            <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-item alert-<?= $alert['priority'] ?>">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <i class="<?= $alert['icon'] ?>"></i>
                    </div>
                    <div class="col">
                        <div class="fw-bold"><?= htmlspecialchars($alert['title']) ?></div>
                        <div><?= htmlspecialchars($alert['message']) ?></div>
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

        <!-- 売上ダッシュボード（大幅改善） -->
        <div class="revenue-dashboard">
            <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>売上・収益状況</h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="revenue-card-large">
                        <h6 class="mb-2"><i class="fas fa-calendar-day me-2"></i>今日の売上</h6>
                        <div class="revenue-number-large">¥<?= number_format($today_total_revenue) ?></div>
                        <div style="color: rgba(255,255,255,0.8); font-size: 0.9rem;">
                            <?= $today_ride_records ?>回の乗車
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <h6 class="mb-2 text-info"><i class="fas fa-calendar-alt me-2"></i>今月の売上</h6>
                        <div class="stats-number text-info">¥<?= number_format($month_total_revenue) ?></div>
                        <div class="stats-label"><?= $month_ride_records ?>回の乗車</div>
                        <?php if ($last_month_revenue > 0): ?>
                        <div class="revenue-change mt-2" style="color: <?= $revenue_change_percent >= 0 ? '#28a745' : '#dc3545' ?>;">
                            <i class="fas fa-<?= $revenue_change_percent >= 0 ? 'arrow-up' : 'arrow-down' ?> me-1"></i>
                            前月比 <?= abs($revenue_change_percent) ?>%
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <h6 class="mb-2 text-secondary"><i class="fas fa-chart-bar me-2"></i>日平均売上</h6>
                        <div class="stats-number text-secondary">¥<?= number_format($month_avg_revenue) ?></div>
                        <div class="stats-label">今月の1日あたり平均</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 今日の業務状況 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="stats-card">
                    <h5 class="mb-3"><i class="fas fa-tasks me-2"></i>今日の業務状況</h5>
                    <div class="row text-center">
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-success"><?= $today_ride_records ?></div>
                            <div class="stats-label">乗車</div>
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

        <!-- 権限レベル別メニュー -->
        <div class="row">
            <!-- 基本業務（全ユーザー共通） -->
            <div class="col-lg-6">
                <div class="menu-section">
                    <h5><i class="fas fa-route me-2"></i>📋 基本業務</h5>
                    
                    <a href="daily_inspection.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-secondary">
                                <i class="fas fa-tools"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>日常点検</h6>
                                <small>17項目の点検・法定義務</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="pre_duty_call.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-warning">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>乗務前点呼</h6>
                                <small>15項目確認・アルコールチェック</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="departure.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-primary">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>出庫処理</h6>
                                <small>点呼・点検完了後の出庫記録</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="ride_records.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-success">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>乗車記録</h6>
                                <small>営業中の乗車記録・復路作成機能</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="arrival.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-info">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>入庫処理</h6>
                                <small>営業終了時の入庫記録</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="post_duty_call.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-danger">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>乗務後点呼</h6>
                                <small>7項目確認・入庫後に実施</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="periodic_inspection.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-purple">
                                <i class="fas fa-wrench"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>定期点検</h6>
                                <small>3ヶ月点検・7カテゴリー</small>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- 管理業務・権限別表示 -->
            <div class="col-lg-6">
                <!-- 管理者限定機能 -->
                <?php if ($permission_level === 'Admin'): ?>
                <div class="menu-section">
                    <h5><i class="fas fa-shield-alt me-2"></i>⚙️ システム管理</h5>
                    
                    <a href="user_management.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-orange">
                                <i class="fas fa-users-cog"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>ユーザー管理</h6>
                                <small>運転者・点呼者・権限管理</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="vehicle_management.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-orange">
                                <i class="fas fa-car"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>車両管理</h6>
                                <small>車両情報・点検期限管理</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="master_menu.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-orange">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>システム設定</h6>
                                <small>システム名・各種設定</small>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="menu-section">
                    <h5><i class="fas fa-money-bill-wave me-2"></i>💰 集金・経理業務</h5>
                    
                    <a href="cash_management.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-success">
                                <i class="fas fa-cash-register"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>集金管理</h6>
                                <small>日次売上集計・入金確認・差額管理</small>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="menu-section">
                    <h5><i class="fas fa-file-alt me-2"></i>📄 監査・報告業務</h5>
                    
                    <a href="emergency_audit_kit.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>緊急監査対応</h6>
                                <small>5分で監査書類完全準備</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="annual_report.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-info">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>陸運局提出</h6>
                                <small>年度集計・第4号様式・輸送実績</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="accident_management.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-warning">
                                <i class="fas fa-car-crash"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>事故管理</h6>
                                <small>事故記録・統計・報告書作成</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="adaptive_export_document.php" class="menu-btn">
                        <div class="menu-btn-content">
                            <div class="menu-btn-icon text-teal">
                                <i class="fas fa-file-export"></i>
                            </div>
                            <div class="menu-btn-text">
                                <h6>適応型出力</h6>
                                <small>任意のDB構造対応・帳票出力</small>
                            </div>
                        </div>
                    </a>
                </div>

                <?php else: ?>
                <!-- 一般ユーザー向け：実績・統計表示 -->
                <div class="menu-section">
                    <h5><i class="fas fa-chart-bar me-2"></i>📊 実績・統計</h5>
                    
                    <div class="stats-card">
                        <h6 class="text-center mb-3">売上統計</h6>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stats-number text-success" style="font-size: 1.8rem;">¥<?= number_format($today_total_revenue) ?></div>
                                <div class="stats-label">今日の売上</div>
                            </div>
                            <div class="col-6">
                                <div class="stats-number text-info" style="font-size: 1.8rem;">¥<?= number_format($month_total_revenue) ?></div>
                                <div class="stats-label">今月の売上</div>
                            </div>
                        </div>
                        <?php if ($last_month_revenue > 0): ?>
                        <div class="text-center mt-2">
                            <small class="text-muted">
                                前月比 
                                <span style="color: <?= $revenue_change_percent >= 0 ? '#28a745' : '#dc3545' ?>;">
                                    <i class="fas fa-<?= $revenue_change_percent >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                                    <?= abs($revenue_change_percent) ?>%
                                </span>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stats-card">
                        <h6 class="text-center mb-3">運行実績</h6>
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="stats-number text-primary" style="font-size: 1.5rem;"><?= $today_ride_records ?></div>
                                <div class="stats-label">今日の乗車</div>
                            </div>
                            <div class="col-4">
                                <div class="stats-number text-warning" style="font-size: 1.5rem;"><?= $month_ride_records ?></div>
                                <div class="stats-label">今月の乗車</div>
                            </div>
                            <div class="col-4">
                                <div class="stats-number text-secondary" style="font-size: 1.5rem;">¥<?= number_format($month_avg_revenue) ?></div>
                                <div class="stats-label">日平均売上</div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 業務進捗ガイド -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="stats-card">
                    <h5 class="mb-3"><i class="fas fa-route me-2"></i>今日の業務進捗</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="progress-guide">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <div class="progress-step <?= ($today_pre_duty_calls > 0 && $today_daily_inspections > 0) ? 'completed' : 'pending' ?>">
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
                                        <div class="progress-step <?= ($today_arrivals > 0 && $today_post_duty_calls > 0) ? 'completed' : 'pending' ?>">
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
        // 重要なアラートがある場合のブラウザ通知
        <?php if (!empty($alerts) && in_array('critical', array_column($alerts, 'priority'))): ?>
        if (Notification.permission === "granted") {
            new Notification("重要な業務確認があります", {
                body: "<?= isset($alerts[0]) ? str_replace(['"', "'", "\n"], ['\"', "\'", " "], $alerts[0]['message']) : '' ?>",
                icon: "/favicon.ico"
            });
        } else if (Notification.permission !== "denied") {
            Notification.requestPermission().then(function (permission) {
                if (permission === "granted") {
                    new Notification("重要な業務確認があります", {
                        body: "<?= isset($alerts[0]) ? str_replace(['"', "'", "\n"], ['\"', "\'", " "], $alerts[0]['message']) : '' ?>",
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
        });

        // 5分ごとの自動更新（アラート更新のため）
        setInterval(function() {
            // 現在のスクロール位置を保持
            const scrollY = window.scrollY;
            window.location.reload();
            // リロード後にスクロール位置を復元
            window.scrollTo(0, scrollY);
        }, 300000); // 5分

        console.log('新権限システム対応ダッシュボード loaded');
        console.log('Permission Level: <?= $permission_level ?>');
        console.log('User Permissions:', <?= json_encode($user_permissions) ?>);
    </script>
</body>
</html> text-primary"><?= $today_departures ?></div>
                            <div class="stats-label">出庫</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stats-number
