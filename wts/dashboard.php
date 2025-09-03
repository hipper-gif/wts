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
    
    // 今日の乗車記録数と売上（修正版）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_fare), 0) as revenue FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    $today_ride_records = $result ? $result['count'] : 0;
    $today_total_revenue = $result ? $result['revenue'] : 0;

    // 当月の乗車記録数と売上（修正版）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_fare), 0) as revenue FROM ride_records WHERE ride_date >= ?");
    $stmt->execute([$current_month_start]);
    $result = $stmt->fetch();
    $month_ride_records = $result ? $result['count'] : 0;
    $month_total_revenue = $result ? $result['revenue'] : 0;
    
    // 平均売上計算
    $days_in_month = date('j'); // 今月の経過日数
    $month_avg_revenue = $days_in_month > 0 ? round($month_total_revenue / $days_in_month) : 0;
    
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
    
    <!-- 統一UI CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/ui-unified-v3.css">
</head>
<body>
    <!-- 統一システムヘッダー -->
    <?= renderSystemHeader($user_name, $user_permission_level) ?>
    
    <!-- 統一ページヘッダー -->
    <?= renderPageHeader('tachometer-alt', 'ダッシュボード', '業務状況・売上管理') ?>
    
    <!-- メインコンテンツ -->
    <div class="main-content">
        <div class="content-container">
            
            <!-- 業務漏れアラート（最優先表示） -->
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
                <a href="<?= $alert['action'] ?>" class="btn btn-<?= $alert['type'] === 'danger' ? 'danger' : 'warning' ?>">
                    <i class="fas fa-arrow-right"></i> <?= htmlspecialchars($alert['action_text']) ?>
                </a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- 今日の業務状況 -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-chart-line icon-primary"></i>
                    今日の業務状況
                </h3>
                <p class="section-description"><?= date('Y年n月j日 (D)', strtotime($today)) ?> <?= $current_time ?> 現在</p>
            </div>
            
            <div class="dashboard-grid">
                <?= renderStatCard('今日の出庫', $today_departures . '台', 'sign-out-alt', '', 'neutral') ?>
                <?= renderStatCard('今日の乗車', $today_ride_records . '回', 'users', '', 'positive') ?>
                <?= renderStatCard('未入庫', ($today_departures - $today_arrivals) . '台', 'exclamation-triangle', '', ($today_departures - $today_arrivals > 0) ? 'negative' : 'positive') ?>
                <?= renderStatCard('点呼実施', $today_pre_duty_calls . '/' . $today_post_duty_calls, 'clipboard-check', '前/後', 'neutral') ?>
            </div>
            
            <!-- 売上情報 -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-yen-sign icon-success"></i>
                    売上情報
                </h3>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <div class="stat-icon">
                                <i class="fas fa-yen-sign icon-success icon-xl"></i>
                            </div>
                            <div class="stat-value text-success">¥<?= number_format($today_total_revenue) ?></div>
                            <div class="stat-label">今日の売上</div>
                            <div class="stat-change neutral"><?= $today_ride_records ?>回の乗車</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-alt icon-info icon-xl"></i>
                            </div>
                            <div class="stat-value text-info">¥<?= number_format($month_total_revenue) ?></div>
                            <div class="stat-label">今月の売上</div>
                            <div class="stat-change neutral"><?= $month_ride_records ?>回の乗車</div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <div class="stat-icon">
                                <i class="fas fa-chart-bar icon-secondary icon-xl"></i>
                            </div>
                            <div class="stat-value text-secondary">¥<?= number_format($month_avg_revenue) ?></div>
                            <div class="stat-label">月平均</div>
                            <div class="stat-change neutral">1日あたり平均売上</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- クイックアクション -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-bolt icon-primary"></i>
                    クイックアクション
                </h3>
                <p class="section-description">業務の流れに沿って操作してください</p>
            </div>
            
            <div class="row">
                <!-- 運転者向け：1日の流れに沿った業務 -->
                <div class="col-lg-6">
                    <div class="card">
                        <?= renderCardHeader('route', '運転業務（1日の流れ）', '必須業務') ?>
                        <div class="card-body">
                            
                            <a href="daily_inspection.php" class="btn btn-outline w-100 mb-3 text-start">
                                <i class="fas fa-tools me-3 text-secondary"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">1. 日常点検</div>
                                    <small class="text-muted">最初に実施（法定義務）</small>
                                </div>
                            </a>
                            
                            <a href="pre_duty_call.php" class="btn btn-outline w-100 mb-3 text-start">
                                <i class="fas fa-clipboard-check me-3 text-warning"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">2. 乗務前点呼</div>
                                    <small class="text-muted">日常点検後に実施</small>
                                </div>
                            </a>
                            
                            <a href="departure.php" class="btn btn-outline w-100 mb-3 text-start">
                                <i class="fas fa-sign-out-alt me-3 text-primary"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">3. 出庫処理</div>
                                    <small class="text-muted">点呼・点検完了後</small>
                                </div>
                            </a>
                            
                            <a href="ride_records.php" class="btn btn-outline w-100 text-start">
                                <i class="fas fa-users me-3 text-success"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">4. 乗車記録</div>
                                    <small class="text-muted">営業中随時入力</small>
                                </div>
                            </a>
                            
                        </div>
                    </div>
                </div>

                <!-- 1日の終了業務と管理業務 -->
                <div class="col-lg-6">
                    <div class="card">
                        <?= renderCardHeader('moon', '終業・管理業務', $is_admin ? '管理者' : '終業処理') ?>
                        <div class="card-body">
                            
                            <a href="arrival.php" class="btn btn-outline w-100 mb-3 text-start">
                                <i class="fas fa-sign-in-alt me-3 text-info"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">入庫処理</div>
                                    <small class="text-muted">営業終了時に実施</small>
                                </div>
                            </a>
                            
                            <a href="post_duty_call.php" class="btn btn-outline w-100 mb-3 text-start">
                                <i class="fas fa-clipboard-check me-3 text-danger"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">乗務後点呼</div>
                                    <small class="text-muted">入庫後に実施</small>
                                </div>
                            </a>
                            
                            <a href="periodic_inspection.php" class="btn btn-outline w-100 mb-3 text-start">
                                <i class="fas fa-wrench me-3 text-warning"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">定期点検</div>
                                    <small class="text-muted">3ヶ月ごと</small>
                                </div>
                            </a>

                            <?php if ($is_admin): ?>
                            <a href="cash_management.php" class="btn btn-outline w-100 mb-3 text-start">
                                <i class="fas fa-calculator me-3 text-success"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">集金管理</div>
                                    <small class="text-muted">売上・現金管理</small>
                                </div>
                            </a>
                            
                            <a href="master_menu.php" class="btn btn-outline w-100 text-start">
                                <i class="fas fa-cogs me-3 text-secondary"></i>
                                <div class="d-inline-block">
                                    <div class="fw-bold">マスタ管理</div>
                                    <small class="text-muted">システム設定</small>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 今日の業務進捗ガイド -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-tasks icon-primary"></i>
                    今日の業務進捗ガイド
                </h3>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <?php
                            $progress_steps = [
                                ['icon' => 'tools', 'label' => '点検・点呼', 'completed' => $today_departures > 0],
                                ['icon' => 'sign-out-alt', 'label' => '出庫', 'completed' => $today_departures > 0],
                                ['icon' => 'users', 'label' => '営業', 'completed' => $today_ride_records > 0],
                                ['icon' => 'sign-in-alt', 'label' => '終業', 'completed' => $today_arrivals > 0]
                            ];
                            $completed_count = count(array_filter($progress_steps, function($step) { return $step['completed']; }));
                            $total_count = count($progress_steps);
                            ?>
                            
                            <div class="text-center mb-3">
                                <?= renderProgressBar($completed_count, $total_count, 'primary', true) ?>
                            </div>
                            
                            <div class="row text-center">
                                <?php foreach ($progress_steps as $step): ?>
                                <div class="col-3">
                                    <div class="p-3 rounded <?= $step['completed'] ? 'bg-success text-white' : 'bg-light text-muted' ?>">
                                        <i class="fas fa-<?= $step['icon'] ?> fa-2x mb-2"></i>
                                        <div><small><?= $step['label'] ?></small></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border-start border-primary ps-3">
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

    <!-- 統一JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/ui-interactions.js"></script>
    
    <script>
        // 5分ごとにページを自動更新してアラートを更新
        setInterval(function() {
            window.location.reload();
        }, 300000); // 5分 = 300000ms

        // 重要なアラートがある場合のブラウザ通知
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

        // ダッシュボード初期化
        document.addEventListener('DOMContentLoaded', function() {
            // 統計カードのアニメーション
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.transform = 'translateY(10px)';
                    card.style.opacity = '0';
                    card.style.transition = 'all 0.5s ease';
                    
                    setTimeout(() => {
                        card.style.transform = 'translateY(0)';
                        card.style.opacity = '1';
                    }, 50);
                }, index * 100);
            });
            
            // クリック時のフィードバック
            const actionButtons = document.querySelectorAll('.btn-outline');
            actionButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    // ハプティックフィードバック（対応ブラウザのみ）
                    if (navigator.vibrate) {
                        navigator.vibrate(50);
                    }
                    
                    // ボタンアニメーション
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 150);
                });
            });
        });
    </script>
</body>
</html>
