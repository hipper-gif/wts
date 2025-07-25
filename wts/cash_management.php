<?php
session_start();

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// データベース接続
require_once 'config/database.php';

// 共通関数読み込み（作成した場合）
if (file_exists('functions.php')) {
    require_once 'functions.php';
}

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ユーザー情報取得（改善版）
$stmt = $pdo->prepare("SELECT name, role, is_caller, is_admin FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// 権限チェック（点呼者または管理者のみアクセス可能）
if (!$user['is_caller'] && !$user['is_admin']) {
    header('Location: dashboard.php?error=' . urlencode('集金管理機能を使用する権限がありません。'));
    exit();
}

// 必要なテーブルを作成
try {
    // 集金確認テーブル
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
            INDEX idx_confirmation_date (confirmation_date),
            FOREIGN KEY (confirmed_by) REFERENCES users(id)
        )
    ");
    
    // 月次集計テーブル
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS monthly_summaries (
            id INT PRIMARY KEY AUTO_INCREMENT,
            summary_month VARCHAR(7) NOT NULL UNIQUE, -- YYYY-MM
            total_rides INT DEFAULT 0,
            total_amount INT DEFAULT 0,
            cash_amount INT DEFAULT 0,
            card_amount INT DEFAULT 0,
            other_amount INT DEFAULT 0,
            confirmed_days INT DEFAULT 0,
            unconfirmed_days INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_summary_month (summary_month)
        )
    ");
} catch (PDOException $e) {
    error_log("Table creation error: " . $e->getMessage());
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
                    $memo = trim($_POST['memo'] ?? '');
                    
                    // 集金確認記録を保存（改善版）
                    $stmt = $pdo->prepare("
                        INSERT INTO cash_confirmations 
                        (confirmation_date, confirmed_amount, calculated_amount, difference, memo, confirmed_by, created_at) 
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
                    
                    // 月次集計を更新
                    updateMonthlySummary($pdo, date('Y-m', strtotime($date)));
                    
                    $message = "現金確認を記録しました。";
                    if ($difference != 0) {
                        $message .= " 差額: " . ($difference > 0 ? '+' : '') . "¥" . number_format($difference);
                    }
                    break;
                    
                case 'export_daily_report':
                    // 日次レポート出力
                    $report_date = $_POST['report_date'];
                    exportDailyReport($pdo, $report_date);
                    exit();
                    break;
                    
                case 'export_monthly_report':
                    // 月次レポート出力
                    $report_month = $_POST['report_month'];
                    exportMonthlyReport($pdo, $report_month);
                    exit();
                    break;
                    
                case 'bulk_confirm':
                    // 一括現金確認処理
                    $start_date = $_POST['start_date'];
                    $end_date = $_POST['end_date'];
                    $confirmed_count = bulkCashConfirm($pdo, $start_date, $end_date, $_SESSION['user_id']);
                    $message = "{$confirmed_count}件の現金確認を一括実行しました。";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
        error_log("Cash management error: " . $e->getMessage());
    }
}

// データ取得関数群

/**
 * 日次売上データ取得（改善版）
 */
