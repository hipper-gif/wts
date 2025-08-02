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
$user_name = $_SESSION['user_name'] ?? 'ユーザー';

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
            $fare_amount = $_POST['fare_amount'];
            $charge = $_POST['charge'] ?? 0;
            $transport_type = $_POST['transport_type'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            $is_return_trip = (isset($_POST['is_return_trip']) && $_POST['is_return_trip'] == '1') ? 1 : 0;
            $original_ride_id = !empty($_POST['original_ride_id']) ? $_POST['original_ride_id'] : null;
            
// ✅ 正しいINSERT文（テーブル構造に合わせて）
$insert_sql = "INSERT INTO ride_records 
    (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
     pickup_location, dropoff_location, fare_amount, charge, transport_type, 
     payment_method, notes, is_return_trip, original_ride_id, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            // デバッグ情報を追加
            error_log("INSERT SQL: " . $insert_sql);
            error_log("Parameters: " . json_encode([
                $driver_id, $vehicle_id, $ride_date, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare_amount, $charge, $transport_type,
                $payment_method, $notes, $is_return_trip, $original_ride_id
            ]));
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $driver_id, $vehicle_id, $ride_date, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare_amount, $charge, $transport_type,
                $payment_method, $notes, $is_return_trip, $original_ride_id
            ]);
            
            if ($is_return_trip == 1) {
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
            $fare_amount = $_POST['fare_amount'];
            $charge = $_POST['charge'] ?? 0;
            $transport_type = $_POST['transport_type'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            
            $update_sql = "UPDATE ride_records SET 
                ride_time = ?, passenger_count = ?, pickup_location = ?, dropoff_location = ?, 
                fare_amount = ?, charge = ?, transport_type = ?, payment_method = ?, 
                notes = ?, updated_at = NOW() 
                WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $ride_time, $passenger_count, $pickup_location, $dropoff_location,
                $fare_amount, $charge, $transport_type, $payment_method, $notes, $record_id
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
        // デバッグ情報を追加
        error_log("乗車記録処理エラー: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}

// 運転者一覧取得（新権限システム対応）
try {
    $drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name";
    error_log("Drivers SQL: " . $drivers_sql);
    $drivers_stmt = $pdo->prepare($drivers_sql);
    $drivers_stmt->execute();
    $drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Drivers count: " . count($drivers));
} catch (Exception $e) {
    error_log("Drivers query error: " . $e->getMessage());
    $drivers = [];
}

// 車両一覧取得（is_active統一 + COALESCE使用）
try {
    $vehicles_sql = "SELECT id, vehicle_number, model FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number";
    error_log("Vehicles SQL: " . $vehicles_sql);
    $vehicles_stmt = $pdo->prepare($vehicles_sql);
    $vehicles_stmt->execute();
    $vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Vehicles count: " . count($vehicles));
} catch (Exception $e) {
    error_log("Vehicles query error: " . $e->getMessage());
    $vehicles = [];
}

// よく使う場所の取得（過去の記録から）
$common_locations_sql = "
    SELECT location, COUNT(*) as usage_count FROM (
        SELECT pickup_location as location FROM ride_records 
        WHERE pickup_location IS NOT NULL AND pickup_location != '' AND pickup_location NOT LIKE '%(%'
        UNION ALL
        SELECT dropoff_location as location FROM ride_records 
        WHERE dropoff_location IS NOT NULL AND dropoff_location != '' AND dropoff_location NOT LIKE '%(%'
    ) as all_locations 
    GROUP BY location 
    ORDER BY usage_count DESC, location ASC 
    LIMIT 20
";
$common_locations_stmt = $pdo->prepare($common_locations_sql);
$common_locations_stmt->execute();
$common_locations_data = $common_locations_stmt->fetchAll(PDO::FETCH_ASSOC);

// よく使う場所のリストを作成
$common_locations = array_column($common_locations_data, 'location');

// デフォルト場所も追加（よく使われる場所がない場合）
$default_locations = [
    '○○病院', '△△クリニック', '□□総合病院',
    'スーパー○○', 'イオンモール', '駅前ショッピングセンター',
    '○○介護施設', 'デイサービス△△',
    '市役所', '郵便局', '銀行○○支店'
];

if (empty($common_locations)) {
    $common_locations = $default_locations;
} else {
    // 既存の場所とデフォルト場所をマージ（重複除去）
    $common_locations = array_unique(array_merge($common_locations, $default_locations));
}

// JavaScript用にJSONエンコード
$locations_json = json_encode($common_locations, JSON_UNESCAPED_UNICODE);

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

// 乗車記録取得クエリ（JOIN条件強化 + COALESCE使用）
$rides_sql = "SELECT r.*, u.name as driver_name, v.vehicle_number, 
    COALESCE(v.vehicle_name, v.model) as vehicle_name,
    (r.fare_amount + r.charge) as total_amount,
    CASE WHEN r.is_return_trip = 1 THEN '復路' ELSE '往路' END as trip_type
    FROM ride_records r 
    JOIN users u ON r.driver_id = u.id AND u.is_active = 1
    JOIN vehicles v ON r.vehicle_id = v.id AND v.is_active = 1
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY r.ride_time DESC";
$rides_stmt = $pdo->prepare($rides_sql);
$rides_stmt->execute($params);
$rides = $rides_stmt->fetchAll(PDO::FETCH_ASSOC);

// 日次集計
$summary_sql = "SELECT 
    COUNT(*) as total_rides,
    SUM(r.passenger_count) as total_passengers,
    SUM(r.fare_amount + r.charge) as total_revenue,
    AVG(r.fare_amount + r.charge) as avg_fare,
    COUNT(CASE WHEN r.payment_method = '現金' THEN 1 END) as cash_count,
    COUNT(CASE WHEN r.payment_method = 'カード' THEN 1 END) as card_count,
    SUM(CASE WHEN r.payment_method = '現金' THEN r.fare_amount + r.charge ELSE 0 END) as cash_total,
    SUM(CASE WHEN r.payment_method = 'カード' THEN r.fare_amount + r.charge ELSE 0 END) as card_total
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions);
$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// 輸送分類別集計
$category_sql = "SELECT 
    r.transport_type,
    COUNT(*) as count,
    SUM(r.passenger_count) as passengers,
    SUM(r.fare_amount + r.charge) as revenue
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY r.transport_type 
    ORDER BY count DESC";
$category_stmt = $pdo->prepare($category_sql);
$category_stmt->execute($params);
$categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

// 輸送分類・支払方法の選択肢
$transport_types = ['通院', '外出等', '退院', '転院', '施設入所', 'その他'];
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
        
        /* メインアクションエリア */
        .main-actions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .action-btn {
            border: 2px solid white;
            color: white;
            background: transparent;
            padding: 12px 25px;
            font-size: 1.1em;
            font-weight: bold;
            border-radius: 50px;
            margin: 0 10px 10px 0;
            transition: all 0.3s;
            min-width: 180px;
        }
        
        .action-btn:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .action-btn.primary {
            background: white;
            color: #667eea;
            border-color: white;
        }
        
        .action-btn.primary:hover {
            background: #f8f9fa;
            transform: scale(1.05);
        }
        
        /* 検索フォーム - コンパクト化 */
        .search-form {
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .search-form .form-label {
            font-size: 0.9em;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .search-form .form-control,
        .search-form .form-select {
            font-size: 0.9em;
            padding: 6px 10px;
        }
        
        /* 乗車記録カード */
        .ride-record {
            background: white;
            padding: 18px;
            margin: 10px 0;
            border-radius: 12px;
            border-left: 4px solid #007bff;
            transition: all 0.3s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        
        .ride-record:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .return-trip {
            border-left-color: #28a745;
            background: linear-gradient(90deg, #f8fff9 0%, white 20%);
        }
        
        /* 復路作成ボタン強調 */
        .return-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        
        .return-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        /* よく使う場所のドロップダウン */
        .location-dropdown {
            position: relative;
        }
        
        .location-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .location-suggestion {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
        }
        
        .location-suggestion:hover {
            background-color: #f8f9fa;
        }
        
        .location-suggestion:last-child {
            border-bottom: none;
        }
        
        .location-suggestion mark {
            background-color: #fff3cd;
            padding: 0 2px;
            border-radius: 2px;
        }
        
        .location-suggestion-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        
        /* その他のスタイル */
        .amount-display {
            font-size: 1.2em;
            font-weight: bold;
            color: #28a745;
        }
        
        .trip-type-badge {
            font-size: 0.8em;
            padding: 3px 10px;
            border-radius: 15px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        
        .summary-value {
            font-size: 1.8em;
            font-weight: bold;
            display: block;
        }
        
        .return-trip-info {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
        }
        
        /* スマートフォン対応 */
        @media (max-width: 768px) {
            .main-actions {
                text-align: center;
                padding: 15px;
            }
            
            .action-btn {
                display: block;
                width: 100%;
                margin: 0;
                min-width: auto;
            }
            
            .main-actions h3 {
                font-size: 1.3em;
                margin-bottom: 8px;
            }
            
            .main-actions p {
                font-size: 0.9em;
                margin-bottom: 15px;
            }
            
            /* 検索フォーム - スマホ最適化 */
            .search-form {
                padding: 12px;
                margin-bottom: 15px;
            }
            
            .search-form .row {
                --bs-gutter-x: 0.75rem;
            }
            
            .search-form .form-label {
                font-size: 0.8em;
                margin-bottom: 3px;
            }
            
            .search-form .form-control,
            .search-form .form-select {
                font-size: 0.85em;
                padding: 5px 8px;
                height: auto;
            }
            
            .search-form .btn {
                font-size: 0.85em;
                padding: 6px 12px;
                margin-top: 10px;
                width: 100%;
            }
            
            /* 検索フォームの列幅調整 */
            .search-form .col-md-3 {
                margin-bottom: 8px;
            }
            
            .ride-record {
                padding: 15px;
            }
            
            .btn-group {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .location-suggestions {
                max-height: 250px;
            }
            
            .location-suggestion {
                padding: 15px;
                font-size: 1.1em;
            }
        }
        
        /* より小さな画面向けの追加調整 */
        @media (max-width: 576px) {
            .search-form {
                padding: 10px;
            }
            
            .search-form .form-label {
                font-size: 0.75em;
            }
            
            .search-form .form-control,
            .search-form .form-select {
                font-size: 0.8em;
                padding: 4px 6px;
            }
            
            .ride-record {
                padding: 12px;
                margin: 6px 0;
            }
            
            .ride-record .row {
                --bs-gutter-x: 0.5rem;
            }
            
            .amount-display {
                font-size: 1.1em;
            }
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
        <!-- メインアクションエリア -->
        <div class="main-actions">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-2"><i class="fas fa-clipboard-list me-2"></i>乗車記録管理</h3>
                    <p class="mb-0">乗車記録の新規登録・編集・復路作成ができます</p>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" class="action-btn primary" onclick="showAddModal()">
                        <i class="fas fa-plus me-2"></i>新規登録
                    </button>
                </div>
            </div>
        </div>

        <!-- 検索フォーム - コンパクト版 -->
        <div class="search-form">
            <form method="GET" class="row g-2">
                <div class="col-6 col-md-3">
                    <label for="search_date" class="form-label">日付</label>
                    <input type="date" class="form-control" id="search_date" name="search_date" 
                           value="<?php echo htmlspecialchars($search_date); ?>">
                </div>
                <div class="col-6 col-md-3">
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
                <div class="col-6 col-md-3">
                    <label for="search_vehicle" class="form-label">車両</label>
                    <select class="form-select" id="search_vehicle" name="search_vehicle">
                        <option value="">全て</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo $vehicle['id']; ?>" 
                                <?php echo ($search_vehicle == $vehicle['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['model']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>検索
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
                    <div class="card-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; border-radius: 10px 10px 0 0 !important;">
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
                                                <?php echo $ride['passenger_count']; ?>名 / <?php echo htmlspecialchars($ride['transport_type']); ?> / <?php echo htmlspecialchars($ride['payment_method']); ?>
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
                                                    <button type="button" class="btn return-btn btn-sm" 
                                                            onclick="createReturnTrip(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                            title="復路作成">
                                                        <i class="fas fa-route me-1"></i>復路作成
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
                    <div class="card-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white;">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>日次集計</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6">
                                <div class="summary-card">
                                    <span class="summary-value"><?php echo $summary['total_rides'] ?? 0; ?></span>
                                    <span class="summary-label">総回数</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <span class="summary-value"><?php echo $summary['total_passengers'] ?? 0; ?></span>
                                    <span class="summary-label">総人数</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="summary-card">
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
                    <div class="card-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white;">
                        <h6 class="mb-0"><i class="fas fa-pie-chart me-2"></i>輸送分類別</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categories)): ?>
                            <p class="text-muted">データがありません</p>
                        <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($category['transport_type'] ?? 'その他'); ?></strong>
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
                        <div id="returnTripInfo" class="return-trip-info" style="display: none;">
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
                                        <option value="<?php echo $driver['id']; ?>">
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
    <?php echo htmlspecialchars($vehicle['vehicle_number'] . (!empty($vehicle['model']) ? ' - ' . $vehicle['model'] : '')); ?>
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
                                <div class="location-dropdown">
                                    <input type="text" class="form-control" id="modalPickupLocation" name="pickup_location" 
                                           placeholder="乗車地を入力または選択" required>
                                    <div id="pickupSuggestions" class="location-suggestions" style="display: none;"></div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalDropoffLocation" class="form-label">
                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>降車地 <span class="text-danger">*</span>
                                </label>
                                <div class="location-dropdown">
                                    <input type="text" class="form-control" id="modalDropoffLocation" name="dropoff_location" 
                                           placeholder="降車地を入力または選択" required>
                                    <div id="dropoffSuggestions" class="location-suggestions" style="display: none;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalFareAmount" class="form-label">
                                    <i class="fas fa-yen-sign me-1"></i>運賃 <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control" id="modalFareAmount" name="fare_amount" min="0" step="10" required>
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
                                <label for="modalTransportType" class="form-label">
                                    <i class="fas fa-tags me-1"></i>輸送分類 <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="modalTransportType" name="transport_type" required>
                                    <option value="">分類を選択</option>
                                    <?php foreach ($transport_types as $type): ?>
                                        <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalPaymentMethod" class="form-label">
                                    <i class="fas fa-credit-card me-1"></i>支払方法 <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="modalPaymentMethod" name="payment_method" required>
                                    <?php foreach ($payment_methods as $method): ?>
                                        <option value="<?php echo $method; ?>" <?php echo ($method === '現金') ? 'selected' : ''; ?>>
                                            <?php echo $method; ?>
                                        </option>
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
        // PHPから取得したよく使う場所データ
        const commonLocations = <?php echo $locations_json; ?>;

        // デバッグ用: モーダル表示確認
        console.log('showAddModal function called');
        
        // 新規登録モーダル表示
        function showAddModal() {
            console.log('Modal elements check:', {
                modal: document.getElementById('rideModal'),
                title: document.getElementById('rideModalTitle'),
                form: document.getElementById('rideForm')
            });
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>乗車記録登録';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('modalRecordId').value = '';
            document.getElementById('modalIsReturnTrip').value = '0';
            document.getElementById('modalOriginalRideId').value = '';
            document.getElementById('returnTripInfo').style.display = 'none';
            
            // フォームをリセット
            document.getElementById('rideForm').reset();
            document.getElementById('modalRideDate').value = '<?php echo $today; ?>';
            document.getElementById('modalRideTime').value = getCurrentTime();
            document.getElementById('modalPassengerCount').value = '1';
            document.getElementById('modalCharge').value = '0';
            
            // デフォルトで現金を選択
            document.getElementById('modalPaymentMethod').value = '現金';
            
// 運転者を自動選択（ログインユーザー）
var modalDriverId = document.getElementById('modalDriverId');
if (modalDriverId) {
    modalDriverId.value = '<?php echo $user_id; ?>';


var rideModal = new bootstrap.Modal(document.getElementById('rideModal'));
rideModal.show();
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
            document.getElementById('modalFareAmount').value = record.fare_amount;
            document.getElementById('modalCharge').value = record.charge;
            document.getElementById('modalTransportType').value = record.transport_type;
            document.getElementById('modalPaymentMethod').value = record.payment_method;
            document.getElementById('modalNotes').value = record.notes || '';
            
            var rideModal = new bootstrap.Modal(document.getElementById('rideModal'));
            rideModal.show();
        }

        // 復路作成モーダル表示
        function createReturnTrip(record) {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-route me-2"></i>復路作成';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('modalRecordId').value = '';
            document.getElementById('modalIsReturnTrip').value = '1';
            document.getElementById('modalOriginalRideId').value = record.id;
            
            // 復路情報表示
            var returnTripInfo = document.getElementById('returnTripInfo');
            returnTripInfo.style.display = 'block';
            returnTripInfo.innerHTML = 
                '<h6><i class="fas fa-route me-2"></i>復路作成</h6>' +
                '<p class="mb-0">「' + record.pickup_location + ' → ' + record.dropoff_location + '」の復路を作成します。</p>' +
                '<p class="mb-0 text-muted">乗車地と降車地が自動で入れ替わります。</p>';
            
            // 基本情報をコピー（乗降地は入れ替え）
            document.getElementById('modalDriverId').value = record.driver_id;
            document.getElementById('modalVehicleId').value = record.vehicle_id;
            document.getElementById('modalRideDate').value = record.ride_date;
            document.getElementById('modalRideTime').value = getCurrentTime();
            document.getElementById('modalPassengerCount').value = record.passenger_count;
            
            // 乗降地を入れ替え
            document.getElementById('modalPickupLocation').value = record.dropoff_location;
            document.getElementById('modalDropoffLocation').value = record.pickup_location;
            document.getElementById('modalFareAmount').value = record.fare_amount;
            document.getElementById('modalCharge').value = record.charge;
            document.getElementById('modalTransportType').value = record.transport_type;
            document.getElementById('modalPaymentMethod').value = record.payment_method;
            document.getElementById('modalNotes').value = '';
            
            var rideModal = new bootstrap.Modal(document.getElementById('rideModal'));
            rideModal.show();
        }

        // 削除確認
        function deleteRecord(recordId) {
            if (confirm('この乗車記録を削除しますか？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = 
                    '<input type="hidden" name="action" value="delete">' +
                    '<input type="hidden" name="record_id" value="' + recordId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 現在時刻を取得
        function getCurrentTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            return hours + ':' + minutes;
        }

        // よく使う場所の候補表示
        function showLocationSuggestions(input, type) {
            const query = input.value.toLowerCase().trim();
            const suggestionId = type === 'pickup' ? 'pickupSuggestions' : 'dropoffSuggestions';
            const suggestionsDiv = document.getElementById(suggestionId);
            
            // 空文字またはフォーカス時はよく使う場所を表示
            if (query.length === 0) {
                const topLocations = commonLocations.slice(0, 8);
                suggestionsDiv.innerHTML = '';
                
                if (topLocations.length > 0) {
                    const header = document.createElement('div');
                    header.className = 'location-suggestion-header';
                    header.innerHTML = '<small class="text-muted px-3 py-2 d-block"><i class="fas fa-star me-1"></i>よく使う場所</small>';
                    suggestionsDiv.appendChild(header);
                    
                    topLocations.forEach(location => {
                        const div = document.createElement('div');
                        div.className = 'location-suggestion';
                        div.innerHTML = '<i class="fas fa-map-marker-alt me-2 text-muted"></i>' + location;
                        div.onclick = () => selectLocation(input, location, suggestionsDiv);
                        suggestionsDiv.appendChild(div);
                    });
                    suggestionsDiv.style.display = 'block';
                }
                return;
            }
            
            // 検索結果
            const filteredLocations = commonLocations.filter(location =>
                location.toLowerCase().includes(query)
            );
            
            if (filteredLocations.length === 0) {
                suggestionsDiv.style.display = 'none';
                return;
            }
            
            suggestionsDiv.innerHTML = '';
            filteredLocations.slice(0, 10).forEach(location => {
                const div = document.createElement('div');
                div.className = 'location-suggestion';
                

    const highlightedText = location.replace(
        new RegExp(query, 'gi'), 
        `<mark>$&</mark>`
        );
                div.innerHTML = `<i class="fas fa-search me-2 text-muted"></i>${highlightedText}`;
                div.onclick = () => selectLocation(input, location, suggestionsDiv);
                suggestionsDiv.appendChild(div);
            });
            
            suggestionsDiv.style.display = 'block';
        }

        // 場所選択処理
        function selectLocation(input, location, suggestionsDiv) {
            input.value = location;
            suggestionsDiv.style.display = 'none';
            input.classList.remove('is-invalid');
        }

        // イベントリスナー設定
        document.addEventListener('DOMContentLoaded', function() {
            // 場所入力フィールドのイベント設定
            var locationFields = ['modalPickupLocation', 'modalDropoffLocation'];
            locationFields.forEach(function(id) {
                var input = document.getElementById(id);
                if (input) {
                    var type = id.includes('Pickup') ? 'pickup' : 'dropoff';
                    
                    input.addEventListener('keyup', function() {
                        showLocationSuggestions(this, type);
                    });
                    
                    input.addEventListener('focus', function() {
                        showLocationSuggestions(this, type);
                    });
                }
            });
            
            // 外部クリックで候補を閉じる
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.location-dropdown')) {
                    document.getElementById('pickupSuggestions').style.display = 'none';
                    document.getElementById('dropoffSuggestions').style.display = 'none';
                }
            });
        });

        // フォーム送信前の確認
        document.getElementById('rideForm').addEventListener('submit', function(e) {
            var action = document.getElementById('modalAction').value;
            var isReturnTrip = document.getElementById('modalIsReturnTrip').value === '1';
            
            var message = '';
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
