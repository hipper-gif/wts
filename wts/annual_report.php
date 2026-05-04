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
                case 'export_form4_excel':
                case 'export_form21':
                case 'export_form21_excel':
                    $export_year = $_POST['fiscal_year'];
                    $is_form4 = ($_POST['action'] === 'export_form4' || $_POST['action'] === 'export_form4_excel');
                    $report_type = $is_form4 ? '第4号様式' : '第21号様式';

                    // 出力履歴を annual_reports に自動記録（重複時は更新）
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO annual_reports (fiscal_year, report_type, status, submitted_by, updated_at)
                            VALUES (?, ?, '作成中', ?, NOW())
                            ON DUPLICATE KEY UPDATE updated_at = NOW(), submitted_by = VALUES(submitted_by)
                        ");
                        $stmt->execute([$export_year, $report_type, $user_id]);
                        logAnnualReportAudit($pdo, $user_id, $user_name, '書類出力', "year={$export_year}, type={$report_type}, action={$_POST['action']}");
                    } catch (Exception $logEx) {
                        error_log("Annual report log error: " . $logEx->getMessage());
                    }

                    $company_info_export = getCompanyInfo($pdo);
                    if ($is_form4) {
                        $business_overview_export = getBusinessOverview($pdo, $export_year);
                        $transport_results_export = getTransportResults($pdo, $export_year);
                        $accident_data_export = getAccidentData($pdo, $export_year);
                        if ($_POST['action'] === 'export_form4') {
                            generateForm4PDF($company_info_export, $business_overview_export, $transport_results_export, $accident_data_export, $export_year);
                        } else {
                            generateForm4Excel($company_info_export, $business_overview_export, $transport_results_export, $accident_data_export, $export_year);
                        }
                    } else {
                        $form21_data_export = getForm21Data($pdo);
                        if ($_POST['action'] === 'export_form21') {
                            generateForm21PDF($company_info_export, $form21_data_export, $export_year);
                        } else {
                            generateForm21Excel($company_info_export, $form21_data_export, $export_year);
                        }
                    }
                    exit();
                    break;

                case 'update_inline_field':
                    // インライン編集 — AJAX で company_info の単一フィールドを更新
                    header('Content-Type: application/json; charset=UTF-8');
                    $allowed = [
                        'business_number', 'capital_thousand_yen', 'concurrent_business',
                        'form21_target_vehicles', 'form21_plan_content', 'form21_change_content',
                        'form21_prev_total', 'form21_prev_wheelchair', 'form21_prev_udt',
                        'form21_prev_stretcher', 'form21_prev_combo', 'form21_prev_rotation',
                    ];
                    $field = $_POST['field'] ?? '';
                    $value = $_POST['value'] ?? '';

                    if (!in_array($field, $allowed, true)) {
                        echo json_encode(['success' => false, 'error' => 'invalid_field']);
                        exit();
                    }

                    // 数値フィールドは intval で正規化
                    $numeric_fields = [
                        'capital_thousand_yen', 'form21_prev_total', 'form21_prev_wheelchair',
                        'form21_prev_udt', 'form21_prev_stretcher', 'form21_prev_combo', 'form21_prev_rotation',
                    ];
                    if (in_array($field, $numeric_fields, true)) {
                        $value = intval(preg_replace('/[^0-9-]/', '', (string)$value));
                    }

                    try {
                        $id_row = $pdo->query("SELECT id FROM company_info ORDER BY id LIMIT 1")->fetch();
                        if (!$id_row) {
                            $pdo->exec("INSERT INTO company_info (id) VALUES (1)");
                            $target_id = 1;
                        } else {
                            $target_id = $id_row['id'];
                        }
                        $stmt = $pdo->prepare("UPDATE company_info SET {$field} = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$value, $target_id]);
                        logAnnualReportAudit($pdo, $user_id, $user_name, 'インライン編集', "field={$field}");
                        echo json_encode(['success' => true, 'field' => $field, 'value' => $value]);
                    } catch (Exception $e) {
                        error_log("Inline edit error: " . $e->getMessage());
                        echo json_encode(['success' => false, 'error' => 'db_error']);
                    }
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
@page { size: A4 portrait; margin: 0; }
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 210mm; }
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
    overflow: hidden;
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
    .page-wrapper { margin: 0; padding: 10mm 12mm; box-shadow: none; min-height: auto; width: 210mm; }
    body { background: #fff; width: 210mm; }
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
    word-break: break-all;
    overflow-wrap: break-word;
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
    <span class="lhead">第４号様式　（第２条関係）　（日本産業規格Ａ列４番）　第３表</span>
    <span class="rhead">
        <span class="bango-label">事業者番号</span><span class="bango-box">' . $h($company['business_number'] ?? '') . '</span><span class="gentei">限定</span>
    </span>
</div>

<!-- 運輸支局 -->
<div class="shikyu-line"><span class="shikyu-name">大阪</span>運輸支局</div>

<!-- タイトル -->
<div class="report-title">一般乗用旅客自動車運送事業　（限定）　輸送実績報告書　（令和' . $reiwa . '年度）</div>

<!-- 宛先 -->
<div class="ate-line">　　　　　　　　　　　　　あて</div>

<!-- 事業者情報 -->
<div class="company-block">
    <table>
        <tr><td class="lbl">住　　　所</td><td class="val">' . $h($company['address']) . '</td></tr>
        <tr><td class="lbl">事業者名</td><td class="val">' . $h($company['company_name']) . '</td></tr>
        <tr><td class="lbl">代表者名（役職名及び氏名）</td><td class="val">' . $h($company['representative_name']) . '</td></tr>
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
        <td class="lbl">資本金（基金）の額　（千円）</td>
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
        <td class="val">' . $f($accident['total_deaths']) . '</td><td class="unit">人</td>
        <td class="val">　</td><td class="unit">　</td>
    </tr>
    <tr>
        <td class="lbl">負傷者数</td>
        <td class="val">' . $f($accident['total_injuries']) . '</td><td class="unit">人</td>
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
        <li>　交通事故とは、道路交通法（昭和23年法律第105号）第72条第１項の交通事故をいう。</li>
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
    echo '<td colspan="5" class="formhead">第４号様式　（第２条関係）　（日本産業規格Ａ列４番）　第３表</td>';
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

    // 宛先（見本通り「13個の全角スペース + あて」を列0始点）
    echo '<tr><td colspan="8">　　　　　　　　　　　　　あて</td></tr>';
    echo '<tr style="height:6pt;"><td colspan="8"></td></tr>';

    // 事業者情報（右寄せブロック）
    echo '<tr><td colspan="4"></td><td>住　　　所</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['address']) . '</td></tr>';
    echo '<tr><td colspan="4"></td><td>事業者名</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['company_name']) . '</td></tr>';
    echo '<tr><td colspan="4"></td><td>代表者名（役職名及び氏名）</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['representative_name']) . '</td></tr>';
    echo '<tr><td colspan="4"></td><td>電話番号</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['phone']) . '</td></tr>';
    echo '<tr style="height:8pt;"><td colspan="8"></td></tr>';

    // === 事業概況 ===
    echo '<tr><td class="bx sec" colspan="8">事業概況　（令和' . $reiwa . '年３月３１日現在）</td></tr>';
    echo '<tr><td class="bx" colspan="4">　</td><td class="bx hdr" colspan="2">管轄区域内</td><td class="bx hdr" colspan="2">全国</td></tr>';
    echo '<tr>';
    echo '<td class="bx txt" colspan="4">資本金（基金）の額　（千円）</td>';
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
        ['交通事故件数', intval($accident['traffic_accidents']), '件'],
        ['重大事故件数', intval($accident['serious_accidents']), '件'],
        ['死者数',       intval($accident['total_deaths']),     '人'],
        ['負傷者数',     intval($accident['total_injuries']),   '人'],
    ] as $row) {
        echo '<tr>';
        echo '<td class="bx txt" colspan="4">' . $h($row[0]) . '</td>';
        echo '<td class="bx num">' . $row[1] . '</td><td class="bx unit">' . $h($row[2]) . '</td>';
        echo '<td class="bx num">　</td><td class="bx unit">　</td>';
        echo '</tr>';
    }

    // === 備考 ===
    echo '<tr><td class="bx biko">備　考</td><td class="bx biko ctr">1</td><td class="bx biko" colspan="6">兼営事業については、主な兼営事業の名称を記載すること。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">2</td><td class="bx biko" colspan="6">従業員数は、兼営事業がある場合は主として当該事業に従事している人数及び共通部門に従事している従業員については当該事業分として適正な基準により配分した人数とする。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">3</td><td class="bx biko" colspan="6">従業員数の欄の（　　　　）には、運転者数を記載すること。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">4</td><td class="bx biko" colspan="6">交通事故とは、道路交通法（昭和23年法律第105号）第72条第１項の交通事故をいう。</td></tr>';
    echo '<tr><td class="bx"></td><td class="bx biko ctr">5</td><td class="bx biko" colspan="6">重大事故とは、自動車事故報告規則（昭和26年運輸省令第104号）第２条の事故をいう。</td></tr>';

    echo '</table></body></html>';
}