function getDailySales($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(fare_amount) as total_amount,
            AVG(fare_amount) as avg_amount,
            MIN(fare_amount) as min_amount,
            MAX(fare_amount) as max_amount
        FROM ride_records 
        WHERE DATE(ride_date) = ? 
        GROUP BY payment_method
        ORDER BY 
            CASE payment_method 
                WHEN '現金' THEN 1 
                WHEN 'カード' THEN 2 
                ELSE 3 
            END
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 日次合計取得（改善版）
 */
function getDailyTotal($pdo, $date) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            SUM(fare_amount) as total_amount,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card_amount,
            SUM(CASE WHEN payment_method = 'その他' THEN fare_amount ELSE 0 END) as other_amount,
            COUNT(DISTINCT driver_id) as active_drivers,
            COUNT(DISTINCT vehicle_id) as active_vehicles
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ");
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 月次集計データ取得（改善版）
 */
function getMonthlySummary($pdo, $month) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(ride_date) as date,
            COUNT(*) as rides,
            SUM(fare_amount) as total,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card,
            SUM(CASE WHEN payment_method = 'その他' THEN fare_amount ELSE 0 END) as other,
            CASE WHEN cc.id IS NOT NULL THEN 1 ELSE 0 END as is_confirmed
        FROM ride_records rr
        LEFT JOIN cash_confirmations cc ON DATE(rr.ride_date) = cc.confirmation_date
        WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
        GROUP BY DATE(ride_date)
        ORDER BY date
    ");
    $stmt->execute([$month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 集金確認記録取得
 */
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

/**
 * 未確認日数取得
 */
function getUnconfirmedDays($pdo, $month) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT DATE(ride_date)) as unconfirmed_days
        FROM ride_records 
        WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
        AND DATE(ride_date) NOT IN (
            SELECT confirmation_date FROM cash_confirmations 
            WHERE DATE_FORMAT(confirmation_date, '%Y-%m') = ?
        )
    ");
    $stmt->execute([$month, $month]);
    return $stmt->fetchColumn();
}

/**
 * 月次集計更新
 */
function updateMonthlySummary($pdo, $month) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            SUM(fare_amount) as total_amount,
            SUM(CASE WHEN payment_method = '現金' THEN fare_amount ELSE 0 END) as cash_amount,
            SUM(CASE WHEN payment_method = 'カード' THEN fare_amount ELSE 0 END) as card_amount,
            SUM(CASE WHEN payment_method = 'その他' THEN fare_amount ELSE 0 END) as other_amount
        FROM ride_records 
        WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
    ");
    $stmt->execute([$month]);
    $monthly_data = $stmt->fetch();
    
    $confirmed_days = $pdo->prepare("
        SELECT COUNT(*) FROM cash_confirmations 
        WHERE DATE_FORMAT(confirmation_date, '%Y-%m') = ?
    ");
    $confirmed_days->execute([$month]);
    $confirmed_count = $confirmed_days->fetchColumn();
    
    $unconfirmed_count = getUnconfirmedDays($pdo, $month);
    
    $stmt = $pdo->prepare("
        INSERT INTO monthly_summaries 
        (summary_month, total_rides, total_amount, cash_amount, card_amount, other_amount, confirmed_days, unconfirmed_days)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        total_rides = VALUES(total_rides),
        total_amount = VALUES(total_amount),
        cash_amount = VALUES(cash_amount),
        card_amount = VALUES(card_amount),
        other_amount = VALUES(other_amount),
        confirmed_days = VALUES(confirmed_days),
        unconfirmed_days = VALUES(unconfirmed_days),
        updated_at = NOW()
    ");
    
    $stmt->execute([
        $month,
        $monthly_data['total_rides'] ?? 0,
        $monthly_data['total_amount'] ?? 0,
        $monthly_data['cash_amount'] ?? 0,
        $monthly_data['card_amount'] ?? 0,
        $monthly_data['other_amount'] ?? 0,
        $confirmed_count,
        $unconfirmed_count
    ]);
}

/**
 * 一括現金確認
 */
function bulkCashConfirm($pdo, $start_date, $end_date, $user_id) {
    $confirmed_count = 0;
    $current_date = $start_date;
    
    while ($current_date <= $end_date) {
        // その日の売上データを取得
        $daily_total = getDailyTotal($pdo, $current_date);
        
        if ($daily_total['total_rides'] > 0) {
            // 既に確認済みでない場合のみ処理
            $existing = getCashConfirmation($pdo, $current_date);
            
            if (!$existing) {
                $stmt = $pdo->prepare("
                    INSERT INTO cash_confirmations 
                    (confirmation_date, confirmed_amount, calculated_amount, difference, memo, confirmed_by) 
                    VALUES (?, ?, ?, 0, '一括確認', ?)
                ");
                $stmt->execute([
                    $current_date,
                    $daily_total['cash_amount'],
                    $daily_total['cash_amount'],
                    $user_id
                ]);
                $confirmed_count++;
            }
        }
        
        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
    }
    
    return $confirmed_count;
}

/**
 * 日次レポート出力
 */
function exportDailyReport($pdo, $date) {
    $filename = "daily_cash_report_" . str_replace('-', '', $date) . ".csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    
    $output = fopen('php://output', 'w');
    
    // ヘッダー
    fputcsv($output, ['日次集金レポート - ' . date('Y年m月d日', strtotime($date))]);
    fputcsv($output, []);
    
    // 売上サマリー
    $daily_total = getDailyTotal($pdo, $date);
    fputcsv($output, ['売上サマリー']);
    fputcsv($output, ['項目', '金額', '回数']);
    fputcsv($output, ['総売上', $daily_total['total_amount'], $daily_total['total_rides']]);
    fputcsv($output, ['現金', $daily_total['cash_amount'], '']);
    fputcsv($output, ['カード', $daily_total['card_amount'], '']);
    fputcsv($output, ['その他', $daily_total['other_amount'], '']);
    fputcsv($output, []);
    
    // 現金確認
    $confirmation = getCashConfirmation($pdo, $date);
    if ($confirmation) {
        fputcsv($output, ['現金確認']);
        fputcsv($output, ['確認金額', $confirmation['confirmed_amount']]);
        fputcsv($output, ['計算金額', $confirmation['calculated_amount']]);
        fputcsv($output, ['差額', $confirmation['difference']]);
        fputcsv($output, ['確認者', $confirmation['confirmed_by_name']]);
        fputcsv($output, ['メモ', $confirmation['memo']]);
    }
    
    fclose($output);
}

/**
 * 月次レポート出力
 */
function exportMonthlyReport($pdo, $month) {
    $filename = "monthly_cash_report_" . str_replace('-', '', $month) . ".csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    
    $output = fopen('php://output', 'w');
    
    // ヘッダー
    fputcsv($output, ['月次集金レポート - ' . date('Y年m月', strtotime($month . '-01'))]);
    fputcsv($output, []);
    fputcsv($output, ['日付', '乗車回数', '総売上', '現金', 'カード', 'その他', '確認状況']);
    
    $monthly_data = getMonthlySummary($pdo, $month);
    $total_rides = 0;
    $total_amount = 0;
    $total_cash = 0;
    $total_card = 0;
    $total_other = 0;
    $confirmed_days = 0;
    
    foreach ($monthly_data as $day) {
        fputcsv($output, [
            date('m/d', strtotime($day['date'])),
            $day['rides'],
            $day['total'],
            $day['cash'],
            $day['card'],
            $day['other'],
            $day['is_confirmed'] ? '確認済み' : '未確認'
        ]);
        
        $total_rides += $day['rides'];
        $total_amount += $day['total'];
        $total_cash += $day['cash'];
        $total_card += $day['card'];
        $total_other += $day['other'];
        if ($day['is_confirmed']) $confirmed_days++;
    }
    
    fputcsv($output, []);
    fputcsv($output, ['合計', $total_rides, $total_amount, $total_cash, $total_card, $total_other, $confirmed_days . '日確認済み']);
    
    fclose($output);
}

// データ取得
$daily_sales = getDailySales($pdo, $selected_date);
$daily_total = getDailyTotal($pdo, $selected_date);
$monthly_summary = getMonthlySummary($pdo, $selected_month);
$cash_confirmation = getCashConfirmation($pdo, $selected_date);
$unconfirmed_days = getUnconfirmedDays($pdo, $selected_month);

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
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand {
            font-weight: bold;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            margin-bottom: 1.5rem;
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
            font-size: 1.8rem;
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
        .unconfirmed-alert {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            border-radius: 10px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1rem 1.5rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            transform: translateY(-1px);
        }
    </style>
</head>

<body>
    <!-- ナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-calculator me-2"></i>集金管理システム
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

        <!-- 未確認アラート -->
        <?php if ($unconfirmed_days > 0): ?>
        <div class="alert unconfirmed-alert" role="alert">
            <div class="row align-items-center">
                <div class="col">
                    <h5 class="mb-1"><i class="fas fa-exclamation-triangle me-2"></i>未確認の日があります</h5>
                    <p class="mb-0">
                        <?= date('Y年m月', strtotime($selected_month . '-01')) ?>に
                        <strong><?= $unconfirmed_days ?>日分</strong>の現金確認が未完了です。
                    </p>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-light" onclick="showBulkConfirmModal()">
                        <i class="fas fa-check-double me-1"></i>一括確認
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 日付・月選択 -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title"><i class="fas fa-calendar-day me-2"></i>日次集計</h6>
                        <form method="GET" class="d-flex">
                            <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" class="form-control me-2">
                            <input type="hidden" name="month" value="<?= htmlspecialchars($selected_month) ?>">
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
                        <small><?= $daily_total['total_rides'] ?? 0 ?>回 (<?= $daily_total['active_drivers'] ?? 0 ?>名)</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card cash-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($daily_total['cash_amount'] ?? 0) ?></div>
                        <div>現金売上</div>
                        <small>要確認</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($daily_total['card_amount'] ?? 0) ?></div>
                        <div>カード売上</div>
                        <small>自動確認</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card other-card">
                    <div class="card-body text-center">
                        <div class="amount-display">¥<?= number_format($daily_total['other_amount'] ?? 0) ?></div>
                        <div>その他</div>
                        <small>要確認</small>
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
                                <form method="POST" class="d-inline ms-2">
                                    <input type="hidden" name="action" value="export_daily_report">
                                    <input type="hidden" name="report_date" value="<?= htmlspecialchars($selected_date) ?>">
                                    <button type="submit" class="btn btn-outline-success">
                                        <i class="fas fa-download me-1"></i>日次レポート出力
                                    </button>
                                </form>
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
                            
                            <?php if (($daily_total['total_rides'] ?? 0) == 0): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    この日は乗車記録がありません。
                                </div>
                            <?php else: ?>
                                <form method="POST" id="cashConfirmForm">
                                    <input type="hidden" name="action" value="confirm_cash">
                                    <input type="hidden" name="target_date" value="<?= htmlspecialchars($selected_date) ?>">
                                    <input type="hidden" name="calculated_amount" value="<?= $daily_total['cash_amount'] ?? 0 ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <label class="form-label">計算上の現金売上</label>
                                            <div class="fs-4 text-primary">¥<?= number_format($daily_total['cash_amount'] ?? 0) ?></div>
                                            <small class="text-muted">乗車記録より算出</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="confirmed_amount" class="form-label">実際の現金額 <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" id="confirmed_amount" name="confirmed_amount" 
                                                   value="<?= $daily_total['cash_amount'] ?? 0 ?>" required>
                                            <small class="text-muted">実際に手元にある現金額</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">差額</label>
                                            <div class="fs-4" id="difference_display">¥0</div>
                                            <small class="text-muted" id="difference_explanation">一致しています</small>
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
                                        <button type="button" class="btn btn-outline-secondary ms-2" onclick="autoFillAmount()">
                                            <i class="fas fa-equals me-1"></i>計算額と同額で確認
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 詳細売上データ -->
        <?php if (!empty($daily_sales)): ?>
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
                                        <th class="text-end">最低料金</th>
                                        <th class="text-end">最高料金</th>
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
                                            <td class="text-end">¥<?= number_format($sale['min_amount']) ?></td>
                                            <td class="text-end">¥<?= number_format($sale['max_amount']) ?></td>
                                            <td class="text-end">
                                                <?= round(($sale['total_amount'] / ($daily_total['total_amount'] ?: 1)) * 100, 1) ?>%
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
        <?php endif; ?>

        <!-- 月次サマリー -->
        <?php if (!empty($monthly_summary)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-chart-line me-2"></i>月次サマリー (<?= date('Y年m月', strtotime($selected_month . '-01')) ?>)</h5>
                        <div>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="export_monthly_report">
                                <input type="hidden" name="report_month" value="<?= htmlspecialchars($selected_month) ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-download me-1"></i>月次レポート出力
                                </button>
                            </form>
                        </div>
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
                                        <th class="text-center">確認状況</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $monthly_total_rides = 0;
                                    $monthly_total_amount = 0;
                                    $monthly_cash_amount = 0;
                                    $monthly_card_amount = 0;
                                    $monthly_other_amount = 0;
                                    $confirmed_count = 0;
                                    
                                    foreach ($monthly_summary as $day): 
                                        $monthly_total_rides += $day['rides'];
                                        $monthly_total_amount += $day['total'];
                                        $monthly_cash_amount += $day['cash'];
                                        $monthly_card_amount += $day['card'];
                                        $monthly_other_amount += $day['other'];
                                        if ($day['is_confirmed']) $confirmed_count++;
                                    ?>
                                        <tr class="<?= !$day['is_confirmed'] && $day['cash'] > 0 ? 'table-warning' : '' ?>">
                                            <td><?= date('m/d(D)', strtotime($day['date'])) ?></td>
                                            <td class="text-end"><?= number_format($day['rides']) ?>回</td>
                                            <td class="text-end">¥<?= number_format($day['total']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['cash']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['card']) ?></td>
                                            <td class="text-end">¥<?= number_format($day['other']) ?></td>
                                            <td class="text-center">
                                                <?php if ($day['is_confirmed']): ?>
                                                    <span class="badge bg-success">確認済み</span>
                                                <?php elseif ($day['cash'] > 0): ?>
                                                    <span class="badge bg-warning">未確認</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">-</span>
                                                <?php endif; ?>
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
                                        <th class="text-center"><?= $confirmed_count ?>日確認済み</th>
                                    </tr>
                                </tfoot>
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

    <!-- 一括確認モーダル -->
    <div class="modal fade" id="bulkConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">一括現金確認</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_confirm">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>注意:</strong> 現金額と計算額が一致しているものとして一括確認します。
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label for="bulk_start_date" class="form-label">開始日</label>
                                <input type="date" class="form-control" id="bulk_start_date" name="start_date" 
                                       value="<?= date('Y-m-01', strtotime($selected_month . '-01')) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="bulk_end_date" class="form-label">終了日</label>
                                <input type="date" class="form-control" id="bulk_end_date" name="end_date" 
                                       value="<?= date('Y-m-t', strtotime($selected_month . '-01')) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-check-double me-1"></i>一括確認実行
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 差額計算（改善版）
        document.getElementById('confirmed_amount')?.addEventListener('input', function() {
            const confirmedAmount = parseInt(this.value) || 0;
            const calculatedAmount = <?= $daily_total['cash_amount'] ?? 0 ?>;
            const difference = confirmedAmount - calculatedAmount;
            
            const diffDisplay = document.getElementById('difference_display');
            const diffExplanation = document.getElementById('difference_explanation');
            
            if (difference > 0) {
                diffDisplay.innerHTML = '+¥' + difference.toLocaleString();
                diffDisplay.className = 'fs-4 difference-positive';
                diffExplanation.textContent = '計算額より多い（要メモ記入）';
                diffExplanation.className = 'text-danger';
            } else if (difference < 0) {
                diffDisplay.innerHTML = '¥' + difference.toLocaleString();
                diffDisplay.className = 'fs-4 difference-negative';
                diffExplanation.textContent = '計算額より少ない（要メモ記入）';
                diffExplanation.className = 'text-danger';
            } else {
                diffDisplay.innerHTML = '¥0';
                diffDisplay.className = 'fs-4';
                diffExplanation.textContent = '一致しています';
                diffExplanation.className = 'text-success';
            }
        });
        
        // 自動入力
        function autoFillAmount() {
            const calculatedAmount = <?= $daily_total['cash_amount'] ?? 0 ?>;
            document.getElementById('confirmed_amount').value = calculatedAmount;
            document.getElementById('confirmed_amount').dispatchEvent(new Event('input'));
        }
        
        // 確認修正
        function editConfirmation() {
            if (confirm('現金確認記録を修正しますか？')) {
                location.reload();
            }
        }
        
        // 一括確認モーダル表示
        function showBulkConfirmModal() {
            new bootstrap.Modal(document.getElementById('bulkConfirmModal')).show();
        }
        
        // フォーム送信確認
        document.getElementById('cashConfirmForm')?.addEventListener('submit', function(e) {
            const confirmedAmount = parseInt(document.getElementById('confirmed_amount').value) || 0;
            const calculatedAmount = <?= $daily_total['cash_amount'] ?? 0 ?>;
            const difference = confirmedAmount - calculatedAmount;
            
            if (Math.abs(difference) > 0) {
                const memo = document.getElementById('memo').value.trim();
                if (!memo) {
                    alert('差額がある場合は、メモ欄に理由を記入してください。');
                    e.preventDefault();
                    return;
                }
                
                if (!confirm(`差額が${difference > 0 ? '+' : ''}¥${difference.toLocaleString()}あります。\n記録してよろしいですか？`)) {
                    e.preventDefault();
                }
            }
        });
        
        // 自動保存機能（オプション）
        let autoSaveTimer;
        document.getElementById('memo')?.addEventListener('input', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                // 自動保存処理をここに実装可能
                console.log('Auto-save memo:', this.value);
            }, 2000);
        });
    </script>
</body>
</html>
