<?php
/**
 * 自動車事故報告書 印刷用テンプレート
 * パラメータ:
 *   ?id=1      — 特定の事故報告書を表示（A4縦向き）
 *   ?year=2026 — 年間の事故一覧表を表示（A4横向き）
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session_check.php';

// 権限チェック（Admin のみ）
requireRole('Admin');

$pdo = getDBConnection();

// パラメータ取得
$accident_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;

// パラメータなしの場合は当年の一覧へ
if (!$accident_id && !$year) {
    $year = intval(date('Y'));
}

// 会社情報取得
$company = [
    'company_name' => '',
    'company_kana' => '',
    'representative_name' => '',
    'postal_code' => '',
    'address' => '',
    'phone' => '',
    'license_number' => '',
    'business_type' => ''
];
try {
    $stmt = $pdo->prepare("SELECT * FROM company_info WHERE id = 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $company = array_merge($company, $row);
    }
} catch (PDOException $e) {
    // テーブルが存在しない場合は空欄のまま
}

// ==============================
// 個別事故報告書モード (?id=)
// ==============================
if ($accident_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, v.vehicle_number, u.name AS driver_name,
               creator.name AS created_by_name
        FROM accidents a
        LEFT JOIN vehicles v ON a.vehicle_id = v.id
        LEFT JOIN users u ON a.driver_id = u.id
        LEFT JOIN users creator ON a.created_by = creator.id
        WHERE a.id = ?
    ");
    $stmt->execute([$accident_id]);
    $accident = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$accident) {
        echo '<p>指定された事故記録が見つかりません。</p>';
        exit;
    }

    // 日付フォーマット
    $date_display = date('Y年m月d日', strtotime($accident['accident_date']));
    $time_display = $accident['accident_time'] ? date('H:i', strtotime($accident['accident_time'])) : '';
    $report_date = date('Y年m月d日');
}

// ==============================
// 年間一覧モード (?year=)
// ==============================
if ($year) {
    $stmt = $pdo->prepare("
        SELECT a.*, v.vehicle_number, u.name AS driver_name
        FROM accidents a
        LEFT JOIN vehicles v ON a.vehicle_id = v.id
        LEFT JOIN users u ON a.driver_id = u.id
        WHERE YEAR(a.accident_date) = ?
        ORDER BY a.accident_date ASC, a.accident_time ASC
    ");
    $stmt->execute([$year]);
    $accidents_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 年間集計
    $total_deaths = 0;
    $total_injuries = 0;
    $total_damage = 0;
    foreach ($accidents_list as $a) {
        $total_deaths += intval($a['deaths']);
        $total_injuries += intval($a['injuries']);
        $total_damage += intval($a['damage_amount']);
    }
}

// 表示モード判定
$mode = $accident_id ? 'detail' : 'list';
$page_orientation = $mode === 'detail' ? 'portrait' : 'landscape';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $mode === 'detail' ? '自動車事故報告書' : '事故一覧表 ' . $year . '年' ?></title>
    <style>
        /* 基本スタイル */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Yu Gothic', 'YuGothic', 'Meiryo', 'Hiragino Kaku Gothic Pro', sans-serif;
            font-size: 12px;
            color: #000;
            background: #f5f5f5;
        }

        /* 印刷用ボタン */
        .print-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #343a40;
            color: white;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .print-controls .btn-print {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        .print-controls .btn-print:hover {
            background: #0056b3;
        }
        .print-controls .btn-back {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
        }
        .print-controls .btn-back:hover {
            background: #5a6268;
        }
        .print-controls .info {
            font-size: 13px;
            opacity: 0.9;
        }

        /* ==============================
           個別報告書スタイル（A4縦）
           ============================== */
        .page-portrait {
            width: 210mm;
            min-height: 297mm;
            margin: 60px auto 20px;
            background: white;
            padding: 15mm 18mm;
            box-shadow: 0 2px 12px rgba(0,0,0,0.15);
        }

        .report-title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 8px;
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 3px double #000;
        }

        .company-info {
            margin-bottom: 16px;
            font-size: 12px;
            line-height: 1.8;
        }
        .company-info td.label {
            font-weight: bold;
            white-space: nowrap;
            padding-right: 10px;
            width: 1%;
        }
        .company-info td.value {
            padding-right: 20px;
        }

        .section {
            margin-bottom: 12px;
        }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            background: #f0f0f0;
            border: 1px solid #333;
            border-bottom: none;
            padding: 4px 8px;
        }
        .section-body {
            border: 1px solid #333;
            padding: 8px 10px;
            min-height: 30px;
            font-size: 12px;
            line-height: 1.7;
        }
        .section-body table {
            width: 100%;
        }
        .section-body td {
            padding: 2px 8px 2px 0;
            vertical-align: top;
        }
        .section-body td.item-label {
            font-weight: bold;
            white-space: nowrap;
            width: 1%;
        }
        .section-body-text {
            border: 1px solid #333;
            padding: 10px;
            min-height: 60px;
            font-size: 12px;
            line-height: 1.8;
            white-space: pre-wrap;
        }

        /* 署名欄 */
        .signature-section {
            margin-top: 30px;
            border: 1px solid #333;
            padding: 12px 16px;
        }
        .signature-section .report-date {
            font-size: 12px;
            margin-bottom: 16px;
        }
        .signature-row {
            display: flex;
            justify-content: space-around;
        }
        .signature-box {
            text-align: center;
            width: 40%;
        }
        .signature-box .sig-label {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .signature-box .sig-line {
            border-bottom: 1px solid #333;
            width: 100%;
            height: 30px;
            position: relative;
        }
        .signature-box .sig-line::after {
            content: '印';
            position: absolute;
            right: -24px;
            bottom: 0;
            font-size: 11px;
        }

        /* ==============================
           年間一覧スタイル（A4横）
           ============================== */
        .page-landscape {
            width: 297mm;
            min-height: 210mm;
            margin: 60px auto 20px;
            background: white;
            padding: 10mm;
            box-shadow: 0 2px 12px rgba(0,0,0,0.15);
        }

        .list-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 6px;
            margin-bottom: 10px;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
        }

        .list-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 11px;
        }

        .list-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .list-table th,
        .list-table td {
            border: 1px solid #333;
            padding: 4px 6px;
            font-size: 10px;
            vertical-align: middle;
        }
        .list-table th {
            background: #e9e9e9;
            font-weight: bold;
            text-align: center;
            font-size: 9.5px;
            white-space: nowrap;
        }
        .list-table td {
            text-align: center;
        }
        .list-table td.text-left {
            text-align: left;
        }
        .list-table td.text-right {
            text-align: right;
        }
        .list-table .summary-row td {
            font-weight: bold;
            background: #f0f0f0;
        }

        .status-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
        }
        .status-occurred { background: #ffd9d9; color: #c00; }
        .status-investigating { background: #fff3cd; color: #856404; }
        .status-resolved { background: #d4edda; color: #155724; }

        /* データなし */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #666;
            font-size: 16px;
        }

        /* ==============================
           印刷設定
           ============================== */
        @page {
            size: A4 <?= $page_orientation ?>;
            margin: 10mm;
        }

        @media print {
            .print-controls {
                display: none !important;
            }
            body {
                background: white;
            }
            .page-portrait,
            .page-landscape {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
                page-break-after: always;
            }
            .page-portrait:last-child,
            .page-landscape:last-child {
                page-break-after: auto;
            }
            .status-badge {
                border: 1px solid #333;
                background: none !important;
                color: #000 !important;
            }
        }
    </style>
</head>
<body>

<!-- 印刷コントロール -->
<div class="print-controls">
    <div class="info">
        <?php if ($mode === 'detail'): ?>
            自動車事故報告書 — <?= htmlspecialchars($date_display) ?>
        <?php else: ?>
            事故一覧表 — <?= $year ?>年（<?= count($accidents_list) ?>件）
        <?php endif; ?>
    </div>
    <div>
        <button class="btn-print" onclick="window.print()">PDF保存 / 印刷</button>
        <a href="../accident_management.php" class="btn-back" style="margin-left:8px;">一覧に戻る</a>
    </div>
</div>

<?php if ($mode === 'detail'): ?>
<!-- ============================================================
     個別事故報告書
     ============================================================ -->
<div class="page-portrait">
    <div class="report-title">自動車事故報告書</div>

    <!-- 事業者情報 -->
    <table class="company-info" style="width:100%;">
        <tr>
            <td class="label">事業者名:</td>
            <td class="value"><?= htmlspecialchars($company['company_name']) ?></td>
            <td class="label">許可番号:</td>
            <td class="value"><?= htmlspecialchars($company['license_number']) ?></td>
        </tr>
        <tr>
            <td class="label">代表者名:</td>
            <td class="value"><?= htmlspecialchars($company['representative_name']) ?></td>
            <td class="label">事業種別:</td>
            <td class="value"><?= htmlspecialchars($company['business_type']) ?></td>
        </tr>
        <tr>
            <td class="label">所在地:</td>
            <td class="value" colspan="3">
                <?php if ($company['postal_code']): ?>〒<?= htmlspecialchars($company['postal_code']) ?> <?php endif; ?>
                <?= htmlspecialchars($company['address']) ?>
                <?php if ($company['phone']): ?>　TEL: <?= htmlspecialchars($company['phone']) ?><?php endif; ?>
            </td>
        </tr>
    </table>

    <!-- 1. 事故の概要 -->
    <div class="section">
        <div class="section-title">1. 事故の概要</div>
        <div class="section-body">
            <table>
                <tr>
                    <td class="item-label">発生日時:</td>
                    <td><?= htmlspecialchars($date_display) ?><?= $time_display ? '　' . htmlspecialchars($time_display) : '' ?></td>
                    <td class="item-label" style="padding-left:20px;">事故種別:</td>
                    <td><?= htmlspecialchars($accident['accident_type']) ?></td>
                </tr>
                <tr>
                    <td class="item-label">発生場所:</td>
                    <td colspan="3"><?= htmlspecialchars($accident['location'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="item-label">天候:</td>
                    <td colspan="3"><?= htmlspecialchars($accident['weather'] ?? '') ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- 2. 当事車両 -->
    <div class="section">
        <div class="section-title">2. 当事車両</div>
        <div class="section-body">
            <table>
                <tr>
                    <td class="item-label">車両番号:</td>
                    <td><?= htmlspecialchars($accident['vehicle_number'] ?? '') ?></td>
                    <td class="item-label" style="padding-left:20px;">乗務員:</td>
                    <td><?= htmlspecialchars($accident['driver_name'] ?? '') ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- 3. 被害状況 -->
    <div class="section">
        <div class="section-title">3. 被害状況</div>
        <div class="section-body">
            <table>
                <tr>
                    <td class="item-label">死者:</td>
                    <td><?= intval($accident['deaths']) ?>名</td>
                    <td class="item-label" style="padding-left:20px;">負傷者:</td>
                    <td><?= intval($accident['injuries']) ?>名</td>
                </tr>
                <tr>
                    <td class="item-label">物損:</td>
                    <td><?= $accident['property_damage'] ? 'あり' : 'なし' ?></td>
                    <td class="item-label" style="padding-left:20px;">損害額:</td>
                    <td><?= $accident['damage_amount'] ? '&yen;' . number_format(intval($accident['damage_amount'])) : '-' ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- 4. 事故の状況 -->
    <div class="section">
        <div class="section-title">4. 事故の状況</div>
        <div class="section-body-text"><?= htmlspecialchars($accident['description'] ?? '') ?: '（記載なし）' ?></div>
    </div>

    <!-- 5. 原因分析 -->
    <div class="section">
        <div class="section-title">5. 原因分析</div>
        <div class="section-body-text"><?= htmlspecialchars($accident['cause_analysis'] ?? '') ?: '（記載なし）' ?></div>
    </div>

    <!-- 6. 再発防止策 -->
    <div class="section">
        <div class="section-title">6. 再発防止策</div>
        <div class="section-body-text"><?= htmlspecialchars($accident['prevention_measures'] ?? '') ?: '（記載なし）' ?></div>
    </div>

    <!-- 7. 届出状況 -->
    <div class="section">
        <div class="section-title">7. 届出状況</div>
        <div class="section-body">
            <table>
                <tr>
                    <td class="item-label">警察届出:</td>
                    <td>
                        <?= $accident['police_report'] ? 'あり' : 'なし' ?>
                        <?php if ($accident['police_report'] && $accident['police_report_number']): ?>
                            （届出番号: <?= htmlspecialchars($accident['police_report_number']) ?>）
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="item-label">保険請求:</td>
                    <td>
                        <?= $accident['insurance_claim'] ? 'あり' : 'なし' ?>
                        <?php if ($accident['insurance_claim'] && $accident['insurance_number']): ?>
                            （証券番号: <?= htmlspecialchars($accident['insurance_number']) ?>）
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- 署名欄 -->
    <div class="signature-section">
        <div class="report-date">報告日: <?= htmlspecialchars($report_date) ?>　　処理状況: <?= htmlspecialchars($accident['status']) ?></div>
        <div class="signature-row">
            <div class="signature-box">
                <div class="sig-label">報告者</div>
                <div class="sig-line"></div>
            </div>
            <div class="signature-box">
                <div class="sig-label">確認者</div>
                <div class="sig-line"></div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ============================================================
     年間事故一覧表
     ============================================================ -->
<div class="page-landscape">
    <div class="list-title">事故一覧表（<?= $year ?>年）</div>

    <div class="list-info">
        <div>事業者: <?= htmlspecialchars($company['company_name']) ?>　　許可番号: <?= htmlspecialchars($company['license_number']) ?></div>
        <div>出力日: <?= date('Y年m月d日') ?></div>
    </div>

    <?php if (empty($accidents_list)): ?>
    <div class="no-data">
        <?= $year ?>年の事故記録はありません。
    </div>
    <?php else: ?>
    <table class="list-table">
        <thead>
            <tr>
                <th style="width:30px;">No</th>
                <th style="width:80px;">日付</th>
                <th style="width:50px;">時刻</th>
                <th style="width:65px;">種別</th>
                <th>場所</th>
                <th style="width:85px;">車両</th>
                <th style="width:70px;">乗務員</th>
                <th style="width:35px;">死者</th>
                <th style="width:40px;">負傷者</th>
                <th style="width:35px;">物損</th>
                <th style="width:70px;">損害額</th>
                <th>再発防止策</th>
                <th style="width:60px;">ステータス</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accidents_list as $i => $a): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= date('m/d', strtotime($a['accident_date'])) ?></td>
                <td><?= $a['accident_time'] ? substr($a['accident_time'], 0, 5) : '-' ?></td>
                <td><?= htmlspecialchars($a['accident_type']) ?></td>
                <td class="text-left"><?= htmlspecialchars($a['location'] ?? '') ?></td>
                <td><?= htmlspecialchars($a['vehicle_number'] ?? '') ?></td>
                <td><?= htmlspecialchars($a['driver_name'] ?? '') ?></td>
                <td><?= intval($a['deaths']) ?></td>
                <td><?= intval($a['injuries']) ?></td>
                <td><?= $a['property_damage'] ? 'あり' : '-' ?></td>
                <td class="text-right"><?= $a['damage_amount'] ? '&yen;' . number_format(intval($a['damage_amount'])) : '-' ?></td>
                <td class="text-left" style="font-size:9px;"><?= htmlspecialchars(mb_strimwidth($a['prevention_measures'] ?? '', 0, 60, '...')) ?></td>
                <td>
                    <?php
                    $status_class = '';
                    switch ($a['status']) {
                        case '発生': $status_class = 'status-occurred'; break;
                        case '調査中': $status_class = 'status-investigating'; break;
                        case '処理完了': $status_class = 'status-resolved'; break;
                    }
                    ?>
                    <span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($a['status']) ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            <!-- 合計行 -->
            <tr class="summary-row">
                <td colspan="7" style="text-align:right;">合計（<?= count($accidents_list) ?>件）</td>
                <td><?= $total_deaths ?></td>
                <td><?= $total_injuries ?></td>
                <td colspan="2" style="text-align:right;">&yen;<?= number_format($total_damage) ?></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

</body>
</html>
