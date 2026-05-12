<?php
// 寝屋川市重度障害者(児)タクシー利用券 請求書
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/session_check.php';
require_once 'includes/unified-header.php';

$pdo = getDBConnection();

// 会社情報を取得
$company = [
    'company_name' => '',
    'address' => '',
    'postal_code' => '',
    'phone' => '',
    'representative_name' => '',
];
try {
    $stmt = $pdo->prepare("SELECT company_name, address, postal_code, phone, representative_name FROM company_info WHERE id = 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) {
        $company = array_merge($company, $row);
    }
} catch (PDOException $e) {
    error_log('タクシー利用券請求書 会社情報取得エラー: ' . $e->getMessage());
}

// 今日の日付（令和）
$today = new DateTime();
$reiwa_year = (int)$today->format('Y') - 2018; // 令和元年=2019
$today_month = (int)$today->format('n');
$today_day = (int)$today->format('j');

// 月別明細の乗車料金（紙様式どおり）
$fare_rows = [680, 660, 640, 620, 610, 600, 590, 580, 570];

// 請求対象の3か月（直近3か月をデフォルトで提案）
$default_months = [];
for ($i = 3; $i >= 1; $i--) {
    $d = new DateTime();
    $d->modify("-{$i} month");
    $default_months[] = [
        'year' => (int)$d->format('Y'),
        'month' => (int)$d->format('n'),
    ];
}

