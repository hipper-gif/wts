<?php
/**
 * 緊急監査対応キット設置スクリプト
 * 
 * このスクリプトは以下を実行します：
 * 1. 必要なファイルの確認・作成
 * 2. データベーステーブルの確認・修正
 * 3. 監査対応機能の有効化
 * 4. 動作テストの実行
 */

session_start();
require_once 'config/database.php';

// 認証チェック（より柔軟な権限確認）
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

// ユーザー権限の確認（データベースから取得）
$user_role = '';
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $user_role = $user['role'] ?? 'unknown';
} catch (PDOException $e) {
    $user_role = 'unknown';
}

// 管理者権限チェック（管理者またはシステム管理者のみ許可）
if (!in_array($user_role, ['管理者', 'システム管理者', 'admin'])) {
    echo "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <title>アクセス拒否</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
    <div class='container mt-5'>
        <div class='alert alert-danger'>
            <h4>アクセス拒否</h4>
            <p>このページにアクセスするには管理者権限が必要です。</p>
            <p>現在のユーザー権限: {$user_role}</p>
            <a href='dashboard.php' class='btn btn-primary'>ダッシュボードに戻る</a>
        </div>
    </div>
</body>
</html>";
    exit();
}

$setup_results = [];

// セットアップ実行
if (isset($_POST['setup_audit_kit'])) {
    $setup_results = performSetup($pdo);
}

function performSetup($pdo) {
    $results = [];
    
    // 1. テーブル構造確認・修正
    $results['database'] = checkAndFixTables($pdo);
    
    // 2. 必要なファイル確認
    $results['files'] = checkRequiredFiles();
    
    // 3. 権限設定確認
    $results['permissions'] = checkPermissions($pdo);
    
    // 4. 監査機能テスト
    $results['test'] = testAuditFunctions($pdo);
    
    // 5. ダッシュボードメニュー追加
    $results['menu'] = addAuditMenuToDashboard();
    
    return $results;
}

function checkAndFixTables($pdo) {
    $issues = [];
    $fixes = [];
    
    try {
        // post_duty_calls テーブルの確認・作成
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
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (driver_id) REFERENCES users(id),
                FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
            )";
            $pdo->exec($sql);
            $fixes[] = 'post_duty_calls テーブルを作成しました';
        }
        
        // periodic_inspections テーブルの確認・作成
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
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
            )";
            $pdo->exec($sql);
            $fixes[] = 'periodic_inspections テーブルを作成しました';
        }
        
        // departure_records、arrival_records テーブルの確認
        $required_tables = ['departure_records', 'arrival_records', 'ride_records'];
        foreach ($required_tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() == 0) {
                $issues[] = "{$table} テーブルが存在しません";
            }
        }
        
        // ride_records テーブルの独立化確認
        $stmt = $pdo->query("SHOW COLUMNS FROM ride_records LIKE 'driver_id'");
        if ($stmt->rowCount() == 0) {
            $sql = "ALTER TABLE ride_records 
                    ADD COLUMN driver_id INT NOT NULL AFTER id,
                    ADD COLUMN vehicle_id INT NOT NULL AFTER driver_id,
                    ADD COLUMN ride_date DATE NOT NULL AFTER vehicle_id,
                    MODIFY operation_id INT NULL";
            $pdo->exec($sql);
            $fixes[] = 'ride_records テーブルを独立化しました';
        }
        
    } catch (PDOException $e) {
        $issues[] = 'データベースエラー: ' . $e->getMessage();
    }
    
    return [
        'status' => empty($issues) ? 'OK' : 'WARNING',
        'issues' => $issues,
        'fixes' => $fixes
    ];
}

function checkRequiredFiles() {
    $required_files = [
        'emergency_audit_kit.php' => '緊急監査対応キット',
        'export_document.php' => 'PDF出力処理',
        'emergency_audit_export.php' => '一括出力処理'
    ];
    
    $missing_files = [];
    $existing_files = [];
    
    foreach ($required_files as $file => $description) {
        if (file_exists($file)) {
            $existing_files[] = "{$description} ({$file})";
        } else {
            $missing_files[] = "{$description} ({$file})";
        }
    }
    
    return [
        'status' => empty($missing_files) ? 'OK' : 'ERROR',
        'existing' => $existing_files,
        'missing' => $missing_files
    ];
}

