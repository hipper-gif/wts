<?php
session_start();
require_once 'config/database.php';

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 権限確認：Admin権限必須
try {
    $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$user || $user->permission_level !== 'Admin') {
        header('Location: dashboard.php?error=admin_required');
        exit;
    }
} catch (PDOException $e) {
    die("権限確認エラー: " . $e->getMessage());
}

// 必要なテーブルを作成
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
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_change_stocks (
            id INT PRIMARY KEY AUTO_INCREMENT,
            driver_id INT,
            stock_amount INT NOT NULL DEFAULT 0,
            notes TEXT,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // テーブル作成エラーは無視（既存の場合）
}

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'confirm_cash':
                $date = $_POST['date'];
                $confirmed_amount = (int)$_POST['confirmed_amount'];
                $calculated_amount = (int)$_POST['calculated_amount'];
                $difference = $confirmed_amount - $calculated_amount;
                $memo = $_POST['memo'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_confirmations 
                        (confirmation_date, confirmed_amount, calculated_amount, difference, memo, confirmed_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        confirmed_amount = VALUES(confirmed_amount),
                        calculated_amount = VALUES(calculated_amount),
                        difference = VALUES(difference),
                        memo = VALUES(memo),
                        confirmed_by = VALUES(confirmed_by),
                        updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$date, $confirmed_amount, $calculated_amount, $difference, $memo, $_SESSION['user_id']]);
                    $success_message = "現金確認記録を保存しました";
                } catch (PDOException $e) {
                    $error_message = "保存エラー: " . $e->getMessage();
                }
                break;
                
            case 'update_change_stock':
                $driver_id = (int)$_POST['driver_id'];
                $stock_amount = (int)$_POST['stock_amount'];
                $notes = $_POST['notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO driver_change_stocks (driver_id, stock_amount, notes, updated_by)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        stock_amount = VALUES(stock_amount),
                        notes = VALUES(notes),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$driver_id, $stock_amount, $notes, $_SESSION['user_id']]);
                    $success_message = "おつり在庫を更新しました";
                } catch (PDOException $e) {
                    $error_message = "更新エラー: " . $e->getMessage();
                }
                break;
        }
    }
}

// パラメータ取得
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = $_GET['month'] ?? date('Y-m');

// 関数定義
function getDailySales($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                payment_method,
                COUNT(*) as trip_count,
                SUM(fare) as total_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ?
            GROUP BY payment_method
            ORDER BY payment_method
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getDailyTotal($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_trips,
                SUM(fare) as total_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ?
        ");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['total_trips' => 0, 'total_amount' => 0];
    }
}

