<?php
session_start();
require_once 'config/database.php';
require_once 'functions.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 管理者権限チェック
if (!in_array($_SESSION['permission_level'], ['Admin']) && $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['name'];
$user_role = $_SESSION['permission_level'] ?? $_SESSION['user_role'];

// 統一ヘッダーシステム
require_once 'includes/unified-header.php';
$page_config = getPageConfiguration('vehicle_management');

$success_message = '';
$error_message = '';

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            // 新規車両追加
            $vehicle_number = trim($_POST['vehicle_number']);
            $vehicle_name = trim($_POST['vehicle_name']);
            $model = trim($_POST['model']) ?: null;
            $registration_date = $_POST['registration_date'] ?: null;
            $vehicle_type = $_POST['vehicle_type'] ?? 'welfare';
            $capacity = intval($_POST['capacity']) ?: 4;
            $current_mileage = intval($_POST['current_mileage']) ?: 0;
            $next_inspection_date = $_POST['next_inspection_date'] ?: null;
            $status = $_POST['status'] ?? 'active';
            
            // バリデーション
            if (empty($vehicle_number) || empty($vehicle_name)) {
                throw new Exception('車両番号と車両名は必須です。');
            }
            
            // 車両番号重複チェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_number = ? AND is_active = 1");
            $stmt->execute([$vehicle_number]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両番号は既に使用されています。');
            }
            
            // 車両追加（仕様書準拠のカラム構成）
            $stmt = $pdo->prepare("
                INSERT INTO vehicles (
                    vehicle_number, vehicle_name, model, registration_date, 
                    vehicle_type, capacity, current_mileage, next_inspection_date, 
                    status, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $vehicle_number, $vehicle_name, $model, $registration_date,
                $vehicle_type, $capacity, $current_mileage, $next_inspection_date,
                $status
            ]);
            
            $success_message = '車両を追加しました。';
            
        } elseif ($action === 'edit') {
            // 車両編集
            $vehicle_id = $_POST['vehicle_id'];
            $vehicle_number = trim($_POST['vehicle_number']);
            $vehicle_name = trim($_POST['vehicle_name']);
            $model = trim($_POST['model']) ?: null;
            $registration_date = $_POST['registration_date'] ?: null;
            $vehicle_type = $_POST['vehicle_type'] ?? 'welfare';
            $capacity = intval($_POST['capacity']) ?: 4;
            $current_mileage = intval($_POST['current_mileage']) ?: 0;
            $next_inspection_date = $_POST['next_inspection_date'] ?: null;
            $status = $_POST['status'] ?? 'active';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // バリデーション
            if (empty($vehicle_number) || empty($vehicle_name)) {
                throw new Exception('車両番号と車両名は必須です。');
            }
            
            // 車両番号重複チェック（自分以外）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_number = ? AND id != ? AND is_active = 1");
            $stmt->execute([$vehicle_number, $vehicle_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両番号は既に使用されています。');
            }
            
            // 車両更新
            $stmt = $pdo->prepare("
                UPDATE vehicles SET 
                    vehicle_number = ?, vehicle_name = ?, model = ?, registration_date = ?,
                    vehicle_type = ?, capacity = ?, current_mileage = ?, next_inspection_date = ?,
                    status = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $vehicle_number, $vehicle_name, $model, $registration_date,
                $vehicle_type, $capacity, $current_mileage, $next_inspection_date,
                $status, $is_active, $vehicle_id
            ]);
            
            $success_message = '車両情報を更新しました。';
            
        } elseif ($action === 'delete') {
            // 論理削除
            $vehicle_id = $_POST['vehicle_id'];
            
            // 使用中チェック（今日の出庫記録があるか）
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM departure_records 
                WHERE vehicle_id = ? AND departure_date = CURDATE()
            ");
            $stmt->execute([$vehicle_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両は本日使用中のため削除できません。');
            }
            
            // 論理削除実行
            $stmt = $pdo->prepare("UPDATE vehicles SET is_active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            
            $success_message = '車両を削除しました。';
            
        } elseif ($action === 'update_inspection') {
            // 点検日更新
            $vehicle_id = $_POST['vehicle_id'];
            $next_inspection_date = $_POST['next_inspection_date'];
            
            if (!$next_inspection_date) {
                throw new Exception('点検日を入力してください。');
            }
            
            $stmt = $pdo->prepare("
                UPDATE vehicles SET 
                    next_inspection_date = ?, 
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$next_inspection_date, $vehicle_id]);
            
            $success_message = '点検期限を更新しました。';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 車両一覧取得（仕様書準拠のカラム使用）
$stmt = $pdo->prepare("
    SELECT v.*, 
           DATEDIFF(v.next_inspection_date, CURDATE()) as days_to_inspection,
           CASE 
               WHEN v.next_inspection_date IS NULL THEN 'unknown'
               WHEN DATEDIFF(v.next_inspection_date, CURDATE()) < 0 THEN 'overdue'
               WHEN DATEDIFF(v.next_inspection_date, CURDATE()) <= 7 THEN 'urgent'
               WHEN DATEDIFF(v.next_inspection_date, CURDATE()) <= 30 THEN 'warning'
               ELSE 'ok'
           END as inspection_status
    FROM vehicles v 
    WHERE v.is_active = 1
    ORDER BY v.vehicle_number
");
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報取得
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_vehicles,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_vehicles,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_vehicles,
        SUM(CASE WHEN next_inspection_date IS NOT NULL AND DATEDIFF(next_inspection_date, CURDATE()) <= 30 THEN 1 ELSE 0 END) as inspection_due_soon
    FROM vehicles 
    WHERE is_active = 1
");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// ステータス・車両タイプ選択肢
$statuses = [
    'active' => '運用中',
    'maintenance' => '整備中',
    'reserved' => '予約済み',
    'inactive' => '使用停止'
];

$vehicle_types = [
    'welfare' => '福祉車両',
    'taxi' => 'タクシー'
];

// ページオプション設定
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
    ],
    'additional_js' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '管理機能', 'url' => 'master_menu.php'],
        ['text' => '車両管理', 'url' => 'vehicle_management.php']
    ]
];

