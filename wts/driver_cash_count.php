<?php
/**
 * 運転者向け現金カウント機能
 * 集金管理システムの分割実装 - 運転者専用画面
 */
session_start();
require_once 'config/database.php';
require_once 'includes/unified-header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'] ?? 'User';

// ユーザー情報取得（is_driver含む）
$stmt = $pdo->prepare("SELECT id, name, permission_level, is_driver FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch(PDO::FETCH_OBJ);

if (!$current_user) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// 権限チェック: 運転者フラグまたはAdmin権限
if (!$current_user->is_driver && $current_user->permission_level !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

// 今日の売上データ取得
$today_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as trip_count,
        COALESCE(SUM(total_fare), 0) as total_sales,
        COALESCE(SUM(cash_amount), 0) as cash_sales,
        COALESCE(SUM(card_amount), 0) as card_sales
    FROM ride_records
    WHERE ride_date = CURDATE()
    AND driver_id = ?
");
$today_stmt->execute([$current_user->id]);
$today_sales = $today_stmt->fetch(PDO::FETCH_OBJ);

// 基準おつり構成（固定）
$base_change = [
    'bill_5000' => ['count' => 1, 'value' => 5000, 'name' => '5千円札'],
    'bill_1000' => ['count' => 10, 'value' => 1000, 'name' => '千円札'],
    'coin_500'  => ['count' => 3, 'value' => 500, 'name' => '500円玉'],
    'coin_100'  => ['count' => 11, 'value' => 100, 'name' => '100円玉'],
    'coin_50'   => ['count' => 5, 'value' => 50, 'name' => '50円玉'],
    'coin_10'   => ['count' => 15, 'value' => 10, 'name' => '10円玉']
];
$base_total = 18000;

// 既存の今日のカウントデータ取得
$existing_count = null;
$ex_stmt = $pdo->prepare("SELECT * FROM cash_count_details WHERE confirmation_date = CURDATE() AND driver_id = ?");
$ex_stmt->execute([$current_user->id]);
$existing_count = $ex_stmt->fetch(PDO::FETCH_OBJ);

// --- ページ設定 ---
$page_config = getPageConfiguration('driver_cash_count');
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
        'css/ui-unified-v3.css'
    ]
];
$page_data = renderCompletePage(
    $page_config['title'], $user_name, $user_role,
    'driver_cash_count', $page_config['icon'],
    $page_config['title'], $page_config['subtitle'],
    $page_config['category'], $page_options
);
echo $page_data['html_head'];
?>

