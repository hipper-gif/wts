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

// 既存テーブル構造を確認し、必要に応じて更新
try {
    // cash_confirmationsテーブルにdriver_idとchange_stockカラムを追加（存在しない場合）
    $stmt = $pdo->query("SHOW COLUMNS FROM cash_confirmations LIKE 'driver_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE cash_confirmations ADD COLUMN driver_id INT AFTER confirmation_date");
        $pdo->exec("ALTER TABLE cash_confirmations ADD COLUMN change_stock INT DEFAULT 0 AFTER difference");
        // 既存データのdriver_idを1に設定（デフォルト）
        $pdo->exec("UPDATE cash_confirmations SET driver_id = 1 WHERE driver_id IS NULL");
    }
    
    // cash_count_detailsテーブルを作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cash_count_details (
            id INT PRIMARY KEY AUTO_INCREMENT,
            confirmation_date DATE NOT NULL,
            driver_id INT NOT NULL,
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
            total_amount INT NOT NULL DEFAULT 0,
            memo TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_date_driver (confirmation_date, driver_id)
        )
    ");
    
    // driver_change_stocksテーブルを作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_change_stocks (
            id INT PRIMARY KEY AUTO_INCREMENT,
            driver_id INT,
            stock_amount INT NOT NULL DEFAULT 0,
            notes TEXT,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_driver (driver_id)
        )
    ");
    
} catch (PDOException $e) {
    // テーブル操作エラーは無視して続行
}