function getMonthlySummary($pdo, $month) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(ride_date) as date,
                COUNT(*) as trip_count,
                SUM(fare) as total_amount,
                SUM(CASE WHEN payment_method = '現金' THEN fare ELSE 0 END) as cash_amount,
                SUM(CASE WHEN payment_method = 'カード' THEN fare ELSE 0 END) as card_amount,
                SUM(CASE WHEN payment_method NOT IN ('現金', 'カード') THEN fare ELSE 0 END) as other_amount
            FROM ride_records 
            WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
            GROUP BY DATE(ride_date)
            ORDER BY DATE(ride_date) DESC
        ");
        $stmt->execute([$month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getMonthlySalesDetails($pdo, $month) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.ride_date,
                r.ride_time,
                u.name as driver_name,
                r.pickup_location,
                r.dropoff_location,
                r.passenger_count,
                r.fare,
                r.payment_method,
                r.transportation_type,
                r.memo
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE_FORMAT(r.ride_date, '%Y-%m') = ?
            ORDER BY r.ride_date DESC, r.ride_time DESC
        ");
        $stmt->execute([$month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getCashConfirmation($pdo, $date) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM cash_confirmations WHERE confirmation_date = ?");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

function getDriverChangeStocks($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                dcs.*,
                u.name as driver_name
            FROM driver_change_stocks dcs
            LEFT JOIN users u ON dcs.driver_id = u.id
            WHERE u.is_active = TRUE AND u.is_driver = TRUE
            ORDER BY u.name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getDailyDrivers($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                u.id,
                u.name
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE(r.ride_date) = ? AND u.is_active = TRUE
            ORDER BY u.name
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// データ取得
$daily_sales = getDailySales($pdo, $selected_date);
$daily_total = getDailyTotal($pdo, $selected_date);
$monthly_summary = getMonthlySummary($pdo, $selected_month);
$monthly_details = getMonthlySalesDetails($pdo, $selected_month);
$cash_confirmation = getCashConfirmation($pdo, $selected_date);
$driver_change_stocks = getDriverChangeStocks($pdo);
$daily_drivers = getDailyDrivers($pdo, $selected_date);

// 計算
$cash_sales = 0;
$card_sales = 0;
$other_sales = 0;

foreach ($daily_sales as $sale) {
    switch ($sale['payment_method']) {
        case '現金':
            $cash_sales = $sale['total_amount'];
            break;
        case 'カード':
            $card_sales = $sale['total_amount'];
            break;
        default:
            $other_sales += $sale['total_amount'];
            break;
    }
}

// おつり在庫考慮の計算現金
$total_change_stock = array_sum(array_column($driver_change_stocks, 'stock_amount'));
$calculated_cash = $cash_sales - $total_change_stock;

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
        .cash-card { border-left: 4px solid #e91e63; }
        .card-card { border-left: 4px solid #2196f3; }
        .other-card { border-left: 4px solid #ff9800; }
        .total-card { border-left: 4px solid #4caf50; }
        .amount-display { font-size: 1.8rem; font-weight: bold; }
        .change-stock-card { border-left: 4px solid #9c27b0; }
        .accounting-memo { background-color: #f8f9fa; border-left: 4px solid #17a2b8; }
        @media print {
            .no-print { display: none !important; }
            .container-fluid { max-width: none !important; }
        }
    </style>
</head>
<body class="bg-light">

<!-- ナビゲーション -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary no-print">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-cash-register me-2"></i>集金管理
        </a>
        <div class="navbar-nav ms-auto">
            <span class="navbar-text me-3">
                <i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name'] ?? 'ユーザー') ?>
            </span>
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt me-1"></i>ダッシュボード
            </a>
            <a class="nav-link" href="logout.php">
                <i class="fas fa-sign-out-alt me-1"></i>ログアウト
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">
    <!-- アラート -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 日付選択 -->
    <div class="row mb-4 no-print">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calendar-day me-2"></i>日次確認
                </div>
                <div class="card-body">
                    <form method="GET" class="d-flex gap-2">
                        <input type="date" name="date" value="<?= $selected_date ?>" class="form-control">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calendar-alt me-2"></i>月次確認
                </div>
                <div class="card-body">
                    <form method="GET" class="d-flex gap-2">
                        <input type="month" name="month" value="<?= $selected_month ?>" class="form-control">
                        <input type="hidden" name="date" value="<?= $selected_date ?>">
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
            <div class="card cash-card">
                <div class="card-body text-center">
                    <i class="fas fa-money-bill-wave fa-2x text-danger mb-2"></i>
                    <h6 class="card-title">現金売上</h6>
                    <div class="amount-display text-danger">¥<?= number_format($cash_sales) ?></div>
                    <small class="text-muted"><?= count(array_filter($daily_sales, fn($s) => $s['payment_method'] === '現金')) > 0 ? array_filter($daily_sales, fn($s) => $s['payment_method'] === '現金')[0]['trip_count'] ?? 0 : 0 ?>回</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-card">
                <div class="card-body text-center">
                    <i class="fas fa-credit-card fa-2x text-primary mb-2"></i>
                    <h6 class="card-title">カード売上</h6>
                    <div class="amount-display text-primary">¥<?= number_format($card_sales) ?></div>
                    <small class="text-muted"><?= count(array_filter($daily_sales, fn($s) => $s['payment_method'] === 'カード')) > 0 ? array_filter($daily_sales, fn($s) => $s['payment_method'] === 'カード')[0]['trip_count'] ?? 0 : 0 ?>回</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card other-card">
                <div class="card-body text-center">
                    <i class="fas fa-coins fa-2x text-warning mb-2"></i>
                    <h6 class="card-title">その他</h6>
                    <div class="amount-display text-warning">¥<?= number_format($other_sales) ?></div>
                    <small class="text-muted">-</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card total-card">
                <div class="card-body text-center">
                    <i class="fas fa-chart-line fa-2x text-success mb-2"></i>
                    <h6 class="card-title">合計売上</h6>
                    <div class="amount-display text-success">¥<?= number_format($daily_total['total_amount']) ?></div>
                    <small class="text-muted"><?= $daily_total['total_trips'] ?>回</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 運転者別おつり在庫 -->
    <?php if (!empty($driver_change_stocks)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card change-stock-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-wallet me-2"></i>運転者別おつり在庫
                    </h5>
                    <span class="badge bg-purple text-white">合計: ¥<?= number_format($total_change_stock) ?></span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($driver_change_stocks as $stock): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title"><?= htmlspecialchars($stock['driver_name']) ?></h6>
                                    <div class="h5 text-purple">¥<?= number_format($stock['stock_amount']) ?></div>
                                    <?php if ($stock['notes']): ?>
                                        <small class="text-muted"><?= htmlspecialchars($stock['notes']) ?></small>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-primary mt-2 no-print" 
                                            onclick="showChangeStockModal(<?= $stock['driver_id'] ?>, '<?= htmlspecialchars($stock['driver_name']) ?>', <?= $stock['stock_amount'] ?>, '<?= htmlspecialchars($stock['notes'] ?? '') ?>')">
                                        <i class="fas fa-edit"></i> 調整
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 現金確認セクション -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calculator me-2"></i><?= date('Y/m/d', strtotime($selected_date)) ?> 現金確認
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="confirm_cash">
                        <input type="hidden" name="date" value="<?= $selected_date ?>">
                        <input type="hidden" name="calculated_amount" value="<?= $calculated_cash ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">売上現金（自動計算）</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="text" class="form-control" value="<?= number_format($cash_sales) ?>" readonly>
                                    </div>
                                    <small class="text-muted">本日の現金売上</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">おつり在庫（自動計算）</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="text" class="form-control" value="<?= number_format($total_change_stock) ?>" readonly>
                                    </div>
                                    <small class="text-muted">運転者が保持するおつり在庫</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">実回収可能額（自動計算）</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="text" id="calculated_amount" class="form-control bg-success text-white fw-bold" 
                                               value="<?= number_format($calculated_cash) ?>" readonly>
                                    </div>
                                    <small class="text-success">現金売上 - おつり在庫</small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">実際の回収金額 <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="number" name="confirmed_amount" id="confirmed_amount" 
                                               class="form-control" required
                                               value="<?= $cash_confirmation['confirmed_amount'] ?? $calculated_cash ?>"
                                               onchange="calculateDifference()">
                                    </div>
                                    <small class="text-muted">実際に回収した現金額</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">差額</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="text" id="difference_display" class="form-control" readonly>
                                    </div>
                                    <small id="difference_note" class="text-muted">実際金額 - 計算金額</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">メモ</label>
                                    <textarea name="memo" class="form-control" rows="3" 
                                              placeholder="差額の理由等"><?= htmlspecialchars($cash_confirmation['memo'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-success no-print">
                            <i class="fas fa-save me-2"></i>現金確認記録を保存
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- 経理入力支援 -->
        <div class="col-md-4">
            <div class="card accounting-memo">
                <div class="card-header">
                    <i class="fas fa-file-invoice me-2"></i>経理入力用仕訳メモ
                </div>
                <div class="card-body">
                    <h6>売上計上</h6>
                    <pre class="small">現金　　　¥<?= number_format($cash_sales) ?>
カード　　¥<?= number_format($card_sales) ?>
／売上　　¥<?= number_format($daily_total['total_amount']) ?></pre>
                    
                    <h6 class="mt-3">現金管理</h6>
                    <pre class="small">現金　　　¥<?= number_format($calculated_cash) ?>
／売掛金　¥<?= number_format($calculated_cash) ?></pre>
                    
                    <?php if ($cash_confirmation && $cash_confirmation['difference'] != 0): ?>
                    <h6 class="mt-3">現金過不足</h6>
                    <pre class="small"><?= $cash_confirmation['difference'] > 0 ? '現金' : '雑損失' ?>　¥<?= number_format(abs($cash_confirmation['difference'])) ?>
／<?= $cash_confirmation['difference'] > 0 ? '雑益' : '現金' ?>　¥<?= number_format(abs($cash_confirmation['difference'])) ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 支払方法別詳細 -->
    <?php if (!empty($daily_sales)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list me-2"></i><?= date('Y/m/d', strtotime($selected_date)) ?> 支払方法別詳細
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>支払方法</th>
                                    <th class="text-end">回数</th>
                                    <th class="text-end">金額</th>
                                    <th class="text-end">構成比</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily_sales as $sale): ?>
                                <tr>
                                    <td>
                                        <?php if ($sale['payment_method'] === '現金'): ?>
                                            <i class="fas fa-money-bill-wave text-danger me-2"></i>
                                        <?php elseif ($sale['payment_method'] === 'カード'): ?>
                                            <i class="fas fa-credit-card text-primary me-2"></i>
                                        <?php else: ?>
                                            <i class="fas fa-coins text-warning me-2"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($sale['payment_method']) ?>
                                    </td>
                                    <td class="text-end"><?= $sale['trip_count'] ?>回</td>
                                    <td class="text-end">¥<?= number_format($sale['total_amount']) ?></td>
                                    <td class="text-end">
                                        <?= $daily_total['total_amount'] > 0 ? number_format(($sale['total_amount'] / $daily_total['total_amount']) * 100, 1) : 0 ?>%
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-primary">
                                <tr>
                                    <th>合計</th>
                                    <th class="text-end"><?= $daily_total['total_trips'] ?>回</th>
                                    <th class="text-end">¥<?= number_format($daily_total['total_amount']) ?></th>
                                    <th class="text-end">100.0%</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 当月売上詳細リスト -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i><?= date('Y年m月', strtotime($selected_month)) ?> 売上詳細
                    </h5>
                    <button class="btn btn-outline-primary no-print" onclick="toggleDetails()">
                        <i class="fas fa-eye me-2"></i><span id="toggle-text">詳細表示</span>
                    </button>
                </div>
                <div class="card-body" id="details-section" style="display: none;">
                    <?php if (!empty($monthly_details)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>日時</th>
                                    <th>運転者</th>
                                    <th>乗車地</th>
                                    <th>降車地</th>
                                    <th class="text-center">人数</th>
                                    <th class="text-end">料金</th>
                                    <th>支払</th>
                                    <th>輸送種別</th>
                                    <th>メモ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_details as $detail): ?>
                                <tr>
                                    <td>
                                        <?= date('m/d', strtotime($detail['ride_date'])) ?>
                                        <small class="text-muted d-block"><?= date('H:i', strtotime($detail['ride_time'])) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($detail['driver_name']) ?></td>
                                    <td><?= htmlspecialchars($detail['pickup_location']) ?></td>
                                    <td><?= htmlspecialchars($detail['dropoff_location']) ?></td>
                                    <td class="text-center"><?= $detail['passenger_count'] ?></td>
                                    <td class="text-end">¥<?= number_format($detail['fare']) ?></td>
                                    <td>
                                        <?php if ($detail['payment_method'] === '現金'): ?>
                                            <span class="badge bg-danger"><?= htmlspecialchars($detail['payment_method']) ?></span>
                                        <?php elseif ($detail['payment_method'] === 'カード'): ?>
                                            <span class="badge bg-primary"><?= htmlspecialchars($detail['payment_method']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-warning"><?= htmlspecialchars($detail['payment_method']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($detail['transportation_type']) ?></td>
                                    <td>
                                        <?php if ($detail['memo']): ?>
                                            <i class="fas fa-comment text-info" title="<?= htmlspecialchars($detail['memo']) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <p>該当月のデータがありません</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 月次サマリー -->
    <?php if (!empty($monthly_summary)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar me-2"></i><?= date('Y年m月', strtotime($selected_month)) ?> 日別サマリー
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>日付</th>
                                    <th class="text-center">曜日</th>
                                    <th class="text-end">回数</th>
                                    <th class="text-end">現金</th>
                                    <th class="text-end">カード</th>
                                    <th class="text-end">その他</th>
                                    <th class="text-end">合計</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $monthly_total = [
                                    'trip_count' => 0,
                                    'cash_amount' => 0,
                                    'card_amount' => 0,
                                    'other_amount' => 0,
                                    'total_amount' => 0
                                ];
                                ?>
                                <?php foreach ($monthly_summary as $summary): ?>
                                <?php
                                $date = new DateTime($summary['date']);
                                $day_of_week = ['日', '月', '火', '水', '木', '金', '土'][$date->format('w')];
                                $is_weekend = in_array($date->format('w'), [0, 6]);
                                
                                $monthly_total['trip_count'] += $summary['trip_count'];
                                $monthly_total['cash_amount'] += $summary['cash_amount'];
                                $monthly_total['card_amount'] += $summary['card_amount'];
                                $monthly_total['other_amount'] += $summary['other_amount'];
                                $monthly_total['total_amount'] += $summary['total_amount'];
                                ?>
                                <tr class="<?= $is_weekend ? 'table-light' : '' ?>">
                                    <td><?= $date->format('m/d') ?></td>
                                    <td class="text-center <?= $is_weekend ? 'text-danger' : '' ?>"><?= $day_of_week ?></td>
                                    <td class="text-end"><?= $summary['trip_count'] ?></td>
                                    <td class="text-end">¥<?= number_format($summary['cash_amount']) ?></td>
                                    <td class="text-end">¥<?= number_format($summary['card_amount']) ?></td>
                                    <td class="text-end">¥<?= number_format($summary['other_amount']) ?></td>
                                    <td class="text-end fw-bold">¥<?= number_format($summary['total_amount']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-primary">
                                <tr>
                                    <th colspan="2">月計</th>
                                    <th class="text-end"><?= $monthly_total['trip_count'] ?></th>
                                    <th class="text-end">¥<?= number_format($monthly_total['cash_amount']) ?></th>
                                    <th class="text-end">¥<?= number_format($monthly_total['card_amount']) ?></th>
                                    <th class="text-end">¥<?= number_format($monthly_total['other_amount']) ?></th>
                                    <th class="text-end">¥<?= number_format($monthly_total['total_amount']) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="2">1日平均</th>
                                    <th class="text-end"><?= count($monthly_summary) > 0 ? number_format($monthly_total['trip_count'] / count($monthly_summary), 1) : 0 ?></th>
                                    <th class="text-end">¥<?= count($monthly_summary) > 0 ? number_format($monthly_total['cash_amount'] / count($monthly_summary)) : 0 ?></th>
                                    <th class="text-end">¥<?= count($monthly_summary) > 0 ? number_format($monthly_total['card_amount'] / count($monthly_summary)) : 0 ?></th>
                                    <th class="text-end">¥<?= count($monthly_summary) > 0 ? number_format($monthly_total['other_amount'] / count($monthly_summary)) : 0 ?></th>
                                    <th class="text-end">¥<?= count($monthly_summary) > 0 ? number_format($monthly_total['total_amount'] / count($monthly_summary)) : 0 ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- おつり在庫調整モーダル -->
<div class="modal fade" id="changeStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-wallet me-2"></i>おつり在庫調整
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_change_stock">
                    <input type="hidden" name="driver_id" id="modal_driver_id">
                    
                    <div class="mb-3">
                        <label class="form-label">運転者</label>
                        <input type="text" id="modal_driver_name" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">在庫金額 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">¥</span>
                            <input type="number" name="stock_amount" id="modal_stock_amount" 
                                   class="form-control" required min="0" step="100">
                        </div>
                        <small class="text-muted">100円単位で入力してください</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">メモ</label>
                        <textarea name="notes" id="modal_notes" class="form-control" rows="3" 
                                  placeholder="調整理由など"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 差額計算
function calculateDifference() {
    const confirmed = parseInt(document.getElementById('confirmed_amount').value) || 0;
    const calculated = <?= $calculated_cash ?>;
    const difference = confirmed - calculated;
    
    const differenceDisplay = document.getElementById('difference_display');
    const differenceNote = document.getElementById('difference_note');
    
    differenceDisplay.value = (difference >= 0 ? '+' : '') + difference.toLocaleString();
    
    if (difference > 0) {
        differenceDisplay.className = 'form-control text-primary fw-bold';
        differenceNote.textContent = '現金過剰（お釣り不足等の可能性）';
        differenceNote.className = 'text-primary';
    } else if (difference < 0) {
        differenceDisplay.className = 'form-control text-danger fw-bold';
        differenceNote.textContent = '現金不足（売上金の未回収等の可能性）';
        differenceNote.className = 'text-danger';
    } else {
        differenceDisplay.className = 'form-control text-success fw-bold';
        differenceNote.textContent = '計算通り（問題なし）';
        differenceNote.className = 'text-success';
    }
}

// 詳細表示切り替え
function toggleDetails() {
    const section = document.getElementById('details-section');
    const toggleText = document.getElementById('toggle-text');
    
    if (section.style.display === 'none') {
        section.style.display = 'block';
        toggleText.textContent = '詳細非表示';
    } else {
        section.style.display = 'none';
        toggleText.textContent = '詳細表示';
    }
}

// おつり在庫調整モーダル
function showChangeStockModal(driverId, driverName, stockAmount, notes) {
    document.getElementById('modal_driver_id').value = driverId;
    document.getElementById('modal_driver_name').value = driverName;
    document.getElementById('modal_stock_amount').value = stockAmount;
    document.getElementById('modal_notes').value = notes;
    
    new bootstrap.Modal(document.getElementById('changeStockModal')).show();
}

// ページ読み込み時の初期化
document.addEventListener('DOMContentLoaded', function() {
    calculateDifference();
    
    // ツールチップ初期化
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// 印刷機能
function printPage() {
    window.print();
}

// 確認金額入力時のエンターキー対応
document.getElementById('confirmed_amount').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        calculateDifference();
    }
});
</script>

</body>
</html>
