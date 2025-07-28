<?php
session_start();

// データベース接続
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// デバッグ: テーブル構造を確認
try {
    $debug_sql = "SHOW COLUMNS FROM ride_records";
    $debug_stmt = $pdo->prepare($debug_sql);
    $debug_stmt->execute();
    $columns = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // エラーログに記録（本番環境では削除）
    error_log("ride_records テーブル構造:");
    foreach ($columns as $column) {
        error_log($column['Field'] . " - " . $column['Type']);
    }
} catch (Exception $e) {
    error_log("テーブル構造確認エラー: " . $e->getMessage());
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
            // 新規追加 - 安全なINSERT文
            $insert_sql = "INSERT INTO ride_records 
                (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
                 pickup_location, dropoff_location, fare, charge, transport_category, 
                 payment_method, notes, is_return_trip, original_ride_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $result = $insert_stmt->execute([
                $_POST['driver_id'], $_POST['vehicle_id'], $_POST['ride_date'], 
                $_POST['ride_time'], $_POST['passenger_count'], $_POST['pickup_location'], 
                $_POST['dropoff_location'], $_POST['fare'], $_POST['charge'] ?? 0, 
                $_POST['transport_category'], $_POST['payment_method'], 
                $_POST['notes'] ?? '', 
                (isset($_POST['is_return_trip']) && $_POST['is_return_trip'] == '1') ? 1 : 0,
                !empty($_POST['original_ride_id']) ? $_POST['original_ride_id'] : null
            ]);
            
            if ($result) {
                $success_message = (isset($_POST['is_return_trip']) && $_POST['is_return_trip'] == '1') ? 
                    '復路の乗車記録を登録しました。' : '乗車記録を登録しました。';
            } else {
                $error_message = '登録に失敗しました。';
            }
            
        } elseif ($action === 'edit') {
            // 編集 - 安全なUPDATE文
            $update_sql = "UPDATE ride_records SET 
                ride_time = ?, passenger_count = ?, pickup_location = ?, dropoff_location = ?, 
                fare = ?, charge = ?, transport_category = ?, payment_method = ?, 
                notes = ?, updated_at = NOW() 
                WHERE id = ?";
            
            $update_stmt = $pdo->prepare($update_sql);
            $result = $update_stmt->execute([
                $_POST['ride_time'], $_POST['passenger_count'], $_POST['pickup_location'], 
                $_POST['dropoff_location'], $_POST['fare'], $_POST['charge'] ?? 0, 
                $_POST['transport_category'], $_POST['payment_method'], 
                $_POST['notes'] ?? '', $_POST['record_id']
            ]);
            
            if ($result) {
                $success_message = '乗車記録を更新しました。';
            } else {
                $error_message = '更新に失敗しました。';
            }
            
        } elseif ($action === 'delete') {
            // 削除
            $delete_sql = "DELETE FROM ride_records WHERE id = ?";
            $delete_stmt = $pdo->prepare($delete_sql);
            $result = $delete_stmt->execute([$_POST['record_id']]);
            
            if ($result) {
                $success_message = '乗車記録を削除しました。';
            } else {
                $error_message = '削除に失敗しました。';
            }
        }
        
    } catch (Exception $e) {
        $error_message = "エラー: " . $e->getMessage();
        error_log("ride_records.php エラー: " . $e->getMessage());
    }
}

// 運転者一覧取得（エラー回避のため基本的なクエリ）
try {
    $drivers_sql = "SELECT id, name FROM users WHERE is_active = 1 ORDER BY name";
    $drivers_stmt = $pdo->prepare($drivers_sql);
    $drivers_stmt->execute();
    $drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $drivers = [];
    error_log("運転者取得エラー: " . $e->getMessage());
}

// 車両一覧取得
try {
    $vehicles_sql = "SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE status = 'active' ORDER BY vehicle_number";
    $vehicles_stmt = $pdo->prepare($vehicles_sql);
    $vehicles_stmt->execute();
    $vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $vehicles = [];
    error_log("車両取得エラー: " . $e->getMessage());
}

// 検索条件
$search_date = $_GET['search_date'] ?? $today;
$search_driver = $_GET['search_driver'] ?? '';
$search_vehicle = $_GET['search_vehicle'] ?? '';

// 乗車記録一覧取得（シンプルなクエリに変更）
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

