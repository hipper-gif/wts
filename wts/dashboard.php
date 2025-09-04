<?php
session_start();
require_once 'config/database.php';

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$pdo = getDBConnection();

// ユーザー権限取得
$stmt = $pdo->prepare("SELECT permission_level, NAME, is_driver, is_caller, is_admin, is_manager FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: logout.php');
    exit();
}

// 現在の日付
$today = date('Y-m-d');
$current_month = date('Y-m');
$last_month = date('Y-m', strtotime('-1 month'));

// 当日売上集計
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as ride_count,
        COALESCE(SUM(total_fare), 0) as total_revenue,
        COALESCE(AVG(total_fare), 0) as average_fare
    FROM ride_records 
    WHERE ride_date = ?
");
$stmt->execute([$today]);
$today_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// 当月売上集計
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as ride_count,
        COALESCE(SUM(total_fare), 0) as total_revenue,
        COUNT(DISTINCT ride_date) as working_days
    FROM ride_records 
    WHERE ride_date LIKE ?
");
$stmt->execute([$current_month . '%']);
$month_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// 先月売上（比較用）
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_fare), 0) as total_revenue
    FROM ride_records 
    WHERE ride_date LIKE ?
");
$stmt->execute([$last_month . '%']);
$last_month_revenue = $stmt->fetchColumn();

// 売上差額計算
$revenue_diff = $month_stats['total_revenue'] - $last_month_revenue;
$revenue_diff_rate = $last_month_revenue > 0 ? 
    ($revenue_diff / $last_month_revenue * 100) : 0;

// 出庫・未入庫状況
$stmt = $pdo->prepare("
    SELECT 
        COUNT(d.id) as departed_count,
        COUNT(a.id) as arrived_count,
        (COUNT(d.id) - COUNT(a.id)) as not_arrived_count
    FROM departure_records d
    LEFT JOIN arrival_records a ON d.id = a.departure_record_id
    WHERE d.departure_date = ?
");
$stmt->execute([$today]);
$operation_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// アラート条件判定
$current_hour = intval(date('H'));
$alerts = [];

// 朝の業務フローアラート（8時以降）
if ($current_hour >= 8) {
    // 日常点検漏れチェック
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date = ?");
    $stmt->execute([$today]);
    $inspection_count = $stmt->fetchColumn();
    
    if ($inspection_count == 0) {
        $alerts[] = [
            'type' => 'warning',
            'icon' => 'tools',
            'title' => '日常点検未実施',
            'message' => '本日の日常点検が未実施です'
        ];
    }
    
    // 乗務前点呼漏れチェック（8:30以降）
    if ($current_hour >= 8 || ($current_hour == 8 && intval(date('i')) >= 30)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ?");
        $stmt->execute([$today]);
        $call_count = $stmt->fetchColumn();
        
        if ($call_count == 0 && $inspection_count > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'clipboard-check',
                'title' => '乗務前点呼未実施',
                'message' => '乗務前点呼が未実施です'
            ];
        }
    }
}

// 夕方の業務確認アラート（18時以降）
if ($current_hour >= 18 && $operation_stats['not_arrived_count'] > 0) {
    $alerts[] = [
        'type' => 'info',
        'icon' => 'truck-pickup',
        'title' => '未入庫車両あり',
        'message' => $operation_stats['not_arrived_count'] . '台の車両が未入庫です'
    ];
}

