<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集金管理 | スマイリーケアタクシー</title>
    
    <!-- ✅ 修正: 必須CSS/JS読み込み -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header-unified.css">
    
    <style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-color: #11998e;
        --warning-color: #ffc107;
        --danger-color: #dc3545;
    }
    
    .cash-count-item {
        background: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .denomination-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 15px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .denomination-info {
        flex: 1;
    }
    
    .denomination-controls {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .count-btn {
        width: 40px;
        height: 40px;
        border: none;
        border-radius: 50%;
        font-size: 18px;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .count-btn.plus {
        background: var(--success-color);
        color: white;
    }
    
    .count-btn.minus {
        background: var(--danger-color);
        color: white;
    }
    
    .count-input {
        width: 80px;
        text-align: center;
        border: 2px solid #e9ecef;
        border-radius: 10px;
        padding: 8px;
        font-size: 16px;
        font-weight: bold;
    }
    
    .base-difference {
        font-size: 12px;
        font-weight: bold;
        margin-top: 5px;
    }
    
    .base-difference.positive {
        color: var(--success-color);
    }
    
    .base-difference.negative {
        color: var(--danger-color);
    }
    
    .summary-card {
        background: var(--primary-gradient);
        color: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
        text-align: center;
    }
    
    .bag-selector {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .bag-option {
        flex: 1;
        padding: 15px;
        background: white;
        border: 2px solid #e9ecef;
        border-radius: 15px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .bag-option.active {
        border-color: var(--success-color);
        background: #f8f9ff;
    }
    
    @media (max-width: 430px) {
        .denomination-controls {
            flex-wrap: wrap;
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

<?php
// セッション・認証処理
session_start();

// データベース接続
require_once 'config/database.php';
require_once 'includes/cash_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user = (object)[
    'id' => $_SESSION['user_id'],
    'name' => $_SESSION['user_name'] ?? '管理者',
    'permission_level' => $_SESSION['permission_level'] ?? 'User'
];

// 関数型データベース接続に対応
try {
    $pdo = getDBConnection(); // 関数呼び出しでPDO取得
    
    // 当日データ取得（✅ 修正済み関数使用）
    $today_data = getTodayCashRevenue($pdo);
    $base_change = getBaseChangeBreakdown();
    
} catch (Exception $e) {
    // エラー時のデフォルト値
    $today_data = [
        'ride_count' => 0,
        'total_revenue' => 0,
        'cash_revenue' => 0,
        'card_revenue' => 0,
        'average_fare' => 0
    ];
    $base_change = getBaseChangeBreakdown();
    error_log('集金管理データ取得エラー: ' . $e->getMessage());
}
?>

<!-- ✅ 統一ヘッダー適用 -->
<div class="system-header">
    <div class="container-fluid">
        <div class="system-title">
            <i class="fas fa-taxi"></i> スマイリーケアタクシー
        </div>
        <div class="user-info">
            <i class="fas fa-user"></i> <?= htmlspecialchars($user->name) ?>
        </div>
    </div>
</div>

<div class="function-header">
    <div class="container-fluid">
        <div class="function-title">
            <i class="fas fa-yen-sign"></i> 集金管理
        </div>
        <div class="function-subtitle">
            日次売上集計・差額管理
        </div>
    </div>
</div>

<div class="container-fluid mt-4">

    <!-- ✅ 修正: Bootstrapタブ機能 -->
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
                <i class="fas fa-chart-bar"></i> 月次統計
            </button>
        </li>
    </ul>

    <div class="tab-content" id="cashTabContent">
        
        <!-- 日次集計タブ -->
        <div class="tab-pane fade show active" id="daily" role="tabpanel">
            
            <!-- 当日売上サマリー -->
            <div class="summary-card mt-4">
                <h4><i class="fas fa-yen-sign"></i> 当日売上サマリー</h4>
                <div class="row mt-3">
                    <div class="col-6">
                        <h5>総売上</h5>
                        <h3>¥<?= number_format($today_data['total_revenue']) ?></h3>
                        <small><?= $today_data['ride_count'] ?>回</small>
                    </div>
                    <div class="col-6">
                        <h5>現金売上</h5>
                        <h3>¥<?= number_format($today_data['cash_revenue']) ?></h3>
                        <small>平均 ¥<?= number_format($today_data['average_fare']) ?>/回</small>
                    </div>
                </div>
            </div>

            <!-- システム予想 -->
            <div class="cash-count-item">
                <h5><i class="fas fa-calculator"></i> システム予想</h5>
                <div class="row">
                    <div class="col-4 text-center">
                        <strong>基準おつり</strong><br>
                        <span class="text-success">¥18,000</span>
                    </div>
                    <div class="col-4 text-center">
                        <strong>現金売上</strong><br>
                        <span class="text-primary">¥<?= number_format($today_data['cash_revenue']) ?></span>
                    </div>
                    <div class="col-4 text-center">
                        <strong>予想合計</strong><br>
                        <span class="text-warning">¥<?= number_format(18000 + $today_data['cash_revenue']) ?></span>
                    </div>
                </div>
            </div>

        </div>

        <!-- 現金カウントタブ -->
        <div class="tab-pane fade" id="count" role="tabpanel">
            
            <!-- 集金バック選択 -->
            <div class="bag-selector mt-4">
                <div class="bag-option active" data-bag="A">
                    <i class="fas fa-briefcase"></i><br>
                    集金バック A
                </div>
                <div class="bag-option" data-bag="B">
                    <i class="fas fa-briefcase"></i><br>
                    集金バック B
                </div>
            </div>

            <!-- 金種別カウント -->
            <div class="cash-count-item">
                <h5><i class="fas fa-coins"></i> 金種別カウント</h5>
                
                <?php foreach ($base_change as $type => $info): ?>
                    <?php if ($type === 'total') continue; ?>
                    <?php 
                    $denomination_names = [
                        'bill_5000' => '5千円札',
                        'bill_1000' => '千円札', 
                        'coin_500' => '500円玉',
                        'coin_100' => '100円玉',
                        'coin_50' => '50円玉',
                        'coin_10' => '10円玉'
                    ];
                    ?>
                    
                    <div class="denomination-row">
                        <div class="denomination-info">
                            <strong><?= $denomination_names[$type] ?></strong><br>
                            <small>基準: <?= $info['count'] ?>枚 (¥<?= number_format($info['amount']) ?>)</small>
                            <div class="base-difference" id="diff_<?= $type ?>"></div>
                        </div>
                        <div class="denomination-controls">
                            <button type="button" class="count-btn minus" onclick="adjustCount('<?= $type ?>', -1)">
                                <i class="fas fa-minus"></i>
                            </button>
                            <input type="number" 
                                   class="count-input" 
                                   id="<?= $type ?>" 
                                   value="<?= $info['count'] ?>"
                                   min="0"
                                   onchange="calculateTotal()">
                            <button type="button" class="count-btn plus" onclick="adjustCount('<?= $type ?>', 1)">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- 合計表示 -->
                <div class="denomination-row" style="border-bottom: none; background: #f8f9fa; border-radius: 10px;">
                    <div class="denomination-info">
                        <h5>合計金額</h5>
                        <div id="total-display">¥18,000</div>
                    </div>
                    <div class="denomination-controls">
                        <button type="button" class="btn btn-warning" onclick="resetToBase()">
                            <i class="fas fa-undo"></i> 基準値リセット
                        </button>
                    </div>
                </div>
            </div>

            <!-- 集計結果表示 -->
            <div class="summary-card">
                <h5>集金結果</h5>
                <div class="row">
                    <div class="col-6">
                        <strong>入金額</strong><br>
                        <h4 id="deposit-amount">¥0</h4>
                        <small>カウント合計 - 基準おつり</small>
                    </div>
                    <div class="col-6">
                        <strong>差額</strong><br>
                        <h4 id="actual-difference">¥0</h4>
                        <small>実際 - 予想</small>
                    </div>
                </div>
            </div>

            <!-- 保存ボタン -->
            <div class="d-grid gap-2">
                <button type="button" class="btn btn-success btn-lg" onclick="saveCashCount()">
                    <i class="fas fa-save"></i> 集金データ保存
                </button>
            </div>

        </div>

        <!-- 月次統計タブ -->
        <div class="tab-pane fade" id="monthly" role="tabpanel">
            
            <!-- 統計サマリー -->
            <div class="cash-count-item mt-4">
                <h5><i class="fas fa-chart-line"></i> 集金履歴統計</h5>
                <div class="row" id="statistics-summary">
                    <div class="col-3 text-center">
                        <strong>総記録数</strong><br>
                        <span class="text-primary" id="stat-total">-</span>
                    </div>
                    <div class="col-3 text-center">
                        <strong>平均入金額</strong><br>
                        <span class="text-success" id="stat-avg-deposit">¥-</span>
                    </div>
                    <div class="col-3 text-center">
                        <strong>平均差額</strong><br>
                        <span class="text-warning" id="stat-avg-diff">¥-</span>
                    </div>
                    <div class="col-3 text-center">
                        <strong>正確率</strong><br>
                        <span class="text-info" id="stat-accuracy">-%</span>
                    </div>
                </div>
            </div>

            <!-- 履歴フィルター -->
            <div class="cash-count-item">
                <h6><i class="fas fa-filter"></i> 履歴フィルター</h6>
                <div class="row">
                    <div class="col-6">
                        <label class="form-label">開始日</label>
                        <input type="date" class="form-control" id="filter-date-from" 
                               value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">終了日</label>
                        <input type="date" class="form-control" id="filter-date-to" 
                               value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <button type="button" class="btn btn-primary mt-3 w-100" onclick="loadCashHistory()">
                    <i class="fas fa-search"></i> 履歴検索
                </button>
            </div>

            <!-- 履歴一覧 -->
            <div class="cash-count-item">
                <h6><i class="fas fa-history"></i> 集金履歴</h6>
                <div id="history-list" class="mt-3">
                    <div class="text-center text-muted">
                        <i class="fas fa-spinner fa-spin"></i> 履歴を読み込み中...
                    </div>
                </div>
                <button type="button" class="btn btn-outline-primary w-100 mt-3" 
                        id="load-more-btn" onclick="loadMoreHistory()" style="display: none;">
                    <i class="fas fa-plus"></i> もっと見る
                </button>
            </div>

        </div>

    </div>
</div>

<!-- ✅ 必須: Bootstrap JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// 基準値データ
const baseAmounts = <?= json_encode($base_change) ?>;
const systemCashAmount = <?= $today_data['cash_revenue'] ?>;

// 金種別計算
function adjustCount(type, delta) {
    const input = document.getElementById(type);
    const currentValue = parseInt(input.value) || 0;
    const newValue = Math.max(0, currentValue + delta);
    input.value = newValue;
    calculateTotal();
}

// 合計計算
function calculateTotal() {
    let total = 0;
    
    Object.keys(baseAmounts).forEach(type => {
        if (type === 'total') return;
        
        const input = document.getElementById(type);
        const count = parseInt(input.value) || 0;
        const unitValue = baseAmounts[type].amount / baseAmounts[type].count;
        const amount = count * unitValue;
        total += amount;
        
        // 基準差異表示
        const baseDiff = count - baseAmounts[type].count;
        const diffElement = document.getElementById('diff_' + type);
        if (baseDiff !== 0) {
            diffElement.textContent = (baseDiff > 0 ? '+' : '') + baseDiff + '枚';
            diffElement.className = 'base-difference ' + (baseDiff > 0 ? 'positive' : 'negative');
        } else {
            diffElement.textContent = '基準通り';
            diffElement.className = 'base-difference';
        }
    });
    
    // 表示更新
    document.getElementById('total-display').textContent = '¥' + total.toLocaleString();
    
    // 入金額計算（合計 - 基準おつり）
    const depositAmount = total - 18000;
    document.getElementById('deposit-amount').textContent = '¥' + depositAmount.toLocaleString();
    
    // 差額計算（実際 - 予想）
    const expectedTotal = 18000 + systemCashAmount;
    const actualDifference = total - expectedTotal;
    document.getElementById('actual-difference').textContent = '¥' + actualDifference.toLocaleString();
    document.getElementById('actual-difference').className = actualDifference >= 0 ? 'text-success' : 'text-danger';
}

// 基準値リセット
function resetToBase() {
    Object.keys(baseAmounts).forEach(type => {
        if (type === 'total') return;
        document.getElementById(type).value = baseAmounts[type].count;
    });
    calculateTotal();
}

// バック選択
document.querySelectorAll('.bag-option').forEach(option => {
    option.addEventListener('click', function() {
        document.querySelectorAll('.bag-option').forEach(o => o.classList.remove('active'));
        this.classList.add('active');
    });
});

// 保存処理（実テーブル対応版）
function saveCashCount() {
    // データ収集
    const data = {
        confirmation_date: '<?= date('Y-m-d') ?>',
        driver_id: <?= $user->id ?>,
        system_cash_amount: systemCashAmount
    };
    
    // 実テーブル構造に合わせた金種別データ収集
    const baseAmountKeys = Object.keys(baseAmounts);
    baseAmountKeys.forEach(type => {
        if (type === 'total') return;
        data[type] = parseInt(document.getElementById(type).value) || 0;
    });
    
    // 合計金額計算
    const totalAmount = parseInt(document.getElementById('total-display').textContent.replace(/[¥,]/g, ''));
    data.total_amount = totalAmount;
    
    // 差額計算（仮想カラムではなく計算で実装）
    const baseChange = 18000;
    const depositAmount = totalAmount - baseChange;
    const expectedTotal = baseChange + systemCashAmount;
    const actualDifference = totalAmount - expectedTotal;
    
    // メモ入力
    data.memo = prompt('差額理由やメモがあれば入力してください:', '') || '';
    
    // 保存確認
    const confirmMessage = `集金データを保存しますか？

【集計結果】
合計金額: ¥${totalAmount.toLocaleString()}
入金額: ¥${depositAmount.toLocaleString()}
差額: ¥${actualDifference.toLocaleString()}

※差額 = (実際合計 - 予想合計)
　予想合計 = 基準おつり(¥18,000) + システム現金売上(¥${systemCashAmount.toLocaleString()})`;

    if (!confirm(confirmMessage)) {
        return;
    }
    
    // Ajax保存（実テーブル構造対応）
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
            alert('✅ 集金データを保存しました');
            
            // 成功時のフィードバック
            if (window.cashMobile) {
                window.cashMobile.onSaveSuccess();
            }
            
            // 履歴を再読み込み
            if (typeof loadCashHistory === 'function') {
                loadCashHistory();
            }
        } else {
            alert('❌ 保存エラー: ' + result.message);
            
            // エラー時のフィードバック
            if (window.cashMobile) {
                window.cashMobile.onError(result.message);
            }
        }
    })
    .catch(error => {
        console.error('保存エラー:', error);
        alert('❌ 通信エラーが発生しました');
        
        if (window.cashMobile) {
            window.cashMobile.onError('通信エラーが発生しました');
        }
    });
}

// 履歴読み込み（実テーブル対応版）
let historyOffset = 0;
const historyLimit = 10;

function loadCashHistory() {
    historyOffset = 0;
    document.getElementById('history-list').innerHTML = 
        '<div class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> 読み込み中...</div>';
    
    const dateFrom = document.getElementById('filter-date-from').value;
    const dateTo = document.getElementById('filter-date-to').value;
    
    fetch(`api/get_cash_history.php?limit=${historyLimit}&offset=${historyOffset}&date_from=${dateFrom}&date_to=${dateTo}`)
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                displayHistory(result.data.history, true);
                
                if (result.data.statistics) {
                    updateStatistics(result.data.statistics);
                }
                
                // もっと見るボタン制御
                const loadMoreBtn = document.getElementById('load-more-btn');
                if (result.data.pagination && result.data.pagination.has_more) {
                    loadMoreBtn.style.display = 'block';
                    historyOffset += historyLimit;
                } else {
                    loadMoreBtn.style.display = 'none';
                }
            } else {
                document.getElementById('history-list').innerHTML = 
                    '<div class="text-center text-danger">❌ 履歴の読み込みに失敗しました</div>';
            }
        })
        .catch(error => {
            console.error('履歴読み込みエラー:', error);
            document.getElementById('history-list').innerHTML = 
                '<div class="text-center text-danger">❌ 通信エラーが発生しました</div>';
        });
}

