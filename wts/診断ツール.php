<?php
/**
 * データベース不整合 統合修正スクリプト
 * キャッシュマネージメント実装後の不整合を完全解決
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 データベース不整合 統合修正スクリプト</h1>";
echo "<div style='font-family: Arial; background: #f8f9fa; padding: 20px;'>";

// 修正ステップ1: 統一DB設定ファイル作成
echo "<h2>ステップ1: 統一DB設定ファイル作成</h2>";

$unified_config = '<?php
/**
 * 統一データベース設定ファイル
 * 全システムで共通使用
 */

// データベース接続設定
define("DB_HOST", "localhost");
define("DB_NAME", "twinklemark_wts");
define("DB_USER", "twinklemark_taxi");
define("DB_PASS", "Smiley2525");
define("DB_CHARSET", "utf8mb4");

/**
 * データベース接続を取得
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            die("データベース接続エラー: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * 旧形式の互換性のため
 */
$pdo = getDBConnection();
?>';

// config/database.php を更新
if (!is_dir('config')) {
    mkdir('config', 0755, true);
}

file_put_contents('config/database.php', $unified_config);
echo "<div style='background: #d4edda; padding: 10px; margin: 5px;'>";
echo "✅ 統一DB設定ファイル作成完了: config/database.php";
echo "</div>";

// 修正ステップ2: セッション修正
echo "<h2>ステップ2: セッション修正</h2>";

session_start();

// 現在のセッション状況確認
if (isset($_SESSION['user_id'])) {
    echo "<div style='background: #fff3cd; padding: 10px; margin: 5px;'>";
    echo "現在のセッション - ユーザーID: " . $_SESSION['user_id'];
    if (isset($_SESSION['role'])) {
        echo ", 権限: " . $_SESSION['role'];
    }
    echo "</div>";
    
    // DBから正しい権限を取得
    try {
        include 'config/database.php';
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_role = $stmt->fetchColumn();
        
        if ($user_role) {
            $_SESSION['role'] = $user_role;
            echo "<div style='background: #d4edda; padding: 10px; margin: 5px;'>";
            echo "✅ セッション権限を修正: " . $user_role;
            echo "</div>";
        }
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 10px; margin: 5px;'>";
        echo "❌ セッション修正エラー: " . $e->getMessage();
        echo "</div>";
    }
}

// 修正ステップ3: ダッシュボード修正
echo "<h2>ステップ3: ダッシュボード修正</h2>";

$fixed_dashboard = '<?php
session_start();

