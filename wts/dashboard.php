<?php
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ユーザー情報取得（新権限システム対応）
function getUserInfo($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name, permission_level, is_driver, is_caller, is_manager FROM users WHERE id = ? AND active = TRUE");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_OBJ);
}

// 権限レベル取得
function getUserPermissionLevel($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    return $user ? $user->permission_level : 'User';
}

// 職務フラグによるユーザー取得
function getUsersByJobFunction($pdo, $job_function) {
    if (is_array($job_function)) {
        $conditions = [];
        foreach ($job_function as $job) {
            $conditions[] = "is_{$job} = TRUE";
        }
        $where = implode(' OR ', $conditions);
    } else {
        $where = "is_{$job_function} = TRUE";
    }
    
    $stmt = $pdo->prepare("
        SELECT id, name FROM users 
        WHERE active = TRUE AND ({$where})
        ORDER BY name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

$user_info = getUserInfo($pdo, $_SESSION['user_id']);
$permission_level = getUserPermissionLevel($pdo, $_SESSION['user_id']);

if (!$user_info) {
    session_destroy();
    header('Location: index.php?error=invalid_user');
    exit;
}

// 今日の日付
$today = date('Y-m-d');

// 今日の業務状況取得
function getTodayStats($pdo, $today) {
    // 稼働車両数（今日出庫済み未入庫）
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT d.vehicle_id) as active_vehicles
        FROM departure_records d 
        LEFT JOIN arrival_records a ON d.id = a.departure_record_id
        WHERE d.departure_date = ? AND a.id IS NULL
    ");
    $stmt->execute([$today]);
    $active_vehicles = $stmt->fetchColumn() ?: 0;

    // 今日の乗車回数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as ride_count
        FROM ride_records 
        WHERE ride_date = ?
    ");
    $stmt->execute([$today]);
    $ride_count = $stmt->fetchColumn() ?: 0;

    // 今日の売上（修正版 - リアルタイム反映）
    $stmt = $pdo->prepare("
        SELECT 
            SUM(fare_amount) as total_sales,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash_sales,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card_sales,
            COUNT(*) as total_rides,
            SUM(passenger_count) as total_passengers
        FROM ride_records 
        WHERE ride_date = ?
    ");
    $stmt->execute([$today]);
    $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);

    // 未入庫車両リスト
    $stmt = $pdo->prepare("
        SELECT d.vehicle_id, v.vehicle_number, u.name as driver_name, d.departure_time
        FROM departure_records d
        JOIN vehicles v ON d.vehicle_id = v.id
        JOIN users u ON d.driver_id = u.id
        LEFT JOIN arrival_records a ON d.id = a.departure_record_id
        WHERE d.departure_date = ? AND a.id IS NULL
        ORDER BY d.departure_time
    ");
    $stmt->execute([$today]);
    $not_returned_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'active_vehicles' => $active_vehicles,
        'ride_count' => $ride_count,
        'total_sales' => $sales_data['total_sales'] ?: 0,
        'cash_sales' => $sales_data['cash_sales'] ?: 0,
        'card_sales' => $sales_data['card_sales'] ?: 0,
        'total_rides' => $sales_data['total_rides'] ?: 0,
        'total_passengers' => $sales_data['total_passengers'] ?: 0,
        'not_returned_vehicles' => $not_returned_vehicles
    ];
}

// 月間実績取得
function getMonthlyStats($pdo) {
    $current_month = date('Y-m');
    
    $stmt = $pdo->prepare("
        SELECT 
            SUM(fare_amount) as monthly_sales,
            COUNT(*) as monthly_rides,
            SUM(passenger_count) as monthly_passengers,
            AVG(fare_amount) as avg_fare
        FROM ride_records 
        WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
    ");
    $stmt->execute([$current_month]);
    $monthly_data = $stmt->fetch(PDO::FETCH_ASSOC);

    // 月間走行距離
    $stmt = $pdo->prepare("
        SELECT SUM(total_distance) as monthly_distance
        FROM arrival_records 
        WHERE DATE_FORMAT(arrival_date, '%Y-%m') = ?
    ");
    $stmt->execute([$current_month]);
    $monthly_distance = $stmt->fetchColumn() ?: 0;

    return [
        'monthly_sales' => $monthly_data['monthly_sales'] ?: 0,
        'monthly_rides' => $monthly_data['monthly_rides'] ?: 0,
        'monthly_passengers' => $monthly_data['monthly_passengers'] ?: 0,
        'monthly_distance' => $monthly_distance,
        'avg_fare' => $monthly_data['avg_fare'] ?: 0
    ];
}

// アラート情報取得
function getAlerts($pdo) {
    $alerts = [];
    
    // 点検期限アラート（1週間以内）
    $stmt = $pdo->prepare("
        SELECT vehicle_number, next_inspection_date
        FROM vehicles 
        WHERE next_inspection_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND next_inspection_date >= CURDATE()
    ");
    $stmt->execute();
    $inspection_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($inspection_alerts) {
        foreach ($inspection_alerts as $alert) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "車両 {$alert['vehicle_number']} の定期点検期限が近づいています ({$alert['next_inspection_date']})"
            ];
        }
    }

    // 未入庫車両アラート（18時以降）
    if (date('H') >= 18) {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM departure_records d
            LEFT JOIN arrival_records a ON d.id = a.departure_record_id
            WHERE d.departure_date = ? AND a.id IS NULL
        ");
        $stmt->execute([$today]);
        $not_returned_count = $stmt->fetchColumn();
        
        if ($not_returned_count > 0) {
            $alerts[] = [
                'type' => 'danger',
                'message' => "{$not_returned_count}台の車両が未入庫です"
            ];
        }
    }

    return $alerts;
}

$today_stats = getTodayStats($pdo, $today);
$monthly_stats = getMonthlyStats($pdo);
$alerts = getAlerts($pdo);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-card.sales {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .stat-card.rides {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        }
        .stat-card.vehicles {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }
        .menu-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            height: 100%;
        }
        .menu-card:hover {
            transform: translateY(-5px);
        }
        .alert-custom {
            border-radius: 10px;
            border: none;
        }
        .not-returned-vehicle {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="#"><i class="fas fa-car me-2"></i>福祉輸送管理システム</a>
        <div class="navbar-nav ms-auto">
            <span class="navbar-text me-3">
                <i class="fas fa-user me-1"></i><?= htmlspecialchars($user_info->name) ?>
                <span class="badge bg-<?= $permission_level === 'Admin' ? 'danger' : 'info' ?> ms-1">
                    <?= $permission_level === 'Admin' ? '管理者' : 'ユーザー' ?>
                </span>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sign-out-alt me-1"></i>ログアウト
            </a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- アラート表示 -->
    <?php if (!empty($alerts)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <?php foreach ($alerts as $alert): ?>
                    <div class="alert alert-<?= $alert['type'] ?> alert-custom">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($alert['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- 今日の業務状況 -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-chart-line me-2"></i>今日の業務状況 (<?= date('Y年m月d日') ?>)</h2>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="stat-card sales">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3>¥<?= number_format($today_stats['total_sales']) ?></h3>
                        <p class="mb-0">今日の売上</p>
                        <small>現金: ¥<?= number_format($today_stats['cash_sales']) ?> | カード: ¥<?= number_format($today_stats['card_sales']) ?></small>
                    </div>
                    <i class="fas fa-yen-sign fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card rides">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= $today_stats['total_rides'] ?>回</h3>
                        <p class="mb-0">乗車回数</p>
                        <small><?= $today_stats['total_passengers'] ?>名様を輸送</small>
                    </div>
                    <i class="fas fa-users fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card vehicles">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= $today_stats['active_vehicles'] ?>台</h3>
                        <p class="mb-0">稼働車両</p>
                        <small>現在運行中</small>
                    </div>
                    <i class="fas fa-car fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= count($today_stats['not_returned_vehicles']) ?>台</h3>
                        <p class="mb-0">未入庫車両</p>
                        <small>要確認</small>
                    </div>
                    <i class="fas fa-exclamation-triangle fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- 未入庫車両詳細 -->
    <?php if (!empty($today_stats['not_returned_vehicles'])): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>未入庫車両一覧</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($today_stats['not_returned_vehicles'] as $vehicle): ?>
                            <div class="not-returned-vehicle">
                                <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong>
                                - 運転者: <?= htmlspecialchars($vehicle['driver_name']) ?>
                                - 出庫時刻: <?= htmlspecialchars($vehicle['departure_time']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 月間実績 -->
    <div class="row mb-4">
        <div class="col-12">
            <h3><i class="fas fa-calendar-alt me-2"></i>今月の実績 (<?= date('Y年m月') ?>)</h3>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-success">¥<?= number_format($monthly_stats['monthly_sales']) ?></h4>
                    <p class="card-text">月間売上</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-primary"><?= $monthly_stats['monthly_rides'] ?>回</h4>
                    <p class="card-text">月間乗車回数</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-info"><?= number_format($monthly_stats['monthly_distance']) ?>km</h4>
                    <p class="card-text">月間走行距離</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-warning">¥<?= number_format($monthly_stats['avg_fare']) ?></h4>
                    <p class="card-text">平均売上</p>
                </div>
            </div>
        </div>
    </div>

    <!-- メニュー -->
    <div class="row mb-4">
        <div class="col-12">
            <h3><i class="fas fa-th-large me-2"></i>機能メニュー</h3>
        </div>
    </div>

    <?php if ($permission_level === 'Admin'): ?>
        <!-- 管理者メニュー：全機能 -->
        <div class="row">
            <!-- 基本業務 -->
            <div class="col-md-4 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-clipboard-list me-2"></i>基本業務</h5>
                    </div>
                    <div class="card-body">
                        <a href="departure.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-out-alt me-1"></i>出庫処理
                        </a>
                        <a href="arrival.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-in-alt me-1"></i>入庫処理
                        </a>
                        <a href="ride_records.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-users me-1"></i>乗車記録
                        </a>
                        <a href="pre_duty_call.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-phone me-1"></i>乗務前点呼
                        </a>
                        <a href="post_duty_call.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-phone-alt me-1"></i>乗務後点呼
                        </a>
                        <a href="daily_inspection.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-tools me-1"></i>日常点検
                        </a>
                        <a href="periodic_inspection.php" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-cog me-1"></i>定期点検
                        </a>
                    </div>
                </div>
            </div>

            <!-- 管理業務 -->
            <div class="col-md-4 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-chart-bar me-2"></i>管理業務</h5>
                    </div>
                    <div class="card-body">
                        <a href="cash_management.php" class="btn btn-outline-success btn-sm mb-2 w-100">
                            <i class="fas fa-coins me-1"></i>集金管理
                        </a>
                        <a href="accident_management.php" class="btn btn-outline-success btn-sm mb-2 w-100">
                            <i class="fas fa-exclamation-circle me-1"></i>事故管理
                        </a>
                        <a href="#" class="btn btn-outline-success btn-sm w-100" onclick="showStats()">
                            <i class="fas fa-chart-line me-1"></i>売上統計
                        </a>
                    </div>
                </div>
            </div>

            <!-- システム管理 -->
            <div class="col-md-4 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-danger text-white">
                        <h5><i class="fas fa-cogs me-2"></i>システム管理</h5>
                    </div>
                    <div class="card-body">
                        <a href="user_management.php" class="btn btn-outline-danger btn-sm mb-2 w-100">
                            <i class="fas fa-users-cog me-1"></i>ユーザー管理
                        </a>
                        <a href="vehicle_management.php" class="btn btn-outline-danger btn-sm mb-2 w-100">
                            <i class="fas fa-car me-1"></i>車両管理
                        </a>
                        <a href="annual_report.php" class="btn btn-outline-danger btn-sm mb-2 w-100">
                            <i class="fas fa-file-alt me-1"></i>陸運局提出
                        </a>
                        <a href="emergency_audit_kit.php" class="btn btn-outline-danger btn-sm w-100">
                            <i class="fas fa-shield-alt me-1"></i>緊急監査対応
                        </a>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- 一般ユーザーメニュー：基本業務のみ -->
        <div class="row">
            <!-- 基本業務 -->
            <div class="col-md-6 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-clipboard-list me-2"></i>基本業務</h5>
                    </div>
                    <div class="card-body">
                        <a href="departure.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-out-alt me-1"></i>出庫処理
                        </a>
                        <a href="arrival.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-in-alt me-1"></i>入庫処理
                        </a>
                        <a href="ride_records.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-users me-1"></i>乗車記録
                        </a>
                        <a href="pre_duty_call.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-phone me-1"></i>乗務前点呼
                        </a>
                        <a href="post_duty_call.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-phone-alt me-1"></i>乗務後点呼
                        </a>
                        <a href="daily_inspection.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-tools me-1"></i>日常点検
                        </a>
                        <a href="periodic_inspection.php" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-cog me-1"></i>定期点検
                        </a>
                    </div>
                </div>
            </div>

            <!-- 実績・統計 -->
            <div class="col-md-6 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-chart-bar me-2"></i>実績・統計</h5>
                    </div>
                    <div class="card-body">
                        <a href="#" class="btn btn-outline-info btn-sm mb-2 w-100" onclick="showStats()">
                            <i class="fas fa-chart-line me-1"></i>売上統計
                        </a>
                        <a href="#" class="btn btn-outline-info btn-sm mb-2 w-100" onclick="showOperationStats()">
                            <i class="fas fa-road me-1"></i>運行実績
                        </a>
                        <a href="#" class="btn btn-outline-info btn-sm w-100" onclick="showReports()">
                            <i class="fas fa-file-alt me-1"></i>各種レポート
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- 統計表示モーダル -->
<div class="modal fade" id="statsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">売上統計</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="statsModalBody">
                <!-- 統計データが動的に読み込まれます -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showStats() {
    // 売上統計表示
    document.getElementById('statsModalBody').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <canvas id="salesChart"></canvas>
            </div>
            <div class="col-md-6">
                <h6>今日の詳細</h6>
                <p>総売上: ¥${<?= $today_stats['total_sales'] ?>}</p>
                <p>現金: ¥${<?= $today_stats['cash_sales'] ?>}</p>
                <p>カード: ¥${<?= $today_stats['card_sales'] ?>}</p>
                <p>乗車回数: ${<?= $today_stats['total_rides'] ?>}回</p>
                <p>乗客数: ${<?= $today_stats['total_passengers'] ?>}名</p>
            </div>
        </div>
    `;
    new bootstrap.Modal(document.getElementById('statsModal')).show();
}

function showOperationStats() {
    alert('運行実績画面を表示します（実装予定）');
}

function showReports() {
    alert('各種レポート画面を表示します（実装予定）');
}

// リアルタイム更新（5分ごと）
setInterval(function() {
    location.reload();
}, 300000);
</script>

</body>
</html>
