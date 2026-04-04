<?php

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

function canEditRide($record, $user_role) {
    return canEditByDate($record, 'ride_date', $user_role);
}

// session_check.phpで$pdo, $user_id, $user_name, $user_role('Admin'/'User')が設定済み
// 表示用の権限名
$user_role_display = ($user_role === 'Admin') ? '管理者' : '一般ユーザー';

// is_driver情報を取得
$user_info_stmt = $pdo->prepare("SELECT is_driver FROM users WHERE id = ?");
$user_info_stmt->execute([$user_id]);
$user_info = $user_info_stmt->fetch(PDO::FETCH_ASSOC);
$user_is_driver = ($user_info['is_driver'] == 1);

// 今日の日付
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';

// デフォルト運転者・車両の取得
$default_driver_id = $user_is_driver ? $user_id : '';
$default_vehicle_id = '';

// 出庫記録からデフォルト車両を取得
if ($user_is_driver) {
    // 当日の最新出庫記録から車両を取得
    $departure_vehicle_sql = "SELECT vehicle_id FROM departure_records WHERE driver_id = ? AND departure_date = ? ORDER BY created_at DESC LIMIT 1";
    $departure_vehicle_stmt = $pdo->prepare($departure_vehicle_sql);
    $departure_vehicle_stmt->execute([$user_id, $today]);
    $departure_vehicle = $departure_vehicle_stmt->fetchColumn();

    if ($departure_vehicle) {
        $default_vehicle_id = $departure_vehicle;
    } else {
        // 出庫記録がない場合は最近使用した車両を取得（フォールバック）
        $recent_vehicle_sql = "SELECT vehicle_id FROM ride_records WHERE driver_id = ? ORDER BY created_at DESC LIMIT 1";
        $recent_vehicle_stmt = $pdo->prepare($recent_vehicle_sql);
        $recent_vehicle_stmt->execute([$user_id]);
        $recent_vehicle = $recent_vehicle_stmt->fetchColumn();
        if ($recent_vehicle) {
            $default_vehicle_id = $recent_vehicle;
        }
    }
}

// POSTデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    try {
        $action = $_POST['action'] ?? 'add';

        if ($action === 'add') {
            // 新規追加
            $driver_id = $_POST['driver_id'];
            $vehicle_id = $_POST['vehicle_id'];
            $ride_date = $_POST['ride_date'];
            $ride_time = !empty($_POST['ride_time']) ? $_POST['ride_time'] : date('H:i');
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
            $dropoff_time = !empty($_POST['dropoff_time']) ? $_POST['dropoff_time'] : null;
            $ride_distance = !empty($_POST['ride_distance']) ? $_POST['ride_distance'] : null;
            $disability_discount = (isset($_POST['disability_discount']) && $_POST['disability_discount'] == '1') ? 1 : 0;
            $ticket_amount = intval($_POST['ticket_amount'] ?? 0);

            if (empty($driver_id) || empty($vehicle_id) || empty($ride_date) || empty($pickup_location) || empty($dropoff_location)) {
                throw new Exception('運転者、車両、乗車日、乗車地、降車地は必須です。');
            }

            // 料金システム統一仕様に準拠
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;

            // 出庫記録との紐付け
            $departure_record_sql = "SELECT id FROM departure_records WHERE driver_id = ? AND departure_date = ? ORDER BY created_at DESC LIMIT 1";
            $departure_record_stmt = $pdo->prepare($departure_record_sql);
            $departure_record_stmt->execute([$driver_id, $ride_date]);
            $departure_record_id = $departure_record_stmt->fetchColumn() ?: null;

            $pdo->beginTransaction();
            try {
                $insert_sql = "INSERT INTO ride_records
                    (driver_id, vehicle_id, ride_date, ride_time, dropoff_time, passenger_count,
                     pickup_location, dropoff_location, ride_distance, fare, charge, total_fare,
                     cash_amount, card_amount, transportation_type, payment_method,
                     disability_discount, ticket_amount,
                     notes, is_return_trip, original_ride_id, departure_record_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute([
                    $driver_id, $vehicle_id, $ride_date, $ride_time, $dropoff_time, $passenger_count,
                    $pickup_location, $dropoff_location, $ride_distance, $fare, $charge, $total_fare,
                    $cash_amount, $card_amount, $transportation_type, $payment_method,
                    $disability_discount, $ticket_amount,
                    $notes, $is_return_trip, $original_ride_id, $departure_record_id
                ]);

                $new_record_id = $pdo->lastInsertId();

                // 経由地の保存
                $waypoints = $_POST['waypoints'] ?? [];
                if (!empty($waypoints)) {
                    $wp_stmt = $pdo->prepare("INSERT INTO ride_waypoints (ride_record_id, stop_order, location) VALUES (?, ?, ?)");
                    foreach ($waypoints as $i => $wp) {
                        $wp = trim($wp);
                        if ($wp !== '') {
                            $wp_stmt->execute([$new_record_id, $i + 1, $wp]);
                        }
                    }
                }

                logAudit($pdo, $new_record_id, 'create', $user_id, 'ride_record');

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            if ($is_return_trip == 1) {
                $success_message = '復路の乗車記録を登録しました。';
            } else {
                $success_message = '乗車記録を登録しました。';
            }

        } elseif ($action === 'edit') {
            // 編集
            $record_id = $_POST['record_id'];
            $driver_id = $_POST['driver_id'];
            $vehicle_id = $_POST['vehicle_id'];
            $ride_time = !empty($_POST['ride_time']) ? $_POST['ride_time'] : date('H:i');
            $passenger_count = $_POST['passenger_count'];
            $pickup_location = $_POST['pickup_location'];
            $dropoff_location = $_POST['dropoff_location'];
            $fare = $_POST['fare'];
            $charge = $_POST['charge'] ?? 0;
            $transportation_type = $_POST['transportation_type'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            $edit_reason = $_POST['edit_reason'] ?? '';
            $dropoff_time = !empty($_POST['dropoff_time']) ? $_POST['dropoff_time'] : null;
            $ride_distance = !empty($_POST['ride_distance']) ? $_POST['ride_distance'] : null;
            $disability_discount = (isset($_POST['disability_discount']) && $_POST['disability_discount'] == '1') ? 1 : 0;
            $ticket_amount = intval($_POST['ticket_amount'] ?? 0);

            // 既存レコード取得（ロック判定・変更差分用）
            $existing_stmt = $pdo->prepare("SELECT * FROM ride_records WHERE id = ?");
            $existing_stmt->execute([$record_id]);
            $existing_record = $existing_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing_record) {
                throw new Exception('編集対象の記録が見つかりません。');
            }

            // ロック判定
            $edit_check = canEditRide($existing_record, $user_role);
            if (!$edit_check['can_edit']) {
                throw new Exception($edit_check['lock_reason']);
            }
            if ($edit_check['needs_reason'] && empty($edit_reason)) {
                throw new Exception('過去日の記録を修正するには修正理由の入力が必要です。');
            }

            // 料金システム統一仕様に準拠
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;

            // 変更差分を記録
            $changes = [];
            $field_map = [
                'driver_id' => $driver_id, 'vehicle_id' => $vehicle_id,
                'ride_time' => $ride_time, 'dropoff_time' => $dropoff_time,
                'passenger_count' => $passenger_count,
                'pickup_location' => $pickup_location, 'dropoff_location' => $dropoff_location,
                'ride_distance' => $ride_distance,
                'fare' => $fare, 'charge' => $charge,
                'transportation_type' => $transportation_type, 'payment_method' => $payment_method,
                'disability_discount' => $disability_discount, 'ticket_amount' => $ticket_amount,
                'notes' => $notes
            ];
            foreach ($field_map as $field => $new_val) {
                $old_val = $existing_record[$field] ?? '';
                if ((string)$old_val !== (string)$new_val) {
                    $changes[] = ['field' => $field, 'old' => (string)$old_val, 'new' => (string)$new_val];
                }
            }

            $pdo->beginTransaction();
            try {
                $update_sql = "UPDATE ride_records SET
                    driver_id = ?, vehicle_id = ?,
                    ride_time = ?, dropoff_time = ?, passenger_count = ?,
                    pickup_location = ?, dropoff_location = ?, ride_distance = ?,
                    fare = ?, charge = ?, total_fare = ?, cash_amount = ?, card_amount = ?,
                    transportation_type = ?, payment_method = ?,
                    disability_discount = ?, ticket_amount = ?,
                    notes = ?, updated_at = NOW()
                    WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    $driver_id, $vehicle_id,
                    $ride_time, $dropoff_time, $passenger_count,
                    $pickup_location, $dropoff_location, $ride_distance,
                    $fare, $charge, $total_fare, $cash_amount, $card_amount,
                    $transportation_type, $payment_method,
                    $disability_discount, $ticket_amount,
                    $notes, $record_id
                ]);

                // 経由地の再保存（削除→再挿入）
                $pdo->prepare("DELETE FROM ride_waypoints WHERE ride_record_id = ?")->execute([$record_id]);
                $waypoints = $_POST['waypoints'] ?? [];
                if (!empty($waypoints)) {
                    $wp_stmt = $pdo->prepare("INSERT INTO ride_waypoints (ride_record_id, stop_order, location) VALUES (?, ?, ?)");
                    foreach ($waypoints as $i => $wp) {
                        $wp = trim($wp);
                        if ($wp !== '') {
                            $wp_stmt->execute([$record_id, $i + 1, $wp]);
                        }
                    }
                }

                logAudit($pdo, $record_id, 'edit', $user_id, 'ride_record', $changes, $edit_reason ?: null);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            $success_message = '乗車記録を更新しました。';

        } elseif ($action === 'delete') {
            // 削除
            $record_id = $_POST['record_id'];
            $delete_reason = $_POST['delete_reason'] ?? '';

            // 既存レコード取得（ロック判定用）
            $existing_stmt = $pdo->prepare("SELECT * FROM ride_records WHERE id = ?");
            $existing_stmt->execute([$record_id]);
            $existing_record = $existing_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing_record) {
                throw new Exception('削除対象の記録が見つかりません。');
            }

            // 権限チェック: 一般ユーザーは当日データのみ削除可
            $record_date = $existing_record['ride_date'] ?? '';
            if ($user_role !== 'Admin' && $record_date < date('Y-m-d')) {
                throw new Exception('過去日の記録は管理者のみ削除できます。管理者にお問い合わせください。');
            }

            $pdo->beginTransaction();
            try {
                $delete_sql = "DELETE FROM ride_records WHERE id = ?";
                $delete_stmt = $pdo->prepare($delete_sql);
                $delete_stmt->execute([$record_id]);

                logAudit($pdo, $record_id, 'delete', $user_id, 'ride_record', [], $delete_reason ?: null);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            $success_message = '乗車記録を削除しました。';
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者一覧取得（職務フラグのみ使用）
$drivers = getActiveDrivers($pdo);

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

// 各乗車記録の経由地を取得
$wp_stmt = $pdo->prepare("SELECT location FROM ride_waypoints WHERE ride_record_id = ? ORDER BY stop_order");
foreach ($rides as &$ride) {
    $wp_stmt->execute([$ride['id']]);
    $ride['waypoints'] = $wp_stmt->fetchAll(PDO::FETCH_COLUMN);
}
unset($ride);

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
$transport_categories = ['通院', '外出等', '入院', '退院', '転院', '施設入所', 'その他'];
$payment_methods = ['現金', 'カード', 'その他'];

// ページ設定（統一ヘッダーシステム準拠）
$page_config = getPageConfiguration('ride_records');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'css/ui-unified-v3.css',
        'css/header-unified.css',
        'css/workflow-stepper.css'
    ],
    'additional_js' => [
        'js/ui-interactions.js'
    ],
    'workflow_stepper' => renderWorkflowStepper(
        'ride',
        getWorkflowCompletionStatus($pdo, $user_id),
        ['url' => 'departure.php', 'label' => '出庫処理'],
        ['url' => 'arrival.php', 'label' => '入庫処理']
    )
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
<main class="main-content" id="main-content" tabindex="-1">
    <div class="container-fluid">



        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show text-center py-3" style="font-size: 1.1rem; font-weight: 600; border-radius: 12px; box-shadow: 0 4px 15px rgba(25,135,84,0.3);">
            <i class="fas fa-check-circle me-2 fa-lg"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>document.addEventListener('DOMContentLoaded', function() { showToast('<?= addslashes($success_message) ?>', 'success'); });</script>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>

        <!-- メインアクション + 検索トグル -->
        <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
            <button type="button" class="btn btn-primary btn-lg shadow-sm px-4" onclick="showAddModal()" style="font-size:1.05rem;">
                <i class="fas fa-plus me-2"></i>乗車記録を登録する
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#searchCollapse" aria-expanded="false">
                <i class="fas fa-search me-1"></i>検索
            </button>
            <span class="text-muted ms-auto" style="font-size:0.85rem;">
                <?php echo htmlspecialchars($search_date); ?>
                <?php if ($search_driver): ?> / <?php echo htmlspecialchars($drivers[array_search($search_driver, array_column($drivers, 'id'))]['name'] ?? ''); ?><?php endif; ?>
            </span>
        </div>

        <!-- 検索フォーム（折りたたみ） -->
        <div class="collapse <?php echo ($search_driver || $search_vehicle || $search_date !== date('Y-m-d')) ? 'show' : ''; ?> mb-4" id="searchCollapse">
            <div class="unified-card">
                <div class="unified-card-body">
                    <form method="GET" class="row g-3 align-items-end">
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
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="fas fa-search me-1"></i>検索
                            </button>
                        </div>
                    </form>
                </div>
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
                            <div class="text-center py-5">
                                <i class="fas fa-car-side text-muted fs-1 mb-3 d-block" style="opacity:.3"></i>
                                <p class="text-muted mb-3">乗車記録がありません</p>
                                <button type="button" class="btn btn-primary" onclick="showAddModal()">
                                    <i class="fas fa-plus me-1"></i>最初の記録を登録する
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($rides as $ride):
                                $ride_edit_check = canEditRide($ride, $user_role);
                            ?>
                                <div class="unified-record-item <?php echo $ride['is_return_trip'] ? 'return-trip' : ''; ?> mb-3">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <strong class="me-3 fs-5"><?php echo substr($ride['ride_time'], 0, 5); ?></strong>
                                                <span class="badge unified-badge <?php echo $ride['is_return_trip'] ? 'badge-success' : 'badge-primary'; ?>">
                                                    <?php echo $ride['trip_type']; ?>
                                                </span>
                                                <?php if (!$ride_edit_check['can_edit']): ?>
                                                    <span class="badge bg-secondary ms-2" title="<?php echo htmlspecialchars($ride_edit_check['lock_reason']); ?>">
                                                        <i class="fas fa-lock me-1"></i>ロック
                                                    </span>
                                                <?php elseif ($ride_edit_check['needs_reason']): ?>
                                                    <span class="badge bg-warning text-dark ms-2" title="<?php echo htmlspecialchars($ride_edit_check['lock_reason']); ?>">
                                                        <i class="fas fa-exclamation-triangle me-1"></i>過去日
                                                    </span>
                                                <?php endif; ?>
                                                <small class="text-muted ms-3">
                                                    <?php echo htmlspecialchars($ride['driver_name']); ?> / <?php echo htmlspecialchars($ride['vehicle_number']); ?>
                                                </small>
                                            </div>
                                            <div class="mb-2">
                                                <i class="fas fa-map-marker-alt text-primary me-2"></i>
                                                <strong><?php echo htmlspecialchars($ride['pickup_location']); ?></strong>
                                                <?php if (!empty($ride['waypoints'])): ?>
                                                    <?php foreach ($ride['waypoints'] as $wp): ?>
                                                        <i class="fas fa-arrow-right mx-1 text-muted" style="font-size:0.7em;"></i>
                                                        <span class="text-info"><?php echo htmlspecialchars($wp); ?></span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
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
                                                <?php if ($ride_edit_check['can_edit']): ?>
                                                    <button type="button" class="btn btn-warning btn-sm"
                                                            onclick="editRecord(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                            title="編集">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($ride_edit_check['can_edit'] || (!$ride_edit_check['can_edit'] && $user_role === 'Admin')): ?>
                                                    <button type="button" class="btn btn-danger btn-sm"
                                                            onclick="deleteRecord(<?php echo $ride['id']; ?>, <?php echo htmlspecialchars(json_encode($ride['ride_date'])); ?>)"
                                                            title="削除">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
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

<!-- スマホ用フローティング新規ボタン -->
<button type="button" class="ride-fab d-md-none" onclick="showAddModal()" aria-label="新規登録">
    <i class="fas fa-plus"></i>
</button>
<style>
@keyframes slideDown {
    from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}
.ride-fab {
    position: fixed;
    bottom: 24px;
    right: 20px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #667eea;
    color: white;
    border: none;
    font-size: 1.3rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    z-index: 1040;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.ride-fab:active { transform: scale(0.92); }
</style>

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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
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
                        <p class="mb-0">
                            運転者として自動選択されます。<br>
                            車両は当日の出庫記録から自動取得されます。変更も可能です。
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalDriverId" class="form-label unified-label">
                                <i class="fas fa-user me-1"></i>運転者 <span class="text-danger fw-bold small">（必須）</span>
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
                                <i class="fas fa-car me-1"></i>車両 <span class="text-danger fw-bold small">（必須）</span>
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
                                <i class="fas fa-calendar me-1"></i>乗車日 <span class="text-danger fw-bold small">（必須）</span>
                            </label>
                            <input type="date" class="form-control unified-input" id="modalRideDate" name="ride_date" 
                                   value="<?php echo $today; ?>" required>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label for="modalRideTime" class="form-label unified-label">
                                <i class="fas fa-clock me-1"></i>乗車時刻 <span class="text-danger fw-bold small">（必須）</span>
                            </label>
                            <input type="time" class="form-control unified-input" id="modalRideTime" name="ride_time"
                                   value="<?php echo $current_time; ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="modalDropoffTime" class="form-label unified-label">
                                <i class="fas fa-clock me-1" style="opacity:.5"></i>降車時刻
                            </label>
                            <input type="time" class="form-control unified-input" id="modalDropoffTime" name="dropoff_time">
                        </div>
                    </div>

                    <!-- 人数選択（1-3人はボタン、4人以上は入力） -->
                    <div class="mb-3">
                        <label class="form-label unified-label">
                            <i class="fas fa-users me-1"></i>人数 <span class="text-danger fw-bold small">（必須）</span>
                        </label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-primary passenger-btn" data-count="1" onclick="updatePassengerButtons(1)">1名</button>
                            <button type="button" class="btn btn-outline-primary passenger-btn" data-count="2" onclick="updatePassengerButtons(2)">2名</button>
                            <button type="button" class="btn btn-outline-primary passenger-btn" data-count="3" onclick="updatePassengerButtons(3)">3名</button>
                            <span class="text-muted small">4名以上→</span>
                            <input type="number" class="form-control unified-input" id="modalPassengerCount" name="passenger_count"
                                   style="width: 70px;" value="1" min="1" max="20" inputmode="numeric"
                                   onchange="updatePassengerButtons(this.value)">
                            <span class="text-muted small">名</span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalPickupLocation" class="form-label unified-label">
                                <i class="fas fa-map-marker-alt text-primary me-1"></i>乗車地 <span class="text-danger fw-bold small">（必須）</span>
                            </label>
                            <div class="unified-dropdown">
                                <input type="text" class="form-control unified-input" id="modalPickupLocation" name="pickup_location" 
                                       placeholder="乗車地を入力または選択" required>
                                <div id="pickupSuggestions" class="unified-suggestions" style="display: none;"></div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalDropoffLocation" class="form-label unified-label">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>降車地 <span class="text-danger fw-bold small">（必須）</span>
                            </label>
                            <div class="unified-dropdown">
                                <input type="text" class="form-control unified-input" id="modalDropoffLocation" name="dropoff_location"
                                       placeholder="降車地を入力または選択" required>
                                <div id="dropoffSuggestions" class="unified-suggestions" style="display: none;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- 走行距離 -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="modalRideDistance" class="form-label unified-label">
                                <i class="fas fa-road me-1"></i>走行距離 (km)
                            </label>
                            <input type="number" class="form-control unified-input" id="modalRideDistance" name="ride_distance"
                                   min="0" step="0.1" inputmode="decimal" placeholder="例：12.5">
                        </div>
                    </div>

                    <!-- 経由地 -->
                    <div class="mb-3" id="waypointSection">
                        <div id="waypointList"></div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addWaypoint()">
                            <i class="fas fa-plus me-1"></i>経由地を追加
                        </button>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalFare" class="form-label unified-label">
                                <i class="fas fa-yen-sign me-1"></i>運賃 <span class="text-danger fw-bold small">（必須）</span>
                            </label>
                            <input type="number" class="form-control unified-input" id="modalFare" name="fare" min="0" step="10" inputmode="numeric" placeholder="例：5000" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalCharge" class="form-label unified-label">
                                <i class="fas fa-plus me-1"></i>追加料金
                            </label>
                            <input type="number" class="form-control unified-input" id="modalCharge" name="charge" min="0" step="10" inputmode="numeric" placeholder="0" value="0">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="modalTicketAmount" class="form-label unified-label">
                                <i class="fas fa-ticket-alt me-1"></i>利用券額
                            </label>
                            <input type="number" class="form-control unified-input" id="modalTicketAmount" name="ticket_amount"
                                   min="0" step="100" inputmode="numeric" placeholder="0" value="0">
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="modalDisabilityDiscount" name="disability_discount" value="1">
                                <label class="form-check-label" for="modalDisabilityDiscount">
                                    障害者割引
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalTransportationType" class="form-label unified-label">
                                <i class="fas fa-tags me-1"></i>輸送分類 <span class="text-danger fw-bold small">（必須）</span>
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
                                <i class="fas fa-credit-card me-1"></i>支払方法 <span class="text-danger fw-bold small">（必須）</span>
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

                    <!-- 修正理由セクション（過去日データ編集時のみ表示） -->
                    <div class="mb-3" id="editReasonSection" style="display:none;">
                        <label class="form-label"><i class="fas fa-exclamation-triangle text-warning me-1"></i>修正理由（必須）</label>
                        <textarea name="edit_reason" id="editReason" class="form-control" rows="2" placeholder="修正理由を入力してください"></textarea>
                        <small class="text-muted">監査ログに記録されます。</small>
                    </div>
                </div>
                
                <div class="modal-footer unified-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>キャンセル
                    </button>
                    <button type="submit" class="btn btn-primary" data-loading-text="保存中...">
                        <i class="fas fa-save me-1"></i>保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="css/ride_records.css">

<script>
    // 中央表示トースト（改善版）
    function showToast(message, type) {
        var existing = document.getElementById('centralToast');
        if (existing) existing.remove();
        var colors = { success: '#198754', warning: '#ffc107', error: '#dc3545', info: '#0d6efd' };
        var icons = { success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle', info: 'info-circle' };
        var textColor = type === 'warning' ? '#000' : '#fff';
        var html = '<div id="centralToast" style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999;'
            + 'background:' + colors[type] + ';color:' + textColor + ';padding:16px 32px;border-radius:12px;'
            + 'box-shadow:0 8px 32px rgba(0,0,0,0.3);font-size:1.1rem;font-weight:600;'
            + 'display:flex;align-items:center;gap:10px;animation:slideDown 0.4s ease;">'
            + '<i class="fas fa-' + icons[type] + ' fa-lg"></i><span>' + message + '</span></div>';
        document.body.insertAdjacentHTML('beforeend', html);
        setTimeout(function() {
            var el = document.getElementById('centralToast');
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.5s'; setTimeout(function() { el.remove(); }, 500); }
        }, 8000);
    }

    // PHP→JS変数ブリッジ
    const commonLocations = <?php echo $locations_json; ?>;
    const currentUserId = <?php echo $user_id; ?>;
    const userIsDriver = <?php echo $user_is_driver ? 'true' : 'false'; ?>;
    const defaultDriverId = '<?php echo $default_driver_id; ?>';
    const defaultVehicleId = '<?php echo $default_vehicle_id; ?>';
    const userRole = '<?php echo $user_role; ?>';
    const todayDate = '<?php echo $today; ?>';
</script>
<script src="js/ride_records.js" defer></script>

<?php echo $page_data['html_footer']; ?>
