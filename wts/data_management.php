<?php
session_start();

// データベース接続
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

$message = '';
$error = '';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_sample_flag':
                // サンプルフラグカラムを追加
                addSampleFlags($pdo);
                $message = 'サンプルフラグカラムを追加しました。';
                break;
                
            case 'mark_sample_by_date':
                // 日付範囲でサンプルマーク
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                markSampleByDate($pdo, $start_date, $end_date);
                $message = "期間 {$start_date} ～ {$end_date} のデータをサンプルとしてマークしました。";
                break;
                
            case 'mark_sample_manual':
                // 手動でサンプルマーク
                $table = $_POST['table'];
                $ids = explode(',', $_POST['record_ids']);
                markSampleManual($pdo, $table, $ids);
                $message = "選択されたレコードをサンプルとしてマークしました。";
                break;
                
            case 'delete_sample_data':
                // サンプルデータ削除
                deleteSampleData($pdo);
                $message = 'サンプルデータを削除しました。';
                break;
                
            case 'export_real_data':
                // 実務データのみエクスポート
                exportRealData($pdo);
                break;
                
            case 'reset_to_production':
                // 本番運用モードにリセット
                resetToProduction($pdo, $_POST['production_start_date']);
                $message = '本番運用モードに設定しました。';
                break;
                
            case 'create_sample_dataset':
                // 新しいサンプルデータセット作成
                createSampleDataset($pdo);
                $message = '新しいサンプルデータセットを作成しました。';
                break;
        }
    } catch (Exception $e) {
        $error = 'エラー: ' . $e->getMessage();
    }
}

// データ統計取得
$stats = getDataStatistics($pdo);

// 関数定義
function addSampleFlags($pdo) {
    $tables = ['ride_records', 'daily_inspections', 'pre_duty_calls', 'post_duty_calls', 
               'departure_records', 'arrival_records', 'periodic_inspections'];
    
    foreach ($tables as $table) {
        // カラムが存在するかチェック
        $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'is_sample_data'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN is_sample_data BOOLEAN DEFAULT FALSE");
        }
    }
}

function markSampleByDate($pdo, $start_date, $end_date) {
    $tables_with_date = [
        'ride_records' => 'ride_date',
        'daily_inspections' => 'inspection_date',
        'pre_duty_calls' => 'call_date',
        'post_duty_calls' => 'call_date',
        'departure_records' => 'departure_date',
        'arrival_records' => 'arrival_date',
        'periodic_inspections' => 'inspection_date'
    ];
    
    foreach ($tables_with_date as $table => $date_column) {
        $sql = "UPDATE {$table} SET is_sample_data = TRUE 
                WHERE {$date_column} BETWEEN ? AND ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start_date, $end_date]);
    }
}

function markSampleManual($pdo, $table, $ids) {
    if (empty($ids) || empty($table)) return;
    
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $sql = "UPDATE {$table} SET is_sample_data = TRUE WHERE id IN ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
}

function deleteSampleData($pdo) {
    $tables = ['ride_records', 'daily_inspections', 'pre_duty_calls', 'post_duty_calls', 
               'departure_records', 'arrival_records', 'periodic_inspections'];
    
    foreach ($tables as $table) {
        $sql = "DELETE FROM {$table} WHERE is_sample_data = TRUE";
        $pdo->exec($sql);
    }
}

function exportRealData($pdo) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="real_data_backup_' . date('Y-m-d_H-i-s') . '.json"');
    
    $export_data = [];
    $tables = ['users', 'vehicles', 'ride_records', 'daily_inspections', 'pre_duty_calls', 
               'post_duty_calls', 'departure_records', 'arrival_records', 'periodic_inspections'];
    
    foreach ($tables as $table) {
        if (in_array($table, ['users', 'vehicles'])) {
            // マスタテーブルは全件
            $stmt = $pdo->query("SELECT * FROM {$table}");
        } else {
            // データテーブルは実務データのみ
            $stmt = $pdo->query("SELECT * FROM {$table} WHERE is_sample_data = FALSE OR is_sample_data IS NULL");
        }
        $export_data[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function resetToProduction($pdo, $production_start_date) {
    // 本番開始日より前のデータをサンプルとしてマーク
    markSampleByDate($pdo, '2020-01-01', date('Y-m-d', strtotime($production_start_date . ' -1 day')));
    
    // システム設定に本番開始日を記録
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                          VALUES ('production_start_date', ?) 
                          ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$production_start_date, $production_start_date]);
}

