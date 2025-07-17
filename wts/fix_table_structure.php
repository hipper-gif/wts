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

$fix_results = [];
$fix_executed = false;

// テーブル修正実行
if (isset($_POST['fix_tables'])) {
    $fix_executed = true;
    
    // 1. ride_records テーブルの修正
    $fix_results[] = fixRideRecordsTable($pdo);
    
    // 2. daily_inspections テーブルの修正
    $fix_results[] = fixDailyInspectionsTable($pdo);
    
    // 3. 不足しているテーブルの作成
    $fix_results[] = createMissingTables($pdo);
    
    // 4. データ整合性の確認
    $fix_results[] = verifyDataIntegrity($pdo);
}

// ride_records テーブルの修正
function fixRideRecordsTable($pdo) {
    $result = ['table' => 'ride_records', 'actions' => [], 'status' => 'success'];
    
    try {
        // 現在のテーブル構造を確認
        $stmt = $pdo->query("DESCRIBE ride_records");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        
        // 必要なカラムを追加
        $required_columns = [
            'transportation_type' => "VARCHAR(50) DEFAULT '未設定'",
            'payment_method' => "VARCHAR(20) DEFAULT '現金'",
            'driver_id' => "INT",
            'vehicle_id' => "INT",
            'ride_date' => "DATE"
        ];
        
        foreach ($required_columns as $column => $definition) {
            if (!in_array($column, $columns)) {
                $sql = "ALTER TABLE ride_records ADD COLUMN {$column} {$definition}";
                $pdo->exec($sql);
                $result['actions'][] = "✅ {$column} カラムを追加しました";
            } else {
                $result['actions'][] = "ℹ️ {$column} カラムは既に存在します";
            }
        }
        
        // ride_date にデータがない場合、created_at から設定
        $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE ride_date IS NULL");
        $null_count = $stmt->fetchColumn();
        
        if ($null_count > 0) {
            $pdo->exec("UPDATE ride_records SET ride_date = DATE(created_at) WHERE ride_date IS NULL");
            $result['actions'][] = "✅ ride_date を {$null_count} 件設定しました";
        }
        
    } catch (PDOException $e) {
        $result['status'] = 'error';
        $result['actions'][] = "❌ エラー: " . $e->getMessage();
    }
    
    return $result;
}

// daily_inspections テーブルの修正
function fixDailyInspectionsTable($pdo) {
    $result = ['table' => 'daily_inspections', 'actions' => [], 'status' => 'success'];
    
    try {
        // 現在のテーブル構造を確認
        $stmt = $pdo->query("DESCRIBE daily_inspections");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        
        // 必要なカラムを追加
        $required_columns = [
            'cabin_brake_pedal' => "TINYINT DEFAULT 1",
            'cabin_parking_brake' => "TINYINT DEFAULT 1",
            'lighting_headlights' => "TINYINT DEFAULT 1",
            'lighting_taillights' => "TINYINT DEFAULT 1",
            'engine_oil' => "TINYINT DEFAULT 1",
            'brake_fluid' => "TINYINT DEFAULT 1",
            'tire_condition' => "TINYINT DEFAULT 1"
        ];
        
        foreach ($required_columns as $column => $definition) {
            if (!in_array($column, $columns)) {
                $sql = "ALTER TABLE daily_inspections ADD COLUMN {$column} {$definition}";
                $pdo->exec($sql);
                $result['actions'][] = "✅ {$column} カラムを追加しました";
            } else {
                $result['actions'][] = "ℹ️ {$column} カラムは既に存在します";
            }
        }
        
        // 既存データの正規化
        $pdo->exec("UPDATE daily_inspections SET cabin_brake_pedal = 1 WHERE cabin_brake_pedal IS NULL");
        $pdo->exec("UPDATE daily_inspections SET cabin_parking_brake = 1 WHERE cabin_parking_brake IS NULL");
        $pdo->exec("UPDATE daily_inspections SET lighting_headlights = 1 WHERE lighting_headlights IS NULL");
        $pdo->exec("UPDATE daily_inspections SET lighting_taillights = 1 WHERE lighting_taillights IS NULL");
        
        $result['actions'][] = "✅ 既存データを正規化しました";
        
    } catch (PDOException $e) {
        $result['status'] = 'error';
        $result['actions'][] = "❌ エラー: " . $e->getMessage();
    }
    
    return $result;
}

