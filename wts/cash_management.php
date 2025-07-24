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

// ride_recordsテーブルの構造を動的に確認
function getTableColumns($pdo, $table_name) {
    try {
        $stmt = $pdo->query("DESCRIBE {$table_name}");
        return array_column($stmt->fetchAll(), 'Field');
    } catch (PDOException $e) {
        return [];
    }
}

$ride_columns = getTableColumns($pdo, 'ride_records');

// カラム名を動的に決定
$fare_column = 'fare';
$charge_column = 'charge';

if (in_array('fare_amount', $ride_columns)) {
    $fare_column = 'fare_amount';
}
if (in_array('charge_amount', $ride_columns)) {
    $charge_column = 'charge_amount';
}

// 日付フィルター
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = $_GET['month'] ?? date('Y-m');

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'confirm_cash':
                    // 現金確認処理
                    $date = $_POST['target_date'];
                    $confirmed_amount = (int)$_POST['confirmed_amount'];
                    $calculated_amount = (int)$_POST['calculated_amount'];
                    $difference = $confirmed_amount - $calculated_amount;
                    $memo = $_POST['memo'] ?? '';
                    
                    // 集金確認記録を保存
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
                    
                    $stmt->execute([$date, $confirmed_amount, $calculated_amount, $difference, $memo, $_SESSION['user_id']]);
                    
                    $message = "現金確認を記録しました。";
                    break;
                    
                case 'export_daily_report':
                    // 日次レポート出力
                    $report_date = $_POST['report_date'];
                    header('Location: ?action=export_daily&date=' . $report_date);
                    exit();
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// 日次売上データ取得（修正版）
function getDailySales($pdo, $date, $fare_column, $charge_column) {
    // 金額計算部分を動的に構築
    $amount_sql = "COALESCE({$fare_column}, 0)";
    if (in_array($charge_column, getTableColumns($pdo, 'ride_records'))) {
        $amount_sql .= " + COALESCE({$charge_column}, 0)";
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(payment_method, '不明') as payment_method,
            COUNT(*) as count,
            SUM({$amount_sql}) as total_amount,
            AVG({$amount_sql}) as avg_amount
        FROM ride_records 
        WHERE DATE(ride_date) = ? 
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 日次合計取得（修正版）
function getDailyTotal($pdo, $date, $fare_column, $charge_column) {
    // 金額計算部分を動的に構築
    $amount_sql = "COALESCE({$fare_column}, 0)";
    if (in_array($charge_column, getTableColumns($pdo, 'ride_records'))) {
        $amount_sql .= " + COALESCE({$charge_column}, 0)";
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            SUM({$amount_sql}) as total_amount,
            SUM(CASE WHEN payment_method = '現金' THEN {$amount_sql} ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'カード' THEN {$amount_sql} ELSE 0 END) as card_amount,
            SUM(CASE WHEN payment_method = 'その他' THEN {$amount_sql} ELSE 0 END) as other_amount,
            SUM(CASE WHEN payment_method IS NULL OR payment_method = '' THEN {$amount_sql} ELSE 0 END) as unknown_amount
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ");
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 月次集計データ取得（修正版）
function getMonthlySummary($pdo, $month, $fare_column, $charge_column) {
    // 金額計算部分を動的に構築
    $amount_sql = "COALESCE({$fare_column}, 0)";
    if (in_array($charge_column, getTableColumns($pdo, 'ride_records'))) {
        $amount_sql .= " + COALESCE({$charge_column}, 0)";
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE(ride_date) as date,
            COUNT(*) as rides,
            SUM({$amount_sql}) as total,
            SUM(CASE WHEN payment_method = '現金' THEN {$amount_sql} ELSE 0 END) as cash,
            SUM(CASE WHEN payment_method = 'カード' THEN {$amount_sql} ELSE 0 END) as card,
            SUM(CASE WHEN payment_method = 'その他' THEN {$amount_sql} ELSE 0 END) as other
        FROM ride_records 
        WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
        GROUP BY DATE(ride_date)
        ORDER BY date
    ");
    $stmt->execute([$month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 集金確認記録取得
function getCashConfirmation($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT cc.*, u.name as confirmed_by_name
        FROM cash_confirmations cc
        LEFT JOIN users u ON cc.confirmed_by = u.id
        WHERE cc.confirmation_date = ?
    ");
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// データ取得
$daily_sales = getDailySales($pdo, $selected_date, $fare_column, $charge_column);
$daily_total = getDailyTotal($pdo, $selected_date, $fare_column, $charge_column);
$monthly_summary = getMonthlySummary($pdo, $selected_month, $fare_column, $charge_column);
$cash_confirmation = getCashConfirmation($pdo, $selected_date);

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
    // テーブル作成に失敗した場合は警告のみ表示
    $error = "集金確認テーブルの作成に失敗しました。システム管理者にお問い合わせください。";
}

// デバッグ情報（開発時のみ表示）
$debug_info = [
    'ride_columns' => $ride_columns,
    'fare_column' => $fare_column,
    'charge_column' => $charge_column,
    'sample_data' => []
];

// サンプルデータ取得（最新5件）
try {
    $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE DATE(ride_date) = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$selected_date]);
    $debug_info['sample_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $debug_info['sample_data'] = ['error' => $e->getMessage()];
}
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
        .navbar-brand {
            font-weight: bold;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
        }
        .cash-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 15px;
        }
        .card-card {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-radius: 15px;
        }
        .other-card {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #333;
            border-radius: 15px;
        }
        .difference-positive {
            color: #dc3545;
            font-weight: bold;
        }
        .difference-negative {
            color: #198754;
            font-weight: bold;
        }
        .amount-display {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .summary-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .confirmation-section {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
        }
        .confirmed-section {
            background-color: #d1edff;
            border: 1px solid #b8daff;
            border-radius: 10px;
        }
        .debug-section {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 0.9rem;
        }
        .debug-toggle {
            background: none;
            border: none;
            color: #6c757d;
            text-decoration: underline;
            cursor: pointer;
        }
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

        <!-- 日付選択 -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title"><i class="fas fa-calendar-day me-2"></i>日次集計</h6>
                        <form method="GET" class="d-flex">
                            <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" class="form-control me-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title"><i class="fas fa-calendar-alt me-2"></i>月次集計</h6>
                        <form method="GET" class="d-flex">
                            <input type="month" name="month" value="<?= htmlspecialchars($selected_month) ?>" class="form-control me-2">
                            <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 日次売上サマリー -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($daily_total['total_amount'] ?? 0) ?></div>
                        <div>総売上</div>
                        <small><?= $daily_total['total_rides'] ?? 0 ?>回</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card cash-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($daily_total['cash_amount'] ?? 0) ?></div>
                        <div>現金売上</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($daily_total['card_amount'] ?? 0) ?></div>
                        <div>カード売上</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card other-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format(($daily_total['other_amount'] ?? 0) + ($daily_total['unknown_amount'] ?? 0)) ?></div>
                        <div>その他</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 現金確認セクション -->
        <div class="row mb-4">
            <div class="col-12">
                <?php if ($cash_confirmation): ?>
                    <!-- 確認済み -->
                    <div class="card confirmed-section">
                        <div class="card-body">
                            <h5 class="card-title text-primary">
                                <i class="fas fa-check-circle me-2"></i>現金確認済み
                                <small class="text-muted">(<?= date('Y/m/d', strtotime($selected_date)) ?>)</small>
                            </h5>
                            <div class="row">
                                <div class="col-md-2">
                                    <strong>実現金額:</strong><br>
                                    <span class="fs-5">¥<?= number_format($cash_confirmation['confirmed_amount']) ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>計算売上:</strong><br>
                                    <span class="fs-5">¥<?= number_format($cash_confirmation['calculated_amount']) ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>差額:</strong><br>
                                    <span class="fs-5 <?= $cash_confirmation['difference'] > 0 ? 'difference-positive' : ($cash_confirmation['difference'] < 0 ? 'difference-negative' : '') ?>">
                                        <?= $cash_confirmation['difference'] > 0 ? '+' : '' ?>¥<?= number_format($cash_confirmation['difference']) ?>
                                    </span>
                                </div>
                                <div class="col-md-3">
                                    <strong>確認者:</strong><br>
                                    <?= htmlspecialchars($cash_confirmation['confirmed_by_name']) ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>確認日時:</strong><br>
                                    <?= date('Y/m/d H:i', strtotime($cash_confirmation['created_at'])) ?>
                                </div>
                            </div>
                            <?php if ($cash_confirmation['memo']): ?>
                                <div class="mt-3">
                                    <strong>メモ:</strong><br>
                                    <?= nl2br(htmlspecialchars($cash_confirmation['memo'])) ?>
                                </div>
                            <?php endif; ?>
                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-primary" onclick="editConfirmation()">
                                    <i class="fas fa-edit me-1"></i>修正
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 未確認 -->
                    <div class="card confirmation-section">
                        <div class="card-body">
                            <h5 class="card-title text-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>現金確認が必要です
                                <small class="text-muted">(<?= date('Y/m/d', strtotime($selected_date)) ?>)</small>
                            </h5>
                            <form method="POST" id="cashConfirmForm">
                                <input type="hidden" name="action" value="confirm_cash">
                                <input type="hidden" name="target_date" value="<?= htmlspecialchars($selected_date) ?>">
                                <input type="hidden" name="calculated_amount" value="<?= $daily_total['cash_amount'] ?? 0 ?>">
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="form-label">計算上の現金売上</label>
                                        <div class="fs-4 text-primary">¥<?= number_format($daily_total['cash_amount'] ?? 0) ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="confirmed_amount" class="form-label">実際の現金額 *</label>
                                        <input type="number" class="form-control" id="confirmed_amount" name="confirmed_amount" 
                                               value="<?= $daily_total['cash_amount'] ?? 0 ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">差額</label>
                                        <div class="fs-4" id="difference_display">¥0</div>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <label for="memo" class="form-label">メモ（差額がある場合の理由等）</label>
                                        <textarea class="form-control" id="memo" name="memo" rows="2" 
                                                  placeholder="差額の理由や特記事項があれば記入してください"></textarea>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-check me-1"></i>現金確認を記録
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 詳細売上データ -->
        <?php if ($daily_sales): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-list me-2"></i>支払方法別詳細 (<?= date('Y/m/d', strtotime($selected_date)) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table summary-table">
                                <thead>
                                    <tr>
                                        <th>支払方法</th>
                                        <th class="text-end">乗車回数</th>
                                        <th class="text-end">合計金額</th>
                                        <th class="text-end">平均単価</th>
                                        <th class="text-end">構成比</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daily_sales as $sale): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-<?= $sale['payment_method'] === '現金' ? 'money-bill' : ($sale['payment_method'] === 'カード' ? 'credit-card' : 'ellipsis-h') ?> me-2"></i>
                                                <?= htmlspecialchars($sale['payment_method']) ?>
                                            </td>
                                            <td class="text-end"><?= number_format($sale['count']) ?>回</td>
                                            <td class="text-end">¥<?= number_format($sale['total_amount']) ?></td>
                                            <td class="text-end">¥<?= number_format($sale['avg_amount']) ?></td>
                                            <td class="text-end">
                                                <?= round(($sale['total_amount'] / ($daily_total['total_amount'] ?: 1)) * 100, 1) ?>%
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-dark">
                                    <tr>
                                        <th>月計</th>
                                        <th class="text-end"><?= number_format($monthly_total_rides) ?>回</th>
                                        <th class="text-end">¥<?= number_format($monthly_total_amount) ?></th>
                                        <th class="text-end">¥<?= number_format($monthly_cash_amount) ?></th>
                                        <th class="text-end">¥<?= number_format($monthly_card_amount) ?></th>
                                        <th class="text-end">¥<?= number_format($monthly_other_amount) ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- デバッグ情報セクション -->
        <div class="row mb-4">
            <div class="col-12">
                <button type="button" class="debug-toggle" onclick="toggleDebug()">
                    <i class="fas fa-bug me-1"></i>デバッグ情報を表示
                </button>
                <div id="debug-section" class="debug-section" style="display: none;">
                    <h6><i class="fas fa-info-circle me-2"></i>システム診断情報</h6>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6>テーブル構造:</h6>
                            <ul class="mb-3">
                                <li>使用する金額カラム: <code><?= $fare_column ?></code> + <code><?= $charge_column ?></code></li>
                                <li>検出されたカラム数: <?= count($ride_columns) ?>個</li>
                            </ul>
                            
                            <h6>利用可能カラム:</h6>
                            <div class="mb-3">
                                <?php foreach ($ride_columns as $col): ?>
                                    <span class="badge bg-secondary me-1"><?= htmlspecialchars($col) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h6>本日のサンプルデータ (最新5件):</h6>
                            <?php if (!empty($debug_info['sample_data']) && !isset($debug_info['sample_data']['error'])): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>支払方法</th>
                                                <th>運賃</th>
                                                <th>料金</th>
                                                <th>合計</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($debug_info['sample_data'] as $record): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($record['id'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($record['payment_method'] ?? '未設定') ?></td>
                                                    <td>¥<?= number_format($record[$fare_column] ?? 0) ?></td>
                                                    <td>
                                                        <?php if (isset($record[$charge_column])): ?>
                                                            ¥<?= number_format($record[$charge_column] ?? 0) ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>¥<?= number_format(($record[$fare_column] ?? 0) + ($record[$charge_column] ?? 0)) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif (isset($debug_info['sample_data']['error'])): ?>
                                <div class="alert alert-warning">
                                    データ取得エラー: <?= htmlspecialchars($debug_info['sample_data']['error']) ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">本日のデータはありません</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>集計値検証:</h6>
                            <ul>
                                <li>総売上: ¥<?= number_format($daily_total['total_amount'] ?? 0) ?> (<?= $daily_total['total_rides'] ?? 0 ?>回)</li>
                                <li>現金売上: ¥<?= number_format($daily_total['cash_amount'] ?? 0) ?></li>
                                <li>カード売上: ¥<?= number_format($daily_total['card_amount'] ?? 0) ?></li>
                                <li>その他売上: ¥<?= number_format($daily_total['other_amount'] ?? 0) ?></li>
                                <li>不明支払方法: ¥<?= number_format($daily_total['unknown_amount'] ?? 0) ?></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <strong>データが合わない場合の対処法:</strong><br>
                        1. テーブル構造が正しく検出されているか確認<br>
                        2. 支払方法が正しく設定されているか確認<br>
                        3. ride_recordsテーブルのデータ整合性をチェック<br>
                        4. 必要に応じて<a href="fix_ride_records_table.php" target="_blank">テーブル修正ツール</a>を実行
                    </div>
                </div>
            </div>
        </div>

        <!-- 戻るボタン -->
        <div class="row">
            <div class="col-12 text-center">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>ダッシュボードに戻る
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 差額計算
        document.getElementById('confirmed_amount').addEventListener('input', function() {
            const confirmedAmount = parseInt(this.value) || 0;
            const calculatedAmount = <?= $daily_total['cash_amount'] ?? 0 ?>;
            const difference = confirmedAmount - calculatedAmount;
            
            const diffDisplay = document.getElementById('difference_display');
            
            if (difference > 0) {
                diffDisplay.innerHTML = '+¥' + difference.toLocaleString();
                diffDisplay.className = 'fs-4 difference-positive';
            } else if (difference < 0) {
                diffDisplay.innerHTML = '¥' + difference.toLocaleString();
                diffDisplay.className = 'fs-4 difference-negative';
            } else {
                diffDisplay.innerHTML = '¥0';
                diffDisplay.className = 'fs-4';
            }
        });
        
        // 確認修正
        function editConfirmation() {
            if (confirm('現金確認記録を修正しますか？')) {
                location.reload();
            }
        }
        
        // フォーム送信確認
        document.getElementById('cashConfirmForm')?.addEventListener('submit', function(e) {
            const confirmedAmount = parseInt(document.getElementById('confirmed_amount').value);
            const calculatedAmount = <?= $daily_total['cash_amount'] ?? 0 ?>;
            const difference = confirmedAmount - calculatedAmount;
            
            if (Math.abs(difference) > 0) {
                if (!confirm(`差額が${difference > 0 ? '+' : ''}¥${difference.toLocaleString()}あります。\n記録してよろしいですか？`)) {
                    e.preventDefault();
                }
            }
        });
        
        // デバッグ情報表示切り替え
        function toggleDebug() {
            const debugSection = document.getElementById('debug-section');
            const toggleButton = document.querySelector('.debug-toggle');
            
            if (debugSection.style.display === 'none') {
                debugSection.style.display = 'block';
                toggleButton.innerHTML = '<i class="fas fa-bug me-1"></i>デバッグ情報を非表示';
            } else {
                debugSection.style.display = 'none';
                toggleButton.innerHTML = '<i class="fas fa-bug me-1"></i>デバッグ情報を表示';
            }
        }
        
        // 自動リフレッシュ（開発時は無効化）
        // setTimeout(function() {
        //     location.reload();
        // }, 300000);
    </script>
</body>
</html>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 月次サマリー -->
        <?php if ($monthly_summary): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-chart-line me-2"></i>月次サマリー (<?= date('Y年m月', strtotime($selected_month . '-01')) ?>)</h5>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="export_daily_report">
                            <input type="hidden" name="report_date" value="<?= htmlspecialchars($selected_month) ?>">
                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-download me-1"></i>レポート出力
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped summary-table">
                                <thead>
                                    <tr>
                                        <th>日付</th>
                                        <th class="text-end">乗車回数</th>
                                        <th class="text-end">総売上</th>
                                        <th class="text-end">現金</th>
                                        <th class="text-end">カード</th>
                                        <th class="text-end">その他</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $monthly_total_rides = 0;
                                    $monthly_total_amount = 0;
                                    $monthly_cash_amount = 0;
                                    $monthly_card_amount = 0;
                                    $monthly_other_amount = 0;
                                    
                                    foreach ($monthly_summary as $day): 
                                        $monthly_total_rides += $day['rides'];
                                        $monthly_total_amount += $day['total'];
                                        $monthly_cash_amount += $day['cash'];
                                        $monthly_card_amount += $day['card'];
                                        $monthly_other_amount += $day['other'];
                                    ?>
                                        <tr>
                                            <td><?= date('m/d(D)', strtotime($day['date'])) ?></td>
                                            <td class="text-end"><?= number_format($day['rides']) ?>回</td>
                                            <td class="text-end">¥<?= number_format($day['total']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['cash']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['card']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['other']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
