<?php
session_start();
require_once 'config/database.php';

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

// 現在のシステム名を取得
$current_system_name = '福祉輸送管理システム'; // デフォルト値
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) {
        $current_system_name = $result['setting_value'];
    }
} catch (Exception $e) {
    // system_settingsテーブルが存在しない場合は作成
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // デフォルト値を挿入
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('system_name', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$current_system_name]);
    } catch (Exception $e2) {
        error_log("System settings table creation error: " . $e2->getMessage());
    }
}

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
            
            // 車両追加（license_plateカラムを除外）
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
            
            // 車両更新（license_plateカラムを除外）
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>車両・システム管理 - <?= htmlspecialchars($current_system_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1rem 1.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            border-radius: 25px;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #138496 0%, #0e6674 100%);
            transform: translateY(-1px);
        }
        
        .vehicle-row {
            border-left: 4px solid #17a2b8;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .vehicle-row:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .vehicle-row.inactive {
            opacity: 0.6;
            border-left-color: #6c757d;
        }
        
        .status-badge {
            font-size: 0.8em;
            padding: 4px 12px;
            border-radius: 20px;
        }
        
        .status-active { background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); }
        .status-maintenance { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); }
        .status-reserved { background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); }
        .status-inactive { background: linear-gradient(135deg, #6c757d 0%, #545b62 100%); }
        
        .inspection-status {
            font-size: 0.8em;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: bold;
        }
        
        .inspection-ok { background-color: #d4edda; color: #155724; }
        .inspection-warning { background-color: #fff3cd; color: #856404; }
        .inspection-urgent { background-color: #f8d7da; color: #721c24; }
        .inspection-overdue { background-color: #dc3545; color: white; }
        .inspection-unknown { background-color: #e2e3e5; color: #495057; }
        
        .mileage-display {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #495057;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #17a2b8;
            box-shadow: 0 0 0 0.2rem rgba(23, 162, 184, 0.25);
        }
        
        .quick-inspection-update {
            background: #e3f2fd;
            padding: 0.5rem;
            border-radius: 6px;
            margin-top: 0.5rem;
        }
        
        .system-name-display {
            font-size: 1.2em;
            font-weight: bold;
            color: #17a2b8;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-car me-2"></i>車両・システム管理</h1>
                    <small>車両情報とシステム基本設定の管理</small>
                    <div class="system-name-display mt-1">
                        <i class="fas fa-cog me-1"></i>現在のシステム名: <?= htmlspecialchars($current_system_name) ?>
                    </div>
                </div>
                <div class="col-auto">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>ダッシュボード
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container mt-4">
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
        
        <!-- システム名設定 -->
        <div class="card mb-4">
            <div class="card-header">
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
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>車両一覧</h5>
            </div>
            <div class="card-body">
                <?php if (empty($vehicles)): ?>
                    <p class="text-muted text-center">車両が登録されていません。</p>
                <?php else: ?>
                    <?php foreach ($vehicles as $vehicle): ?>
                    <div class="vehicle-row <?= $vehicle['is_active'] ? '' : 'inactive' ?>">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <h6 class="mb-1"><?= htmlspecialchars($vehicle['vehicle_number']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($vehicle['vehicle_name']) ?></small>
                                <?php if ($vehicle['model']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($vehicle['model']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2">
                                <span class="badge status-badge text-white status-<?= $vehicle['status'] ?>">
                                    <?= $statuses[$vehicle['status']] ?>
                                </span>
                                <br>
                                <span class="badge <?= $vehicle['is_active'] ? 'bg-success' : 'bg-secondary' ?> mt-1">
                                    <?= $vehicle['is_active'] ? '有効' : '無効' ?>
                                </span>
                            </div>
                            <div class="col-md-2">
                                <div class="mileage-display">
                                    <?= number_format($vehicle['current_mileage']) ?> km
                                </div>
                            </div>
                            <div class="col-md-2">
                                <?php if ($vehicle['next_inspection_date']): ?>
                                    <span class="inspection-status inspection-<?= $vehicle['inspection_status'] ?>">
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
                                    <div class="quick-inspection-update">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="update_inspection">
                                            <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
                                            <div class="input-group input-group-sm">
                                                <input type="date" class="form-control" name="next_inspection_date" 
                                                       value="<?= $vehicle['next_inspection_date'] ?>">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="inspection-status inspection-unknown">未設定</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3 text-end">
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editVehicle(<?= htmlspecialchars(json_encode($vehicle)) ?>)"
                                            title="編集">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteVehicle(<?= $vehicle['id'] ?>, '<?= htmlspecialchars($vehicle['vehicle_number']) ?>')"
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