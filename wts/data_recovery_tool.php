<?php
// データ復旧・調査ツール
session_start();

// 管理者権限チェック
if (!isset($_SESSION['user_id'])) {
    die('ログインが必要です。');
}

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// 調査対象日付
$target_date = '2025-07-26';
if (isset($_GET['date'])) {
    $target_date = $_GET['date'];
}

// アクション処理
$action_result = '';
if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'backup_current':
            $action_result = backupCurrentData($pdo, $target_date);
            break;
        case 'restore_from_backup':
            $action_result = restoreFromBackup($pdo, $_POST['backup_file']);
            break;
        case 'manual_restore':
            $action_result = manualRestore($pdo, $_POST['restore_data']);
            break;
    }
}

// 現在のデータベース構造確認
function checkTableStructure($pdo, $table) {
    try {
        $stmt = $pdo->query("DESCRIBE {$table}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// バックアップファイル検索
function findBackupFiles() {
    $backup_files = [];
    $backup_dirs = [
        './backup/',
        './backups/',
        '../backup/',
        '../backups/',
        '/home/twinklemark/backup/',
        '/home/twinklemark/tw1nkle.com/backup/'
    ];
    
    foreach ($backup_dirs as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . "*.sql");
            $backup_files = array_merge($backup_files, $files);
        }
    }
    
    return $backup_files;
}

// 現在データのバックアップ
function backupCurrentData($pdo, $date) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE ride_date <= ? ORDER BY ride_date DESC, id DESC");
        $stmt->execute([$date]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $backup_content = "-- Ride Records Backup: " . date('Y-m-d H:i:s') . "\n";
        $backup_content .= "-- Data before: {$date}\n\n";
        
        foreach ($data as $row) {
            $values = array_map(function($v) { return is_null($v) ? 'NULL' : "'" . addslashes($v) . "'"; }, $row);
            $backup_content .= "INSERT INTO ride_records (" . implode(', ', array_keys($row)) . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        
        $filename = "ride_records_backup_" . date('Y-m-d_H-i-s') . ".sql";
        file_put_contents($filename, $backup_content);
        
        return "バックアップ完了: {$filename}";
    } catch(Exception $e) {
        return "バックアップエラー: " . $e->getMessage();
    }
}

// データ詳細分析
function analyzeData($pdo, $date) {
    $analysis = [];
    
    try {
        // 指定日以前のデータ統計
        $stmt = $pdo->prepare("
            SELECT 
                DATE(ride_date) as date,
                COUNT(*) as count,
                SUM(fare_amount) as total_amount,
                AVG(fare_amount) as avg_amount,
                MIN(fare_amount) as min_amount,
                MAX(fare_amount) as max_amount,
                GROUP_CONCAT(DISTINCT fare_amount ORDER BY fare_amount) as amounts
            FROM ride_records 
            WHERE ride_date <= ?
            GROUP BY DATE(ride_date)
            ORDER BY date DESC
            LIMIT 10
        ");
        $stmt->execute([$date]);
        $analysis['daily_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 異常な金額データ検出
        $stmt = $pdo->prepare("
            SELECT *
            FROM ride_records 
            WHERE ride_date <= ? 
            AND (fare_amount = 0 OR fare_amount IS NULL OR fare_amount > 50000 OR fare_amount < 0)
            ORDER BY ride_date DESC, id DESC
        ");
        $stmt->execute([$date]);
        $analysis['anomalies'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 最近の更新記録
        $stmt = $pdo->prepare("
            SELECT *
            FROM ride_records 
            WHERE ride_date <= ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 20
        ");
        $stmt->execute([$date]);
        $analysis['recent_updates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        $analysis['error'] = $e->getMessage();
    }
    
    return $analysis;
}

// データ分析実行
$ride_records_structure = checkTableStructure($pdo, 'ride_records');
$backup_files = findBackupFiles();
$data_analysis = analyzeData($pdo, $target_date);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データ復旧・調査ツール - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .anomaly-row { background-color: #ffebee; }
        .zero-amount { background-color: #fff3e0; }
        .high-amount { background-color: #e8f5e8; }
        .code-block { background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-danger">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-tools me-2"></i>データ復旧・調査ツール
        </a>
        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i>ダッシュボードに戻る
        </a>
    </div>
</nav>

<div class="container mt-4">
    
    <?php if ($action_result): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($action_result) ?>
        </div>
    <?php endif; ?>

    <!-- 調査対象日付選択 -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5><i class="fas fa-calendar me-2"></i>調査対象日付</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">調査対象日（この日以前のデータを調査）</label>
                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($target_date) ?>">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>調査実行
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- テーブル構造情報 -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h5><i class="fas fa-database me-2"></i>ride_recordsテーブル構造</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>カラム名</th>
                            <th>データ型</th>
                            <th>NULL許可</th>
                            <th>デフォルト値</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ride_records_structure as $column): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($column['Field']) ?></code></td>
                                <td><?= htmlspecialchars($column['Type']) ?></td>
                                <td><?= $column['Null'] === 'YES' ? '○' : '×' ?></td>
                                <td><?= htmlspecialchars($column['Default'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 日別統計 -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5><i class="fas fa-chart-bar me-2"></i>日別データ統計（<?= htmlspecialchars($target_date) ?>以前）</h5>
        </div>
        <div class="card-body">
            <?php if (isset($data_analysis['daily_stats'])): ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>日付</th>
                                <th>乗車回数</th>
                                <th>合計金額</th>
                                <th>平均金額</th>
                                <th>最小金額</th>
                                <th>最大金額</th>
                                <th>金額一覧</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data_analysis['daily_stats'] as $stat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($stat['date']) ?></td>
                                    <td><?= number_format($stat['count']) ?>回</td>
                                    <td>¥<?= number_format($stat['total_amount'] ?? 0) ?></td>
                                    <td>¥<?= number_format($stat['avg_amount'] ?? 0) ?></td>
                                    <td>¥<?= number_format($stat['min_amount'] ?? 0) ?></td>
                                    <td>¥<?= number_format($stat['max_amount'] ?? 0) ?></td>
                                    <td><small><?= htmlspecialchars($stat['amounts']) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 異常データ検出 -->
    <?php if (isset($data_analysis['anomalies']) && !empty($data_analysis['anomalies'])): ?>
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>異常データ検出（0円・NULL・異常に高額・負の値）</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>日付</th>
                                <th>時間</th>
                                <th>乗車地</th>
                                <th>降車地</th>
                                <th>金額</th>
                                <th>人数</th>
                                <th>更新日時</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data_analysis['anomalies'] as $anomaly): ?>
                                <tr class="<?= ($anomaly['fare_amount'] == 0 || is_null($anomaly['fare_amount'])) ? 'zero-amount' : 'anomaly-row' ?>">
                                    <td><?= htmlspecialchars($anomaly['id']) ?></td>
                                    <td><?= htmlspecialchars($anomaly['ride_date']) ?></td>
                                    <td><?= htmlspecialchars($anomaly['ride_time']) ?></td>
                                    <td><?= htmlspecialchars($anomaly['pickup_location'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($anomaly['dropoff_location'] ?? '') ?></td>
                                    <td><strong>¥<?= number_format($anomaly['fare_amount'] ?? 0) ?></strong></td>
                                    <td><?= htmlspecialchars($anomaly['passenger_count'] ?? 1) ?>名</td>
                                    <td><?= htmlspecialchars($anomaly['updated_at'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 復旧アクション -->
    <div class="card mb-4">
        <div class="card-header bg-danger text-white">
            <h5><i class="fas fa-undo me-2"></i>データ復旧アクション</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- 現在データのバックアップ -->
                <div class="col-md-6 mb-3">
                    <div class="card h-100">
                        <div class="card-header bg-secondary text-white">
                            <h6><i class="fas fa-save me-1"></i>1. 現在データのバックアップ</h6>
                        </div>
                        <div class="card-body">
                            <p>現在のデータをバックアップファイルとして保存します。</p>
                            <form method="POST">
                                <input type="hidden" name="action" value="backup_current">
                                <button type="submit" class="btn btn-secondary">
                                    <i class="fas fa-download me-1"></i>バックアップ作成
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- 手動データ修正SQL生成 -->
                <div class="col-md-6 mb-3">
                    <div class="card h-100">
                        <div class="card-header bg-warning text-dark">
                            <h6><i class="fas fa-edit me-1"></i>2. 手動データ修正</h6>
                        </div>
                        <div class="card-body">
                            <p>異常データを手動で修正するSQLを生成します。</p>
                            <button type="button" class="btn btn-warning" onclick="generateFixSQL()">
                                <i class="fas fa-wrench me-1"></i>修正SQL生成
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- バックアップファイルから復元 -->
            <?php if (!empty($backup_files)): ?>
                <div class="card mt-3">
                    <div class="card-header bg-success text-white">
                        <h6><i class="fas fa-upload me-1"></i>3. バックアップファイルから復元</h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>注意：</strong>この操作は現在のデータを置き換えます。事前にバックアップを作成してください。
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="restore_from_backup">
                            <div class="mb-3">
                                <label class="form-label">復元するバックアップファイル</label>
                                <select name="backup_file" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($backup_files as $file): ?>
                                        <option value="<?= htmlspecialchars($file) ?>">
                                            <?= htmlspecialchars(basename($file)) ?> 
                                            (<?= date('Y-m-d H:i:s', filemtime($file)) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success" onclick="return confirm('本当に復元しますか？現在のデータは上書きされます。')">
                                <i class="fas fa-undo me-1"></i>復元実行
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 修正SQL表示エリア -->
    <div id="sqlOutput" class="card mb-4" style="display: none;">
        <div class="card-header bg-info text-white">
            <h5><i class="fas fa-code me-2"></i>生成された修正SQL</h5>
        </div>
        <div class="card-body">
            <div class="code-block" id="sqlCode"></div>
            <button type="button" class="btn btn-primary mt-2" onclick="copySQLToClipboard()">
                <i class="fas fa-copy me-1"></i>SQLをコピー
            </button>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function generateFixSQL() {
    const anomalies = <?= json_encode($data_analysis['anomalies'] ?? []) ?>;
    let sql = "-- データ修正SQL (生成日時: " + new Date().toISOString() + ")\n";
    sql += "-- 実行前に必ずデータをバックアップしてください\n\n";
    
    anomalies.forEach(function(record) {
        if (record.fare_amount == 0 || record.fare_amount == null) {
            sql += `-- ID ${record.id}: 金額が0またはNULL\n`;
            sql += `UPDATE ride_records SET fare_amount = 2000 WHERE id = ${record.id}; -- 適切な金額に修正してください\n\n`;
        } else if (record.fare_amount < 0) {
            sql += `-- ID ${record.id}: 負の金額\n`;
            sql += `UPDATE ride_records SET fare_amount = ${Math.abs(record.fare_amount)} WHERE id = ${record.id};\n\n`;
        } else if (record.fare_amount > 50000) {
            sql += `-- ID ${record.id}: 異常に高額\n`;
            sql += `-- UPDATE ride_records SET fare_amount = ??? WHERE id = ${record.id}; -- 正しい金額を確認してください\n\n`;
        }
    });
    
    if (sql.length > 200) {
        document.getElementById('sqlCode').textContent = sql;
        document.getElementById('sqlOutput').style.display = 'block';
    } else {
        alert('修正が必要な異常データが見つかりませんでした。');
    }
}

function copySQLToClipboard() {
    const sqlText = document.getElementById('sqlCode').textContent;
    navigator.clipboard.writeText(sqlText).then(function() {
        alert('SQLをクリップボードにコピーしました。');
    });
}
</script>

</body>
</html>