// 完全ページ生成（統一ヘッダーシステム使用）
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'vehicle_management',
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

<main class="container mt-4">
    <!-- アラート -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- 統計情報ダッシュボード -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">総車両数</h6>
                            <h2 class="mb-0"><?= $stats['total_vehicles'] ?></h2>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-car fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">運用中</h6>
                            <h2 class="mb-0"><?= $stats['active_vehicles'] ?></h2>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">整備中</h6>
                            <h2 class="mb-0"><?= $stats['maintenance_vehicles'] ?></h2>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-tools fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">点検期限間近</h6>
                            <h2 class="mb-0"><?= $stats['inspection_due_soon'] ?></h2>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-exclamation-triangle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 点検期限アラート -->
    <?php 
    $urgent_vehicles = array_filter($vehicles, function($v) { 
        return in_array($v['inspection_status'], ['overdue', 'urgent']); 
    });
    if (!empty($urgent_vehicles)): 
    ?>
    <div class="alert alert-warning">
        <h6><i class="fas fa-exclamation-triangle me-2"></i>点検期限アラート</h6>
        <?php foreach ($urgent_vehicles as $vehicle): ?>
            <div class="mb-1">
                <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong>
                (<?= htmlspecialchars($vehicle['vehicle_name']) ?>): 
                <?php if ($vehicle['inspection_status'] === 'overdue'): ?>
                    <span class="text-danger">期限切れ（<?= abs($vehicle['days_to_inspection']) ?>日経過）</span>
                <?php else: ?>
                    <span class="text-warning">あと<?= $vehicle['days_to_inspection'] ?>日</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 新規追加ボタン -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>車両一覧</h4>
        <button type="button" class="btn btn-primary" onclick="showAddModal()">
            <i class="fas fa-plus me-2"></i>新規車両追加
        </button>
    </div>

    <!-- 車両一覧テーブル -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($vehicles)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-car fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">車両が登録されていません</h5>
                    <p class="text-muted">「新規車両追加」ボタンから車両を登録してください。</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>車両番号</th>
                                <th>車両名</th>
                                <th>車種</th>
                                <th>タイプ</th>
                                <th>ステータス</th>
                                <th>走行距離</th>
                                <th>次回点検</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td>
                                <td><?= htmlspecialchars($vehicle['model'] ?? '-') ?></td>
                                <td>
                                    <span class="badge bg-<?= $vehicle['vehicle_type'] === 'welfare' ? 'info' : 'warning' ?>">
                                        <?= $vehicle_types[$vehicle['vehicle_type']] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $vehicle['status'] === 'active' ? 'success' : 
                                        ($vehicle['status'] === 'maintenance' ? 'warning' : 
                                        ($vehicle['status'] === 'reserved' ? 'info' : 'secondary')) 
                                    ?>">
                                        <?= $statuses[$vehicle['status']] ?>
                                    </span>
                                </td>
                                <td class="font-monospace">
                                    <?= number_format($vehicle['current_mileage']) ?> km
                                </td>
                                <td>
                                    <?php if ($vehicle['next_inspection_date']): ?>
                                        <div class="d-flex align-items-center">
                                            <span class="badge bg-<?= 
                                                $vehicle['inspection_status'] === 'ok' ? 'success' : 
                                                ($vehicle['inspection_status'] === 'warning' ? 'warning' : 
                                                ($vehicle['inspection_status'] === 'urgent' ? 'danger' : 'dark')) 
                                            ?> me-2">
                                                <?= $vehicle['next_inspection_date'] ?>
                                            </span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="quickUpdateInspection(<?= $vehicle['id'] ?>, '<?= $vehicle['next_inspection_date'] ?>')"
                                                    title="点検日更新">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">未設定</span>
                                        <button type="button" class="btn btn-sm btn-outline-warning ms-1" 
                                                onclick="quickUpdateInspection(<?= $vehicle['id'] ?>, '')"
                                                title="点検日設定">
                                            <i class="fas fa-calendar-plus"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="editVehicle(<?= htmlspecialchars(json_encode($vehicle), ENT_QUOTES) ?>)"
                                                title="編集">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteVehicle(<?= $vehicle['id'] ?>, '<?= htmlspecialchars($vehicle['vehicle_number']) ?>')"
                                                title="削除">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- 車両追加・編集モーダル -->
