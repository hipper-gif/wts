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

// ヘッダー関数の読み込み
require_once 'includes/header.php';

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
    $is_driver = $user_data['is_driver'];
    $is_caller = $user_data['is_caller'];
    $is_manager = $user_data['is_manager'];
    
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

try {
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
    
    // 今日の乗車記録数と売上（total_fareカラムを使用）
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as count, 
            COALESCE(SUM(total_fare), 0) as revenue,
            COALESCE(SUM(cash_amount), 0) as cash_revenue,
            COALESCE(SUM(card_amount), 0) as card_revenue
        FROM ride_records 
        WHERE ride_date = ?
    ");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    $today_ride_records = $result ? $result['count'] : 0;
    $today_total_revenue = $result ? $result['revenue'] : 0;
    $today_cash_revenue = $result ? $result['cash_revenue'] : 0;
    $today_card_revenue = $result ? $result['card_revenue'] : 0;

    // 当月の乗車記録数と売上
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as count, 
            COALESCE(SUM(total_fare), 0) as revenue 
        FROM ride_records 
        WHERE ride_date >= ?
    ");
    $stmt->execute([$current_month_start]);
    $result = $stmt->fetch();
    $month_ride_records = $result ? $result['count'] : 0;
    $month_total_revenue = $result ? $result['revenue'] : 0;
    
    // 平均売上計算
    $days_in_month = date('j'); // 今月の経過日数
    $month_avg_revenue = $days_in_month > 0 ? round($month_total_revenue / $days_in_month) : 0;
    
    // 今日の平均単価計算
    $today_avg_fare = $today_ride_records > 0 ? round($today_total_revenue / $today_ride_records) : 0;
    
} catch (Exception $e) {
    error_log("Dashboard statistics error: " . $e->getMessage());
    // デフォルト値を設定
    $today_pre_duty_calls = 0;
    $today_post_duty_calls = 0;
    $today_departures = 0;
    $today_arrivals = 0;
    $today_ride_records = 0;
    $today_total_revenue = 0;
    $today_cash_revenue = 0;
    $today_card_revenue = 0;
    $month_ride_records = 0;
    $month_total_revenue = 0;
    $month_avg_revenue = 0;
    $today_avg_fare = 0;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - <?= htmlspecialchars($system_name) ?></title>
    
    <!-- 必須CSS（ヘッダー統一仕様準拠） -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header-unified.css">
    
    <style>
        :root {
            /* ヘッダー統一仕様のCSS変数を使用 */
            --ride-primary: #11998e;
            --ride-secondary: #38ef7d;
        }
        
        body {
            padding-bottom: 120px; /* フローティングヘッダー分の余白 */
            padding-top: calc(var(--header-height) + var(--subheader-height));
        }
        
        /* コンテンツカード */
        .content-card {
            background: var(--bg-primary);
            border-radius: var(--border-radius-lg);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-md);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
        }
        
        .summary-card {
            background: var(--accent);
            color: var(--text-inverse);
            text-align: center;
        }
        
        .summary-stat {
            padding: var(--spacing-md) var(--spacing-xs);
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
            color: inherit;
        }
        
        .stat-label {
            font-size: 0.75rem;
            opacity: 0.9;
            margin-top: var(--spacing-xs);
        }
        
        /* 右下フローティング乗務記録ヘッダー */
        .mobile-ride-header {
            position: fixed;
            bottom: var(--spacing-lg);
            right: var(--spacing-lg);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: var(--spacing-md);
        }
        
        /* 乗務記録専用ヘッダーバー */
        .ride-header-bar {
            background: linear-gradient(135deg, var(--ride-primary) 0%, var(--ride-secondary) 100%);
            color: var(--text-inverse);
            padding: var(--spacing-sm) var(--spacing-lg);
            border-radius: 25px;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            min-width: 280px;
            transform: translateX(220px);
            transition: transform 0.3s ease;
        }
        
        .ride-header-bar.expanded {
            transform: translateX(0);
        }
        
        .ride-toggle-btn {
            background: var(--ride-primary);
            color: var(--text-inverse);
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            font-size: 1.25rem;
            box-shadow: var(--shadow-md);
            cursor: pointer;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .ride-toggle-btn:hover {
            background: var(--ride-secondary);
            transform: scale(1.1);
        }
        
        .ride-header-content {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .ride-header-bar.expanded .ride-header-content {
            opacity: 1;
        }
        
        .ride-quick-action {
            background: rgba(255,255,255,0.2);
            border: none;
            color: var(--text-inverse);
            padding: var(--spacing-xs) var(--spacing-sm);
            border-radius: var(--border-radius);
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .ride-quick-action:hover {
            background: rgba(255,255,255,0.3);
            color: var(--text-inverse);
            transform: translateY(-1px);
        }
        
        /* ステータス表示 */
        .ride-status-indicator {
            background: var(--bg-primary);
            color: var(--ride-primary);
            border-radius: 20px;
            padding: var(--spacing-md);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            min-width: 200px;
            transform: translateX(140px);
            transition: transform 0.3s ease;
            border: 1px solid var(--border-light);
        }
        
        .ride-status-indicator.expanded {
            transform: translateX(0);
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .status-text {
            font-size: 0.8125rem;
            font-weight: 600;
            flex: 1;
        }
        
        /* 今日の記録カウンター */
        .ride-counter {
            background: var(--bg-primary);
            color: var(--text-primary);
            border-radius: 20px;
            padding: var(--spacing-sm) var(--spacing-md);
            box-shadow: var(--shadow-sm);
            text-align: center;
            min-width: 120px;
            transform: translateX(60px);
            transition: transform 0.3s ease;
            border: 1px solid var(--border-light);
        }
        
        .ride-counter.expanded {
            transform: translateX(0);
        }
        
        .counter-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ride-primary);
            line-height: 1;
        }
        
        .counter-label {
            font-size: 0.6875rem;
            color: var(--text-muted);
        }
        
        /* 緊急アクション用ボタン */
        .emergency-ride-btn {
            background: var(--warning);
            color: var(--text-inverse);
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-sm);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .emergency-ride-btn:hover {
            background: var(--danger);
            transform: scale(1.1);
        }
        
        /* 展開時のオーバーレイ */
        .ride-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.3);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .ride-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* クイックアクションボタン */
        .quick-btn {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--border-radius);
            padding: var(--spacing-md);
            text-decoration: none;
            color: var(--text-primary);
            display: block;
            margin-bottom: var(--spacing-sm);
            transition: all 0.3s ease;
        }
        
        .quick-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .quick-btn i {
            font-size: 1.25rem;
            margin-right: var(--spacing-sm);
            width: 30px;
        }
        
        /* スマホ画面サイズでの最適化 */
        @media (max-width: 430px) {
            .ride-header-bar {
                min-width: 250px;
                transform: translateX(190px);
                padding: var(--spacing-sm) var(--spacing-md);
            }
            
            .ride-status-indicator {
                min-width: 180px;
                transform: translateX(120px);
            }
            
            .ride-counter {
                min-width: 100px;
                transform: translateX(40px);
            }
        }
        
        @media (max-width: 375px) {
            .mobile-ride-header {
                bottom: var(--spacing-md);
                right: var(--spacing-md);
            }
            
            .ride-header-bar {
                min-width: 220px;
                transform: translateX(160px);
            }
        }
    </style>