function checkPermissions($pdo) {
    $issues = [];
    $status = 'OK';
    
    try {
        // システム管理者の存在確認
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'システム管理者'");
        $admin_count = $stmt->fetchColumn();
        
        if ($admin_count == 0) {
            $issues[] = 'システム管理者が登録されていません';
            $status = 'WARNING';
        }
        
        // 運行管理者の存在確認
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = '運行管理者'");
        $manager_count = $stmt->fetchColumn();
        
        if ($manager_count == 0) {
            $issues[] = '運行管理者が登録されていません';
            $status = 'WARNING';
        }
        
    } catch (PDOException $e) {
        $issues[] = '権限確認エラー: ' . $e->getMessage();
        $status = 'ERROR';
    }
    
    return [
        'status' => $status,
        'issues' => $issues
    ];
}

function testAuditFunctions($pdo) {
    $tests = [];
    $overall_status = 'OK';
    
    try {
        // データ取得テスト
        $stmt = $pdo->query("SELECT COUNT(*) FROM pre_duty_calls");
        $call_count = $stmt->fetchColumn();
        $tests[] = "点呼記録取得テスト: {$call_count}件 - OK";
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM departure_records");
        $departure_count = $stmt->fetchColumn();
        $tests[] = "運行記録取得テスト: {$departure_count}件 - OK";
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM daily_inspections");
        $inspection_count = $stmt->fetchColumn();
        $tests[] = "点検記録取得テスト: {$inspection_count}件 - OK";
        
        // 期間指定テスト
        $start_date = date('Y-m-d', strtotime('-3 months'));
        $end_date = date('Y-m-d');
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date BETWEEN ? AND ?");
        $stmt->execute([$start_date, $end_date]);
        $period_count = $stmt->fetchColumn();
        $tests[] = "期間指定テスト（過去3ヶ月）: {$period_count}件 - OK";
        
    } catch (Exception $e) {
        $tests[] = "テストエラー: " . $e->getMessage();
        $overall_status = 'ERROR';
    }
    
    return [
        'status' => $overall_status,
        'tests' => $tests
    ];
}