$page_config = getPageConfiguration('taxi_ticket_invoice');
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'taxi_ticket_invoice',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    [
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => 'マスターメニュー', 'url' => 'master_menu.php'],
            ['text' => $page_config['title'], 'url' => 'taxi_ticket_invoice.php'],
        ],
    ]
);
?>
<?= $page_data['html_head'] ?>
<style>
/* ===== 画面（編集）レイアウト ===== */
.invoice-toolbar {
    position: sticky; top: 0; z-index: 10;
    background: #fff; padding: 12px 16px; margin-bottom: 16px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.invoice-toolbar .btn { white-space: nowrap; }
.invoice-toolbar .toolbar-hint { color: #64748b; font-size: 0.85rem; margin-left: auto; }

.invoice-page {
    background: #fff;
    width: 297mm; min-height: 210mm;
    margin: 0 auto;
    padding: 12mm 12mm;
    box-shadow: 0 0 12px rgba(0,0,0,0.15);
    font-family: "Yu Mincho", "YuMincho", "MS Mincho", "Hiragino Mincho ProN", serif;
    color: #000;
    font-size: 11pt;
    line-height: 1.5;
    position: relative;
}

.invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6mm; }
.invoice-header .to-city { font-size: 12pt; }
.invoice-header .title { font-size: 22pt; font-weight: bold; letter-spacing: 0.6em; flex: 1; text-align: center; }
.invoice-header .note { font-size: 9pt; color: #333; }

.invoice-body { display: grid; grid-template-columns: 1fr 1.4fr 1fr; gap: 8mm; align-items: start; }

.block h3 { font-size: 11pt; font-weight: bold; margin: 0 0 2mm; }

/* テーブル共通 */
table.tt { border-collapse: collapse; width: 100%; }
table.tt th, table.tt td { border: 0.7pt solid #000; padding: 1.5mm 2mm; font-size: 10pt; text-align: center; vertical-align: middle; }
table.tt th { background: #f5f5f5; font-weight: normal; }
table.tt input[type="number"], table.tt input[type="text"] {
    width: 100%; border: none; background: transparent; text-align: center;
    font-family: inherit; font-size: 10pt; padding: 0;
}
table.tt input[type="number"]::-webkit-outer-spin-button,
table.tt input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
table.tt input[type="number"] { -moz-appearance: textfield; }
table.tt td.readout { background: #fafafa; font-weight: bold; }
table.tt tr.sum td { background: #f0f4f8; font-weight: bold; }

/* 内訳ブロック */
.req-total-row { display: flex; align-items: center; gap: 2mm; margin-bottom: 2mm; }
.req-total-row label { white-space: nowrap; font-weight: bold; }
.req-total-row .total-box {
    flex: 1; border: 0.7pt solid #000; padding: 2mm 3mm; min-height: 9mm;
    background: #fafafa; text-align: right; font-size: 14pt; font-weight: bold;
}
.req-total-row .unit { font-size: 12pt; }

/* 月のラベルの入力 */
.month-input { display: inline-flex; align-items: center; gap: 1mm; justify-content: center; }
.month-input input.year { width: 16mm; }
.month-input input.mon { width: 10mm; }
.month-input span { font-size: 9pt; }

/* 事業者ブロック */
.date-line { text-align: right; margin-bottom: 4mm; }
.date-line input { width: 12mm; border: none; border-bottom: 0.5pt solid #000; text-align: center; background: transparent; font-family: inherit; font-size: 11pt; }
.confirm-line { margin-bottom: 3mm; font-size: 11pt; }

.company-table { border-collapse: collapse; width: 100%; }
.company-table th, .company-table td { border: 0.7pt solid #000; padding: 2mm 3mm; font-size: 10pt; vertical-align: middle; }
.company-table th { width: 30mm; text-align: center; background: #f5f5f5; font-weight: normal; }
.company-table td { min-height: 8mm; }
.company-table input { width: 100%; border: none; background: transparent; font-family: inherit; font-size: 10pt; padding: 0; }

/* 印刷モード */
@media print {
    @page { size: A4 landscape; margin: 0; }
    body { background: #fff !important; }
    .system-header, .page-header, .navbar, .toast-container,
    .invoice-toolbar, footer, .breadcrumb, .breadcrumb-container,
    .container > h1, .container > h2, .pageHeader { display: none !important; }
    .container, .container-fluid { padding: 0 !important; margin: 0 !important; max-width: none !important; }
    .invoice-page { box-shadow: none; margin: 0; width: 297mm; min-height: 210mm; padding: 10mm 10mm; }
    table.tt input, .company-table input, .date-line input { color: #000; }
    /* 0 表示を非表示にする（紙の様式に近づける） */
    .hide-zero-print[data-value="0"] { color: transparent; }
}
</style>
</head>
<body>
<?= $page_data['system_header'] ?>
<?= $page_data['page_header'] ?>

<div class="container-fluid mt-3">

    <div class="invoice-toolbar no-print">
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>印刷 / PDF保存
        </button>
        <button class="btn btn-outline-secondary" onclick="resetForm()">
            <i class="fas fa-eraser me-1"></i>入力クリア
        </button>
        <span class="toolbar-hint">
            乗車料金（680〜570円）× 月別の枚数を入力すると、金額・合計が自動計算されます。
        </span>
    </div>

    <div class="invoice-page" id="invoicePage">

        <!-- ヘッダー -->
        <div class="invoice-header">
            <div class="to-city">寝屋川市長　様</div>
            <div class="title">請　求　書</div>
            <div class="note">※コピーして使って下さい</div>
        </div>

        <!-- ボディ：3カラム -->
        <div class="invoice-body">

            <!-- 左：内訳 -->
            <div class="block">
                <div class="req-total-row">
                    <label>請求金額</label>
                    <div class="total-box"><span id="grandTotalDisplay">0</span></div>
                    <span class="unit">円</span>
                </div>

                <table class="tt" id="breakdownTable">
                    <thead>
                        <tr>
                            <th>請求月</th>
                            <th>枚数</th>
                            <th>金額</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < 3; $i++): ?>
                        <tr>
                            <td>
                                <div class="month-input">
                                    <input type="number" class="year req-year" min="2020" max="2099"
                                           value="<?= htmlspecialchars((string)$default_months[$i]['year']) ?>" data-col="<?= $i ?>"><span>年</span>
                                    <input type="number" class="mon req-month" min="1" max="12"
                                           value="<?= htmlspecialchars((string)$default_months[$i]['month']) ?>" data-col="<?= $i ?>"><span>月</span>
                                </div>
                            </td>
                            <td class="readout"><span class="month-count" data-col="<?= $i ?>">0</span> 枚</td>
                            <td class="readout"><span class="month-amount" data-col="<?= $i ?>">0</span> 円</td>
                        </tr>
                        <?php endfor; ?>
                        <tr class="sum">
                            <td>合　計</td>
                            <td><span id="totalCount">0</span> 枚</td>
                            <td><span id="totalAmount">0</span> 円</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 中央：月別明細 -->
            <div class="block">
                <h3>月別明細</h3>
                <table class="tt" id="detailTable">
                    <thead>
                        <tr>
                            <th rowspan="2">乗車料金<br>（円）</th>
                            <?php for ($i = 0; $i < 3; $i++): ?>
                            <th colspan="2">
                                <span class="detail-month-label" data-col="<?= $i ?>">
                                    <?= $default_months[$i]['month'] ?>
                                </span>月分
                            </th>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($i = 0; $i < 3; $i++): ?>
                            <th>枚数</th>
                            <th>金額</th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fare_rows as $fare): ?>
                        <tr data-fare="<?= $fare ?>">
                            <td><?= $fare ?></td>
                            <?php for ($i = 0; $i < 3; $i++): ?>
                            <td><input type="number" class="count-input" min="0" step="1"
                                       data-col="<?= $i ?>" data-fare="<?= $fare ?>" placeholder=""></td>
                            <td class="readout"><span class="cell-amount" data-col="<?= $i ?>" data-fare="<?= $fare ?>"></span></td>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                        <!-- 空欄行（その他料金用） -->
                        <tr data-fare="other">
                            <td><input type="number" class="fare-other" min="0" step="10" placeholder=""></td>
                            <?php for ($i = 0; $i < 3; $i++): ?>
                            <td><input type="number" class="count-input" min="0" step="1"
                                       data-col="<?= $i ?>" data-fare="other" placeholder=""></td>
                            <td class="readout"><span class="cell-amount" data-col="<?= $i ?>" data-fare="other"></span></td>
                            <?php endfor; ?>
                        </tr>
                        <tr class="sum">
                            <td>合　計</td>
                            <?php for ($i = 0; $i < 3; $i++): ?>
                            <td><span class="month-count" data-col="<?= $i ?>">0</span></td>
                            <td><span class="month-amount" data-col="<?= $i ?>">0</span></td>
                            <?php endfor; ?>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 右：事業者情報 -->
            <div class="block">
                <div class="date-line">
                    令和 <input type="number" id="dateYear" min="1" max="99" value="<?= $reiwa_year ?>"> 年
                    <input type="number" id="dateMonth" min="1" max="12" value="<?= $today_month ?>"> 月
                    <input type="number" id="dateDay" min="1" max="31" value="<?= $today_day ?>"> 日
                </div>
                <div class="confirm-line">上記のとおり請求します。</div>

                <table class="company-table">
                    <tr>
                        <th rowspan="4" style="writing-mode: vertical-rl; text-orientation: upright; width: 8mm; padding: 0; letter-spacing: 0.3em;">請求事業者</th>
                        <th>住　所</th>
                        <td><input type="text" id="companyAddress" value="<?= htmlspecialchars(($company['postal_code'] ? '〒' . $company['postal_code'] . '　' : '') . $company['address']) ?>"></td>
                    </tr>
                    <tr>
                        <th>電話番号</th>
                        <td><input type="text" id="companyPhone" value="<?= htmlspecialchars($company['phone']) ?>"></td>
                    </tr>
                    <tr>
                        <th>名　称</th>
                        <td><input type="text" id="companyName" value="<?= htmlspecialchars($company['company_name']) ?>"></td>
                    </tr>
                    <tr>
                        <th>代表者氏名</th>
                        <td><input type="text" id="companyRep" value="<?= htmlspecialchars($company['representative_name']) ?>"></td>
                    </tr>
                </table>
            </div>

        </div>
    </div>
</div>

<script>
(function() {
    const FARE_ROWS = <?= json_encode($fare_rows) ?>;
    const NUM_COLS = 3;

    function fmt(n) {
        return (n || 0).toLocaleString('ja-JP');
    }

    function getFareValue(fare) {
        if (fare === 'other') {
            const el = document.querySelector('.fare-other');
            return parseInt(el && el.value, 10) || 0;
        }
        return parseInt(fare, 10) || 0;
    }

    function recalc() {
        const monthCount = [0, 0, 0];
        const monthAmount = [0, 0, 0];

        // 各セルの金額を再計算
        document.querySelectorAll('.count-input').forEach(input => {
            const col = parseInt(input.dataset.col, 10);
            const fare = input.dataset.fare;
            const count = parseInt(input.value, 10) || 0;
            const fareValue = getFareValue(fare);
            const amount = count * fareValue;

            const amountCell = document.querySelector(`.cell-amount[data-col="${col}"][data-fare="${fare}"]`);
            if (amountCell) amountCell.textContent = amount > 0 ? fmt(amount) : '';

            monthCount[col] += count;
            monthAmount[col] += amount;
        });

        // 月別合計
        for (let col = 0; col < NUM_COLS; col++) {
            document.querySelectorAll(`.month-count[data-col="${col}"]`).forEach(el => el.textContent = fmt(monthCount[col]));
            document.querySelectorAll(`.month-amount[data-col="${col}"]`).forEach(el => el.textContent = fmt(monthAmount[col]));
        }

        // 総合計
        const totalC = monthCount.reduce((a, b) => a + b, 0);
        const totalA = monthAmount.reduce((a, b) => a + b, 0);
        document.getElementById('totalCount').textContent = fmt(totalC);
        document.getElementById('totalAmount').textContent = fmt(totalA);
        document.getElementById('grandTotalDisplay').textContent = fmt(totalA);
    }

    function syncMonthLabels() {
        document.querySelectorAll('.req-month').forEach(input => {
            const col = input.dataset.col;
            const monthLabel = document.querySelector(`.detail-month-label[data-col="${col}"]`);
            if (monthLabel) monthLabel.textContent = input.value || '';
        });
    }

    document.addEventListener('input', (e) => {
        if (e.target.matches('.count-input, .fare-other')) recalc();
        if (e.target.matches('.req-month')) syncMonthLabels();
    });

    window.resetForm = function() {
        if (!confirm('入力内容をクリアしますか？')) return;
        document.querySelectorAll('.count-input, .fare-other').forEach(el => el.value = '');
        recalc();
    };

    syncMonthLabels();
    recalc();
})();
</script>

<?= $page_data['html_footer'] ?>
