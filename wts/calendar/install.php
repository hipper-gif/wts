<?php
// =================================================================
// カレンダーシステムインストーラー（関数名修正版）
// 
// ファイル: /Smiley/taxi/wts/calendar/install.php
// 修正: getDBConnection() に変更
// 修正日: 2025年10月6日
// =================================================================

session_start();

// インストール完了後はアクセス禁止
if (file_exists(__DIR__ . '/.installed')) {
    die('カレンダーシステムは既にインストール済みです。');
}

// 基盤システム確認
if (!file_exists('../config/database.php')) {
    die('データベース設定ファイルが見つかりません。');
}

require_once '../config/database.php';
require_once '../functions.php';

// ✅ 修正: 正しい関数名で接続確認
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

// ✅ データベースから直接ユーザー情報を取得
try {
    $stmt = $pdo->prepare("SELECT id, NAME, login_id, permission_level FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user_from_db = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_from_db) {
        die('ユーザーが見つかりません。再度ログインしてください。');
    }
    
    // セッション情報を補完
    if (!isset($_SESSION['permission_level'])) {
        $_SESSION['permission_level'] = $user_from_db['permission_level'];
    }
    if (!isset($_SESSION['user_name'])) {
        $_SESSION['user_name'] = $user_from_db['NAME'];
    }
    
    // ✅ 管理者確認（DBから直接）
    if ($user_from_db['permission_level'] !== 'Admin') {
        ?>
        <!DOCTYPE html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>アクセス拒否</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container mt-5">
                <div class="alert alert-danger">
                    <h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> アクセス拒否</h4>
                    <p>このインストーラーは管理者権限が必要です。</p>
                    <hr>
                    <h5>現在のログイン情報:</h5>
                    <table class="table table-sm">
                        <tr><th>ユーザーID:</th><td><?= htmlspecialchars($user_from_db['id']) ?></td></tr>
                        <tr><th>ログインID:</th><td><?= htmlspecialchars($user_from_db['login_id']) ?></td></tr>
                        <tr><th>ユーザー名:</th><td><?= htmlspecialchars($user_from_db['NAME']) ?></td></tr>
                        <tr><th>権限レベル (DB):</th><td><strong class="text-danger"><?= htmlspecialchars($user_from_db['permission_level']) ?></strong></td></tr>
                    </table>
                    <p><strong>必要:</strong> permission_level = 'Admin'</p>
                    <a href="../index.php" class="btn btn-primary mt-3">ログイン画面に戻る</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
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
        .installer-container { max-width: 800px; margin: 2rem auto; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 2rem; }
        .step { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 10px; position: relative; }
        .step.active { background-color: #007bff; color: white; }
        .step.completed { background-color: #28a745; color: white; }
        .step.pending { background-color: #e9ecef; color: #6c757d; }
        .step:not(:last-child)::after { content: ''; position: absolute; top: 50%; left: 100%; width: 20px; height: 2px; background-color: #e9ecef; z-index: -1; }
        .log-output { background-color: #212529; color: #ffffff; font-family: 'Courier New', monospace; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto; }
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
                    <span class="badge bg-success">
                        <i class="fas fa-user-shield"></i> <?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($permission_level) ?>)
                    </span>
                </div>
            </div>
            <div class="card-body">

<?php

switch ($step) {
    case 1: showWelcomeStep(); break;
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
    default: showWelcomeStep();
}

function showWelcomeStep() {
    global $user_name, $permission_level;
    renderStepIndicator(1);
    ?>
    <h4><i class="fas fa-home me-2"></i>インストーラーへようこそ</h4>
    
    <div class="alert alert-success mb-3">
        <i class="fas fa-check-circle me-2"></i>
        <strong>認証成功！</strong> 管理者としてログインされています。
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
                        <li><i class="fas fa-check-circle text-success me-2"></i>管理者権限 ✓</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div class="alert alert-warning mt-3">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>重要:</strong> データベースのバックアップを作成してください。
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
    <div id="requirementResults">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2">確認中...</p>
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
        'Admin Permission' => [
            'required' => 'Admin',
            'current' => $permission_level,
            'status' => $permission_level === 'Admin'
        ],
        'Directory Permissions' => [
            'required' => '書き込み可能',
            'current' => is_writable(__DIR__) ? '可能' : '不可',
            'status' => is_writable(__DIR__)
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
            すべての要件を満たしています。
        </div>
        <div class="d-grid">
            <a href="?step=3" class="btn btn-primary btn-lg">
                <i class="fas fa-arrow-right me-2"></i>次へ: データベース作成
            </a>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            要件を満たしていません。
        </div>
    <?php endif;
}

function showDatabaseStep() {
    renderStepIndicator(3);
    echo '<h4>データベース作成準備完了</h4>';
    echo '<p>この段階は開発中です。</p>';
}

function createDatabaseTables() { echo '<p>DB作成処理</p>'; }
function showInitialDataStep() { echo '<p>初期データ設定</p>'; }
function insertInitialData() { echo '<p>データ投入処理</p>'; }
function showTestStep() { echo '<p>テスト準備</p>'; }
function runSystemTests() { echo '<p>テスト実行</p>'; }
function showCompletionStep() { echo '<p>完了準備</p>'; }
function completeInstallation() { echo '<p>完了処理</p>'; }

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
        $pdo = getDBConnection();
        return $pdo !== false;
    } catch (Exception $e) {
        return false;
    }
}

?>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