// POST処理
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'cash_count_and_confirm':
                $date = $_POST['date'];
                $driver_id = (int)$_POST['driver_id'];
                $confirmed_amount = (int)$_POST['confirmed_amount'];
                $memo = $_POST['memo'] ?? '';
                
                // 各金種の枚数
                $bills = [
                    '10000' => (int)($_POST['bill_10000'] ?? 0),
                    '5000' => (int)($_POST['bill_5000'] ?? 0),
                    '2000' => (int)($_POST['bill_2000'] ?? 0),
                    '1000' => (int)($_POST['bill_1000'] ?? 0)
                ];
                $coins = [
                    '500' => (int)($_POST['coin_500'] ?? 0),
                    '100' => (int)($_POST['coin_100'] ?? 0),
                    '50' => (int)($_POST['coin_50'] ?? 0),
                    '10' => (int)($_POST['coin_10'] ?? 0),
                    '5' => (int)($_POST['coin_5'] ?? 0),
                    '1' => (int)($_POST['coin_1'] ?? 0)
                ];
                
                // カウント合計金額計算
                $count_total = 0;
                foreach ($bills as $value => $count) {
                    $count_total += $value * $count;
                }
                foreach ($coins as $value => $count) {
                    $count_total += $value * $count;
                }
                
                // 運転者の売上・おつり在庫を取得
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN payment_method = '現金' THEN fare ELSE 0 END) as cash_sales
                    FROM ride_records 
                    WHERE DATE(ride_date) = ? AND driver_id = ?
                ");
                $stmt->execute([$date, $driver_id]);
                $driver_data = $stmt->fetch(PDO::FETCH_ASSOC);
                $cash_sales = $driver_data['cash_sales'] ?? 0;
                
                $change_stock = getDriverChangeStock($pdo, $driver_id);
                $calculated_amount = $cash_sales - $change_stock;
                $difference = $confirmed_amount - $calculated_amount;
                
                try {
                    $pdo->beginTransaction();
                    
                    // カウント詳細を保存
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_count_details 
                        (confirmation_date, driver_id, bill_10000, bill_5000, bill_2000, bill_1000, 
                         coin_500, coin_100, coin_50, coin_10, coin_5, coin_1, total_amount, memo)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
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
                        total_amount = VALUES(total_amount),
                        memo = VALUES(memo),
                        updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([
                        $date, $driver_id, 
                        $bills['10000'], $bills['5000'], $bills['2000'], $bills['1000'],
                        $coins['500'], $coins['100'], $coins['50'], $coins['10'], $coins['5'], $coins['1'],
                        $count_total, $memo
                    ]);
                    
                    // 現金確認を保存
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_confirmations 
                        (confirmation_date, driver_id, confirmed_amount, calculated_amount, difference, change_stock, memo, confirmed_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        confirmed_amount = VALUES(confirmed_amount),
                        calculated_amount = VALUES(calculated_amount),
                        difference = VALUES(difference),
                        change_stock = VALUES(change_stock),
                        memo = VALUES(memo),
                        confirmed_by = VALUES(confirmed_by),
                        updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$date, $driver_id, $confirmed_amount, $calculated_amount, $difference, $change_stock, $memo, $_SESSION['user_id']]);
                    
                    $pdo->commit();
                    $success_message = "現金カウント・確認記録を保存しました";
                } catch (PDOException $e) {
                    $pdo->rollBack();
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

function getDailyDrivers($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                u.id,
                u.name,
                SUM(CASE WHEN r.payment_method = '現金' THEN r.fare ELSE 0 END) as cash_sales,
                SUM(CASE WHEN r.payment_method = 'カード' THEN r.fare ELSE 0 END) as card_sales,
                SUM(CASE WHEN r.payment_method NOT IN ('現金', 'カード') THEN r.fare ELSE 0 END) as other_sales,
                COUNT(*) as total_trips,
                SUM(r.fare) as total_sales
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE(r.ride_date) = ? AND u.is_active = TRUE
            GROUP BY u.id, u.name
            ORDER BY u.name
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getDriverChangeStock($pdo, $driver_id) {
    try {
        $stmt = $pdo->prepare("SELECT stock_amount FROM driver_change_stocks WHERE driver_id = ?");
        $stmt->execute([$driver_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['stock_amount'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function getCashCountDetails($pdo, $date, $driver_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM cash_count_details WHERE confirmation_date = ? AND driver_id = ?");
        $stmt->execute([$date, $driver_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

function getCashConfirmation($pdo, $date, $driver_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM cash_confirmations WHERE confirmation_date = ? AND driver_id = ?");
        $stmt->execute([$date, $driver_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
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

// データ取得
$daily_sales = getDailySales($pdo, $selected_date);
$daily_total = getDailyTotal($pdo, $selected_date);
$daily_drivers = getDailyDrivers($pdo, $selected_date);
$monthly_summary = getMonthlySummary($pdo, $selected_month);

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
        .driver-cash-card { border-left: 4px solid #673ab7; }
        .cash-counter { background-color: #f8f9fa; }
        .money-input { text-align: center; font-weight: bold; }
        .money-label { font-size: 0.9rem; color: #666; text-align: center; display: block; margin-bottom: 5px; }
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
                <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'ユーザー'); ?>
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
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
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
                        <input type="date" name="date" value="<?php echo $selected_date; ?>" class="form-control">
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
                        <input type="month" name="month" value="<?php echo $selected_month; ?>" class="form-control">
                        <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
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
                    <div class="amount-display text-danger">¥<?php echo number_format($cash_sales); ?></div>
                    <small class="text-muted"><?php 
                    $cash_count = 0;
                    foreach($daily_sales as $sale) {
                        if($sale['payment_method'] === '現金') {
                            $cash_count = $sale['trip_count'];
                            break;
                        }
                    }
                    echo $cash_count;
                    ?>回</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-card">
                <div class="card-body text-center">
                    <i class="fas fa-credit-card fa-2x text-primary mb-2"></i>
                    <h6 class="card-title">カード売上</h6>
                    <div class="amount-display text-primary">¥<?php echo number_format($card_sales); ?></div>
                    <small class="text-muted"><?php 
                    $card_count = 0;
                    foreach($daily_sales as $sale) {
                        if($sale['payment_method'] === 'カード') {
                            $card_count = $sale['trip_count'];
                            break;
                        }
                    }
                    echo $card_count;
                    ?>回</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card other-card">
                <div class="card-body text-center">
                    <i class="fas fa-coins fa-2x text-warning mb-2"></i>
                    <h6 class="card-title">その他</h6>
                    <div class="amount-display text-warning">¥<?php echo number_format($other_sales); ?></div>
                    <small class="text-muted">-</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card total-card">
                <div class="card-body text-center">
                    <i class="fas fa-chart-line fa-2x text-success mb-2"></i>
                    <h6 class="card-title">合計売上</h6>
                    <div class="amount-display text-success">¥<?php echo number_format($daily_total['total_amount']); ?></div>
                    <small class="text-muted"><?php echo $daily_total['total_trips']; ?>回</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 運転者別現金確認 -->
    <?php if (!empty($daily_drivers)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card driver-cash-card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-users me-2"></i><?php echo date('Y/m/d', strtotime($selected_date)); ?> 運転者別現金確認
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($daily_drivers as $driver): ?>
                        <?php 
                        $change_stock = getDriverChangeStock($pdo, $driver['id']);
                        $calculated_cash = $driver['cash_sales'] - $change_stock;
                        $cash_count = getCashCountDetails($pdo, $selected_date, $driver['id']);
                        $cash_confirmation = getCashConfirmation($pdo, $selected_date, $driver['id']);
                        ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">
                                        <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($driver['name']); ?>
                                    </h6>
                                </div>
                    
                    <!-- 最終確認セクション -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="alert alert-light border">
                                <h6 class="text-success mb-3">
                                    <i class="fas fa-check-double me-2"></i>最終確認
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">最終確認金額 <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">¥</span>
                                            <input type="number" name="confirmed_amount" id="final_confirmed_amount" 
                                                   class="form-control fw-bold" required 
                                                   onchange="calculateFinalDifference()">
                                        </div>
                                        <small class="text-muted">実際に確認・回収した金額</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">最終差額</label>
                                        <div class="input-group">
                                            <span class="input-group-text">¥</span>
                                            <input type="text" id="final_difference_display" class="form-control" readonly>
                                        </div>
                                        <small id="final_difference_note" class="text-muted">実際金額 - 予定金額</small>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <label class="form-label">メモ</label>
                                        <textarea name="memo" id="cash_memo" class="form-control" rows="3" 
                                                  placeholder="カウント結果、差額の理由、特記事項など"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save me-2"></i>カウント・確認記録を保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 現金確認モーダル（簡易版・削除予定） -->
<div class="modal fade" id="cashConfirmModal" tabindex="-1" aria-labelledby="cashConfirmModalLabel" aria-hidden="true" style="display: none;">
    <!-- 統合モーダルがあるため使用しない -->
</div>
                                <div class="card-body">
                                    <!-- 売上サマリー -->
                                    <div class="mb-3">
                                        <div class="row text-center">
                                            <div class="col-4">
                                                <div class="text-danger">
                                                    <strong>¥<?php echo number_format($driver['cash_sales']); ?></strong>
                                                    <small class="d-block text-muted">現金売上</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="text-warning">
                                                    <strong>¥<?php echo number_format($change_stock); ?></strong>
                                                    <small class="d-block text-muted">おつり在庫</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="text-success">
                                                    <strong>¥<?php echo number_format($calculated_cash); ?></strong>
                                                    <small class="d-block text-muted">回収予定</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 確認状況 -->
                                    <?php if ($cash_confirmation): ?>
                                    <div class="alert alert-success py-2 mb-2">
                                        <small>
                                            <i class="fas fa-check-circle me-1"></i>確認済み: ¥<?php echo number_format($cash_confirmation['confirmed_amount']); ?>
                                            <?php if ($cash_confirmation['difference'] != 0): ?>
                                                <span class="text-<?php echo $cash_confirmation['difference'] > 0 ? 'primary' : 'danger'; ?>">
                                                    (<?php echo $cash_confirmation['difference'] > 0 ? '+' : ''; ?><?php echo number_format($cash_confirmation['difference']); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>

                                    <!-- カウント結果表示 -->
                                    <?php if ($cash_count): ?>
                                    <div class="alert alert-info py-2 mb-2">
                                        <small>
                                            <i class="fas fa-coins me-1"></i>カウント: ¥<?php echo number_format($cash_count['total_amount']); ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>

                                    <!-- 統合された現金カウント・確認ボタン -->
                                    <button type="button" class="btn btn-success btn-sm w-100 mb-2 no-print" 
                                            data-bs-toggle="modal" data-bs-target="#cashManagementModal"
                                            onclick="showCashManagementModal(<?php echo $driver['id']; ?>, '<?php echo htmlspecialchars($driver['name']); ?>', <?php echo $driver['cash_sales']; ?>, <?php echo $change_stock; ?>, <?php echo $calculated_cash; ?>)">
                                        <i class="fas fa-calculator me-2"></i>現金カウント・確認
                                        <?php if ($cash_confirmation): ?>
                                            <span class="badge bg-light text-dark ms-2">完了</span>
                                        <?php endif; ?>
                                    </button>

                                    <!-- おつり在庫調整ボタン -->
                                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 no-print" 
                                            data-bs-toggle="modal" data-bs-target="#changeStockModal"
                                            onclick="showChangeStockModal(<?php echo $driver['id']; ?>, '<?php echo htmlspecialchars($driver['name']); ?>', <?php echo $change_stock; ?>, '')">
                                        <i class="fas fa-wallet me-2"></i>おつり調整
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
    <?php else: ?>
    <!-- 運転者がいない場合の表示 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5><?php echo date('Y/m/d', strtotime($selected_date)); ?> の運転者データがありません</h5>
                    <p>この日に乗車記録がある運転者のみ表示されます。</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 月次サマリー -->
    <?php if (!empty($monthly_summary)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar me-2"></i><?php echo date('Y年m月', strtotime($selected_month)); ?> 日別サマリー
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
                                <tr class="<?php echo $is_weekend ? 'table-light' : ''; ?>">
                                    <td><?php echo $date->format('m/d'); ?></td>
                                    <td class="text-center <?php echo $is_weekend ? 'text-danger' : ''; ?>"><?php echo $day_of_week; ?></td>
                                    <td class="text-end"><?php echo $summary['trip_count']; ?></td>
                                    <td class="text-end">¥<?php echo number_format($summary['cash_amount']); ?></td>
                                    <td class="text-end">¥<?php echo number_format($summary['card_amount']); ?></td>
                                    <td class="text-end">¥<?php echo number_format($summary['other_amount']); ?></td>
                                    <td class="text-end fw-bold">¥<?php echo number_format($summary['total_amount']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-primary">
                                <tr>
                                    <th colspan="2">月計</th>
                                    <th class="text-end"><?php echo $monthly_total['trip_count']; ?></th>
                                    <th class="text-end">¥<?php echo number_format($monthly_total['cash_amount']); ?></th>
                                    <th class="text-end">¥<?php echo number_format($monthly_total['card_amount']); ?></th>
                                    <th class="text-end">¥<?php echo number_format($monthly_total['other_amount']); ?></th>
                                    <th class="text-end">¥<?php echo number_format($monthly_total['total_amount']); ?></th>
                                </tr>
                                <tr>
                                    <th colspan="2">1日平均</th>
                                    <th class="text-end"><?php echo count($monthly_summary) > 0 ? number_format($monthly_total['trip_count'] / count($monthly_summary), 1) : 0; ?></th>
                                    <th class="text-end">¥<?php echo count($monthly_summary) > 0 ? number_format($monthly_total['cash_amount'] / count($monthly_summary)) : 0; ?></th>
                                    <th class="text-end">¥<?php echo count($monthly_summary) > 0 ? number_format($monthly_total['card_amount'] / count($monthly_summary)) : 0; ?></th>
                                    <th class="text-end">¥<?php echo count($monthly_summary) > 0 ? number_format($monthly_total['other_amount'] / count($monthly_summary)) : 0; ?></th>
                                    <th class="text-end">¥<?php echo count($monthly_summary) > 0 ? number_format($monthly_total['total_amount'] / count($monthly_summary)) : 0; ?></th>
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

<!-- 統合現金管理モーダル -->
<div class="modal fade" id="cashManagementModal" tabindex="-1" aria-labelledby="cashManagementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cashManagementModalLabel">
                    <i class="fas fa-calculator me-2"></i>現金カウント・確認
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="cashManagementForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="cash_count_and_confirm">
                    <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                    <input type="hidden" name="driver_id" id="cash_driver_id">
                    
                    <!-- 運転者情報 -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label class="form-label">運転者</label>
                            <input type="text" id="cash_driver_name" class="form-control" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">現金売上</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="text" id="cash_sales_display" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">おつり在庫</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="text" id="change_stock_display" class="form-control" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">回収予定金額</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="text" id="expected_amount_display" class="form-control bg-info text-white fw-bold" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">カウント合計</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="text" id="count_total_display" class="form-control bg-success text-white fw-bold" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- 現金カウント -->
                        <div class="col-md-8">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-coins me-2"></i>現金カウント
                            </h6>
                            
                            <div class="cash-counter p-3 rounded">
                                <!-- 紙幣 -->
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <h6 class="text-success mb-2">
                                            <i class="fas fa-money-bill-wave me-2"></i>紙幣
                                        </h6>
                                    </div>
                                    <div class="col-3">
                                        <label class="money-label">1万円札</label>
                                        <input type="number" name="bill_10000" id="cash_bill_10000" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-3">
                                        <label class="money-label">5千円札</label>
                                        <input type="number" name="bill_5000" id="cash_bill_5000" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-3">
                                        <label class="money-label">2千円札</label>
                                        <input type="number" name="bill_2000" id="cash_bill_2000" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-3">
                                        <label class="money-label">千円札</label>
                                        <input type="number" name="bill_1000" id="cash_bill_1000" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                </div>
                                
                                <!-- 硬貨 -->
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <h6 class="text-warning mb-2">
                                            <i class="fas fa-coins me-2"></i>硬貨
                                        </h6>
                                    </div>
                                    <div class="col-2">
                                        <label class="money-label">500円</label>
                                        <input type="number" name="coin_500" id="cash_coin_500" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-2">
                                        <label class="money-label">100円</label>
                                        <input type="number" name="coin_100" id="cash_coin_100" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-2">
                                        <label class="money-label">50円</label>
                                        <input type="number" name="coin_50" id="cash_coin_50" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-2">
                                        <label class="money-label">10円</label>
                                        <input type="number" name="coin_10" id="cash_coin_10" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-2">
                                        <label class="money-label">5円</label>
                                        <input type="number" name="coin_5" id="cash_coin_5" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                    <div class="col-2">
                                        <label class="money-label">1円</label>
                                        <input type="number" name="coin_1" id="cash_coin_1" 
                                               class="form-control money-input" min="0" value="0" 
                                               onchange="calculateCashTotal()">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 確認・差額 -->
                        <div class="col-md-4">
                            <h6 class="text-success mb-3">
                                <i class="fas fa-check-circle me-2"></i>確認・差額
                            </h6>
                            
                            <!-- 差額表示 -->
                            <div class="alert alert-primary mb-3">
                                <div class="text-center">
                                    <h6 class="mb-1">差額</h6>
                                    <div class="h4 mb-0">
                                        <span id="cash_difference_display">¥0</span>
                                    </div>
                                    <small id="cash_difference_status" class="text-muted">-</small>
                                </div>
                            </div>
                            
                            <!-- 最終確認金額 -->
                            <div class="mb-3">
                                <label class="form-label">最終確認金額 <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">¥</span>
                                    <input type="number" name="confirmed_amount" id="final_confirmed_amount" 
                                           class="form-control fw-bold" required 
                                           onchange="calculateFinalDifference()">
                                </div>
                                <small class="text-muted">実際に確認・回収した金額</small>
                            </div>
                            
                            <!-- 最終差額 -->
                            <div class="mb-3">
                                <label class="form-label">最終差額</label>
                                <div class="input-group">
                                    <span class="input-group-text">¥</span>
                                    <input type="text" id="final_difference_display" class="form-control" readonly>
                                </div>
                                <small id="final_difference_note" class="text-muted">実際金額 - 予定金額</small>
                            </div>
                            
                            <!-- メモ -->
                            <div class="mb-3">
                                <label class="form-label">メモ</label>
                                <textarea name="memo" id="cash_memo" class="form-control" rows="4" 
                                          placeholder="カウント結果、差額の理由、特記事項など"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save me-2"></i>カウント・確認記録を保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
                    
                    <!-- 合計表示 -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="alert alert-success">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <h5 class="mb-0">
                                            <strong>カウント合計: </strong>
                                            <span id="total_amount_display">¥0</span>
                                        </h5>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>差額: </strong>
                                        <span id="difference_amount" class="fw-bold">¥0</span>
                                    </div>
                                    <div class="col-md-4">
                                        <span id="difference_status" class="badge bg-secondary">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">メモ</label>
                        <textarea name="memo" id="count_memo" class="form-control" rows="2" 
                                  placeholder="カウント時の特記事項など"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>カウント記録を保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 現金確認モーダル -->
<div class="modal fade" id="cashConfirmModal" tabindex="-1" aria-labelledby="cashConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cashConfirmModalLabel">
                    <i class="fas fa-check-circle me-2"></i>現金確認
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="cashConfirmForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="confirm_cash">
                    <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                    <input type="hidden" name="driver_id" id="confirm_driver_id">
                    <input type="hidden" name="calculated_amount" id="confirm_calculated_amount">
                    <input type="hidden" name="change_stock" id="confirm_change_stock">
                    
                    <div class="mb-3">
                        <label class="form-label">運転者</label>
                        <input type="text" id="confirm_driver_name" class="form-control" readonly>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">現金売上</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="text" id="confirm_cash_sales" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">おつり在庫</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="text" id="confirm_change_display" class="form-control" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">計算上の回収額</label>
                        <div class="input-group">
                            <span class="input-group-text">¥</span>
                            <input type="text" id="confirm_calculated_display" class="form-control bg-info text-white fw-bold" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">実際の確認金額 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">¥</span>
                            <input type="number" name="confirmed_amount" id="confirm_actual_amount" 
                                   class="form-control" required onchange="calculateConfirmDifference()">
                        </div>
                        <small class="text-muted">カウントした実際の金額を入力</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">差額</label>
                        <div class="input-group">
                            <span class="input-group-text">¥</span>
                            <input type="text" id="confirm_difference_display" class="form-control" readonly>
                        </div>
                        <small id="confirm_difference_note" class="text-muted">実際金額 - 計算金額</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">メモ</label>
                        <textarea name="memo" id="confirm_memo" class="form-control" rows="3" 
                                  placeholder="差額の理由、特記事項など"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>確認記録を保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- おつり在庫調整モーダル -->
<div class="modal fade" id="changeStockModal" tabindex="-1" aria-labelledby="changeStockModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changeStockModalLabel">
                    <i class="fas fa-wallet me-2"></i>おつり在庫調整
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="changeStockForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_change_stock">
                    <input type="hidden" name="driver_id" id="stock_driver_id">
                    
                    <div class="mb-3">
                        <label class="form-label">運転者</label>
                        <input type="text" id="stock_driver_name" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">在庫金額 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">¥</span>
                            <input type="number" name="stock_amount" id="stock_amount" 
                                   class="form-control" required min="0" step="100">
                        </div>
                        <small class="text-muted">100円単位で入力してください</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">メモ</label>
                        <textarea name="notes" id="stock_notes" class="form-control" rows="3" 
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
// グローバル変数
let currentExpectedAmount = 0;

// 現金カウント計算
function calculateTotal() {
    // 紙幣計算
    const bills = {
        10000: parseInt(document.getElementById('bill_10000').value) || 0,
        5000: parseInt(document.getElementById('bill_5000').value) || 0,
        2000: parseInt(document.getElementById('bill_2000').value) || 0,
        1000: parseInt(document.getElementById('bill_1000').value) || 0
    };
    
    // 硬貨計算
    const coins = {
        500: parseInt(document.getElementById('coin_500').value) || 0,
        100: parseInt(document.getElementById('coin_100').value) || 0,
        50: parseInt(document.getElementById('coin_50').value) || 0,
        10: parseInt(document.getElementById('coin_10').value) || 0,
        5: parseInt(document.getElementById('coin_5').value) || 0,
        1: parseInt(document.getElementById('coin_1').value) || 0
    };
    
    // 紙幣合計
    let billsTotal = 0;
    for (const [value, count] of Object.entries(bills)) {
        billsTotal += parseInt(value) * count;
    }
    
    // 硬貨合計
    let coinsTotal = 0;
    for (const [value, count] of Object.entries(coins)) {
        coinsTotal += parseInt(value) * count;
    }
    
    // 総合計
    const totalAmount = billsTotal + coinsTotal;
    
    // 表示更新
    document.getElementById('bills_total').textContent = '¥' + billsTotal.toLocaleString();
    document.getElementById('coins_total').textContent = '¥' + coinsTotal.toLocaleString();
    document.getElementById('total_amount_display').textContent = '¥' + totalAmount.toLocaleString();
    
    // 差額計算
    const difference = totalAmount - currentExpectedAmount;
    
    document.getElementById('difference_amount').textContent = (difference >= 0 ? '+' : '') + '¥' + Math.abs(difference).toLocaleString();
    
    const statusElement = document.getElementById('difference_status');
    if (difference > 0) {
        statusElement.textContent = '過剰';
        statusElement.className = 'badge bg-warning';
    } else if (difference < 0) {
        statusElement.textContent = '不足';
        statusElement.className = 'badge bg-danger';
    } else {
        statusElement.textContent = '一致';
        statusElement.className = 'badge bg-success';
    }
}

// 現金確認差額計算
function calculateConfirmDifference() {
    const actual = parseInt(document.getElementById('confirm_actual_amount').value) || 0;
    const calculated = parseInt(document.getElementById('confirm_calculated_amount').value) || 0;
    const difference = actual - calculated;
    
    const differenceDisplay = document.getElementById('confirm_difference_display');
    const differenceNote = document.getElementById('confirm_difference_note');
    
    differenceDisplay.value = (difference >= 0 ? '+' : '') + difference.toLocaleString();
    
    if (difference > 0) {
        differenceDisplay.className = 'form-control text-primary fw-bold';
        differenceNote.textContent = '現金過剰（要確認）';
        differenceNote.className = 'text-primary';
    } else if (difference < 0) {
        differenceDisplay.className = 'form-control text-danger fw-bold';
        differenceNote.textContent = '現金不足（要確認）';
        differenceNote.className = 'text-danger';
    } else {
        differenceDisplay.className = 'form-control text-success fw-bold';
        differenceNote.textContent = '計算通り（問題なし）';
        differenceNote.className = 'text-success';
    }
}

// 現金カウントモーダル表示
function showCashCountModal(driverId, driverName, expectedAmount) {
    currentExpectedAmount = expectedAmount;
    
    document.getElementById('count_driver_id').value = driverId;
    document.getElementById('count_driver_name').value = driverName;
    document.getElementById('count_expected_amount').value = expectedAmount.toLocaleString();
    
    // フォームリセット
    document.getElementById('cashCountForm').reset();
    document.getElementById('count_driver_id').value = driverId;
    
    calculateTotal();
    
    const modal = new bootstrap.Modal(document.getElementById('cashCountModal'));
    modal.show();
}
    
    calculateTotal();
    
    const modal = new bootstrap.Modal(document.getElementById('cashCountModal'));
    modal.show();
}

// 現金確認モーダル表示
function showCashConfirmModal(driverId, driverName, calculatedAmount, changeStock, countedAmount) {
    const cashSales = calculatedAmount + changeStock;
    
    document.getElementById('confirm_driver_id').value = driverId;
    document.getElementById('confirm_driver_name').value = driverName;
    document.getElementById('confirm_calculated_amount').value = calculatedAmount;
    document.getElementById('confirm_change_stock').value = changeStock;
    document.getElementById('confirm_cash_sales').value = cashSales.toLocaleString();
    document.getElementById('confirm_change_display').value = changeStock.toLocaleString();
    document.getElementById('confirm_calculated_display').value = calculatedAmount.toLocaleString();
    document.getElementById('confirm_actual_amount').value = countedAmount;
    
    calculateConfirmDifference();
    
    const modal = new bootstrap.Modal(document.getElementById('cashConfirmModal'));
    modal.show();
}

// おつり在庫調整モーダル表示
function showChangeStockModal(driverId, driverName, stockAmount, notes) {
    document.getElementById('stock_driver_id').value = driverId;
    document.getElementById('stock_driver_name').value = driverName;
    document.getElementById('stock_amount').value = stockAmount;
    document.getElementById('stock_notes').value = notes;
    
    const modal = new bootstrap.Modal(document.getElementById('changeStockModal'));
    modal.show();
}

// ページ読み込み時の初期化
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing cash management...');
    
    // 初期計算が必要な関数があれば実行
    if (typeof calculateCashTotal === 'function') {
        // 初期計算は行わない（モーダル表示時に実行）
    }
    
    // Bootstrap モーダルの初期化確認
    const modals = ['cashManagementModal', 'changeStockModal'];
    modals.forEach(modalId => {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            console.log(`Modal ${modalId} found and ready`);
            
            // モーダルイベントリスナー追加
            modalElement.addEventListener('shown.bs.modal', function () {
                console.log(`Modal ${modalId} has been shown`);
            });
            
            modalElement.addEventListener('hidden.bs.modal', function () {
                console.log(`Modal ${modalId} has been hidden`);
            });
        } else {
            console.error(`Modal ${modalId} not found`);
        }
    });
    
    // フォーム送信時の処理
    const cashForm = document.getElementById('cashManagementForm');
    if (cashForm) {
        cashForm.addEventListener('submit', function(e) {
            console.log('Cash management form submitted');
            
            // バリデーション
            const confirmedAmount = document.getElementById('final_confirmed_amount').value;
            if (!confirmedAmount || confirmedAmount === '' || parseInt(confirmedAmount) < 0) {
                e.preventDefault();
                alert('最終確認金額を正しく入力してください。');
                return false;
            }
        });
    }
    
    // デバッグ用：ボタンクリックをログ
    document.addEventListener('click', function(e) {
        if (e.target.closest('[onclick*="showCashManagementModal"]')) {
            console.log('Cash management button clicked:', e.target);
        }
    });
});

// エラーハンドリング
window.addEventListener('error', function(e) {
    console.error('JavaScript Error:', e.error);
    console.error('Error details:', {
        message: e.message,
        filename: e.filename,
        lineno: e.lineno,
        colno: e.colno
    });
});
</script>

</body>
</html>
