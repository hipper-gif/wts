<?php

// データベース接続
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

// 権限チェック（Admin限定ページ）
if ($user_role !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

// --- 監査ログ関数 ---
function logAnnualReportAudit($pdo, $user_id, $user_name, $action, $details = '') {
    logAudit($pdo, 0, '[年次報告] ' . $action, $user_id, 'annual_report', [], $details);
}

// 現在の年度取得
$current_year = date('Y');
$selected_year = $_GET['year'] ?? $current_year;

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_report':
                    $fiscal_year = $_POST['fiscal_year'];
                    $report_type = $_POST['report_type'];

                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO annual_reports (fiscal_year, report_type, status, submitted_by)
                            VALUES (?, ?, '作成中', ?)
                            ON DUPLICATE KEY UPDATE status = '作成中', updated_at = NOW()
                        ");
                        $stmt->execute([$fiscal_year, $report_type, $user_id]);
                        logAnnualReportAudit($pdo, $user_id, $user_name, 'レポート作成開始', "year={$fiscal_year}, type={$report_type}");
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }

                    $message = "{$fiscal_year}年度の{$report_type}を作成開始しました。";
                    break;

                case 'update_status':
                    $report_id = $_POST['report_id'];
                    $status = $_POST['status'];
                    $memo = $_POST['memo'] ?? '';

                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE annual_reports
                            SET status = ?, memo = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$status, $memo, $report_id]);
                        logAnnualReportAudit($pdo, $user_id, $user_name, 'ステータス更新', "report_id={$report_id}, status={$status}");
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }

                    $message = "レポートの状態を更新しました。";
                    break;
                    
                case 'export_form4':
                    // 第4号様式PDF出力処理
                    $export_year = $_POST['fiscal_year'];

                    // データ取得
                    $company_info_export = getCompanyInfo($pdo);
                    $business_overview_export = getBusinessOverview($pdo, $export_year);
                    $transport_results_export = getTransportResults($pdo, $export_year);
                    $accident_data_export = getAccidentData($pdo, $export_year);

                    // PDF出力
                    generateForm4PDF($company_info_export, $business_overview_export, $transport_results_export, $accident_data_export, $export_year);
                    exit();
                    break;

                case 'export_form4_excel':
                    // 第4号様式エクセル出力処理
                    $export_year = $_POST['fiscal_year'];

                    // データ取得
                    $company_info_export = getCompanyInfo($pdo);
                    $business_overview_export = getBusinessOverview($pdo, $export_year);
                    $transport_results_export = getTransportResults($pdo, $export_year);
                    $accident_data_export = getAccidentData($pdo, $export_year);

                    // エクセル出力
                    generateForm4Excel($company_info_export, $business_overview_export, $transport_results_export, $accident_data_export, $export_year);
                    exit();
                    break;
            }
        }
    } catch (Exception $e) {
        error_log("Annual report error: " . $e->getMessage());
        $error = "エラーが発生しました。管理者にお問い合わせください。";
    }
}

// データ取得関数群
function getCompanyInfo($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM company_info ORDER BY id LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return [
            'company_name' => '',
            'representative_name' => '',
            'address' => '',
            'phone' => '',
            'fax' => '',
            'manager_name' => '',
            'manager_email' => '',
            'postal_code' => '',
            'license_number' => '',
            'business_type' => '一般乗用旅客自動車運送事業（福祉）'
        ];
    }
    
    return $result;
}

function getBusinessOverview($pdo, $year) {
    $end_date = $year . '-03-31';
    try {
        // 車両数（アクティブな車両のみ、「その他」を除外）
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE is_active = 1 AND COALESCE(vehicle_type, '') != 'other' AND vehicle_number NOT LIKE '%その他%'");
        $stmt->execute();
        $vehicle_count = $stmt->fetchColumn();

        // 従業員数（運転者または管理者）
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE is_active = 1
            AND (is_driver = 1 OR permission_level = 'Admin')
        ");
        $stmt->execute();
        $employee_count = $stmt->fetchColumn();

        // 運転者数（is_driverフラグ）
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE is_active = 1 AND is_driver = 1
        ");
        $stmt->execute();
        $driver_count = $stmt->fetchColumn();

        return [
            'vehicle_count' => $vehicle_count,
            'employee_count' => $employee_count,
            'driver_count' => $driver_count,
            'as_of_date' => $end_date
        ];
    } catch (Exception $e) {
        error_log("Business overview error: " . $e->getMessage());
        return ['vehicle_count' => 0, 'employee_count' => 0, 'driver_count' => 0, 'as_of_date' => $end_date];
    }
}