<style>
.count-card {
    background: white; border: 1px solid #e0e0e0;
    border-radius: 8px; margin-bottom: 20px; padding: 20px;
}
.cash-type-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 0; border-bottom: 1px solid #f0f0f0;
}
.cash-type-row:last-child { border-bottom: none; }
.cash-info { flex: 1; }
.cash-name { font-weight: 600; font-size: 15px; color: #333; }
.cash-base { font-size: 12px; color: #888; margin-top: 2px; }
.count-controls { display: flex; align-items: center; gap: 10px; }
.count-btn {
    width: 38px; height: 38px; border-radius: 50%; border: none;
    font-size: 16px; font-weight: bold;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: opacity 0.15s;
}
.count-btn:active { opacity: 0.7; }
.count-btn.minus { background: #ef5350; color: white; }
.count-btn.plus  { background: #4caf50; color: white; }
.count-input {
    width: 70px; text-align: center; font-size: 16px; font-weight: 600;
    border: 2px solid #e0e0e0; border-radius: 6px; padding: 6px;
}
.amount-display {
    text-align: right; font-weight: 600; font-size: 15px;
    color: #333; min-width: 80px;
}
.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white; border-radius: 8px; padding: 20px; margin-bottom: 20px;
}
.summary-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px; padding-bottom: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}
.summary-row:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
.summary-label { font-size: 15px; opacity: 0.9; }
.summary-value { font-size: 18px; font-weight: bold; }
.difference {
    padding: 6px 14px; border-radius: 16px;
    font-weight: bold; font-size: 13px; display: inline-block;
}
.difference.positive { background: #4caf50; color: white; }
.difference.negative { background: #ef5350; color: white; }
.difference.zero     { background: #42a5f5; color: white; }
.save-btn {
    width: 100%; padding: 14px; font-size: 16px; font-weight: 600;
    border: none; border-radius: 8px; background: #4caf50;
    color: white; cursor: pointer; transition: background 0.15s;
}
.save-btn:hover { background: #43a047; }
.save-btn:disabled { background: #bdbdbd; cursor: not-allowed; }
.info-badge {
    background: #42a5f5; color: white; padding: 6px 12px;
    border-radius: 16px; font-size: 12px; font-weight: 600;
}
.back-link {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.875rem; color: #1976d2; text-decoration: none;
    margin-bottom: 20px;
}
.back-link:hover { text-decoration: underline; }
@media (max-width: 768px) {
    .cash-type-row { flex-wrap: wrap; gap: 8px; }
    .count-controls { justify-content: center; }
    .amount-display { width: 100%; text-align: center; margin-top: 4px; }
}
</style>

<?php echo $page_data['system_header']; ?>
<?php echo $page_data['page_header']; ?>

<div class="container-fluid mt-4">
    <a href="cash_management.php" class="back-link">
        <i class="fas fa-arrow-left"></i> 売上金確認に戻る
    </a>

    <!-- 今日の売上情報 -->
    <div class="count-card">
        <h6 class="mb-3"><i class="fas fa-chart-line" style="color:#42a5f5;"></i> 今日の売上実績</h6>
        <div class="row text-center">
            <div class="col-3">
                <div class="info-badge">回数</div>
                <div class="h5 mt-2"><?php echo $today_sales->trip_count; ?>回</div>
            </div>
            <div class="col-3">
                <div class="info-badge">総売上</div>
                <div class="h5 mt-2">¥<?php echo number_format($today_sales->total_sales); ?></div>
            </div>
            <div class="col-3">
                <div class="info-badge">現金</div>
                <div class="h5 mt-2">¥<?php echo number_format($today_sales->cash_sales); ?></div>
            </div>
            <div class="col-3">
                <div class="info-badge">カード</div>
                <div class="h5 mt-2">¥<?php echo number_format($today_sales->card_sales); ?></div>
            </div>
        </div>
    </div>

    <!-- 現金カウント入力 -->
    <div class="count-card">
        <h6 class="mb-3"><i class="fas fa-coins" style="color:#ff9800;"></i> 現金カウント
            <span style="font-size:0.8rem;font-weight:400;color:#888;margin-left:8px;">基準おつり: ¥18,000</span>
        </h6>

        <?php foreach ($base_change as $type => $info): ?>
        <div class="cash-type-row">
            <div class="cash-info">
                <div class="cash-name"><?php echo $info['name']; ?></div>
                <div class="cash-base">基準: <?php echo $info['count']; ?>枚</div>
            </div>
            <div class="count-controls">
                <button class="count-btn minus" onclick="adjustCount('<?php echo $type; ?>', -1)">
                    <i class="fas fa-minus"></i>
                </button>
                <input type="number"
                       id="<?php echo $type; ?>"
                       class="count-input"
                       value="<?php echo $existing_count ? $existing_count->$type : $info['count']; ?>"
                       min="0"
                       onchange="recalc()">
                <button class="count-btn plus" onclick="adjustCount('<?php echo $type; ?>', 1)">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            <div class="amount-display" id="amount_<?php echo $type; ?>">
                ¥<?php echo number_format(($existing_count ? $existing_count->$type : $info['count']) * $info['value']); ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="text-end mt-3">
            <button class="btn btn-outline-secondary btn-sm" onclick="resetToBase()">
                <i class="fas fa-undo"></i> 基準値にリセット
            </button>
        </div>
    </div>

    <!-- 集計結果 -->
    <div class="summary-card">
        <h6 style="margin-bottom:16px;"><i class="fas fa-calculator"></i> 集計結果</h6>
        <div class="summary-row">
            <span class="summary-label">カウント合計</span>
            <span class="summary-value" id="totalCount">¥0</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">基準おつり</span>
            <span class="summary-value">¥<?php echo number_format($base_total); ?></span>
        </div>
        <div class="summary-row">
            <span class="summary-label">入金額</span>
            <span class="summary-value" id="depositAmount">¥0</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">予想金額</span>
            <span class="summary-value">¥<?php echo number_format($base_total + $today_sales->cash_sales); ?></span>
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
                  placeholder="差額がある場合は理由を記入してください"><?php echo $existing_count ? htmlspecialchars($existing_count->memo) : ''; ?></textarea>
    </div>

    <!-- 保存ボタン -->
    <button class="save-btn" id="saveBtn" onclick="saveCashCount()">
        <i class="fas fa-save"></i> 現金カウント保存
    </button>
</div>

<script>
    var baseChange = <?php echo json_encode($base_change); ?>;
    var baseTotal = <?php echo $base_total; ?>;
    var expectedAmount = <?php echo $base_total + $today_sales->cash_sales; ?>;
    var driverId = <?php echo $current_user->id; ?>;

    function adjustCount(type, change) {
        var input = document.getElementById(type);
        var newValue = Math.max(0, parseInt(input.value) + change);
        input.value = newValue;
        recalc();
    }

    function recalc() {
        var totalCount = 0;
        var keys = Object.keys(baseChange);
        for (var i = 0; i < keys.length; i++) {
            var type = keys[i];
            var count = parseInt(document.getElementById(type).value) || 0;
            var amount = count * baseChange[type].value;
            document.getElementById('amount_' + type).textContent = '¥' + amount.toLocaleString();
            totalCount += amount;
        }

        var depositAmount = totalCount - baseTotal;
        var difference = totalCount - expectedAmount;

        document.getElementById('totalCount').textContent = '¥' + totalCount.toLocaleString();
        document.getElementById('depositAmount').textContent = '¥' + depositAmount.toLocaleString();

        var diffDisplay = document.getElementById('differenceDisplay');
        diffDisplay.textContent = (difference >= 0 ? '+' : '') + '¥' + difference.toLocaleString();
        diffDisplay.className = 'difference ' + (difference > 0 ? 'positive' : difference < 0 ? 'negative' : 'zero');
    }

    function resetToBase() {
        var keys = Object.keys(baseChange);
        for (var i = 0; i < keys.length; i++) {
            document.getElementById(keys[i]).value = baseChange[keys[i]].count;
        }
        recalc();
    }

    function saveCashCount() {
        var btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';

        var data = {
            driver_id: driverId,
            confirmation_date: new Date().toISOString().split('T')[0],
            memo: document.getElementById('memo').value
        };

        var keys = Object.keys(baseChange);
        var totalAmount = 0;
        for (var i = 0; i < keys.length; i++) {
            data[keys[i]] = parseInt(document.getElementById(keys[i]).value) || 0;
            totalAmount += data[keys[i]] * baseChange[keys[i]].value;
        }
        data.total_amount = totalAmount;

        fetch('api/save_cash_count.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try { return JSON.parse(text); }
            catch(e) { throw new Error('レスポンスエラー: ' + text.substring(0, 100)); }
        })
        .then(function(result) {
            if (result.success) {
                alert('現金カウントを保存しました');
                window.location.reload();
            } else {
                alert('保存に失敗しました: ' + (result.message || '不明なエラー'));
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('保存中にエラーが発生しました: ' + error.message);
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> 現金カウント保存';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        recalc();
    });
</script>

<?php echo $page_data['footer'] ?? ''; ?>
</body>
</html>