function createSampleDataset($pdo) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // サンプル運転者と車両のID取得
    $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('driver', 'admin') LIMIT 1");
    $driver_id = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT id FROM vehicles LIMIT 1");
    $vehicle_id = $stmt->fetchColumn();
    
    if (!$driver_id || !$vehicle_id) {
        throw new Exception('サンプルデータ作成には運転者と車両が必要です');
    }
    
    // サンプル乗車記録を作成
    $sample_rides = [
        [$yesterday, '09:00', '○○病院', '○○様宅', 1500, 0, '通院'],
        [$yesterday, '10:30', '○○様宅', '△△クリニック', 1200, 0, '通院'],
        [$yesterday, '14:00', 'スーパー○○', '□□様宅', 800, 0, '外出等'],
        [$today, '08:30', '○○介護施設', '総合病院', 2000, 0, '通院'],
        [$today, '11:00', '総合病院', '○○介護施設', 2000, 0, '通院']
    ];
    
    foreach ($sample_rides as $ride) {
        $stmt = $pdo->prepare("
            INSERT INTO ride_records 
            (driver_id, vehicle_id, ride_date, ride_time, pickup_location, dropoff_location, 
             fare, charge, transport_category, payment_method, passenger_count, is_sample_data, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '現金', 1, TRUE, NOW())
        ");
        $stmt->execute([$driver_id, $vehicle_id, $ride[0], $ride[1], $ride[2], $ride[3], $ride[4], $ride[5], $ride[6]]);
    }
}

