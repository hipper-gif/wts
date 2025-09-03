<?php
/**
 * 運転者向け現金カウント機能
 * 集金管理システムの分割実装 - 運転者専用画面
 */

require_once 'config/database.php';
require_once 'includes/auth_check.php';

$pdo = getDBConnection();
$current_user = getCurrentUser();

// 権限チェック: 運転者フラグまたはAdmin権限
if (!$current_user->is_driver && $current_user->permission_level !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

// 今日の売上データ取得
function getTodayCashSales($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as trip_count,
            COALESCE(SUM(total_fare), 0) as total_sales,
            COALESCE(SUM(cash_amount), 0) as cash_sales,
            COALESCE(SUM(card_amount), 0) as card_sales
        FROM ride_records 
        WHERE ride_date = CURDATE()
        AND driver_id = ?
    ");
    $stmt->execute([$current_user->id]);
    return $stmt->fetch(PDO::FETCH_OBJ);
}

$today_sales = getTodayCashSales($pdo);

// 基準おつり構成（固定）
$base_change = [
    'bill_5000' => ['count' => 1, 'value' => 5000, 'name' => '5千円札'],
    'bill_1000' => ['count' => 10, 'value' => 1000, 'name' => '千円札'], 
    'coin_500' => ['count' => 3, 'value' => 500, 'name' => '500円玉'],
    'coin_100' => ['count' => 11, 'value' => 100, 'name' => '100円玉'],
    'coin_50' => ['count' => 5, 'value' => 50, 'name' => '50円玉'],
    'coin_10' => ['count' => 15, 'value' => 10, 'name' => '10円玉']
];
$base_total = 18000;

// 既存の今日のカウントデータ取得
$existing_count = null;
$stmt = $pdo->prepare("SELECT * FROM cash_count_details WHERE confirmation_date = CURDATE() AND driver_id = ?");
$stmt->execute([$current_user->id]);
$existing_count = $stmt->fetch(PDO::FETCH_OBJ);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>現金カウント - <?= htmlspecialchars($current_user->name) ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
        }
        
        .count-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 20px;
        }
        
        .cash-type-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        
        .cash-type-row:last-child {
            border-bottom: none;
        }
        
        .cash-info {
            flex: 1;
        }
        
        .cash-name {
            font-weight: bold;
            font-size: 16px;
            color: var(--primary-color);
        }
        
        .cash-base {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        
        .count-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .count-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            font-size: 18px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .count-btn.minus {
            background: var(--danger-color);
            color: white;
        }
        
        .count-btn.plus {
            background: var(--success-color);
            color: white;
        }
        
        .count-input {
            width: 80px;
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 8px;
        }
        
        .amount-display {
            text-align: right;
            font-weight: bold;
            font-size: 16px;
            color: var(--primary-color);
            min-width: 80px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .summary-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .summary-label {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .summary-value {
            font-size: 20px;
            font-weight: bold;
        }
        
        .difference {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
        }
        
        .difference.positive {
            background: var(--success-color);
            color: white;
        }
        
        .difference.negative {
            background: var(--danger-color);
            color: white;
        }
        
        .difference.zero {
            background: var(--info-color);
            color: white;
        }
        
        .save-btn {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            font-weight: bold;
            border: none;
            border-radius: 10px;
            background: var(--success-color);
            color: white;
            margin-top: 20px;
        }
        
        .info-badge {
            background: var(--info-color);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .count-controls {
                flex-direction: column;
                gap: 5px;
            }
            
            .cash-type-row {
                flex-direction: column;
                text-align: center;
            }
            
            .count-input {
                width: 100px;
            }
        }
    </style>
</head>
<body class="bg-light">
    <!-- ヘッダー -->
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="fas fa-wallet"></i> 現金カウント
            </span>
            <div class="d-flex">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($current_user->name) ?>
                </span>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-home"></i> ダッシュボード
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <!-- 今日の売上情報 -->
        <div class="count-card">
            <h5><i class="fas fa-chart-line text-info"></i> 今日の売上実績</h5>
            <div class="row text-center">
                <div class="col-3">
                    <div class="info-badge">回数</div>
                    <div class="h5 mt-2"><?= $today_sales->trip_count ?>回</div>
                </div>
                <div class="col-3">
                    <div class="info-badge">総売上</div>
                    <div class="h5 mt-2">¥<?= number_format($today_sales->total_sales) ?></div>
                </div>
                <div class="col-3">
                    <div class="info-badge">現金</div>
                    <div class="h5 mt-2">¥<?= number_format($today_sales->cash_sales) ?></div>
                </div>
                <div class="col-3">
                    <div class="info-badge">カード</div>
                    <div class="h5 mt-2">¥<?= number_format($today_sales->card_sales) ?></div>
                </div>
            </div>
        </div>

        <!-- 現金カウント入力 -->
        <div class="count-card">
            <h5><i class="fas fa-coins text-warning"></i> 現金カウント</h5>
            <p class="text-muted mb-3">各金種の枚数を入力してください（基準おつり: ¥18,000）</p>
            
            <?php foreach ($base_change as $type => $info): ?>
            <div class="cash-type-row">
                <div class="cash-info">
                    <div class="cash-name"><?= $info['name'] ?></div>
                    <div class="cash-base">基準: <?= $info['count'] ?>枚</div>
                </div>
                <div class="count-controls">
                    <button class="count-btn minus" onclick="adjustCount('<?= $type ?>', -1)">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" 
                           id="<?= $type ?>"
                           class="count-input" 
                           value="<?= $existing_count ? $existing_count->$type : $info['count'] ?>"
                           min="0"
                           onchange="calculateAmount('<?= $type ?>', <?= $info['value'] ?>)">
                    <button class="count-btn plus" onclick="adjustCount('<?= $type ?>', 1)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div class="amount-display" id="amount_<?= $type ?>">
                    ¥<?= number_format(($existing_count ? $existing_count->$type : $info['count']) * $info['value']) ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="text-end mt-3">
                <button class="btn btn-outline-secondary" onclick="resetToBase()">
                    <i class="fas fa-undo"></i> 基準値にリセット
                </button>
            </div>
        </div>

        <!-- 集計結果 -->
        <div class="summary-card" id="summaryCard">
            <h5><i class="fas fa-calculator"></i> 集計結果</h5>
            
            <div class="summary-row">
                <span class="summary-label">カウント合計</span>
                <span class="summary-value" id="totalCount">¥0</span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">基準おつり</span>
                <span class="summary-value">¥<?= number_format($base_total) ?></span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">入金額</span>
                <span class="summary-value" id="depositAmount">¥0</span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">予想金額</span>
                <span class="summary-value">¥<?= number_format($base_total + $today_sales->cash_sales) ?></span>
            </div>
            
            <div class="summary-row">
                <span class="summary-label">差額</span>
                <span class="summary-value">
                    <span class="difference zero" id="differenceDisplay">¥0</span>
                </span>
            </div>
        </div>

        <!-- メモ入力 -->
        <div class="count-card">
            <h6><i class="fas fa-sticky-note"></i> メモ</h6>
            <textarea id="memo" class="form-control" rows="3" 
                      placeholder="差額がある場合は理由を記入してください"><?= $existing_count ? htmlspecialchars($existing_count->memo) : '' ?></textarea>
        </div>

        <!-- 保存ボタン -->
        <button class="save-btn" onclick="saveCashCount()">
            <i class="fas fa-save"></i> 現金カウント保存
        </button>
    </div>

    <script>
        const baseChange = <?= json_encode($base_change) ?>;
        const baseTotal = <?= $base_total ?>;
        const expectedAmount = <?= $base_total + $today_sales->cash_sales ?>;
        
        // 枚数調整
        function adjustCount(type, change) {
            const input = document.getElementById(type);
            const newValue = Math.max(0, parseInt(input.value) + change);
            input.value = newValue;
            calculateAmount(type, baseChange[type].value);
            updateSummary();
        }
        
        // 金額計算
        function calculateAmount(type, value) {
            const count = parseInt(document.getElementById(type).value) || 0;
            const amount = count * value;
            document.getElementById('amount_' + type).textContent = '¥' + amount.toLocaleString();
            updateSummary();
        }
        
        // 基準値リセット
        function resetToBase() {
            Object.keys(baseChange).forEach(type => {
                const input = document.getElementById(type);
                input.value = baseChange[type].count;
                calculateAmount(type, baseChange[type].value);
            });
            updateSummary();
        }
        
        // 集計更新
        function updateSummary() {
            let totalCount = 0;
            
            Object.keys(baseChange).forEach(type => {
                const count = parseInt(document.getElementById(type).value) || 0;
                totalCount += count * baseChange[type].value;
            });
            
            const depositAmount = totalCount - baseTotal;
            const difference = totalCount - expectedAmount;
            
            document.getElementById('totalCount').textContent = '¥' + totalCount.toLocaleString();
            document.getElementById('depositAmount').textContent = '¥' + depositAmount.toLocaleString();
            
            const diffDisplay = document.getElementById('differenceDisplay');
            diffDisplay.textContent = (difference >= 0 ? '+' : '') + '¥' + difference.toLocaleString();
            
            // 差額の色分け
            diffDisplay.className = 'difference ' + (difference > 0 ? 'positive' : difference < 0 ? 'negative' : 'zero');
        }
        
        // 保存処理
        function saveCashCount() {
            const data = {
                driver_id: <?= $current_user->id ?>,
                confirmation_date: new Date().toISOString().split('T')[0],
                memo: document.getElementById('memo').value
            };
            
            // 各金種の枚数取得
            Object.keys(baseChange).forEach(type => {
                data[type] = parseInt(document.getElementById(type).value) || 0;
            });
            
            // 合計金額計算
            let totalAmount = 0;
            Object.keys(baseChange).forEach(type => {
                totalAmount += data[type] * baseChange[type].value;
            });
            data.total_amount = totalAmount;
            
            // Ajax保存
            fetch('api/save_cash_count.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('現金カウントを保存しました');
                } else {
                    alert('保存に失敗しました: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('保存中にエラーが発生しました');
            });
        }
        
        // 初期化
        document.addEventListener('DOMContentLoaded', function() {
            updateSummary();
        });
    </script>
</body>
</html>