try {
    $rides_sql = "SELECT 
        r.id, r.driver_id, r.vehicle_id, r.ride_date, r.ride_time,
        r.passenger_count, r.pickup_location, r.dropoff_location,
        r.fare, COALESCE(r.charge, 0) as charge, r.transport_category,
        r.payment_method, r.notes, COALESCE(r.is_return_trip, 0) as is_return_trip,
        (r.fare + COALESCE(r.charge, 0)) as total_amount,
        u.name as driver_name, v.vehicle_number, v.vehicle_name,
        CASE WHEN COALESCE(r.is_return_trip, 0) = 1 THEN '復路' ELSE '往路' END as trip_type
        FROM ride_records r 
        LEFT JOIN users u ON r.driver_id = u.id 
        LEFT JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE " . implode(' AND ', $where_conditions) . "
        ORDER BY r.ride_time DESC";
    
    $rides_stmt = $pdo->prepare($rides_sql);
    $rides_stmt->execute($params);
    $rides = $rides_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rides = [];
    $error_message = "乗車記録取得エラー: " . $e->getMessage();
    error_log("乗車記録取得エラー: " . $e->getMessage());
}

// 日次集計（最もシンプルなクエリ）
try {
    $summary_sql = "SELECT 
        COUNT(*) as total_rides,
        COALESCE(SUM(passenger_count), 0) as total_passengers,
        COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as total_revenue,
        COUNT(CASE WHEN payment_method = '現金' THEN 1 END) as cash_count,
        COUNT(CASE WHEN payment_method = 'カード' THEN 1 END) as card_count,
        COALESCE(SUM(CASE WHEN payment_method = '現金' THEN fare + COALESCE(charge, 0) ELSE 0 END), 0) as cash_total,
        COALESCE(SUM(CASE WHEN payment_method = 'カード' THEN fare + COALESCE(charge, 0) ELSE 0 END), 0) as card_total
        FROM ride_records 
        WHERE " . implode(' AND ', $where_conditions);
    
    $summary_stmt = $pdo->prepare($summary_sql);
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $summary = [
        'total_rides' => 0, 'total_passengers' => 0, 'total_revenue' => 0,
        'cash_count' => 0, 'card_count' => 0, 'cash_total' => 0, 'card_total' => 0
    ];
    error_log("集計エラー: " . $e->getMessage());
}

// 輸送分類別集計
try {
    $category_sql = "SELECT 
        transport_category,
        COUNT(*) as count,
        COALESCE(SUM(passenger_count), 0) as passengers,
        COALESCE(SUM(fare + COALESCE(charge, 0)), 0) as revenue
        FROM ride_records 
        WHERE " . implode(' AND ', $where_conditions) . "
        GROUP BY transport_category 
        ORDER BY count DESC";
    
    $category_stmt = $pdo->prepare($category_sql);
    $category_stmt->execute($params);
    $categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
    error_log("分類別集計エラー: " . $e->getMessage());
}

// 輸送分類・支払方法の選択肢
$transport_categories = ['通院', '外出等', '退院', '転院', '施設入所', 'その他'];
$payment_methods = ['現金', 'カード', 'その他'];

// よく使う場所の取得（エラー回避版）
$common_locations = [];
try {
    $locations_sql = "SELECT DISTINCT pickup_location as location FROM ride_records 
        WHERE pickup_location IS NOT NULL AND pickup_location != '' 
        UNION 
        SELECT DISTINCT dropoff_location as location FROM ride_records 
        WHERE dropoff_location IS NOT NULL AND dropoff_location != ''
        ORDER BY location LIMIT 20";
    $locations_stmt = $pdo->prepare($locations_sql);
    $locations_stmt->execute();
    $common_locations = array_column($locations_stmt->fetchAll(PDO::FETCH_ASSOC), 'location');
} catch (Exception $e) {
    error_log("よく使う場所取得エラー: " . $e->getMessage());
}

// デフォルト場所も追加
$default_locations = [
    '○○病院', '△△クリニック', '□□総合病院',
    'スーパー○○', 'イオンモール', '駅前ショッピングセンター',
    '○○介護施設', 'デイサービス△△',
    '市役所', '郵便局', '銀行○○支店'
];

if (empty($common_locations)) {
    $common_locations = $default_locations;
} else {
    $common_locations = array_unique(array_merge($common_locations, $default_locations));
}