// セッションチェック
if (!isset($_SESSION[\'user_id\'])) {
    header("Location: index.php");
    exit();
}

// 統一DB設定を使用
require_once \'config/database.php\';

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION[\'user_id\']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// ユーザー権限をセッションに設定
$_SESSION[\'role\'] = $user[\'role\'];
$user_role = $user[\'role\'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="fas fa-taxi me-2"></i>福祉輸送管理システム</a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($user[\'name\']) ?>
                    <small class="badge bg-secondary ms-1"><?= htmlspecialchars($user_role) ?></small>
                </span>
                <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i>ログアウト</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- 今日の業務状況 -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>今日の業務状況</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        try {
                            // 今日の出庫車両数
                            $stmt = $pdo->query("SELECT COUNT(DISTINCT vehicle_id) as count FROM departure_records WHERE departure_date = CURDATE()");
                            $departure_count = $stmt->fetchColumn() ?: 0;

                            // 今日の乗車回数
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM ride_records WHERE DATE(created_at) = CURDATE()");
                            $ride_count = $stmt->fetchColumn() ?: 0;

                            // 今日の売上
                            $stmt = $pdo->query("SELECT COALESCE(SUM(fare), 0) as total FROM ride_records WHERE DATE(created_at) = CURDATE()");
                            $total_sales = $stmt->fetchColumn() ?: 0;

                            // 未入庫車両
                            $stmt = $pdo->query("
                                SELECT COUNT(*) as count 
                                FROM departure_records d 
                                LEFT JOIN arrival_records a ON d.id = a.departure_record_id 
                                WHERE d.departure_date = CURDATE() AND a.id IS NULL
                            ");
                            $pending_arrivals = $stmt->fetchColumn() ?: 0;
                        ?>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-primary"><?= $departure_count ?></h3>
                                    <small class="text-muted">稼働車両</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-success"><?= $ride_count ?></h3>
                                    <small class="text-muted">乗車回数</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-info">¥<?= number_format($total_sales) ?></h3>
                                    <small class="text-muted">売上金額</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="<?= $pending_arrivals > 0 ? \'text-warning\' : \'text-success\' ?>"><?= $pending_arrivals ?></h3>
                                    <small class="text-muted">未入庫</small>
                                </div>
                            </div>
                        </div>
                        <?php
                        } catch (Exception $e) {
                            echo "<div class=\'alert alert-danger\'>データ取得エラー: " . htmlspecialchars($e->getMessage()) . "</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- メインメニュー -->
        <div class="row">
            <!-- 日常業務 -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="fas fa-calendar-day me-2"></i>日常業務</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="departure.php" class="btn btn-outline-primary">
                                <i class="fas fa-play me-2"></i>出庫処理
                            </a>
                            <a href="ride_records.php" class="btn btn-outline-success">
                                <i class="fas fa-users me-2"></i>乗車記録
                            </a>
                            <a href="arrival.php" class="btn btn-outline-info">
                                <i class="fas fa-stop me-2"></i>入庫処理
                            </a>
                            <a href="cash_management.php" class="btn btn-outline-warning">
                                <i class="fas fa-yen-sign me-2"></i>集金管理
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 点呼・点検 -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>点呼・点検</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="pre_duty_call.php" class="btn btn-outline-success">
                                <i class="fas fa-check-circle me-2"></i>乗務前点呼
                            </a>
                            <a href="post_duty_call.php" class="btn btn-outline-success">
                                <i class="fas fa-check-circle me-2"></i>乗務後点呼
                            </a>
                            <a href="daily_inspection.php" class="btn btn-outline-info">
                                <i class="fas fa-tools me-2"></i>日常点検
                            </a>
                            <a href="periodic_inspection.php" class="btn btn-outline-warning">
                                <i class="fas fa-cog me-2"></i>定期点検
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($user_role === \'システム管理者\' || $user_role === \'管理者\'): ?>
            <!-- 管理機能 -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>管理機能</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="user_management.php" class="btn btn-outline-dark">
                                <i class="fas fa-users-cog me-2"></i>ユーザー管理
                            </a>
                            <a href="vehicle_management.php" class="btn btn-outline-dark">
                                <i class="fas fa-car me-2"></i>車両管理
                            </a>
                            <a href="annual_report.php" class="btn btn-outline-secondary">
                                <i class="fas fa-file-alt me-2"></i>陸運局提出
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';

// ダッシュボード修正版を保存
file_put_contents('dashboard_fixed.php', $fixed_dashboard);
echo "<div style='background: #d4edda; padding: 10px; margin: 5px;'>";
echo "✅ 修正版ダッシュボード作成完了: dashboard_fixed.php";
echo "</div>";

// 修正ステップ4: cash_management.php の設定確認
echo "<h2>ステップ4: cash_management.php 設定確認</h2>";

if (file_exists('cash_management.php')) {
    $cash_content = file_get_contents('cash_management.php');
    
    if (strpos($cash_content, 'config/database.php') !== false) {
        echo "<div style='background: #d4edda; padding: 10px; margin: 5px;'>";
        echo "✅ cash_management.php は正しい設定ファイルを使用";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 10px; margin: 5px;'>";
        echo "⚠️ cash_management.php の設定ファイル参照を修正が必要";
        echo "</div>";
    }
} else {
    echo "<div style='background: #f8d7da; padding: 10px; margin: 5px;'>";
    echo "❌ cash_management.php が見つかりません";
    echo "</div>";
}

// 完了メッセージ
echo "<h2>🎉 修正完了</h2>";
echo "<div style='background: #d1ecf1; padding: 15px; border: 1px solid #bee5eb;'>";
echo "<h4>次のステップ:</h4>";
echo "<ol>";
echo "<li><strong>ログアウト→再ログイン</strong> - セッションをリセット</li>";
echo "<li><strong>dashboard_fixed.php をテスト</strong> - 修正版ダッシュボードの確認</li>";
echo "<li><strong>問題が解決したら dashboard.php を置き換え</strong></li>";
echo "<li><strong>各機能の動作確認</strong> - 特にcash_managementとの連携</li>";
echo "</ol>";
echo "</div>";

echo "</div>";
?>
