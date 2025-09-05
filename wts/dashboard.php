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

// 業務進捗チェック機能
$flow_status = [];
$alerts = [];

try {
    // 今日のログインユーザーの業務進捗をチェック
    $user_id = $_SESSION['user_id'];
    
    // 1. 日常点検
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['daily_inspection'] = $stmt->fetchColumn() > 0;
    
    // 2. 乗務前点呼
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND driver_id = ? AND is_completed = TRUE");
    $stmt->execute([$today, $user_id]);
    $flow_status['pre_duty_call'] = $stmt->fetchColumn() > 0;
    
    // 3. 出庫
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['departure'] = $stmt->fetchColumn() > 0;
    
    // 4. 乗車記録
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records WHERE ride_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['ride_records'] = $stmt->fetchColumn() > 0;
    
    // 5. 入庫
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['arrival'] = $stmt->fetchColumn() > 0;
    
    // 6. 乗務後点呼
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND driver_id = ? AND is_completed = TRUE");
    $stmt->execute([$today, $user_id]);
    $flow_status['post_duty_call'] = $stmt->fetchColumn() > 0;
    
    // 7. 売上金確認
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_management WHERE confirmation_date = ? AND driver_id = ?");
    $stmt->execute([$today, $user_id]);
    $flow_status['cash_confirmation'] = $stmt->fetchColumn() > 0;
    
    // 業務フロー違反チェック
    if ($flow_status['ride_records'] && !$flow_status['daily_inspection']) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'message' => '日常点検を実施せずに乗車記録が登録されています。',
            'action' => 'daily_inspection.php',
            'action_text' => '日常点検を実施'
        ];
    }
    
    if ($flow_status['ride_records'] && !$flow_status['pre_duty_call']) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'message' => '乗務前点呼を実施せずに乗車記録が登録されています。',
            'action' => 'pre_duty_call.php',
            'action_text' => '乗務前点呼を実施'
        ];
    }
    
    if ($flow_status['ride_records'] && !$flow_status['departure']) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'message' => '出庫処理を実施せずに乗車記録が登録されています。',
            'action' => 'departure.php',
            'action_text' => '出庫処理を実施'
        ];
    }
    
    // 18時以降の終業チェック
    if ($current_hour >= 18) {
        if ($flow_status['departure'] && !$flow_status['arrival']) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-clock',
                'message' => '18時以降も入庫処理を完了していません。',
                'action' => 'arrival.php',
                'action_text' => '入庫処理を実施'
            ];
        }
        
        if ($flow_status['arrival'] && !$flow_status['post_duty_call']) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-user-clock',
                'message' => '入庫後、乗務後点呼を完了していません。',
                'action' => 'post_duty_call.php',
                'action_text' => '乗務後点呼を実施'
            ];
        }
        
        if ($flow_status['ride_records'] && !$flow_status['cash_confirmation']) {
            $alerts[] = [
                'type' => 'info',
                'priority' => 'medium',
                'icon' => 'fas fa-yen-sign',
                'message' => '本日の売上金確認がまだ完了していません。',
                'action' => 'cash_management.php',
                'action_text' => '売上金確認を実施'
            ];
        }
    }

    // 統計データ取得
    // 今日の全体統計
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT driver_id) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_active_drivers = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $today_total_rides = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_departures = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute([$today]);
    $today_arrivals = $stmt->fetchColumn();
    
    // 売上統計
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_fare), 0) FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $today_total_revenue = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_fare), 0) FROM ride_records WHERE ride_date >= ?");
    $stmt->execute([$current_month_start]);
    $month_total_revenue = $stmt->fetchColumn();
    
    // 平均売上計算
    $days_in_month = date('j');
    $month_avg_revenue = $days_in_month > 0 ? round($month_total_revenue / $days_in_month) : 0;
    
} catch (Exception $e) {
    error_log("Dashboard data error: " . $e->getMessage());
}

// アラートを優先度でソート
usort($alerts, function($a, $b) {
    $priority_order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    return $priority_order[$a['priority']] - $priority_order[$b['priority']];
});

