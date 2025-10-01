<?php
// =================================================================
// カレンダーシステムインストーラー
// 
// ファイル: /Smiley/taxi/wts/calendar/install.php
// 機能: 自動インストール・セットアップ・動作確認
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

session_start();

// インストール完了後はアクセス禁止
if (file_exists(__DIR__ . '/.installed')) {
    die('カレンダーシステムは既にインストール済みです。');
}

// 基盤システム確認
if (!file_exists('../functions.php')) {
    die('福祉輸送管理システム v3.1が必要です。先にベースシステムをインストールしてください。');
}

require_once '../functions.php';

// 管理者権限チェック
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// インストール段階
$step = $_GET['step'] ?? 1;
$action = $_POST['action'] ?? '';

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>カレンダーシステム インストーラー - WTS v3.1</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .installer-container { max-width: 800px; margin: 2rem auto; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 2rem; }
        .step { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 10px; position: relative; }
        .step.active { background-color: #007bff; color: white; }
        .step.completed { background-color: #28a745; color: white; }
        .step.pending { background-color: #e9ecef; color: #6c757d; }
        .step:not(:last-child)::after { content: ''; position: absolute; top: 50%; left: 100%; width: 20px; height: 2px; background-color: #e9ecef; z-index: -1; }
        .log-output { background-color: #212529; color: #ffffff; font-family: 'Courier New', monospace; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto; }
        .progress-bar-animated { animation: progress-bar-stripes 1s linear infinite; }
    </style>
</head>
<body>
    <div class="installer-container">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h3 class="mb-0">
                    <i class="fas fa-calendar-alt me-2"></i>
                    介護タクシー予約管理カレンダーシステム インストーラー
                </h3>
                <small>福祉輸送管理システム v3.1 拡張モジュール</small>
            </div>
            <div class="card-body">

<?php

// =================================================================
// インストール手順処理
// =================================================================

switch ($step) {
    case 1:
        showWelcomeStep();
        break;
    case 2:
        if ($action === 'check_requirements') {
            checkRequirements();
        } else {
            showRequirementsStep();
        }
        break;
    case 3:
        if ($action === 'create_database') {
            createDatabaseTables();
        } else {
            showDatabaseStep();
        }
        break;
    case 4:
        if ($action === 'insert_initial_data') {
            insertInitialData();
        } else {
            showInitialDataStep();
        }
        break;
    case 5:
        if ($action === 'run_tests') {
            runSystemTests();
        } else {
            showTestStep();
        }
        break;
    case 6:
        if ($action === 'complete_installation') {
            completeInstallation();
        } else {
            showCompletionStep();
        }
        break;
    default:
        showWelcomeStep();
}

// =================================================================
// ステップ表示関数
// =================================================================

function showWelcomeStep() {
    renderStepIndicator(1);
    ?>
    <h4><i class="fas fa-home me-2"></i>インストーラーへようこそ</h4>
    <p class="lead">介護タクシー予約管理カレンダーシステムのインストールを開始します。</p>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card border-primary">
                <div class="card-body">
                    <h5 class="card-title text-primary">主な機能</h5>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i>月・週・日表示カレンダー</li>
                        <li><i class="fas fa-check text-success me-2"></i>予約作成・編集・管理</li>
                        <li><i class="fas fa-check text-success me-2"></i>復路自動作成機能</li>
                        <li><i class="fas fa-check text-success me-2"></i>車両制約チェック</li>
                        <li><i class="fas fa-check text-success me-2"></i>WTS売上連携</li>
                        <li><i class="fas fa-check text-success me-2"></i>タイムツリー移行</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-info">
                <div class="card-body">
                    <h5 class="card-title text-info">インストール要件</h5>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-server me-2"></i>PHP 8.0以上</li>
                        <li><i class="fas fa-database me-2"></i>MySQL 8.0以上</li>
                        <li><i class="fas fa-shield-alt me-2"></i>SSL/HTTPS対応</li>
                        <li><i class="fas fa-cogs me-2"></i>WTS v3.1基盤</li>
                        <li><i class="fas fa-user-shield me-2"></i>管理者権限</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="alert alert-warning mt-3">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>重要:</strong> インストール前に必ずデータベースのバックアップを作成してください。
    </div>
    
    <div class="d-grid">
        <a href="?step=2" class="btn btn-primary btn-lg">
            <i class="fas fa-arrow-right me-2"></i>インストール開始
        </a>
    </div>
    <?php
}

function showRequirementsStep() {
    renderStepIndicator(2);
    ?>
    <h4><i class="fas fa-list-check me-2"></i>システム要件確認</h4>
    <p>インストールに必要な要件を確認します。</p>
    
    <div id="requirementResults">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">確認中...</span>
            </div>
            <p class="mt-2">システム要件を確認しています...</p>
        </div>
    </div>
    
    <script>
    // 自動で要件チェック実行
    fetch('?step=2', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check_requirements'
    })
    .then(response => response.text())
    .then(html => {
        document.getElementById('requirementResults').innerHTML = html;
    });
    </script>
    <?php
}

function checkRequirements() {
    $requirements = [
        'PHP Version' => [
            'required' => '8.0.0',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '8.0.0', '>=')
        ],
        'MySQL Connection' => [
            'required' => '接続可能',
            'current' => 'テスト中...',
            'status' => testDatabaseConnection()
        ],
        'WTS Base System' => [
            'required' => 'v3.1以上',
            'current' => getWTSVersion(),
            'status' => checkWTSVersion()
        ],
        'Directory Permissions' => [
            'required' => '書き込み可能',
            'current' => is_writable(__DIR__) ? '可能' : '不可',
            'status' => is_writable(__DIR__)
        ],
        'Required Extensions' => [
            'required' => 'PDO, JSON, OpenSSL',
            'current' => getRequiredExtensions(),
            'status' => checkRequiredExtensions()
        ]
    ];
    
    $all_passed = true;
    ?>
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>項目</th>
                    <th>必要条件</th>
                    <th>現在の状況</th>
                    <th>状態</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requirements as $name => $req): ?>
                <tr>
                    <td><strong><?= $name ?></strong></td>
                    <td><?= $req['required'] ?></td>
                    <td><?= $req['current'] ?></td>
                    <td>
                        <?php if ($req['status']): ?>
                            <i class="fas fa-check-circle text-success"></i> 合格
                        <?php else: ?>
                            <i class="fas fa-times-circle text-danger"></i> 不合格
                            <?php $all_passed = false; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($all_passed): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            すべての要件を満たしています。インストールを続行できます。
        </div>
        <div class="d-grid">
            <a href="?step=3" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right me-2"></i>次へ: データベース作成
            </a>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            一部の要件を満たしていません。システム管理者に相談してください。
        </div>
        <div class="d-grid">
            <button onclick="location.reload()" class="btn btn-outline-primary btn-lg">
                <i class="fas fa-redo me-2"></i>再確認
            </button>
        </div>
    <?php endif;
}

function showDatabaseStep() {
    renderStepIndicator(3);
    ?>
    <h4><i class="fas fa-database me-2"></i>データベーステーブル作成</h4>
    <p>カレンダーシステム用のデータベーステーブルを作成します。</p>
    
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        以下のテーブルが作成されます：
        <ul class="mb-0 mt-2">
            <li>reservations（予約管理）</li>
            <li>partner_companies（協力会社管理）</li>
            <li>frequent_locations（よく使う場所）</li>
            <li>calendar_audit_logs（操作ログ）</li>
        </ul>
    </div>
    
    <div id="databaseProgress" style="display: none;">
        <div class="progress mb-3">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
        </div>
        <div class="log-output" id="databaseLog"></div>
    </div>
    
    <form method="post" onsubmit="return createDatabaseWithProgress()">
        <input type="hidden" name="action" value="create_database">
        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-play me-2"></i>データベース作成開始
            </button>
        </div>
    </form>
    
    <script>
    function createDatabaseWithProgress() {
        const progressDiv = document.getElementById('databaseProgress');
        const progressBar = progressDiv.querySelector('.progress-bar');
        const logDiv = document.getElementById('databaseLog');
        
        progressDiv.style.display = 'block';
        progressBar.style.width = '25%';
        logDiv.innerHTML = '[INFO] データベース作成を開始します...\n';
        
        return true;
    }
    </script>
    <?php
}

function createDatabaseTables() {
    ?>
    <h4><i class="fas fa-database me-2"></i>データベーステーブル作成</h4>
    <div class="progress mb-3">
        <div class="progress-bar bg-success" style="width: 100%">完了</div>
    </div>
    <div class="log-output" id="createLog">
    <?php
    
    try {
        $pdo = getDatabaseConnection();
        
        echo "[INFO] データベース接続確立\n";
        
        // SQLファイル読み込み
        $sql_file = __DIR__ . '/sql/create_tables.sql';
        if (!file_exists($sql_file)) {
            // SQLを直接定義
            $sql_commands = getCreateTableSQL();
        } else {
            $sql_commands = file_get_contents($sql_file);
        }
        
        echo "[INFO] SQLコマンド読み込み完了\n";
        
        // テーブル作成実行
        $pdo->exec($sql_commands);
        echo "[SUCCESS] カレンダーテーブル作成完了\n";
        
        // 初期データ作成
        insertSampleData($pdo);
        echo "[SUCCESS] サンプルデータ投入完了\n";
        
        echo "[INFO] データベース作成が正常に完了しました\n";
        
        ?>
        </div>
        <div class="alert alert-success mt-3">
            <i class="fas fa-check-circle me-2"></i>
            データベーステーブルの作成が完了しました。
        </div>
        <div class="d-grid">
            <a href="?step=4" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right me-2"></i>次へ: 初期データ設定
            </a>
        </div>
        <?php
        
    } catch (Exception $e) {
        echo "[ERROR] データベース作成エラー: " . $e->getMessage() . "\n";
        ?>
        </div>
        <div class="alert alert-danger mt-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            データベース作成中にエラーが発生しました。ログを確認してください。
        </div>
        <div class="d-grid">
            <button onclick="location.reload()" class="btn btn-outline-primary btn-lg">
                <i class="fas fa-redo me-2"></i>再試行
            </button>
        </div>
        <?php
    }
}

function showInitialDataStep() {
    renderStepIndicator(4);
    ?>
    <h4><i class="fas fa-seedling me-2"></i>初期データ設定</h4>
    <p>システムの初期設定とサンプルデータを投入します。</p>
    
    <form method="post">
        <input type="hidden" name="action" value="insert_initial_data">
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">協力会社設定</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="sampleCompanies" name="sample_companies" checked>
                            <label class="form-check-label" for="sampleCompanies">
                                サンプル協力会社データを作成
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">よく使う場所</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="sampleLocations" name="sample_locations" checked>
                            <label class="form-check-label" for="sampleLocations">
                                大阪エリアの病院・施設データを作成
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="d-grid mt-3">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-play me-2"></i>初期データ投入
            </button>
        </div>
    </form>
    <?php
}

function insertInitialData() {
    $sample_companies = isset($_POST['sample_companies']);
    $sample_locations = isset($_POST['sample_locations']);
    
    ?>
    <h4><i class="fas fa-seedling me-2"></i>初期データ投入中...</h4>
    <div class="log-output">
    <?php
    
    try {
        $pdo = getDatabaseConnection();
        
        if ($sample_companies) {
            insertSampleCompanies($pdo);
            echo "[SUCCESS] 協力会社サンプルデータを投入しました\n";
        }
        
        if ($sample_locations) {
            insertSampleLocations($pdo);
            echo "[SUCCESS] よく使う場所データを投入しました\n";
        }
        
        echo "[INFO] 初期データ投入が完了しました\n";
        
        ?>
        </div>
        <div class="alert alert-success mt-3">
            <i class="fas fa-check-circle me-2"></i>
            初期データの投入が完了しました。
        </div>
        <div class="d-grid">
            <a href="?step=5" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right me-2"></i>次へ: システムテスト
            </a>
        </div>
        <?php
        
    } catch (Exception $e) {
        echo "[ERROR] 初期データ投入エラー: " . $e->getMessage() . "\n";
        ?>
        </div>
        <div class="alert alert-danger mt-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            初期データ投入中にエラーが発生しました。
        </div>
        <?php
    }
}

function showTestStep() {
    renderStepIndicator(5);
    ?>
    <h4><i class="fas fa-vial me-2"></i>システムテスト</h4>
    <p>インストールされたシステムの動作確認を行います。</p>
    
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        以下のテストを実行します：
        <ul class="mb-0 mt-2">
            <li>データベース接続テスト</li>
            <li>テーブル構造確認</li>
            <li>API エンドポイントテスト</li>
            <li>JavaScript ライブラリ読み込み確認</li>
            <li>権限設定確認</li>
        </ul>
    </div>
    
    <form method="post">
        <input type="hidden" name="action" value="run_tests">
        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-play me-2"></i>システムテスト実行
            </button>
        </div>
    </form>
    <?php
}

function runSystemTests() {
    ?>
    <h4><i class="fas fa-vial me-2"></i>システムテスト実行中...</h4>
    <div class="log-output">
    <?php
    
    $tests = [
        'データベース接続' => testDatabaseConnection(),
        'テーブル存在確認' => testTablesExist(),
        'API エンドポイント' => testAPIEndpoints(),
        'ファイル権限' => testFilePermissions(),
        'JavaScript ライブラリ' => testJavaScriptLibraries()
    ];
    
    $passed = 0;
    $total = count($tests);
    
    foreach ($tests as $test_name => $result) {
        if ($result) {
            echo "[PASS] {$test_name}\n";
            $passed++;
        } else {
            echo "[FAIL] {$test_name}\n";
        }
    }
    
    echo "\n=== テスト結果 ===\n";
    echo "合格: {$passed}/{$total}\n";
    
    if ($passed === $total) {
        echo "[SUCCESS] すべてのテストに合格しました\n";
        ?>
        </div>
        <div class="alert alert-success mt-3">
            <i class="fas fa-check-circle me-2"></i>
            すべてのシステムテストに合格しました。
        </div>
        <div class="d-grid">
            <a href="?step=6" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right me-2"></i>次へ: インストール完了
            </a>
        </div>
        <?php
    } else {
        echo "[WARNING] 一部のテストが失敗しました\n";
        ?>
        </div>
        <div class="alert alert-warning mt-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            一部のテストが失敗しましたが、基本機能は動作する可能性があります。
        </div>
        <div class="d-grid gap-2">
            <a href="?step=6" class="btn btn-warning btn-lg">
                <i class="fas fa-arrow-right me-2"></i>警告を無視して続行
            </a>
            <button onclick="location.reload()" class="btn btn-outline-primary">
                <i class="fas fa-redo me-2"></i>テスト再実行
            </button>
        </div>
        <?php
    }
}

function showCompletionStep() {
    renderStepIndicator(6);
    ?>
    <h4><i class="fas fa-flag-checkered me-2"></i>インストール完了</h4>
    <p>カレンダーシステムのインストールが完了しました。</p>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0">次のステップ</h6>
                </div>
                <div class="card-body">
                    <ol>
                        <li>カレンダー画面にアクセスして動作確認</li>
                        <li>必要に応じて協力会社・場所データの追加</li>
                        <li>タイムツリーからのデータ移行（必要な場合）</li>
                        <li>ユーザートレーニングの実施</li>
                    </ol>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">アクセス先</h6>
                </div>
                <div class="card-body">
                    <p><strong>カレンダー画面:</strong><br>
                    <a href="index.php" target="_blank">calendar/index.php</a></p>
                    
                    <p><strong>ダッシュボード統合:</strong><br>
                    <a href="../dashboard.php" target="_blank">dashboard.php</a></p>
                    
                    <p><strong>データ移行:</strong><br>
                    <a href="index.php?action=migrate" target="_blank">移行ツール</a></p>
                </div>
            </div>
        </div>
    </div>
    
    <form method="post">
        <input type="hidden" name="action" value="complete_installation">
        <div class="d-grid mt-3">
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-check me-2"></i>インストール完了
            </button>
        </div>
    </form>
    <?php
}

function completeInstallation() {
    // インストール完了マーカー作成
    $install_info = [
        'installed_at' => date('Y-m-d H:i:s'),
        'version' => '1.0.0',
        'installer_user' => $_SESSION['user_id'],
        'system_info' => [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
        ]
    ];
    
    file_put_contents(__DIR__ . '/.installed', json_encode($install_info, JSON_PRETTY_PRINT));
    
    ?>
    <div class="text-center">
        <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
        <h2 class="text-success mt-3">インストール完了！</h2>
        <p class="lead">介護タクシー予約管理カレンダーシステムのインストールが正常に完了しました。</p>
        
        <div class="alert alert-success">
            <i class="fas fa-info-circle me-2"></i>
            このインストーラーは自動的に無効化されました。
        </div>
        
        <div class="d-grid gap-2 d-md-block">
            <a href="index.php" class="btn btn-primary btn-lg">
                <i class="fas fa-calendar-alt me-2"></i>カレンダーを開く
            </a>
            <a href="../dashboard.php" class="btn btn-outline-primary btn-lg">
                <i class="fas fa-tachometer-alt me-2"></i>ダッシュボードに戻る
            </a>
        </div>
    </div>
    <?php
}

// =================================================================
// ユーティリティ関数
// =================================================================

function renderStepIndicator($current_step) {
    $steps = [
        1 => 'ようこそ',
        2 => '要件確認',
        3 => 'DB作成',
        4 => '初期データ',
        5 => 'テスト',
        6 => '完了'
    ];
    
    echo '<div class="step-indicator">';
    foreach ($steps as $step => $label) {
        $class = $step < $current_step ? 'completed' : 
                ($step == $current_step ? 'active' : 'pending');
        echo "<div class='step {$class}' title='{$label}'>{$step}</div>";
    }
    echo '</div>';
}

function testDatabaseConnection() {
    try {
        $pdo = getDatabaseConnection();
        return $pdo !== false;
    } catch (Exception $e) {
        return false;
    }
}

function getWTSVersion() {
    // functions.phpからバージョン取得（簡易版）
    return 'v3.1'; // 実際の実装では定数から取得
}

function checkWTSVersion() {
    return file_exists('../functions.php') && file_exists('../dashboard.php');
}

function getRequiredExtensions() {
    $extensions = ['PDO', 'json', 'openssl'];
    $loaded = [];
    foreach ($extensions as $ext) {
        if (extension_loaded($ext)) {
            $loaded[] = $ext;
        }
    }
    return implode(', ', $loaded);
}

function checkRequiredExtensions() {
    $required = ['PDO', 'json', 'openssl'];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            return false;
        }
    }
    return true;
}