// ============================================================
// 第21号様式（移動等円滑化実績等報告書 福祉タクシー車両）
// 参照見本: NAS \\LS220D679\share\…\2024年度\2024年度移動等円滑化実績等報告書（福祉タクシー車両）.xlsx
// 根拠: バリアフリー法施行規則第23条
// 提出期限: 翌年度5月31日 / 提出先: 大阪運輸支局 輸送部門
// ============================================================

function getForm21Data($pdo) {
    try {
        // accessibility_category は排他選択（none/wheelchair/stretcher/combo/rotation）。
        // UDT は車椅子対応のサブセットフラグ。
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(accessibility_category != 'none'), 0) AS total,
                COALESCE(SUM(accessibility_category = 'wheelchair'), 0) AS wheelchair,
                COALESCE(SUM(accessibility_category = 'wheelchair' AND is_universal_design_taxi = 1), 0) AS udt,
                COALESCE(SUM(accessibility_category = 'stretcher'), 0) AS stretcher,
                COALESCE(SUM(accessibility_category = 'combo'), 0) AS combo,
                COALESCE(SUM(accessibility_category = 'rotation'), 0) AS rotation
            FROM vehicles
            WHERE is_active = 1
              AND COALESCE(vehicle_type, '') != 'other'
              AND vehicle_number NOT LIKE '%その他%'
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => intval($row['total'] ?? 0),
            'wheelchair' => intval($row['wheelchair'] ?? 0),
            'udt' => intval($row['udt'] ?? 0),
            'stretcher' => intval($row['stretcher'] ?? 0),
            'combo' => intval($row['combo'] ?? 0),
            'rotation' => intval($row['rotation'] ?? 0),
        ];
    } catch (Exception $e) {
        error_log("Form21 data error: " . $e->getMessage());
        return ['total' => 0, 'wheelchair' => 0, 'udt' => 0, 'stretcher' => 0, 'combo' => 0, 'rotation' => 0];
    }
}

function generateForm21PDF($company, $f21, $year) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=0, must-revalidate');

    $reiwa = toReiwaYear($year);
    $h = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $f = function($v) { return $v === null || $v === '' ? '　' : intval($v); };

    echo '<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>第21号様式 移動等円滑化実績等報告書（福祉タクシー車両） 令和' . $reiwa . '年度</title>
<style>
@page { size: A4 portrait; margin: 0; }
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 210mm; }
body {
    font-family: "Yu Mincho", "YuMincho", "MS Mincho", serif;
    font-size: 10pt; color: #000; background: #fff; line-height: 1.4;
}
.page-wrapper {
    width: 210mm; min-height: 297mm;
    margin: 10mm auto; padding: 10mm 10mm;
    background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.15);
    overflow: hidden;
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
    .page-wrapper { margin: 0; padding: 10mm 10mm; box-shadow: none; min-height: auto; width: 210mm; }
    body { background: #fff; width: 210mm; }
}

.form-no { font-size: 9pt; margin-bottom: 4mm; }
.title { text-align: center; font-size: 14pt; font-weight: bold; letter-spacing: 0.3em; margin: 6mm 0 2mm; }
.subtitle { text-align: center; font-size: 11pt; margin-bottom: 6mm; }

