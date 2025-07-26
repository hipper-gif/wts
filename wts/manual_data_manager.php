<?php
session_start();
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック（管理者のみ）
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$table = $_GET['table'] ?? 'ride_records';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$action = $_POST['action'] ?? '';

$message = '';
$error = '';

// テーブル設定
$tables = [
    'ride_records' => [
        'name' => '乗車記録',
        'icon' => 'fas fa-users',
        'date_column' => 'ride_date',
        'columns' => ['id', 'ride_date', 'ride_time', 'pickup_location', 'dropoff_location', 'fare', 'charge', 'transport_category', 'payment_method', 'passenger_count', 'notes'],
        'display_columns' => ['日時', '乗車地', '降車地', '運賃', '料金', '分類', '支払', '人数', '備考'],
        'editable' => ['ride_time', 'pickup_location', 'dropoff_location', 'fare', 'charge', 'transport_category', 'payment_method', 'passenger_count', 'notes']
    ],
    'daily_inspections' => [
        'name' => '日常点検',
        'icon' => 'fas fa-tools',
        'date_column' => 'inspection_date',
        'columns' => ['id', 'inspection_date', 'vehicle_id', 'driver_id', 'foot_brake_condition', 'parking_brake_condition', 'defect_details'],
        'display_columns' => ['点検日', '車両ID', '運転者ID', 'フットブレーキ', 'パーキングブレーキ', '不良箇所'],
        'editable' => ['foot_brake_condition', 'parking_brake_condition', 'defect_details']
    ],
    'pre_duty_calls' => [
        'name' => '乗務前点呼',
        'icon' => 'fas fa-clipboard-check',
        'date_column' => 'call_date',
        'columns' => ['id', 'call_date', 'call_time', 'driver_id', 'vehicle_id', 'caller_name', 'alcohol_check_value', 'is_completed'],
        'display_columns' => ['点呼日', '点呼時刻', '運転者ID', '車両ID', '点呼者', 'アルコール値', '完了'],
        'editable' => ['call_time', 'caller_name', 'alcohol_check_value']
    ],
    'post_duty_calls' => [
        'name' => '乗務後点呼',
        'icon' => 'fas fa-clipboard-check',
        'date_column' => 'call_date',
        'columns' => ['id', 'call_date', 'call_time', 'driver_id', 'vehicle_id', 'caller_name', 'alcohol_check_value', 'is_completed'],
        'display_columns' => ['点呼日', '点呼時刻', '運転者ID', '車両ID', '点呼者', 'アルコール値', '完了'],
        'editable' => ['call_time', 'caller_name', 'alcohol_check_value']
    ],
    'departure_records' => [
        'name' => '出庫記録',
        'icon' => 'fas fa-sign-out-alt',
        'date_column' => 'departure_date',
        'columns' => ['id', 'departure_date', 'departure_time', 'driver_id', 'vehicle_id', 'weather', 'departure_mileage'],
        'display_columns' => ['出庫日', '出庫時刻', '運転者ID', '車両ID', '天候', '出庫メーター'],
        'editable' => ['departure_time', 'weather', 'departure_mileage']
    ],
    'arrival_records' => [
        'name' => '入庫記録',
        'icon' => 'fas fa-sign-in-alt',
        'date_column' => 'arrival_date',
        'columns' => ['id', 'arrival_date', 'arrival_time', 'driver_id', 'vehicle_id', 'arrival_mileage', 'total_distance', 'fuel_cost'],
        'display_columns' => ['入庫日', '入庫時刻', '運転者ID', '車両ID', '入庫メーター', '走行距離', '燃料代'],
        'editable' => ['arrival_time', 'arrival_mileage', 'fuel_cost']
    ],
    'users' => [
        'name' => 'ユーザー',
        'icon' => 'fas fa-user',
        'date_column' => 'created_at',
        'columns' => ['id', 'name', 'login_id', 'role', 'is_active', 'created_at'],
        'display_columns' => ['名前', 'ログインID', '権限', '有効', '作成日'],
        'editable' => ['name', 'role', 'is_active']
    ],
    'vehicles' => [
        'name' => '車両',
        'icon' => 'fas fa-car',
        'date_column' => 'created_at',
        'columns' => ['id', 'vehicle_number', 'vehicle_name', 'total_mileage', 'status', 'created_at'],
        'display_columns' => ['車両番号', '車両名', '総走行距離', 'ステータス', '作成日'],
        'editable' => ['vehicle_number', 'vehicle_name', 'total_mileage', 'status']
    ]
];

if (!isset($tables[$table])) {
    $table = 'ride_records';
}

