<?php
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/session_check.php';
require_once 'includes/unified-header.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'ユーザー';
$user_role = $_SESSION['permission_level'] ?? $_SESSION['user_role'] ?? 'User';

// ユーザー詳細情報取得
try {
    $stmt = $pdo->prepare("SELECT name, permission_level, is_driver, is_caller, is_manager FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();

    if (!$user_data) {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    $user_name = $user_data['name'];
    $user_permission_level = $user_data['permission_level'];
    $is_admin = ($user_permission_level === 'Admin');

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
    error_log("ダッシュボード ユーザー取得エラー: " . $e->getMessage());
    session_destroy();
    header('Location: index.php');
    exit;
}

$today = date('Y-m-d');
$current_time = date('H:i');
$current_hour = (int)date('H');
$current_month_start = date('Y-m-01');

// システム名取得
$system_name = '福祉輸送管理システム';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) $system_name = $result['setting_value'];
} catch (Exception $e) {
    // デフォルト値を使用
}

// 売上計算関数
function calculateRevenue($pdo, $date_condition, $params = []) {
    $sql = "
        SELECT
            COUNT(*) as ride_count,
            SUM(COALESCE(passenger_count, 0)) as total_passengers,
            SUM(
                CASE
                    WHEN total_fare IS NOT NULL AND total_fare > 0 THEN total_fare
                    WHEN (fare IS NOT NULL OR charge IS NOT NULL) THEN (COALESCE(fare, 0) + COALESCE(charge, 0))
                    ELSE 0
                END
            ) as total_revenue,
            ROUND(AVG(
                CASE
                    WHEN total_fare IS NOT NULL AND total_fare > 0 THEN total_fare
                    WHEN (fare IS NOT NULL OR charge IS NOT NULL) THEN (COALESCE(fare, 0) + COALESCE(charge, 0))
                    ELSE 0
                END
            ), 0) as avg_fare
        FROM ride_records
        WHERE {$date_condition} AND COALESCE(is_sample_data, 0) = 0
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

// 安全なカウント
function safeDashboardCount($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        return 0;
    }
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

// 同期間比較
$revenue_difference = 0;
$revenue_percentage = 0;
$revenue_trend = 'neutral';
try {
    $current_day = date('j');
    $this_month_end = date('Y-m-' . sprintf('%02d', $current_day));
    $prev_month_start = date('Y-m-01', strtotime('-1 month'));
    $prev_month_end = date('Y-m-' . sprintf('%02d', min($current_day, date('t', strtotime('-1 month')))), strtotime('-1 month'));

    $last_month_stats = calculateRevenue($pdo, "ride_date BETWEEN ? AND ?", [$prev_month_start, $prev_month_end]);
    $this_month_stats = calculateRevenue($pdo, "ride_date BETWEEN ? AND ?", [$current_month_start, $this_month_end]);

    $last_month_revenue = $last_month_stats['total_revenue'] ?? 0;
    $this_month_revenue = $this_month_stats['total_revenue'] ?? 0;

    $revenue_difference = $this_month_revenue - $last_month_revenue;
    $revenue_percentage = $last_month_revenue > 0 ? round(($revenue_difference / $last_month_revenue) * 100, 1) : 0;
    $revenue_trend = $revenue_difference >= 0 ? 'up' : 'down';
} catch (Exception $e) {
    // デフォルト値のまま
}

// 日平均売上
$working_days = safeDashboardCount($pdo, "SELECT COUNT(DISTINCT ride_date) FROM ride_records WHERE ride_date >= ? AND COALESCE(is_sample_data, 0) = 0", [$current_month_start]);
$working_days = max($working_days, 1);
$month_avg_daily_revenue = round($month_total_revenue / $working_days);

// アラート生成
$alerts = [];
try {
    // Critical: 乗務前点呼未実施で乗車記録あり
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.driver_id, u.name as driver_name, COUNT(r.id) as ride_count
        FROM ride_records r
        JOIN users u ON r.driver_id = u.id
        LEFT JOIN pre_duty_calls pdc ON r.driver_id = pdc.driver_id AND r.ride_date = pdc.call_date AND pdc.is_completed = TRUE
        WHERE r.ride_date = ? AND pdc.id IS NULL
        GROUP BY r.driver_id, u.name
    ");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $driver) {
        $alerts[] = [
            'type' => 'danger', 'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle', 'title' => '乗務前点呼未実施',
            'message' => "運転者「{$driver['driver_name']}」が乗務前点呼を行わずに乗車記録（{$driver['ride_count']}件）を登録しています。",
            'action' => 'pre_duty_call.php'
        ];
    }

    // High: 18時以降の未入庫
    if ($current_hour >= 18) {
        $stmt = $pdo->prepare("
            SELECT v.vehicle_number, u.name as driver_name
            FROM departure_records dr
            JOIN vehicles v ON dr.vehicle_id = v.id
            JOIN users u ON dr.driver_id = u.id
            LEFT JOIN arrival_records ar ON dr.vehicle_id = ar.vehicle_id AND dr.departure_date = ar.arrival_date
            WHERE dr.departure_date = ? AND ar.id IS NULL
        ");
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll() as $vehicle) {
            $alerts[] = [
                'type' => 'warning', 'priority' => 'high',
                'icon' => 'fas fa-clock', 'title' => '入庫処理未完了',
                'message' => "車両「{$vehicle['vehicle_number']}」（{$vehicle['driver_name']}）が入庫処理を完了していません。",
                'action' => 'arrival.php'
            ];
        }
    }

    // 書類期限切れチェック
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()");
        $stmt->execute();
        $expired_docs = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        $stmt->execute();
        $expiring_soon_docs = (int)$stmt->fetchColumn();

        if ($expired_docs > 0) {
            $alerts[] = [
                'type' => 'danger', 'priority' => 'high',
                'icon' => 'fas fa-file-exclamation',
                'title' => '書類期限切れ',
                'message' => "期限切れの書類が{$expired_docs}件あります。早急に更新してください。",
                'action' => 'document_management.php?expiry=expired'
            ];
        }
        if ($expiring_soon_docs > 0) {
            $alerts[] = [
                'type' => 'warning', 'priority' => 'normal',
                'icon' => 'fas fa-file-contract',
                'title' => '書類期限間近',
                'message' => "7日以内に期限切れとなる書類が{$expiring_soon_docs}件あります。",
                'action' => 'document_management.php?expiry=expired'
            ];
        }
    } catch (Exception $e) {
        // 書類テーブルが存在しない場合等はスキップ
    }

    // 乗務員 免許・健診・適性診断 期限チェック
    try {
        $driver_alert_configs = [
            [
                'column' => 'driver_license_expiry',
                'icon_danger' => 'fas fa-id-card',
                'icon_warning' => 'fas fa-id-card',
                'title_danger' => '運転免許期限切れ',
                'title_warning' => '運転免許期限間近',
                'msg_danger' => '期限切れの運転免許がある乗務員が%d名います。早急に更新してください。',
                'msg_warning' => '30日以内に期限切れとなる運転免許がある乗務員が%d名います。',
            ],
            [
                'column' => 'health_check_next',
                'icon_danger' => 'fas fa-heartbeat',
                'icon_warning' => 'fas fa-heartbeat',
                'title_danger' => '健康診断期限切れ',
                'title_warning' => '健康診断期限間近',
                'msg_danger' => '健康診断の期限が切れている乗務員が%d名います。早急に受診してください。',
                'msg_warning' => '30日以内に健康診断の期限が切れる乗務員が%d名います。',
            ],
            [
                'column' => 'aptitude_test_next',
                'icon_danger' => 'fas fa-clipboard-check',
                'icon_warning' => 'fas fa-clipboard-check',
                'title_danger' => '適性診断期限切れ',
                'title_warning' => '適性診断期限間近',
                'msg_danger' => '適性診断の期限が切れている乗務員が%d名います。早急に受診してください。',
                'msg_warning' => '30日以内に適性診断の期限が切れる乗務員が%d名います。',
            ],
        ];

        foreach ($driver_alert_configs as $cfg) {
            $col = $cfg['column'];
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_driver = 1 AND {$col} IS NOT NULL AND {$col} < CURDATE()");
            $stmt->execute();
            $expired = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_driver = 1 AND {$col} IS NOT NULL AND {$col} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
            $stmt->execute();
            $expiring = (int)$stmt->fetchColumn();

            if ($expired > 0) {
                $alerts[] = [
                    'type' => 'danger', 'priority' => 'high',
                    'icon' => $cfg['icon_danger'],
                    'title' => $cfg['title_danger'],
                    'message' => sprintf($cfg['msg_danger'], $expired),
                    'action' => 'user_management.php'
                ];
            }
            if ($expiring > 0) {
                $alerts[] = [
                    'type' => 'warning', 'priority' => 'normal',
                    'icon' => $cfg['icon_warning'],
                    'title' => $cfg['title_warning'],
                    'message' => sprintf($cfg['msg_warning'], $expiring),
                    'action' => 'user_management.php'
                ];
            }
        }
    } catch (Exception $e) {
        // 乗務員台帳カラムが存在しない場合等はスキップ
    }
} catch (Exception $e) {
    error_log("ダッシュボード アラート生成エラー: " . $e->getMessage());
}