// フロー定義
$flow_steps = [
    ['key' => 'daily_inspection', 'number' => '1', 'title' => '日常点検', 'icon' => 'tools', 'url' => 'daily_inspection.php', 'desc' => '法定義務・最初に実施'],
    ['key' => 'pre_duty_call', 'number' => '2', 'title' => '乗務前点呼', 'icon' => 'clipboard-check', 'url' => 'pre_duty_call.php', 'desc' => 'アルコール検査含む'],
    ['key' => 'departure', 'number' => '3', 'title' => '出庫処理', 'icon' => 'sign-out-alt', 'url' => 'departure.php', 'desc' => 'メーター・天候記録'],
    ['key' => 'ride_records', 'number' => '4', 'title' => '乗車記録', 'icon' => 'users', 'url' => 'ride_records.php', 'desc' => '営業中随時入力'],
    ['key' => 'arrival', 'number' => '5', 'title' => '入庫処理', 'icon' => 'sign-in-alt', 'url' => 'arrival.php', 'desc' => '燃料費・経費記録'],
    ['key' => 'post_duty_call', 'number' => '6', 'title' => '乗務後点呼', 'icon' => 'user-clock', 'url' => 'post_duty_call.php', 'desc' => '健康状態確認'],
    ['key' => 'cash_confirmation', 'number' => '7', 'title' => '売上金確認', 'icon' => 'yen-sign', 'url' => 'cash_management.php', 'desc' => '現金・カード売上確認']
];

// 現在のステップを特定
$current_step = 0;
foreach ($flow_steps as $index => $step) {
    if (!$flow_status[$step['key']]) {
        $current_step = $index;
        break;
    }
    if ($index === count($flow_steps) - 1) {
        $current_step = count($flow_steps);
    }
}

