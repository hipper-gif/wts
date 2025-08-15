// ログインユーザーが運転者かどうかを確認（職務フラグのみ使用）
try {
    $user_is_driver_sql = "SELECT is_driver FROM users WHERE id = ?";
    $user_is_driver_stmt = $pdo->prepare($user_is_driver_sql);
    $user_is_driver_stmt->execute([$user_id]);
    $user_info = $user_is_driver_stmt->fetch(PDO::FETCH_ASSOC);
    $user_is_driver = ($user_info && $user_info['is_driver'] == 1);
} catch (Exception $e) {
    error_log("ユーザー情報取得エラー: " . $e->getMessage());
    $user_is_driver = false;
}<?php
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

// ユーザーの権限レベルを取得（新権限システム）
$user_permission_sql = "SELECT permission_level FROM users WHERE id = ?";
$user_permission_stmt = $pdo->prepare($user_permission_sql);
$user_permission_stmt->execute([$user_id]);
$user_permission = $user_permission_stmt->fetchColumn() ?: 'User';

// 🔥 デバッグ: テーブル構造確認（本番環境では削除）
try {
    // vehiclesテーブルの構造確認
    $vehicles_columns_sql = "SHOW COLUMNS FROM vehicles";
    $vehicles_columns_stmt = $pdo->prepare($vehicles_columns_sql);
    $vehicles_columns_stmt->execute();
    $vehicles_columns = $vehicles_columns_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ride_recordsテーブルの構造確認
    $rides_columns_sql = "SHOW COLUMNS FROM ride_records";
    $rides_columns_stmt = $pdo->prepare($rides_columns_sql);
    $rides_columns_stmt->execute();
    $rides_columns = $rides_columns_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // usersテーブルの構造確認
    $users_columns_sql = "SHOW COLUMNS FROM users";
    $users_columns_stmt = $pdo->prepare($users_columns_sql);
    $users_columns_stmt->execute();
    $users_columns = $users_columns_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // エラーが発生しても処理を続行
    error_log("テーブル構造確認エラー: " . $e->getMessage());
}

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
            $is_return_trip = (isset($_POST['is_return_trip']) && $_POST['is_return_trip'] == '1') ? 1 : 0;
            $original_ride_id = !empty($_POST['original_ride_id']) ? $_POST['original_ride_id'] : null;
            
            // total_fare, cash_amount, card_amount の計算
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;
            
            $insert_sql = "INSERT INTO ride_records 
                (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
                 pickup_location, dropoff_location, fare, charge, total_fare,
                 cash_amount, card_amount, transport_category, 
                 payment_method, notes, is_return_trip, original_ride_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $driver_id, $vehicle_id, $ride_date, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare, $charge, $total_fare,
                $cash_amount, $card_amount, $transport_category,
                $payment_method, $notes, $is_return_trip, $original_ride_id
            ]);
            
            if ($is_return_trip == 1) {
                $success_message = '復路の乗車記録を登録しました。';
            } else {
                $success_message = '乗車記録を登録しました。';
            }
            
        } elseif ($action === 'edit') {
            // 編集 - 🔥 運転者と車両情報も含めて更新
            $record_id = $_POST['record_id'];
            $driver_id = $_POST['driver_id']; // 🔥 追加
            $vehicle_id = $_POST['vehicle_id']; // 🔥 追加
            $ride_time = $_POST['ride_time'];
            $passenger_count = $_POST['passenger_count'];
            $pickup_location = $_POST['pickup_location'];
            $dropoff_location = $_POST['dropoff_location'];
            $fare = $_POST['fare'];
            $charge = $_POST['charge'] ?? 0;
            $transport_category = $_POST['transport_category'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            
            // total_fare, cash_amount, card_amount の再計算
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;
            
            // 🔥 UPDATE文に driver_id, vehicle_id, 料金計算カラムを追加
            $update_sql = "UPDATE ride_records SET 
                driver_id = ?, vehicle_id = ?, ride_time = ?, passenger_count = ?, 
                pickup_location = ?, dropoff_location = ?, fare = ?, charge = ?, 
                total_fare = ?, cash_amount = ?, card_amount = ?,
                transport_category = ?, payment_method = ?, notes = ?, updated_at = NOW() 
                WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $driver_id, $vehicle_id, $ride_time, $passenger_count, 
                $pickup_location, $dropoff_location, $fare, $charge,
                $total_fare, $cash_amount, $card_amount,
                $transport_category, $payment_method, $notes, $record_id
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

// 運転者一覧取得（職務フラグのみ使用）
$drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name";
$drivers_stmt = $pdo->prepare($drivers_sql);
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// 車両一覧取得（シンプル版）
$vehicles_sql = "SELECT id, 
    CASE 
        WHEN vehicle_number IS NOT NULL THEN vehicle_number
        WHEN name IS NOT NULL THEN name
        ELSE CONCAT('車両', id)
    END as vehicle_number,
    CASE 
        WHEN vehicle_name IS NOT NULL THEN vehicle_name
        WHEN name IS NOT NULL THEN name
        ELSE CONCAT('車両', id)
    END as vehicle_name
    FROM vehicles 
    ORDER BY id";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute();
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

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

// 🔥 乗車記録一覧取得（エラーハンドリング強化）
try {
    $rides_sql = "SELECT 
        r.id, r.ride_time, r.passenger_count, r.pickup_location, r.dropoff_location,
        r.fare, COALESCE(r.charge, 0) as charge, r.transport_category, r.payment_method, 
        r.notes, r.is_return_trip, r.ride_date, r.driver_id, r.vehicle_id,
        COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0)) as total_amount,
        u.name as driver_name,
        CASE 
            WHEN v.vehicle_number IS NOT NULL THEN v.vehicle_number
            WHEN v.name IS NOT NULL THEN v.name
            ELSE CONCAT('車両', v.id)
        END as vehicle_number,
        CASE 
            WHEN v.vehicle_name IS NOT NULL THEN v.vehicle_name
            WHEN v.name IS NOT NULL THEN v.name
            ELSE CONCAT('車両', v.id)
        END as vehicle_name,
        CASE WHEN r.is_return_trip = 1 THEN '復路' ELSE '往路' END as trip_type
        FROM ride_records r 
        LEFT JOIN users u ON r.driver_id = u.id 
        LEFT JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE " . implode(' AND ', $where_conditions) . "
        ORDER BY r.ride_time DESC";
    
    $rides_stmt = $pdo->prepare($rides_sql);
    $rides_stmt->execute($params);
    $rides = $rides_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("乗車記録取得エラー: " . $e->getMessage());
    $rides = [];
    $error_message = "乗車記録の取得でエラーが発生しました。";
}