.company-block { margin: 0 0 6mm 95mm; }
.company-block table { border-collapse: collapse; width: 90mm; }
.company-block td { padding: 0.5mm 2mm; font-size: 10pt; vertical-align: bottom; }
.company-block td.lbl { white-space: nowrap; width: 30mm; }
.company-block td.val { border-bottom: 0.5pt solid #000; min-width: 60mm; }

.section-h { font-size: 10.5pt; margin: 4mm 0 2mm; }
.as-of { font-size: 10pt; margin: 0 0 2mm; }

.t1 { width: 100%; border-collapse: collapse; border: 1pt solid #000; margin-bottom: 4mm; table-layout: fixed; }
.t1 td { border: 0.5pt solid #000; padding: 3px 6px; font-size: 9.5pt; vertical-align: middle; text-align: center; word-break: break-all; overflow-wrap: break-word; }
.t1 td.h1 { font-weight: normal; height: 6mm; }
.t1 td.lbl { text-align: center; }
.t1 td.val { font-size: 11pt; height: 8mm; }

.t2 { width: 100%; border-collapse: collapse; border: 1pt solid #000; margin-bottom: 4mm; table-layout: fixed; }
.t2 td { border: 0.5pt solid #000; padding: 3px 6px; font-size: 10pt; vertical-align: middle; word-break: break-all; overflow-wrap: break-word; }
.t2 td.h { text-align: center; height: 7mm; }
.t2 td.lbl { width: 35%; padding-left: 4mm; }
.t2 td.body { padding: 4mm; min-height: 24mm; vertical-align: top; }

.t3 { width: 100%; border-collapse: collapse; border: 1pt solid #000; margin-bottom: 4mm; table-layout: fixed; }
.t3 td { border: 0.5pt solid #000; padding: 3px 6px; font-size: 10pt; vertical-align: middle; word-break: break-all; overflow-wrap: break-word; }
.t3 td.body { padding: 4mm; min-height: 18mm; vertical-align: top; }

.req-table { width: 100%; border-collapse: collapse; border: 1pt solid #000; margin-bottom: 4mm; table-layout: fixed; }
.req-table td { border: 0.5pt solid #000; padding: 4px 6px; font-size: 9.5pt; vertical-align: top; word-break: break-all; overflow-wrap: break-word; }
.req-table td.req-text { width: 90%; }
.req-table td.req-mark { width: 10%; text-align: center; }

.notes { word-break: break-all; overflow-wrap: break-word; }

.notes { font-size: 8.5pt; line-height: 1.6; margin-top: 4mm; }
.notes .note-title { font-weight: bold; margin-bottom: 1mm; }
.notes ol { margin-left: 6mm; }
.notes li { margin-bottom: 0.5mm; }
</style>
</head>
<body>

<div class="print-controls">
    <button class="btn-print" onclick="window.print()"><b>PDF保存 / 印刷</b></button>
    <button class="btn-close" onclick="window.close()">閉じる</button>
</div>

<div class="page-wrapper">

<div class="form-no">第21号様式（日本産業規格Ａ列４番）</div>

<div class="title">移 動 等 円 滑 化 実 績 等 報 告 書（福祉タクシー車両）</div>
<div class="subtitle">（令和' . $reiwa . '年度）</div>

<!-- 事業者情報 -->
<div class="company-block">
    <table>
        <tr><td class="lbl">　　住　　所</td><td class="val">' . $h($company['address']) . '</td></tr>
        <tr><td class="lbl">　　事業者名</td><td class="val">' . $h($company['company_name']) . '</td></tr>
        <tr><td class="lbl">　　代表者名（役職名及び氏名）</td><td class="val">' . $h($company['representative_name']) . '</td></tr>
    </table>
</div>

<!-- 1. 達成状況 -->
<div class="section-h">Ⅰ．福祉タクシー車両の移動等円滑化の達成状況</div>
<div class="as-of">（令和' . $reiwa . '年３月３１日現在）</div>

<table class="t1">
    <tr>
        <td class="h1" rowspan="2" style="width:18%;">　</td>
        <td class="h1" colspan="6">公　共　交　通　移　動　等　円　滑　化　基　準　省　令　に　適　合　し　た　車　両　数</td>
    </tr>
    <tr>
        <td class="h1" style="width:9%;">計</td>
        <td class="h1" colspan="2" style="width:24%;">車椅子対応車数<br><span style="font-size:8.5pt;">うち、ユニバーサルデザインタクシー車両数</span></td>
        <td class="h1" style="width:13%;">寝台対応車数</td>
        <td class="h1" style="width:13%;">兼用車数</td>
        <td class="h1" style="width:14%;">回転シート車数</td>
    </tr>
    <tr>
        <td class="lbl">前年度車両数</td>
        <td class="val">' . $f($company['form21_prev_total'] ?? 0) . '</td>
        <td class="val">' . $f($company['form21_prev_wheelchair'] ?? 0) . '</td>
        <td class="val">' . $f($company['form21_prev_udt'] ?? 0) . '</td>
        <td class="val">' . $f($company['form21_prev_stretcher'] ?? 0) . '</td>
        <td class="val">' . $f($company['form21_prev_combo'] ?? 0) . '</td>
        <td class="val">' . $f($company['form21_prev_rotation'] ?? 0) . '</td>
    </tr>
    <tr>
        <td class="lbl">年度末車両数</td>
        <td class="val">' . $f21['total'] . '</td>
        <td class="val">' . $f21['wheelchair'] . '</td>
        <td class="val">' . $f21['udt'] . '</td>
        <td class="val">' . $f21['stretcher'] . '</td>
        <td class="val">' . $f21['combo'] . '</td>
        <td class="val">' . $f21['rotation'] . '</td>
    </tr>
</table>

<!-- 2. 計画 -->
<div class="section-h">Ⅱ．福祉タクシー車両の移動等円滑化のための事業の計画</div>

<table class="t2">
    <tr>
        <td class="h" style="width:30%;">対象となる福祉タクシー車両</td>
        <td class="h">計画内容<br><span style="font-size:9pt;">（計画対象期間及び事業の主な内容を明記すること。）</span></td>
    </tr>
    <tr>
        <td class="body">' . nl2br($h($company['form21_target_vehicles'] ?? '')) . '</td>
        <td class="body">' . nl2br($h($company['form21_plan_content'] ?? '')) . '</td>
    </tr>
</table>

<table class="t3">
    <tr><td class="h" style="text-align:left; padding-left:4mm; height:6mm;">前年度の計画からの変更内容</td></tr>
    <tr><td class="body">' . nl2br($h($company['form21_change_content'] ?? '')) . '</td></tr>
</table>

<!-- Ⅲ. 要件 -->
<div class="section-h">Ⅲ．高齢者、障害者等の移動等の円滑化の促進に関する法律施行規則第６条の２で定める要件に関する事項</div>

<table class="req-table">
    <tr>
        <td class="req-text">（１）過去３年度における１年度当たりの平均の輸送人員が1000万人以上である。</td>
        <td class="req-mark">　</td>
    </tr>
    <tr>
        <td class="req-text">（２）過去３年度における１年度当たりの平均の輸送人員が100万人以上1000万人未満であり、かつ、以下のいずれかに該当する。<br>　　　①中小企業者でない。<br>　　　②大企業者である公共交通事業者等が自社の株式を50％以上所有しているか、又は自社に対し50％以上出資している中小企業者である。</td>
        <td class="req-mark">　</td>
    </tr>
</table>

<!-- 注記 -->
<div class="notes">
    <div class="note-title">（第21号様式）</div>
    <ol>
        <li>公共交通移動等円滑化基準省令に適合した車両数の欄には、公共交通移動等円滑化基準省令第45条第１項又は第２項の基準に適合している車両の合計数を記入すること。</li>
        <li>車椅子対応車数の欄には、公共交通移動等円滑化基準省令第45条第１項の基準に適合している車両のうち、車椅子使用者のみを輸送することができる車両の合計数を記入すること。</li>
        <li>ユニバーサルデザインタクシーの台数の欄には、２の車両のうち、移動等円滑化の促進に関する基本方針において移動等円滑化の目標が定められているノンステップバスの基準等を定める告示（平成24年国土交通省告示第257号）第４条第１項の規定に基づき、ユニバーサルデザインタクシーの認定を受けている車両の合計数を記入すること。</li>
        <li>寝台対応車数の欄には、公共交通移動等円滑化基準省令第45条第１項の基準に適合している車両のうち、寝台等を使用している者のみを輸送することができる車両の合計数を記入すること。</li>
        <li>兼用車数の欄には、公共交通移動等円滑化基準省令第45条第１項の基準に適合している車両のうち、車椅子使用者及び寝台等を使用している者のいずれをも輸送することができる車両の合計数を記入すること。</li>
        <li>回転シート車数の欄には、公共交通移動等円滑化基準省令第45条第２項の基準に適合している車両の合計数を記入すること。</li>
        <li>Ⅲについては、該当する場合には右の欄に○印を記入すること。</li>
        <li>「中小企業者」とは、資本金の額が３億円以下又は従業員数が300人以下である民間事業者を指す。</li>
        <li>「大企業者」とは、中小企業者以外の民間事業者を指す。</li>
    </ol>
</div>

</div>
</body>
</html>';
}

function generateForm21Excel($company, $f21, $year) {
    $filename = 'fukushi_taxi_R' . toReiwaYear($year) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    $reiwa = toReiwaYear($year);
    $h = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    echo "\xEF\xBB\xBF";
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8">
<!--[if gte mso 9]><xml>
 <x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
  <x:Name>福祉タクシー車両</x:Name>
  <x:WorksheetOptions><x:Print><x:PaperSizeIndex>9</x:PaperSizeIndex><x:Orientation>Portrait</x:Orientation></x:Print></x:WorksheetOptions>
 </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>
</xml><![endif]-->
<style>
table { border-collapse: collapse; }
td { font-family: "ＭＳ Ｐ明朝", "Yu Mincho", serif; font-size: 10pt; vertical-align: middle; }
td.bx { border: 0.5pt solid #000; }
td.ctr { text-align: center; }
td.title { font-size: 14pt; font-weight: bold; text-align: center; }
td.sub { font-size: 11pt; text-align: center; }
td.head { font-size: 9pt; }
td.h { font-size: 9.5pt; text-align: center; }
td.num { text-align: center; mso-number-format:"\#\,\#\#0"; }
td.note { font-size: 9pt; }
</style>
</head><body>
<table>';
    // 様式番号
    echo '<tr><td colspan="7" class="head">第21号様式（日本産業規格Ａ列４番）</td></tr>';
    echo '<tr><td colspan="7" class="title">移 動 等 円 滑 化 実 績 等 報 告 書（福祉タクシー車両）</td></tr>';
    echo '<tr><td colspan="7" class="sub">（令和' . $reiwa . '年度）</td></tr>';
    echo '<tr><td colspan="7"></td></tr>';

    // 事業者情報
    echo '<tr><td colspan="3"></td><td>　　住　　所</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['address']) . '</td></tr>';
    echo '<tr><td colspan="3"></td><td>　　事業者名</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['company_name']) . '</td></tr>';
    echo '<tr><td colspan="3"></td><td>　　代表者名（役職名及び氏名）</td><td colspan="3" style="border-bottom:0.5pt solid #000;">' . $h($company['representative_name']) . '</td></tr>';
    echo '<tr><td colspan="7"></td></tr>';

    // 1. 達成状況
    echo '<tr><td colspan="7">Ⅰ．福祉タクシー車両の移動等円滑化の達成状況</td></tr>';
    echo '<tr><td colspan="7">（令和' . $reiwa . '年３月３１日現在）</td></tr>';

    // 達成状況テーブル: 7列構成
    echo '<tr>';
    echo '<td class="bx h" rowspan="2">　</td>';
    echo '<td class="bx h" colspan="6">公共交通移動等円滑化基準省令に適合した車両数</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="bx h">計</td>';
    echo '<td class="bx h">車椅子対応車数</td>';
    echo '<td class="bx h">うちUDT</td>';
    echo '<td class="bx h">寝台対応車数</td>';
    echo '<td class="bx h">兼用車数</td>';
    echo '<td class="bx h">回転シート車数</td>';
    echo '</tr>';

    // 前年度車両数
    echo '<tr>';
    echo '<td class="bx ctr">前年度車両数</td>';
    echo '<td class="bx num">' . intval($company['form21_prev_total'] ?? 0) . '</td>';
    echo '<td class="bx num">' . intval($company['form21_prev_wheelchair'] ?? 0) . '</td>';
    echo '<td class="bx num">' . intval($company['form21_prev_udt'] ?? 0) . '</td>';
    echo '<td class="bx num">' . intval($company['form21_prev_stretcher'] ?? 0) . '</td>';
    echo '<td class="bx num">' . intval($company['form21_prev_combo'] ?? 0) . '</td>';
    echo '<td class="bx num">' . intval($company['form21_prev_rotation'] ?? 0) . '</td>';
    echo '</tr>';

    // 年度末車両数
    echo '<tr>';
    echo '<td class="bx ctr">年度末車両数</td>';
    echo '<td class="bx num">' . $f21['total'] . '</td>';
    echo '<td class="bx num">' . $f21['wheelchair'] . '</td>';
    echo '<td class="bx num">' . $f21['udt'] . '</td>';
    echo '<td class="bx num">' . $f21['stretcher'] . '</td>';
    echo '<td class="bx num">' . $f21['combo'] . '</td>';
    echo '<td class="bx num">' . $f21['rotation'] . '</td>';
    echo '</tr>';
    echo '<tr><td colspan="7"></td></tr>';

    // 2. 計画
    echo '<tr><td colspan="7">Ⅱ．福祉タクシー車両の移動等円滑化のための事業の計画</td></tr>';
    echo '<tr><td class="bx h" colspan="3">対象となる福祉タクシー車両</td><td class="bx h" colspan="4">計画内容（計画対象期間及び事業の主な内容を明記すること。）</td></tr>';
    echo '<tr><td class="bx" colspan="3" style="height:60pt; vertical-align:top;">' . nl2br($h($company['form21_target_vehicles'] ?? '')) . '</td><td class="bx" colspan="4" style="height:60pt; vertical-align:top;">' . nl2br($h($company['form21_plan_content'] ?? '')) . '</td></tr>';
    echo '<tr><td class="bx" colspan="7">前年度の計画からの変更内容</td></tr>';
    echo '<tr><td class="bx" colspan="7" style="height:50pt; vertical-align:top;">' . nl2br($h($company['form21_change_content'] ?? '')) . '</td></tr>';
    echo '<tr><td colspan="7"></td></tr>';

    // Ⅲ. 要件
    echo '<tr><td colspan="7">Ⅲ．高齢者、障害者等の移動等の円滑化の促進に関する法律施行規則第６条の２で定める要件に関する事項</td></tr>';
    echo '<tr><td class="bx" colspan="6">（１）過去３年度における１年度当たりの平均の輸送人員が1000万人以上である。</td><td class="bx ctr">　</td></tr>';
    echo '<tr><td class="bx" colspan="6">（２）過去３年度における１年度当たりの平均の輸送人員が100万人以上1000万人未満であり、かつ、以下のいずれかに該当する。<br>　　　①中小企業者でない。<br>　　　②大企業者である公共交通事業者等が自社の株式を50％以上所有しているか、又は自社に対し50％以上出資している中小企業者である。</td><td class="bx ctr">　</td></tr>';
    echo '<tr><td colspan="7"></td></tr>';

    // 注記
    foreach ([
        '注１．公共交通移動等円滑化基準省令に適合した車両数の欄には、公共交通移動等円滑化基準省令第45条第１項又は第２項の基準に適合している車両の合計数を記入すること。',
        '　２．車椅子対応車数の欄には、公共交通移動等円滑化基準省令第45条第１項の基準に適合している車両のうち、車椅子使用者のみを輸送することができる車両の合計数を記入すること。',
        '　３．ユニバーサルデザインタクシーの台数の欄には、２の車両のうち、移動等円滑化の促進に関する基本方針において移動等円滑化の目標が定められているノンステップバスの基準等を定める告示（平成24年国土交通省告示第257号）第４条第１項の規定に基づき、ユニバーサルデザインタクシーの認定を受けている車両の合計数を記入すること。',
        '　４．寝台対応車数の欄には、公共交通移動等円滑化基準省令第45条第１項の基準に適合している車両のうち、寝台等を使用している者のみを輸送することができる車両の合計数を記入すること。',
        '　５．兼用車数の欄には、公共交通移動等円滑化基準省令第45条第１項の基準に適合している車両のうち、車椅子使用者及び寝台等を使用している者のいずれをも輸送することができる車両の合計数を記入すること。',
        '　６．回転シート車数の欄には、公共交通移動等円滑化基準省令第45条第２項の基準に適合している車両の合計数を記入すること。',
        '　７．Ⅲについては、該当する場合には右の欄に○印を記入すること。',
        '　８．「中小企業者」とは、資本金の額が３億円以下又は従業員数が300人以下である民間事業者を指す。',
        '　９．「大企業者」とは、中小企業者以外の民間事業者を指す。',
    ] as $note) {
        echo '<tr><td colspan="7" class="note">' . $h($note) . '</td></tr>';
    }

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

<!-- Google Fonts (Zen Maru Gothic + JetBrains Mono) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

<style>
/* Bootstrap モーダルの z-index を明示的に設定（カスタムCSSとの競合を防ぐ） */
.modal-backdrop { z-index: 1040 !important; }
.modal { z-index: 1055 !important; }
@media (max-width: 767px) {
    .modal, .modal-backdrop {
        -webkit-transform: none !important;
        transform: none !important;
    }
}

/* === スマルト デザイントークン (.ar-* スコープ) === */
.ar-root {
    --ar-teal: #2C7A7B; --ar-teal-light: #38B2AC; --ar-teal-bg: #E6FFFA; --ar-teal-bg-deep: #B2F5EA;
    --ar-lamp: #ED8936; --ar-lamp-light: #F6AD55; --ar-lamp-bg: #FFFAF0;
    --ar-ink: #1A202C; --ar-ink-soft: #2D3748; --ar-muted: #718096; --ar-muted-soft: #A0AEC0;
    --ar-line: #E2E8F0; --ar-line-soft: #EDF2F7; --ar-paper: #F7FAFC;
    --ar-success: #38A169; --ar-warn: #D69E2E; --ar-danger: #E53E3E;
    --ar-shadow: 0 1px 2px rgba(26,32,44,.04), 0 6px 24px rgba(26,32,44,.06);
    --ar-shadow-lift: 0 2px 6px rgba(26,32,44,.05), 0 24px 48px rgba(26,32,44,.10);
    --ar-jp: 'Zen Maru Gothic', 'Hiragino Maru Gothic ProN', 'Yu Gothic', system-ui, sans-serif;
    --ar-mono: 'JetBrains Mono', 'SF Mono', ui-monospace, monospace;
    font-family: var(--ar-jp);
    color: var(--ar-ink);
    padding: 24px 8px 80px;
}
.ar-root * { box-sizing: border-box; }
.ar-root button { font-family: inherit; cursor: pointer; }

/* === Page header === */
.ar-pageheader { display: flex; align-items: flex-start; gap: 20px; margin-bottom: 22px; flex-wrap: wrap; }
.ar-pageheader .ar-badge {
    width: 56px; height: 56px; border-radius: 14px; flex-shrink: 0;
    background: var(--ar-lamp-bg); border: 1px solid #FEEBC8;
    display: flex; align-items: center; justify-content: center; color: var(--ar-lamp);
}
.ar-pageheader h1 { font-size: 26px; font-weight: 700; line-height: 1.25; margin: 0; }
.ar-pageheader .ar-sub { font-size: 14px; color: var(--ar-muted); margin-top: 4px; line-height: 1.7; }

/* === Year selector pill === */
.ar-yearbar {
    background: #fff; border: 1px solid var(--ar-line); border-radius: 14px;
    padding: 6px; display: inline-flex; gap: 4px; box-shadow: var(--ar-shadow);
    margin-bottom: 18px; flex-wrap: wrap;
}
.ar-yearbar a, .ar-yearbar button {
    padding: 8px 16px; border-radius: 10px; border: none; background: none;
    font-size: 14px; font-weight: 700; color: var(--ar-ink-soft);
    text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    font-family: var(--ar-jp);
}
.ar-yearbar a.on, .ar-yearbar button.on {
    background: var(--ar-teal); color: #fff;
    box-shadow: 0 2px 8px rgba(44,122,123,.3);
}
.ar-yearbar a:hover:not(.on), .ar-yearbar button:hover:not(.on) { background: var(--ar-line-soft); }
.ar-yearbar .ar-reiwa { font-size: 11px; opacity: .65; font-family: var(--ar-mono); }

/* === Hero === */
.ar-hero {
    background: linear-gradient(180deg, #fff 0%, #FFFAF0 100%);
    border: 1px solid var(--ar-line); border-radius: 20px;
    padding: 22px 26px; margin-bottom: 22px; box-shadow: var(--ar-shadow);
    display: grid; grid-template-columns: 1fr auto; gap: 24px; align-items: center;
}
.ar-hero-eyebrow { font-size: 13px; color: var(--ar-lamp); font-weight: 700; letter-spacing: 0.08em; margin-bottom: 4px; }
.ar-hero h2 { font-size: 21px; font-weight: 700; line-height: 1.45; margin: 0 0 4px; }
.ar-hero p { font-size: 14px; color: var(--ar-muted); line-height: 1.7; margin: 0; }
.ar-hero-progress {
    display: flex; align-items: center; gap: 14px; padding: 16px 20px; background: #fff;
    border: 1.5px solid var(--ar-lamp-light); border-radius: 16px; min-width: 220px;
}
.ar-hero-progress .ar-ring { width: 64px; height: 64px; flex-shrink: 0; position: relative; }
.ar-hero-progress .ar-ring svg { transform: rotate(-90deg); }
.ar-hero-progress .ar-ring .ar-num {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 700; color: var(--ar-lamp);
}
.ar-hero-progress .ar-label { font-size: 12px; color: var(--ar-muted); margin-bottom: 2px; }
.ar-hero-progress .ar-val { font-size: 17px; font-weight: 700; color: var(--ar-ink); }
.ar-hero-progress .ar-deadline { font-size: 11px; color: var(--ar-muted); margin-top: 2px; font-family: var(--ar-mono); }

/* === Steps === */
.ar-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 26px; }
.ar-step {
    background: #fff; border: 1px solid var(--ar-line); border-radius: 16px;
    padding: 20px; box-shadow: var(--ar-shadow);
    display: flex; flex-direction: column; gap: 10px;
    transition: transform .15s, box-shadow .15s;
}
.ar-step:hover { transform: translateY(-2px); box-shadow: var(--ar-shadow-lift); }
.ar-step .ar-step-num {
    width: 34px; height: 34px; border-radius: 50%;
    background: var(--ar-teal-bg); color: var(--ar-teal);
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 16px; font-family: var(--ar-mono);
}
.ar-step.done .ar-step-num { background: var(--ar-success); color: #fff; }
.ar-step.current { border-color: var(--ar-lamp); border-width: 2px; padding: 19px; }
.ar-step.current .ar-step-num { background: var(--ar-lamp); color: #fff; box-shadow: 0 0 0 4px rgba(237,137,54,.18); }
.ar-step h3 { font-size: 16px; font-weight: 700; line-height: 1.35; margin: 0; }
.ar-step .ar-step-desc { font-size: 13px; color: var(--ar-muted); line-height: 1.65; flex: 1; margin: 0; }
.ar-step .ar-step-stat {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; color: var(--ar-muted); padding: 5px 10px;
    background: var(--ar-line-soft); border-radius: 8px; align-self: flex-start;
}
.ar-step.done .ar-step-stat { background: #F0FFF4; color: var(--ar-success); }
.ar-step.current .ar-step-stat { background: var(--ar-lamp-bg); color: var(--ar-lamp); }

/* === Section title === */
.ar-section-title {
    display: flex; align-items: center; gap: 10px;
    margin: 26px 0 14px; font-size: 17px; font-weight: 700;
}
.ar-section-title .ar-accent { width: 4px; height: 18px; background: var(--ar-teal); border-radius: 2px; }
.ar-section-title .ar-count { font-size: 13px; color: var(--ar-muted); font-weight: 500; }
.ar-section-title .ar-right { margin-left: auto; }

/* === Data review === */
.ar-review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.ar-data-card {
    background: #fff; border: 1px solid var(--ar-line); border-radius: 16px;
    padding: 18px 20px; box-shadow: var(--ar-shadow);
}
.ar-data-card .ar-head {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 12px; padding-bottom: 10px;
    border-bottom: 1px solid var(--ar-line-soft);
}
.ar-data-card .ar-icw {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--ar-teal-bg); color: var(--ar-teal);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ar-data-card.ar-lamp .ar-icw { background: var(--ar-lamp-bg); color: var(--ar-lamp); }
.ar-data-card.ar-danger .ar-icw { background: #FFF5F5; color: var(--ar-danger); }
.ar-data-card .ar-head h4 { font-size: 14px; font-weight: 700; margin: 0; }
.ar-data-card .ar-as-of {
    margin-left: auto; font-size: 11px; color: var(--ar-muted);
    background: var(--ar-line-soft); padding: 3px 8px; border-radius: 999px; font-family: var(--ar-mono);
}
.ar-kv { display: flex; align-items: center; padding: 9px 0; border-bottom: 1px dashed var(--ar-line-soft); }
.ar-kv:last-child { border-bottom: none; }
.ar-kv .ar-k { font-size: 13px; color: var(--ar-muted); flex: 1; font-weight: 500; }
.ar-kv .ar-v {
    font-size: 18px; font-weight: 700; color: var(--ar-ink);
    font-feature-settings: "tnum"; letter-spacing: -0.01em;
}
.ar-kv .ar-v small { font-size: 11px; color: var(--ar-muted); margin-left: 3px; font-weight: 500; }

/* Inline edit affordance */
.ar-edit {
    cursor: text; padding: 2px 6px; border-radius: 4px;
    transition: background .1s; min-width: 24px; display: inline-block;
}
.ar-edit:hover { background: var(--ar-lamp-bg); outline: 1px dashed var(--ar-lamp-light); outline-offset: 2px; }
.ar-edit[contenteditable="true"]:focus {
    background: #fff; outline: 2px solid var(--ar-lamp); outline-offset: 2px;
}

/* Help tip */
.ar-tip {
    margin-top: 18px;
    background: #FFFCEF; border: 1px solid #FAF089; border-radius: 12px;
    padding: 14px 18px; display: flex; gap: 12px; align-items: flex-start;
}
.ar-tip .ar-i {
    width: 26px; height: 26px; border-radius: 50%; background: var(--ar-lamp);
    color: #fff; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-weight: 700; font-family: var(--ar-mono); font-size: 13px;
}
.ar-tip h5 { font-size: 14px; font-weight: 700; margin: 0 0 3px; }
.ar-tip p { font-size: 13px; color: var(--ar-ink-soft); line-height: 1.7; margin: 0; }

/* Export panel */
.ar-exports {
    background: linear-gradient(180deg, var(--ar-teal-bg) 0%, #fff 100%);
    border: 1px solid var(--ar-teal-bg-deep); border-radius: 20px;
    padding: 24px; margin: 24px 0; box-shadow: var(--ar-shadow);
}
.ar-exports h3 { font-size: 17px; font-weight: 700; margin: 0 0 4px; }
.ar-exports .ar-exports-sub { font-size: 13px; color: var(--ar-muted); margin: 0 0 16px; }
.ar-export-row {
    background: #fff; border: 1px solid var(--ar-line); border-radius: 14px;
    padding: 16px 18px; display: flex; align-items: center; gap: 14px;
    margin-bottom: 10px; flex-wrap: wrap;
}
.ar-export-row .ar-ic-tile {
    width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
    background: var(--ar-teal-bg); color: var(--ar-teal);
    display: flex; align-items: center; justify-content: center;
}
.ar-export-row.ar-form21 .ar-ic-tile { background: var(--ar-lamp-bg); color: var(--ar-lamp); }
.ar-export-row .ar-info { flex: 1; min-width: 200px; }
.ar-export-row h4 { font-size: 15px; font-weight: 700; margin: 0 0 2px; }
.ar-export-row .ar-meta { font-size: 12px; color: var(--ar-muted); display: flex; gap: 10px; flex-wrap: wrap; }
.ar-export-row .ar-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* Buttons */
.ar-btn {
    height: 42px; padding: 0 18px; border-radius: 10px;
    font-family: var(--ar-jp); font-size: 14px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    border: 1.5px solid transparent; transition: transform .1s, box-shadow .1s;
    text-decoration: none;
}
.ar-btn:active { transform: translateY(1px); }
.ar-btn-primary { background: var(--ar-teal); color: #fff; box-shadow: 0 2px 8px rgba(44,122,123,.25); }
.ar-btn-primary:hover { background: #285E61; color: #fff; }
.ar-btn-lamp { background: var(--ar-lamp); color: #fff; box-shadow: 0 2px 8px rgba(237,137,54,.25); }
.ar-btn-lamp:hover { background: #C05621; color: #fff; }
.ar-btn-ghost { background: #fff; color: var(--ar-ink-soft); border-color: var(--ar-line); }
.ar-btn-ghost:hover { background: var(--ar-line-soft); border-color: var(--ar-muted-soft); }
.ar-btn-sm { height: 34px; padding: 0 12px; font-size: 13px; }

/* History table */
.ar-history { background: #fff; border: 1px solid var(--ar-line); border-radius: 16px; overflow: hidden; box-shadow: var(--ar-shadow); }
.ar-history table { width: 100%; border-collapse: collapse; }
.ar-history th, .ar-history td { padding: 13px 16px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--ar-line-soft); }
.ar-history th { background: var(--ar-paper); color: var(--ar-muted); font-weight: 600; font-size: 12px; }
.ar-history tr:last-child td { border-bottom: none; }
.ar-history tr:hover td { background: var(--ar-line-soft); }
.ar-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.ar-pill .ar-dot { width: 6px; height: 6px; border-radius: 50%; }
.ar-pill.ar-draft { background: var(--ar-lamp-bg); color: var(--ar-lamp); }
.ar-pill.ar-draft .ar-dot { background: var(--ar-lamp); }
.ar-pill.ar-done { background: #F0FFF4; color: var(--ar-success); }
.ar-pill.ar-done .ar-dot { background: var(--ar-success); }
.ar-pill.ar-idle { background: var(--ar-line-soft); color: var(--ar-muted); }
.ar-pill.ar-idle .ar-dot { background: var(--ar-muted); }
.ar-pill.ar-check { background: #EBF8FF; color: #2B6CB0; }
.ar-pill.ar-check .ar-dot { background: #2B6CB0; }

/* Toast */
.ar-toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(100px);
    background: var(--ar-ink); color: #fff;
    padding: 13px 22px; border-radius: 12px; font-size: 14px; font-weight: 600;
    box-shadow: 0 12px 32px rgba(0,0,0,.25); z-index: 9999; opacity: 0;
    transition: all .25s ease-out; display: flex; align-items: center; gap: 10px;
    font-family: var(--ar-jp);
}
.ar-toast.on { opacity: 1; transform: translateX(-50%) translateY(0); }
.ar-toast .ar-tic { color: var(--ar-teal-light); }

/* Mobile */
@media (max-width: 900px) {
    .ar-pageheader { flex-direction: column; align-items: stretch; }
    .ar-hero { grid-template-columns: 1fr; padding: 18px 20px; }
    .ar-steps { grid-template-columns: 1fr; }
    .ar-review-grid { grid-template-columns: 1fr; }
    .ar-export-row { flex-direction: column; align-items: stretch; }
    .ar-export-row .ar-actions { width: 100%; }
    .ar-export-row .ar-actions .ar-btn { flex: 1; }
    .ar-history { overflow-x: auto; }
}
</style>

<!-- メインコンテンツ開始 -->
<main class="main-content">
<?php
// 第21号様式データを取得（メインカード表示用）
$form21_data_main = getForm21Data($pdo);

// 提出期限（年度の翌年5月31日）と残日数
$deadline_str = $selected_year . '-05-31';
$today = new DateTime('today');
$deadline = new DateTime($deadline_str);
$days_left = (int) $today->diff($deadline)->format('%r%a');

// 年度履歴の form4/form21 提出済み状態を集計（進捗リング用）
$has_form4 = false; $has_form21 = false; $form4_done = false; $form21_done = false;
foreach (($annual_reports ?? []) as $rpt) {
    if ($rpt['report_type'] === '第4号様式') {
        $has_form4 = true;
        if ($rpt['status'] === '提出済み') $form4_done = true;
    }
    if ($rpt['report_type'] === '第21号様式') {
        $has_form21 = true;
        if ($rpt['status'] === '提出済み') $form21_done = true;
    }
}
$completed_steps = 1; // データ自動集計は常に完了
if ($has_form4 || $has_form21) $completed_steps = 2; // 一度でも出力していれば内容確認段階
if ($form4_done && $form21_done) $completed_steps = 3; // 両方提出済み
$progress_pct = intval(round($completed_steps / 3 * 100));

$reiwa_year = toReiwaYear($selected_year);
?>
<div class="ar-root container-fluid px-md-4 px-2">

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

    <!-- ===== Page header ===== -->
    <div class="ar-pageheader">
        <div class="ar-badge" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M9 13h6M9 17h4"/></svg>
        </div>
        <div>
            <h1>陸運局へ提出する年次報告書</h1>
            <p class="ar-sub">輸送実績報告書（第4号様式）と、福祉タクシー車両報告書（第21号様式）を <b>1年に1回</b> 作成します。<br>
                スマルトのデータから自動で集計するので、内容を確認するだけで完了します。</p>
        </div>
    </div>

    <!-- ===== Year selector ===== -->
    <div class="ar-yearbar" role="tablist" aria-label="年度">
        <?php for ($y = $current_year + 1; $y >= $current_year - 4; $y--): ?>
            <a href="?year=<?= $y ?>" class="<?= $y == $selected_year ? 'on' : '' ?>">
                <?= $y ?>年度<span class="ar-reiwa">R<?= toReiwaYear($y) ?></span>
            </a>
        <?php endfor; ?>
    </div>

    <!-- ===== Hero ===== -->
    <div class="ar-hero">
        <div>
            <div class="ar-hero-eyebrow">
                <?php if ($days_left > 0): ?>
                    ▲ 提出期限まで <?= $days_left ?>日
                <?php elseif ($days_left === 0): ?>
                    ⚡ 提出期限は本日です
                <?php else: ?>
                    ✓ <?= abs($days_left) ?>日経過（期限超過）
                <?php endif; ?>
            </div>
            <h2><?= $selected_year ?>年度（令和<?= $reiwa_year ?>年度）の提出を準備しましょう</h2>
            <p>提出先：大阪運輸支局　／　提出期限：<b><?= htmlspecialchars($deadline_str) ?></b><br>
                スマルトのデータから自動集計済み。内容を確認したらPDF/Excelで書き出して、郵送または窓口へ提出してください。</p>
        </div>
        <div class="ar-hero-progress">
            <div class="ar-ring" aria-hidden="true">
                <svg width="64" height="64">
                    <circle cx="32" cy="32" r="28" fill="none" stroke="#FFE5BD" stroke-width="6"/>
                    <circle cx="32" cy="32" r="28" fill="none" stroke="#ED8936" stroke-width="6"
                            stroke-dasharray="175.9" stroke-dashoffset="<?= 175.9 - (175.9 * $progress_pct / 100) ?>" stroke-linecap="round"/>
                </svg>
                <div class="ar-num"><?= $progress_pct ?>%</div>
            </div>
            <div>
                <div class="ar-label">完了状況</div>
                <div class="ar-val"><?= $completed_steps ?>/3 ステップ</div>
                <div class="ar-deadline"><?= $completed_steps == 3 ? '提出済み' : ($completed_steps == 2 ? '残り：提出のみ' : '残り：内容確認・出力') ?></div>
            </div>
        </div>
    </div>

    <!-- ===== 3 STEP cards ===== -->
    <div class="ar-steps">
        <div class="ar-step done">
            <div class="ar-step-num">✓</div>
            <h3>1. データを集める</h3>
            <p class="ar-step-desc">スマルトに記録された運行・乗車・事故・車両データから自動で年度集計します。手作業の入力は不要です。</p>
            <div class="ar-step-stat">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4L19 7"/></svg>
                自動集計済み
            </div>
        </div>
        <div class="ar-step <?= $completed_steps == 2 ? 'current' : ($completed_steps >= 2 ? 'done' : '') ?>">
            <div class="ar-step-num"><?= $completed_steps >= 3 ? '✓' : '2' ?></div>
            <h3>2. 内容を確認する</h3>
            <p class="ar-step-desc">下の集計データを確認してください。事業者番号・資本金・兼営事業などは数値をクリックしてその場で編集できます。</p>
            <div class="ar-step-stat">
                <?php if ($completed_steps >= 3): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4L19 7"/></svg>
                    確認済み
                <?php elseif ($completed_steps == 2): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    いまここ
                <?php else: ?>
                    まだ
                <?php endif; ?>
            </div>
        </div>
        <div class="ar-step <?= $completed_steps == 3 ? 'done' : ($completed_steps == 2 ? '' : 'current') ?>">
            <div class="ar-step-num"><?= $completed_steps >= 3 ? '✓' : '3' ?></div>
            <h3>3. ダウンロードして提出</h3>
            <p class="ar-step-desc">PDFまたはExcelで書き出して、運輸支局へ郵送または窓口提出。書式は近畿運輸局の公式様式に準拠しています。</p>
            <div class="ar-step-stat">
                <?php if ($completed_steps >= 3): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4L19 7"/></svg>
                    提出済み
                <?php else: ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                    まだ
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== Data review ===== -->
    <div class="ar-section-title">
        <div class="ar-accent"></div>
        集計データを確認
        <span class="ar-count"><?= ($selected_year - 1) ?>年4月1日 〜 <?= $selected_year ?>年3月31日</span>
        <div class="ar-right">
            <a href="?year=<?= $selected_year ?>&refresh=1" class="ar-btn ar-btn-ghost ar-btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                再集計
            </a>
        </div>
    </div>

    <div class="ar-review-grid">
        <!-- 事業概況 -->
        <div class="ar-data-card">
            <div class="ar-head">
                <div class="ar-icw"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l8-4 8 4v14"/><path d="M9 9h.01M9 12h.01M9 15h.01M13 9h.01M13 12h.01M13 15h.01"/></svg></div>
                <h4>事業概況</h4>
                <span class="ar-as-of">R<?= $reiwa_year ?>年3月31日現在</span>
            </div>
            <div class="ar-kv"><span class="ar-k">事業者番号</span><span class="ar-v"><span class="ar-edit" data-field="business_number"><?= htmlspecialchars($company_info['business_number'] ?? '') ?></span></span></div>
            <div class="ar-kv"><span class="ar-k">事業用自動車数</span><span class="ar-v"><?= number_format(intval($business_overview['vehicle_count'] ?? 0)) ?><small>両</small></span></div>
            <div class="ar-kv"><span class="ar-k">従業員数（うち運転者）</span><span class="ar-v"><?= number_format(intval($business_overview['employee_count'] ?? 0)) ?><small>名（</small><?= number_format(intval($business_overview['driver_count'] ?? 0)) ?><small>）</small></span></div>
            <div class="ar-kv"><span class="ar-k">資本金</span><span class="ar-v"><span class="ar-edit" data-field="capital_thousand_yen" data-numeric="1"><?= number_format(intval($company_info['capital_thousand_yen'] ?? 0)) ?></span><small>千円</small></span></div>
            <div class="ar-kv"><span class="ar-k">兼営事業</span><span class="ar-v" style="font-size:14px;font-weight:600;"><span class="ar-edit" data-field="concurrent_business"><?= htmlspecialchars($company_info['concurrent_business'] ?? '') ?></span></span></div>
        </div>

        <!-- 輸送実績 -->
        <div class="ar-data-card ar-lamp">
            <div class="ar-head">
                <div class="ar-icw"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-5a2 2 0 0 1 2-1h6a2 2 0 0 1 2 1l2 5"/><rect x="3" y="12" width="18" height="5"/><circle cx="7" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/></svg></div>
                <h4>輸送実績</h4>
                <span class="ar-as-of">R<?= $reiwa_year - 1 ?>年4月〜R<?= $reiwa_year ?>年3月</span>
            </div>
            <div class="ar-kv"><span class="ar-k">走行キロ</span><span class="ar-v"><?= number_format(intval($transport_results['total_distance'] ?? 0)) ?><small>km</small></span></div>
            <div class="ar-kv"><span class="ar-k">運送回数</span><span class="ar-v"><?= number_format(intval($transport_results['ride_count'] ?? 0)) ?><small>回</small></span></div>
            <div class="ar-kv"><span class="ar-k">輸送人員</span><span class="ar-v"><?= number_format(intval($transport_results['total_passengers'] ?? 0)) ?><small>人</small></span></div>
            <div class="ar-kv"><span class="ar-k">営業収入</span><span class="ar-v"><?= number_format(intval(($transport_results['total_revenue'] ?? 0) / 1000)) ?><small>千円</small></span></div>
        </div>

        <!-- 事故件数 -->
        <div class="ar-data-card ar-danger">
            <div class="ar-head">
                <div class="ar-icw"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3L2 20h20L12 3zM12 10v5M12 18h.01"/></svg></div>
                <h4>事故件数</h4>
                <span class="ar-as-of">年度集計</span>
            </div>
            <div class="ar-kv"><span class="ar-k">交通事故件数</span><span class="ar-v"><?= intval($accident_data['traffic_accidents'] ?? 0) ?><small>件</small></span></div>
            <div class="ar-kv"><span class="ar-k">重大事故件数</span><span class="ar-v"><?= intval($accident_data['serious_accidents'] ?? 0) ?><small>件</small></span></div>
            <div class="ar-kv"><span class="ar-k">死者数</span><span class="ar-v"><?= intval($accident_data['total_deaths'] ?? 0) ?><small>人</small></span></div>
            <div class="ar-kv"><span class="ar-k">負傷者数</span><span class="ar-v"><?= intval($accident_data['total_injuries'] ?? 0) ?><small>人</small></span></div>
        </div>

        <!-- 福祉タクシー車両（第21号様式） -->
        <div class="ar-data-card ar-lamp">
            <div class="ar-head">
                <div class="ar-icw"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="3"/><path d="M9 22v-7a3 3 0 0 1 3-3 3 3 0 0 1 3 3v7"/><circle cx="6" cy="20" r="2"/></svg></div>
                <h4>福祉タクシー車両（第21号様式）</h4>
                <span class="ar-as-of">R<?= $reiwa_year ?>年3月31日現在</span>
            </div>
            <div class="ar-kv"><span class="ar-k">合計</span><span class="ar-v"><?= intval($form21_data_main['total']) ?><small>両</small></span></div>
            <div class="ar-kv"><span class="ar-k">車椅子対応車（うちUDT）</span><span class="ar-v"><?= intval($form21_data_main['wheelchair']) ?><small>両（</small><?= intval($form21_data_main['udt']) ?><small>）</small></span></div>
            <div class="ar-kv"><span class="ar-k">寝台対応車</span><span class="ar-v"><?= intval($form21_data_main['stretcher']) ?><small>両</small></span></div>
            <div class="ar-kv"><span class="ar-k">兼用車・回転シート車</span><span class="ar-v"><?= intval($form21_data_main['combo']) + intval($form21_data_main['rotation']) ?><small>両</small></span></div>
        </div>
    </div>

    <div class="ar-tip">
        <div class="ar-i">i</div>
        <div>
            <h5>数字を直接編集できます</h5>
            <p>事業者番号・資本金・兼営事業は <b style="color:var(--ar-lamp);">薄い枠が出る</b> 場所をクリックすると編集できます。編集内容はダウンロードする書類に反映されます。集計値（走行キロ・運送回数・事故件数・車両数）は<a href="vehicle_management.php">車両管理</a>等の元データを修正してから「再集計」してください。</p>
        </div>
    </div>

    <!-- ===== Export panel ===== -->
    <div class="ar-section-title" style="margin-top: 30px;">
        <div class="ar-accent" style="background: var(--ar-lamp);"></div>
        ダウンロードして提出
        <span class="ar-count">公式様式に準拠</span>
    </div>

    <div class="ar-exports">
        <h3>📥 <?= $selected_year ?>年度（令和<?= $reiwa_year ?>年度）の書類を書き出す</h3>
        <p class="ar-exports-sub">A4縦・近畿運輸局の公式書式に準拠。印刷してそのまま提出できます。出力するとこの画面の「過去の提出履歴」にも自動記録されます。</p>

        <!-- 第4号様式 -->
        <div class="ar-export-row">
            <div class="ar-ic-tile">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>
            </div>
            <div class="ar-info">
                <h4>第4号様式 第3表 — 輸送実績報告書</h4>
                <div class="ar-meta">
                    <span>提出期限：5月31日</span>
                    <span>旅客自動車運送事業等報告規則 第2条</span>
                </div>
            </div>
            <div class="ar-actions">
                <form method="POST" class="d-inline" target="_blank">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="export_form4">
                    <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                    <button type="submit" class="ar-btn ar-btn-ghost">PDF</button>
                </form>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="export_form4_excel">
                    <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                    <button type="submit" class="ar-btn ar-btn-primary">Excel</button>
                </form>
            </div>
        </div>

        <!-- 第21号様式 -->
        <div class="ar-export-row ar-form21">
            <div class="ar-ic-tile">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="3"/><path d="M9 22v-7a3 3 0 0 1 3-3 3 3 0 0 1 3 3v7"/><circle cx="6" cy="20" r="2"/></svg>
            </div>
            <div class="ar-info">
                <h4>第21号様式 — 移動等円滑化実績等報告書（福祉タクシー車両）</h4>
                <div class="ar-meta">
                    <span>提出期限：5月31日</span>
                    <span>バリアフリー法施行規則 第23条</span>
                </div>
            </div>
            <div class="ar-actions">
                <form method="POST" class="d-inline" target="_blank">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="export_form21">
                    <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                    <button type="submit" class="ar-btn ar-btn-ghost">PDF</button>
                </form>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="export_form21_excel">
                    <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                    <button type="submit" class="ar-btn ar-btn-lamp">Excel</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== History ===== -->
    <div class="ar-section-title">
        <div class="ar-accent"></div>
        過去の提出履歴
        <span class="ar-count"><?= count($annual_reports ?? []) ?>件</span>
    </div>

    <div class="ar-history">
        <table>
            <thead>
                <tr><th>年度</th><th>様式</th><th>状態</th><th>担当</th><th>更新日</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($annual_reports)): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 40px 16px; color: var(--ar-muted);">
                        まだ書類を出力していません。上の「ダウンロードして提出」から PDF/Excel を生成すると、自動で履歴に記録されます。
                    </td></tr>
                <?php else: foreach ($annual_reports as $report): ?>
                <tr>
                    <td><b><?= htmlspecialchars($report['fiscal_year']) ?>年度</b><span style="color:var(--ar-muted);font-size:11px;margin-left:6px;">R<?= toReiwaYear($report['fiscal_year']) ?></span></td>
                    <td><?= htmlspecialchars($report['report_type']) ?></td>
                    <td>
                        <?php
                        $st = $report['status'];
                        $cls = 'ar-idle';
                        if ($st === '作成中') $cls = 'ar-draft';
                        elseif ($st === '確認中') $cls = 'ar-check';
                        elseif ($st === '提出済み') $cls = 'ar-done';
                        ?>
                        <span class="ar-pill <?= $cls ?>"><span class="ar-dot"></span><?= htmlspecialchars($st) ?></span>
                    </td>
                    <td><?= htmlspecialchars($report['submitted_by_name'] ?? '') ?></td>
                    <td style="font-family:var(--ar-mono);font-size:12px;color:var(--ar-muted);">
                        <?= !empty($report['updated_at']) ? date('Y-m-d', strtotime($report['updated_at'])) : '-' ?>
                    </td>
                    <td>
                        <?php if ($st !== '提出済み'): ?>
                            <button class="ar-btn ar-btn-ghost ar-btn-sm" onclick="updateStatus(<?= $report['id'] ?>, '<?= htmlspecialchars($st) ?>')">状態変更</button>
                        <?php else: ?>
                            <span style="color:var(--ar-muted); font-size:12px;">完了</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ===== 関連機能（既存維持） ===== -->
    <div class="ar-section-title" style="margin-top: 30px;">
        <div class="ar-accent" style="background: var(--ar-muted-soft);"></div>
        関連機能
    </div>
    <div class="row g-2 mb-4">
        <div class="col-6 col-lg-3"><a href="accident_management.php" class="ar-btn ar-btn-ghost w-100"><i class="fas fa-exclamation-triangle me-1"></i>事故管理</a></div>
        <div class="col-6 col-lg-3"><a href="vehicle_management.php" class="ar-btn ar-btn-ghost w-100"><i class="fas fa-car me-1"></i>車両管理</a></div>
        <div class="col-6 col-lg-3"><a href="company_settings.php" class="ar-btn ar-btn-ghost w-100"><i class="fas fa-building me-1"></i>会社情報設定</a></div>
        <div class="col-6 col-lg-3"><a href="dashboard.php" class="ar-btn ar-btn-ghost w-100"><i class="fas fa-home me-1"></i>ダッシュボード</a></div>
    </div>

</div><!-- .ar-root -->

<!-- Toast -->
<div class="ar-toast" id="arToast">
    <svg class="ar-tic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4L19 7"/></svg>
    <span id="arToastMsg">完了しました</span>
</div></main>

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
(function() {
    // ===== Toast =====
    const toast = document.getElementById('arToast');
    const toastMsg = document.getElementById('arToastMsg');
    let toastTimer;
    window.arShowToast = function(msg, isError) {
        if (!toast || !toastMsg) return;
        toastMsg.textContent = msg;
        toast.style.background = isError ? '#E53E3E' : '#1A202C';
        toast.classList.add('on');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('on'), 2400);
    };

    // ===== Inline edit (AJAX保存) =====
    const csrf = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>';
    document.querySelectorAll('.ar-edit').forEach(el => {
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('spellcheck', 'false');

        let originalValue = el.textContent;
        el.addEventListener('focus', () => {
            originalValue = el.textContent;
        });
        el.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
            if (e.key === 'Escape') { el.textContent = originalValue; el.blur(); }
        });
        el.addEventListener('blur', () => {
            const field = el.dataset.field;
            let value = el.textContent.trim();
            if (el.dataset.numeric === '1') {
                value = value.replace(/[^\d-]/g, '');
            }
            if (value === originalValue.trim()) return; // 変更なし

            const fd = new FormData();
            fd.append('action', 'update_inline_field');
            fd.append('field', field);
            fd.append('value', value);
            fd.append('csrf_token', csrf);

            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (el.dataset.numeric === '1') {
                            el.textContent = Number(data.value).toLocaleString();
                        }
                        window.arShowToast('変更を保存しました');
                    } else {
                        el.textContent = originalValue;
                        window.arShowToast('保存に失敗しました（' + (data.error || 'unknown') + '）', true);
                    }
                })
                .catch(err => {
                    el.textContent = originalValue;
                    window.arShowToast('通信エラーが発生しました', true);
                    console.error(err);
                });
        });
    });

    // ===== 出力ボタン押下時にトースト表示 =====
    document.querySelectorAll('.ar-export-row form').forEach(f => {
        f.addEventListener('submit', () => {
            const action = f.querySelector('[name="action"]').value;
            const map = {
                'export_form4':       '第4号様式 PDFを生成しています…',
                'export_form4_excel': '第4号様式 Excelをダウンロードします',
                'export_form21':      '第21号様式 PDFを生成しています…',
                'export_form21_excel':'第21号様式 Excelをダウンロードします',
            };
            window.arShowToast(map[action] || '書類を出力します');
        });
    });

    // ===== 状態更新モーダル（履歴テーブルの「状態変更」ボタンから） =====
    window.updateStatus = function(reportId, currentStatus) {
        document.getElementById('update_report_id').value = reportId;
        document.getElementById('status').value = currentStatus;
        const modalEl = document.getElementById('updateStatusModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    };
})();
</script>

<?php
// 統一フッターの出力
echo $page_data['html_footer'] ?? '';
?>
