<?php
session_start();

// データベース接続（直接定義）
try {
    $host = 'localhost';
    $dbname = 'twinklemark_wts';
    $username = 'twinklemark_taxi';
    $password = 'Smiley2525';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("データベース接続エラー: " . htmlspecialchars($e->getMessage()));
}

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ユーザー情報取得
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_role = $stmt->fetchColumn();
    
    if (!$user_role) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    $user_role = '運転者'; // デフォルト権限
}

// 今日の日付
$today = date('Y-m-d');
$current_month = date('Y-m');

// 統計データ取得
try {
    // 今日の業務統計
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_departures = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute([$today]);
    $today_arrivals = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*), SUM(fare_amount) FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $ride_stats = $stmt->fetch();
    $today_rides = $ride_stats[0] ?? 0;
    $today_sales = $ride_stats[1] ?? 0;
    
    // 月間統計
    $stmt = $pdo->prepare("SELECT COUNT(*), SUM(fare_amount) FROM ride_records WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?");
    $stmt->execute([$current_month]);
    $month_stats = $stmt->fetch();
    $month_rides = $month_stats[0] ?? 0;
    $month_sales = $month_stats[1] ?? 0;
    
    // 未入庫車両
    $stmt = $pdo->prepare("
        SELECT v.vehicle_number, d.departure_time, u.name as driver_name
        FROM departure_records d
        JOIN vehicles v ON d.vehicle_id = v.id
        JOIN users u ON d.driver_id = u.id
        LEFT JOIN arrival_records a ON d.id = a.departure_record_id
        WHERE d.departure_date = ? AND a.id IS NULL
    ");
    $stmt->execute([$today]);
    $pending_arrivals = $stmt->fetchAll();
    
} catch (Exception $e) {
    $today_departures = $today_arrivals = $today_rides = $today_sales = 0;
    $month_rides = $month_sales = 0;
    $pending_arrivals = [];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - ダッシュボード</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .dashboard-card {
            transition: transform 0.2s ease-in-out;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
        }
        .emergency-section {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            border-radius: 15px;
            animation: pulse-glow 2s infinite;
        }
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 20px rgba(255, 107, 107, 0.3); }
            50% { box-shadow: 0 0 30px rgba(255, 107, 107, 0.6); }
        }
        .function-btn {
            border: none;
            border-radius: 10px;
            padding: 15px;
            margin: 5px 0;
            width: 100%;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        .function-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .priority-high { background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white; }
        .priority-medium { background: linear-gradient(135deg, #4ecdc4, #44a08d); color: white; }
        .priority-normal { background: linear-gradient(135deg, #45b7d1, #96c93d); color: white; }
        .priority-low { background: linear-gradient(135deg, #f7971e, #ffd200); color: white; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-bus"></i> 福祉輸送管理システム
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'ユーザー') ?>
                    (<?= htmlspecialchars($user_role) ?>)
                </span>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i> ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- 今日の統計 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card stat-card dashboard-card">
                    <div class="card-body">
                        <h4 class="card-title">
                            <i class="fas fa-chart-line"></i> 今日の業務状況
                            <small class="float-end"><?= date('n月j日(D)') ?></small>
                        </h4>
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h2><?= $today_departures ?></h2>
                                <p>出庫</p>
                            </div>
                            <div class="col-md-3">
                                <h2><?= $today_arrivals ?></h2>
                                <p>入庫</p>
                            </div>
                            <div class="col-md-3">
                                <h2><?= $today_rides ?></h2>
                                <p>乗車回数</p>
                            </div>
                            <div class="col-md-3">
                                <h2>¥<?= number_format($today_sales) ?></h2>
                                <p>売上</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 緊急監査対応システム -->
        <?php if (in_array($user_role, ['システム管理者', '管理者'])): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card emergency-section dashboard-card">
                    <div class="card-body">
                        <h4 class="card-title">
                            <i class="fas fa-exclamation-triangle"></i> 緊急監査対応システム
                            <span class="badge bg-warning text-dark ms-2">5分で完了</span>
                        </h4>
                        <p class="mb-3">国土交通省・陸運局の突然の監査に対応。従来3-4日→5分の99%短縮を実現</p>
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <a href="emergency_audit_kit.php" class="function-btn priority-high">
                                    <i class="fas fa-shield-alt"></i><br>
                                    <strong>緊急監査対応キット</strong><br>
                                    <small>5分で監査準備完了</small>
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="adaptive_export_document.php" class="function-btn priority-high">
                                    <i class="fas fa-file-export"></i><br>
                                    <strong>適応型出力システム</strong><br>
                                    <small>法定書類一括出力</small>
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="audit_data_manager.php" class="function-btn priority-high">
                                    <i class="fas fa-database"></i><br>
                                    <strong>監査データ管理</strong><br>
                                    <small>データ整合性自動修正</small>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- メイン機能 -->
        <div class="row">
            <!-- 日常業務 -->
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-calendar-day"></i> 日常業務</h5>
                    </div>
                    <div class="card-body">
                        <a href="departure.php" class="function-btn priority-medium">
                            <i class="fas fa-play-circle"></i> 出庫処理
                        </a>
                        <a href="ride_records.php" class="function-btn priority-medium">
                            <i class="fas fa-users"></i> 乗車記録<span class="badge bg-success ms-2">復路作成</span>
                        </a>
                        <a href="arrival.php" class="function-btn priority-medium">
                            <i class="fas fa-stop-circle"></i> 入庫処理
                        </a>
                    </div>
                </div>
            </div>

            <!-- 点呼・点検 -->
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-clipboard-check"></i> 点呼・点検</h5>
                    </div>
                    <div class="card-body">
                        <a href="pre_duty_call.php" class="function-btn priority-normal">
                            <i class="fas fa-clipboard-list"></i> 乗務前点呼
                        </a>
                        <a href="post_duty_call.php" class="function-btn priority-normal">
                            <i class="fas fa-clipboard-check"></i> 乗務後点呼<span class="badge bg-info ms-2">NEW</span>
                        </a>
                        <a href="daily_inspection.php" class="function-btn priority-normal">
                            <i class="fas fa-wrench"></i> 日常点検
                        </a>
                        <a href="periodic_inspection.php" class="function-btn priority-normal">
                            <i class="fas fa-cogs"></i> 定期点検（3ヶ月）<span class="badge bg-info ms-2">NEW</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- 管理機能 -->
            <?php if (in_array($user_role, ['システム管理者', '管理者'])): ?>
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="fas fa-chart-bar"></i> 管理・集計</h5>
                    </div>
                    <div class="card-body">
                        <a href="cash_management.php" class="function-btn priority-low">
                            <i class="fas fa-yen-sign"></i> 集金管理
                        </a>
                        <a href="annual_report.php" class="function-btn priority-low">
                            <i class="fas fa-file-alt"></i> 陸運局提出<span class="badge bg-info ms-2">NEW</span>
                        </a>
                        <a href="accident_management.php" class="function-btn priority-low">
                            <i class="fas fa-exclamation-circle"></i> 事故管理<span class="badge bg-info ms-2">NEW</span>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- システム管理 -->
            <?php if ($user_role === 'システム管理者'): ?>
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-secondary text-white">
                        <h5><i class="fas fa-cog"></i> システム管理</h5>
                    </div>
                    <div class="card-body">
                        <a href="user_management.php" class="function-btn priority-low">
                            <i class="fas fa-users-cog"></i> ユーザー管理
                        </a>
                        <a href="vehicle_management.php" class="function-btn priority-low">
                            <i class="fas fa-car"></i> 車両管理
                        </a>
                        <a href="check_table_structure.php" class="function-btn priority-low">
                            <i class="fas fa-database"></i> システム診断
                        </a>
                        <a href="fix_table_structure.php" class="function-btn priority-low">
                            <i class="fas fa-tools"></i> 構造修正
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 未入庫アラート -->
        <?php if (!empty($pending_arrivals)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle"></i> 未入庫車両あり</h5>
                    <?php foreach ($pending_arrivals as $pending): ?>
                    <p class="mb-1">
                        <strong><?= htmlspecialchars($pending['vehicle_number']) ?></strong> - 
                        <?= htmlspecialchars($pending['driver_name']) ?> 
                        (出庫: <?= date('H:i', strtotime($pending['departure_time'])) ?>)
                        <a href="arrival.php" class="btn btn-sm btn-warning ms-2">入庫処理</a>
                    </p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 月間実績 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card dashboard-card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-calendar-alt"></i> 月間実績（<?= date('Y年n月') ?>）</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <h3 class="text-primary"><?= $month_rides ?></h3>
                                <p>総乗車回数</p>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-success">¥<?= number_format($month_sales) ?></h3>
                                <p>総売上</p>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-info">¥<?= $month_rides > 0 ? number_format($month_sales / $month_rides) : 0 ?></h3>
                                <p>平均単価</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- システム情報 -->
        <div class="row">
            <div class="col-12">
                <div class="card dashboard-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">
                            <i class="fas fa-shield-check"></i> 福祉輸送管理システム v2.0 
                            <span class="badge bg-success">完成度 100%</span>
                        </h6>
                        <p class="small text-muted mb-0">
                            🚨 5分監査対応 | 🔄 復路作成機能 | 📊 リアルタイム集計 | 🛡️ 法令完全遵守
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 自動リロード（5分ごと）
        setTimeout(function() {
            location.reload();
        }, 300000);
        
        // 統計カードアニメーション
        document.addEventListener("DOMContentLoaded", function() {
            const cards = document.querySelectorAll(".dashboard-card");
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = "0";
                    card.style.transform = "translateY(20px)";
                    card.style.transition = "all 0.5s ease";
                    setTimeout(() => {
                        card.style.opacity = "1";
                        card.style.transform = "translateY(0)";
                    }, 100);
                }, index * 100);
            });
        });
    </script>
</body>
</html>