// 🔥 日次集計（エラーハンドリング強化）
try {
    $summary_sql = "SELECT 
        COUNT(*) as total_rides,
        SUM(r.passenger_count) as total_passengers,
        SUM(COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0))) as total_revenue,
        AVG(COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0))) as avg_fare,
        COUNT(CASE WHEN r.payment_method = '現金' THEN 1 END) as cash_count,
        COUNT(CASE WHEN r.payment_method = 'カード' THEN 1 END) as card_count,
        SUM(CASE WHEN r.payment_method = '現金' THEN COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0)) ELSE 0 END) as cash_total,
        SUM(CASE WHEN r.payment_method = 'カード' THEN COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0)) ELSE 0 END) as card_total
        FROM ride_records r 
        WHERE " . implode(' AND ', $where_conditions);
    
    $summary_stmt = $pdo->prepare($summary_sql);
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("集計データ取得エラー: " . $e->getMessage());
    $summary = [
        'total_rides' => 0,
        'total_passengers' => 0,
        'total_revenue' => 0,
        'avg_fare' => 0,
        'cash_count' => 0,
        'card_count' => 0,
        'cash_total' => 0,
        'card_total' => 0
    ];
}

// 🔥 輸送分類別集計（エラーハンドリング強化）
try {
    $category_sql = "SELECT 
        r.transport_category,
        COUNT(*) as count,
        SUM(r.passenger_count) as passengers,
        SUM(COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0))) as revenue
        FROM ride_records r 
        WHERE " . implode(' AND ', $where_conditions) . "
        GROUP BY r.transport_category 
        ORDER BY count DESC";
    
    $category_stmt = $pdo->prepare($category_sql);
    $category_stmt->execute($params);
    $categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("分類別集計取得エラー: " . $e->getMessage());
    $categories = [];
}

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
        
        /* デフォルト運転者表示 */
        .default-driver-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .default-driver-info .icon {
            color: #1976d2;
            font-size: 1.2em;
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
        
        /* 🔥 合計料金自動計算表示 */
        .total-fare-display {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
        }
        
        .total-fare-amount {
            font-size: 1.3em;
            font-weight: bold;
            color: #1565c0;
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
                                <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