function getDataStatistics($pdo) {
    $stats = [];
    $tables = ['ride_records', 'daily_inspections', 'pre_duty_calls', 'post_duty_calls', 
               'departure_records', 'arrival_records', 'periodic_inspections'];
    
    foreach ($tables as $table) {
        // テーブル存在確認
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() == 0) continue;
        
        // is_sample_dataカラム存在確認
        $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'is_sample_data'");
        $has_sample_flag = $stmt->rowCount() > 0;
        
        if ($has_sample_flag) {
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_sample_data = TRUE THEN 1 END) as sample,
                    COUNT(CASE WHEN is_sample_data = FALSE OR is_sample_data IS NULL THEN 1 END) as real
                FROM {$table}
            ");
            $stats[$table] = $stmt->fetch();
            $stats[$table]['has_sample_flag'] = true;
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM {$table}");
            $total = $stmt->fetchColumn();
            $stats[$table] = [
                'total' => $total,
                'sample' => 0,
                'real' => $total,
                'has_sample_flag' => false
            ];
        }
    }
    
    return $stats;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データ整理管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .action-card { 
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .action-card:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .danger-zone {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border: 1px solid #f1aeb5;
        }
        .stats-table th { background-color: #f8f9fa; }
        .sample-badge { background-color: #ffc107; }
        .real-badge { background-color: #28a745; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-database me-2"></i>データ整理管理
            </a>
            <a href="dashboard.php" class="btn btn-outline-light">
                <i class="fas fa-arrow-left me-1"></i>ダッシュボード
            </a>
        </div>
    </nav>

    <div class="container mt-4">
        <h1><i class="fas fa-broom me-2"></i>データ整理管理ツール</h1>
        <p class="text-muted">サンプルデータと実務データを整理・管理できます</p>

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

        <!-- データ統計 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-bar me-2"></i>現在のデータ状況</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table stats-table">
                                <thead>
                                    <tr>
                                        <th>テーブル</th>
                                        <th>総件数</th>
                                        <th>サンプルデータ</th>
                                        <th>実務データ</th>
                                        <th>状態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats as $table => $stat): ?>
                                        <tr>
                                            <td><code><?= $table ?></code></td>
                                            <td><?= number_format($stat['total']) ?></td>
                                            <td>
                                                <span class="badge sample-badge"><?= number_format($stat['sample']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge real-badge"><?= number_format($stat['real']) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($stat['has_sample_flag']): ?>
                                                    <i class="fas fa-check text-success"></i> 整理済み
                                                <?php else: ?>
                                                    <i class="fas fa-exclamation text-warning"></i> 未整理
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- ステップ1: 準備 -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-cog me-2"></i>ステップ1: 準備</h5>
                    <p>データ整理のためのフラグを追加します</p>
                    
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="action" value="add_sample_flag">
                        <button type="submit" class="btn btn-primary" 
                                onclick="return confirm('全テーブルにサンプルフラグを追加しますか？')">
                            <i class="fas fa-flag me-1"></i>サンプルフラグ追加
                        </button>
                    </form>
                    
                    <small class="text-muted">
                        各テーブルに「is_sample_data」カラムを追加し、データの種別を管理できるようにします
                    </small>
                </div>
            </div>

            <!-- ステップ2: サンプルデータ識別 -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-calendar me-2"></i>ステップ2: 日付でサンプル識別</h5>
                    <p>特定期間のデータをサンプルとしてマークします</p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="mark_sample_by_date">
                        <div class="row">
                            <div class="col-6">
                                <input type="date" name="start_date" class="form-control form-control-sm" 
                                       value="2024-01-01" required>
                            </div>
                            <div class="col-6">
                                <input type="date" name="end_date" class="form-control form-control-sm" 
                                       value="<?= date('Y-m-d', strtotime('-7 days')) ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm mt-2" 
                                onclick="return confirm('指定期間のデータをサンプルとしてマークしますか？')">
                            <i class="fas fa-tag me-1"></i>期間指定でマーク
                        </button>
                    </form>
                </div>
            </div>

            <!-- ステップ3: 本番運用設定 -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-rocket me-2"></i>ステップ3: 本番運用設定</h5>
                    <p>本番運用開始日を設定して、それ以前をサンプル扱いにします</p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="reset_to_production">
                        <div class="mb-2">
                            <label class="form-label">本番運用開始日</label>
                            <input type="date" name="production_start_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <button type="submit" class="btn btn-success" 
                                onclick="return confirm('本番運用モードに設定しますか？この操作により、開始日以前のデータはサンプル扱いになります。')">
                            <i class="fas fa-play me-1"></i>本番運用開始
                        </button>
                    </form>
                </div>
            </div>

            <!-- ステップ4: データ管理 -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5><i class="fas fa-download me-2"></i>ステップ4: データエクスポート</h5>
                    <p>実務データのみをバックアップします</p>
                    
                    <form method="POST" class="mb-2">
                        <input type="hidden" name="action" value="export_real_data">
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-file-export me-1"></i>実務データエクスポート
                        </button>
                    </form>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="create_sample_dataset">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <i class="fas fa-plus me-1"></i>サンプルデータ作成
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 危険ゾーン -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="action-card danger-zone">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i>危険操作</h5>
                    <p class="text-danger">以下の操作は元に戻せません。必ずバックアップを取ってから実行してください。</p>
                    
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="delete_sample_data">
                        <button type="submit" class="btn btn-danger" 
                                onclick="return confirm('⚠️ 警告: サンプルデータを完全に削除します。この操作は元に戻せません。本当に実行しますか？')">
                            <i class="fas fa-trash me-1"></i>サンプルデータ削除
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 使い方ガイド -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-info-circle me-2"></i>データ整理の推奨手順</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li><strong>サンプルフラグ追加</strong>: まず全テーブルにサンプル識別用のフラグを追加</li>
                            <li><strong>期間指定マーク</strong>: テスト期間のデータをサンプルとしてマーク</li>
                            <li><strong>本番運用設定</strong>: 実際の運用開始日を設定し、システムを本番モードに</li>
                            <li><strong>データエクスポート</strong>: 実務データのみをバックアップとして保存</li>
                            <li><strong>不要データ削除</strong>: 必要に応じてサンプルデータを削除</li>
                        </ol>
                        
                        <div class="alert alert-info mt-3">
                            <h6><i class="fas fa-lightbulb me-2"></i>おすすめの運用方法</h6>
                            <ul class="mb-0">
                                <li>本格運用前: サンプルデータで操作を習得</li>
                                <li>本格運用開始: 「本番運用設定」でサンプルデータを分離</li>
                                <li>定期的: 実務データのみをエクスポートしてバックアップ</li>
                                <li>研修時: 新しいサンプルデータセットを作成して練習</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