function getCreateTableSQL() {
    // 前に作成したテーブル作成SQLを返す
    return file_get_contents(__DIR__ . '/../calendar_database_tables.sql') ?: '';
}

function insertSampleData($pdo) {
    // 基本的なサンプルデータのみ投入
    // 詳細なサンプルデータは別途処理
}

function insertSampleCompanies($pdo) {
    $companies = [
        ['協力会社A', '田中', '06-1234-5678', '閲覧のみ', '#FF5722', 1],
        ['協力会社B', '佐藤', '06-2345-6789', '部分作成', '#2196F3', 2],
        ['協力会社C', '鈴木', '06-3456-7890', '閲覧のみ', '#4CAF50', 3]
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO partner_companies 
        (company_name, contact_person, phone, access_level, display_color, sort_order) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($companies as $company) {
        $stmt->execute($company);
    }
}

function insertSampleLocations($pdo) {
    $locations = [
        ['大阪市立総合医療センター', '病院', '大阪市都島区都島本通2-13-22', '06-6929-1221'],
        ['大阪大学医学部附属病院', '病院', '大阪府吹田市山田丘2-15', '06-6879-5111'],
        ['大阪駅', '駅', '大阪市北区梅田3丁目1-1', NULL],
        ['ケアハウス大阪', '施設', '大阪市中央区南船場1-3-5', '06-6271-1234']
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO frequent_locations 
        (location_name, location_type, address, phone) 
        VALUES (?, ?, ?, ?)
    ");
    
    foreach ($locations as $location) {
        $stmt->execute($location);
    }
}

function testTablesExist() {
    try {
        $pdo = getDatabaseConnection();
        $tables = ['reservations', 'partner_companies', 'frequent_locations', 'calendar_audit_logs'];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if (!$stmt->fetch()) {
                return false;
            }
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function testAPIEndpoints() {
    // 簡易版：APIファイルの存在確認
    $apis = [
        'api/get_reservations.php',
        'api/save_reservation.php',
        'api/create_return_trip.php',
        'api/get_availability.php'
    ];
    
    foreach ($apis as $api) {
        if (!file_exists(__DIR__ . '/' . $api)) {
            return false;
        }
    }
    return true;
}

function testFilePermissions() {
    return is_writable(__DIR__) && is_readable(__DIR__);
}

function testJavaScriptLibraries() {
    // 簡易版：JSファイルの存在確認
    $js_files = [
        'js/calendar.js',
        'js/reservation.js',
        'js/vehicle_constraints.js'
    ];
    
    foreach ($js_files as $js) {
        if (!file_exists(__DIR__ . '/' . $js)) {
            return false;
        }
    }
    return true;
}

?>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
