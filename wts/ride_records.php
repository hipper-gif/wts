<?php
session_start();

// データベース接続
require_once 'config/database.php';
require_once 'includes/unified-header.php';

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

// ユーザー情報を取得（新権限システム準拠）
$user_info_sql = "SELECT permission_level, is_driver FROM users WHERE id = ?";
$user_info_stmt = $pdo->prepare($user_info_sql);
$user_info_stmt->execute([$user_id]);
$user_info = $user_info_stmt->fetch(PDO::FETCH_ASSOC);
$user_permission = $user_info['permission_level'] ?: 'User';
$user_is_driver = ($user_info['is_driver'] == 1);

// ユーザー権限表示用
$user_role = ($user_permission === 'Admin') ? '管理者' : '一般ユーザー';

// 今日の日付
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';

// デフォルト運転者・車両の取得
$default_driver_id = $user_is_driver ? $user_id : '';
$default_vehicle_id = '';

// 最近使用した車両を取得（デフォルト車両設定）
if ($user_is_driver) {
    $recent_vehicle_sql = "SELECT vehicle_id FROM ride_records WHERE driver_id = ? ORDER BY created_at DESC LIMIT 1";
    $recent_vehicle_stmt = $pdo->prepare($recent_vehicle_sql);
    $recent_vehicle_stmt->execute([$user_id]);
    $recent_vehicle = $recent_vehicle_stmt->fetchColumn();
    if ($recent_vehicle) {
        $default_vehicle_id = $recent_vehicle;
    }
}

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
            $transportation_type = $_POST['transportation_type'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            $is_return_trip = (isset($_POST['is_return_trip']) && $_POST['is_return_trip'] == '1') ? 1 : 0;
            $original_ride_id = !empty($_POST['original_ride_id']) ? $_POST['original_ride_id'] : null;
            
            // 料金システム統一仕様に準拠
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;
            
            // 出庫記録との紐付け
            $departure_record_sql = "SELECT id FROM departure_records WHERE driver_id = ? AND departure_date = ? ORDER BY created_at DESC LIMIT 1";
            $departure_record_stmt = $pdo->prepare($departure_record_sql);
            $departure_record_stmt->execute([$driver_id, $ride_date]);
            $departure_record_id = $departure_record_stmt->fetchColumn() ?: null;
            
            $insert_sql = "INSERT INTO ride_records 
                (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
                 pickup_location, dropoff_location, fare, charge, total_fare, 
                 cash_amount, card_amount, transportation_type, payment_method, 
                 notes, is_return_trip, original_ride_id, departure_record_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $driver_id, $vehicle_id, $ride_date, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare, $charge, $total_fare,
                $cash_amount, $card_amount, $transportation_type, $payment_method, 
                $notes, $is_return_trip, $original_ride_id, $departure_record_id
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
            $fare = $_POST['fare'];
            $charge = $_POST['charge'] ?? 0;
            $transportation_type = $_POST['transportation_type'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            
            // 料金システム統一仕様に準拠
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;
            
            $update_sql = "UPDATE ride_records SET 
                ride_time = ?, passenger_count = ?, pickup_location = ?, dropoff_location = ?, 
                fare = ?, charge = ?, total_fare = ?, cash_amount = ?, card_amount = ?,
                transportation_type = ?, payment_method = ?, notes = ?, updated_at = NOW() 
                WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $ride_time, $passenger_count, $pickup_location, $dropoff_location,
                $fare, $charge, $total_fare, $cash_amount, $card_amount,
                $transportation_type, $payment_method, $notes, $record_id
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

// 車両一覧取得（status='active'のみ）
$vehicles_sql = "SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE status = 'active' ORDER BY vehicle_number";
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

// 乗車記録一覧取得 - 料金システム統一仕様に準拠
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
    COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0)) as total_amount,
    CASE WHEN r.is_return_trip = 1 THEN '復路' ELSE '往路' END as trip_type
    FROM ride_records r 
    JOIN users u ON r.driver_id = u.id 
    JOIN vehicles v ON r.vehicle_id = v.id 
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY r.ride_time DESC";
$rides_stmt = $pdo->prepare($rides_sql);
$rides_stmt->execute($params);
$rides = $rides_stmt->fetchAll(PDO::FETCH_ASSOC);

// 日次集計 - 料金システム統一仕様に準拠
$summary_sql = "SELECT 
    COUNT(*) as total_rides,
    SUM(r.passenger_count) as total_passengers,
    COALESCE(SUM(r.total_fare), SUM(r.fare + COALESCE(r.charge, 0))) as total_revenue,
    COALESCE(AVG(r.total_fare), AVG(r.fare + COALESCE(r.charge, 0))) as avg_fare,
    SUM(CASE WHEN r.payment_method = '現金' THEN 1 ELSE 0 END) as cash_count,
    SUM(CASE WHEN r.payment_method = 'カード' THEN 1 ELSE 0 END) as card_count,
    COALESCE(SUM(r.cash_amount), SUM(CASE WHEN r.payment_method = '現金' THEN r.fare + COALESCE(r.charge, 0) ELSE 0 END)) as cash_total,
    COALESCE(SUM(r.card_amount), SUM(CASE WHEN r.payment_method = 'カード' THEN r.fare + COALESCE(r.charge, 0) ELSE 0 END)) as card_total
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions);
$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// 輸送分類別集計
$category_sql = "SELECT 
    r.transportation_type,
    COUNT(*) as count,
    SUM(r.passenger_count) as passengers,
    COALESCE(SUM(r.total_fare), SUM(r.fare + COALESCE(r.charge, 0))) as revenue
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY r.transportation_type 
    ORDER BY count DESC";
