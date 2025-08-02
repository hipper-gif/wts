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

$user_name = $_SESSION['user_name'];

// ✅ permission_levelのみ使用（roleは完全排除）
$user_permission_level = $_SESSION['user_permission_level'] ?? 'User';

// ✅ 職務フラグに基づく表示名決定（roleは使わない）
$user_display_role = 'ユーザー'; // デフォルト

// データベースから職務フラグを取得
try {
    $stmt = $pdo->prepare("SELECT permission_level, is_driver, is_caller, is_manager FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
    
    if ($user_info) {
        if ($user_info['permission_level'] === 'Admin') {
            $user_display_role = 'システム管理者';
        } elseif ($user_info['is_manager']) {
            $user_display_role = '管理者';
        } elseif ($user_info['is_caller']) {
            $user_display_role = '点呼者';
        } elseif ($user_info['is_driver']) {
            $user_display_role = '運転者';
        }
    }
} catch (Exception $e) {
    error_log("User info fetch error: " . $e->getMessage());
}

$today = date('Y-m-d');
$current_time = date('H:i');
$current_hour = date('H');

// 当月の開始日
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

// 今日の統計データ取得
try {
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
    
    // 今日の乗車記録数と売上
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    $today_ride_records = $result ? $result['count'] : 0;
    $today_total_revenue = $result ? $result['revenue'] : 0;

    // 当月の乗車記録数と売上
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date >= ?");
    $stmt->execute([$current_month_start]);
    $result = $stmt->fetch();
    $month_ride_records = $result ? $result['count'] : 0;
    $month_total_revenue = $result ? $result['revenue'] : 0;
    
    // 平均売上計算
    $days_in_month = date('j');
    $month_avg_revenue = $days_in_month > 0 ? round($month_total_revenue / $days_in_month) : 0;
    
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    // デフォルト値設定
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
        
        .revenue-card {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
            color: white;
        }
        
        .revenue-month-card {
            background: linear-gradient(135deg, var(--info-color) 0%, #138496 100%);
            color: white;
        }
        
        @media (max-width: 768px) {
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
                        (<?= htmlspecialchars($user_display_role) ?>)
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

        <!-- 売上情報 -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card revenue-card">
                    <h6 class="mb-2"><i class="fas fa-yen-sign me-2"></i>今日の売上</h6>
                    <div class="stats-number">¥<?= number_format($today_total_revenue) ?></div>
                    <div class="stats-label" style="color: rgba(255,255,255,0.8);"><?= $today_ride_records ?>回の乗車</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card revenue-month-card">
                    <h6 class="mb-2"><i class="fas fa-calendar-alt me-2"></i>今月の売上</h6>
                    <div class="stats-number">¥<?= number_format($month_total_revenue) ?></div>
                    <div class="stats-label" style="color: rgba(255,255,255,0.8);"><?= $month_ride_records ?>回の乗車</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h6 class="mb-2"><i class="fas fa-chart-bar me-2"></i>月平均</h6>
                    <div class="stats-number text-secondary">¥<?= number_format($month_avg_revenue) ?></div>
                    <div class="stats-label">1日あたり平均売上</div>
                </div>
            </div>
        </div>
        
        <!-- クイックアクション -->
        <div class="row">
            <!-- 運転業務 -->
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

            <!-- 終業・管理業務 -->
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

                    <!-- ✅ permission_levelのみでアクセス制御 -->
                    <?php if ($user_permission_level === 'Admin'): ?>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
