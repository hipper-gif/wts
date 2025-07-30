<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

$success_message = '';
$error_message = '';

// 削除処理
if (isset($_POST['delete_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM daily_inspections WHERE id = ?");
        $stmt->execute([$_POST['delete_id']]);
        $success_message = '点検記録を削除しました。';
    } catch (Exception $e) {
        $error_message = '削除中にエラーが発生しました: ' . $e->getMessage();
    }
}

// 検索条件の設定
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // 今月の1日
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // 今日
$vehicle_filter = $_GET['vehicle_id'] ?? '';
$driver_filter = $_GET['driver_id'] ?? '';

// 車両とドライバーのリスト取得
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $vehicles = [];
    $drivers = [];
}

// 点検記録の取得
try {
    $sql = "SELECT 
                di.id,
                di.inspection_date,
                di.inspection_time,
                di.mileage,
                di.defect_details,
                di.remarks,
                di.created_at,
                u.name as driver_name,
                v.vehicle_number,
                v.model,
                di.foot_brake_result,
                di.parking_brake_result,
                di.brake_fluid_result,
                di.lights_result,
                di.lens_result,
                di.tire_pressure_result,
                di.tire_damage_result
            FROM daily_inspections di
            JOIN users u ON di.driver_id = u.id
            JOIN vehicles v ON di.vehicle_id = v.id
            WHERE di.inspection_date BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    
    if ($vehicle_filter) {
        $sql .= " AND di.vehicle_id = ?";
        $params[] = $vehicle_filter;
    }
    
    if ($driver_filter) {
        $sql .= " AND di.driver_id = ?";
        $params[] = $driver_filter;
    }
    
    $sql .= " ORDER BY di.inspection_date DESC, di.inspection_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error_message = 'データの取得中にエラーが発生しました: ' . $e->getMessage();
    $inspections = [];
}

