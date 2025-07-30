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

// 検索条件
$search_date_from = $_GET['date_from'] ?? '';
$search_date_to = $_GET['date_to'] ?? '';
$search_vehicle_id = $_GET['vehicle_id'] ?? '';
$search_driver_id = $_GET['driver_id'] ?? '';

// 初期値設定（過去30日）
if (!$search_date_from && !$search_date_to) {
    $search_date_to = date('Y-m-d');
    $search_date_from = date('Y-m-d', strtotime('-30 days'));
}

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delete') {
    $delete_id = $_POST['inspection_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM daily_inspections WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success_message = '点検記録を削除しました。';
    } catch (Exception $e) {
        $error_message = '削除中にエラーが発生しました: ' . $e->getMessage();
    }
}

// 車両・運転者リスト取得
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

// 検索条件に基づく点検記録取得
$where_conditions = [];
$params = [];

if ($search_date_from) {
    $where_conditions[] = "di.inspection_date >= ?";
    $params[] = $search_date_from;
}

if ($search_date_to) {
    $where_conditions[] = "di.inspection_date <= ?";
    $params[] = $search_date_to;
}

if ($search_vehicle_id) {
    $where_conditions[] = "di.vehicle_id = ?";
    $params[] = $search_vehicle_id;
}

if ($search_driver_id) {
    $where_conditions[] = "di.driver_id = ?";
    $params[] = $search_driver_id;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $sql = "
        SELECT di.*, 
               u.name as driver_name,
               v.vehicle_number, v.model as vehicle_model
        FROM daily_inspections di
        LEFT JOIN users u ON di.driver_id = u.id
        LEFT JOIN vehicles v ON di.vehicle_id = v.id
        {$where_clause}
        ORDER BY di.inspection_date DESC, di.inspection_time DESC
        LIMIT 100
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = '記録の取得中にエラーが発生しました: ' . $e->getMessage();
    $inspections = [];
}

