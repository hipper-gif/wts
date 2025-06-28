<?php
session_start();

// データベース接続
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

// 今日の日付
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';

// POSTデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'add';
        
        if ($action === 'add') {
            // 新規追加
            $driver_id = $_POST['driver_id'];
            $vehicle_id = $_POST['vehicle_id'];
            $ride_date = $_POST['ride_date'];
            $ride_time = $_POST['ride_time'];
            $passenger_count = $_POST['passenger_count'];
            $pickup_location = $_POST['pickup_location'];
            $dropoff_location = $_POST['dropoff_location'];
            $fare = $_POST['fare'];
            $charge = $_POST['charge'] ?? 0;
            $transport_category = $_POST['transport_category'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            $is_return_trip = isset($_POST['is_return_trip']) ? 1 : 0;
            $original_ride_id = $_POST['original_ride_id'] ?? null;
            
            $insert_sql = "INSERT INTO ride_records 
                (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
                 pickup_location, dropoff_location, fare, charge, transport_category, 
                 payment_method, notes, is_return_trip, original_ride_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $driver_id, $vehicle_id, $ride_date, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare, $charge, $transport_category,
                $payment_method, $notes, $is_return_trip, $original_ride_id
            ]);
            
            if ($is_return_trip) {
                $success_message = '復路の乗車記録を登録しました。';
            } else {
                $success_message = '乗車記録を登録しました。';
            }
            
        } elseif ($action === 'edit') {
            // 編集
            $record_id = $_POST['record_id'];
            $ride_time = $_POST['ride_time'];
            $passenger_count = $_POST['passenger_count'];
            $pickup_location = $_POST['pickup_location'];
            $dropoff_location = $_POST['dropoff_location'];
            $fare = $_POST['fare'];
            $charge = $_POST['charge'] ?? 0;
            $transport_category = $_POST['transport_category'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            
            $update_sql = "UPDATE ride_records SET 
                ride_time = ?, passenger_count = ?, pickup_location = ?, dropoff_location = ?, 
                fare = ?, charge = ?, transport_category = ?, payment_method = ?, 
                notes = ?, updated_at = NOW() 
                WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $ride_time, $passenger_count, $pickup_location, $dropoff_location,
                $fare, $charge, $transport_category, $payment_method, $notes, $record_id
            ]);
            
            $success_message = '乗車記録を更新しました。';
            
        } elseif ($action === 'delete') {
            // 削除
            $record_id = $_POST['record_id'];
            
            $delete_sql = "DELETE FROM ride_records WHERE id = ?";
            $delete_stmt = $pdo->prepare($delete_sql);
            $delete_stmt->execute([$record_id]);
            
            $success_message = '乗車記録を削除しました。';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者一覧取得
$drivers_sql = "SELECT id, name FROM users WHERE role IN ('driver', 'admin') ORDER BY name";
$drivers_stmt = $pdo->prepare($drivers_sql);
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// 車両一覧取得
$vehicles_sql = "SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE status = 'active' ORDER BY vehicle_number";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute();
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

// 検索条件
$search_date = $_GET['search_date'] ?? $today;
$search_driver = $_GET['search_driver'] ?? '';
$search_vehicle = $_GET['search_vehicle'] ?? '';

// 乗車記録一覧取得
$where_conditions = ["r.ride_date = ?"];
$params = [$search_date];

if ($search_driver) {
    $where_conditions[] = "r.driver_id = ?";
    $params[] = $search_driver;
}

if ($search_vehicle) {
    $where_conditions[] = "r.vehicle_id = ?";
    $params[] = $search_vehicle;
}

$rides_sql = "SELECT r.*, u.name as driver_name, v.vehicle_number, v.vehicle_name,
    (r.fare + r.charge) as total_amount,
    CASE WHEN r.is_return_trip = 1 THEN '復路' ELSE '往路' END as trip_type
    FROM ride_records r 
    JOIN users u ON r.driver_id = u.id 
    JOIN vehicles v ON r.vehicle_id = v.id 
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY r.ride_time DESC";
$rides_stmt = $pdo->prepare($rides_sql);
$rides_stmt->execute($params);
$rides = $rides_stmt->fetchAll(PDO::FETCH_ASSOC);

// 日次集計
$summary_sql = "SELECT 
    COUNT(*) as total_rides,
    SUM(r.passenger_count) as total_passengers,
    SUM(r.fare + r.charge) as total_revenue,
    AVG(r.fare + r.charge) as avg_fare,
    COUNT(CASE WHEN r.payment_method = '現金' THEN 1 END) as cash_count,
    COUNT(CASE WHEN r.payment_method = 'カード' THEN 1 END) as card_count,
    SUM(CASE WHEN r.payment_method = '現金' THEN r.fare + r.charge ELSE 0 END) as cash_total,
    SUM(CASE WHEN r.payment_method = 'カード' THEN r.fare + r.charge ELSE 0 END) as card_total
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions);
$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// 輸送分類別集計
$category_sql = "SELECT 
    r.transport_category,
    COUNT(*) as count,
    SUM(r.passenger_count) as passengers,
    SUM(r.fare + r.charge) as revenue
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY r.transport_category 
    ORDER BY count DESC";
