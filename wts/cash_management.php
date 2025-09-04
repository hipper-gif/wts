<?php
session_start();
require_once 'config/database.php';

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$pdo = getDBConnection();

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT permission_level, NAME, is_driver, is_admin FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// 権限確認（運転者またはAdmin）
if (!$user['is_driver'] && $user['permission_level'] !== 'Admin') {
    header('Location: dashboard.php');
    exit();
}

$today = date('Y-m-d');
$message = '';

// POST処理：現金カウント保存
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_count') {
    try {
        $pdo->beginTransaction();
        
        // 金種別枚数取得
        $bill_5000 = intval($_POST['bill_5000'] ?? 0);
        $bill_1000 = intval($_POST['bill_1000'] ?? 0);
        $coin_500 = intval($_POST['coin_500'] ?? 0);
        $coin_100 = intval($_POST['coin_100'] ?? 0);
        $coin_50 = intval($_POST['coin_50'] ?? 0);
        $coin_10 = intval($_POST['coin_10'] ?? 0);
        
        // 合計金額計算
        $total_amount = ($bill_5000 * 5000) + ($bill_1000 * 1000) + 
                       ($coin_500 * 500) + ($coin_100 * 100) + 
                       ($coin_50 * 50) + ($coin_10 * 10);
        
        // 基準おつり（18,000円）
        $base_change = 18000;
        $deposit_amount = $total_amount - $base_change;
        
        // システム売上取得
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_fare), 0) as system_revenue,
                   COUNT(*) as ride_count
            FROM ride_records 
            WHERE ride_date = ? AND payment_method = '現金'
        ");
        $stmt->execute([$today]);
        $system_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $expected_amount = $base_change + $system_data['system_revenue'];
        $difference = $total_amount - $expected_amount;
        
        // 既存レコード確認
        $stmt = $pdo->prepare("SELECT id FROM cash_count_details WHERE confirmation_date = ? AND driver_id = ?");
        $stmt->execute([$today, $_SESSION['user_id']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // 更新
            $stmt = $pdo->prepare("
                UPDATE cash_count_details SET 
                    bill_5000 = ?, bill_1000 = ?, coin_500 = ?, coin_100 = ?, coin_50 = ?, coin_10 = ?,
                    total_amount = ?, memo = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $bill_5000, $bill_1000, $coin_500, $coin_100, $coin_50, $coin_10,
                $total_amount, $_POST['memo'] ?? '', $existing['id']
            ]);
        } else {
            // 新規登録
            $stmt = $pdo->prepare("
                INSERT INTO cash_count_details 
                (confirmation_date, driver_id, bill_5000, bill_1000, coin_500, coin_100, coin_50, coin_10, 
                 total_amount, memo, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $today, $_SESSION['user_id'], $bill_5000, $bill_1000, $coin_500, 
                $coin_100, $coin_50, $coin_10, $total_amount, $_POST['memo'] ?? ''
            ]);
        }
        
        // 集金確認テーブルにも記録
        $stmt = $pdo->prepare("SELECT id FROM cash_confirmations WHERE confirmation_date = ? AND driver_id = ?");
        $stmt->execute([$today, $_SESSION['user_id']]);
        $existing_confirmation = $stmt->fetch();
        
        if ($existing_confirmation) {
            $stmt = $pdo->prepare("
                UPDATE cash_confirmations SET 
                    confirmed_amount = ?, calculated_amount = ?, difference = ?, 
                    memo = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $total_amount, $expected_amount, $difference, 
                $_POST['memo'] ?? '', $existing_confirmation['id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cash_confirmations 
                (confirmation_date, driver_id, confirmed_amount, calculated_amount, difference, 
                 change_stock, memo, confirmed_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $today, $_SESSION['user_id'], $total_amount, $expected_amount, 
                $difference, $base_change, $_POST['memo'] ?? '', $_SESSION['user_id']
            ]);
        }
        
        $pdo->commit();
        $message = '現金カウントを保存しました。';
        
    } catch (Exception $e) {
        $pdo->rollback();
        $message = 'エラーが発生しました: ' . $e->getMessage();
    }
}

// 既存データ取得
$stmt = $pdo->prepare("SELECT * FROM cash_count_details WHERE confirmation_date = ? AND driver_id = ?");
$stmt->execute([$today, $_SESSION['user_id']]);
$existing_count = $stmt->fetch(PDO::FETCH_ASSOC);

// 本日の売上データ取得
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(total_fare), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN payment_method = '現金' THEN total_fare ELSE 0 END), 0) as cash_revenue,
        COALESCE(SUM(CASE WHEN payment_method = 'カード' THEN total_fare ELSE 0 END), 0) as card_revenue,
        COUNT(*) as ride_count
    FROM ride_records 
    WHERE ride_date = ?
