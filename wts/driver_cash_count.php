<?php
/**
 * 運転者向け現金カウント機能
 * 集金管理システムの分割実装 - 運転者専用画面
 */
require_once 'config/database.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
// $user_role は session_check.php で設定済み

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

// 今日の売上データ取得（dashboard.php の calculateRevenue と同じロジック）
$today_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as trip_count,
        COALESCE(SUM(
            CASE
                WHEN total_fare IS NOT NULL AND total_fare > 0 THEN total_fare
                WHEN (fare IS NOT NULL OR charge IS NOT NULL) THEN (COALESCE(fare, 0) + COALESCE(charge, 0))
                ELSE 0
            END
        ), 0) as total_sales,
        COALESCE(SUM(cash_amount), 0) as cash_sales,
        COALESCE(SUM(card_amount), 0) as card_sales
    FROM ride_records
    WHERE ride_date = CURDATE()
    AND driver_id = ?
    AND COALESCE(is_sample_data, 0) = 0
");
$today_stmt->execute([$current_user->id]);
$today_sales = $today_stmt->fetch(PDO::FETCH_OBJ);

// 基準おつり構成（固定）
$base_change = [
    'bill_10000' => ['count' => 0, 'value' => 10000, 'name' => '1万円札'],
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
$ex_stmt = $pdo->prepare("
    SELECT id, bill_10000, bill_5000, bill_1000,
           coin_500, coin_100, coin_50, coin_10,
           total_amount, memo
    FROM cash_count_details
    WHERE confirmation_date = CURDATE() AND driver_id = ?
");
$ex_stmt->execute([$current_user->id]);
$existing_count = $ex_stmt->fetch(PDO::FETCH_OBJ);

// 過去の履歴取得（自分のデータのみ、最新10件）
$history_stmt = $pdo->prepare("
    SELECT
        c.confirmation_date,
        c.bill_10000, c.bill_5000, c.bill_1000,
        c.coin_500, c.coin_100, c.coin_50, c.coin_10,
        c.total_amount, c.memo, c.created_at
    FROM cash_count_details c
    WHERE c.driver_id = ?
    ORDER BY c.confirmation_date DESC
    LIMIT 10
");
$history_stmt->execute([$current_user->id]);
$my_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// --- ページ設定 ---
$page_config = getPageConfiguration('driver_cash_count');
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'css/ui-unified-v3.css'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '現金カウント', 'url' => 'driver_cash_count.php']
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
.summary-note {
    display: block; font-size: 11px; opacity: 0.7;
    margin-top: 2px; font-weight: 400;
}
.summary-value { font-size: 18px; font-weight: bold; }
.calc-operator {
    text-align: center; margin: -4px 0;
    position: relative; z-index: 1;
}
.calc-symbol {
    display: inline-block; width: 30px; height: 30px; line-height: 28px;
    background: rgba(255,255,255,0.25); border-radius: 50%;
    font-size: 18px; font-weight: bold; text-align: center;
}
.result-row {
    background: rgba(255,255,255,0.15); border-radius: 8px;
    padding: 12px !important; margin: 0 -8px;
}
.difference-alert {
    background: rgba(255,255,255,0.15); border-radius: 8px;
    padding: 10px 14px; margin-top: 8px;
    font-size: 13px; line-height: 1.5;
}
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
                       min="0" inputmode="numeric"
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

    <!-- 入金額の計算 -->
    <div class="summary-card">
        <h6 style="margin-bottom:16px;"><i class="fas fa-calculator"></i> 本日の入金額</h6>

        <div class="summary-row">
            <span class="summary-label">カウント合計</span>
            <span class="summary-value" id="totalCount">¥0</span>
        </div>

        <div class="calc-operator">
            <span class="calc-symbol">−</span>
        </div>

        <div class="summary-row">
            <div>
                <span class="summary-label">基準おつり</span>
                <span class="summary-note">常時携帯する釣銭</span>
            </div>
            <span class="summary-value">¥<?php echo number_format($base_total); ?></span>
        </div>

        <div class="calc-operator">
            <span class="calc-symbol">=</span>
        </div>

        <div class="summary-row result-row">
            <div>
                <span class="summary-label" style="font-size:16px;">本日入金額</span>
                <span class="summary-note">銀行に預ける金額</span>
            </div>
            <span class="summary-value" id="depositAmount" style="font-size:22px;">¥0</span>
        </div>
    </div>

    <!-- 売上実績との照合 -->
    <div class="summary-card" style="background: linear-gradient(135deg, #43a047 0%, #2e7d32 100%);">
        <h6 style="margin-bottom:16px;"><i class="fas fa-check-circle"></i> 売上実績との照合</h6>

        <div class="summary-row">
            <div>
                <span class="summary-label">本日の現金売上</span>
                <span class="summary-note">乗車記録から自動集計</span>
            </div>
            <span class="summary-value">¥<?php echo number_format($today_sales->cash_sales); ?></span>
        </div>

        <div class="summary-row">
            <span class="summary-label">本日入金額</span>
            <span class="summary-value" id="depositAmount2">¥0</span>
        </div>

        <div class="summary-row" style="border-bottom:none;">
            <span class="summary-label" style="font-size:16px;">差額</span>
            <span class="summary-value">
                <span class="difference zero" id="differenceDisplay">¥0</span>
            </span>
        </div>

        <div id="differenceAlert" class="difference-alert" style="display:none;">
            <i class="fas fa-exclamation-triangle"></i>
            差額があります。下のメモ欄に理由を記入してください。
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

    <!-- 過去の履歴 -->
    <?php if (!empty($my_history)): ?>
    <div class="count-card" style="margin-top:24px;">
        <h6 class="mb-3"><i class="fas fa-history" style="color:#7e57c2;"></i> 過去の記録</h6>
        <div style="overflow-x:auto;">
        <table class="table table-sm" style="font-size:0.85rem;margin-bottom:0;">
            <thead>
                <tr style="background:#f8f9fa;">
                    <th>日付</th>
                    <th class="text-end">合計</th>
                    <th class="text-end">入金額</th>
                    <th>メモ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($my_history as $h):
                    $deposit = $h['total_amount'] - $base_total;
                ?>
                <tr>
                    <td><?php echo date('m/d', strtotime($h['confirmation_date'])); ?>
                        <span style="color:#999;font-size:0.75rem;">(<?php echo ['日','月','火','水','木','金','土'][date('w', strtotime($h['confirmation_date']))]; ?>)</span>
                    </td>
                    <td class="text-end">¥<?php echo number_format($h['total_amount']); ?></td>
                    <td class="text-end">¥<?php echo number_format($deposit); ?></td>
                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo htmlspecialchars($h['memo'] ?: '-'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
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
        document.getElementById('depositAmount2').textContent = '¥' + depositAmount.toLocaleString();

        var diffDisplay = document.getElementById('differenceDisplay');
        diffDisplay.textContent = (difference >= 0 ? '+' : '') + '¥' + difference.toLocaleString();
        diffDisplay.className = 'difference ' + (difference > 0 ? 'positive' : difference < 0 ? 'negative' : 'zero');

        var alertEl = document.getElementById('differenceAlert');
        alertEl.style.display = (difference !== 0) ? 'block' : 'none';
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

        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        fetch('api/save_cash_count.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken ? csrfToken.content : ''
            },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try { return JSON.parse(text); }
            catch(e) { throw new Error('レスポンスエラー: ' + text.substring(0, 100)); }
        })
        .then(function(result) {
            if (result.success) {
                showToast('現金カウントを保存しました', 'success');
                window.location.reload();
            } else {
                showToast('保存に失敗しました: ' + (result.message || '不明なエラー'), 'danger');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showToast('保存中にエラーが発生しました: ' + error.message, 'danger');
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

<?php echo $page_data['html_footer'] ?? ''; ?>
</body>
</html>