$table_config = $tables[$table];

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'update') {
            // レコード更新
            $id = $_POST['id'];
            $updates = [];
            $params = [];
            
            foreach ($table_config['editable'] as $column) {
                if (isset($_POST[$column])) {
                    $updates[] = "{$column} = ?";
                    $params[] = $_POST[$column];
                }
            }
            
            if (!empty($updates)) {
                $params[] = $id;
                $sql = "UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $message = 'レコードを更新しました。';
            }
            
        } elseif ($action === 'delete') {
            // 単一削除
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'レコードを削除しました。';
            
        } elseif ($action === 'bulk_delete') {
            // 一括削除
            $ids = $_POST['selected_ids'] ?? [];
            if (!empty($ids)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
                $stmt->execute($ids);
                $message = count($ids) . '件のレコードを削除しました。';
            }
        }
    } catch (Exception $e) {
        $error = 'エラー: ' . $e->getMessage();
    }
}

// データ取得
$where_conditions = ['1 = 1'];
$params = [];

// 検索条件
if ($search) {
    $search_conditions = [];
    if ($table === 'ride_records') {
        $search_conditions[] = "pickup_location LIKE ?";
        $search_conditions[] = "dropoff_location LIKE ?";
        $search_conditions[] = "notes LIKE ?";
        $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
    } elseif ($table === 'users') {
        $search_conditions[] = "name LIKE ?";
        $search_conditions[] = "login_id LIKE ?";
        $params = array_merge($params, ["%{$search}%", "%{$search}%"]);
    } else {
        // 他のテーブルは最初のテキストカラムで検索
        $first_text_column = '';
        foreach ($table_config['columns'] as $col) {
            if (!in_array($col, ['id', 'created_at', 'updated_at']) && !preg_match('/_id$/', $col)) {
                $first_text_column = $col;
                break;
            }
        }
        if ($first_text_column) {
            $search_conditions[] = "{$first_text_column} LIKE ?";
            $params[] = "%{$search}%";
        }
    }
    
    if (!empty($search_conditions)) {
        $where_conditions[] = '(' . implode(' OR ', $search_conditions) . ')';
    }
}

// 日付範囲
if ($date_from && $table_config['date_column']) {
    $where_conditions[] = "{$table_config['date_column']} >= ?";
    $params[] = $date_from;
}
if ($date_to && $table_config['date_column']) {
    $where_conditions[] = "{$table_config['date_column']} <= ?";
    $params[] = $date_to;
}

$where_clause = implode(' AND ', $where_conditions);

// 総件数取得
$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// データ取得
$order_column = $table_config['date_column'] ?? 'id';
$sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$order_column} DESC LIMIT {$limit} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 関連データ取得（ユーザー名、車両名など）
$users_map = [];
$vehicles_map = [];