</head>
<body>
    <!-- システムヘッダー（統一仕様準拠） -->
    <?= renderSystemHeader($user_name, $user_role_display, 'dashboard') ?>
    
    <!-- ページヘッダー（統一仕様準拠） -->
    <?= renderPageHeader('tachometer-alt', 'ダッシュボード', '業務状況・売上実績') ?>

    <div class="container-fluid">
        <!-- 業務状況セクション -->
        <?= renderSectionHeader('chart-line', '今日の実績', date('n月j日(D)', strtotime($today))) ?>
        
        <div class="content-card summary-card">
            <div class="row">
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value"><?= $today_ride_records ?></div>
                        <div class="stat-label">乗車</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value">¥<?= $today_total_revenue >= 10000 ? number_format($today_total_revenue/1000, 0).'K' : number_format($today_total_revenue) ?></div>
                        <div class="stat-label">売上</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value"><?= $today_departures - $today_arrivals ?></div>
                        <div class="stat-label">未入庫</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value">¥<?= $today_avg_fare >= 1000 ? number_format($today_avg_fare/1000, 1).'K' : $today_avg_fare ?></div>
                        <div class="stat-label">平均</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 業務進捗セクション -->
        <?= renderSectionHeader('tasks', '今日の業務進捗') ?>
        
        <div class="content-card">
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-<?= $today_departures > 0 ? 'success' : 'secondary' ?>">
                    <i class="fas fa-<?= $today_departures > 0 ? 'check-circle' : 'circle' ?>"></i> 日常点検・点呼
                </span>
                <span class="badge bg-<?= $today_departures > 0 ? 'success' : 'secondary' ?>">
                    <i class="fas fa-<?= $today_departures > 0 ? 'check-circle' : 'circle' ?>"></i> 出庫
                </span>
                <span class="badge bg-<?= $today_ride_records > 0 ? 'warning' : 'secondary' ?>">
                    <i class="fas fa-<?= $today_ride_records > 0 ? 'car' : 'circle' ?>"></i> 運行中
                </span>
                <span class="badge bg-<?= $today_arrivals > 0 ? 'success' : 'secondary' ?>">
                    <i class="fas fa-<?= $today_arrivals > 0 ? 'check-circle' : 'circle' ?>"></i> 入庫
                </span>
                <span class="badge bg-<?= $today_post_duty_calls > 0 ? 'success' : 'secondary' ?>">
                    <i class="fas fa-<?= $today_post_duty_calls > 0 ? 'check-circle' : 'circle' ?>"></i> 点呼完了
                </span>
            </div>
        </div>

        <!-- よく使う機能セクション -->
        <?= renderSectionHeader('bolt', 'よく使う機能') ?>
        
        <div class="content-card">
            <!-- 乗車記録を最上位に -->
            <a href="ride_records.php" class="quick-btn">
                <i class="fas fa-car text-success"></i>
                <strong>乗車記録</strong> - 営業中の記録入力
            </a>
            
            <div class="row g-2">
                <div class="col-6">
                    <a href="daily_inspection.php" class="quick-btn">
                        <i class="fas fa-tools text-secondary"></i>
                        日常点検
                    </a>
                </div>
                <div class="col-6">
                    <a href="pre_duty_call.php" class="quick-btn">
                        <i class="fas fa-clipboard-check text-warning"></i>
                        乗務前点呼
                    </a>
                </div>
                <div class="col-6">
                    <a href="departure.php" class="quick-btn">
                        <i class="fas fa-truck-pickup text-primary"></i>
                        出庫処理
                    </a>
                </div>
                <div class="col-6">
                    <a href="arrival.php" class="quick-btn">
                        <i class="fas fa-truck-pickup text-info"></i>
                        入庫処理
                    </a>
                </div>
            </div>
        </div>

        <!-- 管理機能（Admin権限のみ） -->
        <?php if ($is_admin): ?>
        <?= renderSectionHeader('cogs', '管理機能') ?>
        <div class="content-card">
            <div class="row g-2">
                <div class="col-6">
                    <a href="cash_management.php" class="quick-btn">
                        <i class="fas fa-yen-sign text-success"></i>
                        集金管理
                    </a>
                </div>
                <div class="col-6">
                    <a href="user_management.php" class="quick-btn">
                        <i class="fas fa-users text-secondary"></i>
                        ユーザー管理
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 統計情報セクション -->
        <?= renderSectionHeader('chart-bar', '今月の実績') ?>
        
        <div class="content-card">
            <div class="row text-center">
                <div class="col-4">
                    <div style="color: var(--accent);">
                        <strong><?= $month_ride_records ?></strong>
                        <div><small>総乗車回数</small></div>
                    </div>
                </div>
                <div class="col-4">
                    <div style="color: var(--success);">
                        <strong>¥<?= number_format($month_total_revenue) ?></strong>
                        <div><small>総売上</small></div>
                    </div>
                </div>
                <div class="col-4">
                    <div style="color: var(--info);">
                        <strong>¥<?= number_format($month_avg_revenue) ?></strong>
                        <div><small>日平均</small></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 右下フローティング乗務記録ヘッダー -->
    <div class="mobile-ride-header">
        <!-- 緊急アクション（復路作成等） -->
        <button class="emergency-ride-btn" onclick="location.href='ride_records.php?action=return'" title="復路作成">
            <i class="fas fa-sync-alt"></i>
        </button>
        
        <!-- 今日の記録カウンター -->
        <div class="ride-counter" id="rideCounter">
            <div class="counter-number"><?= $today_ride_records ?></div>
            <div class="counter-label">今日の記録</div>
        </div>
        
        <!-- ステータス表示 -->
        <div class="ride-status-indicator" id="rideStatus">
            <div class="status-dot"></div>
            <div class="status-text">
                <?= $today_ride_records > 0 ? "運行中 - {$today_ride_records}件記録済み" : '運行中 - 記録待ち' ?>
            </div>
            <button class="btn btn-sm btn-outline-success" onclick="quickAddRide()">
                <i class="fas fa-plus"></i>
            </button>
        </div>
        
        <!-- メイン乗務記録ヘッダー -->
        <div class="ride-header-bar" id="rideHeaderBar">
            <button class="ride-toggle-btn" onclick="toggleRideHeader()">
                <i class="fas fa-car" id="toggleIcon"></i>
            </button>
            <div class="ride-header-content">
                <button class="ride-quick-action" onclick="location.href='ride_records.php'">
                    <i class="fas fa-list"></i> 一覧
                </button>
                <button class="ride-quick-action" onclick="location.href='ride_records.php?action=add'">
                    <i class="fas fa-plus"></i> 新規
                </button>
                <button class="ride-quick-action" onclick="location.href='ride_records.php?action=stats'">
                    <i class="fas fa-chart-bar"></i> 統計
                </button>
                <?php if ($is_driver): ?>
                <button class="ride-quick-action" onclick="location.href='ride_records.php?driver_id=<?= $_SESSION['user_id'] ?>'">
                    <i class="fas fa-user"></i> 個人
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- オーバーレイ -->
    <div class="ride-overlay" id="rideOverlay" onclick="closeRideHeader()"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let isRideHeaderExpanded = false;

        // 乗務記録ヘッダーの展開/収納
        function toggleRideHeader() {
            isRideHeaderExpanded = !isRideHeaderExpanded;
            const headerBar = document.getElementById('rideHeaderBar');
            const counter = document.getElementById('rideCounter');
            const status = document.getElementById('rideStatus');
            const overlay = document.getElementById('rideOverlay');
            const icon = document.getElementById('toggleIcon');

            if (isRideHeaderExpanded) {
                headerBar.classList.add('expanded');
                counter.classList.add('expanded');
                status.classList.add('expanded');
                overlay.classList.add('active');
                icon.className = 'fas fa-times';
                
                // ハプティックフィードバック
                if (navigator.vibrate) {
                    navigator.vibrate(50);
                }
                
                localStorage.setItem('ride_header_used', 'true');
            } else {
                closeRideHeader();
            }
        }

        function closeRideHeader() {
            isRideHeaderExpanded = false;
            document.getElementById('rideHeaderBar').classList.remove('expanded');
            document.getElementById('rideCounter').classList.remove('expanded');
            document.getElementById('rideStatus').classList.remove('expanded');
            document.getElementById('rideOverlay').classList.remove('active');
            document.getElementById('toggleIcon').className = 'fas fa-car';
        }

        // クイック新規記録
        function quickAddRide() {
            if (navigator.vibrate) {
                navigator.vibrate([50, 50, 50]);
            }
            location.href = 'ride_records.php?action=quick_add';
        }

        // スワイプジェスチャー対応
        let startX = 0, startY = 0;

        document.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        });

        document.addEventListener('touchend', function(e) {
            const endX = e.changedTouches[0].clientX;
            const diffX = startX - endX;
            const diffY = Math.abs(startY - e.changedTouches[0].clientY);
            
            // 右下からの左スワイプで展開
            if (startX > window.innerWidth - 100 && 
                startY > window.innerHeight - 200 &&
                diffX > 50 && diffY < 50) {
                if (!isRideHeaderExpanded) {
                    toggleRideHeader();
                }
            }
        });

        // キーボードショートカット
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                toggleRideHeader();
            }
        });

        // 初回ガイド
        if (!localStorage.getItem('ride_header_used')) {
            setTimeout(() => {
                const tooltip = document.createElement('div');
                tooltip.style.cssText = `
                    position: fixed; bottom: 90px; right: 25px;
                    background: rgba(0,0,0,0.8); color: white;
                    padding: 8px 12px; border-radius: 15px; font-size: 11px;
                    z-index: 1001; animation: fadeInOut 4s ease-in-out;
                `;
                tooltip.textContent = '👈 右下で乗務記録へ';
                document.body.appendChild(tooltip);
                
                const style = document.createElement('style');
                style.textContent = `
                    @keyframes fadeInOut {
                        0%, 100% { opacity: 0; transform: translateX(20px); }
                        20%, 80% { opacity: 1; transform: translateX(0); }
                    }
                `;
                document.head.appendChild(style);
                
                setTimeout(() => tooltip.remove(), 4000);
            }, 3000);
        }
    </script>
</body>
</html>