// 不足しているテーブルの作成
function createMissingTables($pdo) {
    $result = ['table' => 'missing_tables', 'actions' => [], 'status' => 'success'];
    
    try {
        // post_duty_calls テーブルの作成
        $stmt = $pdo->query("SHOW TABLES LIKE 'post_duty_calls'");
        if ($stmt->rowCount() == 0) {
            $sql = "
            CREATE TABLE post_duty_calls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                driver_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                call_date DATE NOT NULL,
                call_time TIME NOT NULL,
                caller_name VARCHAR(100) DEFAULT '点呼者',
                alcohol_check_value DECIMAL(5,3) DEFAULT 0.000,
                health_condition TINYINT DEFAULT 1,
                fatigue_condition TINYINT DEFAULT 1,
                alcohol_condition TINYINT DEFAULT 1,
                vehicle_condition TINYINT DEFAULT 1,
                accident_violation TINYINT DEFAULT 1,
                equipment_return TINYINT DEFAULT 1,
                report_completion TINYINT DEFAULT 1,
                remarks TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $pdo->exec($sql);
            $result['actions'][] = "✅ post_duty_calls テーブルを作成しました";
        } else {
            $result['actions'][] = "ℹ️ post_duty_calls テーブルは既に存在します";
        }
        
        // periodic_inspections テーブルの作成
        $stmt = $pdo->query("SHOW TABLES LIKE 'periodic_inspections'");
        if ($stmt->rowCount() == 0) {
            $sql = "
            CREATE TABLE periodic_inspections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT NOT NULL,
                inspection_date DATE NOT NULL,
                inspector_name VARCHAR(100) DEFAULT '点検者',
                next_inspection_date DATE,
                steering_system_result VARCHAR(10) DEFAULT '○',
                brake_system_result VARCHAR(10) DEFAULT '○',
                running_system_result VARCHAR(10) DEFAULT '○',
                suspension_system_result VARCHAR(10) DEFAULT '○',
                powertrain_result VARCHAR(10) DEFAULT '○',
                electrical_system_result VARCHAR(10) DEFAULT '○',
                engine_result VARCHAR(10) DEFAULT '○',
                co_concentration DECIMAL(5,2),
                hc_concentration DECIMAL(5,2),
                maintenance_company VARCHAR(200),
                remarks TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $pdo->exec($sql);
            $result['actions'][] = "✅ periodic_inspections テーブルを作成しました";
        } else {
            $result['actions'][] = "ℹ️ periodic_inspections テーブルは既に存在します";
        }
        
        // departure_records テーブルの確認・作成
        $stmt = $pdo->query("SHOW TABLES LIKE 'departure_records'");
        if ($stmt->rowCount() == 0) {
            $sql = "
            CREATE TABLE departure_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                driver_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                departure_date DATE NOT NULL,
                departure_time TIME NOT NULL,
                weather VARCHAR(20) DEFAULT '晴',
                departure_mileage INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $pdo->exec($sql);
            $result['actions'][] = "✅ departure_records テーブルを作成しました";
        } else {
            $result['actions'][] = "ℹ️ departure_records テーブルは既に存在します";
        }
        
        // arrival_records テーブルの確認・作成
        $stmt = $pdo->query("SHOW TABLES LIKE 'arrival_records'");
        if ($stmt->rowCount() == 0) {
            $sql = "
            CREATE TABLE arrival_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                departure_record_id INT,
                driver_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                arrival_date DATE NOT NULL,
                arrival_time TIME NOT NULL,
                arrival_mileage INT DEFAULT 0,
                total_distance INT DEFAULT 0,
                fuel_cost INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $pdo->exec($sql);
            $result['actions'][] = "✅ arrival_records テーブルを作成しました";
        } else {
            $result['actions'][] = "ℹ️ arrival_records テーブルは既に存在します";
        }
        
    } catch (PDOException $e) {
        $result['status'] = 'error';
        $result['actions'][] = "❌ エラー: " . $e->getMessage();
    }
    
    return $result;
}