function loadMoreHistory() {
    const dateFrom = document.getElementById('filter-date-from').value;
    const dateTo = document.getElementById('filter-date-to').value;
    
    fetch(`api/get_cash_history.php?limit=${historyLimit}&offset=${historyOffset}&date_from=${dateFrom}&date_to=${dateTo}`)
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                displayHistory(result.data.history, false);
                
                if (result.data.pagination && result.data.pagination.has_more) {
                    historyOffset += historyLimit;
                } else {
                    document.getElementById('load-more-btn').style.display = 'none';
                }
            }
        });
}

function displayHistory(history, replace = true) {
    const historyList = document.getElementById('history-list');
    
    if (replace) {
        historyList.innerHTML = '';
    }
    
    if (history.length === 0 && replace) {
        historyList.innerHTML = '<div class="text-center text-muted">📝 履歴がありません</div>';
        return;
    }
    
    history.forEach(record => {
        const historyItem = document.createElement('div');
        historyItem.className = 'border-bottom py-3';
        
        // 差額計算（実テーブル対応）
        const depositAmount = parseInt(record.total_amount) - 18000;
        const actualDifference = record.actual_difference || 0;
        
        const diffClass = actualDifference > 0 ? 'text-success' : 
                         actualDifference < 0 ? 'text-danger' : 'text-muted';
        const diffIcon = actualDifference > 0 ? 'fa-plus' : 
                        actualDifference < 0 ? 'fa-minus' : 'fa-equals';
        
        historyItem.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>${record.formatted_date}</strong><br>
                    <small class="text-muted">${record.driver_name} - ${record.formatted_time}</small>
                </div>
                <div class="text-end">
                    <div>合計: <strong>¥${parseInt(record.total_amount).toLocaleString()}</strong></div>
                    <div>入金: ¥${depositAmount.toLocaleString()}</div>
                    <div class="${diffClass}">
                        <i class="fas ${diffIcon}"></i> 差額 ¥${Math.abs(actualDifference).toLocaleString()}
                    </div>
                </div>
            </div>
            ${record.memo ? `<div class="mt-2"><small class="text-info"><i class="fas fa-sticky-note"></i> ${record.memo}</small></div>` : ''}
        `;
        
        historyList.appendChild(historyItem);
    });
}

function updateStatistics(stats) {
    if (document.getElementById('stat-total')) {
        document.getElementById('stat-total').textContent = stats.record_count || 0;
    }
    if (document.getElementById('stat-avg-deposit')) {
        document.getElementById('stat-avg-deposit').textContent = '¥' + (stats.avg_deposit || 0).toLocaleString();
    }
    if (document.getElementById('stat-avg-diff')) {
        document.getElementById('stat-avg-diff').textContent = '¥' + (stats.avg_difference || 0).toLocaleString();
    }
    if (document.getElementById('stat-accuracy')) {
        document.getElementById('stat-accuracy').textContent = (stats.accuracy_rate || 0) + '%';
    }
}

// タブ切り替え時の履歴読み込み
document.getElementById('monthly-tab').addEventListener('shown.bs.tab', function () {
    loadCashHistory();
});

// 初期計算
calculateTotal();
</script>

</body>
</html>
