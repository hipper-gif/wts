<?php
session_start();

// ログイン確認
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

// 管理者権限チェック（集金管理は管理者のみ）
$stmt = $pdo->prepare("SELECT name, permission_level FROM users WHERE id = ? AND is_active = TRUE");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

if ($user['permission_level'] !== 'Admin') {
    header('Location: dashboard.php?error=admin_required');
    exit();
}

// 以下、既存のコードを継続...

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT name, permission_level FROM users WHERE id = ? AND is_active = TRUE");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['permission_level'] !== 'Admin') {
    header('Location: dashboard.php?error=admin_required');
    exit;
}

// 🎯 新機能: 拡張されたフィルター
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$selected_drivers = $_GET['drivers'] ?? [];
$selected_month = $_GET['month'] ?? date('Y-m');

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'confirm_cash_detailed':
                    // 🎯 新機能: 詳細現金確認処理
                    $date = $_POST['target_date'];
                    $driver_id = $_POST['driver_id'];
                    
                    // 紙幣・硬貨の詳細
                    $bills_10000 = (int)$_POST['bills_10000'];
                    $bills_5000 = (int)$_POST['bills_5000'];
                    $bills_1000 = (int)$_POST['bills_1000'];
                    $coins_500 = (int)$_POST['coins_500'];
                    $coins_100 = (int)$_POST['coins_100'];
                    $coins_50 = (int)$_POST['coins_50'];
                    $coins_10 = (int)$_POST['coins_10'];
                    $coins_5 = (int)$_POST['coins_5'];
                    $coins_1 = (int)$_POST['coins_1'];
                    
                    // おつり情報
                    $change_amount = (int)$_POST['change_amount'];
                    $memo = $_POST['memo'] ?? '';
                    
                    // 実際の現金合計を計算
                    $confirmed_amount = 
                        ($bills_10000 * 10000) +
                        ($bills_5000 * 5000) +
                        ($bills_1000 * 1000) +
                        ($coins_500 * 500) +
                        ($coins_100 * 100) +
                        ($coins_50 * 50) +
                        ($coins_10 * 10) +
                        ($coins_5 * 5) +
                        ($coins_1 * 1);
                    
                    // おつりを除いた実収金額
                    $net_amount = $confirmed_amount - $change_amount;
                    
                    // 計算上の売上取得
                    $stmt = $pdo->prepare("
                        SELECT SUM(fare + charge) as calculated_amount
                        FROM ride_records 
                        WHERE DATE(ride_date) = ? 
                        AND driver_id = ?
                        AND payment_method = '現金'
                    ");
                    $stmt->execute([$date, $driver_id]);
                    $calculated_result = $stmt->fetch();
                    $calculated_amount = $calculated_result['calculated_amount'] ?? 0;
                    
                    $difference = $net_amount - $calculated_amount;
                    
                    // 詳細集金確認記録を保存
                    $stmt = $pdo->prepare("
                        INSERT INTO detailed_cash_confirmations 
                        (confirmation_date, driver_id, bills_10000, bills_5000, bills_1000, 
                         coins_500, coins_100, coins_50, coins_10, coins_5, coins_1,
                         total_cash, change_amount, net_amount, calculated_amount, difference, 
                         memo, confirmed_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                        bills_10000 = VALUES(bills_10000),
                        bills_5000 = VALUES(bills_5000),
                        bills_1000 = VALUES(bills_1000),
                        coins_500 = VALUES(coins_500),
                        coins_100 = VALUES(coins_100),
                        coins_50 = VALUES(coins_50),
                        coins_10 = VALUES(coins_10),
                        coins_5 = VALUES(coins_5),
                        coins_1 = VALUES(coins_1),
                        total_cash = VALUES(total_cash),
                        change_amount = VALUES(change_amount),
                        net_amount = VALUES(net_amount),
                        calculated_amount = VALUES(calculated_amount),
                        difference = VALUES(difference),
                        memo = VALUES(memo),
                        confirmed_by = VALUES(confirmed_by),
                        updated_at = NOW()
                    ");
                    
                    $stmt->execute([
                        $date, $driver_id, $bills_10000, $bills_5000, $bills_1000,
                        $coins_500, $coins_100, $coins_50, $coins_10, $coins_5, $coins_1,
                        $confirmed_amount, $change_amount, $net_amount, $calculated_amount, $difference,
                        $memo, $_SESSION['user_id']
                    ]);
                    
                    $message = "詳細現金確認を記録しました。";
                    break;
                    
                case 'update_change_stock':
                    // 🎯 新機能: おつり在庫更新
                    $driver_id = $_POST['driver_id'];
                    $change_stock = (int)$_POST['change_stock'];
                    $notes = $_POST['notes'] ?? '';
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO driver_change_stocks (driver_id, stock_amount, notes, updated_by, updated_at)
                        VALUES (?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                        stock_amount = VALUES(stock_amount),
                        notes = VALUES(notes),
                        updated_by = VALUES(updated_by),
                        updated_at = NOW()
                    ");
                    $stmt->execute([$driver_id, $change_stock, $notes, $_SESSION['user_id']]);
                    
                    $message = "おつり在庫を更新しました。";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// 🎯 新機能: 範囲指定売上データ取得
function getRangeSales($pdo, $date_from, $date_to, $driver_ids = []) {
    $where_conditions = ["DATE(ride_date) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if (!empty($driver_ids)) {
        $placeholders = str_repeat('?,', count($driver_ids) - 1) . '?';
        $where_conditions[] = "driver_id IN ($placeholders)";
        $params = array_merge($params, $driver_ids);
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE(ride_date) as date,
            driver_id,
            u.name as driver_name,
            payment_method,
            COUNT(*) as count,
            SUM(fare + charge) as total_amount
        FROM ride_records r
        JOIN users u ON r.driver_id = u.id
        WHERE " . implode(' AND ', $where_conditions) . "
        GROUP BY DATE(ride_date), driver_id, payment_method
        ORDER BY date DESC, driver_name, payment_method
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 運転手一覧取得
function getDrivers($pdo) {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM users 
        WHERE (permission_level IN ('user', 'admin') OR is_driver = 1) 
        AND is_active = 1 
        ORDER BY name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 🎯 新機能: 運転手別集金確認取得
function getDetailedCashConfirmations($pdo, $date, $driver_id = null) {
    $where_conditions = ["confirmation_date = ?"];
    $params = [$date];
    
    if ($driver_id) {
        $where_conditions[] = "driver_id = ?";
        $params[] = $driver_id;
    }
    
    $stmt = $pdo->prepare("
        SELECT dcc.*, u.name as driver_name, cu.name as confirmed_by_name
        FROM detailed_cash_confirmations dcc
        JOIN users u ON dcc.driver_id = u.id
        LEFT JOIN users cu ON dcc.confirmed_by = cu.id
        WHERE " . implode(' AND ', $where_conditions) . "
        ORDER BY u.name
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// おつり在庫取得
function getChangeStocks($pdo) {
    $stmt = $pdo->prepare("
        SELECT dcs.*, u.name as driver_name, cu.name as updated_by_name
        FROM driver_change_stocks dcs
        JOIN users u ON dcs.driver_id = u.id
        LEFT JOIN users cu ON dcs.updated_by = cu.id
        ORDER BY u.name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// テーブル作成
try {
    // 詳細集金確認テーブル
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS detailed_cash_confirmations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            confirmation_date DATE NOT NULL,
            driver_id INT NOT NULL,
            bills_10000 INT DEFAULT 0,
            bills_5000 INT DEFAULT 0,
            bills_1000 INT DEFAULT 0,
            coins_500 INT DEFAULT 0,
            coins_100 INT DEFAULT 0,
            coins_50 INT DEFAULT 0,
            coins_10 INT DEFAULT 0,
            coins_5 INT DEFAULT 0,
            coins_1 INT DEFAULT 0,
            total_cash INT NOT NULL DEFAULT 0,
            change_amount INT DEFAULT 0,
            net_amount INT NOT NULL DEFAULT 0,
            calculated_amount INT NOT NULL DEFAULT 0,
            difference INT NOT NULL DEFAULT 0,
            memo TEXT,
            confirmed_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_date_driver (confirmation_date, driver_id),
            INDEX idx_confirmation_date (confirmation_date),
            INDEX idx_driver_id (driver_id)
        )
    ");
    
    // 運転手別おつり在庫テーブル
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_change_stocks (
            id INT PRIMARY KEY AUTO_INCREMENT,
            driver_id INT NOT NULL UNIQUE,
            stock_amount INT NOT NULL DEFAULT 0,
            notes TEXT,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_driver_id (driver_id)
        )
    ");
} catch (PDOException $e) {
    $error = "テーブル作成に失敗しました: " . $e->getMessage();
}

// データ取得
$drivers = getDrivers($pdo);
$range_sales = getRangeSales($pdo, $date_from, $date_to, $selected_drivers);
$detailed_confirmations = getDetailedCashConfirmations($pdo, $date_from);
$change_stocks = getChangeStocks($pdo);

// 🎯 新機能: 運転手別集計データ
$driver_summaries = [];
foreach ($range_sales as $sale) {
    $driver_id = $sale['driver_id'];
    if (!isset($driver_summaries[$driver_id])) {
        $driver_summaries[$driver_id] = [
            'driver_name' => $sale['driver_name'],
            'cash_amount' => 0,
            'card_amount' => 0,
            'other_amount' => 0,
            'total_amount' => 0,
            'total_rides' => 0
        ];
    }
    
    if ($sale['payment_method'] === '現金') {
        $driver_summaries[$driver_id]['cash_amount'] += $sale['total_amount'];
    } elseif ($sale['payment_method'] === 'カード') {
        $driver_summaries[$driver_id]['card_amount'] += $sale['total_amount'];
    } else {
        $driver_summaries[$driver_id]['other_amount'] += $sale['total_amount'];
    }
    
    $driver_summaries[$driver_id]['total_amount'] += $sale['total_amount'];
    $driver_summaries[$driver_id]['total_rides'] += $sale['count'];
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
        
        /* 🎯 新機能用スタイル */
        .filter-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
        }
        
        .cash-breakdown {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .denomination-input {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .denomination-label {
            min-width: 80px;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .denomination-count {
            width: 80px;
            margin-right: 10px;
        }
        
        .denomination-amount {
            min-width: 100px;
            font-weight: bold;
            color: #28a745;
        }
        
        .change-management {
            background: linear-gradient(135deg, #ffeaa7 0%, #fdcb6e 100%);
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
        
        .driver-card {
            border-left: 4px solid #007bff;
            background: white;
            margin-bottom: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .confirmed-driver {
            border-left-color: #28a745;
            background: linear-gradient(90deg, #f8fff9 0%, white 20%);
        }
        
        .driver-summary {
            background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .amount-display {
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .difference-positive {
            color: #dc3545;
            font-weight: bold;
        }
        
        .difference-negative {
            color: #198754;
            font-weight: bold;
        }
        
        .difference-zero {
            color: #28a745;
            font-weight: bold;
        }
        
        .summary-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .denomination-input {
                flex-wrap: wrap;
            }
            
            .denomination-label {
                min-width: 60px;
                font-size: 0.9rem;
            }
            
            .denomination-count {
                width: 60px;
            }
        }
    </style>
</head>

<body class="bg-light">
    <!-- ナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-calculator me-2"></i>集金管理（機能強化版）
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
            <div class="alert alert-success alert-dismissible fade show" permission_level="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" permission_level="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 🎯 新機能: 拡張フィルター -->
        <div class="filter-section">
            <h4 class="mb-3">
                <i class="fas fa-filter me-2"></i>フィルター設定
            </h4>
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="date_from" class="form-label">
                        <i class="fas fa-calendar me-1"></i>開始日
                    </label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">
                        <i class="fas fa-calendar me-1"></i>終了日
                    </label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-4">
                    <label for="drivers" class="form-label">
                        <i class="fas fa-users me-1"></i>運転手
                    </label>
                    <select class="form-select" id="drivers" name="drivers[]" multiple>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?= $driver['id'] ?>" 
                                <?= in_array($driver['id'], $selected_drivers) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($driver['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-light">Ctrlキーで複数選択</small>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-light w-100">
                        <i class="fas fa-search me-1"></i>検索
                    </button>
                </div>
            </form>
        </div>

        <!-- 🎯 新機能: 運転手別サマリー -->
        <?php if (!empty($driver_summaries)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <h5><i class="fas fa-chart-pie me-2"></i>運転手別集計</h5>
            </div>
            <?php foreach ($driver_summaries as $driver_id => $summary): ?>
            <div class="col-md-6 col-lg-4">
                <div class="driver-summary">
                    <h6><?= htmlspecialchars($summary['driver_name']) ?></h6>
                    <div class="row text-center">
                        <div class="col-6">
                            <strong>総売上</strong><br>
                            <span class="amount-display">¥<?= number_format($summary['total_amount']) ?></span>
                        </div>
                        <div class="col-6">
                            <strong>現金</strong><br>
                            <span class="amount-display">¥<?= number_format($summary['cash_amount']) ?></span>
                        </div>
                    </div>
                    <div class="text-center mt-2">
                        <small><?= $summary['total_rides'] ?>回の乗車</small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 🎯 新機能: 運転手別詳細集金確認 -->
        <div class="row">
            <?php foreach ($drivers as $driver): ?>
                <?php if (!empty($selected_drivers) && !in_array($driver['id'], $selected_drivers)) continue; ?>
                
                <div class="col-lg-6 mb-4">
                    <?php 
                    $driver_cash_amount = $driver_summaries[$driver['id']]['cash_amount'] ?? 0;
                    $driver_confirmation = null;
                    foreach ($detailed_confirmations as $conf) {
                        if ($conf['driver_id'] == $driver['id']) {
                            $driver_confirmation = $conf;
                            break;
                        }
                    }
                    ?>
                    
                    <div class="card driver-card <?= $driver_confirmation ? 'confirmed-driver' : '' ?>">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-user me-2"></i><?= htmlspecialchars($driver['name']) ?>
                                <?php if ($driver_confirmation): ?>
                                    <span class="badge bg-success ms-2">確認済み</span>
                                <?php endif; ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($driver_confirmation): ?>
                                <!-- 確認済み表示 -->
                                <div class="row">
                                    <div class="col-6">
                                        <strong>実現金:</strong><br>
                                        <span class="text-primary">¥<?= number_format($driver_confirmation['total_cash']) ?></span>
                                    </div>
                                    <div class="col-6">
                                        <strong>おつり:</strong><br>
                                        <span class="text-warning">¥<?= number_format($driver_confirmation['change_amount']) ?></span>
                                    </div>
                                    <div class="col-6">
                                        <strong>実収金:</strong><br>
                                        <span class="text-success">¥<?= number_format($driver_confirmation['net_amount']) ?></span>
                                    </div>
                                    <div class="col-6">
                                        <strong>差額:</strong><br>
                                        <span class="<?= $driver_confirmation['difference'] > 0 ? 'difference-positive' : ($driver_confirmation['difference'] < 0 ? 'difference-negative' : 'difference-zero') ?>">
                                            <?= $driver_confirmation['difference'] > 0 ? '+' : '' ?>¥<?= number_format($driver_confirmation['difference']) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- 紙幣・硬貨内訳 -->
                                <div class="cash-breakdown mt-3">
                                    <h6>紙幣・硬貨内訳</h6>
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <small>1万円: <?= $driver_confirmation['bills_10000'] ?>枚</small>
                                        </div>
                                        <div class="col-4">
                                            <small>5千円: <?= $driver_confirmation['bills_5000'] ?>枚</small>
                                        </div>
                                        <div class="col-4">
                                            <small>千円: <?= $driver_confirmation['bills_1000'] ?>枚</small>
                                        </div>
                                        <div class="col-3">
                                            <small>500円: <?= $driver_confirmation['coins_500'] ?>枚</small>
                                        </div>
                                        <div class="col-3">
                                            <small>100円: <?= $driver_confirmation['coins_100'] ?>枚</small>
                                        </div>
                                        <div class="col-3">
                                            <small>50円: <?= $driver_confirmation['coins_50'] ?>枚</small>
                                        </div>
                                        <div class="col-3">
                                            <small>10円: <?= $driver_confirmation['coins_10'] ?>枚</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" 
                                        onclick="editDetailedConfirmation(<?= $driver['id'] ?>, '<?= htmlspecialchars($driver['name']) ?>', <?= $driver_cash_amount ?>)">
                                    <i class="fas fa-edit me-1"></i>修正
                                </button>
                            <?php else: ?>
                                <!-- 未確認表示 -->
                                <div class="alert alert-warning">
                                    <small>
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        現金確認が未完了です（計算売上: ¥<?= number_format($driver_cash_amount) ?>）
                                    </small>
                                </div>
                                
                                <button type="button" class="btn btn-primary btn-sm" 
                                        onclick="showDetailedConfirmation(<?= $driver['id'] ?>, '<?= htmlspecialchars($driver['name']) ?>', <?= $driver_cash_amount ?>)">
                                    <i class="fas fa-calculator me-1"></i>詳細集金確認
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 🎯 新機能: おつり在庫管理 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-coins me-2"></i>おつり在庫管理</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($drivers as $driver): ?>
                                <?php 
                                $stock_info = null;
                                foreach ($change_stocks as $stock) {
                                    if ($stock['driver_id'] == $driver['id']) {
                                        $stock_info = $stock;
                                        break;
                                    }
                                }
                                ?>
                                <div class="col-md-4 mb-3">
                                    <div class="change-management">
                                        <h6><?= htmlspecialchars($driver['name']) ?></h6>
                                        <div class="text-center">
                                            <strong>在庫: ¥<?= number_format($stock_info['stock_amount'] ?? 0) ?></strong>
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" 
                                                onclick="updateChangeStock(<?= $driver['id'] ?>, '<?= htmlspecialchars($driver['name']) ?>', <?= $stock_info['stock_amount'] ?? 0 ?>)">
                                            <i class="fas fa-edit me-1"></i>更新
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
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

    <!-- 🎯 新機能: 詳細集金確認モーダル -->
    <div class="modal fade" id="detailedCashModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calculator me-2"></i>詳細集金確認
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="detailedCashForm">
                    <input type="hidden" name="action" value="confirm_cash_detailed">
                    <input type="hidden" name="target_date" id="modalTargetDate" value="<?= $date_from ?>">
                    <input type="hidden" name="driver_id" id="modalDriverId">
                    
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 id="modalDriverName"></h6>
                                <p class="text-muted">計算上の現金売上: <strong id="modalCalculatedAmount"></strong></p>
                            </div>
                        </div>

                        <!-- 紙幣・硬貨入力 -->
                        <div class="cash-breakdown">
                            <h6><i class="fas fa-money-bill me-2"></i>紙幣・硬貨の詳細</h6>
                            
                            <!-- 紙幣 -->
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="denomination-input">
                                        <span class="denomination-label">1万円:</span>
                                        <input type="number" class="form-control denomination-count" name="bills_10000" id="bills_10000" min="0" value="0">
                                        <span class="denomination-amount" id="amount_10000">¥0</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="denomination-input">
                                        <span class="denomination-label">5千円:</span>
                                        <input type="number" class="form-control denomination-count" name="bills_5000" id="bills_5000" min="0" value="0">
                                        <span class="denomination-amount" id="amount_5000">¥0</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="denomination-input">
                                        <span class="denomination-label">千円:</span>
                                        <input type="number" class="form-control denomination-count" name="bills_1000" id="bills_1000" min="0" value="0">
                                        <span class="denomination-amount" id="amount_1000">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 硬貨 -->
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="denomination-input">
                                        <span class="denomination-label">500円:</span>
                                        <input type="number" class="form-control denomination-count" name="coins_500" id="coins_500" min="0" value="0">
                                        <span class="denomination-amount" id="amount_500">¥0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="denomination-input">
                                        <span class="denomination-label">100円:</span>
                                        <input type="number" class="form-control denomination-count" name="coins_100" id="coins_100" min="0" value="0">
                                        <span class="denomination-amount" id="amount_100">¥0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="denomination-input">
                                        <span class="denomination-label">50円:</span>
                                        <input type="number" class="form-control denomination-count" name="coins_50" id="coins_50" min="0" value="0">
                                        <span class="denomination-amount" id="amount_50">¥0</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="denomination-input">
                                        <span class="denomination-label">10円:</span>
                                        <input type="number" class="form-control denomination-count" name="coins_10" id="coins_10" min="0" value="0">
                                        <span class="denomination-amount" id="amount_10">¥0</span>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="denomination-input">
                                        <span class="denomination-label">5円:</span>
                                        <input type="number" class="form-control denomination-count" name="coins_5" id="coins_5" min="0" value="0">
                                        <span class="denomination-amount" id="amount_5">¥0</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="denomination-input">
                                        <span class="denomination-label">1円:</span>
                                        <input type="number" class="form-control denomination-count" name="coins_1" id="coins_1" min="0" value="0">
                                        <span class="denomination-amount" id="amount_1">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 合計表示 -->
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <strong>実現金合計: <span id="totalCashAmount">¥0</span></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- おつり管理 -->
                        <div class="change-management">
                            <h6><i class="fas fa-coins me-2"></i>おつり管理</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="change_amount" class="form-label">おつり在庫額</label>
                                    <input type="number" class="form-control" name="change_amount" id="change_amount" min="0" value="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">実収金額（おつり除く）</label>
                                    <div class="form-control-plaintext" id="netAmount">¥0</div>
                                </div>
                            </div>
                        </div>

                        <!-- 差額計算結果 -->
                        <div class="alert alert-secondary">
                            <h6>差額確認</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>計算売上:</strong><br>
                                    <span id="calculatedAmountDisplay">¥0</span>
                                </div>
                                <div class="col-md-4">
                                    <strong>実収金額:</strong><br>
                                    <span id="netAmountDisplay">¥0</span>
                                </div>
                                <div class="col-md-4">
                                    <strong>差額:</strong><br>
                                    <span id="differenceDisplay" class="difference-zero">¥0</span>
                                </div>
                            </div>
                        </div>

                        <!-- メモ -->
                        <div class="mb-3">
                            <label for="memo" class="form-label">メモ（差額がある場合の理由等）</label>
                            <textarea class="form-control" name="memo" id="memo" rows="2" 
                                      placeholder="差額の理由や特記事項があれば記入してください"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>キャンセル
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>確認を記録
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- おつり在庫更新モーダル -->
    <div class="modal fade" id="changeStockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-coins me-2"></i>おつり在庫更新
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_change_stock">
                    <input type="hidden" name="driver_id" id="stockDriverId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <h6 id="stockDriverName"></h6>
                        </div>
                        
                        <div class="mb-3">
                            <label for="change_stock" class="form-label">おつり在庫額</label>
                            <input type="number" class="form-control" name="change_stock" id="change_stock" min="0" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="stock_notes" class="form-label">メモ</label>
                            <textarea class="form-control" name="notes" id="stock_notes" rows="2" 
                                      placeholder="在庫変更の理由等"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>キャンセル
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>更新
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 詳細集金確認モーダル表示
        function showDetailedConfirmation(driverId, driverName, calculatedAmount) {
            document.getElementById('modalDriverId').value = driverId;
            document.getElementById('modalDriverName').textContent = driverName;
            document.getElementById('modalCalculatedAmount').textContent = '¥' + calculatedAmount.toLocaleString();
            document.getElementById('calculatedAmountDisplay').textContent = '¥' + calculatedAmount.toLocaleString();
            
            // フォームリセット
            resetCashForm();
            
            new bootstrap.Modal(document.getElementById('detailedCashModal')).show();
        }

        // 編集用モーダル表示
        function editDetailedConfirmation(driverId, driverName, calculatedAmount) {
            // 基本的には新規作成と同じ処理
            showDetailedConfirmation(driverId, driverName, calculatedAmount);
        }

        // おつり在庫更新モーダル表示
        function updateChangeStock(driverId, driverName, currentStock) {
            document.getElementById('stockDriverId').value = driverId;
            document.getElementById('stockDriverName').textContent = driverName;
            document.getElementById('change_stock').value = currentStock;
            
            new bootstrap.Modal(document.getElementById('changeStockModal')).show();
        }

        // フォームリセット
        function resetCashForm() {
            const denominations = ['bills_10000', 'bills_5000', 'bills_1000', 'coins_500', 'coins_100', 'coins_50', 'coins_10', 'coins_5', 'coins_1'];
            denominations.forEach(id => {
                document.getElementById(id).value = 0;
                updateDenominationAmount(id);
            });
            document.getElementById('change_amount').value = 0;
            updateTotalAmount();
        }

        // 金種別金額更新
        function updateDenominationAmount(denominationId) {
            const values = {
                'bills_10000': 10000,
                'bills_5000': 5000,
                'bills_1000': 1000,
                'coins_500': 500,
                'coins_100': 100,
                'coins_50': 50,
                'coins_10': 10,
                'coins_5': 5,
                'coins_1': 1
            };
            
            const count = parseInt(document.getElementById(denominationId).value) || 0;
            const amount = count * values[denominationId];
            const amountId = denominationId.replace('bills_', 'amount_').replace('coins_', 'amount_');
            document.getElementById(amountId).textContent = '¥' + amount.toLocaleString();
            
            updateTotalAmount();
        }

        // 合計金額更新
        function updateTotalAmount() {
            const denominations = [
                { id: 'bills_10000', value: 10000 },
                { id: 'bills_5000', value: 5000 },
                { id: 'bills_1000', value: 1000 },
                { id: 'coins_500', value: 500 },
                { id: 'coins_100', value: 100 },
                { id: 'coins_50', value: 50 },
                { id: 'coins_10', value: 10 },
                { id: 'coins_5', value: 5 },
                { id: 'coins_1', value: 1 }
            ];
            
            let totalCash = 0;
            denominations.forEach(denom => {
                const count = parseInt(document.getElementById(denom.id).value) || 0;
                totalCash += count * denom.value;
            });
            
            const changeAmount = parseInt(document.getElementById('change_amount').value) || 0;
            const netAmount = totalCash - changeAmount;
            
            document.getElementById('totalCashAmount').textContent = '¥' + totalCash.toLocaleString();
            document.getElementById('netAmount').textContent = '¥' + netAmount.toLocaleString();
            document.getElementById('netAmountDisplay').textContent = '¥' + netAmount.toLocaleString();
            
            // 差額計算
            const calculatedAmount = parseInt(document.getElementById('modalCalculatedAmount').textContent.replace(/[¥,]/g, '')) || 0;
            const difference = netAmount - calculatedAmount;
            
            const diffDisplay = document.getElementById('differenceDisplay');
            if (difference > 0) {
                diffDisplay.textContent = '+¥' + difference.toLocaleString();
                diffDisplay.className = 'difference-positive';
            } else if (difference < 0) {
                diffDisplay.textContent = '¥' + difference.toLocaleString();
                diffDisplay.className = 'difference-negative';
            } else {
                diffDisplay.textContent = '¥0';
                diffDisplay.className = 'difference-zero';
            }
        }

        // イベントリスナー設定
        document.addEventListener('DOMContentLoaded', function() {
            // 金種入力のイベントリスナー
            const denominations = ['bills_10000', 'bills_5000', 'bills_1000', 'coins_500', 'coins_100', 'coins_50', 'coins_10', 'coins_5', 'coins_1'];
            denominations.forEach(id => {
                document.getElementById(id).addEventListener('input', function() {
                    updateDenominationAmount(id);
                });
            });
            
            // おつり金額のイベントリスナー
            document.getElementById('change_amount').addEventListener('input', updateTotalAmount);
        });
    </script>
</body>
</html>