$locations_json = json_encode($common_locations, JSON_UNESCAPED_UNICODE);
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
        
        .ride-record {
            background: white;
            padding: 18px;
            margin: 10px 0;
            border-radius: 12px;
            border-left: 4px solid #007bff;
            transition: all 0.3s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        
        .return-trip {
            border-left-color: #28a745;
            background: linear-gradient(90deg, #f8fff9 0%, white 20%);
        }
        
        .return-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        
        .amount-display {
            font-size: 1.2em;
            font-weight: bold;
            color: #28a745;
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

        <!-- 検索フォーム -->
        <div class="card mb-3">
            <div class="card-body">
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
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i>検索
                        </button>
                    </div>
                </form>
            </div>
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
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>乗車記録一覧
                            <small class="ms-2">(<?php echo htmlspecialchars($search_date); ?>)</small>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($rides)): ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-info-circle me-2"></i>
                                該当する乗車記録がありません。
                            </p>
                        <?php else: ?>
                            <?php foreach ($rides as $ride): ?>
                                <div class="ride-record <?php echo ($ride['is_return_trip'] == 1) ? 'return-trip' : ''; ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <strong class="me-2"><?php echo substr($ride['ride_time'], 0, 5); ?></strong>
                                                <span class="badge <?php echo ($ride['is_return_trip'] == 1) ? 'bg-success' : 'bg-primary'; ?>">
                                                    <?php echo $ride['trip_type']; ?>
                                                </span>
                                                <small class="text-muted ms-2">
                                                    <?php echo htmlspecialchars($ride['driver_name'] ?? ''); ?> / 
                                                    <?php echo htmlspecialchars($ride['vehicle_number'] ?? ''); ?>
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
                                                <?php echo $ride['passenger_count']; ?>名 / 
                                                <?php echo htmlspecialchars($ride['transport_category']); ?> / 
                                                <?php echo htmlspecialchars($ride['payment_method']); ?>
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
                                                <?php if ($ride['is_return_trip'] != 1): ?>
                                                    <button type="button" class="btn return-btn btn-sm" 
                                                            onclick="createReturnTrip(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                            title="復路作成">
                                                        <i class="fas fa-route me-1"></i>復路
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
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>日次集計</h6>
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
                    <div class="card-header bg-primary text-white">
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
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalDriverId" class="form-label">運転者 <span class="text-danger">*</span></label>
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
                                <label for="modalVehicleId" class="form-label">車両 <span class="text-danger">*</span></label>
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
                                <label for="modalRideDate" class="form-label">乗車日 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="modalRideDate" name="ride_date" 
                                       value="<?php echo $today; ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalRideTime" class="form-label">乗車時刻 <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="modalRideTime" name="ride_time" 
                                       value="<?php echo $current_time; ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="modalPassengerCount" class="form-label">人員数 <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="modalPassengerCount" name="passenger_count" 
                                   value="1" min="1" max="10" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalPickupLocation" class="form-label">乗車地 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modalPickupLocation" name="pickup_location" 
                                       placeholder="乗車地を入力" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalDropoffLocation" class="form-label">降車地 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modalDropoffLocation" name="dropoff_location" 
                                       placeholder="降車地を入力" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalFare" class="form-label">運賃 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="modalFare" name="fare" min="0" step="10" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalCharge" class="form-label">料金</label>
                                <input type="number" class="form-control" id="modalCharge" name="charge" min="0" step="10" value="0">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modalTransportCategory" class="form-label">輸送分類 <span class="text-danger">*</span></label>
                                <select class="form-select" id="modalTransportCategory" name="transport_category" required>
                                    <option value="">分類を選択</option>
                                    <?php foreach ($transport_categories as $category): ?>
                                        <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="modalPaymentMethod" class="form-label">支払方法 <span class="text-danger">*</span></label>
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
                            <label for="modalNotes" class="form-label">備考</label>
                            <textarea class="form-control" id="modalNotes" name="notes" rows="2" 
                                      placeholder="特記事項があれば入力してください"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">保存</button>
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
            
            // フォームをリセット
            document.getElementById('rideForm').reset();
            document.getElementById('modalRideDate').value = '<?php echo $today; ?>';
            document.getElementById('modalRideTime').value = getCurrentTime();
            document.getElementById('modalPassengerCount').value = '1';
            document.getElementById('modalCharge').value = '0';
            document.getElementById('modalPaymentMethod').value = '現金';
            
            new bootstrap.Modal(document.getElementById('rideModal')).show();
        }

        // 編集モーダル表示
        function editRecord(record) {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>乗車記録編集';
            document.getElementById('modalAction').value = 'edit';
            document.getElementById('modalRecordId').value = record.id;
            
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
            
            // 基本情報をコピー（乗降地は入れ替え）
            document.getElementById('modalDriverId').value = record.driver_id;
            document.getElementById('modalVehicleId').value = record.vehicle_id;
            document.getElementById('modalRideDate').value = record.ride_date;
            document.getElementById('modalRideTime').value = getCurrentTime();
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

        // 現在時刻を取得
        function getCurrentTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            return `${hours}:${minutes}`;
        }
    </script>
</body>
</html>
