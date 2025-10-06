<?php
// =================================================================
// カレンダーシステムインストーラー（完全版）
// 
// ファイル: /Smiley/taxi/wts/calendar/install.php
// バージョン: 1.0.0
// 最終更新: 2025年10月6日
// =================================================================

session_start();

// インストール完了後はアクセス禁止
if (file_exists(__DIR__ . '/.installed')) {
    die('
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title>既にインストール済み</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="alert alert-info">
                <h4><i class="fas fa-info-circle"></i> インストール済み</h4>
                <p>カレンダーシステムは既にインストール済みです。</p>
                <a href="index.php" class="btn btn-primary">カレンダーを開く</a>
            </div>
        </div>
    </body>
    </html>
    ');
}

// 基盤システム確認
if (!file_exists('../config/database.php')) {
    die('データベース設定ファイルが見つかりません。');
}

require_once '../config/database.php';
require_once '../functions.php';

// データベース接続確認
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die('データベース接続エラー: ' . htmlspecialchars($e->getMessage()));
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// 管理者権限確認
try {
    $stmt = $pdo->prepare("SELECT id, NAME, login_id, permission_level FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user_from_db = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_from_db || $user_from_db['permission_level'] !== 'Admin') {
        die('管理者権限が必要です。');
    }
    
    // セッション情報を補完
    if (!isset($_SESSION['permission_level'])) {
        $_SESSION['permission_level'] = $user_from_db['permission_level'];
    }
    if (!isset($_SESSION['user_name'])) {
        $_SESSION['user_name'] = $user_from_db['NAME'];
    }
    
    $user_name = $user_from_db['NAME'];
    $permission_level = $user_from_db['permission_level'];
    
} catch (Exception $e) {
    die('データベースエラー: ' . htmlspecialchars($e->getMessage()));
}

$step = $_GET['step'] ?? 1;
$action = $_POST['action'] ?? '';

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>カレンダーシステム インストーラー</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .installer-container { max-width: 900px; margin: 2rem auto; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 2rem; gap: 10px; }
        .step { width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; position: relative; }
        .step.active { background-color: #007bff; color: white; box-shadow: 0 0 0 4px rgba(0,123,255,0.2); }
        .step.completed { background-color: #28a745; color: white; }
        .step.pending { background-color: #e9ecef; color: #6c757d; }
        .step:not(:last-child)::after { content: ''; position: absolute; top: 50%; left: 100%; width: 10px; height: 2px; background-color: #dee2e6; z-index: -1; }
        .log-output { background-color: #212529; color: #00ff00; font-family: 'Courier New', monospace; padding: 20px; border-radius: 8px; max-height: 450px; overflow-y: auto; font-size: 14px; line-height: 1.6; }
        .success-icon { font-size: 5rem; color: #28a745; }
        .feature-card { transition: transform 0.2s; }
        .feature-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body>
    <div class="installer-container">
        <div class="card shadow-lg">
            <div class="card-header bg-primary text-white py-4">
                <h2 class="mb-0">
                    <i class="fas fa-calendar-alt me-2"></i>
                    介護タクシー予約管理カレンダーシステム
                </h2>
                <p class="mb-0 mt-2">福祉輸送管理システム v3.1 拡張モジュール インストーラー</p>
                <div class="mt-2">
                    <span class="badge bg-success fs-6">
                        <i class="fas fa-user-shield"></i> <?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($permission_level) ?>)
                    </span>
                </div>
            </div>
            <div class="card-body p-4">

<?php

// ステップ処理の振り分け
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
        showDatabaseStepCompleted();
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
// ステップ1: ようこそ画面
// =================================================================

function showWelcomeStep() {
    global $user_name;
    renderStepIndicator(1);
    ?>
    <h3 class="mb-4"><i class="fas fa-home me-2"></i>インストーラーへようこそ</h3>
    
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        <strong>認証成功！</strong> <?= htmlspecialchars($user_name) ?>さん、管理者権限が確認されました。
    </div>
    
    <p class="lead">介護タクシー予約管理カレンダーシステムのインストールを開始します。</p>
    
    <div class="row g-4 my-4">
        <div class="col-md-4">
            <div class="card feature-card border-primary h-100">
                <div class="card-body text-center">
                    <i class="fas fa-calendar-check text-primary" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">予約管理</h5>
                    <p class="text-muted small">月・週・日表示で予約を一元管理</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card feature-card border-success h-100">
                <div class="card-body text-center">
                    <i class="fas fa-rotate text-success" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">復路作成</h5>
                    <p class="text-muted small">ワンクリックで70%時短を実現</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card feature-card border-warning h-100">
                <div class="card-body text-center">
                    <i class="fas fa-car text-warning" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">車両制約</h5>
                    <p class="text-muted small">配車ミスを自動的に防止</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>重要:</strong> インストール前にデータベースのバックアップを作成してください。
    </div>
    
    <div class="d-grid">
        <a href="?step=2" class="btn btn-primary btn-lg">
            <i class="fas fa-arrow-right me-2"></i>インストール開始
        </a>
    </div>
    <?php
}

// =================================================================
// ステップ2: システム要件確認
// =================================================================

function showRequirementsStep() {
    renderStepIndicator(2);
    ?>
    <h3 class="mb-4"><i class="fas fa-list-check me-2"></i>システム要件確認</h3>
    <div id="requirementResults">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">確認中...</span>
            </div>
            <p class="mt-3 text-muted">システム要件を確認しています...</p>
        </div>
    </div>
    <script>
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
    global $permission_level;
    
    $requirements = [
        'PHP Version' => [
            'required' => '8.0.0以上',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'icon' => 'fa-code'
        ],
        'MySQL Connection' => [
            'required' => '接続可能',
            'current' => testDatabaseConnection() ? '成功' : '失敗',
            'status' => testDatabaseConnection(),
            'icon' => 'fa-database'
        ],
        'Admin Permission' => [
            'required' => 'Admin',
            'current' => $permission_level,
            'status' => $permission_level === 'Admin',
            'icon' => 'fa-user-shield'
        ],
        'Directory Permissions' => [
            'required' => '書き込み可能',
            'current' => is_writable(__DIR__) ? '可能' : '不可',
            'status' => is_writable(__DIR__),
            'icon' => 'fa-folder-open'
        ]
    ];
    
    $all_passed = true;
    ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th width="30%">項目</th>
                    <th width="25%">必要条件</th>
                    <th width="25%">現在の状況</th>
                    <th width="20%" class="text-center">状態</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requirements as $name => $req): ?>
                <tr>
                    <td>
                        <i class="fas <?= $req['icon'] ?> me-2 text-primary"></i>
                        <strong><?= $name ?></strong>
                    </td>
                    <td><?= $req['required'] ?></td>
                    <td><code><?= $req['current'] ?></code></td>
                    <td class="text-center">
                        <?php if ($req['status']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-circle"></i> 合格
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger">
                                <i class="fas fa-times-circle"></i> 不合格
                            </span>
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
            <strong>すべての要件を満たしています。</strong> インストールを続行できます。
        </div>
        <div class="d-grid">
            <a href="?step=3" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right me-2"></i>次へ: データベース確認
            </a>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            一部の要件を満たしていません。システム管理者に相談してください。
        </div>
    <?php endif;
}

// =================================================================
// ステップ3: データベース確認（手動作成済み）
// =================================================================

function showDatabaseStepCompleted() {
    renderStepIndicator(3);
    ?>
    <h3 class="mb-4"><i class="fas fa-database me-2"></i>データベース確認</h3>
    
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <strong>テーブル作成済み:</strong> データベーステーブルは手動で作成されています。
    </div>
    
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">作成済みテーブル</h5>
        </div>
        <div class="card-body">
            <ul class="list-unstyled mb-0">
                <li><i class="fas fa-table text-success me-2"></i>reservations（予約管理メイン）</li>
                <li><i class="fas fa-table text-success me-2"></i>partner_companies（協力会社管理）</li>
                <li><i class="fas fa-table text-success me-2"></i>frequent_locations（よく使う場所）</li>
                <li><i class="fas fa-table text-success me-2"></i>calendar_audit_logs（操作ログ）</li>
            </ul>
        </div>
    </div>
    
    <div class="d-grid mt-4">
        <a href="?step=4" class="btn btn-primary btn-lg">
            <i class="fas fa-arrow-right me-2"></i>次へ: 初期データ設定
        </a>
    </div>
    <?php
}

// =================================================================
// ステップ4: 初期データ投入
// =================================================================

function showInitialDataStep() {
    renderStepIndicator(4);
    ?>
    <h3 class="mb-4"><i class="fas fa-seedling me-2"></i>初期データ設定</h3>
    <p>システムの初期設定とサンプルデータを投入します。</p>
    
    <form method="post">
        <input type="hidden" name="action" value="insert_initial_data">
        
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-building me-2"></i>協力会社設定</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="sampleCompanies" name="sample_companies" checked>
                            <label class="form-check-label" for="sampleCompanies">
                                サンプル協力会社データを作成
                            </label>
                        </div>
                        <small class="text-muted">協力会社A、B、Cの3社</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>よく使う場所</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="sampleLocations" name="sample_locations" checked>
                            <label class="form-check-label" for="sampleLocations">
                                大阪エリアの病院・施設データを作成
                            </label>
                        </div>
                        <small class="text-muted">主要病院・施設4か所</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="d-grid mt-4">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-play me-2"></i>初期データ投入開始
            </button>
        </div>
    </form>
    <?php
}

function insertInitialData() {
    global $pdo;
    renderStepIndicator(4);
    
    $sample_companies = isset($_POST['sample_companies']);
    $sample_locations = isset($_POST['sample_locations']);
    
    ?>
    <h3 class="mb-4"><i class="fas fa-seedling me-2"></i>初期データ投入中...</h3>
    <div class="log-output">
    <?php
    
    try {
        echo "[INFO] 初期データ投入を開始します...\n\n";
        
        if ($sample_companies) {
            echo "[PROCESS] 協力会社サンプルデータを投入中...\n";
            
            $companies = [
                ['協力会社A', '田中太郎', '06-1234-5678', 'info-a@example.com', '閲覧のみ', '#FF5722', 1],
                ['協力会社B', '佐藤花子', '06-2345-6789', 'info-b@example.com', '部分作成', '#2196F3', 2],
                ['協力会社C', '鈴木一郎', '06-3456-7890', 'info-c@example.com', '閲覧のみ', '#4CAF50', 3]
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO partner_companies 
                (company_name, contact_person, phone, email, access_level, display_color, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($companies as $company) {
                $stmt->execute($company);
                echo "  ✓ {$company[0]} を登録\n";
            }
            echo "[SUCCESS] 協力会社サンプルデータを投入しました（3件）\n\n";
        }
        
        if ($sample_locations) {
            echo "[PROCESS] よく使う場所データを投入中...\n";
            
            $locations = [
                ['大阪市立総合医療センター', '病院', '大阪市都島区都島本通2-13-22', '06-6929-1221'],
                ['大阪大学医学部附属病院', '病院', '大阪府吹田市山田丘2-15', '06-6879-5111'],
                ['大阪駅', '駅', '大阪市北区梅田3丁目1-1', null],
                ['ケアハウス大阪', '施設', '大阪市中央区南船場1-3-5', '06-6271-1234']
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO frequent_locations 
                (location_name, location_type, address, phone) 
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($locations as $location) {
                $stmt->execute($location);
                echo "  ✓ {$location[0]} を登録\n";
            }
            echo "[SUCCESS] よく使う場所データを投入しました（4件）\n\n";
        }
        
        echo "[INFO] 初期データ投入が完了しました\n";
        echo "[COMPLETE] すべての処理が正常に完了しました！\n";
        
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
            初期データ投入中にエラーが発生しました: <?= htmlspecialchars($e->getMessage()) ?>
        </div>
        <?php
    }
}

// =================================================================
// ステップ5: システムテスト
// =================================================================

function showTestStep() {
    renderStepIndicator(5);
    ?>
    <h3 class="mb-4"><i class="fas fa-vial me-2"></i>システムテスト</h3>
    <p>インストールされたシステムの動作確認を行います。</p>
    
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        以下のテストを実行します：
        <ul class="mb-0 mt-2">
            <li>データベース接続テスト</li>
            <li>テーブル構造確認</li>
            <li>初期データ確認</li>
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
    global $pdo, $permission_level;
    renderStepIndicator(5);
    ?>
    <h3 class="mb-4"><i class="fas fa-vial me-2"></i>システムテスト実行中...</h3>
    <div class="log-output">
    <?php
    
    $tests = [
        'データベース接続' => testDatabaseConnection(),
        'テーブル存在確認' => testTablesExist($pdo),
        '協力会社データ' => testPartnerCompaniesData($pdo),
        'よく使う場所データ' => testFrequentLocationsData($pdo),
        '管理者権限確認' => ($permission_level === 'Admin'),
        'ディレクトリ書き込み権限' => is_writable(__DIR__)
    ];
    
    $passed = 0;
    $total = count($tests);
    
    echo "[INFO] システムテストを開始します...\n\n";
    
    foreach ($tests as $test_name => $result) {
        if ($result) {
            echo "[PASS] {$test_name} ✓\n";
            $passed++;
        } else {
            echo "[FAIL] {$test_name} ✗\n";
        }
    }
    
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "[RESULT] テスト結果: {$passed}/{$total} 合格\n";
    echo str_repeat('=', 50) . "\n";
    
    if ($passed === $total) {
        echo "\n[SUCCESS] すべてのテストに合格しました！\n";
        ?>
        </div>
        <div class="alert alert-success mt-3">
            <i class="fas fa-check-circle me-2"></i>
            <strong>すべてのシステムテストに合格しました。</strong>
        </div>
        <div class="d-grid">
            <a href="?step=6" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right me-2"></i>次へ: インストール完了
            </a>
        </div>
        <?php
    } else {
        echo "\n[WARNING] 一部のテストが失敗しました\n";
        echo "[INFO] 基本機能は動作する可能性があります\n";
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

// =================================================================
// ステップ6: インストール完了
// =================================================================

function showCompletionStep() {
    renderStepIndicator(6);
    ?>
    <h3 class="mb-4"><i class="fas fa-flag-checkered me-2"></i>インストール完了準備</h3>
    
    <div class="alert alert-success">
        <h5 class="alert-heading"><i class="fas fa-check-circle me-2"></i>インストール準備完了</h5>
        <p class="mb-0">すべてのセットアップが正常に完了しました。</p>
    </div>
    
    <div class="card my-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">次のステップ</h5>
        </div>
        <div class="card-body">
            <ol class="mb-0">
                <li>カレンダー画面にアクセスして動作確認</li>
                <li>必要に応じて協力会社・場所データの追加</li>
                <li>タイムツリーからのデータ移行（必要な場合）</li>
                <li>ユーザートレーニングの実施</li>
            </ol>
        </div>
    </div>
    
    <form method="post">
        <input type="hidden" name="action" value="complete_installation">
        <div class="d-grid">
            <button type="submit" class="btn btn-success btn-lg">
                <i class="fas fa-check me-2"></i>インストール完了
            </button>
        </div>
    </form>
    <?php
}

function completeInstallation() {
    global $user_name;
    
    // インストール完了マーカー作成
    $install_info = [
        'installed_at' => date('Y-m-d H:i:s'),
        'version' => '1.0.0',
        'installer_user' => $_SESSION['user_id'],
        'installer_name' => $user_name,
        'permission_level' => $_SESSION['permission_level'],
        'system_info' => [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
        ]
    ];
    
    file_put_contents(__DIR__ . '/.installed', json_encode($install_info, JSON_PRETTY_PRINT));
    
    ?>
    <div class="text-center py-5">
        <i class="fas fa-check-circle success-icon"></i>
        <h2 class="text-success mt-4 mb-3">インストール完了！</h2>
        <p class="lead">介護タクシー予約管理カレンダーシステムのインストールが正常に完了しました。</p>
        
        <div class="alert alert-success d-inline-block mt-4">
            <i class="fas fa-info-circle me-2"></i>
            このインストーラーは自動的に無効化されました。
        </div>
        
        <div class="d-grid gap-2 d-md-block mt-4">
            <a href="index.php" class="btn btn-primary btn-lg me-md-2">
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
        1 => ['label' => 'ようこそ', 'icon' => 'fa-home'],
        2 => ['label' => '要件確認', 'icon' => 'fa-list-check'],
        3 => ['label' => 'DB確認', 'icon' => 'fa-database'],
        4 => ['label' => '初期データ', 'icon' => 'fa-seedling'],
        5 => ['label' => 'テスト', 'icon' => 'fa-vial'],
        6 => ['label' => '完了', 'icon' => 'fa-flag-checkered']
    ];
    
    echo '<div class="step-indicator mb-4">';
    foreach ($steps as $step => $info) {
        $class = $step < $current_step ? 'completed' : 
                ($step == $current_step ? 'active' : 'pending');
        echo "<div class='step {$class}' title='{$info['label']}'>";
        echo "<i class='fas {$info['icon']}'></i>";
        echo "</div>";
    }
    echo '</div>';
}

function testDatabaseConnection() {
    try {
        $pdo = getDBConnection();
        return $pdo !== false;
    } catch (Exception $e) {
        return false;
    }
}

function testTablesExist($pdo) {
    try {
        $tables = ['reservations', 'partner_companies', 'frequent_locations'];
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

function testPartnerCompaniesData($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM partner_companies");
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function testFrequentLocationsData($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM frequent_locations");
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

?>

            </div>
        </div>
        
        <div class="text-center text-muted mt-3">
            <small>
                <i class="fas fa-copyright"></i> 2025 福祉輸送管理システム開発チーム | 
                介護タクシー予約管理カレンダーシステム v1.0.0
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