// 業務統計
$today_pre_duty_calls = safeDashboardCount($pdo, "SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND is_completed = TRUE", [$today]);
$today_departures = safeDashboardCount($pdo, "SELECT COUNT(*) FROM departure_records WHERE departure_date = ?", [$today]);
$today_arrivals = safeDashboardCount($pdo, "SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?", [$today]);

// 予約統計
$today_reservations = safeDashboardCount($pdo, "SELECT COUNT(*) FROM reservations WHERE reservation_date = ? AND status != 'cancelled'", [$today]);
$upcoming_reservations = safeDashboardCount($pdo, "SELECT COUNT(*) FROM reservations WHERE reservation_date > ? AND reservation_date <= DATE_ADD(?, INTERVAL 7 DAY) AND status != 'cancelled'", [$today, $today]);

// アラート優先度ソート
usort($alerts, function($a, $b) {
    $order = ['critical' => 0, 'high' => 1, 'normal' => 2];
    return ($order[$a['priority']] ?? 2) - ($order[$b['priority']] ?? 2);
});

// ページ生成（ダッシュボード専用：ヘッダー非表示モード）
$page_config = getPageConfiguration('dashboard');
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role_display,
    'dashboard',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    [
        'description' => $page_config['description'],
        'additional_css' => ['css/dashboard.css?v=' . filemtime('css/dashboard.css')],
        'breadcrumb' => [['text' => 'ダッシュボード', 'url' => 'dashboard.php']],
        'hide_headers' => true
    ]
);

