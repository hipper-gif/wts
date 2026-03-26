<?php
/**
 * 日常点検記録 印刷用テンプレート
 *
 * 日次モード: ?date=2026-03-26&driver_id=1 (driver_id=all で全員分)
 * 月次モード: ?month=2026-03&vehicle_id=1  (車両単位で1ヶ月分一覧)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/session_check.php';

$pdo = getDBConnection();

// --- 会社情報取得 ---
$company_name = '';
try {
    $stmt = $pdo->query("SELECT company_name FROM company_info WHERE id = 1 LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $company_name = $row['company_name'];
    }
} catch (Exception $e) {
    // テーブル未作成の場合は空文字のまま
}

// --- 点検項目定義（カラム名 => 日本語ラベル） ---
$inspection_categories = [
    '運転室周り' => [
        'foot_brake_result'          => 'フットブレーキの踏み代・効き',
        'parking_brake_result'       => 'パーキングブレーキの引き代',
        'engine_start_result'        => 'エンジンのかかり具合・異音',
        'engine_performance_result'  => 'エンジンの低速・加速',
        'wiper_result'               => 'ワイパーのふき取り能力',
        'washer_spray_result'        => 'ウインドウォッシャー液の噴射状態',
    ],
    'エンジンルーム' => [
        'brake_fluid_result'   => 'ブレーキ液量',
        'coolant_result'       => '冷却水量',
        'engine_oil_result'    => 'エンジンオイル量',
        'battery_fluid_result' => 'バッテリー液量',
        'washer_fluid_result'  => 'ウインドウォッシャー液量',
        'fan_belt_result'      => 'ファンベルトの張り・損傷',
    ],
    '灯火類・タイヤ' => [
        'lights_result'        => '灯火類の点灯・点滅',
        'lens_result'          => 'レンズの損傷・汚れ',
        'tire_pressure_result' => 'タイヤの空気圧',
        'tire_damage_result'   => 'タイヤの亀裂・損傷',
        'tire_tread_result'    => 'タイヤ溝の深さ',
    ],
];

// 全項目のカラム名をフラットに
$all_item_columns = [];
foreach ($inspection_categories as $items) {
    foreach ($items as $col => $label) {
        $all_item_columns[] = $col;
    }
}

/**
 * 点検結果値を表示記号に変換
 */
function resultSymbol($value) {
    if ($value === null || $value === '' || $value === '省略') return '-';
    if ($value === '可') return "\u{25CB}"; // ○
    if ($value === '否') return "\u{00D7}"; // ×
    return "\u{25B3}"; // △
}

/**
 * 全項目が良好かどうか判定
 */
function judgeOverall($record, $columns) {
    $has_bad = false;
    $all_skipped = true;
    foreach ($columns as $col) {
        $v = $record[$col] ?? '省略';
        if ($v === '否') $has_bad = true;
        if ($v !== '省略') $all_skipped = false;
    }
    if ($all_skipped) return ['label' => '未実施', 'class' => 'judge-skip'];
    if ($has_bad) return ['label' => '要確認', 'class' => 'judge-bad'];
    return ['label' => '運行可', 'class' => 'judge-ok'];
}

// --- モード判定 ---
$mode = '';
if (isset($_GET['month']) && isset($_GET['vehicle_id'])) {
    $mode = 'monthly';
} elseif (isset($_GET['date'])) {
    $mode = 'daily';
} else {
    echo '<p>パラメータが不正です。date または month, vehicle_id を指定してください。</p>';
    exit;
}