// 点検結果の状況統計
function getInspectionStatus($inspection) {
    $required_items = [
        'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
        'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
    ];
    
    $ok_count = 0;
    $ng_count = 0;
    
    foreach ($required_items as $item) {
        if ($inspection[$item] == '可') {
            $ok_count++;
        } elseif ($inspection[$item] == '否') {
            $ng_count++;
        }
    }
    
    if ($ng_count > 0) {
        return ['status' => 'warning', 'text' => '要注意', 'color' => 'warning'];
    } elseif ($ok_count == count($required_items)) {
        return ['status' => 'ok', 'text' => '良好', 'color' => 'success'];
    } else {
        return ['status' => 'partial', 'text' => '一部省略', 'color' => 'info'];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>日常点検履歴・編集 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .search-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .search-card-header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 15px 15px 0 0;
            margin: 0;
        }
        
        .results-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .inspection-row {
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.2s ease;
        }
        
        .inspection-row:hover {
            background-color: #f8f9fa;
        }
        
        .inspection-row:last-child {
            border-bottom: none;
        }
        
        .status-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .table-responsive {
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.9rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                border-radius: 0.375rem !important;
                margin-bottom: 0.25rem;
            }
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-history me-2"></i>日常点検履歴・編集</h1>
                    <small>過去の点検記録の確認・編集・削除</small>
                </div>
                <div class="col-auto">
                    <a href="daily_inspection.php" class="btn btn-outline-light btn-sm me-2">
                        <i class="fas fa-tools me-1"></i>新規点検
                    </a>
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
        
        <!-- 検索フィルター -->
        <div class="search-card">
            <h5 class="search-card-header">
                <i class="fas fa-search me-2"></i>検索・フィルター
            </h5>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">開始日</label>
                        <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">終了日</label>
                        <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">車両</label>
                        <select class="form-select" name="vehicle_id">
                            <option value="">全ての車両</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= $vehicle['id'] ?>" <?= ($vehicle_filter == $vehicle['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                <?= $vehicle['model'] ? ' (' . htmlspecialchars($vehicle['model']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">運転手</label>
                        <select class="form-select" name="driver_id">
                            <option value="">全ての運転手</option>
                            <?php foreach ($drivers as $driver): ?>
                            <option value="<?= $driver['id'] ?>" <?= ($driver_filter == $driver['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($driver['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i>検索
                        </button>
                        <a href="daily_inspection_history.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-undo me-1"></i>リセット
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- 検索結果 -->
        <div class="results-card">
            <div class="card-header bg-light">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        検索結果: <?= count($inspections) ?>件
                    </h6>
                    <small class="text-muted">
                        <?= date('Y年n月j日', strtotime($start_date)) ?> ～ <?= date('Y年n月j日', strtotime($end_date)) ?>
                    </small>
                </div>
            </div>
            
            <?php if (empty($inspections)): ?>
            <div class="card-body text-center py-5">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">該当する点検記録がありません</h5>
                <p class="text-muted">検索条件を変更するか、新しい点検記録を作成してください。</p>
                <a href="daily_inspection.php" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i>新規点検記録作成
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>点検日</th>
                            <th>時間</th>
                            <th>運転手</th>
                            <th>車両</th>
                            <th>走行距離</th>
                            <th>点検状況</th>
                            <th>不良個所</th>
                            <th class="text-center">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inspections as $inspection): ?>
                        <?php $status = getInspectionStatus($inspection); ?>
                        <tr class="inspection-row">
                            <td>
                                <strong><?= date('n/j', strtotime($inspection['inspection_date'])) ?></strong>
                                <br>
                                <small class="text-muted"><?= date('(D)', strtotime($inspection['inspection_date'])) ?></small>
                            </td>
                            <td>
                                <?= $inspection['inspection_time'] ? date('H:i', strtotime($inspection['inspection_time'])) : '-' ?>
                            </td>
                            <td><?= htmlspecialchars($inspection['driver_name']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($inspection['vehicle_number']) ?></strong>
                                <?php if ($inspection['model']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($inspection['model']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $inspection['mileage'] ? number_format($inspection['mileage']) . 'km' : '-' ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $status['color'] ?> status-badge">
                                    <?= $status['text'] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($inspection['defect_details']): ?>
                                <i class="fas fa-exclamation-triangle text-warning" 
                                   data-bs-toggle="tooltip" 
                                   title="<?= htmlspecialchars($inspection['defect_details']) ?>"></i>
                                <?php else: ?>
                                <span class="text-muted">なし</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group" role="group">
                                    <a href="daily_inspection.php?inspector_id=<?= $inspection['driver_id'] ?>&vehicle_id=<?= $inspection['vehicle_id'] ?>&date=<?= $inspection['inspection_date'] ?>" 
                                       class="btn btn-outline-primary btn-sm" 
                                       data-bs-toggle="tooltip" title="編集">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" 
                                            class="btn btn-outline-info btn-sm" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#detailModal<?= $inspection['id'] ?>"
                                            title="詳細">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button type="button" 
                                            class="btn btn-outline-danger btn-sm" 
                                            onclick="confirmDelete(<?= $inspection['id'] ?>, '<?= date('n月j日', strtotime($inspection['inspection_date'])) ?>', '<?= htmlspecialchars($inspection['driver_name']) ?>', '<?= htmlspecialchars($inspection['vehicle_number']) ?>')"
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
    
    <!-- 詳細モーダル -->
    <?php foreach ($inspections as $inspection): ?>
    <div class="modal fade" id="detailModal<?= $inspection['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>
                        点検記録詳細 - <?= date('Y年n月j日', strtotime($inspection['inspection_date'])) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>基本情報</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">点検日時</th>
                                    <td>
                                        <?= date('Y年n月j日', strtotime($inspection['inspection_date'])) ?>
                                        <?= $inspection['inspection_time'] ? ' ' . date('H:i', strtotime($inspection['inspection_time'])) : '' ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>運転手</th>
                                    <td><?= htmlspecialchars($inspection['driver_name']) ?></td>
                                </tr>
                                <tr>
                                    <th>車両</th>
                                    <td>
                                        <?= htmlspecialchars($inspection['vehicle_number']) ?>
                                        <?= $inspection['model'] ? ' (' . htmlspecialchars($inspection['model']) . ')' : '' ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>走行距離</th>
                                    <td><?= $inspection['mileage'] ? number_format($inspection['mileage']) . 'km' : '-' ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>主要点検項目</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th width="60%">フットブレーキ</th>
                                    <td><span class="badge bg-<?= $inspection['foot_brake_result'] == '可' ? 'success' : ($inspection['foot_brake_result'] == '否' ? 'danger' : 'warning') ?>"><?= $inspection['foot_brake_result'] ?></span></td>
                                </tr>
                                <tr>
                                    <th>パーキングブレーキ</th>
                                    <td><span class="badge bg-<?= $inspection['parking_brake_result'] == '可' ? 'success' : ($inspection['parking_brake_result'] == '否' ? 'danger' : 'warning') ?>"><?= $inspection['parking_brake_result'] ?></span></td>
                                </tr>
                                <tr>
                                    <th>ブレーキ液量</th>
                                    <td><span class="badge bg-<?= $inspection['brake_fluid_result'] == '可' ? 'success' : ($inspection['brake_fluid_result'] == '否' ? 'danger' : 'warning') ?>"><?= $inspection['brake_fluid_result'] ?></span></td>
                                </tr>
                                <tr>
                                    <th>灯火類</th>
                                    <td><span class="badge bg-<?= $inspection['lights_result'] == '可' ? 'success' : ($inspection['lights_result'] == '否' ? 'danger' : 'warning') ?>"><?= $inspection['lights_result'] ?></span></td>
                                </tr>
                                <tr>
                                    <th>タイヤ空気圧</th>
                                    <td><span class="badge bg-<?= $inspection['tire_pressure_result'] == '可' ? 'success' : ($inspection['tire_pressure_result'] == '否' ? 'danger' : 'warning') ?>"><?= $inspection['tire_pressure_result'] ?></span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($inspection['defect_details'] || $inspection['remarks']): ?>
                    <div class="mt-3">
                        <?php if ($inspection['defect_details']): ?>
                        <h6>不良個所及び処置</h6>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?= nl2br(htmlspecialchars($inspection['defect_details'])) ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($inspection['remarks']): ?>
                        <h6>備考</h6>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <?= nl2br(htmlspecialchars($inspection['remarks'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i>
                            登録日時: <?= date('Y年n月j日 H:i', strtotime($inspection['created_at'])) ?>
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="daily_inspection.php?inspector_id=<?= $inspection['driver_id'] ?>&vehicle_id=<?= $inspection['vehicle_id'] ?>&date=<?= $inspection['inspection_date'] ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-edit me-1"></i>編集
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- 削除確認モーダル -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>点検記録の削除
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>警告:</strong> この操作は取り消せません。
                    </div>
                    <p>以下の点検記録を削除しますか？</p>
                    <div class="card">
                        <div class="card-body">
                            <div id="deleteInfo">
                                <!-- JavaScriptで動的に設定 -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="deleteForm">
                        <input type="hidden" name="delete_id" id="deleteId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>削除する
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ツールチップの初期化
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // 削除確認ダイアログ
        function confirmDelete(id, date, driverName, vehicleNumber) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteInfo').innerHTML = `
                <strong>点検日:</strong> ${date}<br>
                <strong>運転手:</strong> ${driverName}<br>
                <strong>車両:</strong> ${vehicleNumber}
            `;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
        
        // 検索フォームの期間チェック
        document.querySelector('form').addEventListener('submit', function(e) {
            const startDate = new Date(document.querySelector('input[name="start_date"]').value);
            const endDate = new Date(document.querySelector('input[name="end_date"]').value);
            
            if (startDate > endDate) {
                e.preventDefault();
                alert('終了日は開始日以降の日付を選択してください。');
                return false;
            }
            
            // 期間が長すぎる場合の警告（3ヶ月以上）
            const diffTime = Math.abs(endDate - startDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            if (diffDays > 90) {
                if (!confirm('検索期間が3ヶ月を超えています。データが多い場合、表示に時間がかかる可能性があります。続行しますか？')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
        
        // 今月のクイック選択
        function setThisMonth() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            
            document.querySelector('input[name="start_date"]').value = formatDate(firstDay);
            document.querySelector('input[name="end_date"]').value = formatDate(lastDay);
        }
        
        // 先月のクイック選択
        function setLastMonth() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth(), 0);
            
            document.querySelector('input[name="start_date"]').value = formatDate(firstDay);
            document.querySelector('input[name="end_date"]').value = formatDate(lastDay);
        }
        
        // 日付フォーマット関数
        function formatDate(date) {
            return date.getFullYear() + '-' + 
                   String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                   String(date.getDate()).padStart(2, '0');
        }
        
        // クイック期間選択ボタンの追加
        document.addEventListener('DOMContentLoaded', function() {
            const searchButton = document.querySelector('button[type="submit"]');
            const quickButtonsHtml = `
                <button type="button" class="btn btn-outline-info btn-sm ms-2" onclick="setThisMonth(); this.form.submit();">
                    <i class="fas fa-calendar me-1"></i>今月
                </button>
                <button type="button" class="btn btn-outline-info btn-sm ms-1" onclick="setLastMonth(); this.form.submit();">
                    <i class="fas fa-calendar-minus me-1"></i>先月
                </button>
            `;
            searchButton.insertAdjacentHTML('afterend', quickButtonsHtml);
        });
        
        // 詳細モーダルでのエスケープキー対応
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    const modalInstance = bootstrap.Modal.getInstance(modal);
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                });
            }
        });
        
        // 表の行クリックで詳細表示（モバイル対応）
        if (window.innerWidth <= 768) {
            document.querySelectorAll('.inspection-row').forEach(row => {
                row.style.cursor = 'pointer';
                row.addEventListener('click', function(e) {
                    if (!e.target.closest('.btn-group')) {
                        const detailButton = this.querySelector('[data-bs-target^="#detailModal"]');
                        if (detailButton) {
                            detailButton.click();
                        }
                    }
                });
            });
        }
    </script>
</body>
</html>
