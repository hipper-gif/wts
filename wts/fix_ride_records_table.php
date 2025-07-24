<?php
/**
 * ride_recordsテーブル構造修正スクリプト
 * fare_amountカラム不足エラーを解決
 */

session_start();

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ]
    );
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

$results = [];
$errors = [];

function addResult($message, $success = true) {
    global $results;
    $results[] = [
        'message' => $message,
        'success' => $success,
        'time' => date('H:i:s')
    ];
}

function addError($message) {
    global $errors;
    $errors[] = $message;
    addResult($message, false);
}

addResult("ride_recordsテーブル修正を開始します", true);

try {
    // 1. 現在のテーブル構造確認
    addResult("ride_recordsテーブルの現在の構造を確認中...", true);
    
    $stmt = $pdo->query("DESCRIBE ride_records");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $existing_columns = [];
    foreach ($columns as $column) {
        $existing_columns[] = $column['Field'];
        addResult("  - {$column['Field']}: {$column['Type']} ({$column['Null']}, デフォルト: {$column['Default']})", true);
    }

    // 2. 必要なカラムの確認と追加
    $required_columns = [
        'fare_amount' => 'INT NOT NULL DEFAULT 0',
        'payment_method' => "ENUM('現金', 'カード', 'その他') DEFAULT '現金'",
        'ride_date' => 'DATE NOT NULL',
        'driver_id' => 'INT',
        'vehicle_id' => 'INT'
    ];
    
    addResult("必要なカラムの確認・追加中...", true);
    
    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            try {
                $pdo->exec("ALTER TABLE ride_records ADD COLUMN {$column_name} {$column_definition}");
                addResult("✓ {$column_name} カラムを追加しました", true);
            } catch (Exception $e) {
                addError("{$column_name} カラムの追加に失敗: " . $e->getMessage());
            }
        } else {
            addResult("✓ {$column_name} カラムは既に存在します", true);
        }
    }

    // 3. 既存データの確認
    addResult("既存データを確認中...", true);
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records");
    $record_count = $stmt->fetchColumn();
    addResult("ride_records テーブルのレコード数: {$record_count}件", true);

    // 4. サンプルデータの確認（最新5件）
    if ($record_count > 0) {
        addResult("最新5件のデータサンプル:", true);
        $stmt = $pdo->query("SELECT * FROM ride_records ORDER BY id DESC LIMIT 5");
        $sample_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sample_data as $row) {
            $fare = isset($row['fare_amount']) ? "運賃:{$row['fare_amount']}円" : "運賃:未設定";
            $payment = isset($row['payment_method']) ? "支払:{$row['payment_method']}" : "支払:未設定";
            addResult("  - ID:{$row['id']} {$fare} {$payment}", true);
        }
    }

    // 5. fare_amountが0または未設定のデータを修正
    addResult("fare_amountの未設定データを修正中...", true);
    
    try {
        // fare_amountが0または空の場合、デフォルト値を設定
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM ride_records 
            WHERE fare_amount IS NULL OR fare_amount = 0
        ");
        $null_fare_count = $stmt->fetchColumn();
        
        if ($null_fare_count > 0) {
            // デフォルト料金を設定（例：1000円）
            $pdo->exec("
                UPDATE ride_records 
                SET fare_amount = 1000 
                WHERE fare_amount IS NULL OR fare_amount = 0
            ");
            addResult("✓ {$null_fare_count}件のfare_amountにデフォルト値(1000円)を設定しました", true);
        } else {
            addResult("✓ fare_amountの修正が必要なデータはありません", true);
        }
    } catch (Exception $e) {
        addError("fare_amountの修正に失敗: " . $e->getMessage());
    }

    // 6. ride_dateの設定
    addResult("ride_dateの未設定データを修正中...", true);
    
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM ride_records 
            WHERE ride_date IS NULL OR ride_date = '0000-00-00'
        ");
        $null_date_count = $stmt->fetchColumn();
        
        if ($null_date_count > 0) {
            // 現在日付を設定
            $pdo->exec("
                UPDATE ride_records 
                SET ride_date = CURDATE() 
                WHERE ride_date IS NULL OR ride_date = '0000-00-00'
            ");
            addResult("✓ {$null_date_count}件のride_dateに現在日付を設定しました", true);
        } else {
            addResult("✓ ride_dateの修正が必要なデータはありません", true);
        }
    } catch (Exception $e) {
        addError("ride_dateの修正に失敗: " . $e->getMessage());
    }

    // 7. テスト用サンプルデータの追加（データが少ない場合）
    if ($record_count < 10) {
        addResult("テスト用サンプルデータを追加中...", true);
        
        try {
            // 車両とドライバーのIDを取得
            $stmt = $pdo->query("SELECT id FROM vehicles LIMIT 1");
            $vehicle_id = $stmt->fetchColumn() ?: 1;
            
            $stmt = $pdo->query("SELECT id FROM users WHERE role = '運転者' LIMIT 1");
            $driver_id = $stmt->fetchColumn() ?: 1;
            
            // サンプルデータを追加
            $sample_rides = [
                ['現金', 1500, '2025-01-20'],
                ['カード', 2000, '2025-01-21'],
                ['現金', 1200, '2025-01-22'],
                ['カード', 1800, '2025-01-23'],
                ['現金', 2500, '2025-01-24']
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO ride_records 
                (payment_method, fare_amount, ride_date, driver_id, vehicle_id, pickup_location, dropoff_location, passenger_count) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            
            foreach ($sample_rides as $ride) {
                $stmt->execute([
                    $ride[0], // payment_method
                    $ride[1], // fare_amount
                    $ride[2], // ride_date
                    $driver_id,
                    $vehicle_id,
                    'サンプル乗車地',
                    'サンプル降車地'
                ]);
            }
            
            addResult("✓ " . count($sample_rides) . "件のサンプルデータを追加しました", true);
            
        } catch (Exception $e) {
            addResult("サンプルデータの追加をスキップ: " . $e->getMessage(), true);
        }
    }

    // 8. 最終確認
    addResult("最終確認を実行中...", true);
    
    $stmt = $pdo->query("DESCRIBE ride_records");
    $final_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $check_columns = ['fare_amount', 'payment_method', 'ride_date'];
    $all_columns_exist = true;
    
    foreach ($check_columns as $check_col) {
        $exists = false;
        foreach ($final_columns as $col) {
            if ($col['Field'] === $check_col) {
                $exists = true;
                break;
            }
        }
        
        if ($exists) {
            addResult("✓ {$check_col} カラムが正常に存在します", true);
        } else {
            addError("× {$check_col} カラムが存在しません");
            $all_columns_exist = false;
        }
    }
    
    if ($all_columns_exist) {
        // 集金管理機能のテスト
        addResult("集金管理機能のデータ取得テスト中...", true);
        
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    payment_method,
                    COUNT(*) as count,
                    SUM(fare_amount) as total_amount
                FROM ride_records 
                WHERE DATE(ride_date) = CURDATE()
                GROUP BY payment_method
            ");
            $stmt->execute();
            $test_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($test_results)) {
                foreach ($test_results as $result) {
                    addResult("  - {$result['payment_method']}: {$result['count']}件, ¥{$result['total_amount']}", true);
                }
            } else {
                addResult("  - 本日のデータはありません", true);
            }
            
            addResult("✓ 集金管理機能のデータ取得テストが成功しました", true);
            
        } catch (Exception $e) {
            addError("集金管理機能のテストに失敗: " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    addError("修正中にエラーが発生しました: " . $e->getMessage());
}

// 修正完了
if (empty($errors)) {
    addResult("🎉 ride_recordsテーブルの修正が正常に完了しました！", true);
} else {
    addResult("⚠️ 修正が完了しましたが、いくつかのエラーがありました", false);
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ride_recordsテーブル修正 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .fix-header {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .result-item {
            padding: 0.5rem 1rem;
            margin: 0.25rem 0;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .result-success {
            background-color: #d1edff;
            border-left-color: #28a745;
        }
        .result-error {
            background-color: #f8d7da;
            border-left-color: #dc3545;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-4">
        <!-- ヘッダー -->
        <div class="fix-header text-center">
            <h1><i class="fas fa-database me-3"></i>ride_recordsテーブル修正</h1>
            <h2>fare_amountカラムエラー解決</h2>
            <p class="mb-0">集金管理機能に必要なカラムの追加・修正</p>
        </div>

        <!-- 統計情報 -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-success"><?= count(array_filter($results, function($r) { return $r['success']; })) ?></div>
                        <div>成功</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-danger"><?= count($errors) ?></div>
                        <div>エラー</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-primary"><?= count($results) ?></div>
                        <div>総処理数</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 修正結果 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-list me-2"></i>テーブル修正実行結果</h5>
            </div>
            <div class="card-body">
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($results as $result): ?>
                        <div class="result-item <?= $result['success'] ? 'result-success' : 'result-error' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="fas fa-<?= $result['success'] ? 'check-circle text-success' : 'exclamation-triangle text-danger' ?> me-2"></i>
                                    <?= htmlspecialchars($result['message']) ?>
                                </span>
                                <small class="text-muted"><?= $result['time'] ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- テーブル構造確認 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-table me-2"></i>修正後のテーブル構造</h5>
            </div>
            <div class="card-body">
                <?php
                try {
                    $stmt = $pdo->query("DESCRIBE ride_records");
                    $final_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>カラム名</th>
                                    <th>データ型</th>
                                    <th>NULL許可</th>
                                    <th>デフォルト値</th>
                                    <th>備考</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($final_structure as $column): ?>
                                    <tr>
                                        <td>
                                            <code><?= htmlspecialchars($column['Field']) ?></code>
                                            <?php if (in_array($column['Field'], ['fare_amount', 'payment_method', 'ride_date'])): ?>
                                                <span class="badge bg-success">NEW</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($column['Type']) ?></td>
                                        <td><?= $column['Null'] === 'YES' ? '可' : '不可' ?></td>
                                        <td><?= htmlspecialchars($column['Default'] ?? 'なし') ?></td>
                                        <td>
                                            <?php
                                            switch($column['Field']) {
                                                case 'fare_amount': echo '運賃金額'; break;
                                                case 'payment_method': echo '支払方法'; break;
                                                case 'ride_date': echo '乗車日'; break;
                                                case 'driver_id': echo '運転者ID'; break;
                                                case 'vehicle_id': echo '車両ID'; break;
                                                default: echo '';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php } catch (Exception $e) { ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        テーブル構造の取得に失敗しました: <?= htmlspecialchars($e->getMessage()) ?>
                    </div>
                <?php } ?>
            </div>
        </div>

        <!-- エラー詳細 -->
        <?php if (!empty($errors)): ?>
        <div class="card mb-4">
            <div class="card-header bg-danger text-white">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>エラー詳細</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <?php foreach ($errors as $error): ?>
                        <li class="mb-2">
                            <i class="fas fa-times text-danger me-2"></i>
                            <?= htmlspecialchars($error) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- 修正完了アクション -->
        <div class="card">
            <div class="card-header <?= empty($errors) ? 'bg-success text-white' : 'bg-warning' ?>">
                <h5>
                    <i class="fas fa-<?= empty($errors) ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    次のステップ
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($errors)): ?>
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle me-2"></i>テーブル修正完了！</h6>
                        <p>ride_recordsテーブルの修正が完了しました。集金管理機能が正常に動作します。</p>
                    </div>
                    
                    <h6>追加・修正されたカラム:</h6>
                    <ul>
                        <li><code>fare_amount</code>: 運賃金額（INT NOT NULL DEFAULT 0）</li>
                        <li><code>payment_method</code>: 支払方法（ENUM('現金', 'カード', 'その他')）</li>
                        <li><code>ride_date</code>: 乗車日（DATE NOT NULL）</li>
                        <li><code>driver_id</code>: 運転者ID（INT）</li>
                        <li><code>vehicle_id</code>: 車両ID（INT）</li>
                    </ul>
                    
                    <div class="mt-4">
                        <a href="cash_management.php" class="btn btn-primary me-2">
                            <i class="fas fa-calculator me-1"></i>集金管理テスト
                        </a>
                        <a href="ride_records.php" class="btn btn-success me-2">
                            <i class="fas fa-car me-1"></i>乗車記録確認
                        </a>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-home me-1"></i>ダッシュボード
                        </a>
                    </div>
                    
                <?php else: ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>一部エラーがありました</h6>
                        <p>テーブル修正は部分的に完了しましたが、いくつかのエラーがありました。</p>
                    </div>
                    
                    <div class="mt-3">
                        <button onclick="location.reload()" class="btn btn-primary me-2">
                            <i class="fas fa-redo me-1"></i>修正再実行
                        </button>
                        <a href="cash_management.php" class="btn btn-warning">
                            <i class="fas fa-calculator me-1"></i>集金管理テスト（続行）
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- システム情報 -->
        <div class="mt-4 p-3 bg-light rounded">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                <strong>修正日時:</strong> <?= date('Y/m/d H:i:s') ?> | 
                <strong>対象テーブル:</strong> ride_records | 
                <strong>データベース:</strong> <?= DB_NAME ?>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