echo $page_data['html_head'];
?>
<style>
    .ride-highlight {
        border: 3px solid #28a745 !important;
        background: rgba(40, 167, 69, 0.08) !important;
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.25);
    }
    .ride-highlight .workflow-icon {
        background: #28a745 !important;
        color: white !important;
        width: 60px !important;
        height: 60px !important;
        font-size: 1.8rem;
    }
    .ride-highlight strong {
        font-size: 1.05rem;
        color: #28a745;
    }
</style>
</head>
<body class="dashboard-page">

<!-- ダッシュボード専用ヘッダー -->
<div class="dashboard-mini-header">
    <div class="d-flex align-items-center">
        <i class="fas fa-taxi text-primary me-2"></i>
        <strong class="dashboard-title"><?= htmlspecialchars($system_name) ?></strong>
        <span class="text-muted ms-2 d-none d-md-inline dashboard-user-info"><?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_role_display) ?>)</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="master_menu.php" class="btn btn-sm btn-outline-primary d-none d-md-inline-block">
            <i class="fas fa-th-large me-1"></i>メニュー
        </a>
        <a href="logout.php" class="btn btn-sm btn-outline-danger">
            <i class="fas fa-sign-out-alt"></i>
            <span class="d-none d-sm-inline ms-1">ログアウト</span>
        </a>
    </div>
</div>

