<?php
session_start();

// セッション確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続（プロジェクトナレッジ準拠）
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4',
        'twinklemark_taxi',
        'Smiley2525',
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        )
    );
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT name, permission_level FROM users WHERE id = ? AND is_active = TRUE");
$stmt->execute(array($_SESSION['user_id']));
$user = $stmt->fetch();

if (!$user) {
    header('Location: index.php?error=user_not_found');
    exit;
}

$user_name = $user['name'];
$user_permission = $user['permission_level'];

// 今日の日付
$today = date('Y-m-d');
$current_month = date('Y-m');
$last_month = date('Y-m', strtotime('-1 month'));

// 売上統計取得関数
function getRevenueStats($pdo, $date_condition, $params = array()) {
    $sql = "SELECT 
                COUNT(*) as trip_count,
                COALESCE(SUM(total_fare), 0) as total_revenue,
                COALESCE(SUM(cash_amount), 0) as cash_amount,
                COALESCE(SUM(card_amount), 0) as card_amount
            FROM ride_records 
            WHERE " . $date_condition;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

// 統計データ取得
$today_stats = getRevenueStats($pdo, "ride_date = ?", array($today));
$current_month_stats = getRevenueStats($pdo, "DATE_FORMAT(ride_date, '%Y-%m') = ?", array($current_month));
$last_month_stats = getRevenueStats($pdo, "DATE_FORMAT(ride_date, '%Y-%m') = ?", array($last_month));

// 先月比較計算
$revenue_diff = $current_month_stats['total_revenue'] - $last_month_stats['total_revenue'];
if ($last_month_stats['total_revenue'] > 0) {
    $revenue_diff_rate = ($revenue_diff / $last_month_stats['total_revenue'] * 100);
} else {
    $revenue_diff_rate = 0;
}

// 営業日数計算
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT ride_date) as working_days FROM ride_records WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?");
$stmt->execute(array($current_month));
$working_days_result = $stmt->fetch();
$working_days = $working_days_result['working_days'];
if ($working_days == 0) {
    $working_days = 1;
}
$avg_daily_revenue = $current_month_stats['total_revenue'] / $working_days;

