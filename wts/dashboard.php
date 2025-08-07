<?php
session_start();

// ログイン確認のみ（権限チェックなし）
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// 集金確認テーブルが存在しない場合は作成
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cash_confirmations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            confirmation_date DATE NOT NULL UNIQUE,
            confirmed_amount INT NOT NULL DEFAULT 0,
            calculated_amount INT NOT NULL DEFAULT 0,
            difference INT NOT NULL DEFAULT 0,
            memo TEXT,
            confirmed_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_confirmation_date (confirmation_date)
        )
    ");
} catch (PDOException $e) {
    // テーブル作成失敗は無視
}

// アクティブタブの決定
$active_tab = $_GET['tab'] ?? 'daily';

// 日付フィルター
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = $_GET['month'] ?? date('Y-m');

// メッセージ処理
$message = '';
$error = '';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'confirm_cash':
                    $date = $_POST['target_date'];
                    $confirmed_amount = (int)$_POST['confirmed_amount'];
                    $difference = (int)$_POST['difference'];
                    $memo = $_POST['memo'] ?? '';
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_confirmations (confirmation_date, confirmed_amount, calculated_amount, difference, memo, confirmed_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                        confirmed_amount = VALUES(confirmed_amount),
                        calculated_amount = VALUES(calculated_amount),
                        difference = VALUES(difference),
                        memo = VALUES(memo),
                        confirmed_by = VALUES(confirmed_by),
                        updated_at = NOW()
                    ");
                    
                    $calculated_amount = $confirmed_amount - $difference;
                    $stmt->execute([$date, $confirmed_amount, $calculated_amount, $difference, $memo, $_SESSION['user_id']]);
                    
                    $message = "現金確認を記録しました。";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// 共通関数定義
require_once 'includes/cash_functions.php';
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集金管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .navbar-brand { font-weight: bold; }
        .stats-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px; }
        .cash-card { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border-radius: 15px; }
        .card-card { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border-radius: 15px; }
        .difference-positive { color: #dc3545; font-weight: bold; }
        .difference-negative { color: #198754; font-weight: bold; }
        .amount-display { font-size: 1.5rem; font-weight: bold; }
        .summary-table th { background-color: #f8f9fa; font-weight: 600; }
        .confirmation-section { background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 10px; }
        .confirmed-section { background-color: #d1edff; border: 1px solid #b8daff; border-radius: 10px; }
        .tab-content { min-height: 500px; }
    </style>
</head>

<body class="bg-light">
    <!-- ナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-calculator me-2"></i>集金管理
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($user['name']) ?>さん
                </span>
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-home me-1"></i>ダッシュボード
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- メッセージ表示 -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- タブナビゲーション -->
        <ul class="nav nav-tabs mb-4" id="cashTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'daily' ? 'active' : '' ?>" 
                        id="daily-tab" data-bs-toggle="tab" data-bs-target="#daily" 
                        type="button" role="tab">
                    <i class="fas fa-calendar-day me-2"></i>日次管理
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'monthly' ? 'active' : '' ?>" 
                        id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly" 
                        type="button" role="tab">
                    <i class="fas fa-calendar-alt me-2"></i>月次管理
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'reports' ? 'active' : '' ?>" 
                        id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" 
                        type="button" role="tab">
                    <i class="fas fa-chart-line me-2"></i>レポート
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $active_tab === 'settings' ? 'active' : '' ?>" 
                        id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" 
                        type="button" role="tab">
                    <i class="fas fa-cog me-2"></i>設定
                </button>
            </li>
        </ul>

        <!-- タブコンテンツ -->
        <div class="tab-content" id="cashTabContent">
            <!-- 日次管理タブ -->
            <div class="tab-pane fade <?= $active_tab === 'daily' ? 'show active' : '' ?>" 
                 id="daily" role="tabpanel">
                <?php include 'includes/cash_daily.php'; ?>
            </div>

            <!-- 月次管理タブ -->
            <div class="tab-pane fade <?= $active_tab === 'monthly' ? 'show active' : '' ?>" 
                 id="monthly" role="tabpanel">
                <?php include 'includes/cash_monthly.php'; ?>
            </div>

            <!-- レポートタブ -->
            <div class="tab-pane fade <?= $active_tab === 'reports' ? 'show active' : '' ?>" 
                 id="reports" role="tabpanel">
                <?php include 'includes/cash_reports.php'; ?>
            </div>

            <!-- 設定タブ -->
            <div class="tab-pane fade <?= $active_tab === 'settings' ? 'show active' : '' ?>" 
                 id="settings" role="tabpanel">
                <?php include 'includes/cash_settings.php'; ?>
            </div>
        </div>

        <!-- 戻るボタン -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>ダッシュボードに戻る
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/cash_management.js"></script>
</body>
</html>