// データ整合性の確認
function verifyDataIntegrity($pdo) {
    $result = ['table' => 'data_integrity', 'actions' => [], 'status' => 'success'];
    
    try {
        // 各テーブルのレコード数確認
        $tables = ['users', 'vehicles', 'pre_duty_calls', 'daily_inspections', 'ride_records'];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
                $count = $stmt->fetchColumn();
                $result['actions'][] = "ℹ️ {$table}: {$count} 件";
            } catch (PDOException $e) {
                $result['actions'][] = "❌ {$table}: アクセスエラー";
            }
        }
        
        // 基本データの確認
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $user_count = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles");
        $vehicle_count = $stmt->fetchColumn();
        
        if ($user_count == 0) {
            $result['actions'][] = "⚠️ ユーザーデータが不足しています";
        }
        
        if ($vehicle_count == 0) {
            $result['actions'][] = "⚠️ 車両データが不足しています";
        }
        
        if ($user_count > 0 && $vehicle_count > 0) {
            $result['actions'][] = "✅ 基本データは正常です";
        }
        
    } catch (PDOException $e) {
        $result['status'] = 'error';
        $result['actions'][] = "❌ エラー: " . $e->getMessage();
    }
    
    return $result;
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔧 テーブル構造自動修正</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            <div class="alert alert-warning">
                <h3><i class="fas fa-tools"></i> テーブル構造自動修正</h3>
                <p>出力エラーを解決するためのテーブル構造修正を実行します</p>
            </div>
        </div>
    </div>

    <?php if (!$fix_executed): ?>
    <!-- 修正実行前の説明 -->
    <div class="row">
        <div class="col-12">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-info-circle"></i> 実行される修正内容</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>ride_records テーブル</h6>
                            <ul>
                                <li>transportation_type カラム追加</li>
                                <li>payment_method カラム追加</li>
                                <li>driver_id, vehicle_id カラム追加</li>
                                <li>ride_date カラム追加</li>
                                <li>既存データの正規化</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>daily_inspections テーブル</h6>
                            <ul>
                                <li>cabin_brake_pedal カラム追加</li>
                                <li>cabin_parking_brake カラム追加</li>
                                <li>lighting_headlights カラム追加</li>
                                <li>lighting_taillights カラム追加</li>
                                <li>その他点検項目カラム追加</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>不足テーブルの作成</h6>
                            <ul>
                                <li>post_duty_calls テーブル（乗務後点呼）</li>
                                <li>periodic_inspections テーブル（定期点検）</li>
                                <li>departure_records テーブル（出庫記録）</li>
                                <li>arrival_records テーブル（入庫記録）</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <form method="POST">
                            <button type="submit" name="fix_tables" class="btn btn-warning btn-lg">
                                <i class="fas fa-wrench"></i> テーブル構造修正実行
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- 修正実行後の結果表示 -->
    <div class="row">
        <?php foreach ($fix_results as $result): ?>
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header <?php echo $result['status'] == 'success' ? 'bg-success' : 'bg-danger'; ?> text-white">
                    <h5>
                        <i class="fas <?php echo $result['status'] == 'success' ? 'fa-check' : 'fa-times'; ?>"></i>
                        <?php echo $result['table']; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($result['actions'] as $action): ?>
                    <div class="mb-2">
                        <?php echo $action; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- 修正完了後のアクション -->
    <div class="row">
        <div class="col-12">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h5><i class="fas fa-check-circle"></i> 修正完了 - 次のステップ</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <a href="adaptive_export_document.php?type=call_records&start=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&end=<?php echo date('Y-m-d'); ?>" target="_blank" class="btn btn-primary btn-lg mb-2">
                                <i class="fas fa-phone"></i><br>
                                点呼記録テスト
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="adaptive_export_document.php?type=driving_reports&start=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&end=<?php echo date('Y-m-d'); ?>" target="_blank" class="btn btn-success btn-lg mb-2">
                                <i class="fas fa-car"></i><br>
                                運転日報テスト
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="adaptive_export_document.php?type=inspection_records&start=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&end=<?php echo date('Y-m-d'); ?>" target="_blank" class="btn btn-warning btn-lg mb-2">
                                <i class="fas fa-wrench"></i><br>
                                点検記録テスト
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="adaptive_export_document.php?type=emergency_kit&start=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&end=<?php echo date('Y-m-d'); ?>" target="_blank" class="btn btn-danger btn-lg mb-2">
                                <i class="fas fa-rocket"></i><br>
                                緊急キットテスト
                            </a>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <h6><i class="fas fa-lightbulb"></i> 次に実行すべき項目</h6>
                        <ol>
                            <li><strong>上記の出力テスト</strong> - 各種帳票が正常に出力されることを確認</li>
                            <li><strong>データ生成</strong> - <a href="audit_data_manager.php">audit_data_manager.php</a> でサンプルデータを生成</li>
                            <li><strong>監査キット確認</strong> - <a href="emergency_audit_kit.php">emergency_audit_kit.php</a> で監査準備度を確認</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ナビゲーション -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="check_table_structure.php" class="btn btn-info me-2">
                <i class="fas fa-database"></i> テーブル構造確認
            </a>
            <a href="audit_data_manager.php" class="btn btn-success me-2">
                <i class="fas fa-chart-bar"></i> データ管理
            </a>
            <a href="emergency_audit_kit.php" class="btn btn-danger">
                <i class="fas fa-exclamation-triangle"></i> 緊急監査キット
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($fix_executed): ?>
<script>
window.addEventListener('load', function() {
    // 修正完了後のメッセージ
    let successCount = 0;
    let errorCount = 0;
    
    <?php foreach ($fix_results as $result): ?>
    <?php if ($result['status'] == 'success'): ?>
    successCount++;
    <?php else: ?>
    errorCount++;
    <?php endif; ?>
    <?php endforeach; ?>
    
    setTimeout(() => {
        if (errorCount == 0) {
            alert('🎉 テーブル構造の修正が完了しました！\n\n' +
                  '✅ 修正完了: ' + successCount + '件\n' +
                  '今すぐ出力テストを実行してください。');
        } else {
            alert('⚠️ 修正処理が完了しました\n\n' +
                  '✅ 成功: ' + successCount + '件\n' +
                  '❌ エラー: ' + errorCount + '件\n\n' +
                  '出力テストで動作を確認してください。');
        }
    }, 1000);
});
</script>
<?php endif; ?>

</body>
</html>