<?php
session_start();
require_once 'config/database.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// データベース接続
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// テーブル構造確認関数
function getTableStructure($pdo, $table_name) {
    try {
        $stmt = $pdo->query("DESCRIBE {$table_name}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

// サンプルデータ取得関数
function getSampleData($pdo, $table_name, $limit = 3) {
    try {
        $stmt = $pdo->query("SELECT * FROM {$table_name} LIMIT {$limit}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

// 確認対象テーブル
$tables = [
    'users' => 'ユーザー',
    'vehicles' => '車両',
    'pre_duty_calls' => '乗務前点呼',
    'post_duty_calls' => '乗務後点呼',
    'daily_inspections' => '日常点検',
    'periodic_inspections' => '定期点検',
    'departure_records' => '出庫記録',
    'arrival_records' => '入庫記録',
    'ride_records' => '乗車記録',
    'daily_operations' => '運行記録（旧）'
];

$table_info = [];
foreach ($tables as $table => $name) {
    $table_info[$table] = [
        'name' => $name,
        'structure' => getTableStructure($pdo, $table),
        'sample' => getSampleData($pdo, $table, 2)
    ];
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 テーブル構造確認</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .table-card { margin-bottom: 30px; }
        .column-info { font-size: 12px; }
        .error-text { color: #dc3545; }
        .success-text { color: #198754; }
        .sample-data { background: #f8f9fa; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>

<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <h3><i class="fas fa-database"></i> データベーステーブル構造確認</h3>
                <p>出力エラー解決のためのテーブル構造とデータ確認</p>
            </div>
        </div>
    </div>

    <?php foreach ($table_info as $table => $info): ?>
    <div class="row">
        <div class="col-12">
            <div class="card table-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-table"></i> <?php echo $info['name']; ?> (<?php echo $table; ?>)
                        <?php if (isset($info['structure']['error'])): ?>
                        <span class="badge bg-danger">エラー</span>
                        <?php else: ?>
                        <span class="badge bg-success">存在</span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (isset($info['structure']['error'])): ?>
                        <div class="error-text">
                            <i class="fas fa-times"></i> <?php echo $info['structure']['error']; ?>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>テーブル構造</h6>
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr>
                                            <th>カラム名</th>
                                            <th>型</th>
                                            <th>NULL</th>
                                            <th>デフォルト</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($info['structure'] as $column): ?>
                                        <tr>
                                            <td class="column-info"><strong><?php echo $column['Field']; ?></strong></td>
                                            <td class="column-info"><?php echo $column['Type']; ?></td>
                                            <td class="column-info"><?php echo $column['Null']; ?></td>
                                            <td class="column-info"><?php echo $column['Default'] ?? 'NULL'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="col-md-6">
                                <h6>サンプルデータ</h6>
                                <?php if (isset($info['sample']['error'])): ?>
                                    <div class="error-text"><?php echo $info['sample']['error']; ?></div>
                                <?php elseif (empty($info['sample'])): ?>
                                    <div class="text-muted">データなし</div>
                                <?php else: ?>
                                    <div class="sample-data">
                                        <small>
                                            <?php foreach ($info['sample'] as $i => $row): ?>
                                                <strong>レコード<?php echo $i+1; ?>:</strong><br>
                                                <?php foreach ($row as $key => $value): ?>
                                                    <?php echo $key; ?>: <?php echo $value ?? 'NULL'; ?><br>
                                                <?php endforeach; ?>
                                                <hr>
                                            <?php endforeach; ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- 修正案の表示 -->
    <div class="row">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning">
                    <h5><i class="fas fa-wrench"></i> 推奨修正アクション</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6>検出された問題：</h6>
                        <ul>
                            <li><strong>ride_records テーブル</strong>: transportation_type カラムが存在しない可能性</li>
                            <li><strong>daily_inspections テーブル</strong>: cabin_brake_pedal カラムが存在しない可能性</li>
                        </ul>
                    </div>
                    
                    <div class="text-center">
                        <a href="fix_table_structure.php" class="btn btn-warning btn-lg me-3">
                            <i class="fas fa-tools"></i> テーブル構造を自動修正
                        </a>
                        <a href="adaptive_export_document.php" class="btn btn-success btn-lg">
                            <i class="fas fa-download"></i> 適応型出力システム使用
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="emergency_audit_kit.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> 緊急監査キットに戻る
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>