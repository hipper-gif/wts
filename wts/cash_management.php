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

// テーブル構造を動的に確認
function getTableColumns($pdo, $table_name) {
    try {
        $stmt = $pdo->query("DESCRIBE {$table_name}");
        return array_column($stmt->fetchAll(), 'Field');
    } catch (PDOException $e) {
        return [];
    }
}

// 運転手一覧取得
function getDrivers($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('運転者', 'システム管理者', '管理者') ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$drivers = getDrivers($pdo);
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

// フィルター取得
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$selected_driver = $_GET['driver_id'] ?? '';

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'confirm_cash':
                    // 現金確認処理（金種計算含む）
                    $date = $_POST['target_date'];
                    
                    // 金種から金額計算
                    $cash_breakdown = [
                        'bill_10000' => (int)($_POST['bill_10000'] ?? 0),
                        'bill_5000' => (int)($_POST['bill_5000'] ?? 0),
                        'bill_2000' => (int)($_POST['bill_2000'] ?? 0),
                        'bill_1000' => (int)($_POST['bill_1000'] ?? 0),
                        'coin_500' => (int)($_POST['coin_500'] ?? 0),
                        'coin_100' => (int)($_POST['coin_100'] ?? 0),
                        'coin_50' => (int)($_POST['coin_50'] ?? 0),
                        'coin_10' => (int)($_POST['coin_10'] ?? 0),
                        'coin_5' => (int)($_POST['coin_5'] ?? 0),
                        'coin_1' => (int)($_POST['coin_1'] ?? 0),
                        'change_fund' => (int)($_POST['change_fund'] ?? 0)
                    ];
                    
                    $confirmed_amount = 
                        $cash_breakdown['bill_10000'] * 10000 +
                        $cash_breakdown['bill_5000'] * 5000 +
                        $cash_breakdown['bill_2000'] * 2000 +
                        $cash_breakdown['bill_1000'] * 1000 +
                        $cash_breakdown['coin_500'] * 500 +
                        $cash_breakdown['coin_100'] * 100 +
                        $cash_breakdown['coin_50'] * 50 +
                        $cash_breakdown['coin_10'] * 10 +
                        $cash_breakdown['coin_5'] * 5 +
                        $cash_breakdown['coin_1'] * 1;
                    
                    $total_with_change = $confirmed_amount + $cash_breakdown['change_fund'];
                    $calculated_amount = (int)$_POST['calculated_amount'];
                    $difference = $confirmed_amount - $calculated_amount;
                    $memo = $_POST['memo'] ?? '';
                    
                    // 集金確認記録を保存
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_confirmations (
                            confirmation_date, confirmed_amount, calculated_amount, difference, 
                            bill_10000, bill_5000, bill_2000, bill_1000,
                            coin_500, coin_100, coin_50, coin_10, coin_5, coin_1,
                            change_fund, total_with_change, memo, confirmed_by, created_at
                        ) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                        confirmed_amount = VALUES(confirmed_amount),
                        calculated_amount = VALUES(calculated_amount),
                        difference = VALUES(difference),
                        bill_10000 = VALUES(bill_10000),
                        bill_5000 = VALUES(bill_5000),
                        bill_2000 = VALUES(bill_2000),
                        bill_1000 = VALUES(bill_1000),
                        coin_500 = VALUES(coin_500),
                        coin_100 = VALUES(coin_100),
                        coin_50 = VALUES(coin_50),
                        coin_10 = VALUES(coin_10),
                        coin_5 = VALUES(coin_5),
                        coin_1 = VALUES(coin_1),
                        change_fund = VALUES(change_fund),
                        total_with_change = VALUES(total_with_change),
                        memo = VALUES(memo),
                        confirmed_by = VALUES(confirmed_by),
                        updated_at = NOW()
                    ");
                    
                    $stmt->execute([
                        $date, $confirmed_amount, $calculated_amount, $difference,
                        $cash_breakdown['bill_10000'], $cash_breakdown['bill_5000'], 
                        $cash_breakdown['bill_2000'], $cash_breakdown['bill_1000'],
                        $cash_breakdown['coin_500'], $cash_breakdown['coin_100'], 
                        $cash_breakdown['coin_50'], $cash_breakdown['coin_10'], 
                        $cash_breakdown['coin_5'], $cash_breakdown['coin_1'],
                        $cash_breakdown['change_fund'], $total_with_change, $memo, $_SESSION['user_id']
                    ]);
                    
                    $message = "現金確認を記録しました。";
                    break;
                    
                case 'export_range_report':
                    // 範囲レポート出力
                    $report_start = $_POST['report_start'];
                    $report_end = $_POST['report_end'];
                    $report_driver = $_POST['report_driver'] ?? '';
                    header('Location: ?action=export_range&start=' . $report_start . '&end=' . $report_end . '&driver=' . $report_driver);
                    exit();
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// 範囲売上データ取得
function getRangeSales($pdo, $start_date, $end_date, $driver_id, $fare_column, $charge_column) {
    $driver_condition = '';
    $params = [$start_date, $end_date];
    
    if ($driver_id) {
        $driver_condition = ' AND r.driver_id = ?';
        $params[] = $driver_id;
    }
    
    $amount_sql = "COALESCE({$fare_column}, 0)";
    if (in_array($charge_column, getTableColumns($GLOBALS['pdo'], 'ride_records'))) {
        $amount_sql .= " + COALESCE({$charge_column}, 0)";
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(r.payment_method, '現金') as payment_method,
                COUNT(*) as count,
                SUM({$amount_sql}) as total_amount,
                AVG({$amount_sql}) as avg_amount
            FROM ride_records r
            WHERE DATE(r.ride_date) BETWEEN ? AND ? {$driver_condition}
            AND ({$amount_sql}) > 0
            GROUP BY r.payment_method
            ORDER BY total_amount DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// 範囲合計取得
function getRangeTotal($pdo, $start_date, $end_date, $driver_id, $fare_column, $charge_column) {
    $driver_condition = '';
    $params = [$start_date, $end_date];
    
    if ($driver_id) {
        $driver_condition = ' AND r.driver_id = ?';
        $params[] = $driver_id;
    }
    
    $amount_sql = "COALESCE({$fare_column}, 0)";
    if (in_array($charge_column, getTableColumns($GLOBALS['pdo'], 'ride_records'))) {
        $amount_sql .= " + COALESCE({$charge_column}, 0)";
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_rides,
                SUM({$amount_sql}) as total_amount,
                SUM(CASE WHEN r.payment_method = '現金' THEN {$amount_sql} ELSE 0 END) as cash_amount,
                SUM(CASE WHEN r.payment_method = 'カード' THEN {$amount_sql} ELSE 0 END) as card_amount,
                SUM(CASE WHEN r.payment_method = 'その他' THEN {$amount_sql} ELSE 0 END) as other_amount,
                SUM(CASE WHEN r.payment_method IS NULL OR r.payment_method = '' THEN {$amount_sql} ELSE 0 END) as unknown_amount
            FROM ride_records r
            WHERE DATE(r.ride_date) BETWEEN ? AND ? {$driver_condition}
        ");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [
            'total_rides' => 0, 'total_amount' => 0, 'cash_amount' => 0,
            'card_amount' => 0, 'other_amount' => 0, 'unknown_amount' => 0
        ];
    }
}

// 日別詳細取得
function getDailyBreakdown($pdo, $start_date, $end_date, $driver_id, $fare_column, $charge_column) {
    $driver_condition = '';
    $params = [$start_date, $end_date];
    
    if ($driver_id) {
        $driver_condition = ' AND r.driver_id = ?';
        $params[] = $driver_id;
    }
    
    $amount_sql = "COALESCE({$fare_column}, 0)";
    if (in_array($charge_column, getTableColumns($GLOBALS['pdo'], 'ride_records'))) {
        $amount_sql .= " + COALESCE({$charge_column}, 0)";
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(r.ride_date) as date,
                COUNT(*) as rides,
                SUM({$amount_sql}) as total,
                SUM(CASE WHEN r.payment_method = '現金' THEN {$amount_sql} ELSE 0 END) as cash,
                SUM(CASE WHEN r.payment_method = 'カード' THEN {$amount_sql} ELSE 0 END) as card,
                SUM(CASE WHEN r.payment_method = 'その他' THEN {$amount_sql} ELSE 0 END) as other
            FROM ride_records r
            WHERE DATE(r.ride_date) BETWEEN ? AND ? {$driver_condition}
            GROUP BY DATE(r.ride_date)
            ORDER BY date DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
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
$range_sales = getRangeSales($pdo, $start_date, $end_date, $selected_driver, $fare_column, $charge_column);
$range_total = getRangeTotal($pdo, $start_date, $end_date, $selected_driver, $fare_column, $charge_column);
$daily_breakdown = getDailyBreakdown($pdo, $start_date, $end_date, $selected_driver, $fare_column, $charge_column);

// 単日の場合は現金確認も取得
$cash_confirmation = null;
if ($start_date === $end_date) {
    $cash_confirmation = getCashConfirmation($pdo, $start_date);
}

// 集金確認テーブルが存在しない場合は作成（金種フィールド追加）
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cash_confirmations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            confirmation_date DATE NOT NULL UNIQUE,
            confirmed_amount INT NOT NULL DEFAULT 0,
            calculated_amount INT NOT NULL DEFAULT 0,
            difference INT NOT NULL DEFAULT 0,
            bill_10000 INT DEFAULT 0,
            bill_5000 INT DEFAULT 0,
            bill_2000 INT DEFAULT 0,
            bill_1000 INT DEFAULT 0,
            coin_500 INT DEFAULT 0,
            coin_100 INT DEFAULT 0,
            coin_50 INT DEFAULT 0,
            coin_10 INT DEFAULT 0,
            coin_5 INT DEFAULT 0,
            coin_1 INT DEFAULT 0,
            change_fund INT DEFAULT 0,
            total_with_change INT DEFAULT 0,
            memo TEXT,
            confirmed_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_confirmation_date (confirmation_date)
        )
    ");
} catch (PDOException $e) {
    // 既存テーブルの場合、カラム追加を試行
    try {
        $pdo->exec("ALTER TABLE cash_confirmations 
                   ADD COLUMN bill_10000 INT DEFAULT 0,
                   ADD COLUMN bill_5000 INT DEFAULT 0,
                   ADD COLUMN bill_2000 INT DEFAULT 0,
                   ADD COLUMN bill_1000 INT DEFAULT 0,
                   ADD COLUMN coin_500 INT DEFAULT 0,
                   ADD COLUMN coin_100 INT DEFAULT 0,
                   ADD COLUMN coin_50 INT DEFAULT 0,
                   ADD COLUMN coin_10 INT DEFAULT 0,
                   ADD COLUMN coin_5 INT DEFAULT 0,
                   ADD COLUMN coin_1 INT DEFAULT 0,
                   ADD COLUMN change_fund INT DEFAULT 0,
                   ADD COLUMN total_with_change INT DEFAULT 0");
    } catch (PDOException $e) {
        // カラム追加に失敗した場合は警告のみ
    }
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
        .cash-input-group {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        .denomination-input {
            max-width: 80px;
        }
        .denomination-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .denomination-label {
            font-weight: 500;
            min-width: 80px;
        }
        .denomination-amount {
            font-weight: bold;
            color: #28a745;
            min-width: 100px;
            text-align: right;
        }
        .total-display {
            background-color: #e3f2fd;
            border: 2px solid #2196f3;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
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

        <!-- フィルター -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title"><i class="fas fa-filter me-2"></i>期間・運転手フィルター</h6>
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="start_date" class="form-label">開始日</label>
                                <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label for="end_date" class="form-label">終了日</label>
                                <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label for="driver_id" class="form-label">運転手</label>
                                <select name="driver_id" id="driver_id" class="form-select">
                                    <option value="">全ての運転手</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>" <?= $selected_driver == $driver['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($driver['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary d-block">
                                    <i class="fas fa-search me-1"></i>検索
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 期間売上サマリー -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($range_total['total_amount'] ?? 0) ?></div>
                        <div>総売上</div>
                        <small><?= $range_total['total_rides'] ?? 0 ?>回</small>
                        <?php if ($start_date !== $end_date): ?>
                            <small class="d-block"><?= date('m/d', strtotime($start_date)) ?>〜<?= date('m/d', strtotime($end_date)) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card cash-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($range_total['cash_amount'] ?? 0) ?></div>
                        <div>現金売上</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($range_total['card_amount'] ?? 0) ?></div>
                        <div>カード売上</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card other-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format(($range_total['other_amount'] ?? 0) + ($range_total['unknown_amount'] ?? 0)) ?></div>
                        <div>その他</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 現金確認セクション（単日の場合のみ） -->
        <?php if ($start_date === $end_date): ?>
        <div class="row mb-4">
            <div class="col-12">
                <?php if ($cash_confirmation): ?>
                    <!-- 確認済み -->
                    <div class="card confirmed-section">
                        <div class="card-body">
                            <h5 class="card-title text-primary">
                                <i class="fas fa-check-circle me-2"></i>現金確認済み
                                <small class="text-muted">(<?= date('Y/m/d', strtotime($start_date)) ?>)</small>
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>金種内訳:</h6>
                                    <div class="cash-input-group">
                                        <div class="row">
                                            <div class="col-6">
                                                <?php if ($cash_confirmation['bill_10000'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">一万円札:</span>
                                                    <span><?= $cash_confirmation['bill_10000'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['bill_10000'] * 10000) ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cash_confirmation['bill_5000'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">五千円札:</span>
                                                    <span><?= $cash_confirmation['bill_5000'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['bill_5000'] * 5000) ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cash_confirmation['bill_2000'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">二千円札:</span>
                                                    <span><?= $cash_confirmation['bill_2000'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['bill_2000'] * 2000) ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cash_confirmation['bill_1000'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">千円札:</span>
                                                    <span><?= $cash_confirmation['bill_1000'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['bill_1000'] * 1000) ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-6">
                                                <?php if ($cash_confirmation['coin_500'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">500円玉:</span>
                                                    <span><?= $cash_confirmation['coin_500'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['coin_500'] * 500) ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cash_confirmation['coin_100'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">100円玉:</span>
                                                    <span><?= $cash_confirmation['coin_100'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['coin_100'] * 100) ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cash_confirmation['coin_50'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">50円玉:</span>
                                                    <span><?= $cash_confirmation['coin_50'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['coin_50'] * 50) ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($cash_confirmation['coin_10'] + $cash_confirmation['coin_5'] + $cash_confirmation['coin_1'] > 0): ?>
                                                <div class="denomination-row">
                                                    <span class="denomination-label">小銭:</span>
                                                    <span><?= $cash_confirmation['coin_10'] + $cash_confirmation['coin_5'] + $cash_confirmation['coin_1'] ?>枚</span>
                                                    <span class="denomination-amount">¥<?= number_format($cash_confirmation['coin_10'] * 10 + $cash_confirmation['coin_5'] * 5 + $cash_confirmation['coin_1']) ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="total-display">
                                        <div class="row">
                                            <div class="col-6">
                                                <strong>売上現金:</strong><br>
                                                <span class="fs-5">¥<?= number_format($cash_confirmation['confirmed_amount']) ?></span>
                                            </div>
                                            <div class="col-6">
                                                <strong>おつり分:</strong><br>
                                                <span class="fs-5">¥<?= number_format($cash_confirmation['change_fund']) ?></span>
                                            </div>
                                        </div>
                                        <hr>
                                        <div class="row">
                                            <div class="col-6">
                                                <strong>計算売上:</strong><br>
                                                <span class="fs-5">¥<?= number_format($cash_confirmation['calculated_amount']) ?></span>
                                            </div>
                                            <div class="col-6">
                                                <strong>差額:</strong><br>
                                                <span class="fs-5 <?= $cash_confirmation['difference'] > 0 ? 'difference-positive' : ($cash_confirmation['difference'] < 0 ? 'difference-negative' : '') ?>">
                                                    <?= $cash_confirmation['difference'] > 0 ? '+' : '' ?>¥<?= number_format($cash_confirmation['difference']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        確認者: <?= htmlspecialchars($cash_confirmation['confirmed_by_name']) ?><br>
                                        確認日時: <?= date('Y/m/d H:i', strtotime($cash_confirmation['created_at'])) ?>
                                    </small>
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
                                <small class="text-muted">(<?= date('Y/m/d', strtotime($start_date)) ?>)</small>
                            </h5>
                            <form method="POST" id="cashConfirmForm">
                                <input type="hidden" name="action" value="confirm_cash">
                                <input type="hidden" name="target_date" value="<?= htmlspecialchars($start_date) ?>">
                                <input type="hidden" name="calculated_amount" value="<?= $range_total['cash_amount'] ?? 0 ?>">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>金種入力:</h6>
                                        <div class="cash-input-group">
                                            <div class="row">
                                                <div class="col-6">
                                                    <label class="form-label">お札</label>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">一万円札:</span>
                                                        <input type="number" name="bill_10000" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="bill_10000_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">五千円札:</span>
                                                        <input type="number" name="bill_5000" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="bill_5000_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">二千円札:</span>
                                                        <input type="number" name="bill_2000" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="bill_2000_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">千円札:</span>
                                                        <input type="number" name="bill_1000" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="bill_1000_amount">¥0</span>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label">硬貨</label>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">500円玉:</span>
                                                        <input type="number" name="coin_500" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="coin_500_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">100円玉:</span>
                                                        <input type="number" name="coin_100" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="coin_100_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">50円玉:</span>
                                                        <input type="number" name="coin_50" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="coin_50_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">10円玉:</span>
                                                        <input type="number" name="coin_10" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="coin_10_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">5円玉:</span>
                                                        <input type="number" name="coin_5" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="coin_5_amount">¥0</span>
                                                    </div>
                                                    <div class="denomination-row">
                                                        <span class="denomination-label">1円玉:</span>
                                                        <input type="number" name="coin_1" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                        <span class="denomination-amount" id="coin_1_amount">¥0</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <hr>
                                            <div class="denomination-row">
                                                <span class="denomination-label">おつり分:</span>
                                                <input type="number" name="change_fund" class="form-control denomination-input" min="0" value="0" onchange="calculateTotal()">
                                                <span class="denomination-amount" id="change_fund_amount">¥0</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6>集計・確認:</h6>
                                        <div class="total-display">
                                            <div class="row">
                                                <div class="col-6">
                                                    <strong>売上現金:</strong><br>
                                                    <span class="fs-4" id="cash_total_display">¥0</span>
                                                </div>
                                                <div class="col-6">
                                                    <strong>おつり分:</strong><br>
                                                    <span class="fs-4" id="change_total_display">¥0</span>
                                                </div>
                                            </div>
                                            <hr>
                                            <div class="row">
                                                <div class="col-6">
                                                    <strong>計算売上:</strong><br>
                                                    <span class="fs-4 text-primary">¥<?= number_format($range_total['cash_amount'] ?? 0) ?></span>
                                                </div>
                                                <div class="col-6">
                                                    <strong>差額:</strong><br>
                                                    <span class="fs-4" id="difference_display">¥0</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <label for="memo" class="form-label">メモ（差額がある場合の理由等）</label>
                                            <textarea class="form-control" id="memo" name="memo" rows="3" 
                                                      placeholder="差額の理由や特記事項があれば記入してください"></textarea>
                                        </div>
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
        <?php endif; ?>

        <!-- 詳細売上データ -->
        <?php if ($range_sales): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-list me-2"></i>支払方法別詳細 
                            <?php if ($start_date === $end_date): ?>
                                (<?= date('Y/m/d', strtotime($start_date)) ?>)
                            <?php else: ?>
                                (<?= date('Y/m/d', strtotime($start_date)) ?>〜<?= date('Y/m/d', strtotime($end_date)) ?>)
                            <?php endif; ?>
                        </h5>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="export_range_report">
                            <input type="hidden" name="report_start" value="<?= htmlspecialchars($start_date) ?>">
                            <input type="hidden" name="report_end" value="<?= htmlspecialchars($end_date) ?>">
                            <input type="hidden" name="report_driver" value="<?= htmlspecialchars($selected_driver) ?>">
                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-download me-1"></i>レポート出力
                            </button>
                        </form>
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
                                    <?php foreach ($range_sales as $sale): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-<?= $sale['payment_method'] === '現金' ? 'money-bill' : ($sale['payment_method'] === 'カード' ? 'credit-card' : 'ellipsis-h') ?> me-2"></i>
                                                <?= htmlspecialchars($sale['payment_method']) ?>
                                            </td>
                                            <td class="text-end"><?= number_format($sale['count']) ?>回</td>
                                            <td class="text-end">¥<?= number_format($sale['total_amount']) ?></td>
                                            <td class="text-end">¥<?= number_format($sale['avg_amount']) ?></td>
                                            <td class="text-end">
                                                <?= round(($sale['total_amount'] / ($range_total['total_amount'] ?: 1)) * 100, 1) ?>%
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    指定期間の乗務記録データが見つかりません。
                    <a href="ride_records.php" class="btn btn-sm btn-outline-primary ms-2">乗車記録を入力</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 日別詳細（複数日の場合） -->
        <?php if ($start_date !== $end_date && $daily_breakdown): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-line me-2"></i>日別詳細</h5>
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
                                    <?php foreach ($daily_breakdown as $day): ?>
                                        <tr>
                                            <td><?= date('m/d(D)', strtotime($day['date'])) ?></td>
                                            <td class="text-end"><?= number_format($day['rides']) ?>回</td>
                                            <td class="text-end">¥<?= number_format($day['total']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['cash']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['card']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['other']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
        // 金種計算
        function calculateTotal() {
            const bill10000 = parseInt(document.querySelector('input[name="bill_10000"]')?.value) || 0;
            const bill5000 = parseInt(document.querySelector('input[name="bill_5000"]')?.value) || 0;
            const bill2000 = parseInt(document.querySelector('input[name="bill_2000"]')?.value) || 0;
            const bill1000 = parseInt(document.querySelector('input[name="bill_1000"]')?.value) || 0;
            const coin500 = parseInt(document.querySelector('input[name="coin_500"]')?.value) || 0;
            const coin100 = parseInt(document.querySelector('input[name="coin_100"]')?.value) || 0;
            const coin50 = parseInt(document.querySelector('input[name="coin_50"]')?.value) || 0;
            const coin10 = parseInt(document.querySelector('input[name="coin_10"]')?.value) || 0;
            const coin5 = parseInt(document.querySelector('input[name="coin_5"]')?.value) || 0;
            const coin1 = parseInt(document.querySelector('input[name="coin_1"]')?.value) || 0;
            const changeFund = parseInt(document.querySelector('input[name="change_fund"]')?.value) || 0;
            
            // 各金種の金額表示更新
            document.getElementById('bill_10000_amount').textContent = '¥' + (bill10000 * 10000).toLocaleString();
            document.getElementById('bill_5000_amount').textContent = '¥' + (bill5000 * 5000).toLocaleString();
            document.getElementById('bill_2000_amount').textContent = '¥' + (bill2000 * 2000).toLocaleString();
            document.getElementById('bill_1000_amount').textContent = '¥' + (bill1000 * 1000).toLocaleString();
            document.getElementById('coin_500_amount').textContent = '¥' + (coin500 * 500).toLocaleString();
            document.getElementById('coin_100_amount').textContent = '¥' + (coin100 * 100).toLocaleString();
            document.getElementById('coin_50_amount').textContent = '¥' + (coin50 * 50).toLocaleString();
            document.getElementById('coin_10_amount').textContent = '¥' + (coin10 * 10).toLocaleString();
            document.getElementById('coin_5_amount').textContent = '¥' + (coin5 * 5).toLocaleString();
            document.getElementById('coin_1_amount').textContent = '¥' + coin1.toLocaleString();
            document.getElementById('change_fund_amount').textContent = '¥' + changeFund.toLocaleString();
            
            // 合計計算
            const cashTotal = bill10000 * 10000 + bill5000 * 5000 + bill2000 * 2000 + bill1000 * 1000 +
                             coin500 * 500 + coin100 * 100 + coin50 * 50 + coin10 * 10 + coin5 * 5 + coin1;
            
            const calculatedAmount = <?= $range_total['cash_amount'] ?? 0 ?>;
            const difference = cashTotal - calculatedAmount;
            
            // 表示更新
            document.getElementById('cash_total_display').textContent = '¥' + cashTotal.toLocaleString();
            document.getElementById('change_total_display').textContent = '¥' + changeFund.toLocaleString();
            
            const diffDisplay = document.getElementById('difference_display');
            if (difference > 0) {
                diffDisplay.textContent = '+¥' + difference.toLocaleString();
                diffDisplay.className = 'fs-4 difference-positive';
            } else if (difference < 0) {
                diffDisplay.textContent = '¥' + difference.toLocaleString();
                diffDisplay.className = 'fs-4 difference-negative';
            } else {
                diffDisplay.textContent = '¥0';
                diffDisplay.className = 'fs-4';
            }
        }
        
        // 確認修正
        function editConfirmation() {
            if (confirm('現金確認記録を修正しますか？')) {
                location.reload();
            }
        }
        
        // フォーム送信確認
        document.getElementById('cashConfirmForm')?.addEventListener('submit', function(e) {
            calculateTotal(); // 最終計算
            
            const cashTotal = parseInt(document.getElementById('cash_total_display').textContent.replace(/[¥,]/g, ''));
            const calculatedAmount = <?= $range_total['cash_amount'] ?? 0 ?>;
            const difference = cashTotal - calculatedAmount;
            
            if (Math.abs(difference) > 0) {
                if (!confirm(`差額が${difference > 0 ? '+' : ''}¥${difference.toLocaleString()}あります。\n記録してよろしいですか？`)) {
                    e.preventDefault();
                }
            }
        });
        
        // 日付範囲の妥当性チェック
        document.getElementById('start_date')?.addEventListener('change', function() {
            const startDate = this.value;
            const endDateInput = document.getElementById('end_date');
            if (endDateInput.value < startDate) {
                endDateInput.value = startDate;
            }
        });
        
        document.getElementById('end_date')?.addEventListener('change', function() {
            const endDate = this.value;
            const startDateInput = document.getElementById('start_date');
            if (startDateInput.value > endDate) {
                startDateInput.value = endDate;
            }
        });
        
        // 初期計算
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('cash_total_display')) {
                calculateTotal();
            }
        });
    </script>
</body>
</html>
