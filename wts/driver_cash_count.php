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
    destroySessionFully();
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
    'coin_10'   => ['count' => 15, 'value' => 10, 'name' => '10円玉'],
    'coin_5'    => ['count' => 0, 'value' => 5, 'name' => '5円玉'],
    'coin_1'    => ['count' => 0, 'value' => 1, 'name' => '1円玉']
];
$base_total = 18000;

// 既存の今日のカウントデータ取得
$existing_count = null;
$ex_stmt = $pdo->prepare("
    SELECT id, bill_10000, bill_5000, bill_1000,
           coin_500, coin_100, coin_50, coin_10, coin_5, coin_1,
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
/* 色システム(1色=1意味): 緑=一致・保存OK / アンバー=差額あり要説明 / 赤=不足
   青=現在地・フォーカス / スレート=中立の増減操作 / グレー=参照・未着手 */
.cash-type-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 4px; border-bottom: 1px solid #f0f0f0;
    transition: background 0.15s;
}
.cash-type-row:last-child { border-bottom: none; }
.cash-type-row:focus-within {
    background: #e3f2fd; box-shadow: inset 4px 0 0 #1976d2; border-radius: 4px;
}
.cash-type-row:focus-within .cash-name { color: #0d47a1; }
.cash-type-row:focus-within .amount-display { color: #1976d2; }
.cash-info { flex: 1; }
.cash-name { font-weight: 600; font-size: 15px; color: #333; }
.cash-base { font-size: 12px; color: #888; margin-top: 2px; }
.count-controls { display: flex; align-items: center; gap: 12px; }
.count-btn {
    width: 48px; height: 48px; border-radius: 50%;
    font-size: 18px; font-weight: bold;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: opacity 0.15s;
}
.count-btn:active { opacity: 0.7; }
.count-btn.minus { background: #fff; color: #37474f; border: 2px solid #37474f; }
.count-btn.plus  { background: #37474f; color: #fff; border: none; }
.count-input {
    width: 72px; text-align: center; font-size: 18px; font-weight: 600;
    border: 2px solid #e0e0e0; border-radius: 8px; padding: 9px 4px;
}
.count-input:focus { border-color: #1976d2; outline: none; }
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
.verify-card { transition: background 0.25s; }
.verify-card.verify-neutral { background: linear-gradient(135deg, #90a4ae 0%, #78909c 100%); }
.verify-card.verify-green   { background: linear-gradient(135deg, #43a047 0%, #2e7d32 100%); }
.verify-card.verify-amber   { background: linear-gradient(135deg, #ffb300 0%, #ff8f00 100%); }
.verify-card.verify-red     { background: linear-gradient(135deg, #e53935 0%, #c62828 100%); }
.difference {
    padding: 8px 18px; border-radius: 14px;
    font-weight: 800; font-size: 22px; display: inline-block;
    background: rgba(255,255,255,0.22); color: #fff;
    font-variant-numeric: tabular-nums;
}
.save-btn {
    width: 100%; padding: 14px; font-size: 16px; font-weight: 600;
    border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s;
}
.save-btn:focus, #memo:focus { outline: 3px solid #1976d2; outline-offset: 2px; }
.save-btn.save-ready, .bar-save.save-ready {
    background: #2e7d32; color: #fff; border: none;
    box-shadow: 0 4px 12px rgba(46,125,50,0.3);
}
.save-btn.save-wait, .bar-save.save-wait {
    background: #fff; color: #90a4ae; border: 1.5px solid #90a4ae; box-shadow: none;
}
.save-btn.save-explained, .bar-save.save-explained {
    background: #ffb300; color: #fff; border: none;
    box-shadow: 0 3px 9px rgba(255,143,0,0.3);
}
.save-btn:disabled, .bar-save:disabled {
    background: #bdbdbd; color: #fff; border: none;
    cursor: not-allowed; box-shadow: none;
}
.info-cap {
    font-size: 11px; letter-spacing: 0.08em; color: #78909c; font-weight: 700;
}
.sales-strip { padding: 12px 20px; }
.sales-strip .h5 { font-size: 1rem; margin-bottom: 0; }
.memo-card { transition: border-color 0.25s, background 0.25s, box-shadow 0.25s; }
.memo-card.memo-required {
    border: 2px solid #ffb300; box-shadow: inset 4px 0 0 #ffb300; background: #fff8e1;
}
.memo-card.memo-required textarea { border-color: #ffb300; background: #fffdf8; }
.reset-ghost {
    background: none; border: none; color: #90a4ae;
    font-size: 14px; padding: 4px 10px; cursor: pointer;
}
.reset-ghost:hover { color: #546e7a; }
.mobile-bar { display: none; }
.back-link {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.875rem; color: #1976d2; text-decoration: none;
    margin-bottom: 20px;
}
.back-link:hover { text-decoration: underline; }
@media (max-width: 768px) {
    .cash-type-row {
        display: grid; grid-template-columns: 1fr auto;
        grid-template-areas: "info controls" "amount controls";
        gap: 2px 8px; align-items: center;
    }
    .cash-info { grid-area: info; }
    .count-controls { grid-area: controls; justify-content: flex-end; }
    .amount-display {
        grid-area: amount; text-align: left; min-width: 0;
        font-size: 12px; color: #666;
    }
    .cash-base { display: none; }
    body { padding-bottom: 100px; }
    .mobile-bar {
        display: flex; position: fixed; left: 0; right: 0; bottom: 0; z-index: 1000;
        padding: 8px 16px calc(8px + env(safe-area-inset-bottom));
        align-items: center; justify-content: space-between;
        border-top: 3px solid #78909c; background: #eceff1;
        box-shadow: 0 -4px 14px rgba(0,0,0,0.12);
        transition: background 0.25s, border-color 0.25s, opacity 0.25s;
    }
    .mobile-bar.bar-hidden { opacity: 0; pointer-events: none; }
    .mobile-bar.bar-neutral { background: #eceff1; border-top-color: #78909c; }
    .mobile-bar.bar-green   { background: #e8f5e9; border-top-color: #2e7d32; }
    .mobile-bar.bar-amber   { background: #fff8e1; border-top-color: #ffb300; }
    .mobile-bar.bar-red     { background: #ffebee; border-top-color: #c62828; }
    .bar-label {
        display: block; font-size: 10px; font-weight: 700;
        color: #78909c; letter-spacing: 0.06em;
    }
    .bar-deposit {
        font-size: 20px; font-weight: 800; color: #333;
        font-variant-numeric: tabular-nums; line-height: 1.2;
    }
    .bar-diff { font-size: 12px; font-weight: 700; margin-left: 6px; }
    .bar-save {
        border-radius: 8px; padding: 12px 26px; font-size: 14px;
        font-weight: 700; cursor: pointer; min-height: 48px;
        transition: all 0.2s;
    }
}
@media (prefers-reduced-motion: reduce) {
    .verify-card, .memo-card, .save-btn, .bar-save, .mobile-bar, .cash-type-row { transition: none; }
}
</style>

<?php echo $page_data['system_header']; ?>
<?php echo $page_data['page_header']; ?>

<div class="container-fluid mt-4">
    <a href="cash_management.php" class="back-link">
        <i class="fas fa-arrow-left"></i> 売上金確認に戻る
    </a>

    <!-- 今日の売上情報（参照帯・閲覧のみ） -->
    <div class="count-card sales-strip">
        <div class="row text-center">
            <div class="col-3">
                <div class="info-cap">回数</div>
                <div class="h5 mt-1"><?php echo $today_sales->trip_count; ?>回</div>
            </div>
            <div class="col-3">
                <div class="info-cap">総売上</div>
                <div class="h5 mt-1">¥<?php echo number_format($today_sales->total_sales); ?></div>
            </div>
            <div class="col-3">
                <div class="info-cap">現金</div>
                <div class="h5 mt-1">¥<?php echo number_format($today_sales->cash_sales); ?></div>
            </div>
            <div class="col-3">
                <div class="info-cap">カード</div>
                <div class="h5 mt-1">¥<?php echo number_format($today_sales->card_sales); ?></div>
            </div>
        </div>
    </div>

    <!-- 現金カウント入力 -->
    <div class="count-card">
        <h6 class="mb-3 d-flex align-items-center justify-content-between">
            <span><i class="fas fa-coins" style="color:#ff9800;"></i> 現金カウント
                <span style="font-size:0.8rem;font-weight:400;color:#888;margin-left:8px;">基準おつり: ¥18,000</span>
            </span>
            <button type="button" class="reset-ghost" onclick="resetToBase()" title="基準値にリセット" aria-label="基準値にリセット">
                <i class="fas fa-undo"></i>
            </button>
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
                       min="0" inputmode="numeric" enterkeyhint="next"
                       oninput="recalc()">
                <button class="count-btn plus" onclick="adjustCount('<?php echo $type; ?>', 1)">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            <div class="amount-display" id="amount_<?php echo $type; ?>">
                ¥<?php echo number_format(($existing_count ? $existing_count->$type : $info['count']) * $info['value']); ?>
            </div>
        </div>
        <?php endforeach; ?>

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
    <div class="summary-card verify-card verify-neutral" id="verifyCard">
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
    <div class="count-card memo-card" id="memoCard">
        <h6><i class="fas fa-sticky-note"></i> メモ</h6>
        <textarea id="memo" class="form-control" rows="3"
                  placeholder="差額がある場合は理由を記入してください"><?php echo $existing_count ? htmlspecialchars($existing_count->memo) : ''; ?></textarea>
    </div>

    <!-- 保存ボタン -->
    <button class="save-btn save-wait" id="saveBtn" onclick="saveCashCount()">
        <i class="fas fa-save"></i> 現金カウント保存
    </button>

    <!-- モバイル固定結果バー（カウント中も入金額と差額が常に見える） -->
    <div class="mobile-bar bar-neutral" id="mobileBar">
        <div>
            <span class="bar-label">本日入金額</span>
            <span class="bar-deposit" id="barDeposit">¥0</span>
            <span class="bar-diff" id="barDiff"></span>
        </div>
        <button type="button" class="bar-save save-wait" id="barSaveBtn" onclick="saveCashCount()">
            <i class="fas fa-save"></i> 保存
        </button>
    </div>

    <!-- 過去の履歴 -->
    <?php if (!empty($my_history)): ?>
    <div class="count-card" style="margin-top:24px;">
        <h6 class="mb-3"><i class="fas fa-history" style="color:#90a4ae;"></i> 過去の記録</h6>
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

    // 全信号（照合カード・メモ点灯・保存ボタン・固定バー）の同期をこの1関数に集約する。
    // counted=false（入金額0以下＝数え途中）の間はニュートラルで騒がない。
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
        var counted = depositAmount > 0;
        var memoFilled = document.getElementById('memo').value.trim().length > 0;
        var state = !counted ? 'neutral'
                  : difference === 0 ? 'green'
                  : difference > 0 ? 'amber' : 'red';

        document.getElementById('totalCount').textContent = '¥' + totalCount.toLocaleString();
        document.getElementById('depositAmount').textContent = '¥' + depositAmount.toLocaleString();
        document.getElementById('depositAmount2').textContent = '¥' + depositAmount.toLocaleString();

        // 差額（一致は符号なし¥0＋チェック）
        var diffDisplay = document.getElementById('differenceDisplay');
        if (difference === 0) {
            diffDisplay.innerHTML = '<i class="fas fa-check"></i> ¥0';
        } else {
            diffDisplay.textContent = (difference > 0 ? '+' : '−') + '¥' + Math.abs(difference).toLocaleString();
        }
        diffDisplay.className = 'difference';

        // 照合カード: カード全面が状態を語る
        document.getElementById('verifyCard').className = 'summary-card verify-card verify-' + state;

        var alertEl = document.getElementById('differenceAlert');
        alertEl.style.display = (counted && difference !== 0) ? 'block' : 'none';

        // メモ点灯: 差額あり かつ メモ空 のときだけアンバー
        var memoRequired = counted && difference !== 0 && !memoFilled;
        document.getElementById('memoCard').className = 'count-card memo-card' + (memoRequired ? ' memo-required' : '');

        // 保存ボタン: 緑=保存OK / アンバー=理由付きで保存可 / アウトライン=まだ
        var saveState = (counted && difference === 0) ? 'save-ready'
                      : (counted && memoFilled) ? 'save-explained'
                      : 'save-wait';
        document.getElementById('saveBtn').className = 'save-btn ' + saveState;

        // モバイル固定バー
        var bar = document.getElementById('mobileBar');
        bar.className = 'mobile-bar bar-' + state + (bar.dataset.hidden === '1' ? ' bar-hidden' : '');
        document.getElementById('barDeposit').textContent = '¥' + depositAmount.toLocaleString();
        var barDiff = document.getElementById('barDiff');
        if (!counted) {
            barDiff.textContent = '';
        } else if (difference === 0) {
            barDiff.textContent = '✓ 一致';
            barDiff.style.color = '#2e7d32';
        } else {
            barDiff.textContent = '差額 ' + (difference > 0 ? '+' : '−') + '¥' + Math.abs(difference).toLocaleString();
            barDiff.style.color = difference > 0 ? '#b26a00' : '#c62828';
        }
        document.getElementById('barSaveBtn').className = 'bar-save ' + saveState;

        return { difference: difference, counted: counted, memoFilled: memoFilled };
    }

    function resetToBase() {
        if (!confirm('カウントを基準値に戻しますか？（入力した枚数は消えます）')) return;
        var keys = Object.keys(baseChange);
        for (var i = 0; i < keys.length; i++) {
            document.getElementById(keys[i]).value = baseChange[keys[i]].count;
        }
        recalc();
    }

    function saveCashCount() {
        var btn = document.getElementById('saveBtn');
        var barBtn = document.getElementById('barSaveBtn');
        btn.disabled = true;
        if (barBtn) barBtn.disabled = true;
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
                // トーストが見えるよう1.4秒遅延してリロード
                setTimeout(function() { window.location.reload(); }, 1400);
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
            if (barBtn) barBtn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> 現金カウント保存';
        });
    }

    // ===== 共通トースト関数 =====
    function showToast(message, type) {
        type = type || 'info';
        var colors = {
            success: '#38A169',
            danger:  '#E53E3E',
            warning: '#D69E2E',
            info:    '#1A202C'
        };
        var icons = {
            success: '✓',
            danger:  '✕',
            warning: '!',
            info:    'i'
        };
        var toast = document.getElementById('wtsToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'wtsToast';
            toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:#1A202C;color:#fff;padding:14px 24px;border-radius:12px;font-size:15px;font-weight:600;box-shadow:0 12px 32px rgba(0,0,0,.25);z-index:9999;opacity:0;transition:all .25s ease-out;max-width:90vw;display:flex;align-items:center;gap:10px;font-family:"Zen Maru Gothic","Hiragino Maru Gothic ProN","Yu Gothic",system-ui,sans-serif;';
            document.body.appendChild(toast);
        }
        toast.style.background = colors[type] || colors.info;
        toast.innerHTML = '<span style="font-size:18px;font-weight:700;">' + (icons[type] || icons.info) + '</span><span></span>';
        toast.lastChild.textContent = message;
        requestAnimationFrame(function() {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        });
        clearTimeout(toast._timer);
        toast._timer = setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(100px)';
        }, 3000);
    }

    // ===== テンポ入力（PC向け）=====
    // フォーカスで全選択（数字を打つだけで上書き）、Enterで次の金種へ、最後はEnterで保存ボタンへ
    var countInputs = Array.prototype.slice.call(document.querySelectorAll('.count-input'));
    countInputs.forEach(function(input, idx) {
        input.addEventListener('focus', function() {
            var self = this;
            setTimeout(function() { self.select(); }, 0);
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var st = recalc();
                if (idx + 1 < countInputs.length) {
                    countInputs[idx + 1].focus();
                } else if (st.counted && st.difference !== 0 && !st.memoFilled) {
                    // 差額あり: 保存の前にメモへ（照合カードを通る動線）
                    var memoEl = document.getElementById('memo');
                    memoEl.focus();
                    memoEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
                } else {
                    document.getElementById('saveBtn').focus();
                }
            }
        });
    });

    // メモの記入で信号（メモ点灯・保存ボタン）を追従させる
    document.getElementById('memo').addEventListener('input', recalc);

    // モバイル: フォーカス行をキーボードの上（画面中央）へ / ±の触感フィードバック
    if ('ontouchstart' in window) {
        countInputs.forEach(function(input) {
            input.addEventListener('focus', function() {
                var self = this;
                setTimeout(function() {
                    self.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }, 250);
            });
        });
        document.querySelectorAll('.count-btn').forEach(function(btn) {
            btn.addEventListener('touchstart', function() {
                if (navigator.vibrate) navigator.vibrate(10);
            }, { passive: true });
        });
    }

    // 本体の保存ボタンが見えている間は固定バーを引っ込める（二重表示回避）
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function(entries) {
            var bar = document.getElementById('mobileBar');
            bar.dataset.hidden = entries[0].isIntersecting ? '1' : '0';
            bar.classList.toggle('bar-hidden', entries[0].isIntersecting);
        }, { threshold: 0.4 }).observe(document.getElementById('saveBtn'));
    }

    document.addEventListener('DOMContentLoaded', function() {
        recalc();
        // PC（非タッチ環境）では先頭の金種に自動フォーカス（開いてすぐ打てる）
        if (!('ontouchstart' in window) && countInputs.length > 0) {
            countInputs[0].focus();
        }
    });
</script>

<?php echo $page_data['html_footer'] ?? ''; ?>
</body>
</html>
