<?php
session_start();
require_once 'config/database.php';
require_once 'functions.php'; // ✅ 修正：updated_user_functions.php → functions.php
require_once 'includes/unified-header.php'; // 統一ヘッダーシステム適用

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 管理者権限チェック
if (!in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

$success_message = '';
$error_message = '';

// 統一ヘッダー設定
$page_config = getPageConfiguration('vehicle_management');

// 現在のシステム名を取得
$current_system_name = getSystemName($pdo);

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_system_name') {
            // システム名更新
            $system_name = trim($_POST['system_name']);
            
            if (empty($system_name)) {
                throw new Exception('システム名は必須です。');
            }
            
            // system_settingsテーブルが存在しない場合は作成
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) NOT NULL UNIQUE,
                    setting_value TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
            } catch (Exception $e) {
                // テーブルが既に存在する場合は無視
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                VALUES ('system_name', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $stmt->execute([$system_name]);
            
            $current_system_name = $system_name; // 画面に即座に反映
            $success_message = 'システム名を更新しました。';
            
        } elseif ($action === 'add') {
            // 新規追加
            $vehicle_number = trim($_POST['vehicle_number']);
            $vehicle_name = trim($_POST['vehicle_name']);
            $model = trim($_POST['model']);
            $current_mileage = intval($_POST['current_mileage']);
            $next_inspection_date = $_POST['next_inspection_date'] ?: null;
            $status = $_POST['status'];
            
            // バリデーション
            if (empty($vehicle_number) || empty($vehicle_name)) {
                throw new Exception('車両番号と車両名は必須です。');
            }
            
            // 車両番号重複チェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_number = ?");
            $stmt->execute([$vehicle_number]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両番号は既に使用されています。');
            }
            
            // 車両追加
            $stmt = $pdo->prepare("INSERT INTO vehicles (vehicle_number, vehicle_name, model, current_mileage, next_inspection_date, status, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, TRUE, NOW())");
            $stmt->execute([$vehicle_number, $vehicle_name, $model, $current_mileage, $next_inspection_date, $status]);
            
            $success_message = '車両を追加しました。';
            
        } elseif ($action === 'edit') {
            // 編集
            $vehicle_id = $_POST['vehicle_id'];
            $vehicle_number = trim($_POST['vehicle_number']);
            $vehicle_name = trim($_POST['vehicle_name']);
            $model = trim($_POST['model']);
            $current_mileage = intval($_POST['current_mileage']);
            $next_inspection_date = $_POST['next_inspection_date'] ?: null;
            $status = $_POST['status'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // バリデーション
            if (empty($vehicle_number) || empty($vehicle_name)) {
                throw new Exception('車両番号と車両名は必須です。');
            }
            
            // 車両番号重複チェック（自分以外）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_number = ? AND id != ?");
            $stmt->execute([$vehicle_number, $vehicle_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両番号は既に使用されています。');
            }
            
            // 車両更新
            $stmt = $pdo->prepare("UPDATE vehicles SET vehicle_number = ?, vehicle_name = ?, model = ?, current_mileage = ?, next_inspection_date = ?, status = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$vehicle_number, $vehicle_name, $model, $current_mileage, $next_inspection_date, $status, $is_active, $vehicle_id]);
            
            $success_message = '車両情報を更新しました。';
            
        } elseif ($action === 'delete') {
            // 削除（論理削除）
            $vehicle_id = $_POST['vehicle_id'];
            
            // 使用中チェック（今日の出庫記録があるか）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE vehicle_id = ? AND departure_date = CURDATE()");
            $stmt->execute([$vehicle_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両は本日使用中のため削除できません。');
            }
            
            $stmt = $pdo->prepare("UPDATE vehicles SET is_active = FALSE, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            
            $success_message = '車両を削除しました。';
            
        } elseif ($action === 'update_inspection') {
            // 点検日更新
            $vehicle_id = $_POST['vehicle_id'];
            $next_inspection_date = $_POST['next_inspection_date'];
            
            $stmt = $pdo->prepare("UPDATE vehicles SET next_inspection_date = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$next_inspection_date, $vehicle_id]);
            
            $success_message = '点検期限を更新しました。';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 車両一覧取得
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
    ORDER BY v.is_active DESC, v.vehicle_number
");
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ステータス選択肢
$statuses = [
    'active' => '運用中',
    'maintenance' => '整備中',
    'reserved' => '予約済み',
    'inactive' => '使用停止'
];

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => ['css/ui-unified-v3.css'],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '管理機能', 'url' => 'master_menu.php'],
        ['text' => '車両管理', 'url' => 'vehicle_management.php']
    ]
];

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

<main class="main-content">
    <div class="container-fluid">
        <!-- アラート -->
        <?php if ($success_message): ?>
        <?= renderAlert('success', $success_message, 'check-circle') ?>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <?= renderAlert('danger', $error_message, 'exclamation-triangle') ?>
        <?php endif; ?>
        
        <!-- システム名設定 -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-cog me-2"></i>システム基本設定</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="update_system_name">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <label for="system_name" class="form-label">システム名</label>
                            <input type="text" class="form-control" id="system_name" name="system_name" 
                                   value="<?= htmlspecialchars($current_system_name) ?>" required>
                            <small class="text-muted">ヘッダーやタイトルに表示されるシステム名</small>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>システム名を更新
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- 点検期限アラート -->
        <?php 
        $urgent_vehicles = array_filter($vehicles, function($v) { 
            return $v['is_active'] && in_array($v['inspection_status'], ['overdue', 'urgent']); 
        });
        if (!empty($urgent_vehicles)): 
        ?>
        <div class="alert alert-warning">
            <h6><i class="fas fa-exclamation-triangle me-2"></i>点検期限アラート</h6>
            <?php foreach ($urgent_vehicles as $vehicle): ?>
                <div>
                    <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong>: 
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
        <div class="mb-4">
            <button type="button" class="btn btn-primary" onclick="showAddModal()">
                <i class="fas fa-plus me-2"></i>新規車両追加
            </button>
        </div>
        
        <!-- 車両一覧 -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>車両一覧</h5>
            </div>
            <div class="card-body">
                <?php if (empty($vehicles)): ?>
                    <p class="text-muted text-center">車両が登録されていません。</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>車両番号</th>
                                    <th>車両名</th>
                                    <th>型式</th>
                                    <th>ステータス</th>
                                    <th>走行距離</th>
                                    <th>点検期限</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <tr class="<?= $vehicle['is_active'] ? '' : 'table-secondary' ?>">
                                    <td><strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td>
                                    <td><?= htmlspecialchars($vehicle['model'] ?? '未設定') ?></td>
                                    <td>
                                        <span class="badge bg-<?= $vehicle['status'] === 'active' ? 'success' : 'secondary' ?>">
                                            <?= $statuses[$vehicle['status']] ?>
                                        </span>
                                        <br>
                                        <span class="badge <?= $vehicle['is_active'] ? 'bg-success' : 'bg-secondary' ?> mt-1">
                                            <?= $vehicle['is_active'] ? '有効' : '無効' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="font-monospace">
                                            <?= number_format($vehicle['current_mileage']) ?> km
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($vehicle['next_inspection_date']): ?>
                                            <span class="badge 
                                                <?php
                                                switch($vehicle['inspection_status']) {
                                                    case 'ok': echo 'bg-success'; break;
                                                    case 'warning': echo 'bg-warning'; break;
                                                    case 'urgent': echo 'bg-danger text-white'; break;
                                                    case 'overdue': echo 'bg-dark'; break;
                                                }
                                                ?>">
                                                <?php
                                                switch($vehicle['inspection_status']) {
                                                    case 'ok': echo '点検OK'; break;
                                                    case 'warning': echo '要注意'; break;
                                                    case 'urgent': echo '緊急'; break;
                                                    case 'overdue': echo '期限切れ'; break;
                                                }
                                                ?>
                                            </span>
                                            <br>
                                            <small class="text-muted"><?= $vehicle['next_inspection_date'] ?></small>
                                            
                                            <!-- クイック点検日更新 -->
                                            <div class="mt-2">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="update_inspection">
                                                    <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
                                                    <div class="input-group input-group-sm" style="max-width: 200px;">
                                                        <input type="date" class="form-control" name="next_inspection_date" 
                                                               value="<?= $vehicle['next_inspection_date'] ?>">
                                                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">未設定</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    onclick="editVehicle(<?= htmlspecialchars(json_encode($vehicle)) ?>)"
                                                    title="編集">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" 
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
                            <label for="modalVehicleNumber" class="form-label">車両番号 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modalVehicleNumber" name="vehicle_number" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleName" class="form-label">車両名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modalVehicleName" name="vehicle_name" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="modalModel" class="form-label">車種・型式</label>
                            <input type="text" class="form-control" id="modalModel" name="model">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalCurrentMileage" class="form-label">現在走行距離</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="modalCurrentMileage" name="current_mileage" min="0" value="0">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalNextInspectionDate" class="form-label">次回点検日</label>
                            <input type="date" class="form-control" id="modalNextInspectionDate" name="next_inspection_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalStatus" class="form-label">ステータス <span class="text-danger">*</span></label>
                            <select class="form-select" id="modalStatus" name="status" required>
                                <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="activeField" style="display: none;">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="modalIsActive" name="is_active" checked>
                                <label class="form-check-label" for="modalIsActive">
                                    有効
                                </label>
                            </div>
                        </div>
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
    // 新規追加モーダル
    function showAddModal() {
        document.getElementById('vehicleModalTitle').textContent = '車両追加';
        document.getElementById('modalAction').value = 'add';
        document.getElementById('modalVehicleId').value = '';
        document.getElementById('activeField').style.display = 'none';
        
        // フォームリセット
        document.getElementById('vehicleForm').reset();
        document.getElementById('modalCurrentMileage').value = '0';
        
        new bootstrap.Modal(document.getElementById('vehicleModal')).show();
    }
    
    // 編集モーダル
    function editVehicle(vehicle) {
        document.getElementById('vehicleModalTitle').textContent = '車両編集';
        document.getElementById('modalAction').value = 'edit';
        document.getElementById('modalVehicleId').value = vehicle.id;
        document.getElementById('modalVehicleNumber').value = vehicle.vehicle_number;
        document.getElementById('modalVehicleName').value = vehicle.vehicle_name;
        document.getElementById('modalModel').value = vehicle.model || '';
        document.getElementById('modalCurrentMileage').value = vehicle.current_mileage;
        document.getElementById('modalNextInspectionDate').value = vehicle.next_inspection_date || '';
        document.getElementById('modalStatus').value = vehicle.status;
        document.getElementById('modalIsActive').checked = vehicle.is_active == 1;
        document.getElementById('activeField').style.display = 'block';
        
        new bootstrap.Modal(document.getElementById('vehicleModal')).show();
    }
    
    // 削除確認
    function deleteVehicle(vehicleId, vehicleNumber) {
        if (confirm(`車両「${vehicleNumber}」を削除しますか？`)) {
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
    
    // 3か月後の日付を自動設定
    function setDefaultInspectionDate() {
        const today = new Date();
        const threeMonthsLater = new Date(today.setMonth(today.getMonth() + 3));
        const dateString = threeMonthsLater.toISOString().split('T')[0];
        document.getElementById('modalNextInspectionDate').value = dateString;
    }
    
    // 新規追加時に3か月後を自動設定
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('modalNextInspectionDate').addEventListener('focus', function() {
            if (!this.value && document.getElementById('modalAction').value === 'add') {
                setDefaultInspectionDate();
            }
        });
    });
</script>

</body>
</html>
