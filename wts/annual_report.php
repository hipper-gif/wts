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
                case 'update_company_info':
                    $pdo->beginTransaction();
                    try {
                        // 最初のレコードのIDを動的に取得してUPDATE
                        $id_row = $pdo->query("SELECT id FROM company_info ORDER BY id LIMIT 1")->fetch();
                        $target_id = $id_row ? $id_row['id'] : 1;
                        $stmt = $pdo->prepare("
                            UPDATE company_info SET
                            company_name = ?, representative_name = ?,
                            postal_code = ?, address = ?, phone = ?,
                            fax = ?, manager_name = ?, manager_email = ?,
                            license_number = ?, business_type = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $_POST['company_name'], $_POST['representative_name'],
                            $_POST['postal_code'], $_POST['address'], $_POST['phone'],
                            $_POST['fax'] ?? '', $_POST['manager_name'] ?? '', $_POST['manager_email'] ?? '',
                            $_POST['license_number'], $_POST['business_type'],
                            $target_id
                        ]);
                        logAnnualReportAudit($pdo, $user_id, $user_name, '事業者情報更新', "company_name={$_POST['company_name']}");
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                    $message = "事業者情報を更新しました。";
                    break;

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

// PDF出力関数 — 近畿運輸局 第4号様式 第3表（福祉限定）の正式フォーマットに準拠
function generateForm4PDF($company, $business, $transport, $accident, $year) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=0, must-revalidate');

    $prev_year = $year - 1;
    $revenue_sen = floor(($transport['total_revenue'] ?? 0) / 1000); // 千円単位

    // 値のフォーマット（0の場合も表示）
    $f = function($v) { return number_format(intval($v)); };

    echo '<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>第4号様式 第3表（限定）輸送実績報告書 ' . htmlspecialchars($year) . '年度</title>
<style>
/* === 印刷設定: A4縦 === */
@page { size: A4 portrait; margin: 12mm 15mm 10mm 15mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: "Yu Gothic", "YuGothic", "Meiryo", "Hiragino Kaku Gothic ProN", sans-serif;
    font-size: 10.5pt;
    color: #000;
    background: #fff;
    line-height: 1.4;
}

