<?php
session_start();

// 既存のデータベース設定を使用（定数重複を避ける）
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続取得
$pdo = getDBConnection();

// ユーザー情報取得（permission_levelベース）
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
    if ($is_admin) {
        $user_role = 'システム管理者';
    } else {
        $roles = [];
        if ($user_data['is_driver']) $roles[] = '運転者';
        if ($user_data['is_caller']) $roles[] = '点呼者';
        if ($user_data['is_manager']) $roles[] = '管理者';
        $user_role = !empty($roles) ? implode('・', $roles) : '一般ユーザー';
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

// システム名を取得（設定可能システム名機能）
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

// 売上情報計算クラス（Layer 1用）
class RevenueCalculator {
    
    public static function getTodayRevenue($pdo) {
        $today = date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as total_revenue,
                COUNT(*) as ride_count,
                CASE 
                    WHEN COUNT(*) > 0 THEN ROUND(SUM(fare + COALESCE(charge, 0)) / COUNT(*), 0)
                    ELSE 0 
                END as avg_fare
            FROM ride_records 
            WHERE ride_date = ?
        ");
        $stmt->execute([$today]);
        return $stmt->fetch();
    }
    
    public static function getMonthlyRevenue($pdo) {
        $month_start = date('Y-m-01');
        
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as total_revenue,
                COUNT(*) as ride_count,
                COUNT(DISTINCT ride_date) as working_days
            FROM ride_records 
            WHERE ride_date >= ?
        ");
        $stmt->execute([$month_start]);
        return $stmt->fetch();
    }
    
    public static function getPreviousMonthComparison($pdo) {
        $this_month_start = date('Y-m-01');
        $prev_month_start = date('Y-m-01', strtotime('-1 month'));
        $prev_month_end = date('Y-m-t', strtotime('-1 month'));
        
        // 今月の売上
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as revenue 
            FROM ride_records 
            WHERE ride_date >= ?
        ");
        $stmt->execute([$this_month_start]);
        $this_month = $stmt->fetchColumn();
        
        // 先月の売上
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as revenue 
            FROM ride_records 
            WHERE ride_date BETWEEN ? AND ?
        ");
        $stmt->execute([$prev_month_start, $prev_month_end]);
        $prev_month = $stmt->fetchColumn();
        
        $difference = $this_month - $prev_month;
        $percentage = $prev_month > 0 ? round(($difference / $prev_month) * 100, 1) : 0;
        
        return [
            'difference' => $difference,
            'percentage' => $percentage,
            'is_positive' => $difference >= 0
        ];
    }
}

// 売上データ取得
$today_revenue = RevenueCalculator::getTodayRevenue($pdo);
$monthly_revenue = RevenueCalculator::getMonthlyRevenue($pdo);
$comparison = RevenueCalculator::getPreviousMonthComparison($pdo);

// 日平均計算
$daily_average = $monthly_revenue['working_days'] > 0 ? 
    round($monthly_revenue['total_revenue'] / $monthly_revenue['working_days']) : 0;

// アラートシステム（Layer 2用）
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
            'icon' => 'fas fa-exclamation-triangle',
            'title' => '乗務前点呼未実施',
            'message' => "運転者「{$driver['driver_name']}」が乗務前点呼を行わずに乗車記録（{$driver['ride_count']}件）を登録しています。",
            'action' => 'pre_duty_call.php',
            'action_text' => '乗務前点呼を実施'
        ];
    }

    // 2. 18時以降の未入庫・未点呼チェック
    if ($current_hour >= 18) {
        // 未入庫車両
        $stmt = $pdo->prepare("
            SELECT dr.vehicle_id, v.vehicle_number, u.name as driver_name, dr.departure_time
            FROM departure_records dr
            JOIN vehicles v ON dr.vehicle_id = v.id
            JOIN users u ON dr.driver_id = u.id
            LEFT JOIN arrival_records ar ON dr.vehicle_id = ar.vehicle_id AND dr.departure_date = ar.arrival_date
            WHERE dr.departure_date = ? AND ar.id IS NULL
        ");
        $stmt->execute([$today]);
        $not_arrived = $stmt->fetchAll();
        
        foreach ($not_arrived as $vehicle) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'fas fa-clock',
                'title' => '入庫処理未完了',
                'message' => "車両「{$vehicle['vehicle_number']}」（運転者：{$vehicle['driver_name']}）が18時以降も入庫処理を完了していません。",
                'action' => 'arrival.php',
                'action_text' => '入庫処理を実施'
            ];
        }
    }

} catch (Exception $e) {
    error_log("Dashboard alert error: " . $e->getMessage());
}

