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

// 統一ヘッダー関数を読み込み
require_once 'includes/unified-header.php';

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

</body>
</html>
        
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - <?= htmlspecialchars($system_name) ?></title>
    
    <!-- PWA設定 -->
    <link rel="manifest" href="/Smiley/taxi/wts/manifest.json">
    <meta name="theme-color" content="#2196F3">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="WTS">
    <link rel="apple-touch-icon" href="/Smiley/taxi/wts/icons/icon-192x192.png">
    
    <!-- 統一UI CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/ui-unified-v3.css">
    <link rel="stylesheet" href="css/pwa-styles.css">
    
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
        
        .flow-connector {
            height: 2px;
            background: linear-gradient(90deg, #28a745 0%, #dee2e6 100%);
            margin: 10px 0;
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
    <!-- PWA インストールボタン（非表示、必要時に表示） -->
    <button id="pwa-install-btn" class="btn btn-primary position-fixed" style="top: 80px; right: 20px; z-index: 1050; display: none;">
        <i class="fas fa-download"></i> アプリをインストール
    </button>
    
    <!-- 統一システムヘッダー -->
    <?= renderSystemHeader($user_name, $user_permission_level) ?>
    
    <!-- 統一ページヘッダー -->
    <?= renderPageHeader('tachometer-alt', 'ダッシュボード', '日次運行フロー・業務状況管理') ?>
    
    <!-- メインコンテンツ -->
    <div class="main-content">
        <div class="content-container">
            
            <!-- 業務漏れアラート -->
            <?php if (!empty($alerts)): ?>
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-exclamation-triangle icon-danger"></i>
                    重要なお知らせ・業務漏れ確認
                </h3>
            </div>
            
            <?php foreach ($alerts as $alert): ?>
            <?= renderAlert($alert['message'], $alert['type'], $alert['icon'], false) ?>
            <div class="d-flex justify-content-end mb-3">
                <a href="<?= $alert['action'] ?>" class="btn btn-<?= $alert['type'] === 'danger' ? 'danger' : ($alert['type'] === 'warning' ? 'warning' : 'info') ?>">
                    <i class="fas fa-arrow-right"></i> <?= htmlspecialchars($alert['action_text']) ?>
                </a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- 日次運行フロー -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-route icon-primary"></i>
                    日次運行フロー（7段階）
                </h3>
                <p class="section-description"><?= htmlspecialchars($user_name) ?> さんの本日の進捗状況</p>
            </div>
            
            <?php
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
            ?>
            
            <div class="row g-2">
                <?php foreach ($flow_steps as $index => $step): ?>
                    <?php
                    $is_completed = $flow_status[$step['key']];
                    $is_current = ($index === $current_step);
                    $is_pending = ($index > $current_step);
                    $status_class = $is_completed ? 'completed' : ($is_current ? 'current' : 'pending');
                    ?>
                    
                    <div class="col-lg-4 col-md-6 mb-3">
                        <a href="<?= $step['url'] ?>" class="text-decoration-none">
                            <div class="card flow-step <?= $status_class ?> h-100">
                                <div class="card-body text-center p-3">
                                    <div class="step-number mx-auto">
                                        <?= $is_completed ? '<i class="fas fa-check"></i>' : $step['number'] ?>
                                    </div>
                                    <div class="mb-2">
                                        <i class="fas fa-<?= $step['icon'] ?> fa-2x mb-2"></i>
                                    </div>
                                    <h6 class="card-title mb-2"><?= $step['title'] ?></h6>
                                    <p class="card-text small mb-0"><?= $step['desc'] ?></p>
                                    
                                    <?php if ($is_completed): ?>
                                        <div class="mt-2">
                                            <small class="badge bg-light text-dark">
                                                <i class="fas fa-check-circle text-success"></i> 完了
                                            </small>
                                            <a href="<?= $step['url'] ?>?action=edit" class="badge bg-secondary ms-1" onclick="event.stopPropagation();">
                                                <i class="fas fa-edit"></i> 修正
                                            </a>
                                        </div>
                                    <?php elseif ($is_current): ?>
                                        <div class="mt-2">
                                            <small class="badge bg-light text-dark">
                                                <i class="fas fa-arrow-right text-primary"></i> 次の作業
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                    
                    <?php if ($index < count($flow_steps) - 1): ?>
                        <div class="w-100 d-lg-none"></div>
                        <?php if (($index + 1) % 3 === 0): ?>
                            <div class="w-100 d-none d-lg-block"></div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <!-- 進捗サマリー -->
            <div class="card mt-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <?php
                            $completed_count = count(array_filter($flow_status));
                            $total_count = count($flow_status);
                            $progress_percent = round(($completed_count / $total_count) * 100);
                            ?>
                            
                            <h6 class="mb-3">本日の進捗: <?= $completed_count ?>/<?= $total_count ?> 完了 (<?= $progress_percent ?>%)</h6>
                            <div class="progress mb-3" style="height: 10px;">
                                <div class="progress-bar bg-success" style="width: <?= $progress_percent ?>%"></div>
                            </div>
                            
                            <?php if ($current_step < count($flow_steps)): ?>
                                <p class="mb-0 text-primary">
                                    <i class="fas fa-info-circle"></i> 
                                    次の作業: <strong><?= $flow_steps[$current_step]['title'] ?></strong>
                                </p>
                            <?php else: ?>
                                <p class="mb-0 text-success">
                                    <i class="fas fa-check-circle"></i> 
                                    本日の業務フローが全て完了しています！お疲れ様でした。
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-center">
                            <?php if ($progress_percent === 100): ?>
                                <div class="text-success">
                                    <i class="fas fa-trophy fa-3x mb-2"></i>
                                    <div><strong>業務完了</strong></div>
                                </div>
                            <?php else: ?>
                                <div class="text-primary">
                                    <i class="fas fa-tasks fa-3x mb-2"></i>
                                    <div><strong><?= 7 - $completed_count ?> 件残り</strong></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 今日の全体状況 -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-chart-line icon-primary"></i>
                    今日の全体状況
                </h3>
                <p class="section-description"><?= date('Y年n月j日 (D)', strtotime($today)) ?> <?= $current_time ?> 現在</p>
            </div>
            
            <div class="dashboard-grid">
                <div class="col-md-3 mb-3">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-user-tie fa-2x text-secondary mb-3"></i>
                            <h5><?= $today_active_drivers ?></h5>
                            <p class="card-text">稼働運転者</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-users fa-2x text-success mb-3"></i>
                            <h5><?= $today_total_rides ?></h5>
                            <p class="card-text">総乗車回数</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-exclamation-triangle fa-2x <?= ($today_departures - $today_arrivals > 0) ? 'text-danger' : 'text-success' ?> mb-3"></i>
                            <h5><?= ($today_departures - $today_arrivals) ?></h5>
                            <p class="card-text">未入庫車両</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-yen-sign fa-2x text-success mb-3"></i>
                            <h5>¥<?= number_format($today_total_revenue) ?></h5>
                            <p class="card-text">本日売上</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 管理機能（権限別表示） -->
            <?php if ($is_admin): ?>
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-cogs icon-secondary"></i>
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
            <?php endif; ?>
            
        </div>
    </div>

    <!-- 統一JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/ui-interactions.js"></script>
    <script src="js/pwa-install.js"></script>
    
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
            
            //
