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

// ユーザー情報を取得
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

$success_message = '';
$error_message = '';

// 検索条件
$search_date = $_GET['search_date'] ?? $today;
$search_driver = $_GET['search_driver'] ?? '';
$search_vehicle = $_GET['search_vehicle'] ?? '';

// 運転者一覧取得
$drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name";
$drivers_stmt = $pdo->prepare($drivers_sql);
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// 車両一覧取得
$vehicles_sql = "SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE status = 'active' ORDER BY vehicle_number";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute();
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

// 入庫記録一覧取得
$where_conditions = ["DATE(a.arrival_date) = ?"];
$params = [$search_date];

if ($search_driver) {
    $where_conditions[] = "a.driver_id = ?";
    $params[] = $search_driver;
}

if ($search_vehicle) {
    $where_conditions[] = "a.vehicle_id = ?";
    $params[] = $search_vehicle;
}

// 管理者以外は自分の記録のみ表示
if ($user_permission !== 'Admin') {
    $where_conditions[] = "a.driver_id = ?";
    $params[] = $user_id;
}

$arrivals_sql = "SELECT
    a.*,
    u.name as driver_name,
    v.vehicle_number,
    v.vehicle_name,
    d.departure_mileage,
    d.departure_date,
    d.departure_time,
    COALESCE(editor.name, '') as edited_by_name
    FROM arrival_records a
    JOIN users u ON a.driver_id = u.id
    JOIN vehicles v ON a.vehicle_id = v.id
    LEFT JOIN departure_records d ON a.departure_record_id = d.id
    LEFT JOIN users editor ON a.last_edited_by = editor.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY a.arrival_time DESC";

$arrivals_stmt = $pdo->prepare($arrivals_sql);
$arrivals_stmt->execute($params);
$arrivals = $arrivals_stmt->fetchAll(PDO::FETCH_ASSOC);

// 日次集計
$summary_sql = "SELECT
    COUNT(*) as total_count,
    SUM(a.total_distance) as total_distance,
    SUM(a.fuel_cost) as total_fuel_cost,
    SUM(a.highway_cost) as total_highway_cost,
    SUM(a.toll_cost) as total_toll_cost,
    SUM(a.other_cost) as total_other_cost,
    SUM(a.fuel_cost + a.highway_cost + a.toll_cost + a.other_cost) as total_costs
    FROM arrival_records a
    WHERE " . implode(' AND ', $where_conditions);