// 業務進捗データ取得
try {
    // 各業務の完了件数取得
    $business_progress = [];
    
    $queries = [
        'departures' => "SELECT COUNT(*) FROM departure_records WHERE departure_date = ?",
        'arrivals' => "SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?",
        'rides' => "SELECT COUNT(*) FROM ride_records WHERE ride_date = ?",
        'pre_calls' => "SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND is_completed = TRUE",
        'post_calls' => "SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND is_completed = TRUE"
    ];
    
    foreach ($queries as $key => $query) {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$today]);
        $business_progress[$key] = $stmt->fetchColumn();
    }
    
} catch (Exception $e) {
    error_log("Business progress error: " . $e->getMessage());
    $business_progress = ['departures' => 0, 'arrivals' => 0, 'rides' => 0, 'pre_calls' => 0, 'post_calls' => 0];
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
    <meta name="theme-color" content="#2196F3">
    <meta name="description" content="福祉輸送業務の総合管理システム">
    <style>
        /* ========== ダッシュボード全体 ========== */
        body {
            font-family: 'Noto Sans JP', 'Hiragino Kaku Gothic ProN', sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            padding-top: 76px; /* ナビゲーションバー分のパディング */
        }

        /* ========== ナビゲーションバー ========== */
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            z-index: 1030;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        /* ========== Layer 1: 売上ヘッダー ========== */
        .revenue-header {
            background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
            box-shadow: 0 2px 10px rgba(33, 150, 243, 0.3);
            z-index: 1000;
        }

        .revenue-item {
            padding: 0 15px;
            border-right: 1px solid rgba(255,255,255,0.2);
        }

        .revenue-item:last-child {
            border-right: none;
        }

        .revenue-item .amount {
            font-weight: 700;
            color: #FFFFFF;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .revenue-item .detail {
            opacity: 0.9;
            font-size: 0.85rem;
        }

        .revenue-item .average {
            opacity: 0.7;
            font-size: 0.75rem;
        }

        .comparison .badge {
            font-size: 0.9rem;
            border-radius: 20px;
            padding: 8px 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* ========== Layer 2: アラート ========== */
        .alert-area .alert {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid;
        }

        .alert-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-left-color: #a71e2a !important;
        }

        .alert-warning {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #212529;
            border-left-color: #d39e00 !important;
        }

        .alert-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-left-color: #1e7e34 !important;
        }

        .alert-icon {
            min-width: 50px;
            text-align: center;
        }

        .alert-title {
            font-size: 1rem;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .alert-message {
            font-size: 0.9rem;
            opacity: 0.9;
            line-height: 1.4;
        }

        /* ========== Layer 3: 乗車記録アクセス ========== */
        .ride-access-section {
            background: linear-gradient(to bottom, #f8f9fa 0%, #e9ecef 100%);
        }

        .ride-access-section .btn-lg {
            padding: 15px 20px;
            font-size: 1.1rem;
            border-radius: 12px;
            transition: all 0.3s ease;
            min-height: 60px;
        }

        .ride-access-section .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.3);
        }

        .ride-access-section .btn-outline-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.15);
        }

        /* ========== Layer 4: 二層業務フロー ========== */
        .workflow-section {
            background-color: #ffffff;
        }

        .workflow-group {
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
        }

        .workflow-group-title {
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
            font-weight: 600;
        }

        .progress-flow {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: nowrap;
            gap: 20px;
        }

        .step {
            flex: 1;
            max-width: 200px;
            text-align: center;
            padding: 1.5rem;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .step.large {
            max-width: 300px;
            padding: 2rem;
        }

        .step.completed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-color: #1e7e34;
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .step.pending {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #6c757d;
            border-color: #dee2e6;
        }

        .step:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .step-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .step.large .step-icon {
            font-size: 3rem;
        }

        .step-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .step.large .step-title {
            font-size: 1.25rem;
        }

        .step-subtitle {
            font-size: 0.875rem;
            opacity: 0.8;
            margin-bottom: 1rem;
        }

        .arrow {
            flex: 0 0 auto;
            font-size: 1.5rem;
            color: #6c757d;
            margin: 0 10px;
        }

        /* ========== 管理機能セクション ========== */
        .admin-section {
            background-color: #f8f9fa;
            border-top: 3px solid #dee2e6;
        }

        .admin-section .btn {
            border-radius: 12px;
            transition: all 0.3s ease;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .admin-section .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        /* ========== レスポンシブ対応 ========== */
        @media (max-width: 768px) {
            body {
                padding-top: 66px; /* モバイル用ナビゲーションバー調整 */
            }
            
            .revenue-header .h4 {
                font-size: 1.1rem;
            }
            
            .revenue-item .detail {
                font-size: 0.8rem;
            }
            
            .revenue-item .average {
                font-size: 0.7rem;
            }
            
            .ride-access-section .btn-lg {
                padding: 12px 16px;
                font-size: 1rem;
                min-height: 50px;
            }
            
            .progress-flow {
                flex-direction: column;
                gap: 15px;
            }
            
            .arrow {
                transform: rotate(90deg);
                margin: 10px 0;
            }
            
            .step {
                max-width: 100%;
                flex-direction: row;
                text-align: left;
                padding: 1rem;
            }
            
            .step-icon {
                margin-right: 15px;
                margin-bottom: 0;
                font-size: 1.5rem;
            }
            
            .workflow-group {
                padding: 1.5rem;
            }
            
            .admin-section .btn {
                min-height: 60px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .revenue-header {
                padding: 15px 10px !important;
            }
            
            .container {
                padding-left: 10px;
                padding-right: 10px;
            }
            
            .alert-area .alert {
                padding: 12px;
            }
            
            .alert-icon {
                min-width: 40px;
            }
            
            .workflow-group {
                padding: 1rem;
            }
            
            .step {
                padding: 0.8rem;
            }
        }

        /* ========== アニメーション ========== */
        @keyframes slideInDown {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .revenue-header {
            animation: slideInDown 0.5s ease-out;
        }

        .alert-area .alert {
            animation: fadeIn 0.3s ease-in;
        }

        .workflow-group {
            animation: fadeIn 0.4s ease-in;
        }

        .alert-danger {
            animation: pulse 2s infinite;
        }

        /* ========== PWA対応 ========== */
        @media (display-mode: standalone) {
            body {
                padding-top: 0; /* PWAモードではナビゲーションバーなし */
            }
        }

        /* ========== システム情報表示 ========== */
        .system-info {
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <!-- システムナビゲーションバー -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-taxi me-2"></i>
                <span class="d-none d-lg-inline"><?= htmlspecialchars($system_name) ?> v3.1</span>
                <span class="d-none d-md-inline d-lg-none">福祉輸送管理 v3.1</span>
                <span class="d-md-none">WTS v3.1</span>
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_role) ?>)
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                </a>
            </div>
        </div>
    </nav>

    <!-- Layer 1: 売上情報ヘッダー（sticky） -->
    <div class="revenue-header sticky-top text-white p-3 shadow">
        <div class="container">
            <!-- メイン売上表示 -->
            <div class="row text-center mb-2">
                <div class="col-4">
                    <div class="revenue-item today-revenue">
                        <div class="amount h4 mb-1">¥<?= number_format($today_revenue['total_revenue']) ?></div>
                        <div class="detail small">今日 <?= $today_revenue['ride_count'] ?>回</div>
                        <div class="average tiny">平均 ¥<?= number_format($today_revenue['avg_fare']) ?>/回</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="revenue-item monthly-revenue">
                        <div class="amount h4 mb-1">¥<?= number_format($monthly_revenue['total_revenue']) ?></div>
                        <div class="detail small">今月 <?= $monthly_revenue['ride_count'] ?>回</div>
                        <div class="average tiny">稼働 <?= $monthly_revenue['working_days'] ?>日</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="revenue-item daily-average">
                        <div class="amount h4 mb-1">¥<?= number_format($daily_average) ?></div>
                        <div class="detail small">日平均</div>
                        <div class="average tiny">実稼働ベース</div>
                    </div>
                </div>
            </div>
            
            <!-- 先月比較 -->
            <div class="comparison text-center">
                <span class="badge bg-<?= $comparison['is_positive'] ? 'success' : 'danger' ?> px-3 py-2">
                    <i class="fas fa-arrow-<?= $comparison['is_positive'] ? 'up' : 'down' ?>"></i>
                    先月比 <?= $comparison['is_positive'] ? '+' : '' ?>¥<?= number_format(abs($comparison['difference'])) ?>
                    (<?= $comparison['is_positive'] ? '+' : '' ?><?= $comparison['percentage'] ?>%)
                </span>
            </div>
        </div>
    </div>

    <!-- Layer 2: アラート表示エリア -->
    <div class="alert-area py-3" style="background-color: #f8f9fa;">
        <div class="container">
            <?php if (!empty($alerts)): ?>
                <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-<?= $alert['type'] ?> alert-dismissible fade show border-0 shadow-sm mb-2">
                    <div class="d-flex align-items-center">
                        <div class="alert-icon me-3">
                            <i class="<?= $alert['icon'] ?> fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="alert-title fw-bold"><?= htmlspecialchars($alert['title']) ?></div>
                            <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
                        </div>
                        <?php if (isset($alert['action'])): ?>
                        <div class="alert-action">
                            <a href="<?= $alert['action'] ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-arrow-right me-1"></i><?= htmlspecialchars($alert['action_text']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-success border-0 shadow-sm">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle fs-4 me-3"></i>
                        <div>
                            <div class="fw-bold">業務は正常に進行中です</div>
                            <div class="small">現在、緊急対応が必要な問題はありません</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Layer 3: 乗車記録アクセス（メインエリア） -->
    <div class="ride-access-section py-4">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card shadow border-0">
                        <div class="card-body text-center p-5">
                            <h3 class="mb-4">
                                <i class="fas fa-users text-primary me-2"></i>
                                乗車記録管理
                            </h3>
                            <p class="text-muted mb-4">最も頻繁に使用する機能への快速アクセス</p>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <a href="ride_records.php?action=new" class="btn btn-primary btn-lg w-100 py-3">
                                        <i class="fas fa-plus me-2"></i>
                                        新規乗車記録
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="ride_records.php" class="btn btn-outline-primary btn-lg w-100 py-3">
                                        <i class="fas fa-list me-2"></i>
                                        記録一覧・編集
                                    </a>
                                </div>
                            </div>
                            
                            <!-- 復路作成機能の説明 -->
                            <div class="mt-4 p-3 bg-light rounded">
                                <small class="text-muted">
                                    <i class="fas fa-lightbulb me-1"></i>
                                    復路作成機能でワンクリック復路登録が可能です
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Layer 4: 二層業務フロー -->
    <div class="workflow-section py-4" style="background-color: #ffffff;">
        <div class="container">
            <h4 class="text-center mb-4">
                <i class="fas fa-route me-2"></i>
                7段階業務フロー
            </h4>
            
            <!-- 開始業務グループ -->
            <div class="workflow-group mb-4">
                <h5 class="workflow-group-title">
                    <i class="fas fa-play-circle text-success me-2"></i>
                    開始業務
                </h5>
                <div class="progress-flow d-flex justify-content-between align-items-center">
                    <!-- Step 1: 日常点検 -->
                    <div class="step <?= $business_progress['departures'] > 0 ? 'completed' : 'pending' ?>">
                        <div class="step-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">日常点検</div>
                            <div class="step-subtitle">17項目</div>
                            <a href="daily_inspection.php" class="btn btn-sm btn-outline-primary mt-2">実施</a>
                        </div>
                    </div>
                    
                    <div class="arrow"><i class="fas fa-arrow-right"></i></div>
                    
                    <!-- Step 2: 乗務前点呼 -->
                    <div class="step <?= $business_progress['pre_calls'] > 0 ? 'completed' : 'pending' ?>">
                        <div class="step-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">乗務前点呼</div>
                            <div class="step-subtitle">16項目</div>
                            <a href="pre_duty_call.php" class="btn btn-sm btn-outline-warning mt-2">実施</a>
                        </div>
                    </div>
                    
                    <div class="arrow"><i class="fas fa-arrow-right"></i></div>
                    
                    <!-- Step 3: 出庫処理 -->
                    <div class="step <?= $business_progress['departures'] > 0 ? 'completed' : 'pending' ?>">
                        <div class="step-icon">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">出庫処理</div>
                            <div class="step-subtitle">時刻・天候</div>
                            <a href="departure.php" class="btn btn-sm btn-outline-info mt-2">実施</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 営業業務 -->
            <div class="workflow-group mb-4">
                <h5 class="workflow-group-title">
                    <i class="fas fa-business-time text-primary me-2"></i>
                    営業業務
                </h5>
                <div class="text-center py-4">
                    <div class="step large <?= $business_progress['rides'] > 0 ? 'completed' : 'pending' ?>">
                        <div class="step-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">乗車記録</div>
                            <div class="step-subtitle">今日 <?= $business_progress['rides'] ?>件</div>
                            <a href="ride_records.php" class="btn btn-success btn-lg mt-3">記録管理</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 終了業務グループ -->
            <div class="workflow-group">
                <h5 class="workflow-group-title">
                    <i class="fas fa-stop-circle text-danger me-2"></i>
                    終了業務
                </h5>
                <div class="progress-flow d-flex justify-content-between align-items-center">
                    <!-- Step 5: 入庫処理 -->
                    <div class="step <?= $business_progress['arrivals'] > 0 ? 'completed' : 'pending' ?>">
                        <div class="step-icon">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">入庫処理</div>
                            <div class="step-subtitle">走行距離</div>
                            <a href="arrival.php" class="btn btn-sm btn-outline-info mt-2">実施</a>
                        </div>
                    </div>
                    
                    <div class="arrow"><i class="fas fa-arrow-right"></i></div>
                    
                    <!-- Step 6: 乗務後点呼 -->
                    <div class="step <?= $business_progress['post_calls'] > 0 ? 'completed' : 'pending' ?>">
                        <div class="step-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">乗務後点呼</div>
                            <div class="step-subtitle">12項目</div>
                            <a href="post_duty_call.php" class="btn btn-sm btn-outline-warning mt-2">実施</a>
                        </div>
                    </div>
                    
                    <div class="arrow"><i class="fas fa-arrow-right"></i></div>
                    
                    <!-- Step 7: 売上金確認 -->
                    <div class="step pending">
                        <div class="step-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="step-content">
                            <div class="step-title">売上金確認</div>
                            <div class="step-subtitle">現金管理</div>
                            <a href="cash_management.php" class="btn btn-sm btn-outline-success mt-2">実施</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 管理機能（管理者のみ表示） -->
    <?php if ($is_admin): ?>
    <div class="admin-section py-4" style="background-color: #f8f9fa;">
        <div class="container">
            <h4 class="text-center mb-4">
                <i class="fas fa-cogs me-2"></i>
                管理機能
            </h4>
            <div class="row g-3">
                <div class="col-md-3">
                    <a href="master_menu.php" class="btn btn-outline-secondary w-100 py-3">
                        <i class="fas fa-list me-2"></i>
                        マスターメニュー
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="user_management.php" class="btn btn-outline-secondary w-100 py-3">
                        <i class="fas fa-users me-2"></i>
                        ユーザー管理
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="vehicle_management.php" class="btn btn-outline-secondary w-100 py-3">
                        <i class="fas fa-car me-2"></i>
                        車両管理
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="annual_report.php" class="btn btn-outline-secondary w-100 py-3">
                        <i class="fas fa-file-alt me-2"></i>
                        陸運局報告
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- フッター -->
    <footer class="py-4 text-center text-muted">
        <div class="container">
            <div class="system-info">
                <span class="d-none d-lg-inline"><?= htmlspecialchars($system_name) ?> v3.1</span>
                <span class="d-none d-md-inline d-lg-none">福祉輸送管理 v3.1</span>
                <span class="d-md-none">WTS v3.1</span>
                &nbsp;|&nbsp;
                <span id="current-time"><?= date('Y年n月j日 H:i') ?></span>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- カスタムJavaScript -->
    <script>
    class DashboardManager {
        constructor() {
            this.init();
        }
        
        init() {
            this.setupRealtimeUpdates();
            this.setupNotifications();
            this.setupMobileOptimizations();
        }
        
        // リアルタイム更新（5分間隔）
        setupRealtimeUpdates() {
            setInterval(() => {
                this.updateRevenueData();
                this.checkForNewAlerts();
            }, 300000); // 5分
        }
        
        // 売上データ更新
        async updateRevenueData() {
            try {
                const response = await fetch('api/dashboard_revenue.php');
                if (response.ok) {
                    const data = await response.json();
                    if (data.success) {
                        this.updateRevenueDisplay(data.revenue);
                    }
                }
            } catch (error) {
                console.error('売上データ更新エラー:', error);
            }
        }
        
        // 売上表示更新
        updateRevenueDisplay(revenue) {
            // 今日の売上
            const todayAmount = document.querySelector('.today-revenue .amount');
            const todayDetail = document.querySelector('.today-revenue .detail');
            const todayAverage = document.querySelector('.today-revenue .average');
            
            if (todayAmount) {
                todayAmount.textContent = '¥' + revenue.today.amount.toLocaleString();
                todayDetail.textContent = `今日 ${revenue.today.count}回`;
                todayAverage.textContent = `平均 ¥${revenue.today.average.toLocaleString()}/回`;
            }
            
            // 月間売上
            const monthlyAmount = document.querySelector('.monthly-revenue .amount');
            const monthlyDetail = document.querySelector('.monthly-revenue .detail');
            const monthlyAverage = document.querySelector('.monthly-revenue .average');
            
            if (monthlyAmount) {
                monthlyAmount.textContent = '¥' + revenue.monthly.amount.toLocaleString();
                monthlyDetail.textContent = `今月 ${revenue.monthly.count}回`;
                monthlyAverage.textContent = `稼働 ${revenue.monthly.working_days}日`;
            }
            
            // 日平均
            const avgAmount = document.querySelector('.daily-average .amount');
            if (avgAmount) {
                avgAmount.textContent = '¥' + revenue.daily_average.toLocaleString();
            }
            
            // 先月比較
            const comparison = document.querySelector('.comparison .badge');
            if (comparison && revenue.comparison) {
                const isPositive = revenue.comparison.percentage >= 0;
                comparison.className = `badge bg-${isPositive ? 'success' : 'danger'} px-3 py-2`;
                comparison.innerHTML = `
                    <i class="fas fa-arrow-${isPositive ? 'up' : 'down'}"></i>
                    先月比 ${isPositive ? '+' : ''}¥${Math.abs(revenue.comparison.difference).toLocaleString()}
                    (${isPositive ? '+' : ''}${revenue.comparison.percentage}%)
                `;
            }
        }
        
        // アラートチェック
        async checkForNewAlerts() {
            try {
                const response = await fetch('api/dashboard_alerts.php');
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.alerts.length > 0) {
                        this.updateAlerts(data.alerts);
                    }
                }
            } catch (error) {
                console.error('アラートチェックエラー:', error);
            }
        }
        
        // 通知設定
        setupNotifications() {
            // 重要アラートがある場合の通知
            const criticalAlerts = document.querySelectorAll('.alert-danger');
            if (criticalAlerts.length > 0 && 'Notification' in window) {
                this.requestNotificationPermission();
            }
        }
        
        // 通知許可要求
        async requestNotificationPermission() {
            if (Notification.permission === 'default') {
                const permission = await Notification.requestPermission();
                if (permission === 'granted') {
                    new Notification('重要な業務漏れがあります', {
                        body: '乗務前点呼または出庫処理が未完了です',
                        icon: '/Smiley/taxi/wts/icons/icon-192x192.png',
                        tag: 'business-alert'
                    });
                }
            }
        }
        
        // モバイル最適化
        setupMobileOptimizations() {
            // タッチデバイス検出
            if ('ontouchstart' in window) {
                document.body.classList.add('touch-device');
                
                // スワイプナビゲーション
                this.setupSwipeNavigation();
            }
            
            // 画面向き変更対応
            window.addEventListener('orientationchange', () => {
                setTimeout(() => {
                    this.adjustLayoutForOrientation();
                }, 100);
            });
        }
        
        // 画面向き調整
        adjustLayoutForOrientation() {
            const isLandscape = window.innerHeight < window.innerWidth;
            document.body.classList.toggle('landscape-mode', isLandscape);
        }
        
        // スワイプナビゲーション
        setupSwipeNavigation() {
            let startX, startY, distX, distY;
            const threshold = 100;
            
            document.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
            });
            
            document.addEventListener('touchend', (e) => {
                distX = e.changedTouches[0].clientX - startX;
                distY = e.changedTouches[0].clientY - startY;
                
                if (Math.abs(distX) > Math.abs(distY) && Math.abs(distX) > threshold) {
                    if (distX > 0) {
                        // 右スワイプ: 前のページ
                        this.navigateToPrevious();
                    } else {
                        // 左スワイプ: 次のページ
                        this.navigateToNext();
                    }
                }
            });
        }
        
        // 前のページナビゲーション
        navigateToPrevious() {
            // 履歴がある場合は戻る
            if (window.history.length > 1) {
                window.history.back();
            }
        }
        
        // 次のページナビゲーション
        navigateToNext() {
            // 乗車記録への快速アクセス
            window.location.href = 'ride_records.php';
        }
        
        // 時刻更新
        updateCurrentTime() {
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                const now = new Date();
                const options = {
                    year: 'numeric',
                    month: 'numeric',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                };
                timeElement.textContent = now.toLocaleDateString('ja-JP', options);
            }
        }
    }

    // 初期化
    document.addEventListener('DOMContentLoaded', () => {
        const dashboard = new DashboardManager();
        
        // 時刻を1分ごとに更新
        setInterval(() => {
            dashboard.updateCurrentTime();
        }, 60000);
        
        // 初回時刻設定
        dashboard.updateCurrentTime();
    });

    // エラーハンドリング
    window.addEventListener('error', (e) => {
        console.error('ダッシュボードエラー:', e.error);
    });

    // オンライン/オフライン状態監視
    window.addEventListener('online', () => {
        console.log('オンライン状態に復帰');
        document.querySelector('.revenue-header').style.opacity = '1';
    });

    window.addEventListener('offline', () => {
        console.log('オフライン状態');
        document.querySelector('.revenue-header').style.opacity = '0.8';
    });
    </script>

</body>
</html>
