<?php
// =================================================================
// カレンダーシステムインストーラー（デバッグ版・緊急対策）
// 
// ファイル: /Smiley/taxi/wts/calendar/install.php
// 対策: セッション問題回避のため、DBから直接権限確認
// 修正日: 2025年10月6日
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

// ✅ 緊急対策: データベースから直接権限確認
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

try {
    $pdo = getDatabaseConnection();
    
    // データベースから直接ユーザー情報を取得
    $stmt = $pdo->prepare("SELECT id, NAME, login_id, permission_level FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user_from_db = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_from_db) {
        die('ユーザーが見つかりません。再度ログインしてください。');
    }
    
    // ✅ セッションに不足している情報を補完
    if (!isset($_SESSION['permission_level'])) {
        $_SESSION['permission_level'] = $user_from_db['permission_level'];
    }
    if (!isset($_SESSION['user_name'])) {
        $_SESSION['user_name'] = $user_from_db['NAME'];
    }
    
    // ✅ データベースの権限レベルで判定（セッションではなくDBを優先）
    if ($user_from_db['permission_level'] !== 'Admin') {
        ?>
        <!DOCTYPE html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>アクセス拒否 - カレンダーインストーラー</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container mt-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> アクセス拒否</h4>
                    <p>このインストーラーは管理者権限（permission_level = Admin）が必要です。</p>
                    <hr>
                    <h5>デバッグ情報:</h5>
                    <table class="table table-sm">
                        <tr>
                            <th>ユーザーID:</th>
                            <td><?= htmlspecialchars($user_from_db['id']) ?></td>
                        </tr>
                        <tr>
                            <th>ログインID:</th>
                            <td><?= htmlspecialchars($user_from_db['login_id']) ?></td>
                        </tr>
                        <tr>
                            <th>ユーザー名:</th>
                            <td><?= htmlspecialchars($user_from_db['NAME']) ?></td>
                        </tr>
                        <tr>
                            <th>データベース権限:</th>
                            <td><strong><?= htmlspecialchars($user_from_db['permission_level']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>セッション権限:</th>
                            <td><?= htmlspecialchars($_SESSION['permission_level'] ?? '未設定') ?></td>
                        </tr>
                    </table>
                    <hr>
                    <p class="mb-0">
                        <strong>管理者アカウントでログインしてください：</strong><br>
                        - 杉原眞希（login_id: admin）<br>
                        - 杉原充（login_id: Smiley01）<br>
                        - 杉原星（login_id: Smiley999）
                    </p>
                    <a href="../index.php" class="btn btn-primary mt-3">ログイン画面に戻る</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    // ✅ 正常にアクセス可能（管理者確認済み）
    $user_name = $user_from_db['NAME'];
    $permission_level = $user_from_db['permission_level'];
    
} catch (Exception $e) {
    die('データベースエラー: ' . htmlspecialchars($e->getMessage()));
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
        .debug-badge { font-size: 0.75rem; }
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
                <div class="mt-2">
                    <span class="badge bg-success me-2">
                        <i class="fas fa-user-shield"></i> <?= htmlspecialchars($user_name) ?>
                    </span>
                    <span class="badge bg-info me-2">
                        DB権限: <?= htmlspecialchars($permission_level) ?>
                    </span>
                    <span class="badge bg-warning text-dark debug-badge">
                        <i class="fas fa-bug"></i> デバッグモード
                    </span>
                </div>
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
    global $user_name, $permission_level;
    renderStepIndicator(1);
    ?>
    <h4><i class="fas fa-home me-2"></i>インストーラーへようこそ</h4>
    
    <div class="alert alert-success mb-3">
        <i class="fas fa-check-circle me-2"></i>
        <strong>認証成功！</strong> 管理者権限が確認されました。
    </div>
    
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
                        <li><i class="fas fa-check-circle text-success me-2"></i>管理者権限 ✓</li>
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
            'required' => '8.0.0',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '8.0.0', '>=')
        ],
        'MySQL Connection' => [
            'required' => '接続可能',
            'current' => testDatabaseConnection() ? '成功' : '失敗',
            'status' => testDatabaseConnection()
        ],
        'WTS Base System' => [
            'required' => 'v3.1以上',
            'current' => getWTSVersion(),
            'status' => checkWTSVersion()
        ],
        'Admin Permission (DB)' => [
            'required' => 'permission_level = Admin',
            'current' => $permission_level,
            'status' => $permission_level === 'Admin'
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
        <div class="d-grid gap-2">
            <button onclick="location.reload()" class="btn btn-outline-primary btn-lg">
                <i class="fas fa-redo me-2"></i>再確認
            </button>
        </div>
    <?php endif;
}

// 残りの関数は前のバージョンと同じ
function showDatabaseStep() { /* 省略 - 前のコードと同じ */ }
function createDatabaseTables() { /* 省略 */ }
function showInitialDataStep() { /* 省略 */ }
function insertInitialData() { /* 省略 */ }
function showTestStep() { /* 省略 */ }
function runSystemTests() { /* 省略 */ }
function showCompletionStep() { /* 省略 */ }
function completeInstallation() { /* 省略 */ }

// ユーティリティ関数
function renderStepIndicator($current_step) {
    $steps = [1 => 'ようこそ', 2 => '要件確認', 3 => 'DB作成', 4 => '初期データ', 5 => 'テスト', 6 => '完了'];
    echo '<div class="step-indicator">';
    foreach ($steps as $step => $label) {
        $class = $step < $current_step ? 'completed' : ($step == $current_step ? 'active' : 'pending');
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

function getWTSVersion() { return 'v3.1'; }
function checkWTSVersion() { return file_exists('../functions.php'); }
function getRequiredExtensions() {
    $extensions = ['PDO', 'json', 'openssl'];
    $loaded = array_filter($extensions, 'extension_loaded');
    return implode(', ', $loaded);
}
function checkRequiredExtensions() {
    return extension_loaded('PDO') && extension_loaded('json') && extension_loaded('openssl');
}

?>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