<div class="modal fade" id="vehicleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vehicleModalTitle">車両追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="vehicleForm" method="POST">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="vehicle_id" id="modalVehicleId">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleNumber" class="form-label">
                                車両番号 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="modalVehicleNumber" 
                                   name="vehicle_number" required placeholder="例: 福祉001">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleName" class="form-label">
                                車両名 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="modalVehicleName" 
                                   name="vehicle_name" required placeholder="例: スマイルカー1号">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalModel" class="form-label">車種・型式</label>
                            <input type="text" class="form-control" id="modalModel" 
                                   name="model" placeholder="例: トヨタ ハイエース">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalRegistrationDate" class="form-label">登録日</label>
                            <input type="date" class="form-control" id="modalRegistrationDate" 
                                   name="registration_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleType" class="form-label">車両タイプ</label>
                            <select class="form-select" id="modalVehicleType" name="vehicle_type">
                                <?php foreach ($vehicle_types as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalCapacity" class="form-label">定員</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="modalCapacity" 
                                       name="capacity" min="1" max="20" value="4">
                                <span class="input-group-text">人</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalCurrentMileage" class="form-label">現在走行距離</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="modalCurrentMileage" 
                                       name="current_mileage" min="0" value="0">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalNextInspectionDate" class="form-label">次回点検日</label>
                            <input type="date" class="form-control" id="modalNextInspectionDate" 
                                   name="next_inspection_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalStatus" class="form-label">ステータス</label>
                            <select class="form-select" id="modalStatus" name="status">
                                <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="activeField" style="display: none;">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="modalIsActive" 
                                       name="is_active" checked>
                                <label class="form-check-label" for="modalIsActive">
                                    有効
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        キャンセル
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 点検日更新モーダル -->
<div class="modal fade" id="inspectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">点検期限更新</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_inspection">
                <input type="hidden" name="vehicle_id" id="inspectionVehicleId">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="inspectionDate" class="form-label">新しい点検期限</label>
                        <input type="date" class="form-control" id="inspectionDate" 
                               name="next_inspection_date" required>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        法定点検は3ヶ月ごとに実施が必要です。
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        キャンセル
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calendar-check me-1"></i>更新
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 新規追加モーダル表示
function showAddModal() {
    document.getElementById('vehicleModalTitle').textContent = '車両追加';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalVehicleId').value = '';
    document.getElementById('activeField').style.display = 'none';
    
    // フォームリセット
    document.getElementById('vehicleForm').reset();
    document.getElementById('modalCurrentMileage').value = '0';
    document.getElementById('modalCapacity').value = '4';
    
    // 3か月後の日付を自動設定
    const today = new Date();
    const threeMonthsLater = new Date(today.setMonth(today.getMonth() + 3));
    const dateString = threeMonthsLater.toISOString().split('T')[0];
    document.getElementById('modalNextInspectionDate').value = dateString;
    
    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}

// 編集モーダル表示
function editVehicle(vehicle) {
    document.getElementById('vehicleModalTitle').textContent = '車両編集';
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalVehicleId').value = vehicle.id;
    document.getElementById('modalVehicleNumber').value = vehicle.vehicle_number;
    document.getElementById('modalVehicleName').value = vehicle.vehicle_name || '';
    document.getElementById('modalModel').value = vehicle.model || '';
    document.getElementById('modalRegistrationDate').value = vehicle.registration_date || '';
    document.getElementById('modalVehicleType').value = vehicle.vehicle_type || 'welfare';
    document.getElementById('modalCapacity').value = vehicle.capacity || 4;
    document.getElementById('modalCurrentMileage').value = vehicle.current_mileage || 0;
    document.getElementById('modalNextInspectionDate').value = vehicle.next_inspection_date || '';
    document.getElementById('modalStatus').value = vehicle.status || 'active';
    document.getElementById('modalIsActive').checked = vehicle.is_active == 1;
    document.getElementById('activeField').style.display = 'block';
    
    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}

// 削除確認
function deleteVehicle(vehicleId, vehicleNumber) {
    if (confirm(`車両「${vehicleNumber}」を削除しますか？\n※論理削除のため、後で復旧可能です。`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="vehicle_id" value="${vehicleId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// 点検日更新モーダル
function quickUpdateInspection(vehicleId, currentDate) {
    document.getElementById('inspectionVehicleId').value = vehicleId;
    document.getElementById('inspectionDate').value = currentDate;
    
    // 現在の日付から3か月後を推奨
    if (!currentDate) {
        const today = new Date();
        const threeMonthsLater = new Date(today.setMonth(today.getMonth() + 3));
        const dateString = threeMonthsLater.toISOString().split('T')[0];
        document.getElementById('inspectionDate').value = dateString;
    }
    
    new bootstrap.Modal(document.getElementById('inspectionModal')).show();
}

// Bootstrap初期化確認
document.addEventListener('DOMContentLoaded', function() {
    // Bootstrapが正常に読み込まれているかチェック
    if (typeof bootstrap === 'undefined') {
        console.warn('Bootstrap が読み込まれていません');
    }
});
</script>

<?php echo $page_data['html_footer']; ?>