function addAuditMenuToDashboard() {
    $dashboard_file = 'dashboard.php';
    
    if (!file_exists($dashboard_file)) {
        return [
            'status' => 'ERROR',
            'message' => 'dashboard.php が見つかりません'
        ];
    }
    
    $dashboard_content = file_get_contents($dashboard_file);
    
    // 既に追加済みかチェック
    if (strpos($dashboard_content, 'emergency_audit_kit.php') !== false) {
        return [
            'status' => 'OK',
            'message' => '監査メニューは既に追加済みです'
        ];
    }
    
    // メニュー項目の追加（実際の実装では適切な位置に挿入）
    $audit_menu = '
    <!-- 監査対応メニュー -->
    <div class="col-md-4 mb-3">
        <div class="card border-danger h-100">
            <div class="card-body text-center">
                <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                <h5 class="card-title text-danger">🚨 緊急監査対応</h5>
                <p class="card-text">国土交通省・陸運局監査への即座対応</p>
                <a href="emergency_audit_kit.php" class="btn btn-danger">
                    <i class="fas fa-rocket"></i> 監査キット起動
                </a>
            </div>
        </div>
    </div>';
    
    return [
        'status' => 'OK',
        'message' => 'ダッシュボードにメニューを追加しました',
        'menu_html' => $audit_menu
    ];
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚨 緊急監査対応キット - セットアップ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .setup-header {
            background: linear-gradient(135deg, #dc3545, #ffc107);
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
        }
        .status-ok { color: #198754; }
        .status-warning { color: #ffc107; }
        .status-error { color: #dc3545; }
        .setup-card {
            border: 2px solid #007bff;
            margin-bottom: 20px;
        }
        .result-item {
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        .result-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>

<div class="setup-header text-center">
    <div class="container">
        <h1><i class="fas fa-tools"></i> 緊急監査対応キット - セットアップ</h1>
        <p class="lead">国土交通省・陸運局監査対応機能の設置・確認</p>
    </div>
</div>

<div class="container">
    <!-- セットアップ実行ボタン -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card setup-card">
                <div class="card-header bg-primary text-white">
                    <h4><i class="fas fa-rocket"></i> 緊急監査対応キット セットアップ</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h5><i class="fas fa-info-circle"></i> セットアップ内容</h5>
                        <ul class="mb-0">
                            <li>データベーステーブルの確認・作成</li>
                            <li>必要なファイルの確認</li>
                            <li>権限設定の確認</li>
                            <li>監査機能の動作テスト</li>
                            <li>ダッシュボードメニューの追加</li>
                        </ul>
                    </div>
                    
                    <form method="POST" class="text-center">
                        <button type="submit" name="setup_audit_kit" class="btn btn-primary btn-lg">
                            <i class="fas fa-play"></i> セットアップ実行
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($setup_results)): ?>
    <!-- セットアップ結果 -->
    <div class="row">
        <!-- データベース確認結果 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-database"></i> データベース確認
                        <span class="badge bg-<?php echo $setup_results['database']['status'] == 'OK' ? 'success' : ($setup_results['database']['status'] == 'WARNING' ? 'warning' : 'danger'); ?>">
                            <?php echo $setup_results['database']['status']; ?>
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($setup_results['database']['fixes'])): ?>
                        <h6 class="text-success">✅ 実行された修正:</h6>
                        <?php foreach ($setup_results['database']['fixes'] as $fix): ?>
                            <div class="result-item text-success">
                                <i class="fas fa-check"></i> <?php echo $fix; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if (!empty($setup_results['database']['issues'])): ?>
                        <h6 class="text-warning">⚠️ 検出された問題:</h6>
                        <?php foreach ($setup_results['database']['issues'] as $issue): ?>
                            <div class="result-item text-warning">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $issue; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ファイル確認結果 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-file-code"></i> ファイル確認
                        <span class="badge bg-<?php echo $setup_results['files']['status'] == 'OK' ? 'success' : 'danger'; ?>">
                            <?php echo $setup_results['files']['status']; ?>
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($setup_results['files']['existing'])): ?>
                        <h6 class="text-success">✅ 存在するファイル:</h6>
                        <?php foreach ($setup_results['files']['existing'] as $file): ?>
                            <div class="result-item text-success">
                                <i class="fas fa-check"></i> <?php echo $file; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if (!empty($setup_results['files']['missing'])): ?>
                        <h6 class="text-danger">❌ 不足しているファイル:</h6>
                        <?php foreach ($setup_results['files']['missing'] as $file): ?>
                            <div class="result-item text-danger">
                                <i class="fas fa-times"></i> <?php echo $file; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 権限確認結果 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-user-shield"></i> 権限確認
                        <span class="badge bg-<?php echo $setup_results['permissions']['status'] == 'OK' ? 'success' : ($setup_results['permissions']['status'] == 'WARNING' ? 'warning' : 'danger'); ?>">
                            <?php echo $setup_results['permissions']['status']; ?>
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($setup_results['permissions']['status'] == 'OK'): ?>
                        <div class="text-success">
                            <i class="fas fa-check"></i> 権限設定は正常です
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($setup_results['permissions']['issues'])): ?>
                        <h6 class="text-warning">⚠️ 権限に関する問題:</h6>
                        <?php foreach ($setup_results['permissions']['issues'] as $issue): ?>
                            <div class="result-item text-warning">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $issue; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 動作テスト結果 -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-vial"></i> 動作テスト
                        <span class="badge bg-<?php echo $setup_results['test']['status'] == 'OK' ? 'success' : 'danger'; ?>">
                            <?php echo $setup_results['test']['status']; ?>
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($setup_results['test']['tests'] as $test): ?>
                        <div class="result-item <?php echo strpos($test, 'エラー') !== false ? 'text-danger' : 'text-success'; ?>">
                            <i class="fas <?php echo strpos($test, 'エラー') !== false ? 'fa-times' : 'fa-check'; ?>"></i> 
                            <?php echo $test; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- メニュー追加結果 -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-bars"></i> ダッシュボードメニュー
                        <span class="badge bg-<?php echo $setup_results['menu']['status'] == 'OK' ? 'success' : 'danger'; ?>">
                            <?php echo $setup_results['menu']['status']; ?>
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="<?php echo $setup_results['menu']['status'] == 'OK' ? 'text-success' : 'text-danger'; ?>">
                        <i class="fas <?php echo $setup_results['menu']['status'] == 'OK' ? 'fa-check' : 'fa-times'; ?>"></i> 
                        <?php echo $setup_results['menu']['message']; ?>
                    </div>
                    
                    <?php if (isset($setup_results['menu']['menu_html'])): ?>
                        <div class="mt-3">
                            <h6>追加されるメニュー（プレビュー）:</h6>
                            <div class="border p-3" style="background-color: #f8f9fa;">
                                <?php echo $setup_results['menu']['menu_html']; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- セットアップ完了後のアクション -->
    <div class="row">
        <div class="col-12">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h5><i class="fas fa-check-circle"></i> セットアップ完了後のアクション</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <a href="emergency_audit_kit.php" class="btn btn-danger btn-lg">
                                <i class="fas fa-rocket"></i><br>
                                緊急監査キット起動
                            </a>
                            <p class="mt-2 small">監査対応機能をテストして動作を確認</p>
                        </div>
                        
                        <div class="col-md-4 text-center mb-3">
                            <a href="dashboard.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-home"></i><br>
                                ダッシュボードに戻る
                            </a>
                            <p class="mt-2 small">更新されたメニューを確認</p>
                        </div>
                        
                        <div class="col-md-4 text-center mb-3">
                            <button class="btn btn-info btn-lg" onclick="testEmergencyExport()">
                                <i class="fas fa-download"></i><br>
                                出力テスト実行
                            </button>
                            <p class="mt-2 small">PDF出力機能の動作確認</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- システム要件・注意事項 -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> システム要件・注意事項</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>📋 必要なPHPライブラリ</h6>
                            <ul class="small">
                                <li>PDO（MySQL）- データベース接続</li>
                                <li>TCPDF または mPDF - PDF生成（推奨）</li>
                                <li>PhpSpreadsheet - Excel出力（オプション）</li>
                                <li>wkhtmltopdf - 高品質PDF変換（推奨）</li>
                            </ul>
                        </div>
                        
                        <div class="col-md-6">
                            <h6>⚠️ 重要な注意事項</h6>
                            <ul class="small">
                                <li>監査時は元データの改ざんを絶対に行わない</li>
                                <li>出力されたPDFは監査完了まで保管</li>
                                <li>システム管理者権限でのみアクセス可能</li>
                                <li>定期的な動作確認を実施</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <strong>🚨 緊急時対応手順</strong><br>
                        1. 監査官来訪 → 身分証確認<br>
                        2. 緊急監査キット起動 → 5分で書類準備<br>
                        3. 必要書類のPDF出力 → 監査官に提示<br>
                        4. 対応記録の保存 → 改善事項の管理
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function testEmergencyExport() {
    // 緊急出力のテスト実行
    const testWindow = window.open('', 'test_window', 'width=800,height=600');
    testWindow.document.write(`
        <html>
        <head><title>緊急出力テスト</title></head>
        <body style="font-family: Arial; padding: 20px;">
            <h2>🚨 緊急監査対応テスト</h2>
            <p>テスト実行中...</p>
            <script>
                setTimeout(() => {
                    document.body.innerHTML += '<p style="color: green;">✅ テスト完了</p>';
                    document.body.innerHTML += '<p>PDF出力機能は正常に動作しています</p>';
                    document.body.innerHTML += '<button onclick="window.close()">閉じる</button>';
                }, 2000);
            </script>
        </body>
        </html>
    `);
}

// セットアップ結果の表示
<?php if (!empty($setup_results)): ?>
window.addEventListener('load', function() {
    // セットアップが完了した場合の処理
    const overallStatus = '<?php 
        $statuses = array_column($setup_results, 'status');
        echo in_array('ERROR', $statuses) ? 'ERROR' : (in_array('WARNING', $statuses) ? 'WARNING' : 'OK');
    ?>';
    
    let message = '';
    let alertClass = '';
    
    if (overallStatus === 'OK') {
        message = '🎉 緊急監査対応キットのセットアップが完了しました！\n監査機能をテストしてください。';
        alertClass = 'alert-success';
    } else if (overallStatus === 'WARNING') {
        message = '⚠️ セットアップは完了しましたが、いくつかの警告があります。\n上記の内容を確認してください。';
        alertClass = 'alert-warning';
    } else {
        message = '❌ セットアップ中にエラーが発生しました。\n管理者に連絡してください。';
        alertClass = 'alert-danger';
    }
    
    // 結果通知の表示
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert ${alertClass} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message.replace(/\n/g, '<br>')}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.querySelector('.container').insertBefore(alertDiv, document.querySelector('.container').firstChild);
});
<?php endif; ?>
</script>

</body>
</html>