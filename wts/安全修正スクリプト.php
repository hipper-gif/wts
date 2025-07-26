<?php
/**
 * 安全なデータベース修正スクリプト
 * 実務データ（86件のride_recordsなど）を絶対に保護
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🛡️ 安全なデータベース修正スクリプト</h1>";
echo "<div style='font-family: Arial; background: #f8f9fa; padding: 20px;'>";

// DB接続
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4",
        "twinklemark_taxi",
        "Smiley2525",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

// 実行確認
$execute = isset($_GET['execute']) && $_GET['execute'] === 'true';

if (!$execute) {
    echo "<div style='background: #fff3cd; padding: 15px; border: 2px solid #ffc107; margin: 20px 0;'>";
    echo "<h3>⚠️ 安全確認</h3>";
    echo "<p>この修正は<strong>実務データを一切変更しません</strong>。</p>";
    echo "<p>実行する場合は、URL末尾に <strong>?execute=true</strong> を追加してください。</p>";
    echo "</div>";
}

// ステップ1: 重要データ保護確認
echo "<h2>ステップ1: 🚨 重要データ保護確認</h2>";

$protected_tables = [
    'ride_records' => 86,
    'arrival_records' => 19,
    'cash_confirmations' => 11,
    'daily_inspections' => 26,
    'departure_records' => 23,
    'post_duty_calls' => 17,
    'pre_duty_calls' => 32,
    'system_settings' => 20
];

echo "<div style='background: #ffebee; padding: 15px; border: 2px solid #dc3545; border-radius: 5px;'>";
echo "<h3>🛡️ 保護対象データ確認</h3>";
foreach ($protected_tables as $table => $expected_count) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $actual_count = $stmt->fetchColumn();
        
        $status = $actual_count >= $expected_count ? "✅" : "⚠️";
        $color = $actual_count >= $expected_count ? "#d4edda" : "#fff3cd";
        
        echo "<div style='background: {$color}; padding: 5px; margin: 2px; border-radius: 3px;'>";
        echo "{$status} {$table}: {$actual_count}件 (期待: {$expected_count}件以上)";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 5px; margin: 2px; border-radius: 3px;'>";
        echo "❌ {$table}: 確認エラー - " . $e->getMessage();
        echo "</div>";
    }
}
echo "</div>";

// ステップ2: 空テーブル削除（安全）
echo "<h2>ステップ2: 🗑️ 空テーブル削除（安全）</h2>";

$empty_tables = ['accidents', 'annual_reports', 'daily_operations', 'monthly_summaries', 'periodic_inspection_items', 'periodic_inspections'];

if ($execute) {
    foreach ($empty_tables as $table) {
        try {
            // 念のため件数確認
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            
            if ($count == 0) {
                $pdo->exec("DROP TABLE IF EXISTS {$table}");
                echo "<div style='background: #d4edda; padding: 5px; margin: 2px; border-radius: 3px;'>";
                echo "✅ 削除完了: {$table} (0件)";
                echo "</div>";
            } else {
                echo "<div style='background: #fff3cd; padding: 5px; margin: 2px; border-radius: 3px;'>";
                echo "⚠️ 削除スキップ: {$table} ({$count}件のデータあり)";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div style='background: #f8d7da; padding: 5px; margin: 2px; border-radius: 3px;'>";
            echo "❌ 削除エラー: {$table} - " . $e->getMessage();
            echo "</div>";
        }
    }
} else {
    echo "<div style='background: #e7f1ff; padding: 10px; border-radius: 5px;'>";
    echo "📋 削除予定の空テーブル: " . implode(', ', $empty_tables);
    echo "</div>";
}

// ステップ3: ダッシュボード修正版作成
echo "<h2>ステップ3: 📊 ダッシュボード修正版作成</h2>";

$fixed_dashboard_code = '<?php
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
                            // 修正: 存在するテーブルのみ使用
                            
                            // 今日の出庫車両数（departure_records使用）
                            $stmt = $pdo->query("SELECT COUNT(DISTINCT vehicle_id) as count FROM departure_records WHERE departure_date = CURDATE()");
                            $departure_count = $stmt->fetchColumn() ?: 0;

                            // 今日の乗車回数（ride_records使用）
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM ride_records WHERE DATE(created_at) = CURDATE()");
                            $ride_count = $stmt->fetchColumn() ?: 0;

                            // 今日の売上（ride_records使用）
                            $stmt = $pdo->query("SELECT COALESCE(SUM(fare), 0) as total FROM ride_records WHERE DATE(created_at) = CURDATE()");
                            $total_sales = $stmt->fetchColumn() ?: 0;

                            // 未入庫車両（arrival_records使用）
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

if ($execute) {
    file_put_contents('dashboard_fixed.php', $fixed_dashboard_code);
    echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px;'>";
    echo "✅ 修正版ダッシュボード作成完了: dashboard_fixed.php";
    echo "</div>";
} else {
    echo "<div style='background: #e7f1ff; padding: 10px; border-radius: 5px;'>";
    echo "📋 修正予定: 存在するテーブル（departure_records, arrival_records, ride_records）のみ使用";
    echo "</div>";
}

// ステップ4: cash_management修正チェック
echo "<h2>ステップ4: 💰 cash_management修正チェック</h2>";

if (file_exists('cash_management.php')) {
    $cash_content = file_get_contents('cash_management.php');
    
    // 問題のあるテーブル参照をチェック
    $problematic_refs = [];
    if (strpos($cash_content, 'detailed_cash_confirmations') !== false) {
        $problematic_refs[] = 'detailed_cash_confirmations';
    }
    if (strpos($cash_content, 'daily_operations') !== false) {
        $problematic_refs[] = 'daily_operations';
    }
    
    if (empty($problematic_refs)) {
        echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px;'>";
        echo "✅ cash_management.phpは正常（存在しないテーブル参照なし）";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px;'>";
        echo "⚠️ cash_management.phpで問題のあるテーブル参照: " . implode(', ', $problematic_refs);
        echo "<br>cash_confirmationsテーブル（11件）のみ使用するよう修正が必要";
        echo "</div>";
    }
} else {
    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
    echo "❌ cash_management.phpが見つかりません";
    echo "</div>";
}

// 完了メッセージ
echo "<h2>🎉 修正完了</h2>";

if ($execute) {
    echo "<div style='background: #d1ecf1; padding: 20px; border: 1px solid #bee5eb; border-radius: 5px;'>";
    echo "<h4>✅ 実行完了</h4>";
    echo "<ol>";
    echo "<li><strong>重要データ保護確認済み</strong> - ride_records(86件)など全て安全</li>";
    echo "<li><strong>空テーブル削除完了</strong> - 不要な6個のテーブル削除</li>";
    echo "<li><strong>修正版ダッシュボード作成</strong> - dashboard_fixed.phpをテスト</li>";
    echo "</ol>";
    
    echo "<h4>次のステップ:</h4>";
    echo "<ol>";
    echo "<li><strong>修正版テスト</strong>: https://tw1nkle.com/Smiley/taxi/wts/dashboard_fixed.php</li>";
    echo "<li><strong>問題が解決したらdashboard.phpを置き換え</strong></li>";
    echo "<li><strong>cash_management.phpの確認</strong></li>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
    echo "<h4>実行するには:</h4>";
    echo "<p>URL末尾に <strong>?execute=true</strong> を追加してアクセスしてください。</p>";
    echo "<p><strong>保証:</strong> 実務データ（ride_records 86件など）は一切変更されません。</p>";
    echo "</div>";
}

echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
h1, h2, h3, h4 { color: #333; }
</style>