// 売上金確認アラート（19時以降）
if ($current_hour >= 19) {
    // 売上金確認完了チェック（cash_confirmations テーブル）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_confirmations WHERE confirmation_date = ?");
    $stmt->execute([$today]);
    $cash_check_count = $stmt->fetchColumn();
    
    if ($cash_check_count == 0 && $today_stats['total_revenue'] > 0) {
        $alerts[] = [
            'type' => 'info',
            'icon' => 'yen-sign',
            'title' => '売上金確認',
            'message' => '本日の売上金確認をお願いします'
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>ダッシュボード - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #17a2b8 0%, #20c997 100%);
            --warning-gradient: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            --shadow: 0 4px 15px rgba(0,0,0,0.1);
            --border-radius: 15px;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* システムヘッダー */
        .system-header {
            background: var(--primary-gradient);
            color: white;
            padding: 15px 0;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .system-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .user-info {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* 売上カード */
        .revenue-cards {
            margin-bottom: 25px;
        }

        .revenue-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            border: 1px solid rgba(0,0,0,0.08);
            margin-bottom: 15px;
            position: relative;
            overflow: hidden;
        }

        .revenue-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .revenue-amount {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .revenue-label {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-bottom: 3px;
        }

        .revenue-meta {
            font-size: 0.8rem;
            color: #95a5a6;
        }

        .comparison-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 12px;
        }

        .comparison-positive {
            background: #d4edda;
            color: #155724;
        }

        .comparison-negative {
            background: #f8d7da;
            color: #721c24;
        }

        /* 業務フローカード */
        .workflow-section {
            margin-bottom: 25px;
        }

        .workflow-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: var(--shadow);
        }

        .workflow-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .workflow-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: white;
            font-size: 1.2rem;
        }

        .morning-flow .workflow-icon {
            background: var(--warning-gradient);
        }

        .evening-flow .workflow-icon {
            background: var(--info-gradient);
        }

        .workflow-title {
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }

        .workflow-steps {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .step-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            text-decoration: none;
            color: #495057;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .step-btn:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
            text-decoration: none;
        }

        /* アラート */
        .alerts-section {
            margin-bottom: 25px;
        }

        .alert-custom {
            border: none;
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            box-shadow: var(--shadow);
        }

        .alert-warning {
            background: linear-gradient(135deg, #ffeaa7 0%, #fdcb6e 100%);
            color: #d63031;
        }

        .alert-info {
            background: linear-gradient(135deg, #a8e6cf 0%, #74b9ff 100%);
            color: #0984e3;
        }

        .alert-icon {
            font-size: 1.2rem;
            margin-right: 12px;
        }

        /* メインメニュー */
        .menu-section {
            margin-bottom: 25px;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .menu-item {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #2c3e50;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.08);
        }

        .menu-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            text-decoration: none;
            color: #2c3e50;
        }

        .menu-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }

        .menu-title {
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* フローティングボタン */
        .floating-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: var(--success-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            z-index: 999;
        }

        .floating-btn:hover {
            transform: scale(1.1);
            color: white;
            text-decoration: none;
        }

        /* レスポンシブ */
        @media (max-width: 430px) {
            .container {
                padding: 0 10px;
            }
            
            .menu-grid {
                grid-template-columns: 1fr;
            }
            
            .revenue-amount {
                font-size: 1.5rem;
            }
        }

        /* 新しい売上金確認セクション */
        .cash-check-section {
            margin-bottom: 25px;
        }

        .cash-check-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .cash-check-amount {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .cash-check-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
            transition: all 0.3s ease;
        }

        .cash-check-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <!-- システムヘッダー -->
    <div class="system-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-taxi me-2"></i>スマイリーケアタクシー</h1>
                </div>
                <div class="col-auto">
                    <div class="user-info text-end">
                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($user['NAME']) ?>
                        <a href="logout.php" class="text-white ms-3 text-decoration-none">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- 売上表示エリア -->
        <div class="revenue-cards">
            <div class="row">
                <div class="col-6">
                    <div class="revenue-card">
                        <div class="revenue-amount">¥<?= number_format($today_stats['total_revenue']) ?></div>
                        <div class="revenue-label">今日</div>
                        <div class="revenue-meta"><?= $today_stats['ride_count'] ?>回</div>
                        <?php if ($today_stats['ride_count'] > 0): ?>
                            <div class="revenue-meta">平均 ¥<?= number_format($today_stats['average_fare']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-6">
                    <div class="revenue-card">
                        <div class="revenue-amount">¥<?= number_format($month_stats['total_revenue']) ?></div>
                        <div class="revenue-label">今月</div>
                        <div class="revenue-meta"><?= $month_stats['ride_count'] ?>回</div>
                        <?php if ($month_stats['working_days'] > 0): ?>
                            <div class="revenue-meta">日平均 ¥<?= number_format($month_stats['total_revenue'] / $month_stats['working_days']) ?></div>
                        <?php endif; ?>
                        <?php if ($last_month_revenue > 0): ?>
                            <div class="comparison-badge <?= $revenue_diff >= 0 ? 'comparison-positive' : 'comparison-negative' ?>">
                                <?= $revenue_diff >= 0 ? '+' : '' ?><?= number_format($revenue_diff_rate, 1) ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 売上金確認セクション（新フロー対応） -->
        <?php if ($today_stats['total_revenue'] > 0): ?>
        <div class="cash-check-section">
            <div class="cash-check-card">
                <i class="fas fa-yen-sign fa-2x mb-3"></i>
                <div>本日の売上金確認</div>
                <div class="cash-check-amount">¥<?= number_format($today_stats['total_revenue']) ?></div>
                <a href="cash_management.php" class="cash-check-btn">
                    <i class="fas fa-calculator me-2"></i>集金管理へ
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- 業務フローエリア -->
        <div class="workflow-section">
            <div class="workflow-card morning-flow">
                <div class="workflow-header">
                    <div class="workflow-icon">
                        <i class="fas fa-sun"></i>
                    </div>
                    <h5 class="workflow-title">始業フロー</h5>
                </div>
                <div class="workflow-steps">
                    <a href="daily_inspection.php" class="step-btn">
                        <i class="fas fa-tools"></i> 1.日常点検
                    </a>
                    <a href="pre_duty_call.php" class="step-btn">
                        <i class="fas fa-clipboard-check"></i> 2.乗務前点呼
                    </a>
                    <a href="departure.php" class="step-btn">
                        <i class="fas fa-truck-pickup"></i> 3.出庫処理
                    </a>
                </div>
            </div>

            <div class="workflow-card evening-flow">
                <div class="workflow-header">
                    <div class="workflow-icon">
                        <i class="fas fa-moon"></i>
                    </div>
                    <h5 class="workflow-title">終業フロー</h5>
                </div>
                <div class="workflow-steps">
                    <a href="arrival.php" class="step-btn">
                        <i class="fas fa-truck-pickup"></i> 5.入庫処理
                    </a>
                    <a href="post_duty_call.php" class="step-btn">
                        <i class="fas fa-clipboard-check"></i> 6.乗務後点呼
                    </a>
                    <a href="cash_management.php" class="step-btn">
                        <i class="fas fa-yen-sign"></i> 7.売上金確認
                    </a>
                </div>
            </div>
        </div>

        <!-- アラート表示 -->
        <?php if (!empty($alerts)): ?>
        <div class="alerts-section">
            <?php foreach ($alerts as $alert): ?>
            <div class="alert-custom alert-<?= $alert['type'] ?>">
                <i class="fas fa-<?= $alert['icon'] ?> alert-icon"></i>
                <div>
                    <strong><?= htmlspecialchars($alert['title']) ?></strong><br>
                    <small><?= htmlspecialchars($alert['message']) ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 業務状況表示 -->
        <div class="row mb-4">
            <div class="col-6">
                <div class="menu-item">
                    <i class="fas fa-chart-bar menu-icon" style="color: #17a2b8;"></i>
                    <div class="menu-title">出庫状況</div>
                    <div style="color: #17a2b8; font-weight: 600; margin-top: 5px;">
                        <?= $operation_stats['departed_count'] ?>台
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="menu-item">
                    <i class="fas fa-home menu-icon" style="color: #28a745;"></i>
                    <div class="menu-title">入庫完了</div>
                    <div style="color: #28a745; font-weight: 600; margin-top: 5px;">
                        <?= $operation_stats['arrived_count'] ?>台
                    </div>
                </div>
            </div>
        </div>

        <!-- メインメニュー -->
        <div class="menu-section">
            <h5 class="mb-3"><i class="fas fa-th-large me-2"></i>業務メニュー</h5>
            <div class="menu-grid">
                <?php if ($user['is_driver']): ?>
                <a href="ride_records.php" class="menu-item">
                    <i class="fas fa-car menu-icon" style="color: #e74c3c;"></i>
                    <div class="menu-title">乗車記録</div>
                </a>
                <?php endif; ?>

                <?php if ($user['is_caller']): ?>
                <a href="pre_duty_call.php" class="menu-item">
                    <i class="fas fa-clipboard-check menu-icon" style="color: #3498db;"></i>
                    <div class="menu-title">乗務前点呼</div>
                </a>

                <a href="post_duty_call.php" class="menu-item">
                    <i class="fas fa-clipboard-list menu-icon" style="color: #9b59b6;"></i>
                    <div class="menu-title">乗務後点呼</div>
                </a>
                <?php endif; ?>

                <a href="daily_inspection.php" class="menu-item">
                    <i class="fas fa-tools menu-icon" style="color: #f39c12;"></i>
                    <div class="menu-title">日常点検</div>
                </a>

                <a href="departure.php" class="menu-item">
                    <i class="fas fa-truck-pickup menu-icon" style="color: #2ecc71;"></i>
                    <div class="menu-title">出庫処理</div>
                </a>

                <a href="arrival.php" class="menu-item">
                    <i class="fas fa-home menu-icon" style="color: #95a5a6;"></i>
                    <div class="menu-title">入庫処理</div>
                </a>

                <?php if ($user['permission_level'] === 'Admin'): ?>
                <a href="cash_management.php" class="menu-item">
                    <i class="fas fa-yen-sign menu-icon" style="color: #27ae60;"></i>
                    <div class="menu-title">集金管理</div>
                </a>

                <a href="user_management.php" class="menu-item">
                    <i class="fas fa-users menu-icon" style="color: #8e44ad;"></i>
                    <div class="menu-title">ユーザー管理</div>
                </a>

                <a href="vehicle_management.php" class="menu-item">
                    <i class="fas fa-car menu-icon" style="color: #16a085;"></i>
                    <div class="menu-title">車両管理</div>
                </a>

                <a href="emergency_audit_kit.php" class="menu-item">
                    <i class="fas fa-exclamation-triangle menu-icon" style="color: #e67e22;"></i>
                    <div class="menu-title">緊急監査</div>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- フローティングボタン（乗車記録へのクイックアクセス） -->
    <a href="ride_records.php" class="floating-btn" title="乗車記録">
        <i class="fas fa-car"></i>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // リアルタイム売上更新（30秒ごと）
        function updateDashboard() {
            fetch('api/dashboard_stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 売上表示更新
                        document.querySelector('.revenue-cards .col-6:first-child .revenue-amount').textContent = 
                            '¥' + data.today_revenue.toLocaleString();
                        document.querySelector('.revenue-cards .col-6:first-child .revenue-meta').textContent = 
                            data.today_count + '回';
                    }
                })
                .catch(error => console.log('Dashboard update failed:', error));
        }

        // 30秒ごとに更新
        setInterval(updateDashboard, 30000);

        // PWA風の操作性向上
        document.addEventListener('DOMContentLoaded', function() {
            // タップ可能要素のハイライト改善
            const touchElements = document.querySelectorAll('.menu-item, .step-btn, .cash-check-btn');
            touchElements.forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.style.opacity = '0.7';
                }, {passive: true});
                
                element.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                }, {passive: true});
            });

            // 売上金確認の条件表示
            const currentHour = new Date().getHours();
            const cashSection = document.querySelector('.cash-check-section');
            
            // 19時以降または売上がある場合に表示
            if (currentHour >= 19 && cashSection) {
                cashSection.style.display = 'block';
            }
        });

        // 業務フロー開始確認
        function startWorkflow(type) {
            let message = '';
            let firstStep = '';
            
            if (type === 'morning') {
                message = '始業フローを開始しますか？\n1.日常点検 → 2.乗務前点呼 → 3.出庫処理';
                firstStep = 'daily_inspection.php';
            } else if (type === 'evening') {
                message = '終業フローを開始しますか？\n5.入庫処理 → 6.乗務後点呼 → 7.売上金確認';
                firstStep = 'arrival.php';
            }
            
            if (confirm(message)) {
                sessionStorage.setItem('workflow_mode', type);
                sessionStorage.setItem('workflow_step', '1');
                location.href = firstStep + '?workflow=' + type;
            }
        }

        // アラート自動更新（5分ごと）
        function updateAlerts() {
            const currentHour = new Date().getHours();
            const alertsContainer = document.querySelector('.alerts-section');
            
            // 動的アラート生成
            if (currentHour >= 19 && !document.querySelector('[data-alert="cash-check"]')) {
                const cashAlert = document.createElement('div');
                cashAlert.className = 'alert-custom alert-info';
                cashAlert.setAttribute('data-alert', 'cash-check');
                cashAlert.innerHTML = `
                    <i class="fas fa-yen-sign alert-icon"></i>
                    <div>
                        <strong>売上金確認</strong><br>
                        <small>本日の売上金確認をお願いします</small>
                    </div>
                `;
                alertsContainer?.appendChild(cashAlert);
            }
        }

        // 5分ごとにアラート更新
        setInterval(updateAlerts, 300000);

        // ページ読み込み時にフロー状態をチェック
        window.addEventListener('load', function() {
            const workflowMode = sessionStorage.getItem('workflow_mode');
            if (workflowMode) {
                const banner = document.createElement('div');
                banner.className = 'workflow-progress-banner';
                banner.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 8px;
                    text-align: center;
                    font-size: 0.9rem;
                    z-index: 1001;
                `;
                banner.innerHTML = `
                    <i class="fas fa-route me-2"></i>
                    ${workflowMode === 'morning' ? '始業' : '終業'}フロー実行中
                    <button onclick="sessionStorage.clear(); this.parentElement.remove();" 
                            style="background: none; border: none; color: white; margin-left: 10px;">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                document.body.insertBefore(banner, document.body.firstChild);
                document.body.style.paddingTop = '40px';
            }
        });
    </script>
</body>
</html>