");
$stmt->execute([$today]);
$revenue_data = $stmt->fetch(PDO::FETCH_ASSOC);

// 基準おつり設定
$base_counts = [
    'bill_5000' => 1,   // 5,000円
    'bill_1000' => 10,  // 10,000円
    'coin_500' => 3,    // 1,500円
    'coin_100' => 11,   // 1,100円
    'coin_50' => 5,     // 250円
    'coin_10' => 15     // 150円
];

// 現在のカウント（既存データがあれば使用、なければ基準値）
$current_counts = $existing_count ? [
    'bill_5000' => $existing_count['bill_5000'],
    'bill_1000' => $existing_count['bill_1000'],
    'coin_500' => $existing_count['coin_500'],
    'coin_100' => $existing_count['coin_100'],
    'coin_50' => $existing_count['coin_50'],
    'coin_10' => $existing_count['coin_10']
] : $base_counts;

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>売上金確認 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            --shadow: 0 4px 15px rgba(0,0,0,0.1);
            --border-radius: 15px;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ヘッダー */
        .system-header {
            background: var(--primary-gradient);
            color: white;
            padding: 15px 0;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .page-header h2 {
            margin: 0;
            color: #2c3e50;
            font-weight: 600;
        }

        /* カウントカード */
        .count-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .denomination-row {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .denomination-row:last-child {
            border-bottom: none;
        }

        .denom-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2rem;
            color: white;
        }

        .bill-icon {
            background: var(--success-gradient);
        }

        .coin-icon {
            background: var(--warning-gradient);
        }

        .denom-info {
            flex: 1;
        }

        .denom-value {
            font-weight: 600;
            font-size: 1.1rem;
            color: #2c3e50;
        }

        .denom-base {
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .count-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .count-btn {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .count-btn:hover {
            transform: scale(1.1);
        }

        .count-input {
            width: 80px;
            height: 40px;
            text-align: center;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .amount-display {
            width: 100px;
            text-align: right;
            font-weight: 600;
            color: #27ae60;
            font-size: 1.1rem;
        }

        .difference-display {
            font-size: 0.9rem;
            margin-left: 10px;
        }

        .difference-positive {
            color: #e74c3c;
        }

        .difference-negative {
            color: #3498db;
        }

        /* サマリーカード */
        .summary-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            border-left: 4px solid #3498db;
        }

        .summary-amount {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-label {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .deposit-card {
            border-left-color: #27ae60;
        }

        .deposit-card .summary-amount {
            color: #27ae60;
        }

        .system-card {
            border-left-color: #e74c3c;
        }

        .system-card .summary-amount {
            color: #e74c3c;
        }

        /* 差額表示 */
        .difference-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .difference-amount {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .difference-zero {
            color: #27ae60;
        }

        .difference-plus {
            color: #e74c3c;
        }

        .difference-minus {
            color: #3498db;
        }

        /* アクションボタン */
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .btn-reset {
            background: var(--warning-gradient);
            border: none;
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            flex: 1;
        }

        .btn-save {
            background: var(--success-gradient);
            border: none;
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            flex: 2;
        }

        /* フロー完了 */
        .flow-complete {
            background: var(--success-gradient);
            color: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }

        /* レスポンシブ */
        @media (max-width: 430px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .count-input-group {
                flex-wrap: wrap;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }

        /* メッセージ */
        .message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            max-width: 300px;
            padding: 15px;
            border-radius: var(--border-radius);
            color: white;
            background: var(--success-gradient);
            box-shadow: var(--shadow);
        }
    </style>
</head>
<body>
    <!-- システムヘッダー -->
    <div class="system-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-taxi me-2"></i>スマイリーケアタクシー</h1>
                </div>
                <div class="col-auto">
                    <a href="dashboard.php" class="text-white text-decoration-none">
                        <i class="fas fa-tachometer-alt me-1"></i>ダッシュボード
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- ページヘッダー -->
        <div class="page-header">
            <h2><i class="fas fa-yen-sign me-3"></i>売上金確認（現金カウント）</h2>
            <p class="mb-0 text-muted">
                <i class="fas fa-calendar me-2"></i><?= date('Y年m月d日') ?>
                <span class="ms-3"><i class="fas fa-user me-2"></i><?= htmlspecialchars($user['NAME']) ?></span>
            </p>
        </div>

        <?php if ($message): ?>
        <div class="message" id="message">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- 本日の売上サマリー -->
        <div class="count-section">
            <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i>本日の売上</h5>
            <div class="row">
                <div class="col-4 text-center">
                    <div class="summary-amount" style="color: #3498db;">¥<?= number_format($revenue_data['total_revenue']) ?></div>
                    <div class="summary-label">総売上</div>
                </div>
                <div class="col-4 text-center">
                    <div class="summary-amount" style="color: #27ae60;">¥<?= number_format($revenue_data['cash_revenue']) ?></div>
                    <div class="summary-label">現金売上</div>
                </div>
                <div class="col-4 text-center">
                    <div class="summary-amount" style="color: #e74c3c;"><?= $revenue_data['ride_count'] ?>回</div>
                    <div class="summary-label">乗車回数</div>
                </div>
            </div>
        </div>

        <!-- 現金カウント -->
        <form method="POST" id="countForm">
            <input type="hidden" name="action" value="save_count">
            
            <div class="count-section">
                <h5 class="mb-3"><i class="fas fa-calculator me-2"></i>現金カウント</h5>
                
                <!-- 5千円札 -->
                <div class="denomination-row">
                    <div class="denom-icon bill-icon">
                        <i class="fas fa-money-bill"></i>
                    </div>
                    <div class="denom-info">
                        <div class="denom-value">5千円札</div>
                        <div class="denom-base">基準: <?= $base_counts['bill_5000'] ?>枚</div>
                    </div>
                    <div class="count-input-group">
                        <button type="button" class="count-btn" onclick="adjustCount('bill_5000', -1)">-</button>
                        <input type="number" name="bill_5000" id="bill_5000" class="count-input" 
                               value="<?= $current_counts['bill_5000'] ?>" min="0" 
                               onchange="calculateTotal()">
                        <button type="button" class="count-btn" onclick="adjustCount('bill_5000', 1)">+</button>
                        <div class="amount-display" id="amount_5000">¥<?= number_format($current_counts['bill_5000'] * 5000) ?></div>
                        <div class="difference-display" id="diff_5000"></div>
                    </div>
                </div>

                <!-- 千円札 -->
                <div class="denomination-row">
                    <div class="denom-icon bill-icon">
                        <i class="fas fa-money-bill"></i>
                    </div>
                    <div class="denom-info">
                        <div class="denom-value">千円札</div>
                        <div class="denom-base">基準: <?= $base_counts['bill_1000'] ?>枚</div>
                    </div>
                    <div class="count-input-group">
                        <button type="button" class="count-btn" onclick="adjustCount('bill_1000', -1)">-</button>
                        <input type="number" name="bill_1000" id="bill_1000" class="count-input" 
                               value="<?= $current_counts['bill_1000'] ?>" min="0" 
                               onchange="calculateTotal()">
                        <button type="button" class="count-btn" onclick="adjustCount('bill_1000', 1)">+</button>
                        <div class="amount-display" id="amount_1000">¥<?= number_format($current_counts['bill_1000'] * 1000) ?></div>
                        <div class="difference-display" id="diff_1000"></div>
                    </div>
                </div>

                <!-- 500円玉 -->
                <div class="denomination-row">
                    <div class="denom-icon coin-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="denom-info">
                        <div class="denom-value">500円玉</div>
                        <div class="denom-base">基準: <?= $base_counts['coin_500'] ?>枚</div>
                    </div>
                    <div class="count-input-group">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_500', -1)">-</button>
                        <input type="number" name="coin_500" id="coin_500" class="count-input" 
                               value="<?= $current_counts['coin_500'] ?>" min="0" 
                               onchange="calculateTotal()">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_500', 1)">+</button>
                        <div class="amount-display" id="amount_500">¥<?= number_format($current_counts['coin_500'] * 500) ?></div>
                        <div class="difference-display" id="diff_500"></div>
                    </div>
                </div>

                <!-- 100円玉 -->
                <div class="denomination-row">
                    <div class="denom-icon coin-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="denom-info">
                        <div class="denom-value">100円玉</div>
                        <div class="denom-base">基準: <?= $base_counts['coin_100'] ?>枚</div>
                    </div>
                    <div class="count-input-group">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_100', -1)">-</button>
                        <input type="number" name="coin_100" id="coin_100" class="count-input" 
                               value="<?= $current_counts['coin_100'] ?>" min="0" 
                               onchange="calculateTotal()">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_100', 1)">+</button>
                        <div class="amount-display" id="amount_100">¥<?= number_format($current_counts['coin_100'] * 100) ?></div>
                        <div class="difference-display" id="diff_100"></div>
                    </div>
                </div>

                <!-- 50円玉 -->
                <div class="denomination-row">
                    <div class="denom-icon coin-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="denom-info">
                        <div class="denom-value">50円玉</div>
                        <div class="denom-base">基準: <?= $base_counts['coin_50'] ?>枚</div>
                    </div>
                    <div class="count-input-group">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_50', -1)">-</button>
                        <input type="number" name="coin_50" id="coin_50" class="count-input" 
                               value="<?= $current_counts['coin_50'] ?>" min="0" 
                               onchange="calculateTotal()">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_50', 1)">+</button>
                        <div class="amount-display" id="amount_50">¥<?= number_format($current_counts['coin_50'] * 50) ?></div>
                        <div class="difference-display" id="diff_50"></div>
                    </div>
                </div>

                <!-- 10円玉 -->
                <div class="denomination-row">
                    <div class="denom-icon coin-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="denom-info">
                        <div class="denom-value">10円玉</div>
                        <div class="denom-base">基準: <?= $base_counts['coin_10'] ?>枚</div>
                    </div>
                    <div class="count-input-group">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_10', -1)">-</button>
                        <input type="number" name="coin_10" id="coin_10" class="count-input" 
                               value="<?= $current_counts['coin_10'] ?>" min="0" 
                               onchange="calculateTotal()">
                        <button type="button" class="count-btn" onclick="adjustCount('coin_10', 1)">+</button>
                        <div class="amount-display" id="amount_10">¥<?= number_format($current_counts['coin_10'] * 10) ?></div>
                        <div class="difference-display" id="diff_10"></div>
                    </div>
                </div>
            </div>

            <!-- 計算結果表示 -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="summary-amount" id="total_amount">¥0</div>
                    <div class="summary-label">カウント合計</div>
                </div>
                <div class="summary-card deposit-card">
                    <div class="summary-amount" id="deposit_amount">¥0</div>
                    <div class="summary-label">入金額</div>
                    <small class="text-muted">(合計 - 基準18,000円)</small>
                </div>
            </div>

            <!-- 差額表示 -->
            <div class="difference-card">
                <div>予想金額: ¥<span id="expected_amount"><?= number_format(18000 + $revenue_data['cash_revenue']) ?></span></div>
                <div class="difference-amount" id="difference_amount">¥0</div>
                <div class="summary-label">実際差額</div>
                <small class="text-muted">基準18,000円 + 現金売上<?= number_format($revenue_data['cash_revenue']) ?>円 との差</small>
            </div>

            <!-- メモ欄 -->
            <div class="count-section">
                <h6><i class="fas fa-sticky-note me-2"></i>メモ・差額理由</h6>
                <textarea name="memo" class="form-control" rows="3" placeholder="差額がある場合は理由を記入してください"><?= $existing_count['memo'] ?? '' ?></textarea>
            </div>

            <!-- アクションボタン -->
            <div class="action-buttons">
                <button type="button" class="btn btn-reset" onclick="resetToBase()">
                    <i class="fas fa-undo me-2"></i>基準値にリセット
                </button>
                <button type="submit" class="btn btn-save">
                    <i class="fas fa-save me-2"></i>カウント保存
                </button>
            </div>
        </form>

        <!-- フロー完了表示 -->
        <?php if ($existing_count): ?>
        <div class="flow-complete">
            <i class="fas fa-check-circle fa-2x mb-3"></i>
            <h5>7. 売上金確認 完了</h5>
            <p class="mb-0">本日の日次運行フローが完了しました。お疲れ様でした。</p>
            <div class="mt-3">
                <a href="dashboard.php" class="btn btn-light me-2">
                    <i class="fas fa-tachometer-alt me-2"></i>ダッシュボード
                </a>
                <a href="cash_management.php" class="btn btn-outline-light">
                    <i class="fas fa-chart-line me-2"></i>集金管理
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- フロー進行状況 -->
        <div class="count-section">
            <h6><i class="fas fa-route me-2"></i>日次運行フロー進捗</h6>
            <div class="row g-2">
                <div class="col-6">
                    <div class="d-flex align-items-center p-2 bg-light rounded">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        <small>1-6. 基本業務完了</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="d-flex align-items-center p-2 rounded <?= $existing_count ? 'bg-success text-white' : 'bg-warning' ?>">
                        <i class="fas fa-<?= $existing_count ? 'check-circle' : 'clock' ?> me-2"></i>
                        <small>7. 売上金確認 <?= $existing_count ? '完了' : '実施中' ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 基準値定義
        const baseCounts = {
            'bill_5000': <?= $base_counts['bill_5000'] ?>,
            'bill_1000': <?= $base_counts['bill_1000'] ?>,
            'coin_500': <?= $base_counts['coin_500'] ?>,
            'coin_100': <?= $base_counts['coin_100'] ?>,
            'coin_50': <?= $base_counts['coin_50'] ?>,
            'coin_10': <?= $base_counts['coin_10'] ?>
        };

        const denomValues = {
            'bill_5000': 5000,
            'bill_1000': 1000,
            'coin_500': 500,
            'coin_100': 100,
            'coin_50': 50,
            'coin_10': 10
        };

        const baseChange = 18000;
        const systemRevenue = <?= $revenue_data['cash_revenue'] ?>;

        // カウント調整
        function adjustCount(denom, change) {
            const input = document.getElementById(denom);
            const currentValue = parseInt(input.value) || 0;
            const newValue = Math.max(0, currentValue + change);
            input.value = newValue;
            calculateTotal();
        }

        // 合計計算
        function calculateTotal() {
            let totalAmount = 0;
            
            // 各金種の計算と差異表示
            Object.keys(denomValues).forEach(denom => {
                const count = parseInt(document.getElementById(denom).value) || 0;
                const amount = count * denomValues[denom];
                const difference = count - baseCounts[denom];
                
                // 金額表示更新
                document.getElementById('amount_' + denom.split('_')[1]).textContent = 
                    '¥' + amount.toLocaleString();
                
                // 差異表示更新
                const diffElement = document.getElementById('diff_' + denom.split('_')[1]);
                if (difference > 0) {
                    diffElement.textContent = '+' + difference;
                    diffElement.className = 'difference-display difference-positive';
                } else if (difference < 0) {
                    diffElement.textContent = difference.toString();
                    diffElement.className = 'difference-display difference-negative';
                } else {
                    diffElement.textContent = '';
                    diffElement.className = 'difference-display';
                }
                
                totalAmount += amount;
            });

            // 合計金額表示
            document.getElementById('total_amount').textContent = 
                '¥' + totalAmount.toLocaleString();

            // 入金額計算（合計 - 基準おつり）
            const depositAmount = totalAmount - baseChange;
            document.getElementById('deposit_amount').textContent = 
                '¥' + depositAmount.toLocaleString();

            // 差額計算
            const expectedAmount = baseChange + systemRevenue;
            const difference = totalAmount - expectedAmount;
            const diffElement = document.getElementById('difference_amount');
            
            diffElement.textContent = '¥' + Math.abs(difference).toLocaleString();
            
            if (difference === 0) {
                diffElement.className = 'difference-amount difference-zero';
                diffElement.textContent = '差額なし';
            } else if (difference > 0) {
                diffElement.className = 'difference-amount difference-plus';
                diffElement.textContent = '+¥' + difference.toLocaleString();
            } else {
                diffElement.className = 'difference-amount difference-minus';
                diffElement.textContent = '-¥' + Math.abs(difference).toLocaleString();
            }
        }

        // 基準値リセット
        function resetToBase() {
            if (confirm('基準値にリセットしますか？')) {
                Object.keys(baseCounts).forEach(denom => {
                    document.getElementById(denom).value = baseCounts[denom];
                });
                calculateTotal();
            }
        }

        // ページ読み込み時に計算実行
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotal();
            
            // メッセージ自動非表示
            const message = document.getElementById('message');
            if (message) {
                setTimeout(() => {
                    message.style.opacity = '0';
                    setTimeout(() => message.remove(), 300);
                }, 3000);
            }

            // フォーム送信前確認
            document.getElementById('countForm').addEventListener('submit', function(e) {
                const totalAmount = parseInt(document.getElementById('total_amount').textContent.replace(/[¥,]/g, ''));
                const depositAmount = parseInt(document.getElementById('deposit_amount').textContent.replace(/[¥,]/g, ''));
                
                if (!confirm(`現金カウントを保存しますか？\n\nカウント合計: ¥${totalAmount.toLocaleString()}\n入金額: ¥${depositAmount.toLocaleString()}`)) {
                    e.preventDefault();
                }
            });

            // タッチデバイス最適化
            const buttons = document.querySelectorAll('.count-btn');
            buttons.forEach(button => {
                button.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.95)';
                }, {passive: true});
                
                button.addEventListener('touchend', function() {
                    this.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                }, {passive: true});
            });

            // 入力フィールドのフォーカス時選択
            const inputs = document.querySelectorAll('.count-input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.select();
                });
            });
        });

        // キーボードショートカット
        document.addEventListener('keydown', function(e) {
            // Ctrl + R で基準値リセット
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                resetToBase();
            }
            
            // Ctrl + S で保存
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('countForm').submit();
            }
        });
    </script>
</body>
</html>