// 結果判定関数
function getInspectionResult($inspection) {
    $required_items = [
        'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
        'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
    ];
    
    $ng_count = 0;
    foreach ($required_items as $item) {
        if (isset($inspection[$item]) && $inspection[$item] === '否') {
            $ng_count++;
        }
    }
    
    if ($ng_count > 0) {
        return ['status' => 'ng', 'label' => '要整備', 'class' => 'bg-danger'];
    } else {
        return ['status' => 'ok', 'label' => '良好', 'class' => 'bg-success'];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>日常点検記録履歴 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #6f42c1 0%, #5a2d91 100%);
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
            background: linear-gradient(135deg, #6f42c1 0%, #5a2d91 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1rem 1.5rem;
        }
        
        .inspection-row {
            border-left: 4px solid #6f42c1;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .inspection-row:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .inspection-row.ng {
            border-left-color: #dc3545;
        }
        
        .inspection-row.ok {
            border-left-color: #28a745;
        }
        
        .status-badge {
            font-size: 0.8em;
            padding: 4px 12px;
            border-radius: 20px;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
        }
        
        .btn-edit:hover {
            background: linear-gradient(135deg, #138496 0%, #0e6674 100%);
            color: white;
        }
        
        .search-card {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 15px;
            margin-bottom: 2rem;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .inspection-summary {
            font-size: 0.9em;
            color: #6c757d;
            margin-top: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .inspection-row {
                padding: 0.75rem;
            }
            
            .btn-group {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                width: 100%;
            }
            
            .btn-group .btn {
                width: 100%;
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
                    <h1><i class="fas fa-history me-2"></i>日常点検記録履歴</h1>
                    <small>過去の点検記録の確認・編集・削除</small>
                </div>
                <div class="col-auto">
                    <div class="btn-group">
                        <a href="daily_inspection.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-plus me-1"></i>新規点検
                        </a>
                        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>ダッシュボード
                        </a>
                    </div>
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
        
        <!-- 検索フォーム -->
        <div class="search-card">
            <div class="card-body">
                <h5 class="card-title"><i class="fas fa-search me-2"></i>検索条件</h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">開始日</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" 
                               value="<?= htmlspecialchars($search_date_from) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">終了日</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" 
                               value="<?= htmlspecialchars($search_date_to) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="vehicle_id" class="form-label">車両</label>
                        <select class="form-select" id="vehicle_id" name="vehicle_id">
                            <option value="">全車両</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= $vehicle['id'] ?>" <?= ($search_vehicle_id == $vehicle['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                <?= $vehicle['model'] ? ' (' . htmlspecialchars($vehicle['model']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="driver_id" class="form-label">点検者</label>
                        <select class="form-select" id="driver_id" name="driver_id">
                            <option value="">全員</option>
                            <?php foreach ($drivers as $driver): ?>
                            <option value="<?= $driver['id'] ?>" <?= ($search_driver_id == $driver['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($driver['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>検索
                        </button>
                        <a href="daily_inspection_history.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-undo me-2"></i>リセット
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- 検索結果 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>点検記録一覧
                    <span class="badge bg-light text-dark ms-2"><?= count($inspections) ?>件</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($inspections)): ?>
                    <div class="no-data">
                        <i class="fas fa-search fa-3x mb-3"></i>
                        <h5>該当する記録が見つかりません</h5>
                        <p>検索条件を変更して再度お試しください。</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($inspections as $inspection): ?>
                        <?php $result = getInspectionResult($inspection); ?>
                        <div class="inspection-row <?= $result['status'] ?>">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <h6 class="mb-1">
                                        <?= htmlspecialchars($inspection['inspection_date']) ?>
                                        <small class="text-muted"><?= htmlspecialchars($inspection['inspection_time']) ?></small>
                                    </h6>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($inspection['vehicle_number']) ?>
                                        <?= $inspection['vehicle_model'] ? ' (' . htmlspecialchars($inspection['vehicle_model']) . ')' : '' ?>
                                    </small>
                                </div>
                                <div class="col-md-2">
                                    <strong><?= htmlspecialchars($inspection['driver_name']) ?></strong>
                                    <small class="d-block text-muted">点検者</small>
                                </div>
                                <div class="col-md-2">
                                    <span class="badge status-badge text-white <?= $result['class'] ?>">
                                        <?= $result['label'] ?>
                                    </span>
                                    <?php if ($inspection['mileage']): ?>
                                        <div class="inspection-summary">
                                            走行距離: <?= number_format($inspection['mileage']) ?>km
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2">
                                    <?php if ($inspection['defect_details']): ?>
                                        <small class="text-warning">
                                            <i class="fas fa-exclamation-triangle me-1"></i>不良個所あり
                                        </small>
                                    <?php endif; ?>
                                    <?php if ($inspection['remarks']): ?>
                                        <small class="text-info d-block">
                                            <i class="fas fa-comment me-1"></i>備考あり
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3 text-end">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="viewDetail(<?= $inspection['id'] ?>)"
                                                title="詳細表示">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="daily_inspection_edit.php?id=<?= $inspection['id'] ?>" 
                                           class="btn btn-sm btn-edit"
                                           title="編集">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="confirmDelete(<?= $inspection['id'] ?>, '<?= htmlspecialchars($inspection['inspection_date']) ?>', '<?= htmlspecialchars($inspection['vehicle_number']) ?>')"
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
    
    <!-- 詳細表示モーダル -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">点検記録詳細</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <!-- 詳細内容がここに表示される -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 削除確認フォーム -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="inspection_id" id="deleteInspectionId">
    </form>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 詳細表示
        function viewDetail(inspectionId) {
            // 該当の点検記録データを検索
            const inspections = <?= json_encode($inspections) ?>;
            const inspection = inspections.find(item => item.id == inspectionId);
            
            if (!inspection) {
                alert('記録が見つかりません。');
                return;
            }
            
            // 詳細表示用HTML生成
            let detailHtml = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>基本情報</h6>
                        <table class="table table-sm">
                            <tr><td>点検日時</td><td>${inspection.inspection_date} ${inspection.inspection_time || ''}</td></tr>
                            <tr><td>車両</td><td>${inspection.vehicle_number} ${inspection.vehicle_model || ''}</td></tr>
                            <tr><td>点検者</td><td>${inspection.driver_name}</td></tr>
                            <tr><td>走行距離</td><td>${inspection.mileage ? Number(inspection.mileage).toLocaleString() + 'km' : '未記録'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>点検結果</h6>
                        <div class="inspection-results">
            `;
            
            // 点検項目の結果表示
            const items = {
                'foot_brake_result': 'フットブレーキ',
                'parking_brake_result': 'パーキングブレーキ',
                'engine_start_result': 'エンジン始動',
                'engine_performance_result': 'エンジン性能',
                'wiper_result': 'ワイパー',
                'washer_spray_result': 'ウォッシャー',
                'brake_fluid_result': 'ブレーキ液',
                'coolant_result': '冷却水',
                'engine_oil_result': 'エンジンオイル',
                'battery_fluid_result': 'バッテリー液',
                'washer_fluid_result': 'ウォッシャー液',
                'fan_belt_result': 'ファンベルト',
                'lights_result': '灯火類',
                'lens_result': 'レンズ',
                'tire_pressure_result': 'タイヤ空気圧',
                'tire_damage_result': 'タイヤ損傷',
                'tire_tread_result': 'タイヤ溝'
            };
            
            for (const [key, label] of Object.entries(items)) {
                const result = inspection[key] || '未実施';
                const badgeClass = result === '可' ? 'bg-success' : result === '否' ? 'bg-danger' : 'bg-warning';
                detailHtml += `<span class="badge ${badgeClass} me-1 mb-1">${label}: ${result}</span>`;
            }
            
            detailHtml += `</div></div></div>`;
            
            // 不良個所・備考
            if (inspection.defect_details || inspection.remarks) {
                detailHtml += `
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>詳細情報</h6>
                `;
                
                if (inspection.defect_details) {
                    detailHtml += `
                        <div class="mb-2">
                            <strong>不良個所及び処置:</strong>
                            <p class="text-muted">${inspection.defect_details}</p>
                        </div>
                    `;
                }
                
                if (inspection.remarks) {
                    detailHtml += `
                        <div class="mb-2">
                            <strong>備考:</strong>
                            <p class="text-muted">${inspection.remarks}</p>
                        </div>
                    `;
                }
                
                detailHtml += `</div></div>`;
            }
            
            document.getElementById('detailContent').innerHTML = detailHtml;
            new bootstrap.Modal(document.getElementById('detailModal')).show();
        }
        
        // 削除確認
        function confirmDelete(inspectionId, date, vehicleNumber) {
            if (confirm(`${date} の ${vehicleNumber} の点検記録を削除しますか？\n\nこの操作は元に戻せません。`)) {
                document.getElementById('deleteInspectionId').value = inspectionId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        // 検索フォームの便利機能
        document.addEventListener('DOMContentLoaded', function() {
            // 今日ボタン
            const dateFromInput = document.getElementById('date_from');
            const dateToInput = document.getElementById('date_to');
            
            // デフォルト値設定（過去30日）
            if (!dateFromInput.value && !dateToInput.value) {
                const today = new Date();
                const thirtyDaysAgo = new Date(today.setDate(today.getDate() - 30));
                
                dateFromInput.value = thirtyDaysAgo.toISOString().split('T')[0];
                dateToInput.value = new Date().toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>
