<?php
/**
 * 緊急監査対応キット - 簡易セットアップ
 * 権限チェックを緩和したテスト用セットアップ
 */

session_start();
require_once 'config/database.php';

// 基本認証チェックのみ
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

$setup_status = [];
$setup_executed = false;

// セットアップ実行
if (isset($_POST['setup_audit'])) {
    $setup_executed = true;
    
    // 1. post_duty_calls テーブル作成
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'post_duty_calls'");
        if ($stmt->rowCount() == 0) {
            $sql = "
            CREATE TABLE post_duty_calls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                driver_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                call_date DATE NOT NULL,
                call_time TIME NOT NULL,
                caller_name VARCHAR(100),
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
            $setup_status[] = ['status' => 'success', 'message' => 'post_duty_calls テーブルを作成しました'];
        } else {
            $setup_status[] = ['status' => 'info', 'message' => 'post_duty_calls テーブルは既に存在します'];
        }
    } catch (PDOException $e) {
        $setup_status[] = ['status' => 'error', 'message' => 'post_duty_calls テーブル作成エラー: ' . $e->getMessage()];
    }
    
    // 2. periodic_inspections テーブル作成
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'periodic_inspections'");
        if ($stmt->rowCount() == 0) {
            $sql = "
            CREATE TABLE periodic_inspections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT NOT NULL,
                inspection_date DATE NOT NULL,
                inspector_name VARCHAR(100),
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
            $setup_status[] = ['status' => 'success', 'message' => 'periodic_inspections テーブルを作成しました'];
        } else {
            $setup_status[] = ['status' => 'info', 'message' => 'periodic_inspections テーブルは既に存在します'];
        }
    } catch (PDOException $e) {
        $setup_status[] = ['status' => 'error', 'message' => 'periodic_inspections テーブル作成エラー: ' . $e->getMessage()];
    }
    
    // 3. ride_records テーブルの独立化確認
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ride_records LIKE 'driver_id'");
        if ($stmt->rowCount() == 0) {
            $sql = "ALTER TABLE ride_records 
                    ADD COLUMN driver_id INT NOT NULL AFTER id,
                    ADD COLUMN vehicle_id INT NOT NULL AFTER driver_id,
                    ADD COLUMN ride_date DATE NOT NULL AFTER vehicle_id";
            $pdo->exec($sql);
            $setup_status[] = ['status' => 'success', 'message' => 'ride_records テーブルを独立化しました'];
        } else {
            $setup_status[] = ['status' => 'info', 'message' => 'ride_records テーブルは既に独立化済みです'];
        }
    } catch (PDOException $e) {
        $setup_status[] = ['status' => 'warning', 'message' => 'ride_records テーブル更新の一部でエラー: ' . $e->getMessage()];
    }
    
    // 4. 監査機能テスト
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM pre_duty_calls");
        $call_count = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM departure_records");
        $departure_count = $stmt->fetchColumn();
        
        $setup_status[] = ['status' => 'success', 'message' => "データベーステスト完了 - 点呼記録: {$call_count}件, 運行記録: {$departure_count}件"];
    } catch (PDOException $e) {
        $setup_status[] = ['status' => 'error', 'message' => 'データベーステストエラー: ' . $e->getMessage()];
    }
}

// 現在のテーブル状況確認
$table_status = [];
$required_tables = ['users', 'vehicles', 'pre_duty_calls', 'daily_inspections', 'departure_records', 'arrival_records', 'ride_records'];

foreach ($required_tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        $table_status[$table] = ['exists' => true, 'count' => $count];
    } catch (PDOException $e) {
        $table_status[$table] = ['exists' => false, 'error' => $e->getMessage()];
    }
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚨 緊急監査対応キット - 簡易セットアップ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            <div class="alert alert-primary">
                <h4><i class="fas fa-rocket"></i> 緊急監査対応キット - 簡易セットアップ</h4>
                <p>国土交通省・陸運局監査対応機能の設置を行います。</p>
            </div>
        </div>
    </div>

    <!-- 現在のシステム状況 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-database"></i> 現在のシステム状況</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($table_status as $table => $status): ?>
                        <div class="col-md-3 mb-2">
                            <div class="border p-2 rounded">
                                <strong><?php echo $table; ?></strong><br>
                                <?php if ($status['exists']): ?>
                                    <span class="text-success">✅ 存在 (<?php echo $status['count']; ?>件)</span>
                                <?php else: ?>
                                    <span class="text-danger">❌ 未作成</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- セットアップ実行 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5><i class="fas fa-tools"></i> 監査対応機能セットアップ</h5>
                </div>
                <div class="card-body">
                    <?php if (!$setup_executed): ?>
                    <div class="alert alert-warning">
                        <strong>実行される処理:</strong>
                        <ul class="mb-0">
                            <li>post_duty_calls テーブルの作成（乗務後点呼用）</li>
                            <li>periodic_inspections テーブルの作成（定期点検用）</li>
                            <li>ride_records テーブルの独立化</li>
                            <li>監査機能の動作テスト</li>
                        </ul>
                    </div>
                    
                    <form method="POST" class="text-center">
                        <button type="submit" name="setup_audit" class="btn btn-danger btn-lg">
                            <i class="fas fa-play"></i> セットアップ実行
                        </button>
                    </form>
                    <?php else: ?>
                    <h6>セットアップ結果:</h6>
                    <?php foreach ($setup_status as $status): ?>
                    <div class="alert alert-<?php echo $status['status'] == 'success' ? 'success' : ($status['status'] == 'warning' ? 'warning' : ($status['status'] == 'info' ? 'info' : 'danger')); ?>">
                        <?php echo $status['message']; ?>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center">
                        <a href="emergency_audit_kit.php" class="btn btn-success btn-lg me-3">
                            <i class="fas fa-rocket"></i> 緊急監査キット起動
                        </a>
                        <a href="dashboard.php" class="btn btn-primary">
                            <i class="fas fa-home"></i> ダッシュボードに戻る
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 監査対応の説明 -->
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-info-circle"></i> 緊急監査対応機能とは</h6>
                </div>
                <div class="card-body">
                    <ul class="small">
                        <li><strong>5分で監査準備完了</strong> - 必須書類の即座出力</li>
                        <li><strong>国土交通省準拠</strong> - 法定様式での帳票出力</li>
                        <li><strong>改ざん防止</strong> - タイムスタンプ付きPDF</li>
                        <li><strong>監査官対応支援</strong> - 対応マニュアル内蔵</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-exclamation-triangle"></i> 監査時の対応手順</h6>
                </div>
                <div class="card-body">
                    <ol class="small">
                        <li>監査官の身分証明書確認</li>
                        <li>緊急監査キット起動</li>
                        <li>必要書類のPDF出力（5分）</li>
                        <li>監査官に書類提示</li>
                        <li>対応記録の保存</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>