// 業務状況統計
$business_stats = array();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
$stmt->execute(array($today));
$business_stats['departures'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
$stmt->execute(array($today));
$business_stats['arrivals'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ?");
$stmt->execute(array($today));
$business_stats['pre_calls'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ?");
$stmt->execute(array($today));
$business_stats['post_calls'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date = ?");
$stmt->execute(array($today));
$business_stats['inspections'] = $stmt->fetchColumn();

// アラート検出
$alerts = array();
$current_hour = intval(date('H'));

if ($current_hour >= 8) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date = ?");
    $stmt->execute(array($today));
    if ($stmt->fetchColumn() == 0) {
        $alerts[] = array(
            'level' => 'high',
            'title' => '日常点検未実施',
            'message' => '本日の日常点検が完了していません',
            'action' => 'daily_inspection.php'
        );
    }
}

if ($current_hour >= 8) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ?");
    $stmt->execute(array($today));
    if ($stmt->fetchColumn() == 0) {
        $alerts[] = array(
            'level' => 'high',
            'title' => '乗務前点呼未実施',
            'message' => '本日の乗務前点呼が完了していません',
            'action' => 'pre_duty_call.php'
        );
    }
}

if ($current_hour >= 9) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute(array($today));
    if ($stmt->fetchColumn() == 0) {
        $alerts[] = array(
            'level' => 'medium',
            'title' => '出庫処理未実施',
            'message' => '本日の出庫処理が完了していません',
            'action' => 'departure.php'
        );
    }
}

if ($current_hour >= 17) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute(array($today));
    if ($stmt->fetchColumn() == 0) {
        $alerts[] = array(
            'level' => 'medium',
            'title' => '入庫処理未完了',
            'message' => '本日の入庫処理が完了していません',
            'action' => 'arrival.php'
        );
    }
}
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
    
    <style>
    .header-container {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px 0;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .system-title {
        font-size: 24px;
        font-weight: bold;
        margin: 0;
    }
    
    .user-info {
        font-size: 14px;
    }
    
    .logout-link {
        color: white;
        text-decoration: none;
        margin-left: 15px;
    }
    
    .logout-link:hover {
        color: #f8f9fa;
        text-decoration: underline;
    }
    
    .revenue-card {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border-radius: 12px;
    }
    
    .stats-card {
        border-left: 4px solid #007bff;
        border-radius: 8px;
        transition: transform 0.2s ease;
    }
    
    .stats-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .alert-high {
        background-color: #dc3545;
        color: white;
        border: none;
        border-radius: 8px;
    }
    
    .alert-medium {
        background-color: #ffc107;
        color: #212529;
        border: none;
        border-radius: 8px;
    }
    
    .workflow-card {
        border: 2px solid #e9ecef;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        background-color: #f8f9fa;
    }
    
    .workflow-btn-main {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        border: none;
        color: white;
        padding: 15px 30px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: bold;
        width: 100%;
        margin-bottom: 15px;
        cursor: pointer;
        transition: transform 0.2s ease;
    }
    
    .workflow-btn-main:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,123,255,0.3);
    }
    
    .step-btn {
        display: inline-block;
        background-color: #ffffff;
        border: 1px solid #dee2e6;
        color: #495057;
        padding: 8px 15px;
        margin: 5px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    
    .step-btn:hover {
        background-color: #e9ecef;
        color: #495057;
        text-decoration: none;
        transform: translateY(-1px);
    }
    
    .menu-card {
        border-radius: 12px;
        border: none;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    
    .menu-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        text-decoration: none;
    }
    
    .menu-card .card-body {
        text-align: center;
        padding: 20px;
    }
    
    .menu-card i {
        font-size: 2rem;
        margin-bottom: 10px;
    }
    
    .menu-card h6 {
        font-weight: bold;
        margin-bottom: 5px;
        color: #333;
    }
    
    .menu-card small {
        color: #666;
    }
    </style>
</head>
<body>
    <!-- システムヘッダー -->
    <div class="header-container">
        <div class="system-header">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="system-title">
                            <i class="fas fa-taxi"></i> 福祉輸送管理システム
                        </h1>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="user-info">
                            <i class="fas fa-user-circle"></i>
                            <span><?php echo htmlspecialchars($user_name); ?></span>
                            <span class="text-muted">(<?php echo htmlspecialchars($user_permission); ?>)</span>
                            <a href="logout.php" class="logout-link">
                                <i class="fas fa-sign-out-alt"></i>ログアウト
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- アラートエリア -->
        <?php if (count($alerts) > 0) { ?>
        <div class="alert-area mb-4">
            <?php foreach ($alerts as $alert) { ?>
            <div class="alert alert-<?php echo $alert['level']; ?> d-flex align-items-center">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <div>
                    <strong><?php echo htmlspecialchars($alert['title']); ?></strong><br>
                    <small><?php echo htmlspecialchars($alert['message']); ?></small>
                    <?php if (isset($alert['action'])) { ?>
                    <a href="<?php echo htmlspecialchars($alert['action']); ?>" class="btn btn-sm btn-light ms-3">対応する</a>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
        </div>
        <?php } ?>

        <!-- 売上カード -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card revenue-card">
                    <div class="card-body text-center">
                        <div class="row">
                            <div class="col-md-4">
                                <h5><i class="fas fa-yen-sign"></i> 今日の売上</h5>
                                <h2>¥<?php echo number_format($today_stats['total_revenue']); ?></h2>
                                <small><?php echo $today_stats['trip_count']; ?>回の乗車</small>
                                <?php if ($today_stats['trip_count'] > 0) { ?>
                                <br><small>平均 ¥<?php echo number_format($today_stats['total_revenue'] / $today_stats['trip_count']); ?>/回</small>
                                <?php } ?>
                            </div>
                            <div class="col-md-4">
                                <h5><i class="fas fa-calendar-alt"></i> 今月の売上</h5>
                                <h2>¥<?php echo number_format($current_month_stats['total_revenue']); ?></h2>
                                <small><?php echo $current_month_stats['trip_count']; ?>回 / <?php echo $working_days; ?>営業日</small>
                                <br><small>日平均 ¥<?php echo number_format($avg_daily_revenue); ?></small>
                            </div>
                            <div class="col-md-4">
                                <h5><i class="fas fa-chart-line"></i> 先月比較</h5>
                                <?php $diff_class = $revenue_diff >= 0 ? 'text-success' : 'text-danger'; ?>
                                <h2 class="<?php echo $diff_class; ?>">
                                    <?php echo $revenue_diff >= 0 ? '+' : ''; ?>¥<?php echo number_format($revenue_diff); ?>
                                </h2>
                                <small>
                                    <?php echo $revenue_diff >= 0 ? '↗' : '↘'; ?> 
                                    <?php echo number_format(abs($revenue_diff_rate), 1); ?>%
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 業務状況カード -->
        <div class="row mb-4">
            <div class="col-6 col-md-2">
                <div class="card stats-card text-center">
                    <div class="card-body">
                        <i class="fas fa-truck-pickup fa-2x text-primary mb-2"></i>
                        <h3 class="text-primary"><?php echo $business_stats['departures']; ?></h3>
                        <small>今日の出庫</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stats-card text-center">
                    <div class="card-body">
                        <i class="fas fa-home fa-2x text-success mb-2"></i>
                        <h3 class="text-success"><?php echo $business_stats['arrivals']; ?></h3>
                        <small>今日の入庫</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stats-card text-center">
                    <div class="card-body">
                        <i class="fas fa-clipboard-check fa-2x text-info mb-2"></i>
                        <h3 class="text-info"><?php echo $business_stats['pre_calls']; ?></h3>
                        <small>乗務前点呼</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stats-card text-center">
                    <div class="card-body">
                        <i class="fas fa-clipboard-list fa-2x text-warning mb-2"></i>
                        <h3 class="text-warning"><?php echo $business_stats['post_calls']; ?></h3>
                        <small>乗務後点呼</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stats-card text-center">
                    <div class="card-body">
                        <i class="fas fa-tools fa-2x text-secondary mb-2"></i>
                        <h3 class="text-secondary"><?php echo $business_stats['inspections']; ?></h3>
                        <small>日常点検</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stats-card text-center">
                    <div class="card-body">
                        <i class="fas fa-car fa-2x text-dark mb-2"></i>
                        <h3 class="text-dark"><?php echo $today_stats['trip_count']; ?></h3>
                        <small>今日の乗車</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 業務フローカード -->
        <div class="row">
            <div class="col-md-6">
                <div class="workflow-card">
                    <button class="workflow-btn-main" onclick="startMorningFlow()">
                        <i class="fas fa-sun"></i> 始業フローを開始
                    </button>
                    <div class="workflow-steps">
                        <a href="daily_inspection.php" class="step-btn">1.日常点検</a>
                        <a href="pre_duty_call.php" class="step-btn">2.乗務前点呼</a>
                        <a href="departure.php" class="step-btn">3.出庫処理</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="workflow-card">
                    <button class="workflow-btn-main" onclick="startEveningFlow()">
                        <i class="fas fa-moon"></i> 終業フローを開始
                    </button>
                    <div class="workflow-steps">
                        <a href="arrival.php" class="step-btn">1.入庫処理</a>
                        <a href="post_duty_call.php" class="step-btn">2.乗務後点呼</a>
                        <a href="cash_management.php" class="step-btn">3.集金管理</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- メインメニューグリッド -->
        <div class="row mt-4">
            <div class="col-6 col-md-3 mb-3">
                <a href="ride_records.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-route text-primary"></i>
                        <h6>乗車記録</h6>
                        <small class="text-muted">乗降記録・復路作成</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <a href="cash_management.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-calculator text-success"></i>
                        <h6>集金管理</h6>
                        <small class="text-muted">現金・カード集計</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <a href="periodic_inspection.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-calendar-check text-info"></i>
                        <h6>定期点検</h6>
                        <small class="text-muted">3ヶ月点検</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <a href="annual_report.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-file-alt text-warning"></i>
                        <h6>陸運局提出</h6>
                        <small class="text-muted">年次報告</small>
                    </div>
                </a>
            </div>

            <!-- 管理者限定メニュー -->
            <?php if ($user_permission === 'Admin') { ?>
            <div class="col-6 col-md-3 mb-3">
                <a href="user_management.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-users text-danger"></i>
                        <h6>ユーザー管理</h6>
                        <small class="text-muted">権限・職務管理</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <a href="vehicle_management.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-car-side text-secondary"></i>
                        <h6>車両管理</h6>
                        <small class="text-muted">車両情報管理</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <a href="accident_management.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-exclamation-triangle text-danger"></i>
                        <h6>事故管理</h6>
                        <small class="text-muted">事故記録・報告</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <a href="data_management.php" class="card menu-card text-decoration-none h-100">
                    <div class="card-body">
                        <i class="fas fa-database text-info"></i>
                        <h6>データ管理</h6>
                        <small class="text-muted">データ修正・分析</small>
                    </div>
                </a>
            </div>
            <?php } ?>
        </div>

        <!-- フッター -->
        <div class="text-center text-muted mt-4 mb-2">
            <small>最終更新: <?php echo date('H:i:s'); ?></small>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- JavaScript -->
    <script>
    function startMorningFlow() {
        if (confirm('始業フローを開始しますか？\n1.日常点検 → 2.乗務前点呼 → 3.出庫処理')) {
            sessionStorage.setItem('workflow_mode', 'morning');
            sessionStorage.setItem('workflow_step', '1');
            location.href = 'daily_inspection.php?workflow=morning';
        }
    }

    function startEveningFlow() {
        if (confirm('終業フローを開始しますか？\n1.入庫処理 → 2.乗務後点呼 → 3.集金管理')) {
            sessionStorage.setItem('workflow_mode', 'evening');
            sessionStorage.setItem('workflow_step', '1');
            location.href = 'arrival.php?workflow=evening';
        }
    }

    // リアルタイム更新機能
    function updateDashboard() {
        fetch('api/dashboard_stats.php')
            .then(function(response) { 
                return response.json(); 
            })
            .then(function(data) {
                if (data.today_revenue !== undefined) {
                    var revenueElement = document.querySelector('.revenue-card h2');
                    if (revenueElement) {
                        revenueElement.textContent = '¥' + data.today_revenue.toLocaleString();
                    }
                }
            })
            .catch(function(error) { 
                console.error('ダッシュボード更新エラー:', error); 
            });
    }

    // 30秒ごとに自動更新
    setInterval(updateDashboard, 30000);

    // ワークフロー継続チェック
    document.addEventListener('DOMContentLoaded', function() {
        var workflowMode = sessionStorage.getItem('workflow_mode');
        var workflowStep = sessionStorage.getItem('workflow_step');
        
        if (workflowMode && workflowStep) {
            showWorkflowProgress(workflowMode, workflowStep);
        }
    });

    function showWorkflowProgress(mode, step) {
        var workflows = {
            morning: ['日常点検', '乗務前点呼', '出庫処理'],
            evening: ['入庫処理', '乗務後点呼', '集金管理']
        };
        
        var steps = workflows[mode];
        var currentStep = parseInt(step);
        
        if (currentStep <= steps.length) {
            var banner = document.createElement('div');
            banner.className = 'alert alert-info alert-dismissible fade show';
            banner.innerHTML = 
                '<i class="fas fa-info-circle me-2"></i>' +
                '<strong>ワークフロー継続中</strong><br>' +
                '次のステップ: ' + currentStep + '. ' + steps[currentStep - 1] +
                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            
            var container = document.querySelector('.container-fluid');
            if (container) {
                container.insertBefore(banner, container.firstChild);
            }
        }
    }

    // エラーハンドリング
    window.addEventListener('error', function(e) {
        console.error('Dashboard Error:', e);
    });
    </script>
</body>
</html>