if (in_array('driver_id', $table_config['columns']) || in_array('vehicle_id', $table_config['columns'])) {
    $stmt = $pdo->query("SELECT id, name FROM users");
    $users_map = array_column($stmt->fetchAll(), 'name', 'id');
    
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles");
    $vehicles_map = array_column($stmt->fetchAll(), 'vehicle_number', 'id');
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>手動データ管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .table-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .table th { background-color: #f8f9fa; position: sticky; top: 0; z-index: 10; }
        .table-hover tbody tr:hover { background-color: #f5f5f5; }
        .edit-row { background-color: #fff3cd !important; }
        .btn-sm { font-size: 0.8rem; padding: 0.25rem 0.5rem; }
        .search-form { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .table-nav { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0; padding: 15px; }
        .danger-zone { background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; padding: 15px; margin: 20px 0; }
        .selected-row { background-color: #cfe2ff !important; }
        .edit-input { width: 100%; min-width: 80px; }
        .fixed-width { width: 120px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-edit me-2"></i>手動データ管理
            </a>
            <div class="navbar-nav ms-auto">
                <a href="safe_check.php" class="nav-link">
                    <i class="fas fa-shield-alt me-1"></i>安全確認
                </a>
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home me-1"></i>ダッシュボード
                </a>
            </div>

    <!-- 一括編集モーダル -->
    <div class="modal fade" id="batchEditModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>一括編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="batchEditForm">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            選択した条件に一致するレコードを一括で更新します。
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">更新対象フィールド</label>
                                <select class="form-select" id="batchField" name="batch_field">
                                    <?php foreach ($table_config['editable'] as $field): ?>
                                        <option value="<?= $field ?>"><?= $field ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">新しい値</label>
                                <input type="text" class="form-control" id="batchValue" name="batch_value">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">条件（WHERE句）</label>
                            <textarea class="form-control" id="batchCondition" name="batch_condition" rows="3" 
                                      placeholder="例: ride_date >= '2024-01-01' AND transport_category = '通院'"></textarea>
                            <small class="text-muted">SQL WHERE句の条件を入力してください</small>
                        </div>
                        
                        <div class="preview-area" id="batchPreview" style="display: none;">
                            <h6>プレビュー（最初の10件）</h6>
                            <div class="table-responsive">
                                <table class="table table-sm" id="batchPreviewTable">
                                    <thead><tr><th>ID</th><th>現在の値</th><th>新しい値</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-info" onclick="previewBatchEdit()">プレビュー</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="button" class="btn btn-primary" onclick="executeBatchEdit()">実行</button>
                </div>
            </div>
        </div>
    </div>

    <!-- データ分析モーダル -->
    <div class="modal fade" id="dataAnalysisModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-bar me-2"></i>データ分析</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header"><h6>基本統計</h6></div>
                                <div class="card-body" id="basicStats">
                                    <div class="text-center">
                                        <div class="spinner-border" role="status"></div>
                                        <p>分析中...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header"><h6>データ品質</h6></div>
                                <div class="card-body" id="dataQuality">
                                    <div class="text-center">
                                        <div class="spinner-border" role="status"></div>
                                        <p>確認中...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header"><h6>分布グラフ</h6></div>
                                <div class="card-body">
                                    <canvas id="distributionChart" width="400" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CSVインポートモーダル -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-upload me-2"></i>CSVインポート</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="importForm" enctype="multipart/form-data">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>注意:</strong> インポート前に必ずバックアップを作成してください。
                        </div>
                        
                        <div class="mb-3">
                            <label for="csvFile" class="form-label">CSVファイル</label>
                            <input type="file" class="form-control" id="csvFile" name="csv_file" accept=".csv" required>
                            <small class="text-muted">UTF-8エンコードのCSVファイルを選択してください</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hasHeader" name="has_header" checked>
                                <label class="form-check-label" for="hasHeader">
                                    1行目はヘッダー行
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">インポートモード</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="import_mode" value="insert" id="modeInsert" checked>
                                <label class="form-check-label" for="modeInsert">新規追加のみ</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="import_mode" value="update" id="modeUpdate">
                                <label class="form-check-label" for="modeUpdate">IDが一致する場合は更新</label>
                            </div>
                        </div>
                        
                        <div class="preview-area" id="importPreview" style="display: none;">
                            <h6>プレビュー（最初の5行）</h6>
                            <div class="table-responsive">
                                <table class="table table-sm" id="importPreviewTable">
                                    <thead></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-info" onclick="previewImport()">プレビュー</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="button" class="btn btn-primary" onclick="executeImport()">インポート実行</button>
                </div>
            </div>
        </div>
    </div>

    <!-- データ検証モーダル -->
    <div class="modal fade" id="validationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>データ検証</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-3">
                        <button type="button" class="btn btn-primary" onclick="runValidation()">
                            <i class="fas fa-play me-1"></i>検証実行
                        </button>
                        <button type="button" class="btn btn-success" onclick="autoFixIssues()">
                            <i class="fas fa-magic me-1"></i>自動修正
                        </button>
                    </div>
                    
                    <div id="validationResults">
                        <div class="text-center text-muted">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>検証実行ボタンを押してデータを確認してください</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 保存検索モーダル -->
    <div class="modal fade" id="savedSearchModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bookmark me-2"></i>保存検索</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary" onclick="saveCurrentSearch()">
                            <i class="fas fa-save me-1"></i>現在の検索を保存
                        </button>
                    </div>
                    
                    <div class="list-group" id="savedSearchList">
                        <!-- 保存された検索がここに表示されます -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 重複検出モーダル -->
    <div class="modal fade" id="duplicateModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-clone me-2"></i>重複検出</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary" onclick="detectDuplicates()">
                            <i class="fas fa-search me-1"></i>重複を検出
                        </button>
                        <button type="button" class="btn btn-warning" onclick="mergeDuplicates()" style="display: none;" id="mergeBtn">
                            <i class="fas fa-compress-alt me-1"></i>選択項目をマージ
                        </button>
                    </div>
                    
                    <div id="duplicateResults">
                        <div class="text-center text-muted">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p>重複検出ボタンを押してデータを確認してください</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- テーブル選択タブ -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-database me-2"></i>データ管理対象</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($tables as $table_key => $table_info): ?>
                                <div class="col-md-3 col-sm-6 mb-2">
                                    <a href="?table=<?= $table_key ?>" 
                                       class="btn <?= ($table === $table_key) ? 'btn-primary' : 'btn-outline-primary' ?> w-100">
                                        <i class="<?= $table_info['icon'] ?> me-2"></i><?= $table_info['name'] ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 機能拡張メニュー -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-tools me-2"></i>追加機能</h6>
                    </div>
                    <div class="card-body py-2">
                        <div class="btn-toolbar" role="toolbar">
                            <div class="btn-group me-2" role="group">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="showBatchEdit()">
                                    <i class="fas fa-edit me-1"></i>一括編集
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="showDataAnalysis()">
                                    <i class="fas fa-chart-bar me-1"></i>データ分析
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="showImportModal()">
                                    <i class="fas fa-upload me-1"></i>CSVインポート
                                </button>
                            </div>
                            <div class="btn-group me-2" role="group">
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="showDataValidation()">
                                    <i class="fas fa-check-circle me-1"></i>データ検証
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="showSavedSearches()">
                                    <i class="fas fa-bookmark me-1"></i>保存検索
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="showDuplicateDetection()">
                                    <i class="fas fa-clone me-1"></i>重複検出
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 検索フォーム -->
        <div class="search-form">
            <form method="GET" class="row g-3">
                <input type="hidden" name="table" value="<?= $table ?>">
                
                <div class="col-md-4">
                    <label for="search" class="form-label">キーワード検索</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?= htmlspecialchars($search) ?>" 
                           placeholder="場所、備考、名前などを検索">
                </div>
                
                <?php if ($table_config['date_column']): ?>
                <div class="col-md-3">
                    <label for="date_from" class="form-label">開始日</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?= htmlspecialchars($date_from) ?>">
                </div>
                
                <div class="col-md-3">
                    <label for="date_to" class="form-label">終了日</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <?php endif; ?>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i>検索
                    </button>
                </div>
            </form>
        </div>

        <!-- データテーブル -->
        <div class="table-container">
            <div class="table-nav">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h4 class="mb-0">
                            <i class="<?= $table_config['icon'] ?> me-2"></i><?= $table_config['name'] ?>管理
                        </h4>
                        <small>総件数: <?= number_format($total_records) ?>件</small>
                    </div>
                    <div class="col-md-6 text-end">
                        <button type="button" class="btn btn-warning" onclick="toggleBulkMode()">
                            <i class="fas fa-check-square me-1"></i>一括選択モード
                        </button>
                        <button type="button" class="btn btn-info" onclick="exportData()">
                            <i class="fas fa-download me-1"></i>エクスポート
                        </button>
                    </div>
                </div>
            </div>

            <!-- 一括操作パネル -->
            <div id="bulkPanel" class="danger-zone" style="display: none;">
                <form method="POST" onsubmit="return confirmBulkDelete()">
                    <input type="hidden" name="action" value="bulk_delete">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <strong>一括削除モード</strong>
                            <p class="mb-0">削除したいレコードにチェックを入れて、削除ボタンを押してください。</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="selectAll()">全選択</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="selectNone()">選択解除</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash me-1"></i>選択項目を削除
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="bulk-column" style="display: none;">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleAllCheckboxes()">
                            </th>
                            <th class="fixed-width">ID</th>
                            <?php foreach ($table_config['display_columns'] as $col): ?>
                                <th><?= $col ?></th>
                            <?php endforeach; ?>
                            <th class="fixed-width">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="<?= count($table_config['display_columns']) + 2 ?>" class="text-center py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">該当するデータがありません</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $record): ?>
                                <tr id="row-<?= $record['id'] ?>">
                                    <td class="bulk-column" style="display: none;">
                                        <input type="checkbox" name="selected_ids[]" value="<?= $record['id'] ?>" 
                                               class="record-checkbox" form="bulkDeleteForm">
                                    </td>
                                    <td class="fixed-width"><?= $record['id'] ?></td>
                                    
                                    <?php foreach ($table_config['columns'] as $i => $column): ?>
                                        <?php if ($column === 'id') continue; ?>
                                        <td>
                                            <span class="display-value">
                                                <?php
                                                $value = $record[$column] ?? '';
                                                
                                                // 特別な表示処理
                                                if ($column === 'driver_id' && isset($users_map[$value])) {
                                                    echo htmlspecialchars($users_map[$value]) . " (ID:{$value})";
                                                } elseif ($column === 'vehicle_id' && isset($vehicles_map[$value])) {
                                                    echo htmlspecialchars($vehicles_map[$value]) . " (ID:{$value})";
                                                } elseif (in_array($column, ['fare', 'charge', 'fuel_cost'])) {
                                                    echo "¥" . number_format($value);
                                                } elseif ($column === 'is_active') {
                                                    echo $value ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>';
                                                } elseif ($column === 'is_completed') {
                                                    echo $value ? '<span class="badge bg-success">完了</span>' : '<span class="badge bg-warning">未完了</span>';
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                                ?>
                                            </span>
                                            
                                            <?php if (in_array($column, $table_config['editable'])): ?>
                                                <div class="edit-value" style="display: none;">
                                                    <?php if (in_array($column, ['transport_category', 'payment_method', 'role', 'status'])): ?>
                                                        <select class="form-select edit-input" name="<?= $column ?>">
                                                            <?php
                                                            $options = [];
                                                            if ($column === 'transport_category') {
                                                                $options = ['通院', '外出等', '退院', '転院', '施設入所', 'その他'];
                                                            } elseif ($column === 'payment_method') {
                                                                $options = ['現金', 'カード', 'その他'];
                                                            } elseif ($column === 'role') {
                                                                $options = ['driver', 'manager', 'admin'];
                                                            } elseif ($column === 'status') {
                                                                $options = ['active', 'inactive'];
                                                            }
                                                            
                                                            foreach ($options as $option) {
                                                                $selected = ($value === $option) ? 'selected' : '';
                                                                echo "<option value='{$option}' {$selected}>{$option}</option>";
                                                            }
                                                            ?>
                                                        </select>
                                                    <?php elseif (in_array($column, ['is_active', 'is_completed'])): ?>
                                                        <select class="form-select edit-input" name="<?= $column ?>">
                                                            <option value="1" <?= $value ? 'selected' : '' ?>>はい</option>
                                                            <option value="0" <?= !$value ? 'selected' : '' ?>>いいえ</option>
                                                        </select>
                                                    <?php else: ?>
                                                        <input type="text" class="form-control edit-input" 
                                                               name="<?= $column ?>" value="<?= htmlspecialchars($value) ?>">
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    
                                    <td class="fixed-width">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-warning edit-btn" 
                                                    onclick="toggleEdit(<?= $record['id'] ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-success save-btn" 
                                                    style="display: none;" onclick="saveRecord(<?= $record['id'] ?>)">
                                                <i class="fas fa-save"></i>
                                            </button>
                                            <button type="button" class="btn btn-secondary cancel-btn" 
                                                    style="display: none;" onclick="cancelEdit(<?= $record['id'] ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger" 
                                                    onclick="deleteRecord(<?= $record['id'] ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ページネーション -->
            <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?table=<?= $table ?>&page=<?= $i ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                    <div class="text-center">
                        <small class="text-muted">
                            <?= (($page - 1) * $limit + 1) ?>-<?= min($page * $limit, $total_records) ?> / <?= number_format($total_records) ?>件
                        </small>
                    </div>
                </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- 非表示フォーム -->
    <form id="bulkDeleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="bulk_delete">
    </form>

    <form id="updateForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="updateId">
    </form>

    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let bulkMode = false;
        let currentEditRow = null;

        // 一括選択モード切り替え
        function toggleBulkMode() {
            bulkMode = !bulkMode;
            const bulkColumns = document.querySelectorAll('.bulk-column');
            const bulkPanel = document.getElementById('bulkPanel');
            
            bulkColumns.forEach(col => {
                col.style.display = bulkMode ? 'table-cell' : 'none';
            });
            bulkPanel.style.display = bulkMode ? 'block' : 'none';
        }

        // 全選択/解除
        function selectAll() {
            document.querySelectorAll('.record-checkbox').forEach(cb => cb.checked = true);
            document.getElementById('selectAllCheckbox').checked = true;
        }

        function selectNone() {
            document.querySelectorAll('.record-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
        }

        function toggleAllCheckboxes() {
            const selectAll = document.getElementById('selectAllCheckbox').checked;
            document.querySelectorAll('.record-checkbox').forEach(cb => cb.checked = selectAll);
        }

        // 編集モード切り替え
        function toggleEdit(id) {
            if (currentEditRow && currentEditRow !== id) {
                cancelEdit(currentEditRow);
            }
            
            currentEditRow = id;
            const row = document.getElementById(`row-${id}`);
            
            row.classList.add('edit-row');
            row.querySelectorAll('.display-value').forEach(el => el.style.display = 'none');
            row.querySelectorAll('.edit-value').forEach(el => el.style.display = 'block');
            row.querySelector('.edit-btn').style.display = 'none';
            row.querySelector('.save-btn').style.display = 'inline-block';
            row.querySelector('.cancel-btn').style.display = 'inline-block';
        }

        function cancelEdit(id) {
            const row = document.getElementById(`row-${id}`);
            
            row.classList.remove('edit-row');
            row.querySelectorAll('.display-value').forEach(el => el.style.display = 'block');
            row.querySelectorAll('.edit-value').forEach(el => el.style.display = 'none');
            row.querySelector('.edit-btn').style.display = 'inline-block';
            row.querySelector('.save-btn').style.display = 'none';
            row.querySelector('.cancel-btn').style.display = 'none';
            
            currentEditRow = null;
        }

        function saveRecord(id) {
            const row = document.getElementById(`row-${id}`);
            const form = document.getElementById('updateForm');
            
            // フォームにデータを設定
            document.getElementById('updateId').value = id;
            
            // 編集された値を取得
            row.querySelectorAll('.edit-value input, .edit-value select').forEach(input => {
                const clone = input.cloneNode();
                clone.style.display = 'none';
                form.appendChild(clone);
            });
            
            form.submit();
        }

        function deleteRecord(id) {
            if (confirm('このレコードを削除しますか？この操作は元に戻せません。')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        function confirmBulkDelete() {
            const checked = document.querySelectorAll('.record-checkbox:checked');
            if (checked.length === 0) {
                alert('削除するレコードを選択してください。');
                return false;
            }
            
            return confirm(`${checked.length}件のレコードを削除しますか？この操作は元に戻せません。`);
        }

        function exportData() {
            const url = new URL(window.location);
            url.searchParams.set('export', '1');
            window.open(url.toString(), '_blank');
        }

        // Enterキーでの保存
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && currentEditRow) {
                e.preventDefault();
                saveRecord(currentEditRow);
            }
            if (e.key === 'Escape' && currentEditRow) {
                e.preventDefault();
                cancelEdit(currentEditRow);
            }
        });

        // 新機能のJavaScript関数
        
        // 一括編集機能
        function showBatchEdit() {
            new bootstrap.Modal(document.getElementById('batchEditModal')).show();
        }

        function previewBatchEdit() {
            const field = document.getElementById('batchField').value;
            const value = document.getElementById('batchValue').value;
            const condition = document.getElementById('batchCondition').value;
            
            if (!field || !value) {
                alert('フィールドと値を入力してください');
                return;
            }

            fetch('api/batch_preview.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    table: '<?= $table ?>',
                    field: field,
                    value: value,
                    condition: condition
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const previewArea = document.getElementById('batchPreview');
                    const tbody = document.querySelector('#batchPreviewTable tbody');
                    
                    tbody.innerHTML = '';
                    data.records.forEach(record => {
                        const row = tbody.insertRow();
                        row.insertCell(0).textContent = record.id;
                        row.insertCell(1).textContent = record.current_value;
                        row.insertCell(2).textContent = value;
                    });
                    
                    previewArea.style.display = 'block';
                    
                    // 影響件数を表示
                    const modal = document.querySelector('#batchEditModal .modal-body');
                    const existingAlert = modal.querySelector('.alert-success');
                    if (existingAlert) existingAlert.remove();
                    
                    const alert = document.createElement('div');
                    alert.className = 'alert alert-success';
                    alert.innerHTML = `<i class="fas fa-info-circle me-2"></i>影響を受けるレコード数: ${data.total_count}件`;
                    modal.insertBefore(alert, modal.firstChild);
                }
            })
            .catch(error => {
                alert('プレビューエラー: ' + error.message);
            });
        }

        function executeBatchEdit() {
            if (!confirm('一括編集を実行しますか？この操作は元に戻せません。')) return;
            
            const field = document.getElementById('batchField').value;
            const value = document.getElementById('batchValue').value;
            const condition = document.getElementById('batchCondition').value;
            
            fetch('api/batch_edit.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    table: '<?= $table ?>',
                    field: field,
                    value: value,
                    condition: condition
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`${data.affected_rows}件のレコードを更新しました`);
                    location.reload();
                } else {
                    alert('エラー: ' + data.error);
                }
            });
        }

        // データ分析機能
        function showDataAnalysis() {
            const modal = new bootstrap.Modal(document.getElementById('dataAnalysisModal'));
            modal.show();
            
            // 基本統計を取得
            fetch(`api/data_analysis.php?table=<?= $table ?>`)
            .then(response => response.json())
            .then(data => {
                displayBasicStats(data.basic_stats);
                displayDataQuality(data.data_quality);
                drawDistributionChart(data.distribution);
            });
        }

        function displayBasicStats(stats) {
            const container = document.getElementById('basicStats');
            container.innerHTML = `
                <div class="row text-center">
                    <div class="col-4">
                        <h4 class="text-primary">${stats.total_records.toLocaleString()}</h4>
                        <small>総レコード数</small>
                    </div>
                    <div class="col-4">
                        <h4 class="text-success">${stats.recent_records.toLocaleString()}</h4>
                        <small>今月のレコード</small>
                    </div>
                    <div class="col-4">
                        <h4 class="text-info">${stats.avg_per_day.toFixed(1)}</h4>
                        <small>1日平均</small>
                    </div>
                </div>
                <hr>
                <div class="small">
                    <p><strong>最古:</strong> ${stats.oldest_record}</p>
                    <p><strong>最新:</strong> ${stats.newest_record}</p>
                </div>
            `;
        }

        function displayDataQuality(quality) {
            const container = document.getElementById('dataQuality');
            const issues = [];
            
            if (quality.null_values > 0) issues.push(`NULL値: ${quality.null_values}件`);
            if (quality.duplicates > 0) issues.push(`重複: ${quality.duplicates}件`);
            if (quality.invalid_values > 0) issues.push(`不正値: ${quality.invalid_values}件`);
            
            if (issues.length === 0) {
                container.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>データ品質に問題ありません</div>';
            } else {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <strong>検出された問題:</strong>
                        <ul class="mb-0 mt-2">
                            ${issues.map(issue => `<li>${issue}</li>`).join('')}
                        </ul>
                    </div>
                `;
            }
        }

        function drawDistributionChart(distribution) {
            const ctx = document.getElementById('distributionChart').getContext('2d');
            // Chart.jsを使用してグラフを描画（実装例）
            console.log('Distribution data:', distribution);
        }

        // CSVインポート機能
        function showImportModal() {
            new bootstrap.Modal(document.getElementById('importModal')).show();
        }

        function previewImport() {
            const fileInput = document.getElementById('csvFile');
            const file = fileInput.files[0];
            
            if (!file) {
                alert('CSVファイルを選択してください');
                return;
            }

            const formData = new FormData();
            formData.append('csv_file', file);
            formData.append('has_header', document.getElementById('hasHeader').checked);
            formData.append('action', 'preview');
            formData.append('table', '<?= $table ?>');

            fetch('api/csv_import.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayImportPreview(data.preview);
                } else {
                    alert('プレビューエラー: ' + data.error);
                }
            });
        }

        function displayImportPreview(preview) {
            const table = document.getElementById('importPreviewTable');
            const thead = table.querySelector('thead');
            const tbody = table.querySelector('tbody');
            
            // ヘッダー行
            thead.innerHTML = '<tr>' + preview.headers.map(h => `<th>${h}</th>`).join('') + '</tr>';
            
            // データ行
            tbody.innerHTML = '';
            preview.rows.forEach(row => {
                const tr = tbody.insertRow();
                row.forEach(cell => {
                    tr.insertCell().textContent = cell;
                });
            });
            
            document.getElementById('importPreview').style.display = 'block';
        }

        function executeImport() {
            if (!confirm('CSVインポートを実行しますか？')) return;
            
            const fileInput = document.getElementById('csvFile');
            const file = fileInput.files[0];
            
            const formData = new FormData();
            formData.append('csv_file', file);
            formData.append('has_header', document.getElementById('hasHeader').checked);
            formData.append('import_mode', document.querySelector('input[name="import_mode"]:checked').value);
            formData.append('action', 'import');
            formData.append('table', '<?= $table ?>');

            fetch('api/csv_import.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`インポート完了: ${data.imported_count}件のレコードを処理しました`);
                    location.reload();
                } else {
                    alert('インポートエラー: ' + data.error);
                }
            });
        }

        // データ検証機能
        function showDataValidation() {
            new bootstrap.Modal(document.getElementById('validationModal')).show();
        }

        function runValidation() {
            const container = document.getElementById('validationResults');
            container.innerHTML = '<div class="text-center"><div class="spinner-border"></div><p>検証中...</p></div>';
            
            fetch(`api/data_validation.php?table=<?= $table ?>`)
            .then(response => response.json())
            .then(data => {
                displayValidationResults(data);
            });
        }

        function displayValidationResults(results) {
            const container = document.getElementById('validationResults');
            
            if (results.issues.length === 0) {
                container.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>データ検証に問題ありません</div>';
                return;
            }
            
            let html = '<div class="accordion" id="validationAccordion">';
            results.issues.forEach((issue, index) => {
                html += `
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button ${index === 0 ? '' : 'collapsed'}" type="button" 
                                    data-bs-toggle="collapse" data-bs-target="#issue${index}">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                ${issue.title} (${issue.count}件)
                            </button>
                        </h2>
                        <div id="issue${index}" class="accordion-collapse collapse ${index === 0 ? 'show' : ''}" 
                             data-bs-parent="#validationAccordion">
                            <div class="accordion-body">
                                <p>${issue.description}</p>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>ID</th><th>問題のある値</th></tr></thead>
                                        <tbody>
                                            ${issue.examples.map(ex => `<tr><td>${ex.id}</td><td>${ex.value}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function autoFixIssues() {
            if (!confirm('自動修正を実行しますか？')) return;
            
            fetch(`api/auto_fix.php?table=<?= $table ?>`, {method: 'POST'})
            .then(response => response.json())
            .then(data => {
                alert(`自動修正完了: ${data.fixed_count}件の問題を修正しました`);
                runValidation(); // 再検証
            });
        }

        // 保存検索機能
        function showSavedSearches() {
            new bootstrap.Modal(document.getElementById('savedSearchModal')).show();
            loadSavedSearches();
        }

        function saveCurrentSearch() {
            const searchParams = new URLSearchParams(window.location.search);
            const searchName = prompt('検索条件の名前を入力してください:');
            
            if (!searchName) return;
            
            const searchData = {
                name: searchName,
                table: '<?= $table ?>',
                params: Object.fromEntries(searchParams)
            };
            
            let saved = JSON.parse(localStorage.getItem('savedSearches') || '[]');
            saved.push(searchData);
            localStorage.setItem('savedSearches', JSON.stringify(saved));
            
            loadSavedSearches();
            alert('検索条件を保存しました');
        }

        function loadSavedSearches() {
            const saved = JSON.parse(localStorage.getItem('savedSearches') || '[]');
            const container = document.getElementById('savedSearchList');
            
            if (saved.length === 0) {
                container.innerHTML = '<p class="text-muted">保存された検索はありません</p>';
                return;
            }
            
            container.innerHTML = saved.map((search, index) => `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">${search.name}</h6>
                            <small class="text-muted">テーブル: ${search.table}</small>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-primary" onclick="applySavedSearch(${index})">適用</button>
                            <button class="btn btn-sm btn-danger" onclick="deleteSavedSearch(${index})">削除</button>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        function applySavedSearch(index) {
            const saved = JSON.parse(localStorage.getItem('savedSearches') || '[]');
            const search = saved[index];
            
            if (search.table !== '<?= $table ?>') {
                if (!confirm('別のテーブルの検索条件です。適用しますか？')) return;
            }
            
            const url = new URL(window.location);
            Object.keys(search.params).forEach(key => {
                url.searchParams.set(key, search.params[key]);
            });
            
            window.location.href = url.toString();
        }

        function deleteSavedSearch(index) {
            if (!confirm('この保存検索を削除しますか？')) return;
            
            let saved = JSON.parse(localStorage.getItem('savedSearches') || '[]');
            saved.splice(index, 1);
            localStorage.setItem('savedSearches', JSON.stringify(saved));
            
            loadSavedSearches();
        }

        // 重複検出機能
        function showDuplicateDetection() {
            new bootstrap.Modal(document.getElementById('duplicateModal')).show();
        }

        function detectDuplicates() {
            const container = document.getElementById('duplicateResults');
            container.innerHTML = '<div class="text-center"><div class="spinner-border"></div><p>重複を検出中...</p></div>';
            
            fetch(`api/duplicate_detection.php?table=<?= $table ?>`)
            .then(response => response.json())
            .then(data => {
                displayDuplicateResults(data);
            });
        }

        function displayDuplicateResults(results) {
            const container = document.getElementById('duplicateResults');
            
            if (results.duplicates.length === 0) {
                container.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>重複データは見つかりませんでした</div>';
                return;
            }
            
            let html = '<div class="alert alert-warning"><i class="fas fa-clone me-2"></i>' + results.duplicates.length + 'グループの重複が見つかりました</div>';
            
            results.duplicates.forEach((group, index) => {
                html += `
                    <div class="card mb-3">
                        <div class="card-header">
                            <strong>重複グループ ${index + 1}</strong>
                            <small class="text-muted">(${group.records.length}件)</small>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" onchange="toggleGroupSelection(${index})"></th>
                                            <th>ID</th>
                                            ${Object.keys(group.records[0]).filter(k => k !== 'id').map(k => `<th>${k}</th>`).join('')}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${group.records.map(record => `
                                            <tr>
                                                <td><input type="checkbox" name="duplicate_${index}" value="${record.id}"></td>
                                                <td>${record.id}</td>
                                                ${Object.keys(record).filter(k => k !== 'id').map(k => `<td>${record[k]}</td>`).join('')}
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            document.getElementById('mergeBtn').style.display = 'inline-block';
        }

        function toggleGroupSelection(groupIndex) {
            const checkboxes = document.querySelectorAll(`input[name="duplicate_${groupIndex}"]`);
            const masterCheckbox = event.target;
            
            checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
        }

        function mergeDuplicates() {
            const selectedIds = [];
            document.querySelectorAll('input[type="checkbox"][value]:checked').forEach(cb => {
                selectedIds.push(cb.value);
            });
            
            if (selectedIds.length === 0) {
                alert('削除する重複レコードを選択してください');
                return;
            }
            
            if (!confirm(`選択した${selectedIds.length}件のレコードを削除しますか？`)) return;
            
            fetch('api/merge_duplicates.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    table: '<?= $table ?>',
                    ids: selectedIds
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`${data.deleted_count}件の重複レコードを削除しました`);
                    location.reload();
                } else {
                    alert('エラー: ' + data.error);
                }
            });
        }
    </script>
</body>
</html>

<?php
// エクスポート処理
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $table . '_export_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM追加（Excel用）
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // ヘッダー行
    $headers = array_merge(['ID'], $table_config['display_columns']);
    fputcsv($output, $headers);
    
    // データ行
    foreach ($records as $record) {
        $row = [$record['id']];
        foreach (array_slice($table_config['columns'], 1) as $column) {
            $value = $record[$column] ?? '';
            
            // 特別な処理
            if ($column === 'driver_id' && isset($users_map[$value])) {
                $value = $users_map[$value];
            } elseif ($column === 'vehicle_id' && isset($vehicles_map[$value])) {
                $value = $vehicles_map[$value];
            }
            
            $row[] = $value;
        }
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}
?>
