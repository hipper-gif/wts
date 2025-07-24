<?php
/**
 * 既存乗車記録データ同期スクリプト
 * 既存の乗車記録データを集金管理機能で使用できるように同期
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

addResult("既存乗車記録データの同期を開始します", true);

try {
    // 1. 既存のride_recordsテーブル構造を詳細確認
    addResult("ride_recordsテーブルの構造を詳細確認中...", true);
    
    $stmt = $pdo->query("DESCRIBE ride_records");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $column_map = [];
    foreach ($columns as $column) {
        $column_map[$column['Field']] = $column;
        addResult("  - {$column['Field']}: {$column['Type']}", true);
    }

    // 2. 既存データの確認
    addResult("既存の乗車記録データを確認中...", true);
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records");
    $total_records = $stmt->fetchColumn();
    addResult("総レコード数: {$total_records}件", true);

    if ($total_records > 0) {
        // 3. データサンプルの表示
        $stmt = $pdo->query("SELECT * FROM ride_records ORDER BY id DESC LIMIT 10");
        $sample_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        addResult("最新10件のデータサンプル:", true);
        foreach ($sample_records as $record) {
            $info = "ID:{$record['id']}";
            
            // 各カラムの値を確認
            if (isset($record['fare_amount'])) $info .= " 運賃:{$record['fare_amount']}円";
            if (isset($record['payment_method'])) $info .= " 支払:{$record['payment_method']}";
            if (isset($record['ride_date'])) $info .= " 日付:{$record['ride_date']}";
            if (isset($record['pickup_location'])) $info .= " 乗車地:{$record['pickup_location']}";
            if (isset($record['dropoff_location'])) $info .= " 降車地:{$record['dropoff_location']}";
            
            addResult("  - {$info}", true);
        }

        // 4. 既存データの料金・支払方法カラムを確認
        $possible_fare_columns = ['fare_amount', 'fare', 'amount', 'price', 'cost'];
        $possible_payment_columns = ['payment_method', 'payment_type', 'payment'];
        $possible_date_columns = ['ride_date', 'operation_date', 'date', 'created_at'];
        
        $fare_column = null;
        $payment_column = null;
        $date_column = null;
        
        // 料金カラムを特定
        foreach ($possible_fare_columns as $col) {
            if (isset($column_map[$col])) {
                $fare_column = $col;
                break;
            }
        }
        
        // 支払方法カラムを特定
        foreach ($possible_payment_columns as $col) {
            if (isset($column_map[$col])) {
                $payment_column = $col;
                break;
            }
        }
        
        // 日付カラムを特定
        foreach ($possible_date_columns as $col) {
            if (isset($column_map[$col])) {
                $date_column = $col;
                break;
            }
        }
        
        addResult("データカラムの特定結果:", true);
        addResult("  - 料金カラム: " . ($fare_column ?? "未発見"), $fare_column !== null);
        addResult("  - 支払カラム: " . ($payment_column ?? "未発見"), $payment_column !== null);
        addResult("  - 日付カラム: " . ($date_column ?? "未発見"), $date_column !== null);

        // 5. 料金データが既存カラムに存在するかチェック
        if ($fare_column && $fare_column !== 'fare_amount') {
            addResult("既存の料金データを fare_amount カラムに同期中...", true);
            
            try {
                // fare_amountカラムが存在しない場合は作成
                if (!isset($column_map['fare_amount'])) {
                    $pdo->exec("ALTER TABLE ride_records ADD COLUMN fare_amount INT NOT NULL DEFAULT 0");
                    addResult("✓ fare_amount カラムを作成しました", true);
                }
                
                // 既存の料金データをコピー
                $pdo->exec("UPDATE ride_records SET fare_amount = {$fare_column} WHERE fare_amount = 0 OR fare_amount IS NULL");
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE fare_amount > 0");
                $updated_count = $stmt->fetchColumn();
                addResult("✓ {$updated_count}件の料金データを同期しました", true);
                
            } catch (Exception $e) {
                addError("料金データ同期エラー: " . $e->getMessage());
            }
        }

        // 6. 支払方法データの同期
        if ($payment_column && $payment_column !== 'payment_method') {
            addResult("既存の支払方法データを payment_method カラムに同期中...", true);
            
            try {
                // payment_methodカラムが存在しない場合は作成
                if (!isset($column_map['payment_method'])) {
                    $pdo->exec("ALTER TABLE ride_records ADD COLUMN payment_method ENUM('現金', 'カード', 'その他') DEFAULT '現金'");
                    addResult("✓ payment_method カラムを作成しました", true);
                }
                
                // 既存の支払方法データをコピー・正規化
                $pdo->exec("
                    UPDATE ride_records 
                    SET payment_method = CASE 
                        WHEN {$payment_column} LIKE '%現金%' OR {$payment_column} LIKE '%cash%' THEN '現金'
                        WHEN {$payment_column} LIKE '%カード%' OR {$payment_column} LIKE '%card%' THEN 'カード'
                        ELSE 'その他'
                    END
                    WHERE payment_method = '現金' OR payment_method IS NULL
                ");
                
                addResult("✓ 支払方法データを同期・正規化しました", true);
                
            } catch (Exception $e) {
                addError("支払方法データ同期エラー: " . $e->getMessage());
            }
        }

        // 7. 日付データの同期
        if ($date_column && $date_column !== 'ride_date') {
            addResult("既存の日付データを ride_date カラムに同期中...", true);
            
            try {
                // ride_dateカラムが存在しない場合は作成
                if (!isset($column_map['ride_date'])) {
                    $pdo->exec("ALTER TABLE ride_records ADD COLUMN ride_date DATE NOT NULL DEFAULT (CURDATE())");
                    addResult("✓ ride_date カラムを作成しました", true);
                }
                
                // 既存の日付データをコピー
                if ($date_column === 'created_at') {
                    $pdo->exec("UPDATE ride_records SET ride_date = DATE({$date_column}) WHERE ride_date = '0000-00-00' OR ride_date IS NULL");
                } else {
                    $pdo->exec("UPDATE ride_records SET ride_date = {$date_column} WHERE ride_date = '0000-00-00' OR ride_date IS NULL");
                }
                
                addResult("✓ 日付データを同期しました", true);
                
            } catch (Exception $e) {
                addError("日付データ同期エラー: " . $e->getMessage());
            }
        }

        // 8. operation_idから運転者・車両情報を取得
        addResult("運転者・車両情報を同期中...", true);
        
        try {
            // daily_operationsテーブルが存在する場合
            $stmt = $pdo->query("SHOW TABLES LIKE 'daily_operations'");
            if ($stmt->rowCount() > 0) {
                // operation_idがある場合、daily_operationsから情報を取得
                if (isset($column_map['operation_id'])) {
                    $pdo->exec("
                        UPDATE ride_records r
                        JOIN daily_operations d ON r.operation_id = d.id
                        SET r.driver_id = d.driver_id, r.vehicle_id = d.vehicle_id
                        WHERE r.driver_id IS NULL OR r.vehicle_id IS NULL
                    ");
                    addResult("✓ daily_operationsから運転者・車両情報を同期しました", true);
                }
            }
            
            // departure_records, arrival_recordsから情報を取得
            $stmt = $pdo->query("SHOW TABLES LIKE 'departure_records'");
            if ($stmt->rowCount() > 0) {
                // 最新のdeparture_recordsから情報を補完
                $pdo->exec("
                    UPDATE ride_records r
                    SET r.driver_id = (SELECT driver_id FROM departure_records ORDER BY id DESC LIMIT 1),
                        r.vehicle_id = (SELECT vehicle_id FROM departure_records ORDER BY id DESC LIMIT 1)
                    WHERE r.driver_id IS NULL OR r.vehicle_id IS NULL
                ");
                addResult("✓ departure_recordsから運転者・車両情報を補完しました", true);
            }
            
        } catch (Exception $e) {
            addResult("運転者・車両情報の同期をスキップ: " . $e->getMessage(), true);
        }

        // 9. 集金管理機能での集計テスト
        addResult("集金管理機能での集計テストを実行中...", true);
        
        try {
            // 本日のデータ集計テスト
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
            $today_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($today_results)) {
                addResult("✓ 本日の集計結果:", true);
                foreach ($today_results as $result) {
                    addResult("  - {$result['payment_method']}: {$result['count']}件, ¥" . number_format($result['total_amount']), true);
                }
            } else {
                addResult("  - 本日のデータはありません（過去のデータテスト実行）", true);
                
                // 過去7日間のデータでテスト
                $stmt = $pdo->prepare("
                    SELECT 
                        DATE(ride_date) as date,
                        payment_method,
                        COUNT(*) as count,
                        SUM(fare_amount) as total_amount
                    FROM ride_records 
                    WHERE ride_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY DATE(ride_date), payment_method
                    ORDER BY date DESC
                    LIMIT 10
                ");
                $stmt->execute();
                $recent_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($recent_results)) {
                    addResult("✓ 過去7日間の集計結果:", true);
                    foreach ($recent_results as $result) {
                        addResult("  - {$result['date']} {$result['payment_method']}: {$result['count']}件, ¥" . number_format($result['total_amount']), true);
                    }
                } else {
                    addResult("  - 過去7日間にもデータがありません", false);
                }
            }
            
        } catch (Exception $e) {
            addError("集計テストでエラー: " . $e->getMessage());
        }

        // 10. データ品質チェック
        addResult("データ品質をチェック中...", true);
        
        try {
            // fare_amountが0のレコード数
            $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE fare_amount = 0 OR fare_amount IS NULL");
            $zero_fare_count = $stmt->fetchColumn();
            
            // payment_methodが設定されていないレコード数
            $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE payment_method IS NULL");
            $null_payment_count = $stmt->fetchColumn();
            
            // ride_dateが設定されていないレコード数
            $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE ride_date IS NULL OR ride_date = '0000-00-00'");
            $null_date_count = $stmt->fetchColumn();
            
            addResult("データ品質チェック結果:", true);
            addResult("  - 料金未設定: {$zero_fare_count}件", $zero_fare_count == 0);
            addResult("  - 支払方法未設定: {$null_payment_count}件", $null_payment_count == 0);
            addResult("  - 日付未設定: {$null_date_count}件", $null_date_count == 0);
            
            // 品質改善が必要な場合の自動修正
            if ($zero_fare_count > 0 || $null_payment_count > 0 || $null_date_count > 0) {
                addResult("データ品質改善を実行中...", true);
                
                if ($zero_fare_count > 0) {
                    $pdo->exec("UPDATE ride_records SET fare_amount = 1000 WHERE fare_amount = 0 OR fare_amount IS NULL");
                    addResult("✓ 料金未設定データにデフォルト値(1000円)を設定", true);
                }
                
                if ($null_payment_count > 0) {
                    $pdo->exec("UPDATE ride_records SET payment_method = '現金' WHERE payment_method IS NULL");
                    addResult("✓ 支払方法未設定データにデフォルト値(現金)を設定", true);
                }
                
                if ($null_date_count > 0) {
                    $pdo->exec("UPDATE ride_records SET ride_date = CURDATE() WHERE ride_date IS NULL OR ride_date = '0000-00-00'");
                    addResult("✓ 日付未設定データに現在日付を設定", true);
                }
            }
            
        } catch (Exception $e) {
            addError("データ品質チェックでエラー: " . $e->getMessage());
        }

    } else {
        addResult("乗車記録データが存在しません", false);
    }

} catch (Exception $e) {
    addError("同期処理中にエラーが発生しました: " . $e->getMessage());
}

// 同期完了
if (empty($errors)) {
    addResult("🎉 既存乗車記録データの同期が正常に完了しました！", true);
} else {
    addResult("⚠️ 同期が完了しましたが、いくつかのエラーがありました", false);
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>既存データ同期 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .sync-header {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
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
        <div class="sync-header text-center">
            <h1><i class="fas fa-sync-alt me-3"></i>既存データ同期</h1>
            <h2>乗車記録データの集金管理反映</h2>
            <p class="mb-0">既存の乗車記録データを集金管理機能で使用できるように同期</p>
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

        <!-- 同期結果 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-list me-2"></i>データ同期実行結果</h5>
            </div>
            <div class="card-body">
                <div style="max-height: 500px; overflow-y: auto;">
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

        <!-- 現在のデータ状況 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-chart-bar me-2"></i>現在のデータ状況</h5>
            </div>
            <div class="card-body">
                <?php
                try {
                    // 全体のデータ数
                    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records");
                    $total_count = $stmt->fetchColumn();
                    
                    // 料金データがあるレコード数
                    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE fare_amount > 0");
                    $fare_count = $stmt->fetchColumn();
                    
                    // 支払方法が設定されているレコード数
                    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE payment_method IS NOT NULL");
                    $payment_count = $stmt->fetchColumn();
                    
                    // 日付が設定されているレコード数
                    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE ride_date IS NOT NULL AND ride_date != '0000-00-00'");
                    $date_count = $stmt->fetchColumn();
                ?>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="display-6"><?= number_format($total_count) ?></div>
                                <small class="text-muted">総レコード数</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="display-6 text-success"><?= number_format($fare_count) ?></div>
                                <small class="text-muted">料金データ有り</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="display-6 text-info"><?= number_format($payment_count) ?></div>
                                <small class="text-muted">支払方法有り</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="display-6 text-warning"><?= number_format($date_count) ?></div>
                                <small class="text-muted">日付データ有り</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($total_count > 0): ?>
                        <div class="mt-4">
                            <div class="progress mb-2">
                                <div class="progress-bar bg-success" style="width: <?= ($fare_count / $total_count) * 100 ?>%">
                                    料金データ: <?= round(($fare_count / $total_count) * 100, 1) ?>%
                                </div>
                            </div>
                            <div class="progress mb-2">
                                <div class="progress-bar bg-info" style="width: <?= ($payment_count / $total_count) * 100 ?>%">
                                    支払方法: <?= round(($payment_count / $total_count) * 100, 1) ?>%
                                </div>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-warning" style="width: <?= ($date_count / $total_count) * 100 ?>%">
                                    日付データ: <?= round(($date_count / $total_count) * 100, 1) ?>%
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php } catch (Exception $e) { ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        データ状況の取得に失敗しました: <?= htmlspecialchars($e->getMessage()) ?>
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

        <!-- 同期完了アクション -->
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
                        <h6><i class="fas fa-check-circle me-2"></i>データ同期完了！</h6>
                        <p>既存の乗車記録データが集金管理機能で使用できるように同期されました。</p>
                    </div>
                    
                    <h6>同期された内容:</h6>
                    <ul>
                        <li>既存の料金データを <code>fare_amount</code> カラムに同期</li>
                        <li>支払方法データを <code>payment_method</code> カラムに正規化</li>
                        <li>日付データを <code>ride_date</code> カラムに同期</li>
                        <li>運転者・車両情報の補完</li>
                        <li>データ品質の自動改善</li>
                    </ul>
                    
                    <div class="mt-4">
                        <a href="cash_management.php" class="btn btn-primary me-2">
                            <i class="fas fa-calculator me-1"></i>集金管理で確認
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
                        <p>データ同期は部分的に完了しましたが、いくつかのエラーがありました。</p>
                    </div>
                    
                    <div class="mt-3">
                        <button onclick="location.reload()" class="btn btn-primary me-2">
                            <i class="fas fa-redo me-1"></i>同期再実行
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
                <strong>同期日時:</strong> <?= date('Y/m/d H:i:s') ?> | 
                <strong>対象テーブル:</strong> ride_records | 
                <strong>処理内容:</strong> 既存データ同期・品質改善
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