$category_stmt = $pdo->prepare($category_sql);
$category_stmt->execute($params);
$categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

// 輸送分類・支払方法の選択肢
$transport_categories = ['通院', '外出等', '退院', '転院', '施設入所', 'その他'];
$payment_methods = ['現金', 'カード', 'その他'];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗車記録管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Hiragino Kaku Gothic Pro', 'ヒラギノ角ゴ Pro', 'Yu Gothic Medium', '游ゴシック Medium', YuGothic, '游ゴシック体', 'Meiryo', sans-serif;
        }
        .navbar-brand {
            font-weight: bold;
            color: #2c3e50 !important;
        }
        .card {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: none;
            border-radius: 10px;
        }
        .card-header {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
            transform: translateY(-1px);
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 20px;
        }
        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%);
            border: none;
            border-radius: 20px;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
            padding: 8px 12px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        .ride-record {
            background: white;
            padding: 15px;
            margin: 8px 0;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        .ride-record:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .return-trip {
            border-left-color: #28a745;
            background: #f8fff9;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .summary-value {
            font-size: 1.8em;
            font-weight: bold;
            display: block;
        }
        .summary-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        .location-swap {
            background: #fff3cd;
            padding: 8px;
            border-radius: 6px;
            margin: 10px 0;
        }
        .amount-display {
            font-size: 1.1em;
            font-weight: bold;
            color: #28a745;
        }
        .trip-type-badge {
            font-size: 0.8em;
            padding: 2px 8px;
            border-radius: 12px;
        }
        .search-form {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- ナビゲーションバー -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-taxi me-2"></i>福祉輸送管理システム
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt me-1"></i>ダッシュボード</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="departure.php"><i class="fas fa-sign-out-alt me-1"></i>出庫処理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="ride_records.php"><i class="fas fa-users me-1"></i>乗車記録</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="arrival.php"><i class="fas fa-sign-in-alt me-1"></i>入庫処理</a>
                    </li>
                </ul>
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($user_name); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- 検索フォーム -->
        <div class="search-form">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="search_date" class="form-label">日付</label>
                    <input type="date" class="form-control" id="search_date" name="search_date" 
                           value="<?php echo htmlspecialchars($search_date); ?>">
                </div>
                <div class="col-md-3">
                    <label for="search_driver" class="form-label">運転者</label>
                    <select class="form-select" id="search_driver" name="search_driver">
                        <option value="">全て</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?php echo $driver['id']; ?>" 
                                <?php echo ($search_driver == $driver['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($driver['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="search_vehicle" class="form-label">車両</label>
                    <select class="form-select" id="search_vehicle" name="search_vehicle">
                        <option value="">全て</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo $vehicle['id']; ?>" 
                                <?php echo ($search_vehicle == $vehicle['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i>検索
                    </button>
                    <button type="button" class="btn btn-success" onclick="showAddModal()">
                        <i class="fas fa-plus me-1"></i>新規登録
                    </button>
                </div>
            </form>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- 乗車記録一覧 -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-list me-2"></i>乗車記録一覧
                            <small class="ms-2">(<?php echo htmlspecialchars($search_date); ?>)</small>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (empty($rides)): ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-info-circle me-2"></i>
                                該当する乗車記録がありません。
                            </p>
                        <?php else: ?>
                            <?php foreach ($rides as $ride): ?>
                                <div class="ride-record <?php echo $ride['is_return_trip'] ? 'return-trip' : ''; ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <strong class="me-2"><?php echo substr($ride['ride_time'], 0, 5); ?></strong>
                                                <span class="badge trip-type-badge <?php echo $ride['is_return_trip'] ? 'bg-success' : 'bg-primary'; ?>">
                                                    <?php echo $ride['trip_type']; ?>
                                                </span>
                                                <small class="text-muted ms-2">
                                                    <?php echo htmlspecialchars($ride['driver_name']); ?> / <?php echo htmlspecialchars($ride['vehicle_number']); ?>
                                                </small>
                                            </div>
                                            <div class="mb-1">
                                                <i class="fas fa-map-marker-alt text-success me-1"></i>
                                                <strong><?php echo htmlspecialchars($ride['pickup_location']); ?></strong>
                                                <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                                <strong><?php echo htmlspecialchars($ride['dropoff_location']); ?></strong>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $ride['passenger_count']; ?>名 / <?php echo htmlspecialchars($ride['transport_category']); ?> / <?php echo htmlspecialchars($ride['payment_method']); ?>
                                                <?php if ($ride['notes']): ?>
                                                    <br><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($ride['notes']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="amount-display mb-2">
                                                ¥<?php echo number_format($ride['total_amount']); ?>
                                            </div>
                                            <div class="btn-group" role="group">
                                                <?php if (!$ride['is_return_trip']): ?>
                                                    <button type="button" class="btn btn-success btn-sm" 
                                                            onclick="createReturnTrip(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                            title="復路作成">
                                                        <i class="fas fa-route"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-warning btn-sm" 
                                                        onclick="editRecord(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                        title="編集">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" 
                                                        onclick="deleteRecord(<?php echo $ride['id']; ?>)"
                                                        title="削除">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- サイドバー（集計情報） -->
            <div class="col-lg-4">
                <!-- 日次集計 -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>日次集計</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6">
                                <div class="summary-card bg-primary">
                                    <span class="summary-value"><?php echo $summary['total_rides'] ?? 0; ?></span>
                                    <span class="summary-label">総回数</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card bg-success">
                                    <span class="summary-value"><?php echo $summary['total_passengers'] ?? 0; ?></span>
                                    <span class="summary-label">総人数</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="summary-card bg-warning">
                                    <span class="summary-value">¥<?php echo number_format($summary['total_revenue'] ?? 0); ?></span>
                                    <span class="summary-label">売上合計</span>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <strong>現金</strong><br>
                                <span><?php echo $summary['cash_count'] ?? 0; ?>回</span><br>
                                <span class="text-success">¥<?php echo number_format($summary['cash_total'] ?? 0); ?></span>
                            </div>
                            <div class="col-6">
                                <strong>カード</strong><br>
                                <span><?php echo $summary['card_count'] ?? 0; ?>回</span><br>
                                <span class="text-info">¥<?php echo number_format($summary['card_total'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 輸送分類別集計 -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-pie-chart me-2"></i>輸送分類別</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categories)): ?>
                            <p class="text-muted">データがありません</p>
                        <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($category['transport_category']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo $category['count']; ?>回 / <?php echo $category['passengers']; ?>名</small>
                                    </div>
                                    <div class="text-end">
                                        <strong>¥<?php echo number_format($category['revenue']); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 乗車記録入力・編集モーダル -->
    <div class="modal fade" id="rideModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rideModalTitle">
                        <i class="fas fa-plus me-2"></i>乗車記録登録
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="rideForm" method="POST">
                    <input type="hidden" name="action" id="modalAction" value="add">
                    <input type="hidden" name="record_id" id="modalRecordId">
                    <input type="hidden" name="is_return_trip" id="modalIsReturnTrip" value="0">
                    <input type="hidden" name="original_ride_id" id="modalOriginalRideId">
                    
                    <div class="modal-body">
                        <!-- 復路情報表示 -->
                        <div id="returnTripInfo" class="location-swap" style="display: none;">
                            <h6><i class="fas fa-route me-2"></i>復路作成</h6>
                            <p class="mb-0">乗車地と降車地を入れ替えて復路を作成します。</p>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalDriverId" class="form-label">
                                    <i class="fas fa-user me-1"></i>運転者 <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="modalDriverId" name="driver_id" required>
                                    <option value="">運転者を選択</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?php echo $driver['id']; ?>" 
                                            <?php echo ($user_role === 'driver' && $driver['id'] == $user_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($driver['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalVehicleId" class="form-label">
                                    <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="modalVehicleId" name="vehicle_id" required>
                                    <option value="">車両を選択</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?php echo $vehicle['id']; ?>">
                                            <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalRideDate" class="form-label">
                                    <i class="fas fa-calendar me-1"></i>乗車日 <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control" id="modalRideDate" name="ride_date" 
                                       value="<?php echo $today; ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalRideTime" class="form-label">
                                    <i class="fas fa-clock me-1"></i>乗車時刻 <span class="text-danger">*</span>
                                </label>
                                <input type="time" class="form-control" id="modalRideTime" name="ride_time" 
                                       value="<?php echo $current_time; ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="modalPassengerCount" class="form-label">
                                <i class="fas fa-users me-1"></i>人員数 <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control" id="modalPassengerCount" name="passenger_count" 
                                   value="1" min="1" max="10" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalPickupLocation" class="form-label">
                                    <i class="fas fa-map-marker-alt text-success me-1"></i>乗車地 <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="modalPickupLocation" name="pickup_location" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalDropoffLocation" class="form-label">
                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>降車地 <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="modalDropoffLocation" name="dropoff_location" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalFare" class="form-label">
                                    <i class="fas fa-yen-sign me-1"></i>運賃 <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control" id="modalFare" name="fare" min="0" step="10" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalCharge" class="form-label">
                                    <i class="fas fa-plus me-1"></i>料金
                                </label>
                                <input type="number" class="form-control" id="modalCharge" name="charge" min="0" step="10" value="0">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalTransportCategory" class="form-label">
                                    <i class="fas fa-tags me-1"></i>輸送分類 <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="modalTransportCategory" name="transport_category" required>
                                    <option value="">分類を選択</option>
                                    <?php foreach ($transport_categories as $category): ?>
                                        <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalPaymentMethod" class="form-label">
                                    <i class="fas fa-credit-card me-1"></i>支払方法 <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="modalPaymentMethod" name="payment_method" required>
                                    <option value="">支払方法を選択</option>
                                    <?php foreach ($payment_methods as $method): ?>
                                        <option value="<?php echo $method; ?>"><?php echo $method; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="modalNotes" class="form-label">
                                <i class="fas fa-sticky-note me-1"></i>備考
                            </label>
                            <textarea class="form-control" id="modalNotes" name="notes" rows="2" 
                                      placeholder="特記事項があれば入力してください"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>キャンセル
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 新規登録モーダル表示
        function showAddModal() {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>乗車記録登録';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('modalRecordId').value = '';
            document.getElementById('modalIsReturnTrip').value = '0';
            document.getElementById('modalOriginalRideId').value = '';
            document.getElementById('returnTripInfo').style.display = 'none';
            
            // フォームをリセット
            document.getElementById('rideForm').reset();
            document.getElementById('modalRideDate').value = '<?php echo $today; ?>';
            document.getElementById('modalRideTime').value = '<?php echo $current_time; ?>';
            document.getElementById('modalPassengerCount').value = '1';
            document.getElementById('modalCharge').value = '0';
            
            // 運転者を自動選択（運転者の場合）
            <?php if ($user_role === 'driver'): ?>
                document.getElementById('modalDriverId').value = '<?php echo $user_id; ?>';
            <?php endif; ?>
            
            new bootstrap.Modal(document.getElementById('rideModal')).show();
        }

        // 編集モーダル表示
        function editRecord(record) {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>乗車記録編集';
            document.getElementById('modalAction').value = 'edit';
            document.getElementById('modalRecordId').value = record.id;
            document.getElementById('returnTripInfo').style.display = 'none';
            
            // フォームに値を設定
            document.getElementById('modalDriverId').value = record.driver_id;
            document.getElementById('modalVehicleId').value = record.vehicle_id;
            document.getElementById('modalRideDate').value = record.ride_date;
            document.getElementById('modalRideTime').value = record.ride_time;
            document.getElementById('modalPassengerCount').value = record.passenger_count;
            document.getElementById('modalPickupLocation').value = record.pickup_location;
            document.getElementById('modalDropoffLocation').value = record.dropoff_location;
            document.getElementById('modalFare').value = record.fare;
            document.getElementById('modalCharge').value = record.charge;
            document.getElementById('modalTransportCategory').value = record.transport_category;
            document.getElementById('modalPaymentMethod').value = record.payment_method;
            document.getElementById('modalNotes').value = record.notes || '';
            
            new bootstrap.Modal(document.getElementById('rideModal')).show();
        }

        // 復路作成モーダル表示
        function createReturnTrip(record) {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-route me-2"></i>復路作成';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('modalRecordId').value = '';
            document.getElementById('modalIsReturnTrip').value = '1';
            document.getElementById('modalOriginalRideId').value = record.id;
            document.getElementById('returnTripInfo').style.display = 'block';
            
            // 基本情報をコピー（乗降地は入れ替え）
            document.getElementById('modalDriverId').value = record.driver_id;
            document.getElementById('modalVehicleId').value = record.vehicle_id;
            document.getElementById('modalRideDate').value = record.ride_date;
            document.getElementById('modalRideTime').value = '<?php echo $current_time; ?>';
            document.getElementById('modalPassengerCount').value = record.passenger_count;
            
            // 乗降地を入れ替え
            document.getElementById('modalPickupLocation').value = record.dropoff_location;
            document.getElementById('modalDropoffLocation').value = record.pickup_location;
            
            document.getElementById('modalFare').value = record.fare;
            document.getElementById('modalCharge').value = record.charge;
            document.getElementById('modalTransportCategory').value = record.transport_category;
            document.getElementById('modalPaymentMethod').value = record.payment_method;
            document.getElementById('modalNotes').value = '';
            
            new bootstrap.Modal(document.getElementById('rideModal')).show();
        }

        // 削除確認
        function deleteRecord(recordId) {
            if (confirm('この乗車記録を削除しますか？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="record_id" value="${recordId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // フォーム送信前の確認
        document.getElementById('rideForm').addEventListener('submit', function(e) {
            const action = document.getElementById('modalAction').value;
            const isReturnTrip = document.getElementById('modalIsReturnTrip').value === '1';
            
            let message = '';
            if (action === 'add' && isReturnTrip) {
                message = '復路の乗車記録を登録しますか？';
            } else if (action === 'add') {
                message = '乗車記録を登録しますか？';
            } else {
                message = '乗車記録を更新しますか？';
            }
            
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>