<!-- Layer 1: 売上情報ヘッダー（sticky） -->
<div class="revenue-header">
    <div class="container">
        <div class="text-center mb-3">
            <h5 class="mb-0">
                <i class="fas fa-calendar-alt me-2"></i><?= date('Y年n月j日') ?> (<?= ['日','月','火','水','木','金','土'][date('w')] ?>) <?= $current_time ?>
            </h5>
        </div>
        <div class="row text-center mb-2">
            <div class="col-4">
                <div class="revenue-item">
                    <div class="amount">¥<?= number_format($today_total_revenue) ?></div>
                    <div class="detail">今日 <?= $today_ride_records ?>回</div>
                    <div class="average">平均 ¥<?= number_format($today_avg_fare) ?>/回</div>
                </div>
            </div>
            <div class="col-4">
                <div class="revenue-item">
                    <div class="amount">¥<?= number_format($month_total_revenue) ?></div>
                    <div class="detail">今月 <?= $month_ride_records ?>回</div>
                    <div class="average">稼働 <?= $working_days ?>日</div>
                </div>
            </div>
            <div class="col-4">
                <div class="revenue-item">
                    <div class="amount">¥<?= number_format($month_avg_daily_revenue) ?></div>
                    <div class="detail">日平均</div>
                    <div class="average">実稼働ベース</div>
                </div>
            </div>
        </div>
        <?php if ($revenue_percentage != 0): ?>
        <div class="comparison text-center">
            <span class="badge <?= $revenue_trend === 'up' ? 'bg-success' : 'bg-danger' ?> px-3 py-2">
                <i class="fas fa-arrow-<?= $revenue_trend === 'up' ? 'up' : 'down' ?> me-1"></i>
                先月比 <?= $revenue_trend === 'up' ? '+' : '' ?><?= number_format($revenue_difference) ?>円
                (<?= $revenue_trend === 'up' ? '+' : '' ?><?= $revenue_percentage ?>%)
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="container" id="main-content" tabindex="-1">
    <!-- Layer 2: アラート表示 -->
    <?php if (!empty($alerts)): ?>
    <div class="alert-area">
        <h5><i class="fas fa-exclamation-triangle me-2 text-danger"></i>今すぐ対応が必要です</h5>
        <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= $alert['type'] ?> <?= $alert['priority'] === 'critical' ? 'pulse' : '' ?>">
            <div class="row align-items-center">
                <div class="col-auto">
                    <i class="<?= $alert['icon'] ?> alert-icon"></i>
                </div>
                <div class="col">
                    <strong><?= htmlspecialchars($alert['title']) ?></strong><br>
                    <?= htmlspecialchars($alert['message']) ?>
                </div>
                <div class="col-auto">
                    <a href="<?= htmlspecialchars($alert['action']) ?>" class="btn btn-sm btn-outline-primary"><?php
                        $action_labels = [
                            'pre_duty_call.php' => '乗務前点呼を実施',
                            'arrival.php' => '入庫処理へ',
                            'departure.php' => '出庫処理へ',
                            'daily_inspection.php' => '日常点検へ',
                            'user_management.php' => '乗務員管理へ',
                        ];
                        echo $action_labels[$alert['action']] ?? '対応する';
                    ?></a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Layer 3: 業務フロー（4グループ） -->
    <div class="business-flow">
        <!-- 開始業務 -->
        <div class="workflow-group start-group">
            <div class="workflow-header">
                <h6><i class="fas fa-play me-2"></i>開始業務</h6>
            </div>
            <a href="daily_inspection.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-tools"></i></div>
                <div>
                    <strong>日常点検</strong><br>
                    <small>17項目の車両点検</small>
                </div>
            </a>
            <a href="pre_duty_call.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-clipboard-check"></i></div>
                <div>
                    <strong>乗務前点呼</strong><br>
                    <small>16項目のドライバーチェック</small>
                </div>
            </a>
            <a href="departure.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-sign-out-alt"></i></div>
                <div>
                    <strong>出庫処理</strong><br>
                    <small>出庫時刻・天候・メーター記録</small>
                </div>
            </a>
        </div>

        <!-- 営業業務 -->
        <div class="workflow-group operation-group">
            <div class="workflow-header">
                <h6><i class="fas fa-users me-2"></i>営業業務</h6>
            </div>
            <a href="ride_records.php" class="workflow-item ride-highlight">
                <div class="workflow-icon"><i class="fas fa-users"></i></div>
                <div>
                    <strong>乗車記録</strong><br>
                    <small>復路作成機能付き乗車管理</small>
                </div>
            </a>
            <a href="calendar/index.php" class="workflow-item calendar-link">
                <div class="workflow-icon calendar-icon"><i class="fas fa-calendar-alt"></i></div>
                <div>
                    <strong>予約・スケジュール</strong><br>
                    <small>予約管理カレンダー
                        <?php if ($today_reservations > 0): ?><span class="badge bg-primary"><?= $today_reservations ?>件</span><?php endif; ?>
                        <?php if ($upcoming_reservations > 0): ?><span class="badge bg-secondary ms-1">7日間 <?= $upcoming_reservations ?>件</span><?php endif; ?>
                    </small>
                </div>
            </a>
        </div>

        <!-- 終了業務 -->
        <div class="workflow-group end-group">
            <div class="workflow-header">
                <h6><i class="fas fa-moon me-2"></i>終了業務</h6>
            </div>
            <a href="arrival.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-sign-in-alt"></i></div>
                <div>
                    <strong>入庫処理</strong><br>
                    <small>入庫時刻・走行距離・費用記録</small>
                </div>
            </a>
            <a href="post_duty_call.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-clipboard-check"></i></div>
                <div>
                    <strong>乗務後点呼</strong><br>
                    <small>12項目の業務終了チェック</small>
                </div>
            </a>
            <a href="cash_management.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-calculator"></i></div>
                <div>
                    <strong>売上金確認</strong><br>
                    <small>現金内訳・差額確認</small>
                </div>
            </a>
        </div>

        <!-- 定期業務 -->
        <div class="workflow-group periodic-group">
            <div class="workflow-header">
                <h6><i class="fas fa-calendar-alt me-2"></i>定期業務</h6>
            </div>
            <a href="periodic_inspection.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-wrench"></i></div>
                <div>
                    <strong>定期点検</strong><br>
                    <small>3ヶ月毎の法定車両点検</small>
                </div>
            </a>
            <a href="annual_report.php" class="workflow-item">
                <div class="workflow-icon"><i class="fas fa-file-alt"></i></div>
                <div>
                    <strong>陸運局報告</strong><br>
                    <small>年1回の法定報告書提出</small>
                </div>
            </a>
        </div>
    </div>

    <!-- 管理者専用機能 -->
    <?php if ($is_admin): ?>
    <div class="admin-section">
        <h6><i class="fas fa-crown me-2"></i>管理者専用機能</h6>
        <div class="row">
            <div class="col-md-4 mb-2">
                <a href="user_management.php" class="btn btn-outline-primary btn-sm w-100">
                    <i class="fas fa-users-cog me-1"></i>ユーザー管理
                </a>
            </div>
            <div class="col-md-4 mb-2">
                <a href="vehicle_management.php" class="btn btn-outline-primary btn-sm w-100">
                    <i class="fas fa-car me-1"></i>車両管理
                </a>
            </div>
            <div class="col-md-4 mb-2">
                <a href="master_menu.php" class="btn btn-outline-primary btn-sm w-100">
                    <i class="fas fa-cogs me-1"></i>システム設定
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 業務統計 -->
    <div class="row mt-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title mb-3"><i class="fas fa-chart-bar me-2"></i>今日の業務統計</h6>
                    <div class="row text-center">
                        <div class="col-3">
                            <div class="h4 text-primary mb-0"><?= $today_departures ?></div>
                            <small class="text-muted">出庫</small>
                        </div>
                        <div class="col-3">
                            <div class="h4 text-primary mb-0"><?= $today_ride_records ?></div>
                            <small class="text-muted">乗車</small>
                        </div>
                        <div class="col-3">
                            <?php $not_arrived = $today_departures - $today_arrivals; ?>
                            <div class="h4 text-<?= $not_arrived > 0 ? 'danger' : 'success' ?> mb-0"><?= $not_arrived ?></div>
                            <small class="text-muted">未入庫</small>
                        </div>
                        <div class="col-3">
                            <div class="h4 text-info mb-0"><?= $today_passengers ?></div>
                            <small class="text-muted">乗客数</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
class DashboardManager {
    constructor() {
        this.setupRealtimeUpdates();
        this.setupNotifications();
        if ('ontouchstart' in window) document.body.classList.add('touch-device');
    }

    setupRealtimeUpdates() {
        setInterval(() => this.updateAlerts(), 300000);
    }

    setupNotifications() {
        <?php if (!empty($alerts) && in_array('critical', array_column($alerts, 'priority'))): ?>
        if (Notification.permission === "granted") {
            new Notification("重要な業務漏れがあります", {
                body: "<?= isset($alerts[0]) ? htmlspecialchars($alerts[0]['message']) : '' ?>",
                icon: "/favicon.ico"
            });
        } else if (Notification.permission !== "denied") {
            Notification.requestPermission();
        }
        <?php endif; ?>
    }

    async updateAlerts() {
        try {
            const response = await fetch('api/dashboard_alerts.php');
            if (response.ok) {
                const data = await response.json();
                // アラート表示更新
            }
        } catch (e) { /* 通信エラー時は無視 */ }
    }
}

// PWA初期化
if (window.matchMedia('(display-mode: standalone)').matches) {
    document.body.classList.add('pwa-mode');
}
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => new DashboardManager());
</script>

<?php echo $page_data['html_footer']; ?>