// =====================================================
// 月次モード
// =====================================================
if ($mode === 'monthly') {
    $month = $_GET['month']; // 例: 2026-03
    $vehicle_id = (int)$_GET['vehicle_id'];

    // 対象月の年・月
    $year_num  = (int)date('Y', strtotime($month . '-01'));
    $month_num = (int)date('n', strtotime($month . '-01'));
    $days_in_month = (int)date('t', strtotime($month . '-01'));

    // 車両情報
    $stmt = $pdo->prepare("SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vehicle) {
        echo '<p>車両が見つかりません。</p>';
        exit;
    }

    // 月間の点検データ取得
    $stmt = $pdo->prepare("
        SELECT di.*, u.name AS driver_name
        FROM daily_inspections di
        LEFT JOIN users u ON di.driver_id = u.id
        WHERE di.vehicle_id = ?
          AND di.inspection_date >= ?
          AND di.inspection_date <= ?
        ORDER BY di.inspection_date
    ");
    $date_from = $month . '-01';
    $date_to   = $month . '-' . str_pad($days_in_month, 2, '0', STR_PAD_LEFT);
    $stmt->execute([$vehicle_id, $date_from, $date_to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 日付をキーにしたマップ
    $data_by_day = [];
    foreach ($rows as $r) {
        $day = (int)date('j', strtotime($r['inspection_date']));
        $data_by_day[$day] = $r;
    }

    // 実施率計算
    $implemented_days = count($data_by_day);
    $rate = $days_in_month > 0 ? round($implemented_days / $days_in_month * 100, 1) : 0;

    // --- 出力開始 ---
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>日常点検記録簿 <?= h($year_num) ?>年<?= h($month_num) ?>月 - <?= h($vehicle['vehicle_number']) ?></title>
<style>
@page { size: A4 landscape; margin: 8mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: "Yu Gothic", "YuGothic", "Meiryo", "Hiragino Sans", sans-serif;
    font-size: 8.5pt;
    color: #000;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.print-controls {
    text-align: center; margin: 10px 0; padding: 12px; background: #f0f0f0; border-radius: 8px;
}
.print-controls button {
    padding: 8px 24px; font-size: 12pt; cursor: pointer; margin: 0 5px;
    border-radius: 4px; border: 1px solid #ccc;
}
.print-controls .btn-print { background: #2196F3; color: #fff; border-color: #1976D2; }
.print-controls .btn-back { background: #757575; color: #fff; border-color: #616161; }

@media print {
    .print-controls { display: none !important; }
}

.report {
    max-width: 297mm; margin: 0 auto; padding: 4mm;
}
.report-title {
    text-align: center; font-size: 14pt; font-weight: bold; letter-spacing: 0.5em;
    margin-bottom: 4px;
}
.report-meta {
    display: flex; justify-content: space-between; font-size: 9pt; margin-bottom: 6px;
    border-bottom: 1px solid #000; padding-bottom: 4px;
}

table.monthly {
    width: 100%; border-collapse: collapse; table-layout: fixed;
}
table.monthly th, table.monthly td {
    border: 1px solid #000; text-align: center; padding: 1px 2px;
    vertical-align: middle; line-height: 1.3;
    overflow: hidden; white-space: nowrap;
}
table.monthly th {
    background: #f5f5f5; font-weight: bold;
}
table.monthly th.item-col {
    width: 140px; text-align: left; padding-left: 6px; white-space: normal;
}
table.monthly th.day-col {
    width: auto; font-size: 7.5pt;
}
table.monthly td.item-label {
    text-align: left; padding-left: 8px; font-size: 8pt; white-space: normal;
}
table.monthly tr.category-row th {
    background: #e8e8e8; text-align: left; padding-left: 6px; font-size: 8pt;
}
table.monthly tr.footer-row td {
    font-size: 7.5pt;
}
table.monthly .result-ok { color: #000; }
table.monthly .result-bad { color: #000; font-weight: bold; }
table.monthly .result-skip { color: #999; }

.legend {
    font-size: 8pt; margin-top: 6px; padding: 4px 8px;
    border: 1px solid #000;
}
.legend-row { display: flex; justify-content: space-between; }

.sun { color: #c00; }
.sat { color: #00c; }
</style>
</head>
<body>

<div class="print-controls">
    <button class="btn-print" onclick="window.print()"><b>印刷 / PDF保存</b></button>
    <button class="btn-back" onclick="history.back()">戻る</button>
</div>

<div class="report">
    <div class="report-title">日 常 点 検 記 録 簿</div>
    <div class="report-meta">
        <span>事業者名: <?= h($company_name ?: '―') ?></span>
        <span>車両: <?= h($vehicle['vehicle_number']) ?><?= !empty($vehicle['vehicle_name']) ? ' (' . h($vehicle['vehicle_name']) . ')' : '' ?></span>
        <span>対象月: <?= h($year_num) ?>年<?= h($month_num) ?>月</span>
    </div>

    <table class="monthly">
        <thead>
            <tr>
                <th class="item-col">点検項目</th>
                <?php for ($d = 1; $d <= $days_in_month; $d++):
                    $dt = mktime(0, 0, 0, $month_num, $d, $year_num);
                    $dow = date('w', $dt);
                    $cls = $dow == 0 ? ' class="sun"' : ($dow == 6 ? ' class="sat"' : '');
                ?>
                <th class="day-col"<?= $cls ?>><?= $d ?></th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inspection_categories as $cat_name => $items): ?>
            <tr class="category-row">
                <th colspan="<?= $days_in_month + 1 ?>">【<?= h($cat_name) ?>】</th>
            </tr>
            <?php foreach ($items as $col => $label): ?>
            <tr>
                <td class="item-label"><?= h($label) ?></td>
                <?php for ($d = 1; $d <= $days_in_month; $d++):
                    $rec = $data_by_day[$d] ?? null;
                    $val = $rec ? ($rec[$col] ?? '省略') : null;
                    $sym = $rec ? resultSymbol($val) : '';
                    $cls = '';
                    if ($val === '否') $cls = 'result-bad';
                    elseif ($val === '省略' || $val === null) $cls = 'result-skip';
                    else $cls = 'result-ok';
                ?>
                <td class="<?= $cls ?>"><?= $sym ?></td>
                <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>

            <!-- 点検者 -->
            <tr class="footer-row">
                <td class="item-label" style="font-weight:bold;">点検者</td>
                <?php for ($d = 1; $d <= $days_in_month; $d++):
                    $rec = $data_by_day[$d] ?? null;
                    // 姓だけ表示（スペース節約）
                    $name = '';
                    if ($rec && !empty($rec['driver_name'])) {
                        $parts = preg_split('/[\s　]+/u', $rec['driver_name']);
                        $name = mb_substr($parts[0], 0, 2);
                    }
                ?>
                <td><?= h($name) ?></td>
                <?php endfor; ?>
            </tr>

            <!-- 走行距離 -->
            <tr class="footer-row">
                <td class="item-label" style="font-weight:bold;">走行距離(km)</td>
                <?php for ($d = 1; $d <= $days_in_month; $d++):
                    $rec = $data_by_day[$d] ?? null;
                    $ml = ($rec && isset($rec['mileage'])) ? number_format((int)$rec['mileage']) : '';
                ?>
                <td><?= $ml ?></td>
                <?php endfor; ?>
            </tr>
        </tbody>
    </table>

    <div class="legend">
        <div class="legend-row">
            <span>○=良好　×=異常あり　△=要経過観察　-=未実施（省略）</span>
            <span>実施率: <?= $implemented_days ?>/<?= $days_in_month ?>日 (<?= $rate ?>%)</span>
        </div>
    </div>
</div>

</body>
</html>
<?php
    exit;
}

// =====================================================
// 日次モード
// =====================================================
if ($mode === 'daily') {
    $date = $_GET['date'];
    $driver_id = $_GET['driver_id'] ?? 'all';

    // 日付表示用
    $date_obj = new DateTime($date);
    $weekdays = ['日','月','火','水','木','金','土'];
    $date_display = $date_obj->format('Y年n月j日') . '(' . $weekdays[(int)$date_obj->format('w')] . ')';

    // データ取得
    $sql = "
        SELECT di.*, u.name AS driver_name, v.vehicle_number, v.vehicle_name
        FROM daily_inspections di
        LEFT JOIN users u ON di.driver_id = u.id
        LEFT JOIN vehicles v ON di.vehicle_id = v.id
        WHERE di.inspection_date = ?
    ";
    $params = [$date];

    if ($driver_id !== 'all') {
        $sql .= " AND di.driver_id = ?";
        $params[] = (int)$driver_id;
    }
    $sql .= " ORDER BY u.name, v.vehicle_number";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>日常点検記録</title>';
        echo '<style>.print-controls{text-align:center;margin:20px;}.print-controls button{padding:8px 24px;font-size:12pt;cursor:pointer;margin:0 5px;border-radius:4px;border:1px solid #ccc;background:#757575;color:#fff;}</style>';
        echo '</head><body>';
        echo '<div class="print-controls"><button onclick="history.back()">戻る</button></div>';
        echo '<p style="text-align:center;font-size:14pt;margin-top:40px;">指定された条件の点検記録はありません。</p>';
        echo '</body></html>';
        exit;
    }

    // --- 出力開始 ---
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>日常点検記録 <?= h($date_display) ?></title>
<style>
@page { size: A4 portrait; margin: 10mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: "Yu Gothic", "YuGothic", "Meiryo", "Hiragino Sans", sans-serif;
    font-size: 10pt;
    color: #000;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.print-controls {
    text-align: center; margin: 10px 0; padding: 12px; background: #f0f0f0; border-radius: 8px;
}
.print-controls button {
    padding: 8px 24px; font-size: 12pt; cursor: pointer; margin: 0 5px;
    border-radius: 4px; border: 1px solid #ccc;
}
.print-controls .btn-print { background: #2196F3; color: #fff; border-color: #1976D2; }
.print-controls .btn-back { background: #757575; color: #fff; border-color: #616161; }

@media print {
    .print-controls { display: none !important; }
    .daily-record { page-break-after: always; }
    .daily-record:last-child { page-break-after: auto; }
}

.daily-record {
    max-width: 190mm; margin: 0 auto 20px; padding: 8mm;
    border: 2px solid #000;
}
.record-title {
    text-align: center; font-size: 16pt; font-weight: bold; letter-spacing: 0.5em;
    margin-bottom: 10px; border-bottom: 2px solid #000; padding-bottom: 8px;
}
.record-meta {
    display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px;
    font-size: 10pt; margin-bottom: 12px; padding-bottom: 8px;
    border-bottom: 1px solid #aaa;
}
.record-meta .meta-item { display: flex; }
.record-meta .meta-label { font-weight: bold; min-width: 80px; }

.category-title {
    font-size: 10pt; font-weight: bold; margin: 10px 0 6px;
    padding: 3px 8px; background: #f0f0f0; border-left: 3px solid #333;
}
.check-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 2px 16px;
    margin-bottom: 4px; padding-left: 12px;
}
.check-item {
    font-size: 9.5pt; padding: 2px 0;
}
.check-ok::before { content: "\2611 "; }
.check-bad::before { content: "\2612 "; font-weight: bold; }
.check-skip::before { content: "\2610 "; color: #999; }

.record-footer {
    margin-top: 14px; padding-top: 8px; border-top: 2px solid #000;
}
.judge-row {
    font-size: 11pt; font-weight: bold; margin-bottom: 8px;
}
.judge-ok { color: #000; }
.judge-bad { color: #000; text-decoration: underline; }
.judge-skip { color: #666; }

.defect-section {
    margin-top: 6px; padding: 6px 8px; border: 1px solid #aaa; min-height: 30px;
    font-size: 9pt;
}
.defect-section .defect-label { font-weight: bold; margin-bottom: 2px; }

.signature-row {
    display: flex; justify-content: space-around; margin-top: 16px;
    font-size: 10pt;
}
.signature-box {
    text-align: center; width: 40%;
}
.signature-line {
    border-bottom: 1px solid #000; height: 28px; margin-top: 4px;
}
</style>
</head>
<body>

<div class="print-controls">
    <button class="btn-print" onclick="window.print()"><b>印刷 / PDF保存</b></button>
    <button class="btn-back" onclick="history.back()">戻る</button>
</div>

<?php foreach ($records as $rec):
    $judge = judgeOverall($rec, $all_item_columns);
    $mileage_display = isset($rec['mileage']) ? number_format((int)$rec['mileage']) : '―';
    $time_display = !empty($rec['inspection_time']) ? date('H:i', strtotime($rec['inspection_time'])) : '―';
?>
<div class="daily-record">
    <div class="record-title">日 常 点 検 記 録</div>
    <div class="record-meta">
        <div class="meta-item"><span class="meta-label">日付:</span> <?= h($date_display) ?> <?= h($time_display) ?></div>
        <div class="meta-item"><span class="meta-label">点検者:</span> <?= h($rec['driver_name'] ?? '―') ?></div>
        <div class="meta-item"><span class="meta-label">車両:</span> <?= h($rec['vehicle_number'] ?? '―') ?><?= !empty($rec['vehicle_name']) ? ' (' . h($rec['vehicle_name']) . ')' : '' ?></div>
        <div class="meta-item"><span class="meta-label">走行距離:</span> <?= $mileage_display ?> km</div>
    </div>

    <?php foreach ($inspection_categories as $cat_name => $items): ?>
    <div class="category-title">【<?= h($cat_name) ?>】</div>
    <div class="check-grid">
        <?php foreach ($items as $col => $label):
            $val = $rec[$col] ?? '省略';
            if ($val === '可') $cls = 'check-ok';
            elseif ($val === '否') $cls = 'check-bad';
            else $cls = 'check-skip';
        ?>
        <div class="check-item <?= $cls ?>"><?= h($label) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php
    $defect = trim($rec['defect_details'] ?? '');
    $remarks = trim($rec['remarks'] ?? '');
    if ($defect !== '' || $remarks !== ''):
    ?>
    <div class="defect-section">
        <?php if ($defect !== ''): ?>
        <div class="defect-label">不具合詳細:</div>
        <div><?= h($defect) ?></div>
        <?php endif; ?>
        <?php if ($remarks !== ''): ?>
        <div class="defect-label" style="margin-top:4px;">備考:</div>
        <div><?= h($remarks) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="record-footer">
        <div class="judge-row <?= $judge['class'] ?>">
            判定: <?= h($judge['label']) ?>
            <?php if ($judge['label'] === '運行可'): ?>
                &#8212; 全項目良好
            <?php elseif ($judge['label'] === '要確認'): ?>
                &#8212; 異常項目あり
            <?php endif; ?>
        </div>
        <div class="signature-row">
            <div class="signature-box">
                点検者署名
                <div class="signature-line"></div>
            </div>
            <div class="signature-box">
                確認者署名
                <div class="signature-line"></div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

</body>
</html>
<?php
    exit;
}
?>