function getTransportResults($pdo, $year) {
    $start_date = ($year - 1) . '-04-01';
    $end_date = $year . '-03-31';
    $default = [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_distance' => 0,
        'ride_count' => 0,
        'total_passengers' => 0,
        'total_revenue' => 0,
        'transport_categories' => []
    ];
    try {
        // 走行距離（arrival_recordsから、「その他」車両を除外）
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ar.total_distance), 0) as total_distance
            FROM arrival_records ar
            JOIN vehicles v ON ar.vehicle_id = v.id
            WHERE ar.arrival_date BETWEEN ? AND ?
            AND COALESCE(v.vehicle_type, '') != 'other'
            AND v.vehicle_number NOT LIKE '%その他%'
        ");
        $stmt->execute([$start_date, $end_date]);
        $total_distance = $stmt->fetchColumn();

        // 運送実績（ride_recordsから、「その他」車両を除外）
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as ride_count,
                SUM(rr.passenger_count) as total_passengers,
                SUM(rr.fare + COALESCE(rr.charge, 0)) as total_revenue,
                COALESCE(SUM(rr.ride_distance), 0) as total_ride_distance
            FROM ride_records rr
            JOIN vehicles v ON rr.vehicle_id = v.id
            WHERE rr.ride_date BETWEEN ? AND ?
            AND COALESCE(rr.is_sample_data, 0) = 0
            AND COALESCE(v.vehicle_type, '') != 'other'
            AND v.vehicle_number NOT LIKE '%その他%'
        ");
        $stmt->execute([$start_date, $end_date]);
        $transport_data = $stmt->fetch(PDO::FETCH_ASSOC);

        // 輸送種別別の詳細データ（「その他」車両を除外）
        $stmt = $pdo->prepare("
            SELECT
                rr.transportation_type,
                COUNT(*) as count,
                SUM(rr.passenger_count) as passengers,
                SUM(rr.fare + COALESCE(rr.charge, 0)) as revenue
            FROM ride_records rr
            JOIN vehicles v ON rr.vehicle_id = v.id
            WHERE rr.ride_date BETWEEN ? AND ?
            AND COALESCE(rr.is_sample_data, 0) = 0
            AND COALESCE(v.vehicle_type, '') != 'other'
            AND v.vehicle_number NOT LIKE '%その他%'
            GROUP BY rr.transportation_type
        ");
        $stmt->execute([$start_date, $end_date]);
        $transport_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 実車キロ・回送キロ
        $total_ride_distance = floatval($transport_data['total_ride_distance'] ?? 0);
        $dead_run_distance = max(0, floatval($total_distance) - $total_ride_distance);

        return [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'total_distance' => $total_distance,
            'total_ride_distance' => $total_ride_distance,
            'dead_run_distance' => $dead_run_distance,
            'ride_count' => $transport_data['ride_count'] ?? 0,
            'total_passengers' => $transport_data['total_passengers'] ?? 0,
            'total_revenue' => $transport_data['total_revenue'] ?? 0,
            'transport_categories' => $transport_categories
        ];
    } catch (Exception $e) {
        error_log("Transport results error: " . $e->getMessage());
        return $default;
    }
}

function getAccidentData($pdo, $year) {
    // 事故データ（年度集計）
    $start_date = ($year - 1) . '-04-01';
    $end_date = $year . '-03-31';
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                accident_type,
                COUNT(*) as count,
                COALESCE(SUM(deaths), 0) as total_deaths,
                COALESCE(SUM(injuries), 0) as total_injuries
            FROM accidents 
            WHERE accident_date BETWEEN ? AND ?
            GROUP BY accident_type
        ");
        $stmt->execute([$start_date, $end_date]);
        $accidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [
            'traffic_accidents' => 0,
            'serious_accidents' => 0,
            'other_accidents' => 0,
            'total_deaths' => 0,
            'total_injuries' => 0
        ];
        
        foreach ($accidents as $accident) {
            switch ($accident['accident_type']) {
                case '交通事故':
                    $result['traffic_accidents'] = $accident['count'];
                    break;
                case '重大事故':
                    $result['serious_accidents'] = $accident['count'];
                    break;
                case 'その他':
                    $result['other_accidents'] = $accident['count'];
                    break;
            }
            $result['total_deaths'] += $accident['total_deaths'];
            $result['total_injuries'] += $accident['total_injuries'];
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Accident data error: " . $e->getMessage());
        return [
            'traffic_accidents' => 0,
            'serious_accidents' => 0,
            'other_accidents' => 0,
            'total_deaths' => 0,
            'total_injuries' => 0
        ];
    }
}

function getAnnualReports($pdo, $year = null) {
    try {
        $sql = "
            SELECT ar.*, u.name as submitted_by_name
            FROM annual_reports ar
            LEFT JOIN users u ON ar.submitted_by = u.id
        ";
        $params = [];
        
        if ($year) {
            $sql .= " WHERE ar.fiscal_year = ?";
            $params[] = $year;
        }
        
        $sql .= " ORDER BY ar.fiscal_year DESC, ar.report_type";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Annual reports error: " . $e->getMessage());
        return [];
    }
}

// 西暦年度 → 和暦（令和X）変換
function toReiwaYear($year) {
    return intval($year) - 2018;
}

// PDF出力関数 — 近畿運輸局 第4号様式 第3表（福祉限定）公式書式に準拠
// 参照見本: NAS \\LS220D679\share\04.介護タクシー共有フォルダ\…\2024年度\2024年度輸送実績報告書.xls
// 構造: 8列固定（col0-3 ラベル / col4 管轄値 / col5 管轄単位 / col6 全国値 / col7 全国単位）
function generateForm4PDF($company, $business, $transport, $accident, $year) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=0, must-revalidate');

    $revenue_sen = floor(($transport['total_revenue'] ?? 0) / 1000);
    $reiwa = toReiwaYear($year);
    $f = function($v) { return number_format(intval($v)); };
    $h = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    echo '<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>第4号様式 第3表（限定）輸送実績報告書 令和' . $reiwa . '年度</title>