/* === 画面プレビュー === */
.page-wrapper {
    width: 210mm; min-height: 297mm;
    margin: 10mm auto; padding: 12mm 15mm 10mm 15mm;
    background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.15);
}
.print-controls {
    text-align: center; margin: 0 auto 10px; padding: 12px;
    background: #f5f5f5; border-radius: 6px; max-width: 210mm;
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

/* === 様式ヘッダー === */
.form-header { font-size: 8pt; color: #333; margin-bottom: 6mm; }
.form-number { display: flex; justify-content: space-between; }
.form-number-left { }
.form-number-right { display: flex; align-items: center; gap: 2mm; }
.form-number-right .bango-box {
    display: inline-block; border: 1px solid #000; padding: 1px 8px;
    min-width: 60mm; text-align: center; font-size: 9pt;
}
.form-number-right .gentei { border: 1px solid #000; padding: 1px 6px; font-size: 9pt; }

.shikyu-line { font-size: 10pt; margin: 4mm 0; }
.shikyu-line .shikyu-name {
    border-bottom: 1px solid #000; display: inline-block;
    min-width: 30mm; text-align: center; padding: 0 2mm;
}

/* === タイトル === */
.report-title {
    text-align: center; font-size: 12pt; font-weight: bold;
    margin: 4mm 0 5mm; letter-spacing: 0.15em;
}

/* === 宛先・事業者情報 === */
.ate-line { font-size: 10pt; margin: 2mm 0 4mm; text-indent: 12em; }
.company-block { margin-left: 48%; margin-bottom: 4mm; }
.company-block table { border-collapse: collapse; }
.company-block td { padding: 1px 4px; font-size: 9.5pt; vertical-align: top; }
.company-block td.lbl { white-space: nowrap; padding-right: 6px; }
.company-block td.val { border-bottom: 1px dotted #666; min-width: 50mm; }

/* === データテーブル === */
.data-table {
    width: 100%; border-collapse: collapse; margin-bottom: 0;
    border: 1.5pt solid #000;
}
.data-table th, .data-table td {
    border: 0.5pt solid #000; padding: 3px 6px;
    font-size: 9.5pt; vertical-align: middle;
}
.data-table .section-title {
    text-align: left; font-weight: bold; font-size: 9.5pt;
    background: none; border-bottom: 1.5pt solid #000;
    padding: 4px 6px;
}
.data-table .col-header {
    text-align: center; font-weight: normal; font-size: 9pt;
    background: none; height: 22px;
}
.data-table .item-label {
    text-align: left; font-size: 9.5pt; background: none;
    padding-left: 8px;
}
.data-table .item-value {
    text-align: right; font-size: 10pt; background: none;
    padding-right: 8px;
}
.data-table .employee-paren {
    text-align: right; font-size: 9pt; padding-right: 4px;
}

/* === 備考 === */
.biko {
    border: 1.5pt solid #000; border-top: none;
    padding: 4px 8px; font-size: 8pt; line-height: 1.5;
}
.biko .biko-title { font-weight: bold; font-size: 8.5pt; margin-bottom: 2px; }
.biko ol { margin: 0; padding-left: 16px; }
.biko li { margin-bottom: 1px; }
</style>
</head>
<body>

<div class="print-controls">
    <button class="btn-print" onclick="window.print()"><b>PDF保存 / 印刷</b></button>
    <button class="btn-close" onclick="window.close()">閉じる</button>
</div>

<div class="page-wrapper">

<!-- ヘッダー: 様式番号 -->
<div class="form-header">
    <div class="form-number">
        <div class="form-number-left">第４号様式　（第２条関係）　（日本工業規格Ａ列４番）　第３表</div>
        <div class="form-number-right">
            事業者番号 <span class="bango-box">&nbsp;</span>
            <span class="gentei">限定</span>
        </div>
    </div>
</div>

<!-- 運輸支局 -->
<div class="shikyu-line">
    <span class="shikyu-name">大阪</span>運輸支局
</div>

<!-- タイトル -->
<div class="report-title">
    一般乗用旅客自動車運送事業 （限定） 輸送実績報告書 （' . htmlspecialchars($year) . '年度）
</div>

<!-- 宛先 -->
<div class="ate-line">国土交通大臣　あて</div>

<!-- 事業者情報 -->
<div class="company-block">
    <table>
        <tr><td class="lbl">住　　　所</td><td class="val">' . htmlspecialchars(($company['postal_code'] ? '〒' . $company['postal_code'] . '　' : '') . $company['address']) . '</td></tr>
        <tr><td class="lbl">事業者名</td><td class="val">' . htmlspecialchars($company['company_name']) . '</td></tr>
        <tr><td class="lbl">代表者名</td><td class="val">' . htmlspecialchars($company['representative_name']) . '　</td></tr>
        <tr><td class="lbl">電話番号</td><td class="val">' . htmlspecialchars($company['phone']) . '</td></tr>
    </table>
</div>

<!-- ============ 事業概況 ============ -->
<table class="data-table">
    <tr>
        <td colspan="3" class="section-title">事業概況 （' . htmlspecialchars($year) . '年３月３１日現在）</td>
    </tr>
    <tr>
        <td class="col-header" style="width:50%;">&nbsp;</td>
        <td class="col-header" style="width:25%;">管轄区域内</td>
        <td class="col-header" style="width:25%;">全国</td>
    </tr>
    <tr>
        <td class="item-label">資本金（基金）の額 （千円）</td>
        <td class="item-value">&nbsp;</td>
        <td class="item-value">&nbsp;</td>
    </tr>
    <tr>
        <td class="item-label">兼営事業</td>
        <td class="item-value">&nbsp;</td>
        <td class="item-value">&nbsp;</td>
    </tr>
    <tr>
        <td class="item-label">事業用自動車数 （両）</td>
        <td class="item-value">' . $f($business['vehicle_count']) . '</td>
        <td class="item-value">' . $f($business['vehicle_count']) . '</td>
    </tr>
    <tr>
        <td class="item-label">従業員数</td>
        <td class="item-value">' . $f($business['employee_count']) . '<span class="employee-paren">（' . $f($business['driver_count']) . '）</span></td>
        <td class="item-value">' . $f($business['employee_count']) . '<span class="employee-paren">（' . $f($business['driver_count']) . '）</span></td>
    </tr>
</table>

<!-- ============ 輸送実績 ============ -->
<table class="data-table" style="border-top: none;">
    <tr>
        <td colspan="3" class="section-title">輸送実績 （前年４月１日から本年３月３１日まで）</td>
    </tr>
    <tr>
        <td class="col-header" style="width:50%;">&nbsp;</td>
        <td class="col-header" style="width:25%;">管轄区域内</td>
        <td class="col-header" style="width:25%;">全国</td>
    </tr>
    <tr>
        <td class="item-label">走行キロ （キロメートル）</td>
        <td class="item-value">' . $f($transport['total_distance']) . '</td>
        <td class="item-value">' . $f($transport['total_distance']) . '</td>
    </tr>
    <tr>
        <td class="item-label">運送回数 （回）</td>
        <td class="item-value">' . $f($transport['ride_count']) . '</td>
        <td class="item-value">' . $f($transport['ride_count']) . '</td>
    </tr>
    <tr>
        <td class="item-label">輸送人員 （人）</td>
        <td class="item-value">' . $f($transport['total_passengers']) . '</td>
        <td class="item-value">' . $f($transport['total_passengers']) . '</td>
    </tr>
    <tr>
        <td class="item-label">営業収入 （千円）</td>
        <td class="item-value">' . $f($revenue_sen) . '</td>
        <td class="item-value">' . $f($revenue_sen) . '</td>
    </tr>
</table>

<!-- ============ 事故件数 ============ -->
<table class="data-table" style="border-top: none;">
    <tr>
        <td colspan="3" class="section-title">事故件数 （前年４月１日から本年３月３１日まで）</td>
    </tr>
    <tr>
        <td class="col-header" style="width:50%;">&nbsp;</td>
        <td class="col-header" style="width:25%;">管轄区域内</td>
        <td class="col-header" style="width:25%;">全国</td>
    </tr>
    <tr>
        <td class="item-label">交通事故件数</td>
        <td class="item-value">' . $f($accident['traffic_accidents']) . '</td>
        <td class="item-value">' . $f($accident['traffic_accidents']) . '</td>
    </tr>
    <tr>
        <td class="item-label">重大事故件数</td>
        <td class="item-value">' . $f($accident['serious_accidents']) . '</td>
        <td class="item-value">' . $f($accident['serious_accidents']) . '</td>
    </tr>
    <tr>
        <td class="item-label">死者数</td>
        <td class="item-value">' . $f($accident['total_deaths']) . '</td>
        <td class="item-value">' . $f($accident['total_deaths']) . '</td>
    </tr>
    <tr>
        <td class="item-label">負傷者数</td>
        <td class="item-value">' . $f($accident['total_injuries']) . '</td>
        <td class="item-value">' . $f($accident['total_injuries']) . '</td>
    </tr>
</table>

<!-- ============ 備考 ============ -->
<div class="biko">
    <span class="biko-title">備　考</span>
    <ol>
        <li>兼営事業については、主な兼営事業の名称を記載すること。</li>
        <li>従業員数は、兼営事業がある場合は主として当該事業に従事している人数及び共通部門に従事している従業員については当該事業分として適正な基準により配分した人数とする。</li>
        <li>従業員数の欄の（　　）には、運転者数を記載すること。</li>
        <li>交通事故とは、道路交通法（昭和35年法律第105号）第72条第１項の交通事故をいう。</li>
        <li>重大事故とは、自動車事故報告規則（昭和26年運輸省令第104号）第２条の事故をいう。</li>
    </ol>
</div>

</div><!-- .page-wrapper -->
</body>
</html>';
}

// エクセル出力関数 — 第4号様式 第3表（福祉限定）準拠
function generateForm4Excel($company, $business, $transport, $accident, $year) {
    $filename = 'gentei_jisseki_' . $year . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    $revenue_sen = floor(($transport['total_revenue'] ?? 0) / 1000);

    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8">';
    echo '<style>
        td, th { font-family: "Yu Gothic", "Meiryo", sans-serif; font-size: 10pt; vertical-align: middle; }
        td.num { text-align: right; mso-number-format:"\#\,\#\#0"; }
        td.lbl { background: none; }
        .sec { font-weight: bold; }
        .hdr { text-align: center; }
    </style></head><body>';

    echo '<table border="1" cellpadding="4" cellspacing="0">';

    // 様式番号
    echo '<tr><td colspan="3">第４号様式（第２条関係）（日本工業規格Ａ列４番）第３表</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';

    // 支局・タイトル
    echo '<tr><td colspan="3">大阪運輸支局</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';
    echo '<tr><td colspan="3" style="text-align:center;font-weight:bold;font-size:12pt;">一般乗用旅客自動車運送事業（限定）輸送実績報告書（' . htmlspecialchars($year) . '年度）</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';

    // 事業者情報
    echo '<tr><td></td><td>住　　　所</td><td>' . htmlspecialchars(($company['postal_code'] ? '〒' . $company['postal_code'] . ' ' : '') . $company['address']) . '</td></tr>';
    echo '<tr><td></td><td>事業者名</td><td>' . htmlspecialchars($company['company_name']) . '</td></tr>';
    echo '<tr><td></td><td>代表者名</td><td>' . htmlspecialchars($company['representative_name']) . '</td></tr>';
    echo '<tr><td></td><td>電話番号</td><td>' . htmlspecialchars($company['phone']) . '</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';

    // 事業概況
    echo '<tr><td colspan="3" class="sec">事業概況（' . htmlspecialchars($year) . '年3月31日現在）</td></tr>';
    echo '<tr><td class="lbl"></td><td class="hdr">管轄区域内</td><td class="hdr">全国</td></tr>';
    echo '<tr><td class="lbl">資本金（基金）の額（千円）</td><td class="num"></td><td class="num"></td></tr>';
    echo '<tr><td class="lbl">兼営事業</td><td></td><td></td></tr>';
    echo '<tr><td class="lbl">事業用自動車数（両）</td><td class="num">' . $business['vehicle_count'] . '</td><td class="num">' . $business['vehicle_count'] . '</td></tr>';
    echo '<tr><td class="lbl">従業員数（うち運転者数）</td><td class="num">' . $business['employee_count'] . '（' . $business['driver_count'] . '）</td><td class="num">' . $business['employee_count'] . '（' . $business['driver_count'] . '）</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';

    // 輸送実績
    echo '<tr><td colspan="3" class="sec">輸送実績（前年4月1日から本年3月31日まで）</td></tr>';
    echo '<tr><td class="lbl"></td><td class="hdr">管轄区域内</td><td class="hdr">全国</td></tr>';
    echo '<tr><td class="lbl">走行キロ（キロメートル）</td><td class="num">' . intval($transport['total_distance']) . '</td><td class="num">' . intval($transport['total_distance']) . '</td></tr>';
    echo '<tr><td class="lbl">運送回数（回）</td><td class="num">' . intval($transport['ride_count']) . '</td><td class="num">' . intval($transport['ride_count']) . '</td></tr>';
    echo '<tr><td class="lbl">輸送人員（人）</td><td class="num">' . intval($transport['total_passengers']) . '</td><td class="num">' . intval($transport['total_passengers']) . '</td></tr>';
    echo '<tr><td class="lbl">営業収入（千円）</td><td class="num">' . $revenue_sen . '</td><td class="num">' . $revenue_sen . '</td></tr>';
    echo '<tr><td colspan="3"></td></tr>';

    // 事故件数
    echo '<tr><td colspan="3" class="sec">事故件数（前年4月1日から本年3月31日まで）</td></tr>';
    echo '<tr><td class="lbl"></td><td class="hdr">管轄区域内</td><td class="hdr">全国</td></tr>';
    echo '<tr><td class="lbl">交通事故件数</td><td class="num">' . $accident['traffic_accidents'] . '</td><td class="num">' . $accident['traffic_accidents'] . '</td></tr>';
    echo '<tr><td class="lbl">重大事故件数</td><td class="num">' . $accident['serious_accidents'] . '</td><td class="num">' . $accident['serious_accidents'] . '</td></tr>';
    echo '<tr><td class="lbl">死者数</td><td class="num">' . $accident['total_deaths'] . '</td><td class="num">' . $accident['total_deaths'] . '</td></tr>';
    echo '<tr><td class="lbl">負傷者数</td><td class="num">' . $accident['total_injuries'] . '</td><td class="num">' . $accident['total_injuries'] . '</td></tr>';

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

    <!-- 事業者情報カード -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-building me-2"></i>事業者基本情報</h5>
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#companyInfoModal">
                        <i class="fas fa-edit me-1"></i>編集
                    </button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-6">
                            <table class="table table-borderless table-sm">
                                <tr>
                                    <td class="fw-bold" style="width: 120px;">事業者名:</td>
                                    <td><?= htmlspecialchars($company_info['company_name']) ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">代表者名:</td>
                                    <td><?= htmlspecialchars($company_info['representative_name']) ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">住所:</td>
                                    <td><?= htmlspecialchars($company_info['address']) ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-lg-6">
                            <table class="table table-borderless table-sm">
                                <tr>
                                    <td class="fw-bold" style="width: 120px;">電話番号:</td>
                                    <td><?= htmlspecialchars($company_info['phone']) ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">FAX:</td>
                                    <td><?= htmlspecialchars($company_info['fax'] ?? '') ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">運行管理者:</td>
                                    <td><?= htmlspecialchars($company_info['manager_name'] ?? '') ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">管理者メール:</td>
                                    <td><?= htmlspecialchars($company_info['manager_email'] ?? '') ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">事業種別:</td>
                                    <td><?= htmlspecialchars($company_info['business_type']) ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">許可番号:</td>
                                    <td><?= htmlspecialchars($company_info['license_number'] ?: '未設定') ?></td>
                                </tr>
                            </table>
                        </div>
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

<!-- 事業者情報編集モーダル -->
<div class="modal fade" id="companyInfoModal" tabindex="-1" aria-labelledby="companyInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="companyInfoModalLabel"><i class="fas fa-building me-2"></i>事業者情報編集</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_company_info">
                    
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="company_name" class="form-label">事業者名 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="company_name" 
                                       value="<?= htmlspecialchars($company_info['company_name']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="representative_name" class="form-label">代表者名</label>
                                <input type="text" class="form-control" name="representative_name" 
                                       value="<?= htmlspecialchars($company_info['representative_name']) ?>"
                                       placeholder="代表取締役　田中　太郎">
                            </div>
                            
                            <div class="mb-3">
                                <label for="postal_code" class="form-label">郵便番号</label>
                                <input type="text" class="form-control" name="postal_code" 
                                       value="<?= htmlspecialchars($company_info['postal_code']) ?>"
                                       placeholder="000-0000">
                            </div>
                        </div>
                        
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="address" class="form-label">住所 <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="address" rows="3" required><?= htmlspecialchars($company_info['address']) ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">電話番号</label>
                                <input type="text" class="form-control" name="phone"
                                       value="<?= htmlspecialchars($company_info['phone']) ?>"
                                       placeholder="06-1234-5678">
                            </div>

                            <div class="mb-3">
                                <label for="fax" class="form-label">FAX番号</label>
                                <input type="text" class="form-control" name="fax"
                                       value="<?= htmlspecialchars($company_info['fax'] ?? '') ?>"
                                       placeholder="06-1234-5679">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="manager_name" class="form-label">運行管理者氏名</label>
                                <input type="text" class="form-control" name="manager_name"
                                       value="<?= htmlspecialchars($company_info['manager_name'] ?? '') ?>"
                                       placeholder="運行管理者の氏名">
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="manager_email" class="form-label">管理者メールアドレス</label>
                                <input type="email" class="form-control" name="manager_email"
                                       value="<?= htmlspecialchars($company_info['manager_email'] ?? '') ?>"
                                       placeholder="manager@example.co.jp">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="license_number" class="form-label">許可番号</label>
                                <input type="text" class="form-control" name="license_number" 
                                       value="<?= htmlspecialchars($company_info['license_number']) ?>"
                                       placeholder="近運輸第○○号">
                            </div>
                        </div>
                        
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="business_type" class="form-label">事業種別</label>
                                <select class="form-select" name="business_type">
                                    <option value="一般乗用旅客自動車運送事業（福祉）" 
                                            <?= $company_info['business_type'] === '一般乗用旅客自動車運送事業（福祉）' ? 'selected' : '' ?>>
                                        一般乗用旅客自動車運送事業（福祉）
                                    </option>
                                    <option value="一般乗用旅客自動車運送事業" 
                                            <?= $company_info['business_type'] === '一般乗用旅客自動車運送事業' ? 'selected' : '' ?>>
                                        一般乗用旅客自動車運送事業
                                    </option>
                                    <option value="特定旅客自動車運送事業" 
                                            <?= $company_info['business_type'] === '特定旅客自動車運送事業' ? 'selected' : '' ?>>
                                        特定旅客自動車運送事業
                                    </option>
                                </select>
                            </div>
                        </div>
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