$category_stmt = $pdo->prepare($category_sql);
$category_stmt->execute($params);
$categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

// 輸送分類・支払方法の選択肢
$transport_categories = ['通院', '外出等', '退院', '転院', '施設入所', 'その他'];
$payment_methods = ['現金', 'カード', 'その他'];

// ページ設定（統一ヘッダーシステム準拠）
$page_config = getPageConfiguration('ride_records');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'css/ui-unified-v3.css',
        'css/header-unified.css'
    ],
    'additional_js' => [
        'js/ui-interactions.js',
        'js/mobile-ride-access.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '乗車記録', 'url' => 'ride_records.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'ride_records',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

// HTMLヘッダー出力
echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<!-- メインコンテンツ開始 -->
<main class="main-content">
    <div class="container-fluid">
        


        <!-- アラート表示 -->
        <?php if ($success_message): ?>
            <?= renderAlert('success', '操作完了', $success_message) ?>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>

        <!-- メインアクションエリア -->
        <div class="unified-card mb-4">
            <div class="unified-card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h3 class="unified-title mb-2">
                            <i class="fas fa-clipboard-list me-2 text-primary"></i>乗車記録管理
                        </h3>
                        <p class="unified-subtitle mb-0">乗車記録の新規登録・編集・復路作成ができます</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="button" class="btn btn-primary btn-lg shadow-sm" onclick="showAddModal()">
                            <i class="fas fa-plus me-2"></i>新規登録
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 検索フォーム -->
        <div class="unified-card mb-4">
            <div class="unified-card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="search_date" class="form-label unified-label">日付</label>
                        <input type="date" class="form-control unified-input" id="search_date" name="search_date" 
                               value="<?php echo htmlspecialchars($search_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="search_driver" class="form-label unified-label">運転者</label>
                        <select class="form-select unified-select" id="search_driver" name="search_driver">
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
                        <label for="search_vehicle" class="form-label unified-label">車両</label>
                        <select class="form-select unified-select" id="search_vehicle" name="search_vehicle">
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
                        <button type="submit" class="btn btn-outline-primary w-100">
                            <i class="fas fa-search me-1"></i>検索
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <!-- 乗車記録一覧 -->
            <div class="col-lg-8">
                <div class="unified-card">
                    <div class="unified-card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-list me-2"></i>乗車記録一覧
                            <small class="ms-2 opacity-75">(<?php echo htmlspecialchars($search_date); ?>)</small>
                        </h4>
                    </div>
                    <div class="unified-card-body">
                        <?php if (empty($rides)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-info-circle fs-2 mb-3 d-block"></i>
                                該当する乗車記録がありません。
                            </div>
                        <?php else: ?>
                            <?php foreach ($rides as $ride): ?>
                                <div class="unified-record-item <?php echo $ride['is_return_trip'] ? 'return-trip' : ''; ?> mb-3">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <strong class="me-3 fs-5"><?php echo substr($ride['ride_time'], 0, 5); ?></strong>
                                                <span class="badge unified-badge <?php echo $ride['is_return_trip'] ? 'badge-success' : 'badge-primary'; ?>">
                                                    <?php echo $ride['trip_type']; ?>
                                                </span>
                                                <small class="text-muted ms-3">
                                                    <?php echo htmlspecialchars($ride['driver_name']); ?> / <?php echo htmlspecialchars($ride['vehicle_number']); ?>
                                                </small>
                                            </div>
                                            <div class="mb-2">
                                                <i class="fas fa-map-marker-alt text-success me-2"></i>
                                                <strong><?php echo htmlspecialchars($ride['pickup_location']); ?></strong>
                                                <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                                <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                                <strong><?php echo htmlspecialchars($ride['dropoff_location']); ?></strong>
                                            </div>
                                            <div class="text-muted small">
                                                <i class="fas fa-users me-1"></i><?php echo $ride['passenger_count']; ?>名
                                                <i class="fas fa-tag ms-3 me-1"></i><?php echo htmlspecialchars($ride['transportation_type']); ?>
                                                <i class="fas fa-credit-card ms-3 me-1"></i><?php echo htmlspecialchars($ride['payment_method']); ?>
                                                <?php if ($ride['notes']): ?>
                                                    <br><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($ride['notes']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="unified-amount-display mb-3">
                                                ¥<?php echo number_format($ride['total_amount']); ?>
                                            </div>
                                            <div class="btn-group" role="group">
                                                <?php if (!$ride['is_return_trip']): ?>
                                                    <button type="button" class="btn btn-success btn-sm" 
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
                <div class="unified-card mb-3">
                    <div class="unified-card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>日次集計</h5>
                    </div>
                    <div class="unified-card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="unified-stat-card text-center">
                                    <div class="unified-stat-value"><?php echo $summary['total_rides'] ?? 0; ?></div>
                                    <div class="unified-stat-label">総回数</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="unified-stat-card text-center">
                                    <div class="unified-stat-value"><?php echo $summary['total_passengers'] ?? 0; ?></div>
                                    <div class="unified-stat-label">総人数</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="unified-stat-card text-center">
                                    <div class="unified-stat-value text-success">¥<?php echo number_format($summary['total_revenue'] ?? 0); ?></div>
                                    <div class="unified-stat-label">売上合計</div>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="unified-payment-stat">
                                    <strong>現金</strong>
                                    <div class="mt-1"><?php echo $summary['cash_count'] ?? 0; ?>回</div>
                                    <div class="text-success fw-bold">¥<?php echo number_format($summary['cash_total'] ?? 0); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="unified-payment-stat">
                                    <strong>カード</strong>
                                    <div class="mt-1"><?php echo $summary['card_count'] ?? 0; ?>回</div>
                                    <div class="text-info fw-bold">¥<?php echo number_format($summary['card_total'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 輸送分類別集計 -->
                <div class="unified-card">
                    <div class="unified-card-header">
                        <h6 class="mb-0"><i class="fas fa-pie-chart me-2"></i>輸送分類別</h6>
                    </div>
                    <div class="unified-card-body">
                        <?php if (empty($categories)): ?>
                            <p class="text-muted text-center py-3">データがありません</p>
                        <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($category['transportation_type']); ?></div>
                                        <small class="text-muted"><?php echo $category['count']; ?>回 / <?php echo $category['passengers']; ?>名</small>
                                    </div>
                                    <div class="text-end">
                                        <strong class="text-success">¥<?php echo number_format($category['revenue']); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- 乗車記録入力・編集モーダル -->
<div class="modal fade" id="rideModal" tabindex="-1" aria-labelledby="rideModalTitle" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content unified-modal">
            <div class="modal-header unified-modal-header">
                <h5 class="modal-title" id="rideModalTitle">
                    <i class="fas fa-plus me-2"></i>乗車記録登録
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="rideForm" method="POST">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="record_id" id="modalRecordId">
                <input type="hidden" name="is_return_trip" id="modalIsReturnTrip" value="0">
                <input type="hidden" name="original_ride_id" id="modalOriginalRideId">
                
                <div class="modal-body">
                    <!-- 復路情報表示 -->
                    <div id="returnTripInfo" class="unified-alert alert-success mb-4" style="display: none;">
                        <h6 class="mb-2"><i class="fas fa-route me-2"></i>復路作成</h6>
                        <p class="mb-0">乗車地と降車地を入れ替えて復路を作成します。</p>
                    </div>

                    <!-- デフォルト設定情報表示 -->
                    <?php if ($user_is_driver): ?>
                    <div class="unified-alert alert-info mb-4">
                        <h6 class="mb-2"><i class="fas fa-user-check me-2"></i>デフォルト設定</h6>
                        <p class="mb-0">あなたが運転者として自動選択されます。変更も可能です。</p>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalDriverId" class="form-label unified-label">
                                <i class="fas fa-user me-1"></i>運転者 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select unified-select" id="modalDriverId" name="driver_id" required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>" 
                                        <?php echo ($driver['id'] == $default_driver_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($driver['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleId" class="form-label unified-label">
                                <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select unified-select" id="modalVehicleId" name="vehicle_id" required>
                                <option value="">車両を選択</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['id']; ?>" 
                                        <?php echo ($vehicle['id'] == $default_vehicle_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalRideDate" class="form-label unified-label">
                                <i class="fas fa-calendar me-1"></i>乗車日 <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control unified-input" id="modalRideDate" name="ride_date" 
                                   value="<?php echo $today; ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalRideTime" class="form-label unified-label">
                                <i class="fas fa-clock me-1"></i>乗車時刻 <span class="text-danger">*</span>
                            </label>
                            <input type="time" class="form-control unified-input" id="modalRideTime" name="ride_time" 
                                   value="<?php echo $current_time; ?>" required>
                        </div>
                    </div>

                    <!-- 人数選択（1-3人はボタン、4人以上は入力） -->
                    <div class="mb-3">
                        <label class="form-label unified-label">
                            <i class="fas fa-users me-1"></i>人員数 <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <button type="button" class="btn btn-outline-primary passenger-btn" data-count="1">1名</button>
                            <button type="button" class="btn btn-outline-primary passenger-btn" data-count="2">2名</button>
                            <button type="button" class="btn btn-outline-primary passenger-btn active" data-count="3">3名</button>
                            <span class="text-muted">または</span>
                            <input type="number" class="form-control unified-input" id="modalPassengerCount" name="passenger_count" 
                                   style="max-width: 100px;" value="1" min="1" max="10" placeholder="4名以上">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalPickupLocation" class="form-label unified-label">
                                <i class="fas fa-map-marker-alt text-success me-1"></i>乗車地 <span class="text-danger">*</span>
                            </label>
                            <div class="unified-dropdown">
                                <input type="text" class="form-control unified-input" id="modalPickupLocation" name="pickup_location" 
                                       placeholder="乗車地を入力または選択" required>
                                <div id="pickupSuggestions" class="unified-suggestions" style="display: none;"></div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalDropoffLocation" class="form-label unified-label">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>降車地 <span class="text-danger">*</span>
                            </label>
                            <div class="unified-dropdown">
                                <input type="text" class="form-control unified-input" id="modalDropoffLocation" name="dropoff_location" 
                                       placeholder="降車地を入力または選択" required>
                                <div id="dropoffSuggestions" class="unified-suggestions" style="display: none;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalFare" class="form-label unified-label">
                                <i class="fas fa-yen-sign me-1"></i>運賃 <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control unified-input" id="modalFare" name="fare" min="0" step="10" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalCharge" class="form-label unified-label">
                                <i class="fas fa-plus me-1"></i>追加料金
                            </label>
                            <input type="number" class="form-control unified-input" id="modalCharge" name="charge" min="0" step="10" value="0">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalTransportationType" class="form-label unified-label">
                                <i class="fas fa-tags me-1"></i>輸送分類 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select unified-select" id="modalTransportationType" name="transportation_type" required>
                                <option value="">分類を選択</option>
                                <?php foreach ($transport_categories as $category): ?>
                                    <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalPaymentMethod" class="form-label unified-label">
                                <i class="fas fa-credit-card me-1"></i>支払方法 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select unified-select" id="modalPaymentMethod" name="payment_method" required>
                                <?php foreach ($payment_methods as $method): ?>
                                    <option value="<?php echo $method; ?>" <?php echo ($method === '現金') ? 'selected' : ''; ?>>
                                        <?php echo $method; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="modalNotes" class="form-label unified-label">
                            <i class="fas fa-sticky-note me-1"></i>備考
                        </label>
                        <textarea class="form-control unified-textarea" id="modalNotes" name="notes" rows="2" 
                                  placeholder="特記事項があれば入力してください"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer unified-modal-footer">
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

<style>
/* モダンミニマル統一CSS（v3.1準拠） */
.unified-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    border: 1px solid rgba(0,0,0,0.06);
    overflow: hidden;
}

.unified-card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.25rem 1.5rem;
    border: none;
}

.unified-card-body {
    padding: 1.5rem;
}

.unified-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #2d3748;
    margin: 0;
}

.unified-subtitle {
    color: #718096;
    font-size: 0.95rem;
}

.unified-record-item {
    background: #f8f9fa;
    padding: 1.25rem;
    border-radius: 12px;
    border-left: 4px solid #667eea;
    transition: all 0.2s;
}

.unified-record-item:hover {
    transform: translateX(2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.unified-record-item.return-trip {
    border-left-color: #28a745;
    background: linear-gradient(90deg, #f0f8f0 0%, #f8f9fa 20%);
}

.unified-amount-display {
    font-size: 1.4rem;
    font-weight: 700;
    color: #28a745;
}

.unified-stat-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 1rem;
    border-radius: 12px;
    border: 1px solid rgba(0,0,0,0.06);
}

.unified-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2d3748;
}

.unified-stat-label {
    font-size: 0.8rem;
    color: #718096;
    font-weight: 500;
}

.unified-payment-stat {
    padding: 0.75rem;
    background: rgba(102, 126, 234, 0.08);
    border-radius: 8px;
}

.unified-badge {
    padding: 0.35rem 0.8rem;
    font-size: 0.75rem;
    border-radius: 20px;
    font-weight: 500;
}

.badge-primary { background: #667eea; color: white; }
.badge-success { background: #28a745; color: white; }

.unified-alert {
    padding: 1rem 1.25rem;
    border-radius: 12px;
    border: none;
}

/* モーダル関連のスタイル - z-index問題解決 */
.modal {
    z-index: 1055 !important;
}

.modal-backdrop {
    z-index: 1050 !important;
    background-color: rgba(0, 0, 0, 0.5) !important;
}

.unified-modal {
    border-radius: 16px;
    overflow: hidden;
    border: none;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

.unified-modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 1.5rem;
}

.unified-modal-footer {
    background: #f8f9fa;
    border-top: 1px solid rgba(0,0,0,0.06);
    padding: 1.25rem 1.5rem;
}

.unified-label {
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 0.5rem;
}

.unified-input, .unified-select, .unified-textarea {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    transition: all 0.2s;
    font-size: 0.95rem;
}

.unified-input:focus, .unified-select:focus, .unified-textarea:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    outline: none;
}

.unified-dropdown {
    position: relative;
}

.unified-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e2e8f0;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1060;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.unified-suggestion {
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f7fafc;
    transition: background-color 0.2s;
}

.unified-suggestion:hover {
    background-color: #f8f9fa;
}

.unified-suggestion:last-child {
    border-bottom: none;
}

.passenger-btn {
    min-width: 60px;
    border-radius: 20px;
    transition: all 0.2s;
    border: 2px solid #667eea;
    color: #667eea;
    background: white;
}

.passenger-btn:hover {
    background: #667eea;
    color: white;
}

.passenger-btn.active {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

/* レスポンシブ対応 */
@media (max-width: 768px) {
    .unified-card-body {
        padding: 1rem;
    }
    
    .unified-record-item {
        padding: 1rem;
    }
    
    .unified-amount-display {
        font-size: 1.2rem;
    }
    
    .passenger-btn {
        min-width: 50px;
        font-size: 0.85rem;
        padding: 0.5rem 0.75rem;
    }
    
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .btn-group .btn {
        width: 100%;
        margin: 0;
    }
    
    .modal-dialog {
        margin: 0.5rem;
        max-width: calc(100% - 1rem);
    }
    
    .unified-modal-header {
        padding: 1rem;
    }
    
    .unified-modal-footer {
        padding: 1rem;
    }
}

/* Bootstrap overrides for better modal behavior */
.modal.show {
    display: block !important;
}

.modal-open {
    overflow: hidden;
}

.modal-dialog-centered {
    min-height: calc(100% - 1rem);
    display: flex;
    align-items: center;
}

/* ボタンのホバー効果改善 */
.btn {
    transition: all 0.2s ease;
}

.btn:hover {
    transform: translateY(-1px);
}

.btn:active {
    transform: translateY(0);
}
</style>

<script>
// PHPから取得したデータ
const commonLocations = <?php echo $locations_json; ?>;
const currentUserId = <?php echo $user_id; ?>;
const userIsDriver = <?php echo $user_is_driver ? 'true' : 'false'; ?>;
const defaultDriverId = '<?php echo $default_driver_id; ?>';
const defaultVehicleId = '<?php echo $default_vehicle_id; ?>';

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
    
    // デフォルト値設定
    document.getElementById('modalRideDate').value = '<?php echo $today; ?>';
    document.getElementById('modalRideTime').value = getCurrentTime();
    document.getElementById('modalPassengerCount').value = '1';
    document.getElementById('modalCharge').value = '0';
    document.getElementById('modalPaymentMethod').value = '現金';
    
    // デフォルト運転者・車両選択
    if (defaultDriverId) {
        document.getElementById('modalDriverId').value = defaultDriverId;
    }
    if (defaultVehicleId) {
        document.getElementById('modalVehicleId').value = defaultVehicleId;
    }
    
    // 人数ボタン初期化
    document.querySelectorAll('.passenger-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector('.passenger-btn[data-count="1"]').classList.add('active');
    
    // Bootstrap Modal を確実に表示
    const modalElement = document.getElementById('rideModal');
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: true,
        focus: true
    });
    modal.show();
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
    document.getElementById('modalTransportationType').value = record.transportation_type;
    document.getElementById('modalPaymentMethod').value = record.payment_method;
    document.getElementById('modalNotes').value = record.notes || '';
    
    // 人数ボタン設定
    updatePassengerButtons(record.passenger_count);
    
    // Bootstrap Modal を確実に表示
    const modalElement = document.getElementById('rideModal');
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: true,
        focus: true
    });
    modal.show();
}

// 復路作成モーダル表示
function createReturnTrip(record) {
    document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-route me-2"></i>復路作成';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalRecordId').value = '';
    document.getElementById('modalIsReturnTrip').value = '1';
    document.getElementById('modalOriginalRideId').value = record.id;
    
    // 復路情報表示
    const returnTripInfo = document.getElementById('returnTripInfo');
    returnTripInfo.style.display = 'block';
    returnTripInfo.innerHTML = `
        <h6 class="mb-2"><i class="fas fa-route me-2"></i>復路作成</h6>
        <p class="mb-0">「${record.pickup_location} → ${record.dropoff_location}」の復路を作成します。</p>
        <p class="mb-0">乗車地と降車地が自動で入れ替わります。</p>
    `;
    
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
    document.getElementById('modalTransportationType').value = record.transportation_type;
    document.getElementById('modalPaymentMethod').value = record.payment_method;
    document.getElementById('modalNotes').value = '';
    
    // 人数ボタン設定
    updatePassengerButtons(record.passenger_count);
    
    // Bootstrap Modal を確実に表示
    const modalElement = document.getElementById('rideModal');
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: 'static',
        keyboard: true,
        focus: true
    });
    modal.show();
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

// 人数ボタンの更新
function updatePassengerButtons(count) {
    document.querySelectorAll('.passenger-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.count == count) {
            btn.classList.add('active');
        }
    });
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
            topLocations.forEach(location => {
                const div = document.createElement('div');
                div.className = 'unified-suggestion';
                div.innerHTML = `<i class="fas fa-map-marker-alt me-2 text-muted"></i>${location}`;
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
        div.className = 'unified-suggestion';
        
        // 検索語をハイライト
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
}

// イベントリスナー設定
document.addEventListener('DOMContentLoaded', function() {
    // 人数選択ボタンのイベント
    document.querySelectorAll('.passenger-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const count = this.dataset.count;
            document.getElementById('modalPassengerCount').value = count;
            
            // アクティブ状態の切り替え
            document.querySelectorAll('.passenger-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });
    
    // 人数入力フィールドの変更時にボタンを更新
    document.getElementById('modalPassengerCount').addEventListener('input', function() {
        updatePassengerButtons(this.value);
    });
    
    // 場所入力フィールドのイベント設定
    ['modalPickupLocation', 'modalDropoffLocation'].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            const type = id.includes('Pickup') ? 'pickup' : 'dropoff';
            
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
        if (!e.target.closest('.unified-dropdown')) {
            document.getElementById('pickupSuggestions').style.display = 'none';
            document.getElementById('dropoffSuggestions').style.display = 'none';
        }
    });
});

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

<?php echo $page_data['html_footer']; ?>
