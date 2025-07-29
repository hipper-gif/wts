<?php
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続
require_once 'config/database.php';

// ユーザー情報と権限を取得（これが重要！）
try {
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$user) {
        // ユーザーが見つからない場合はログアウト
        session_destroy();
        header('Location: index.php');
        exit;
    }
    
    // user_role変数を定義（これでエラー解決）
    $user_role = $user->role;
    $user_name = $user->name;
    
} catch (PDOException $e) {
    // データベースエラーの場合のデフォルト値
    $user_role = 'User';
    $user_name = 'ゲスト';
}

// 今日の日付
$today = date('Y-m-d');

// 今日の業務状況を取得
try {
    // 出庫済み車両数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM departure_records 
        WHERE departure_date = ? 
        AND id NOT IN (
            SELECT departure_record_id 
            FROM arrival_records 
            WHERE departure_record_id IS NOT NULL
        )
    ");
    $stmt->execute([$today]);
    $active_vehicles = $stmt->fetchColumn();

    // 今日の乗車回数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ");
    $stmt->execute([$today]);
    $today_rides = $stmt->fetchColumn();

    // 今日の売上
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(fare_amount), 0) as total 
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ");
    $stmt->execute([$today]);
    $today_revenue = $stmt->fetchColumn();

    // 未入庫車両
    $stmt = $pdo->prepare("
        SELECT v.vehicle_number, u.name as driver_name, d.departure_time
        FROM departure_records d
        JOIN vehicles v ON d.vehicle_id = v.id
        JOIN users u ON d.driver_id = u.id
        WHERE d.departure_date = ?
        AND d.id NOT IN (
            SELECT departure_record_id 
            FROM arrival_records 
            WHERE departure_record_id IS NOT NULL
        )
        ORDER BY d.departure_time
    ");
    $stmt->execute([$today]);
    $pending_arrivals = $stmt->fetchAll(PDO::FETCH_OBJ);

} catch (PDOException $e) {
    // エラー時のデフォルト値
    $active_vehicles = 0;
    $today_rides = 0;
    $today_revenue = 0;
    $pending_arrivals = [];
}
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
        .quick-stats .card {
            transition: transform 0.2s;
        }
        .quick-stats .card:hover {
            transform: translateY(-2px);
        }
        .alert-custom {
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-taxi"></i> 福祉輸送管理システム
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($user_name); ?>
                    <small class="text-light">(<?php echo htmlspecialchars($user_role); ?>)</small>
                </span>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- 今日の業務状況 -->
            <div class="col-12 mb-4">
                <h2><i class="fas fa-chart-line"></i> 今日の業務状況</h2>
                <div class="row quick-stats">
                    <div class="col-md-3 mb-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">稼働車両</h5>
                                        <h2><?php echo $active_vehicles; ?>台</h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-car fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">乗車回数</h5>
                                        <h2><?php echo $today_rides; ?>回</h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-users fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">今日の売上</h5>
                                        <h2>¥<?php echo number_format($today_revenue); ?></h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-yen-sign fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="card-title">未入庫</h5>
                                        <h2><?php echo count($pending_arrivals); ?>台</h2>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- メニュー -->
            <div class="col-md-8 mb-4">
                <h3><i class="fas fa-th-large"></i> 機能メニュー</h3>
                
                <!-- 基本業務 -->
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-clipboard-list"></i> 基本業務</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <a href="departure.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-sign-out-alt"></i> 出庫処理
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="ride_records.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-users"></i> 乗車記録
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="arrival.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-sign-in-alt"></i> 入庫処理
                                </a>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <a href="pre_duty_call.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-phone"></i> 乗務前点呼
                                </a>
                            </div>
                            <div class="col-md-6 mb-2">
                                <a href="post_duty_call.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-phone-slash"></i> 乗務後点呼
                                </a>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <a href="daily_inspection.php" class="btn btn-outline-warning w-100">
                                    <i class="fas fa-wrench"></i> 日常点検
                                </a>
                            </div>
                            <div class="col-md-6 mb-2">
                                <a href="periodic_inspection.php" class="btn btn-outline-warning w-100">
                                    <i class="fas fa-cogs"></i> 定期点検
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($user_role === 'Admin'): ?>
                <!-- 管理機能（管理者のみ） -->
                <div class="card mb-3">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-cog"></i> 管理機能</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <a href="cash_management.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-calculator"></i> 集金管理
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="annual_report.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-file-alt"></i> 陸運局提出
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="accident_management.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-exclamation-circle"></i> 事故管理
                                </a>
                            </div>
                        </div>
                        <?php if ($user_role === 'Admin'): ?>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <a href="user_management.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-users-cog"></i> ユーザー管理
                                </a>
                            </div>
                            <div class="col-md-6 mb-2">
                                <a href="vehicle_management.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-car-side"></i> 車両管理
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- 未入庫車両アラート -->
            <div class="col-md-4 mb-4">
                <h3><i class="fas fa-bell text-danger"></i> アラート</h3>
                <?php if (!empty($pending_arrivals)): ?>
                    <div class="alert alert-danger alert-custom">
                        <h5><i class="fas fa-exclamation-triangle"></i> 未入庫車両</h5>
                        <?php foreach ($pending_arrivals as $pending): ?>
                            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                <div>
                                    <strong><?php echo htmlspecialchars($pending->vehicle_number); ?></strong><br>
                                    <small>運転者: <?php echo htmlspecialchars($pending->driver_name); ?></small>
                                </div>
                                <small class="text-muted">
                                    出庫: <?php echo date('H:i', strtotime($pending->departure_time)); ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                        <div class="mt-2">
                            <a href="arrival.php" class="btn btn-sm btn-danger">
                                <i class="fas fa-sign-in-alt"></i> 入庫処理へ
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> 全車両入庫済み
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ページ読み込み時にアラートの自動更新（5分毎）
        setInterval(function() {
            location.reload();
        }, 300000); // 5分 = 300,000ms
    </script>
</body>
</html>