$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// ページ設定
$page_config = getPageConfiguration('arrival_list');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'css/ui-unified-v3.css',
        'css/header-unified.css'
    ],
    'additional_js' => [
        'js/ui-interactions.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '入庫記録一覧', 'url' => 'arrival_list.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'arrival_list',
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
        <div id="alertContainer"></div>

        <!-- メインアクションエリア -->
        <div class="unified-card mb-4">
            <div class="unified-card-body">
                <div class="row align-items-center">
                    <div class="col-md-12">
                        <h3 class="unified-title mb-2">
                            <i class="fas fa-list me-2 text-primary"></i>入庫記録一覧・修正
                        </h3>
                        <p class="unified-subtitle mb-0">過去の入庫記録を確認・修正できます</p>
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
                    <?php if ($user_permission === 'Admin'): ?>
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
                    <?php endif; ?>
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
            <!-- 入庫記録一覧 -->
            <div class="col-lg-8">
                <div class="unified-card">
                    <div class="unified-card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-list me-2"></i>入庫記録
                            <small class="ms-2 opacity-75">(<?php echo htmlspecialchars($search_date); ?>)</small>
                        </h4>
                    </div>
                    <div class="unified-card-body">
                        <?php if (empty($arrivals)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-info-circle fs-2 mb-3 d-block"></i>
                                該当する入庫記録がありません。
                            </div>
                        <?php else: ?>
                            <?php foreach ($arrivals as $arrival): ?>
                                <div class="unified-record-item <?php echo $arrival['is_edited'] ? 'edited-record' : ''; ?> mb-3">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <strong class="me-3 fs-5"><?php echo substr($arrival['arrival_time'], 0, 5); ?></strong>
                                                <?php if ($arrival['is_edited']): ?>
                                                    <span class="badge badge-warning">
                                                        <i class="fas fa-edit me-1"></i>修正済み
                                                    </span>
                                                <?php endif; ?>
                                                <small class="text-muted ms-3">
                                                    <?php echo htmlspecialchars($arrival['driver_name']); ?> /
                                                    <?php echo htmlspecialchars($arrival['vehicle_number']); ?>
                                                </small>
                                            </div>
                                            <div class="mb-2">
                                                <i class="fas fa-tachometer-alt text-primary me-2"></i>
                                                <strong>出庫: <?php echo number_format($arrival['departure_mileage']); ?>km</strong>
                                                <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                                <strong>入庫: <?php echo number_format($arrival['arrival_mileage']); ?>km</strong>
                                                <span class="ms-3 text-success">
                                                    <i class="fas fa-route me-1"></i><?php echo number_format($arrival['total_distance']); ?>km
                                                </span>
                                            </div>
                                            <div class="text-muted small">
                                                <i class="fas fa-gas-pump me-1"></i>燃料: ¥<?php echo number_format($arrival['fuel_cost']); ?>
                                                <i class="fas fa-road ms-3 me-1"></i>高速: ¥<?php echo number_format($arrival['highway_cost']); ?>
                                                <?php if ($arrival['toll_cost'] > 0): ?>
                                                    <i class="fas fa-ticket-alt ms-3 me-1"></i>通行料: ¥<?php echo number_format($arrival['toll_cost']); ?>
                                                <?php endif; ?>
                                                <?php if ($arrival['other_cost'] > 0): ?>
                                                    <i class="fas fa-coins ms-3 me-1"></i>その他: ¥<?php echo number_format($arrival['other_cost']); ?>
                                                <?php endif; ?>
                                                <?php if ($arrival['remarks']): ?>
                                                    <br><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($arrival['remarks']); ?>
                                                <?php endif; ?>
                                                <?php if ($arrival['is_edited']): ?>
                                                    <br><i class="fas fa-info-circle me-1 text-warning"></i>
                                                    修正理由: <?php echo htmlspecialchars($arrival['edit_reason']); ?>
                                                    <?php if ($arrival['edited_by_name']): ?>
                                                        (修正者: <?php echo htmlspecialchars($arrival['edited_by_name']); ?>)
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="unified-amount-display mb-3">
                                                ¥<?php echo number_format($arrival['fuel_cost'] + $arrival['highway_cost'] + $arrival['toll_cost'] + $arrival['other_cost']); ?>
                                            </div>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-warning btn-sm"
                                                        onclick="editRecord(<?php echo $arrival['id']; ?>)"
                                                        title="修正">
                                                    <i class="fas fa-edit me-1"></i>修正
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
                                    <div class="unified-stat-value"><?php echo $summary['total_count'] ?? 0; ?></div>
                                    <div class="unified-stat-label">入庫回数</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="unified-stat-card text-center">
                                    <div class="unified-stat-value"><?php echo number_format($summary['total_distance'] ?? 0); ?></div>
                                    <div class="unified-stat-label">総走行距離(km)</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="unified-stat-card text-center">
                                    <div class="unified-stat-value text-danger">¥<?php echo number_format($summary['total_costs'] ?? 0); ?></div>
                                    <div class="unified-stat-label">総費用</div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="row text-center">
                            <div class="col-12 mb-3">
                                <div class="unified-payment-stat">
                                    <strong><i class="fas fa-gas-pump me-2"></i>燃料代</strong>
                                    <div class="text-success fw-bold mt-1">¥<?php echo number_format($summary['total_fuel_cost'] ?? 0); ?></div>
                                </div>
                            </div>
                            <div class="col-12 mb-3">
                                <div class="unified-payment-stat">
                                    <strong><i class="fas fa-road me-2"></i>高速代</strong>
                                    <div class="text-info fw-bold mt-1">¥<?php echo number_format($summary['total_highway_cost'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- 入庫記録編集モーダル -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalTitle" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content unified-modal">
            <div class="modal-header unified-modal-header">
                <h5 class="modal-title" id="editModalTitle">
                    <i class="fas fa-edit me-2"></i>入庫記録修正
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editForm">
                <input type="hidden" name="arrival_id" id="modalArrivalId">

                <div class="modal-body">
                    <!-- 現在の情報表示 -->
                    <div class="unified-alert alert-info mb-4">
                        <h6 class="mb-2"><i class="fas fa-info-circle me-2"></i>現在の記録情報</h6>
                        <div id="currentInfo"></div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalArrivalDate" class="form-label unified-label">
                                <i class="fas fa-calendar me-1"></i>入庫日 <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control unified-input" id="modalArrivalDate" name="arrival_date" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalArrivalTime" class="form-label unified-label">
                                <i class="fas fa-clock me-1"></i>入庫時刻 <span class="text-danger">*</span>
                            </label>
                            <input type="time" class="form-control unified-input" id="modalArrivalTime" name="arrival_time" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="modalArrivalMileage" class="form-label unified-label">
                            <i class="fas fa-tachometer-alt me-1"></i>入庫メーター(km) <span class="text-danger">*</span>
                        </label>
                        <input type="number" class="form-control unified-input" id="modalArrivalMileage" name="arrival_mileage" min="0" required>
                        <div class="form-text">
                            出庫メーター: <span id="departureMileageDisplay" class="fw-bold"></span>km
                            <span id="distanceDisplay" class="ms-3 text-success"></span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalFuelCost" class="form-label unified-label">
                                <i class="fas fa-gas-pump me-1"></i>燃料代(円)
                            </label>
                            <input type="number" class="form-control unified-input" id="modalFuelCost" name="fuel_cost" min="0" step="10" value="0">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalHighwayCost" class="form-label unified-label">
                                <i class="fas fa-road me-1"></i>高速代(円)
                            </label>
                            <input type="number" class="form-control unified-input" id="modalHighwayCost" name="highway_cost" min="0" step="10" value="0">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalTollCost" class="form-label unified-label">
                                <i class="fas fa-ticket-alt me-1"></i>通行料(円)
                            </label>
                            <input type="number" class="form-control unified-input" id="modalTollCost" name="toll_cost" min="0" step="10" value="0">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modalOtherCost" class="form-label unified-label">
                                <i class="fas fa-coins me-1"></i>その他費用(円)
                            </label>
                            <input type="number" class="form-control unified-input" id="modalOtherCost" name="other_cost" min="0" step="10" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="modalRemarks" class="form-label unified-label">
                            <i class="fas fa-sticky-note me-1"></i>備考
                        </label>
                        <textarea class="form-control unified-textarea" id="modalRemarks" name="remarks" rows="2"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="modalEditReason" class="form-label unified-label">
                            <i class="fas fa-comment me-1"></i>修正理由 <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control unified-input" id="modalEditReason" name="edit_reason"
                               placeholder="例: メーター読み間違い、費用追加、時刻修正など" required maxlength="100">
                        <div class="form-text text-danger">※修正理由の入力は必須です</div>
                    </div>
                </div>

                <div class="modal-footer unified-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>キャンセル
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>修正を保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* モダンミニマル統一CSS */
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

.unified-record-item.edited-record {
    border-left-color: #ffc107;
    background: linear-gradient(90deg, #fff9e6 0%, #f8f9fa 20%);
}

.unified-amount-display {
    font-size: 1.4rem;
    font-weight: 700;
    color: #dc3545;
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

.badge-warning {
    background: #ffc107;
    color: #000;
    padding: 0.35rem 0.8rem;
    font-size: 0.75rem;
    border-radius: 20px;
    font-weight: 500;
}

.unified-alert {
    padding: 1rem 1.25rem;
    border-radius: 12px;
    border: none;
}

.alert-info {
    background: #e7f3ff;
    color: #014361;
}

/* モーダル関連 */
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

    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .btn-group .btn {
        width: 100%;
        margin: 0;
    }
}
</style>

<script>
let currentDepartureMileage = 0;

// 編集モーダル表示
async function editRecord(arrivalId) {
    try {
        // 記録取得
        const response = await fetch(`api/get_arrival_record.php?id=${arrivalId}`);
        const data = await response.json();

        if (!data.success) {
            showAlert('danger', 'エラー', data.error);
            return;
        }

        const record = data.record;
        currentDepartureMileage = parseInt(record.departure_mileage);

        // 現在の情報表示
        document.getElementById('currentInfo').innerHTML = `
            <p class="mb-1"><strong>運転者:</strong> ${record.driver_name}</p>
            <p class="mb-1"><strong>車両:</strong> ${record.vehicle_number}</p>
            <p class="mb-1"><strong>出庫日時:</strong> ${record.departure_date} ${record.departure_time}</p>
            <p class="mb-0"><strong>出庫メーター:</strong> ${parseInt(record.departure_mileage).toLocaleString()}km</p>
        `;

        // フォームに値を設定
        document.getElementById('modalArrivalId').value = record.id;
        document.getElementById('modalArrivalDate').value = record.arrival_date;
        document.getElementById('modalArrivalTime').value = record.arrival_time;
        document.getElementById('modalArrivalMileage').value = record.arrival_mileage;
        document.getElementById('modalFuelCost').value = record.fuel_cost || 0;
        document.getElementById('modalHighwayCost').value = record.highway_cost || 0;
        document.getElementById('modalTollCost').value = record.toll_cost || 0;
        document.getElementById('modalOtherCost').value = record.other_cost || 0;
        document.getElementById('modalRemarks').value = record.remarks || '';
        document.getElementById('modalEditReason').value = '';

        // 出庫メーター表示
        document.getElementById('departureMileageDisplay').textContent = currentDepartureMileage.toLocaleString();

        // 走行距離計算
        calculateDistance();

        // モーダル表示
        const modalElement = document.getElementById('editModal');
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: true,
            focus: true
        });
        modal.show();

    } catch (error) {
        console.error('Error:', error);
        showAlert('danger', 'エラー', '記録の取得に失敗しました');
    }
}

// 走行距離計算
function calculateDistance() {
    const arrivalMileage = parseInt(document.getElementById('modalArrivalMileage').value) || 0;
    const distance = arrivalMileage - currentDepartureMileage;

    const displayElement = document.getElementById('distanceDisplay');

    if (distance < 0) {
        displayElement.innerHTML = '<i class="fas fa-exclamation-triangle text-danger"></i> 入庫メーターが出庫メーターより小さいです';
        displayElement.className = 'ms-3 text-danger';
    } else {
        displayElement.innerHTML = `<i class="fas fa-route"></i> 走行距離: ${distance.toLocaleString()}km`;
        displayElement.className = 'ms-3 text-success';

        if (distance > 500) {
            displayElement.innerHTML += ' <i class="fas fa-info-circle text-warning ms-2"></i> 走行距離が500kmを超えています';
        }
    }
}

// フォーム送信処理
document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    if (!confirm('入庫記録を修正しますか？\n※修正履歴が記録されます')) {
        return;
    }

    const formData = new FormData(this);

    try {
        const response = await fetch('api/edit_arrival_record.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // モーダルを閉じる
            const modalElement = document.getElementById('editModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            modal.hide();

            // 成功メッセージ表示
            showAlert('success', '修正完了', data.message);

            // ページをリロード
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showAlert('danger', 'エラー', data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('danger', 'エラー', '修正の保存に失敗しました');
    }
});

// アラート表示関数
function showAlert(type, title, message) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <strong>${title}</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    document.getElementById('alertContainer').innerHTML = alertHtml;

    // 3秒後に自動で消す
    setTimeout(() => {
        const alert = document.querySelector('.alert');
        if (alert) {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }
    }, 3000);
}

// イベントリスナー設定
document.addEventListener('DOMContentLoaded', function() {
    // メーター入力時の走行距離計算
    document.getElementById('modalArrivalMileage').addEventListener('input', calculateDistance);
});
</script>

<?php echo $page_data['html_footer']; ?>
