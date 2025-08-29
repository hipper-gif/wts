<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$current_user_id = $_SESSION['user_id'];
$current_user_name = $_SESSION['user_name'] ?? '不明';

// 今日の日付
$today = date('Y-m-d');

// システム売上取得（運転者別）
function getSystemRevenue($pdo, $date, $driver_id = null) {
    $sql = "SELECT 
                SUM(total_fare) as total_revenue,
                SUM(cash_amount) as cash_total,
                SUM(card_amount) as card_total,
                COUNT(*) as ride_count
            FROM ride_records 
            WHERE DATE(ride_date) = ?";
    
    $params = [$date];
    
    if ($driver_id) {
        $sql .= " AND driver_id = ?";
        $params[] = $driver_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 今日のシステム売上
$system_revenue = getSystemRevenue($pdo, $today);

// 現在のユーザーの今日のシステム売上
$user_system_revenue = getSystemRevenue($pdo, $today, $current_user_id);

// 基準おつり定義
$base_change = [
    'bill_5000' => ['count' => 1, 'unit' => 5000],
    'bill_1000' => ['count' => 10, 'unit' => 1000],
    'coin_500' => ['count' => 3, 'unit' => 500],
    'coin_100' => ['count' => 11, 'unit' => 100],
    'coin_50' => ['count' => 5, 'unit' => 50],
    'coin_10' => ['count' => 15, 'unit' => 10]
];

$base_total = 18000;

// 既存の集金データ取得
$existing_data = null;
$stmt = $pdo->prepare("SELECT * FROM cash_count_details WHERE confirmation_date = ? AND driver_id = ?");
$stmt->execute([$today, $current_user_id]);
$existing_data = $stmt->fetch(PDO::FETCH_ASSOC);

// 月次売上データ取得
$monthly_sql = "SELECT 
    DATE_FORMAT(ride_date, '%Y-%m-%d') as date,
    SUM(total_fare) as daily_total,
    SUM(cash_amount) as cash_total,
    SUM(card_amount) as card_total,
    COUNT(*) as ride_count
    FROM ride_records 
    WHERE ride_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(ride_date)
    ORDER BY date DESC";
$monthly_stmt = $pdo->prepare($monthly_sql);
$monthly_stmt->execute();
$monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集金管理 - スマイリーケアタクシー</title>
    
    <!-- Bootstrap & Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- 統一ヘッダーCSS -->
    <link rel="stylesheet" href="css/header-unified.css">
    
    <style>
        .denomination-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
        }
        
        .denomination-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .count-control {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .count-btn {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            font-weight: bold;
            font-size: 18px;
        }
        
        .count-input {
            width: 80px;
            text-align: center;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 8px;
        }
        
        .base-indicator {
            color: #6c757d;
            font-size: 0.9em;
        }
        
        .difference {
            font-weight: bold;
        }
        
        .difference.positive {
            color: #28a745;
        }
        
        .difference.negative {
            color: #dc3545;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .nav-buttons {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        
        @media (max-width: 768px) {
            .denomination-card {
                margin-bottom: 10px;
                padding: 10px;
            }
            
            .count-btn {
                width: 35px;
                height: 35px;
                font-size: 16px;
            }
            
            .count-input {
                width: 60px;
            }
        }
    </style>
</head>
<body>
    <!-- ナビゲーションボタン -->
    <div class="nav-buttons">
        <a href="dashboard.php" class="btn btn-primary btn-sm me-2">
            <i class="fas fa-arrow-left"></i> ダッシュボードに戻る
        </a>
    </div>

    <!-- 統一ヘッダー -->
    <div class="header-container">
        <div class="system-header">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center">
                    <h1 class="system-title">
                        <i class="fas fa-taxi"></i>
                        スマイリーケアタクシー
                    </h1>
                    <div class="user-info">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($current_user_name); ?>さん
                    </div>
                </div>
            </div>
        </div>
        
        <div class="page-header">
            <div class="container-fluid">
                <h2 class="page-title">
                    <i class="fas fa-money-check-alt"></i>
                    集金管理 - 日次売上集計・差額管理
                </h2>
            </div>
        </div>
    </div>

    <div class="container-fluid mt-4">
        <!-- タブメニュー -->
        <ul class="nav nav-tabs" id="cashTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="daily-tab" data-bs-toggle="tab" data-bs-target="#daily" type="button" role="tab">
                    <i class="fas fa-calendar-day"></i> 日次集計
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="count-tab" data-bs-toggle="tab" data-bs-target="#count" type="button" role="tab">
                    <i class="fas fa-coins"></i> 現金カウント
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly" type="button" role="tab">
                    <i class="fas fa-chart-line"></i> 月次統計
                </button>
            </li>
        </ul>

        <div class="tab-content" id="cashTabContent">
            <!-- 日次集計タブ -->
            <div class="tab-pane fade show active" id="daily" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-chart-bar"></i> 本日の売上実績 (全体)</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-primary">¥<?php echo number_format($system_revenue['total_revenue'] ?? 0); ?></h3>
                                            <p class="text-muted">総売上</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-success"><?php echo $system_revenue['ride_count'] ?? 0; ?>回</h3>
                                            <p class="text-muted">総回数</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-warning">¥<?php echo number_format($system_revenue['cash_total'] ?? 0); ?></h3>
                                            <p class="text-muted">現金売上</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-info">¥<?php echo number_format($system_revenue['card_total'] ?? 0); ?></h3>
                                            <p class="text-muted">カード売上</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-user-check"></i> あなたの売上実績</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-primary">¥<?php echo number_format($user_system_revenue['total_revenue'] ?? 0); ?></h3>
                                            <p class="text-muted">あなたの総売上</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-success"><?php echo $user_system_revenue['ride_count'] ?? 0; ?>回</h3>
                                            <p class="text-muted">あなたの回数</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-warning">¥<?php echo number_format($user_system_revenue['cash_total'] ?? 0); ?></h3>
                                            <p class="text-muted">現金売上</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-item">
                                            <h3 class="text-info">¥<?php echo number_format($user_system_revenue['card_total'] ?? 0); ?></h3>
                                            <p class="text-muted">カード売上</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 現金カウントタブ -->
            <div class="tab-pane fade" id="count" role="tabpanel">
                <div class="row mt-4">
                    <div class="col-md-8">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            あなたの集金をカウントしてください。基準おつり（¥18,000）との差額を自動計算します。
                        </div>
                        
                        <form id="cashCountForm">
                            <input type="hidden" id="confirmation_date" value="<?php echo $today; ?>">
                            <input type="hidden" id="driver_id" value="<?php echo $current_user_id; ?>">
                            
                            <!-- 金種別入力 -->
                            <?php foreach ($base_change as $key => $info): ?>
                            <div class="denomination-card" data-denomination="<?php echo $key; ?>">
                                <div class="denomination-row">
                                    <div class="denomination-info">
                                        <strong>
                                            <?php
                                            $labels = [
                                                'bill_5000' => '5千円札',
                                                'bill_1000' => '千円札',
                                                'coin_500' => '500円玉',
                                                'coin_100' => '100円玉',
                                                'coin_50' => '50円玉',
                                                'coin_10' => '10円玉'
                                            ];
                                            echo $labels[$key];
                                            ?>
                                        </strong>
                                        <div class="base-indicator">基準: <?php echo $info['count']; ?>枚</div>
                                    </div>
                                    
                                    <div class="count-control">
                                        <button type="button" class="btn btn-outline-danger count-btn" onclick="adjustCount('<?php echo $key; ?>', -1)">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                        <input type="number" 
                                               class="form-control count-input" 
                                               id="<?php echo $key; ?>" 
                                               value="<?php echo $existing_data[$key] ?? $info['count']; ?>"
                                               min="0"
                                               onchange="calculateTotals()">
                                        <button type="button" class="btn btn-outline-success count-btn" onclick="adjustCount('<?php echo $key; ?>', 1)">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="amount-info">
                                        <div class="amount" id="amount_<?php echo $key; ?>">¥0</div>
                                        <div class="difference" id="diff_<?php echo $key; ?>">±0</div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="form-group mt-3">
                                <label for="memo">メモ（差額理由など）</label>
                                <textarea class="form-control" id="memo" rows="3" placeholder="差額がある場合は理由を記入してください"><?php echo htmlspecialchars($existing_data['memo'] ?? ''); ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-lg mt-3">
                                <i class="fas fa-save"></i> 保存する
                            </button>
                        </form>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- 集計結果 -->
                        <div class="summary-card">
                            <h4><i class="fas fa-calculator"></i> 集計結果</h4>
                            <div class="summary-item">
                                <div class="d-flex justify-content-between">
                                    <span>カウント合計:</span>
                                    <span id="count_total">¥0</span>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="d-flex justify-content-between">
                                    <span>基準おつり:</span>
                                    <span>¥<?php echo number_format($base_total); ?></span>
                                </div>
                            </div>
                            <hr style="border-color: rgba(255,255,255,0.3);">
                            <div class="summary-item">
                                <div class="d-flex justify-content-between">
                                    <strong>入金額:</strong>
                                    <strong id="deposit_amount">¥0</strong>
                                </div>
                            </div>
                            <hr style="border-color: rgba(255,255,255,0.3);">
                            <div class="summary-item">
                                <div class="d-flex justify-content-between">
                                    <span>システム現金売上:</span>
                                    <span>¥<?php echo number_format($user_system_revenue['cash_total'] ?? 0); ?></span>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="d-flex justify-content-between">
                                    <span>予想金額:</span>
                                    <span id="expected_amount">¥<?php echo number_format($base_total + ($user_system_revenue['cash_total'] ?? 0)); ?></span>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="d-flex justify-content-between">
                                    <strong>実際差額:</strong>
                                    <strong id="actual_difference">¥0</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 月次統計タブ -->
            <div class="tab-pane fade" id="monthly" role="tabpanel">
                <div class="mt-4">
                    <h4><i class="fas fa-chart-line"></i> 直近30日の売上実績</h4>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>日付</th>
                                    <th>総売上</th>
                                    <th>現金売上</th>
                                    <th>カード売上</th>
                                    <th>利用回数</th>
                                    <th>現金率</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_data as $row): ?>
                                <tr>
                                    <td><?php echo date('m/d(D)', strtotime($row['date'])); ?></td>
                                    <td class="text-end">¥<?php echo number_format($row['daily_total']); ?></td>
                                    <td class="text-end">¥<?php echo number_format($row['cash_total']); ?></td>
                                    <td class="text-end">¥<?php echo number_format($row['card_total']); ?></td>
                                    <td class="text-center"><?php echo $row['ride_count']; ?>回</td>
                                    <td class="text-center">
                                        <?php 
                                        $cash_rate = $row['daily_total'] > 0 ? ($row['cash_total'] / $row['daily_total']) * 100 : 0;
                                        echo number_format($cash_rate, 1) . '%'; 
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($monthly_data)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">データがありません</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 基準値とシステム売上
        const baseChange = <?php echo json_encode($base_change); ?>;
        const baseTotal = <?php echo $base_total; ?>;
        const systemCashSales = <?php echo $user_system_revenue['cash_total'] ?? 0; ?>;
        
        // ページ読み込み時に計算
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotals();
        });
        
        // 金種枚数調整
        function adjustCount(denomination, delta) {
            const input = document.getElementById(denomination);
            const currentValue = parseInt(input.value) || 0;
            const newValue = Math.max(0, currentValue + delta);
            input.value = newValue;
            calculateTotals();
            
            // ハプティックフィードバック（モバイル）
            if (navigator.vibrate) {
                navigator.vibrate(10);
            }
        }
        
        // 合計計算
        function calculateTotals() {
            let totalAmount = 0;
            
            Object.keys(baseChange).forEach(denomination => {
                const count = parseInt(document.getElementById(denomination).value) || 0;
                const baseCount = baseChange[denomination].count;
                const unit = baseChange[denomination].unit;
                const amount = count * unit;
                const diff = count - baseCount;
                
                // 金額表示
                document.getElementById('amount_' + denomination).textContent = '¥' + amount.toLocaleString();
                
                // 差異表示
                const diffElement = document.getElementById('diff_' + denomination);
                const diffText = diff === 0 ? '±0' : (diff > 0 ? '+' + diff : diff.toString());
                diffElement.textContent = diffText + '枚';
                diffElement.className = 'difference ' + (diff > 0 ? 'positive' : diff < 0 ? 'negative' : '');
                
                totalAmount += amount;
            });
            
            // サマリー更新
            const depositAmount = totalAmount - baseTotal;
            const expectedAmount = baseTotal + systemCashSales;
            const actualDifference = totalAmount - expectedAmount;
            
            document.getElementById('count_total').textContent = '¥' + totalAmount.toLocaleString();
            document.getElementById('deposit_amount').textContent = '¥' + depositAmount.toLocaleString();
            document.getElementById('actual_difference').textContent = '¥' + actualDifference.toLocaleString();
            
            // 差額の色分け
            const diffElement = document.getElementById('actual_difference');
            diffElement.style.color = actualDifference === 0 ? '#fff' : actualDifference > 0 ? '#28a745' : '#dc3545';
        }
        
        // フォーム送信
        document.getElementById('cashCountForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                confirmation_date: document.getElementById('confirmation_date').value,
                driver_id: document.getElementById('driver_id').value,
                memo: document.getElementById('memo').value
            };
            
            // 金種データ追加
            Object.keys(baseChange).forEach(denomination => {
                formData[denomination] = parseInt(document.getElementById(denomination).value) || 0;
            });
            
            // 合計金額計算
            let totalAmount = 0;
            Object.keys(baseChange).forEach(denomination => {
                totalAmount += formData[denomination] * baseChange[denomination].unit;
            });
            formData.total_amount = totalAmount;
            
            // 保存処理（Ajax）
            fetch('api/save_cash_count.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('集金データを保存しました！');
                } else {
                    alert('保存エラー: ' + (data.message || '不明なエラー'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('保存中にエラーが発生しました');
            });
        });
    </script>
</body>
</html>
