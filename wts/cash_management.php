<?php
session_start();
require_once 'config/database.php';

// 権限チェック - permission_levelがAdminのみアクセス可能
function checkAdminPermission($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$user || $user->permission_level !== 'Admin') {
            header('Location: dashboard.php?error=admin_required');
            exit;
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?error=db_error');
        exit;
    }
}

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 権限チェック実行
checkAdminPermission($pdo, $_SESSION['user_id']);

// 現在のユーザー情報取得
$stmt = $pdo->prepare("SELECT name, permission_level FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_OBJ);

// 日付設定
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = $_GET['month'] ?? date('Y-m');

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['confirm_cash'])) {
            // 現金確認記録
            $stmt = $pdo->prepare("
                INSERT INTO cash_confirmations (date, cash_amount, calculated_amount, difference, notes, confirmed_by, confirmed_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                cash_amount = VALUES(cash_amount),
                calculated_amount = VALUES(calculated_amount),
                difference = VALUES(difference),
                notes = VALUES(notes),
                confirmed_by = VALUES(confirmed_by),
                confirmed_at = NOW()
            ");
            $stmt->execute([
                $_POST['date'],
                $_POST['cash_amount'],
                $_POST['calculated_amount'],
                $_POST['difference'],
                $_POST['notes'],
                $_SESSION['user_id']
            ]);
            $message = '現金確認を記録しました。';
        }
        
        if (isset($_POST['update_change_stock'])) {
            // おつり在庫調整
            $stmt = $pdo->prepare("
                INSERT INTO driver_change_stocks (driver_id, stock_amount, notes, updated_by, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                stock_amount = VALUES(stock_amount),
                notes = VALUES(notes),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
            ");
            $stmt->execute([
                $_POST['driver_id'],
                $_POST['stock_amount'],
                $_POST['notes'],
                $_SESSION['user_id']
            ]);
            $message = 'おつり在庫を更新しました。';
        }
    } catch (PDOException $e) {
        $error = 'データベースエラーが発生しました: ' . $e->getMessage();
    }
}

// 各種データ取得関数
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
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getDailyTotal($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_trips,
                SUM(fare) as total_amount,
                SUM(CASE WHEN payment_method = '現金' THEN fare ELSE 0 END) as cash_amount,
                SUM(CASE WHEN payment_method = 'カード' THEN fare ELSE 0 END) as card_amount,
                SUM(CASE WHEN payment_method NOT IN ('現金', 'カード') THEN fare ELSE 0 END) as other_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ?
        ");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return (object)['total_trips' => 0, 'total_amount' => 0, 'cash_amount' => 0, 'card_amount' => 0, 'other_amount' => 0];
    }
}

function getMonthlySummary($pdo, $month) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(ride_date) as date,
                COUNT(*) as trips,
                SUM(fare) as total,
                SUM(CASE WHEN payment_method = '現金' THEN fare ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method = 'カード' THEN fare ELSE 0 END) as card,
                SUM(CASE WHEN payment_method NOT IN ('現金', 'カード') THEN fare ELSE 0 END) as other
            FROM ride_records 
            WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
            GROUP BY DATE(ride_date)
            ORDER BY DATE(ride_date) DESC
        ");
        $stmt->execute([$month]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
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
                r.transportation_type
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE_FORMAT(r.ride_date, '%Y-%m') = ?
            ORDER BY r.ride_date DESC, r.ride_time DESC
        ");
        $stmt->execute([$month]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getCashConfirmation($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, u.name as confirmed_by_name
            FROM cash_confirmations c
            LEFT JOIN users u ON c.confirmed_by = u.id
            WHERE c.date = ?
        ");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return null;
    }
}

function getDriverChangeStocks($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                dcs.*,
                u.name as driver_name,
                uu.name as updated_by_name
            FROM driver_change_stocks dcs
            LEFT JOIN users u ON dcs.driver_id = u.id
            LEFT JOIN users uu ON dcs.updated_by = uu.id
            WHERE u.is_active = TRUE AND u.is_driver = TRUE
            ORDER BY u.name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getDailyDrivers($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.name
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE(r.ride_date) = ? AND u.is_active = TRUE
            ORDER BY u.name
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
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
$driver_stocks = getDriverChangeStocks($pdo);
$daily_drivers = getDailyDrivers($pdo, $selected_date);

// 現金計算（おつり在庫考慮）
$total_change_stock = array_sum(array_column($driver_stocks, 'stock_amount'));
$calculated_cash_in_office = $daily_total->cash_amount - $total_change_stock;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集金管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .cash-card { background: linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%); }
        .card-card { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
        .other-card { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); }
        .total-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .amount-large { font-size: 1.8rem; font-weight: bold; }
        .amount-medium { font-size: 1.3rem; font-weight: 600; }
        .stock-positive { color: #28a745; }
        .stock-warning { color: #ffc107; }
        .stock-danger { color: #dc3545; }
        .accounting-memo { background: #f8f9fa; border-left: 4px solid #007bff; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
        }
    </style>
</head>
<body class="bg-light">
    <!-- ナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-cash-register"></i> 集金管理
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($current_user->name) ?>
                </span>
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> ダッシュボード
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 日付選択 -->
        <div class="row mb-4 no-print">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-calendar-day"></i> 日次管理</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="d-flex align-items-end gap-2">
                            <div class="flex-grow-1">
                                <label class="form-label">対象日</label>
                                <input type="date" name="date" value="<?= $selected_date ?>" 
                                       class="form-control" onchange="this.form.submit()">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> 表示
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-calendar-alt"></i> 月次管理</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="d-flex align-items-end gap-2">
                            <div class="flex-grow-1">
                                <label class="form-label">対象月</label>
                                <input type="month" name="month" value="<?= $selected_month ?>" 
                                       class="form-control" onchange="this.form.submit()">
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-chart-bar"></i> 月次表示
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 日次売上サマリー -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card total-card">
                    <div class="card-body text-center">
                        <h6 class="card-title"><i class="fas fa-chart-line"></i> 総売上</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->total_amount) ?></div>
                        <small><?= $daily_total->total_trips ?>件</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card cash-card">
                    <div class="card-body text-center text-dark">
                        <h6 class="card-title"><i class="fas fa-money-bill-wave"></i> 現金売上</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->cash_amount) ?></div>
                        <small><?= count(array_filter($daily_sales, fn($s) => $s->payment_method === '現金')) ? array_filter($daily_sales, fn($s) => $s->payment_method === '現金')[0]->trip_count ?? 0 : 0 ?>件</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-card">
                    <div class="card-body text-center text-dark">
                        <h6 class="card-title"><i class="fas fa-credit-card"></i> カード売上</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->card_amount) ?></div>
                        <small><?= count(array_filter($daily_sales, fn($s) => $s->payment_method === 'カード')) ? array_filter($daily_sales, fn($s) => $s->payment_method === 'カード')[0]->trip_count ?? 0 : 0 ?>件</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card other-card">
                    <div class="card-body text-center text-dark">
                        <h6 class="card-title"><i class="fas fa-receipt"></i> その他</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->other_amount) ?></div>
                        <small><?= count(array_filter($daily_sales, fn($s) => !in_array($s->payment_method, ['現金', 'カード']))) ?>種類</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- 左側: 現金管理 -->
            <div class="col-lg-6">
                <!-- 運転者別おつり在庫 -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-coins"></i> 運転者別おつり在庫</h5>
                        <button class="btn btn-sm btn-outline-primary no-print" data-bs-toggle="modal" data-bs-target="#updateStockModal">
                            <i class="fas fa-edit"></i> 在庫調整
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>運転者</th>
                                        <th class="text-end">在庫金額</th>
                                        <th>最終更新</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($driver_stocks as $stock): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($stock->driver_name ?? '不明') ?></td>
                                        <td class="text-end">
                                            <span class="<?= $stock->stock_amount >= 10000 ? 'stock-positive' : ($stock->stock_amount >= 5000 ? 'stock-warning' : 'stock-danger') ?>">
                                                ¥<?= number_format($stock->stock_amount) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small><?= $stock->updated_at ? date('m/d H:i', strtotime($stock->updated_at)) : '未設定' ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-info">
                                        <th>合計在庫</th>
                                        <th class="text-end">¥<?= number_format($total_change_stock) ?></th>
                                        <th></th>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 現金確認 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-calculator"></i> 現金確認</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="cashConfirmForm">
                            <input type="hidden" name="date" value="<?= $selected_date ?>">
                            <input type="hidden" name="calculated_amount" value="<?= $calculated_cash_in_office ?>">
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">計算上の事務所現金</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="text" class="form-control" value="<?= number_format($calculated_cash_in_office) ?>" readonly>
                                    </div>
                                    <small class="text-muted">現金売上 - おつり在庫</small>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">実際の事務所現金</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="number" name="cash_amount" class="form-control" 
                                               value="<?= $cash_confirmation->cash_amount ?? '' ?>"
                                               placeholder="実際の金額を入力" 
                                               onchange="calculateDifference()">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">差額</label>
                                    <input type="number" name="difference" class="form-control" 
                                           id="difference" readonly
                                           value="<?= $cash_confirmation->difference ?? '' ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">備考</label>
                                    <input type="text" name="notes" class="form-control" 
                                           value="<?= htmlspecialchars($cash_confirmation->notes ?? '') ?>"
                                           placeholder="差額がある場合の理由など">
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="confirm_cash" class="btn btn-success">
                                    <i class="fas fa-check"></i> 現金確認を記録
                                </button>
                            </div>
                            
                            <?php if ($cash_confirmation): ?>
                            <div class="mt-2 text-center">
                                <small class="text-muted">
                                    最終確認: <?= date('Y/m/d H:i', strtotime($cash_confirmation->confirmed_at)) ?> 
                                    (<?= htmlspecialchars($cash_confirmation->confirmed_by_name) ?>)
                                </small>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- 経理入力用仕訳メモ -->
                <div class="card accounting-memo">
                    <div class="card-header">
                        <h6><i class="fas fa-file-invoice"></i> 経理ソフト入力用仕訳メモ</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12">
                                <strong>売上計上仕訳（<?= $selected_date ?>）</strong>
                                <pre class="mt-2 mb-0" style="font-size: 0.9rem;">現金     <?= str_pad(number_format($daily_total->cash_amount), 10, ' ', STR_PAD_LEFT) ?> / 売上     <?= str_pad(number_format($daily_total->total_amount), 10, ' ', STR_PAD_LEFT) ?>
普通預金 <?= str_pad(number_format($daily_total->card_amount), 10, ' ', STR_PAD_LEFT) ?> /
<?php if ($daily_total->other_amount > 0): ?>
売掛金   <?= str_pad(number_format($daily_total->other_amount), 10, ' ', STR_PAD_LEFT) ?> /
<?php endif; ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 右側: 売上詳細 -->
            <div class="col-lg-6">
                <!-- 支払方法別詳細 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-list"></i> 支払方法別詳細</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>支払方法</th>
                                        <th class="text-end">件数</th>
                                        <th class="text-end">金額</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daily_sales as $sale): ?>
                                    <tr>
                                        <td>
                                            <?php if ($sale->payment_method === '現金'): ?>
                                                <i class="fas fa-money-bill-wave text-success"></i>
                                            <?php elseif ($sale->payment_method === 'カード'): ?>
                                                <i class="fas fa-credit-card text-primary"></i>
                                            <?php else: ?>
                                                <i class="fas fa-receipt text-warning"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($sale->payment_method) ?>
                                        </td>
                                        <td class="text-end"><?= $sale->trip_count ?>件</td>
                                        <td class="text-end amount-medium">¥<?= number_format($sale->total_amount) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 当月売上詳細リスト -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-table"></i> 当月売上詳細</h5>
                        <button class="btn btn-sm btn-outline-secondary no-print" onclick="toggleDetails()">
                            <i class="fas fa-eye" id="toggleIcon"></i> 
                            <span id="toggleText">詳細表示</span>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div id="monthlyDetails" style="display: none; max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>日時</th>
                                        <th>運転者</th>
                                        <th>乗降地</th>
                                        <th class="text-end">料金</th>
                                        <th>支払</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_details as $detail): ?>
                                    <tr>
                                        <td>
                                            <small>
                                                <?= date('m/d', strtotime($detail->ride_date)) ?><br>
                                                <?= date('H:i', strtotime($detail->ride_time)) ?>
                                            </small>
                                        </td>
                                        <td><?= htmlspecialchars($detail->driver_name ?? '不明') ?></td>
                                        <td>
                                            <small>
                                                <?= htmlspecialchars(mb_strimwidth($detail->pickup_location ?? '', 0, 15, '...')) ?><br>
                                                <i class="fas fa-arrow-down text-muted"></i><br>
                                                <?= htmlspecialchars(mb_strimwidth($detail->dropoff_location ?? '', 0, 15, '...')) ?>
                                            </small>
                                        </td>
                                        <td class="text-end">¥<?= number_format($detail->fare) ?></td>
                                        <td>
                                            <small>
                                                <?php if ($detail->payment_method === '現金'): ?>
                                                    <span class="badge bg-success">現金</span>
                                                <?php elseif ($detail->payment_method === 'カード'): ?>
                                                    <span class="badge bg-primary">カード</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning"><?= htmlspecialchars($detail->payment_method) ?></span>
                                                <?php endif; ?>
                                            </small>
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

        <!-- 月次サマリー -->
        <?php if (!empty($monthly_summary)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-bar"></i> 月次サマリー（<?= $selected_month ?>）</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>日付</th>
                                        <th class="text-end">件数</th>
                                        <th class="text-end">現金</th>
                                        <th class="text-end">カード</th>
                                        <th class="text-end">その他</th>
                                        <th class="text-end">合計</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $month_total_trips = 0;
                                    $month_total_cash = 0;
                                    $month_total_card = 0;
                                    $month_total_other = 0;
                                    $month_total_amount = 0;
                                    
                                    foreach ($monthly_summary as $day): 
                                        $month_total_trips += $day->trips;
                                        $month_total_cash += $day->cash;
                                        $month_total_card += $day->card;
                                        $month_total_other += $day->other;
                                        $month_total_amount += $day->total;
                                    ?>
                                    <tr>
                                        <td><?= date('m/d(D)', strtotime($day->date)) ?></td>
                                        <td class="text-end"><?= $day->trips ?></td>
                                        <td class="text-end">¥<?= number_format($day->cash) ?></td>
                                        <td class="text-end">¥<?= number_format($day->card) ?></td>
                                        <td class="text-end">¥<?= number_format($day->other) ?></td>
                                        <td class="text-end"><strong>¥<?= number_format($day->total) ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-warning">
                                        <th>月合計</th>
                                        <th class="text-end"><?= $month_total_trips ?>件</th>
                                        <th class="text-end">¥<?= number_format($month_total_cash) ?></th>
                                        <th class="text-end">¥<?= number_format($month_total_card) ?></th>
                                        <th class="text-end">¥<?= number_format($month_total_other) ?></th>
                                        <th class="text-end"><strong>¥<?= number_format($month_total_amount) ?></strong></th>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- おつり在庫調整モーダル -->
    <div class="modal fade" id="updateStockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">おつり在庫調整</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">運転者</label>
                            <select name="driver_id" class="form-select" required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($daily_drivers as $driver): ?>
                                <option value="<?= $driver->id ?>"><?= htmlspecialchars($driver->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">在庫金額</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="number" name="stock_amount" class="form-control" 
                                       min="0" max="50000" required>
                            </div>
                            <div class="form-text">推奨在庫: 10,000円〜20,000円</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">備考</label>
                            <textarea name="notes" class="form-control" rows="2" 
                                      placeholder="在庫調整の理由など"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" name="update_change_stock" class="btn btn-primary">
                            <i class="fas fa-save"></i> 更新
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 現金差額の自動計算
        function calculateDifference() {
            const actualAmount = parseInt(document.querySelector('input[name="cash_amount"]').value) || 0;
            const calculatedAmount = <?= $calculated_cash_in_office ?>;
            const difference = actualAmount - calculatedAmount;
            document.getElementById('difference').value = difference;
            
            // 差額の色分け
            const diffField = document.getElementById('difference');
            diffField.className = 'form-control';
            if (difference > 0) {
                diffField.classList.add('bg-success', 'text-white');
            } else if (difference < 0) {
                diffField.classList.add('bg-danger', 'text-white');
            }
        }

        // 詳細リストの表示/非表示切り替え
        function toggleDetails() {
            const details = document.getElementById('monthlyDetails');
            const icon = document.getElementById('toggleIcon');
            const text = document.getElementById('toggleText');
            
            if (details.style.display === 'none') {
                details.style.display = 'block';
                icon.className = 'fas fa-eye-slash';
                text.textContent = '詳細非表示';
            } else {
                details.style.display = 'none';
                icon.className = 'fas fa-eye';
                text.textContent = '詳細表示';
            }
        }

        // ページ読み込み時の初期計算
        document.addEventListener('DOMContentLoaded', function() {
            calculateDifference();
            
            // 今日の日付にフォーカス
            const today = '<?= date('Y-m-d') ?>';
            const selectedDate = '<?= $selected_date ?>';
            if (today === selectedDate) {
                document.querySelector('input[name="cash_amount"]')?.focus();
            }
        });

        // 印刷機能
        function printPage() {
            window.print();
        }

        // 金額入力時のフォーマット
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseInt(this.value);
                }
            });
        });

        // おつり在庫の色分け更新
        function updateStockColors() {
            document.querySelectorAll('[data-stock-amount]').forEach(element => {
                const amount = parseInt(element.getAttribute('data-stock-amount'));
                element.className = '';
                if (amount >= 10000) {
                    element.classList.add('stock-positive');
                } else if (amount >= 5000) {
                    element.classList.add('stock-warning');
                } else {
                    element.classList.add('stock-danger');
                }
            });
        }

        // 月次データの自動更新
        function autoRefresh() {
            // 5分毎にページを自動更新（営業時間内のみ）
            const now = new Date();
            const hour = now.getHours();
            if (hour >= 8 && hour <= 19) {
                setTimeout(() => {
                    location.reload();
                }, 300000); // 5分
            }
        }

        // 自動更新開始
        autoRefresh();

        // Enterキーでの送信防止（誤操作防止）
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && e.target.type !== 'submit') {
                e.preventDefault();
            }
        });

        // 数値入力の妥当性チェック
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('input', function() {
                if (this.value < 0) {
                    this.value = 0;
                }
                if (this.name === 'stock_amount' && this.value > 50000) {
                    this.value = 50000;
                    alert('おつり在庫は50,000円以下で設定してください。');
                }
            });
        });
    </script>

    <!-- 追加のCSS（印刷用） -->
    <style>
        @media print {
            .card {
                border: 1px solid #000 !important;
                page-break-inside: avoid;
            }
            .card-header {
                background: #f8f9fa !important;
                border-bottom: 1px solid #000 !important;
            }
            .amount-large, .amount-medium {
                color: #000 !important;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
            }
        }
        
        /* 追加のレスポンシブ調整 */
        @media (max-width: 768px) {
            .amount-large {
                font-size: 1.5rem;
            }
            .card-body {
                padding: 1rem 0.5rem;
            }
            .table-responsive {
                font-size: 0.85rem;
            }
        }
        
        /* ダークモード対応 */
        @media (prefers-color-scheme: dark) {
            .accounting-memo {
                background: #2d3748;
                border-left: 4px solid #4299e1;
                color: #e2e8f0;
            }
        }
        
        /* アニメーション効果 */
        .card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        /* フォーカス時のハイライト */
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        /* 在庫ステータスアイコン */
        .stock-positive::before {
            content: '✓ ';
            color: #28a745;
        }
        
        .stock-warning::before {
            content: '⚠ ';
            color: #ffc107;
        }
        
        .stock-danger::before {
            content: '⚠ ';
            color: #dc3545;
        }
    </style>

    <!-- 追加のJavaScript機能 -->
    <script>
        // 経理仕訳のコピー機能
        function copyAccounting() {
            const accountingText = document.querySelector('.accounting-memo pre').textContent;
            navigator.clipboard.writeText(accountingText).then(() => {
                alert('仕訳内容をクリップボードにコピーしました');
            });
        }
        
        // 売上データのCSVエクスポート機能
        function exportToCSV() {
            const data = [
                ['日付', '件数', '現金', 'カード', 'その他', '合計']
            ];
            
            <?php foreach ($monthly_summary as $day): ?>
            data.push([
                '<?= $day->date ?>',
                '<?= $day->trips ?>',
                '<?= $day->cash ?>',
                '<?= $day->card ?>',
                '<?= $day->other ?>',
                '<?= $day->total ?>'
            ]);
            <?php endforeach; ?>
            
            const csvContent = data.map(row => row.join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = '売上データ_<?= $selected_month ?>.csv';
            link.click();
        }
        
        // キーボードショートカット
        document.addEventListener('keydown', function(e) {
            // Ctrl+P で印刷
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printPage();
            }
            
            // Ctrl+E でCSVエクスポート
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToCSV();
            }
        });
        
        // リアルタイム時刻表示
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('ja-JP');
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        
        setInterval(updateTime, 1000);
        
        // 通知機能（在庫不足の警告）
        function checkStockAlerts() {
            <?php foreach ($driver_stocks as $stock): ?>
            <?php if ($stock->stock_amount < 5000): ?>
            console.warn('在庫不足警告: <?= $stock->driver_name ?>さんのおつり在庫が不足しています（¥<?= number_format($stock->stock_amount) ?>）');
            <?php endif; ?>
            <?php endforeach; ?>
        }
        
        // ページ読み込み時に在庫チェック
        document.addEventListener('DOMContentLoaded', checkStockAlerts);
    </script>
</body>
</html>