<style>
@page { size: A4 portrait; margin: 10mm 12mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: "Yu Mincho", "YuMincho", "MS Mincho", "Hiragino Mincho ProN", serif;
    font-size: 10.5pt;
    color: #000;
    background: #fff;
    line-height: 1.35;
}
.page-wrapper {
    width: 210mm; min-height: 297mm;
    margin: 10mm auto; padding: 10mm 12mm;
    background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.15);
}
.print-controls {
    text-align: center; margin: 0 auto 10px; padding: 12px;
    background: #f5f5f5; border-radius: 6px; max-width: 210mm;
    font-family: sans-serif;
}
.print-controls button {
    padding: 8px 24px; font-size: 12pt; cursor: pointer;
    margin: 0 4px; border-radius: 4px; border: 1px solid #ccc;
}
.print-controls .btn-print { background: #1976D2; color: #fff; border-color: #1565C0; }
.print-controls .btn-close { background: #757575; color: #fff; border-color: #616161; }
@media print {
    .print-controls { display: none !important; }
    .page-wrapper { margin: 0; padding: 0; box-shadow: none; min-height: auto; }
    body { background: #fff; }
}

/* === 上部ヘッダー === */
.head-row {
    display: table; width: 100%;
    font-size: 9pt; margin-bottom: 5mm;
}
.head-row .lhead { display: table-cell; vertical-align: top; }
.head-row .rhead { display: table-cell; vertical-align: top; text-align: right; white-space: nowrap; }
.bango-label { padding-right: 1mm; }
.bango-box {
    display: inline-block; border: 0.5pt solid #000;
    padding: 1px 8px; min-width: 24mm; text-align: center;
    margin-right: 2mm;
}
.gentei {
    display: inline-block; border: 0.5pt solid #000;
    padding: 1px 8px;
}

.shikyu-line {
    font-size: 11pt; margin: 0 0 4mm;
}
.shikyu-name {
    display: inline-block; border-bottom: 0.5pt solid #000;
    min-width: 24mm; text-align: center; padding: 0 4mm;
}

.report-title {
    text-align: center; font-size: 14pt; font-weight: bold;
    margin: 5mm 0 6mm; letter-spacing: 0.05em;
}

.ate-line {
    font-size: 10.5pt; margin: 0 0 4mm;
    padding-left: 60mm;
}

.company-block { margin: 0 0 6mm 95mm; }
.company-block table { border-collapse: collapse; width: 80mm; }
.company-block td { padding: 0.5mm 2mm; font-size: 10pt; vertical-align: bottom; }
.company-block td.lbl { white-space: nowrap; width: 22mm; }
.company-block td.val {
    border-bottom: 0.5pt solid #000;
    min-width: 56mm;
}

/* === 8列データテーブル === */
.data-table {
    width: 100%;
    border-collapse: collapse;
    border-top: 1pt solid #000;
    border-left: 1pt solid #000;
    border-right: 1pt solid #000;
    table-layout: fixed;
}
.data-table.last { border-bottom: 1pt solid #000; }
.data-table col.col-label  { width: 50%; }
.data-table col.col-kv     { width: 13%; }
.data-table col.col-ku     { width: 12%; }
.data-table col.col-zv     { width: 13%; }
.data-table col.col-zu     { width: 12%; }
.data-table td {
    border: 0.5pt solid #000;
    padding: 2.5px 6px;
    font-size: 10pt;
    vertical-align: middle;
    height: 7.5mm;
}
.data-table td.section-title {
    text-align: left; font-weight: normal;
    border-top: 0.5pt solid #000;
    border-bottom: 0.5pt solid #000;
}
.data-table td.col-header {
    text-align: center; font-weight: normal; font-size: 10pt;
    height: 6mm;
}
.data-table td.lbl {
    text-align: left; padding-left: 6mm;
}
.data-table td.val {
    text-align: right; padding-right: 4px;
}
.data-table td.unit {
    text-align: left; font-size: 9.5pt; padding-left: 2px;
}
.data-table td.paren {
    text-align: center; font-size: 10pt;
}

/* === 備考 === */
.biko {
    border: 1pt solid #000; border-top: none;
    padding: 3mm 4mm; font-size: 9pt; line-height: 1.7;
}
.biko-title { display: inline-block; margin-bottom: 1mm; }
.biko ol { margin: 0 0 0 6mm; padding: 0; list-style-position: outside; }
.biko li { margin: 0; padding-left: 2mm; }
</style>
</head>
<body>

<div class="print-controls">
    <button class="btn-print" onclick="window.print()"><b>PDF保存 / 印刷</b></button>
    <button class="btn-close" onclick="window.close()">閉じる</button>
</div>

<div class="page-wrapper">

<!-- 上部: 様式番号 + 事業者番号 + 限定 -->
<div class="head-row">
    <span class="lhead">第４号様式　（第２条関係）　（日本工業規格Ａ列４番）　第３表</span>
    <span class="rhead">
        <span class="bango-label">事業者番号</span><span class="bango-box">' . $h($company['business_number'] ?? '') . '</span><span class="gentei">限定</span>
    </span>
</div>

<!-- 運輸支局 -->
<div class="shikyu-line"><span class="shikyu-name">大阪</span>運輸支局</div>

<!-- タイトル -->
<div class="report-title">一般乗用旅客自動車運送事業　（限定）　輸送実績報告書　（令和' . $reiwa . '年度）</div>

<!-- 宛先 -->
<div class="ate-line">あ　て</div>

<!-- 事業者情報 -->
<div class="company-block">
    <table>
        <tr><td class="lbl">住　　　所</td><td class="val">' . $h($company['address']) . '</td></tr>
        <tr><td class="lbl">事業者名</td><td class="val">' . $h($company['company_name']) . '</td></tr>
        <tr><td class="lbl">代表者名</td><td class="val">' . $h($company['representative_name']) . '</td></tr>
        <tr><td class="lbl">電話番号</td><td class="val">' . $h($company['phone']) . '</td></tr>
    </table>
</div>

<!-- ============ 事業概況 ============ -->
<table class="data-table">
<colgroup><col class="col-label"><col class="col-kv"><col class="col-ku"><col class="col-zv"><col class="col-zu"></colgroup>
    <tr><td colspan="5" class="section-title">事業概況　（令和' . $reiwa . '年３月３１日現在）</td></tr>
    <tr>
        <td class="col-header">　</td>
        <td class="col-header" colspan="2">管轄区域内</td>
        <td class="col-header" colspan="2">全国</td>
    </tr>
    <tr>
        <td class="lbl">資本金（資金）の額　（千円）</td>
        <td class="val">　</td><td class="unit">　</td>
        <td class="val">' . $f($company['capital_thousand_yen'] ?? 0) . '</td><td class="unit">千円</td>
    </tr>
    <tr>
        <td class="lbl">兼営事業</td>
        <td class="val" colspan="2">　</td>
        <td class="val" colspan="2" style="text-align:center;">' . $h($company['concurrent_business'] ?? '') . '</td>
    </tr>
    <tr>
        <td class="lbl">事業用自動車数　（両）</td>
        <td class="val">' . $f($business['vehicle_count']) . '</td><td class="unit">両</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">従業員数</td>
        <td class="val">' . $f($business['employee_count']) . '</td><td class="paren">（' . $f($business['driver_count']) . '）</td>
        <td class="val">　</td><td class="paren">（　　）</td>
    </tr>
</table>

<!-- ============ 輸送実績 ============ -->
<table class="data-table">
<colgroup><col class="col-label"><col class="col-kv"><col class="col-ku"><col class="col-zv"><col class="col-zu"></colgroup>
    <tr><td colspan="5" class="section-title">輸送実績　（前年４月１日から本年３月３１日まで）</td></tr>
    <tr>
        <td class="col-header">　</td>
        <td class="col-header" colspan="2">管轄区域内</td>
        <td class="col-header" colspan="2">全国</td>
    </tr>
    <tr>
        <td class="lbl">走行キロ　（キロメートル）</td>
        <td class="val">' . $f($transport['total_distance']) . '</td><td class="unit">km</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">運送回数　（回）</td>
        <td class="val">' . $f($transport['ride_count']) . '</td><td class="unit">回</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">輸送人員　（人）</td>
        <td class="val">' . $f($transport['total_passengers']) . '</td><td class="unit">人</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">営業収入　（千円）</td>
        <td class="val">' . $f($revenue_sen) . '</td><td class="unit">千円</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
</table>

<!-- ============ 事故件数 ============ -->
<table class="data-table last">
<colgroup><col class="col-label"><col class="col-kv"><col class="col-ku"><col class="col-zv"><col class="col-zu"></colgroup>
    <tr><td colspan="5" class="section-title">事故件数　（前年４月１日から本年３月３１日まで）</td></tr>
    <tr>
        <td class="col-header">　</td>
        <td class="col-header" colspan="2">管轄区域内</td>
        <td class="col-header" colspan="2">全国</td>
    </tr>
    <tr>
        <td class="lbl">交通事故件数</td>
        <td class="val">' . $f($accident['traffic_accidents']) . '</td><td class="unit">件</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">重大事故件数</td>
        <td class="val">' . $f($accident['serious_accidents']) . '</td><td class="unit">件</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">死者数</td>
        <td class="val">' . $f($accident['total_deaths']) . '</td><td class="unit">件</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">負傷者数</td>
        <td class="val">' . $f($accident['total_injuries']) . '</td><td class="unit">件</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
</table>

<!-- ============ 備考 ============ -->
<div class="biko">
    <span class="biko-title">備　考</span>
    <ol>
        <li>　兼営事業については、主な兼営事業の名称を記載すること。</li>
        <li>　従業員数は、兼営事業がある場合は主として当該事業に従事している人数及び共通部門に従事している従業員については当該事業分として適正な基準により配分した人数とする。</li>
        <li>　従業員数の欄の（　　　　）には、運転者数を記載すること。</li>
        <li>　交通事故とは、道路交通法（昭和35年法律第105号）第72条第１項の交通事故をいう。</li>
        <li>　重大事故とは、自動車事故報告規則（昭和26年運輸省令第104号）第２条の事故をいう。</li>
    </ol>
</div>

</div>
</body>
</html>';
}

// エクセル出力関数 — 近畿運輸局 第4号様式 第3表（福祉限定）公式書式に準拠
// 構造: 8列ベース（A-D ラベル / E 管轄値 / F 管轄単位 / G 全国値 / H 全国単位）
function generateForm4Excel($company, $business, $transport, $accident, $year) {
    $filename = 'gentei_jisseki_R' . toReiwaYear($year) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    $revenue_sen = floor(($transport['total_revenue'] ?? 0) / 1000);
    $reiwa = toReiwaYear($year);
    $h = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    echo "\xEF\xBB\xBF";

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>一般乗用（福祉限定）</x:Name>
    <x:WorksheetOptions>
     <x:DefaultRowHeight>340</x:DefaultRowHeight>
     <x:Print><x:ValidPrinterInfo/><x:PaperSizeIndex>9</x:PaperSizeIndex><x:Orientation>Portrait</x:Orientation></x:Print>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml><![endif]-->
<style>
    table { border-collapse: collapse; }
    col.c0 { width: 80px; }
    col.c1 { width: 80px; }
    col.c2 { width: 80px; }
    col.c3 { width: 80px; }
    col.c4 { width: 70px; }
    col.c5 { width: 40px; }
    col.c6 { width: 70px; }
    col.c7 { width: 40px; }
    td { font-family: "ＭＳ Ｐ明朝", "Yu Mincho", serif; font-size: 10pt; vertical-align: middle; }
    td.bx { border: 0.5pt solid #000; }
    td.num { text-align: right; mso-number-format:"\#\,\#\#0"; }
    td.txt { text-align: left; }
    td.ctr { text-align: center; }
    td.unit { text-align: left; font-size: 9pt; }
    td.hdr { text-align: center; font-size: 10pt; }
    td.sec { font-size: 10pt; }
    td.title { text-align: center; font-weight: bold; font-size: 13pt; }
    td.formhead { font-size: 9pt; }
    td.bango-box { border: 0.5pt solid #000; text-align: center; }
    td.gentei { border: 0.5pt solid #000; text-align: center; }
    td.shikyu { border-bottom: 0.5pt solid #000; text-align: center; }
    .biko { font-size: 9pt; }
</style>
</head>
<body>
<table>
<colgroup><col class="c0"><col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"><col class="c6"><col class="c7"></colgroup>';

    // 行0: 様式番号（左） + 事業者番号 + 限定（右）
    echo '<tr style="height:18pt;">';
    echo '<td colspan="5" class="formhead">第４号様式　（第２条関係）　（日本工業規格Ａ列４番）　第３表</td>';
    echo '<td class="formhead" style="text-align:right;">事業者番号</td>';
    echo '<td class="bango-box">' . $h($company['business_number'] ?? '') . '</td>';
    echo '<td class="gentei">限定</td>';
    echo '</tr>';
    echo '<tr style="height:6pt;"><td colspan="8"></td></tr>';

    // 運輸支局
    echo '<tr><td class="shikyu" colspan="2">大阪</td><td>運輸支局</td><td colspan="5"></td></tr>';
    echo '<tr style="height:6pt;"><td colspan="8"></td></tr>';

    // タイトル
    echo '<tr style="height:24pt;"><td colspan="8" class="title">一般乗用旅客自動車運送事業　（限定）　輸送実績報告書　（令和' . $reiwa . '年度）</td></tr>';
    echo '<tr style="height:6pt;"><td colspan="8"></td></tr>';

    // 宛先
    echo '<tr><td colspan="4"></td><td colspan="4">あ　て</td></tr>';
    echo '<tr style="height:6pt;"><td colspan="8"></td></tr>';

    // 事業者情報（右寄せブロック）
    echo '<tr><td colspan="4"></td><td>住　　　所</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['address']) . '</td></tr>';
    echo '<tr><td colspan="4"></td><td>事業者名</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['company_name']) . '</td></tr>';
    echo '<tr><td colspan="4"></td><td>代表者名</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['representative_name']) . '</td></tr>';
    echo '<tr><td colspan="4"></td><td>電話番号</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['phone']) . '</td></tr>';
    echo '<tr style="height:8pt;"><td colspan="8"></td></tr>';

    // === 事業概況 ===
    echo '<tr><td class="bx sec" colspan="8">事業概況　（令和' . $reiwa . '年３月３１日現在）</td></tr>';
    echo '<tr><td class="bx" colspan="4">　</td><td class="bx hdr" colspan="2">管轄区域内</td><td class="bx hdr" colspan="2">全国</td></tr>';
    echo '<tr>';
    echo '<td class="bx txt" colspan="4">資本金（資金）の額　（千円）</td>';
    echo '<td class="bx num">　</td><td class="bx unit">　</td>';
    echo '<td class="bx num">' . intval($company['capital_thousand_yen'] ?? 0) . '</td><td class="bx unit">千円</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="bx txt" colspan="4">兼営事業</td>';
    echo '<td class="bx" colspan="2">　</td>';
    echo '<td class="bx ctr" colspan="2">' . $h($company['concurrent_business'] ?? '') . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="bx txt" colspan="4">事業用自動車数　（両）</td>';
    echo '<td class="bx num">' . intval($business['vehicle_count']) . '</td><td class="bx unit">両</td>';
    echo '<td class="bx num">　</td><td class="bx unit">　</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="bx txt" colspan="4">従業員数</td>';
    echo '<td class="bx num">' . intval($business['employee_count']) . '</td><td class="bx ctr">（' . intval($business['driver_count']) . '）</td>';
    echo '<td class="bx num">　</td><td class="bx ctr">（　　）</td>';
    echo '</tr>';

    // === 輸送実績 ===
    echo '<tr><td class="bx sec" colspan="8">輸送実績　（前年４月１日から本年３月３１日まで）</td></tr>';
    echo '<tr><td class="bx" colspan="4">　</td><td class="bx hdr" colspan="2">管轄区域内</td><td class="bx hdr" colspan="2">全国</td></tr>';
    foreach ([
        ['走行キロ　（キロメートル）', intval($transport['total_distance']), 'km'],
        ['運送回数　（回）',           intval($transport['ride_count']),     '回'],
        ['輸送人員　（人）',           intval($transport['total_passengers']), '人'],
        ['営業収入　（千円）',         $revenue_sen,                          '千円'],
    ] as $row) {
        echo '<tr>';
        echo '<td class="bx txt" colspan="4">' . $h($row[0]) . '</td>';
        echo '<td class="bx num">' . $row[1] . '</td><td class="bx unit">' . $h($row[2]) . '</td>';
        echo '<td class="bx num">　</td><td class="bx unit">　</td>';
        echo '</tr>';
    }

    // === 事故件数 ===
    echo '<tr><td class="bx sec" colspan="8">事故件数　（前年４月１日から本年３月３１日まで）</td></tr>';
    echo '<tr><td class="bx" colspan="4">　</td><td class="bx hdr" colspan="2">管轄区域内</td><td class="bx hdr" colspan="2">全国</td></tr>';
    foreach ([
        ['交通事故件数', intval($accident['traffic_accidents'])],
        ['重大事故件数', intval($accident['serious_accidents'])],
        ['死者数',       intval($accident['total_deaths'])],
        ['負傷者数',     intval($accident['total_injuries'])],
    ] as $row) {
        echo '<tr>';
        echo '<td class="bx txt" colspan="4">' . $h($row[0]) . '</td>';
        echo '<td class="bx num">' . $row[1] . '</td><td class="bx unit">件</td>';
        echo '<td class="bx num">　</td><td class="bx unit">　</td>';
        echo '</tr>';
    }

    // === 備考 ===
    echo '<tr><td class="bx biko">備　考</td><td class="bx biko ctr">1</td><td class="bx biko" colspan="6">兼営事業については、主な兼営事業の名称を記載すること。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">2</td><td class="bx biko" colspan="6">従業員数は、兼営事業がある場合は主として当該事業に従事している人数及び共通部門に従事している従業員については当該事業分として適正な基準により配分した人数とする。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">3</td><td class="bx biko" colspan="6">従業員数の欄の（　　　　）には、運転者数を記載すること。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">4</td><td class="bx biko" colspan="6">交通事故とは、道路交通法（昭和35年法律第105号）第72条第１項の交通事故をいう。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">5</td><td class="bx biko" colspan="6">重大事故とは、自動車事故報告規則（昭和26年運輸省令第104号）第２条の事故をいう。</td></tr>';

    echo '</table></body></html>';
}

// データ取得
$company_info = getCompanyInfo($pdo);
$business_overview = getBusinessOverview($pdo, $selected_year);
$transport_results = getTransportResults($pdo, $selected_year);
$accident_data = getAccidentData($pdo, $selected_year);
$annual_reports = getAnnualReports($pdo, $selected_year);

// ページ設定
$page_config = getPageConfiguration('annual_report');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => '陸運局第4号様式（輸送実績報告書）の作成・管理',
    'additional_css' => [],
    'additional_js' => [],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '定期業務', 'url' => '#'],
        ['text' => '陸運局提出', 'url' => 'annual_report.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'annual_report',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

// HTMLヘッダー出力
echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<!-- モーダル確実動作のための追加スタイル -->
<style>
/* Bootstrap モーダルの z-index を明示的に設定（カスタムCSSとの競合を防ぐ） */
.modal-backdrop {
    z-index: 1040 !important;
}
.modal {
    z-index: 1055 !important;
}
/* モバイル端末でのスタッキングコンテキスト問題を防ぐ */
@media (max-width: 767px) {
    .modal,
    .modal-backdrop {
        -webkit-transform: none !important;
        transform: none !important;
    }
}
</style>

<!-- メインコンテンツ開始 -->
<main class="main-content">
<div class="container-fluid px-4">

    <!-- メッセージ表示 -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 事業者情報サマリー -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-building me-2"></i>事業者情報</h5>
                    <a href="company_settings.php" class="btn btn-light btn-sm">
                        <i class="fas fa-edit me-1"></i>会社情報設定へ
                    </a>
                </div>
                <div class="card-body py-2">
                    <div class="row">
                        <div class="col-md-4"><strong><?= htmlspecialchars($company_info['company_name']) ?></strong></div>
                        <div class="col-md-4">許可番号: <?= htmlspecialchars($company_info['license_number'] ?: '未設定') ?></div>
                        <div class="col-md-4">運行管理者: <?= htmlspecialchars($company_info['manager_name'] ?? '未設定') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 年度選択 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-alt me-2"></i>年度選択
                            <span class="badge bg-primary ms-2"><?= $selected_year ?>年度</span>
                        </h5>
                        <form method="GET" class="d-flex">
                            <select name="year" class="form-select me-2" style="width: auto;">
                                <?php for ($year = $current_year + 1; $year >= $current_year - 5; $year--): ?>
                                    <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                                        <?= $year ?>年度（<?= $year - 1 ?>年4月〜<?= $year ?>年3月）
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>変更
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 年度データサマリー（陸運局様式準拠） -->
    <div class="row mb-4">
        <div class="col-lg-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= $business_overview['vehicle_count'] ?></div>
                    <div class="h5">事業用自動車数</div>
                    <small><?= $selected_year ?>年3月31日現在</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= $business_overview['employee_count'] ?></div>
                    <div class="h5">従業員数</div>
                    <small>運転者<?= $business_overview['driver_count'] ?>名含む</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= number_format($transport_results['ride_count']) ?></div>
                    <div class="h5">運送回数</div>
                    <small>年度期間合計</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card bg-warning text-dark">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= $accident_data['traffic_accidents'] + $accident_data['serious_accidents'] ?></div>
                    <div class="h5">事故件数</div>
                    <small>交通事故・重大事故計</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 第4号様式詳細データ -->
    <div class="row mb-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-building me-2"></i>事業概況（<?= $selected_year ?>年3月31日現在）</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>事業用自動車数</td>
                            <td class="text-end fw-bold"><?= $business_overview['vehicle_count'] ?>台</td>
                        </tr>
                        <tr>
                            <td>従業員数</td>
                            <td class="text-end fw-bold"><?= $business_overview['employee_count'] ?>名</td>
                        </tr>
                        <tr>
                            <td>　うち運転者数</td>
                            <td class="text-end"><?= $business_overview['driver_count'] ?>名</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>輸送実績（<?= $selected_year ?>年度）</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>走行キロ</td>
                            <td class="text-end fw-bold"><?= number_format($transport_results['total_distance']) ?>km</td>
                        </tr>
                        <tr>
                            <td>運送回数</td>
                            <td class="text-end fw-bold"><?= number_format($transport_results['ride_count']) ?>回</td>
                        </tr>
                        <tr>
                            <td>輸送人員</td>
                            <td class="text-end fw-bold"><?= number_format($transport_results['total_passengers']) ?>人</td>
                        </tr>
                        <tr class="table-success">
                            <td>営業収入</td>
                            <td class="text-end fw-bold">¥<?= number_format($transport_results['total_revenue']) ?></td>
                        </tr>
                        <tr>
                            <td><small>（千円単位）</small></td>
                            <td class="text-end"><small><?= number_format(floor($transport_results['total_revenue'] / 1000)) ?>千円</small></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>事故件数（<?= $selected_year ?>年度）</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>交通事故件数</td>
                            <td class="text-end fw-bold"><?= $accident_data['traffic_accidents'] ?>件</td>
                        </tr>
                        <tr>
                            <td>重大事故件数</td>
                            <td class="text-end fw-bold"><?= $accident_data['serious_accidents'] ?>件</td>
                        </tr>
                        <tr>
                            <td>死者数</td>
                            <td class="text-end fw-bold"><?= $accident_data['total_deaths'] ?>名</td>
                        </tr>
                        <tr>
                            <td>負傷者数</td>
                            <td class="text-end fw-bold"><?= $accident_data['total_injuries'] ?>名</td>
                        </tr>
                    </table>
                    
                    <?php if (($accident_data['traffic_accidents'] + $accident_data['serious_accidents']) == 0): ?>
                        <div class="text-center mt-2">
                            <span class="badge bg-success">無事故達成</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 輸送種別詳細 -->
    <?php if (!empty($transport_results['transport_categories'])): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-list me-2"></i>輸送種別詳細（<?= $selected_year ?>年度）</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="table-dark">
                                <tr>
                                    <th>輸送種別</th>
                                    <th class="text-end">運送回数</th>
                                    <th class="text-end">輸送人員</th>
                                    <th class="text-end">営業収入</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transport_results['transport_categories'] as $category): ?>
                                <tr>
                                    <td><?= htmlspecialchars($category['transportation_type']) ?></td>
                                    <td class="text-end"><?= number_format($category['count']) ?>回</td>
                                    <td class="text-end"><?= number_format($category['passengers']) ?>人</td>
                                    <td class="text-end">¥<?= number_format($category['revenue']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 第4号様式出力・管理 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-file-pdf me-2"></i>第4号様式 第3表（福祉限定 輸送実績報告書）</h5>
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#createReportModal">
                        <i class="fas fa-plus me-1"></i>新規作成
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-lg-8">
                            <h6>陸運局提出用書類の作成・管理</h6>
                            <p class="text-muted mb-0">
                                旅客自動車運送事業等報告規則第2条に基づく輸送実績報告書（限定）です。<br>
                                提出先: 国土交通省近畿運輸局 大阪運輸支局　提出期限: 毎年5月31日
                            </p>
                        </div>
                        <div class="col-lg-4 text-end">
                            <form method="POST" class="d-inline" target="_blank">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="export_form4">
                                <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-file-pdf me-2"></i>PDF出力
                                </button>
                            </form>
                            <form method="POST" class="d-inline ms-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="export_form4_excel">
                                <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-file-excel me-2"></i>Excel出力
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- レポート一覧 -->
                    <?php if ($annual_reports): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>年度</th>
                                        <th>レポート種別</th>
                                        <th>状態</th>
                                        <th>提出日</th>
                                        <th>担当者</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($annual_reports as $report): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?= $report['fiscal_year'] ?>年度</span></td>
                                            <td><?= htmlspecialchars($report['report_type']) ?></td>
                                            <td>
                                                <span class="badge 
                                                    <?php
                                                    switch($report['status']) {
                                                        case '未作成': echo 'bg-secondary'; break;
                                                        case '作成中': echo 'bg-warning text-dark'; break;
                                                        case '確認中': echo 'bg-info'; break;
                                                        case '提出済み': echo 'bg-success'; break;
                                                    }
                                                    ?>">
                                                    <?= $report['status'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= $report['submission_date'] ? date('Y/m/d', strtotime($report['submission_date'])) : '-' ?>
                                            </td>
                                            <td><?= htmlspecialchars($report['submitted_by_name'] ?? '') ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <?php if ($report['status'] !== '提出済み'): ?>
                                                        <button class="btn btn-outline-primary btn-sm" 
                                                                onclick="updateStatus(<?= $report['id'] ?>, '<?= $report['status'] ?>')">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" class="d-inline" target="_blank">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                        <input type="hidden" name="action" value="export_form4">
                                                        <input type="hidden" name="fiscal_year" value="<?= $report['fiscal_year'] ?>">
                                                        <button type="submit" class="btn btn-outline-success btn-sm" title="PDF出力">
                                                            <i class="fas fa-file-pdf"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                        <input type="hidden" name="action" value="export_form4_excel">
                                                        <input type="hidden" name="fiscal_year" value="<?= $report['fiscal_year'] ?>">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm" title="Excel出力">
                                                            <i class="fas fa-file-excel"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">まだレポートが作成されていません</h5>
                            <p class="text-muted">
                                <?= $selected_year ?>年度の第4号様式はまだ作成されていません。<br>
                                上記のPDF出力ボタンから直接出力するか、「新規作成」で管理記録を作成してください。
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 関連機能 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="mb-3"><i class="fas fa-link me-2"></i>関連機能</h6>
                    <div class="row">
                        <div class="col-lg-3">
                            <a href="accident_management.php" class="btn btn-warning w-100">
                                <i class="fas fa-exclamation-triangle me-1"></i>事故管理
                            </a>
                        </div>
                        <div class="col-lg-3">
                            <a href="vehicle_management.php" class="btn btn-info w-100">
                                <i class="fas fa-car me-1"></i>車両管理
                            </a>
                        </div>
                        <div class="col-lg-3">
                            <a href="ride_records.php" class="btn btn-primary w-100">
                                <i class="fas fa-route me-1"></i>乗車記録
                            </a>
                        </div>
                        <div class="col-lg-3">
                            <a href="dashboard.php" class="btn btn-secondary w-100">
                                <i class="fas fa-home me-1"></i>ダッシュボード
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<!-- レポート作成モーダル -->
<div class="modal fade" id="createReportModal" tabindex="-1" aria-labelledby="createReportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createReportModalLabel"><i class="fas fa-plus me-2"></i>新規レポート作成</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_report">
                    
                    <div class="mb-3">
                        <label for="fiscal_year" class="form-label">年度</label>
                        <select name="fiscal_year" id="fiscal_year" class="form-select" required>
                            <?php for ($year = $current_year + 1; $year >= $current_year - 5; $year--): ?>
                                <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                                    <?= $year ?>年度（<?= $year - 1 ?>年4月〜<?= $year ?>年3月）
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="report_type" class="form-label">レポート種別</label>
                        <select name="report_type" id="report_type" class="form-select" required>
                            <option value="第4号様式">第4号様式（輸送実績報告書）</option>
                            <option value="事故報告書">事故報告書</option>
                            <option value="安全統括管理者選任届">安全統括管理者選任届</option>
                            <option value="運行管理者選任届">運行管理者選任届</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>注意:</strong> 作成後は状態管理ができるようになります。
                        PDF出力は作成の有無に関わらず可能です。
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i>作成開始
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 状態更新モーダル -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="updateStatusModalLabel"><i class="fas fa-edit me-2"></i>レポート状態更新</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <form method="POST" id="updateStatusForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="report_id" id="update_report_id">
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">状態</label>
                        <select name="status" id="status" class="form-select" required>
                            <option value="未作成">未作成</option>
                            <option value="作成中">作成中</option>
                            <option value="確認中">確認中</option>
                            <option value="提出済み">提出済み</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="memo" class="form-label">メモ</label>
                        <textarea class="form-control" name="memo" id="memo" rows="3" 
                                  placeholder="進捗状況や注意事項があれば記入してください"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-1"></i>更新
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // 状態更新モーダル（DOMContentLoaded後に実行）
    function updateStatus(reportId, currentStatus) {
        document.getElementById('update_report_id').value = reportId;
        document.getElementById('status').value = currentStatus;
        var modalEl = document.getElementById('updateStatusModal');
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
</script>

<?php
// 統一フッターの出力
echo $page_data['html_footer'] ?? '';
?>