// 進捗計算
$completed_count = count(array_filter($flow_status));
$total_count = count($flow_status);
$progress_percent = round(($completed_count / $total_count) * 100);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - <?php echo htmlspecialchars($system_name); ?></title>
    
    <!-- 統一UI CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/ui-unified-v3.css">
    
    <style>
        .flow-step {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .flow-step.completed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .flow-step.current {
            background: linear-gradient(135deg, #2196F3 0%, #21CBF3 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);
            animation: pulse 2s infinite;
        }
        
        .flow-step.pending {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px dashed #dee2e6;
        }
        
        .flow-step:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3); }
            50% { box-shadow: 0 4px 25px rgba(33, 150, 243, 0.5); }
            100% { box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3); }
        }
        
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .completed .step-number {
            background: rgba(255,255,255,0.3);
        }
        
        .current .step-number {
            background: rgba(255,255,255,0.3);
            animation: bounce 1s infinite;
        }
        
        .pending .step-number {
            background: #e9ecef;
            color: #adb5bd;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
    </style>
</head>
<body>
    <!-- 統一システムヘッダー -->
    <header class="bg-primary text-white py-3">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4 class="mb-0">
                        <i class="fas fa-taxi me-2"></i>
                        <?php echo htmlspecialchars($system_name); ?>
                    </h4>
                </div>
                <div class="col-md-6 text-end">
                    <span class="me-3">
                        <i class="fas fa-user me-1"></i>
                        <?php echo htmlspecialchars($user_name); ?> (<?php echo htmlspecialchars($user_role_display); ?>)
                    </span>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt"></i> ログアウト
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <!-- 統一ページヘッダー -->
    <div class="bg-light border-bottom py-3">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-0">
                        <i class="fas fa-tachometer-alt text-primary me-2"></i>
                        ダッシュボード
                    </h2>
                    <p class="text-muted mb-0">日次運行フロー・業務状況管理</p>
                </div>
                <div class="col-md-4 text-end">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item active">ダッシュボード</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </div>
    
    <!-- メインコンテンツ -->
    <div class="container-fluid py-4">
        
        <!-- 業務漏れアラート -->
        <?php if (!empty($alerts)) { ?>
        <div class="section-header">
            <h3 class="section-title">
                <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                重要なお知らせ・業務漏れ確認
            </h3>
        </div>
        
        <?php foreach ($alerts as $alert) { ?>
        <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show" role="alert">
            <i class="<?php echo $alert['icon']; ?> me-2"></i>
            <?php echo htmlspecialchars($alert['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <div class="d-flex justify-content-end mb-3">
            <a href="<?php echo $alert['action']; ?>" class="btn btn-<?php echo $alert['type'] === 'danger' ? 'danger' : ($alert['type'] === 'warning' ? 'warning' : 'info'); ?>">
                <i class="fas fa-arrow-right"></i> <?php echo htmlspecialchars($alert['action_text']); ?>
            </a>
        </div>
        <?php } ?>
        <?php } ?>
        
        <!-- 日次運行フロー -->
        <div class="section-header mb-4">
            <h3 class="section-title">
                <i class="fas fa-route text-primary me-2"></i>
                日次運行フロー（7段階）
            </h3>
            <p class="section-description"><?php echo htmlspecialchars($user_name); ?> さんの本日の進捗状況</p>
        </div>
        
        <div class="row g-2 mb-4">
            <?php foreach ($flow_steps as $index => $step) { 
                $is_completed = $flow_status[$step['key']];
                $is_current = ($index === $current_step);
                $is_pending = ($index > $current_step);
                $status_class = $is_completed ? 'completed' : ($is_current ? 'current' : 'pending');
            ?>
                
                <div class="col-lg-4 col-md-6 mb-3">
                    <a href="<?php echo $step['url']; ?>" class="text-decoration-none">
                        <div class="card flow-step <?php echo $status_class; ?> h-100">
                            <div class="card-body text-center p-3">
                                <div class="step-number mx-auto">
                                    <?php echo $is_completed ? '<i class="fas fa-check"></i>' : $step['number']; ?>
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-<?php echo $step['icon']; ?> fa-2x mb-2"></i>
                                </div>
                                <h6 class="card-title mb-2"><?php echo $step['title']; ?></h6>
                                <p class="card-text small mb-0"><?php echo $step['desc']; ?></p>
                                
                                <?php if ($is_completed) { ?>
                                    <div class="mt-2">
                                        <small class="badge bg-light text-dark">
                                            <i class="fas fa-check-circle text-success"></i> 完了
                                        </small>
                                        <a href="<?php echo $step['url']; ?>?action=edit" class="badge bg-secondary ms-1" onclick="event.stopPropagation();">
                                            <i class="fas fa-edit"></i> 修正
                                        </a>
                                    </div>
                                <?php } elseif ($is_current) { ?>
                                    <div class="mt-2">
                                        <small class="badge bg-light text-dark">
                                            <i class="fas fa-arrow-right text-primary"></i> 次の作業
                                        </small>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </a>
                </div>
            <?php } ?>
        </div>
        
        <!-- 進捗サマリー -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h6 class="mb-3">本日の進捗: <?php echo $completed_count; ?>/<?php echo $total_count; ?> 完了 (<?php echo $progress_percent; ?>%)</h6>
                        <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>
                        
                        <?php if ($current_step < count($flow_steps)) { ?>
                            <p class="mb-0 text-primary">
                                <i class="fas fa-info-circle"></i> 
                                次の作業: <strong><?php echo $flow_steps[$current_step]['title']; ?></strong>
                            </p>
                        <?php } else { ?>
                            <p class="mb-0 text-success">
                                <i class="fas fa-check-circle"></i> 
                                本日の業務フローが全て完了しています！お疲れ様でした。
                            </p>
                        <?php } ?>
                    </div>
                    <div class="col-md-4 text-center">
                        <?php if ($progress_percent === 100) { ?>
                            <div class="text-success">
                                <i class="fas fa-trophy fa-3x mb-2"></i>
                                <div><strong>業務完了</strong></div>
                            </div>
                        <?php } else { ?>
                            <div class="text-primary">
                                <i class="fas fa-tasks fa-3x mb-2"></i>
                                <div><strong><?php echo 7 - $completed_count; ?> 件残り</strong></div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 今日の全体状況 -->
        <div class="section-header mb-4">
            <h3 class="section-title">
                <i class="fas fa-chart-line text-primary me-2"></i>
                今日の全体状況
            </h3>
            <p class="section-description"><?php echo date('Y年n月j日 (D)', strtotime($today)); ?> <?php echo $current_time; ?> 現在</p>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-user-tie fa-2x text-secondary mb-3"></i>
                        <h5><?php echo $today_active_drivers; ?> 人</h5>
                        <p class="card-text">稼働運転者</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-users fa-2x text-success mb-3"></i>
                        <h5><?php echo $today_total_rides; ?> 回</h5>
                        <p class="card-text">総乗車回数</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-exclamation-triangle fa-2x <?php echo ($today_departures - $today_arrivals > 0) ? 'text-danger' : 'text-success'; ?> mb-3"></i>
                        <h5><?php echo ($today_departures - $today_arrivals); ?> 台</h5>
                        <p class="card-text">未入庫車両</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-yen-sign fa-2x text-success mb-3"></i>
                        <h5>¥<?php echo number_format($today_total_revenue); ?></h5>
                        <p class="card-text">本日売上</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 管理機能（権限別表示） -->
        <?php if ($is_admin) { ?>
        <div class="section-header mb-4">
            <h3 class="section-title">
                <i class="fas fa-cogs text-secondary me-2"></i>
                管理機能
            </h3>
            <p class="section-description">システム管理者専用機能</p>
        </div>
        
        <div class="row">
            <div class="col-md-3 mb-3">
                <a href="master_menu.php" class="card h-100 text-decoration-none">
                    <div class="card-body text-center">
                        <i class="fas fa-database fa-2x mb-3 text-secondary"></i>
                        <h6 class="card-title">マスタ管理</h6>
                        <p class="card-text small text-muted">ユーザー・車両管理</p>
                    </div>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="annual_report.php" class="card h-100 text-decoration-none">
                    <div class="card-body text-center">
                        <i class="fas fa-file-alt fa-2x mb-3 text-info"></i>
                        <h6 class="card-title">陸運局報告</h6>
                        <p class="card-text small text-muted">年次報告書作成</p>
                    </div>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="accident_management.php" class="card h-100 text-decoration-none">
                    <div class="card-body text-center">
                        <i class="fas fa-exclamation-circle fa-2x mb-3 text-warning"></i>
                        <h6 class="card-title">事故管理</h6>
                        <p class="card-text small text-muted">事故記録・報告</p>
                    </div>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="export_document.php" class="card h-100 text-decoration-none">
                    <div class="card-body text-center">
                        <i class="fas fa-download fa-2x mb-3 text-success"></i>
                        <h6 class="card-title">緊急監査対応</h6>
                        <p class="card-text small text-muted">5分で準備完了</p>
                    </div>
                </a>
            </div>
        </div>
        <?php } ?>
        
    </div>

    <!-- 統一JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/ui-interactions.js"></script>
    
    <script>
        // 業務フロー カードのアニメーション
        document.addEventListener('DOMContentLoaded', function() {
            const flowSteps = document.querySelectorAll('.flow-step');
            flowSteps.forEach((step, index) => {
                setTimeout(() => {
                    step.style.transform = 'translateY(20px)';
                    step.style.opacity = '0';
                    step.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                    
                    setTimeout(() => {
                        step.style.transform = 'translateY(0)';
                        step.style.opacity = '1';
                    }, 50);
                }, index * 100);
            });
            
            // 重要なアラートがある場合のブラウザ通知
            <?php if (!empty($alerts) && in_array('critical', array_column($alerts, 'priority'))) { ?>
            if (Notification.permission === "granted") {
                new Notification("重要な業務漏れがあります", {
                    body: "<?php echo isset($alerts[0]) ? htmlspecialchars($alerts[0]['message']) : ''; ?>",
                    icon: "/favicon.ico"
                });
            } else if (Notification.permission !== "denied") {
                Notification.requestPermission().then(function (permission) {
                    if (permission === "granted") {
                        new Notification("重要な業務漏れがあります", {
                            body: "<?php echo isset($alerts[0]) ? htmlspecialchars($alerts[0]['message']) : ''; ?>",
                            icon: "/favicon.ico"
                        });
                    }
                });
            }
            <?php } ?>
            
            // フロー完了時の効果音・振動
            const completedSteps = document.querySelectorAll('.flow-step.completed');
            if (completedSteps.length === 7 && <?php echo $progress_percent; ?> === 100) {
                // 全業務完了時のお祝い効果
                if (navigator.vibrate) {
                    navigator.vibrate([200, 100, 200, 100, 200]);
                }
                
                // 紙吹雪エフェクト（簡易版）
                setTimeout(() => {
                    confetti();
                }, 1000);
            }
            
            // クリック時のフィードバック
            const actionCards = document.querySelectorAll('.flow-step, .card');
            actionCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    // ハプティックフィードバック
                    if (navigator.vibrate) {
                        navigator.vibrate(50);
                    }
                    
                    // カードアニメーション
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 150);
                });
            });
        });
        
        // 簡易紙吹雪エフェクト
        function confetti() {
            const colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FECA57'];
            
            for (let i = 0; i < 50; i++) {
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.style.cssText = `
                        position: fixed;
                        width: 10px;
                        height: 10px;
                        background: ${colors[Math.floor(Math.random() * colors.length)]};
                        left: ${Math.random() * window.innerWidth}px;
                        top: -10px;
                        z-index: 9999;
                        animation: fall 3s linear forwards;
                        pointer-events: none;
                    `;
                    
                    document.body.appendChild(confetti);
                    
                    setTimeout(() => {
                        confetti.remove();
                    }, 3000);
                }, i * 100);
            }
        }
        
        // 紙吹雪アニメーション用CSS
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fall {
                0% { transform: translateY(-10px) rotate(0deg); }
                100% { transform: translateY(${window.innerHeight + 10}px) rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
        
        // 5分ごとにアラートを更新（ページリロードなし）
        setInterval(async function() {
            try {
                const response = await fetch('api/dashboard_alerts.php');
                const data = await response.json();
                
                if (data.alerts && data.alerts.length > 0) {
                    // アラート表示の更新
                    updateAlerts(data.alerts);
                }
            } catch (error) {
                console.log('アラート更新エラー:', error);
            }
        }, 300000); // 5分
        
        function updateAlerts(alerts) {
            // アラート表示の動的更新（必要に応じて実装）
            console.log('新しいアラート:', alerts);
        }
    </script>
</body>
</html>
