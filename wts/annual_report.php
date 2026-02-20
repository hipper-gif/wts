<?php
session_start();

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// データベース接続
require_once 'config/database.php';
require_once 'includes/unified-header.php';

try {
    $pdo = getDBConnection();
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT name, permission_level FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// 権限チェック
if ($user['permission_level'] !== 'Admin') {
    die('アクセス権限がありません。管理者のみ利用可能です。');
}

// 必要テーブルの確認・作成
try {
    // 事業者情報テーブル
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS company_info (
            id INT PRIMARY KEY AUTO_INCREMENT,
            company_name VARCHAR(200) NOT NULL DEFAULT '近畿介護タクシー株式会社',
            address VARCHAR(300) DEFAULT '大阪市中央区天満橋1-7-10',
            phone VARCHAR(20) DEFAULT '06-6949-6446',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // 不足カラムを SHOW COLUMNS で確認して追加
    $existing_columns_stmt = $pdo->query("SHOW COLUMNS FROM company_info");
    $existing_columns = array_column($existing_columns_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    $columns_to_add = [
        'company_kana'    => "ALTER TABLE company_info ADD COLUMN company_kana VARCHAR(200) DEFAULT '' AFTER company_name",
        'representative_name' => "ALTER TABLE company_info ADD COLUMN representative_name VARCHAR(100) DEFAULT '' AFTER company_kana",
        'postal_code'     => "ALTER TABLE company_info ADD COLUMN postal_code VARCHAR(10) DEFAULT '' AFTER representative_name",
        'license_number'  => "ALTER TABLE company_info ADD COLUMN license_number VARCHAR(50) DEFAULT '' AFTER phone",
        'business_type'   => "ALTER TABLE company_info ADD COLUMN business_type VARCHAR(100) DEFAULT '一般乗用旅客自動車運送事業（福祉）' AFTER license_number",
    ];

    foreach ($columns_to_add as $col => $sql) {
        if (!in_array($col, $existing_columns)) {
            $pdo->exec($sql);
        }
    }

    // デフォルトデータの挿入
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company_info");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO company_info (company_name, address, phone)
            VALUES ('近畿介護タクシー株式会社', '大阪市中央区天満橋1-7-10', '06-6949-6446')
        ");
    }

    // 年度マスタの確認・作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fiscal_years (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fiscal_year INT NOT NULL UNIQUE,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_active BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fiscal_year (fiscal_year)
        )
    ");

    // 陸運局提出記録テーブル
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS annual_reports (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fiscal_year INT NOT NULL,
            report_type VARCHAR(50) NOT NULL,
            submission_date DATE,
            submitted_by INT,
            status ENUM('未作成', '作成中', '確認中', '提出済み') DEFAULT '未作成',
            memo TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_year_type (fiscal_year, report_type),
            FOREIGN KEY (submitted_by) REFERENCES users(id)
        )
    ");

    // 事故管理テーブル
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accidents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            accident_date DATE NOT NULL,
            vehicle_id INT NOT NULL,
            driver_id INT NOT NULL,
            accident_type ENUM('交通事故', '重大事故', 'その他') NOT NULL,
            location VARCHAR(255),
            description TEXT,
            deaths INT DEFAULT 0,
            injuries INT DEFAULT 0,
            property_damage BOOLEAN DEFAULT FALSE,
            police_report BOOLEAN DEFAULT FALSE,
            insurance_claim BOOLEAN DEFAULT FALSE,
            prevention_measures TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_accident_date (accident_date)
        )
    ");

} catch (PDOException $e) {
    $error = "テーブル作成エラー: " . $e->getMessage();
}

// 不足カラムの追加（テーブル作成とは別にエラーハンドリング）
function ensureColumnExists($pdo, $table, $column, $definition) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

try {
    // company_info テーブルの不足カラム
    ensureColumnExists($pdo, 'company_info', 'company_kana', "VARCHAR(200) DEFAULT ''");
    ensureColumnExists($pdo, 'company_info', 'representative_name', "VARCHAR(100) DEFAULT ''");
    ensureColumnExists($pdo, 'company_info', 'postal_code', "VARCHAR(10) DEFAULT ''");
    ensureColumnExists($pdo, 'company_info', 'license_number', "VARCHAR(50) DEFAULT ''");
    ensureColumnExists($pdo, 'company_info', 'business_type', "VARCHAR(100) DEFAULT ''");

    // vehicles テーブルに vehicle_type がなければ追加
    ensureColumnExists($pdo, 'vehicles', 'vehicle_type', "VARCHAR(20) DEFAULT 'welfare'");
} catch (PDOException $e) {
    error_log("カラム追加エラー: " . $e->getMessage());
}

// 現在の年度取得
$current_year = date('Y');
$selected_year = $_GET['year'] ?? $current_year;

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_company_info':
                    $stmt = $pdo->prepare("
                        UPDATE company_info SET 
                        company_name = ?, representative_name = ?, 
                        postal_code = ?, address = ?, phone = ?, 
                        license_number = ?, business_type = ?
                        WHERE id = 1
                    ");
                    $stmt->execute([
                        $_POST['company_name'], $_POST['representative_name'],
                        $_POST['postal_code'], $_POST['address'], $_POST['phone'],
                        $_POST['license_number'], $_POST['business_type']
                    ]);
                    $message = "事業者情報を更新しました。";
                    break;
                    
                case 'create_report':
                    $fiscal_year = $_POST['fiscal_year'];
                    $report_type = $_POST['report_type'];
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO annual_reports (fiscal_year, report_type, status, submitted_by) 
                        VALUES (?, ?, '作成中', ?)
                        ON DUPLICATE KEY UPDATE status = '作成中', updated_at = NOW()
                    ");
                    $stmt->execute([$fiscal_year, $report_type, $_SESSION['user_id']]);
                    
                    $message = "{$fiscal_year}年度の{$report_type}を作成開始しました。";
                    break;
                    
                case 'update_status':
                    $report_id = $_POST['report_id'];
                    $status = $_POST['status'];
                    $memo = $_POST['memo'] ?? '';
                    
                    $stmt = $pdo->prepare("
                        UPDATE annual_reports 
                        SET status = ?, memo = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$status, $memo, $report_id]);
                    
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
        $error = "エラーが発生しました: " . $e->getMessage();
        error_log("Annual report error: " . $e->getMessage());
    }
}

// データ取得関数群
function getCompanyInfo($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM company_info WHERE id = 1 LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return [
            'company_name' => '近畿介護タクシー株式会社',
            'representative_name' => '代表取締役　田中　太郎',
            'address' => '大阪市中央区天満橋1-7-10',
            'phone' => '06-6949-6446',
            'postal_code' => '',
            'license_number' => '',
            'business_type' => '一般乗用旅客自動車運送事業（福祉）'
        ];
    }
    
    return $result;
}

function getBusinessOverview($pdo, $year) {
    // 事業概況データ（3月31日現在）
    $end_date = $year . '-03-31';
    
    // 車両数（アクティブな車両のみ、「その他」を除外）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE is_active = 1 AND (vehicle_type IS NULL OR vehicle_type != 'other')");
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
}

function getTransportResults($pdo, $year) {
    // 輸送実績データ（年度集計：4月1日〜3月31日）
    $start_date = ($year - 1) . '-04-01';
    $end_date = $year . '-03-31';
    
    error_log("Transport Results - Date Range: {$start_date} to {$end_date}");
    
    // 走行距離（arrival_recordsから、「その他」車両を除外）
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ar.total_distance), 0) as total_distance
        FROM arrival_records ar
        JOIN vehicles v ON ar.vehicle_id = v.id
        WHERE ar.arrival_date BETWEEN ? AND ?
        AND (v.vehicle_type IS NULL OR v.vehicle_type != 'other')
    ");
    $stmt->execute([$start_date, $end_date]);
    $total_distance = $stmt->fetchColumn();

    // 運送実績（ride_recordsから、「その他」車両を除外）
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as ride_count,
            SUM(rr.passenger_count) as total_passengers,
            SUM(rr.fare + COALESCE(rr.charge, 0)) as total_revenue
        FROM ride_records rr
        JOIN vehicles v ON rr.vehicle_id = v.id
        WHERE rr.ride_date BETWEEN ? AND ?
        AND rr.is_sample_data = 0
        AND (v.vehicle_type IS NULL OR v.vehicle_type != 'other')
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
        AND rr.is_sample_data = 0
        AND (v.vehicle_type IS NULL OR v.vehicle_type != 'other')
        GROUP BY rr.transportation_type
    ");
    $stmt->execute([$start_date, $end_date]);
    $transport_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_distance' => $total_distance,
        'ride_count' => $transport_data['ride_count'] ?? 0,
        'total_passengers' => $transport_data['total_passengers'] ?? 0,
        'total_revenue' => $transport_data['total_revenue'] ?? 0,
        'transport_categories' => $transport_categories
    ];
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

// PDF出力関数（HTML形式で出力し、ブラウザの印刷機能でPDF保存）
function generateForm4PDF($company, $business, $transport, $accident, $year) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, max-age=0, must-revalidate');

    $fiscal_start = ($year - 1) . '年4月1日';
    $fiscal_end = $year . '年3月31日';
    $report_date = date('Y年m月d日');

    $categories_html = '';
    if (!empty($transport['transport_categories'])) {
        $categories_html .= '<h3>輸送種別詳細</h3>';
        $categories_html .= '<table><thead><tr><th>輸送種別</th><th>運送回数</th><th>輸送人員</th><th>営業収入</th></tr></thead><tbody>';
        foreach ($transport['transport_categories'] as $cat) {
            $categories_html .= '<tr>';
            $categories_html .= '<td>' . htmlspecialchars($cat['transportation_type']) . '</td>';
            $categories_html .= '<td class="num">' . number_format($cat['count']) . '回</td>';
            $categories_html .= '<td class="num">' . number_format($cat['passengers']) . '人</td>';
            $categories_html .= '<td class="num">&yen;' . number_format($cat['revenue']) . '</td>';
            $categories_html .= '</tr>';
        }
        $categories_html .= '</tbody></table>';
    }

    echo '<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>第4号様式（輸送実績報告書）' . htmlspecialchars($year) . '年度</title>
<style>
@page { size: A4; margin: 15mm; }
body { font-family: "Yu Gothic", "YuGothic", "Hiragino Sans", "Meiryo", sans-serif; margin: 0; padding: 20px; font-size: 12pt; color: #000; }
.print-controls { text-align: center; margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 8px; }
.print-controls button { padding: 10px 30px; font-size: 14pt; cursor: pointer; margin: 0 5px; border-radius: 4px; border: 1px solid #ccc; }
.print-controls .btn-print { background: #2196F3; color: #fff; border-color: #1976D2; }
.print-controls .btn-close-page { background: #757575; color: #fff; border-color: #616161; }
@media print { .print-controls { display: none !important; } }
.report { max-width: 210mm; margin: 0 auto; }
.report-title { text-align: center; font-size: 16pt; font-weight: bold; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 5px; }
.report-subtitle { text-align: center; font-size: 10pt; color: #555; margin-bottom: 20px; }
.section { margin-bottom: 15px; }
h3 { font-size: 12pt; border-left: 4px solid #333; padding-left: 8px; margin: 15px 0 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
th, td { border: 1px solid #333; padding: 6px 10px; font-size: 11pt; }
th { background: #f5f5f5; text-align: center; font-weight: bold; }
td.num { text-align: right; }
td.label { background: #fafafa; width: 50%; }
.company-info { margin-bottom: 15px; }
.company-info td.label { width: 120px; }
.notes { font-size: 9pt; color: #444; margin-top: 20px; padding: 10px; border: 1px solid #ccc; }
.notes h3 { font-size: 10pt; margin-top: 0; }
.notes ol { margin: 5px 0; padding-left: 20px; }
.notes li { margin-bottom: 3px; }
.footer-line { text-align: center; margin-top: 25px; padding-top: 10px; border-top: 1px solid #999; font-size: 9pt; color: #666; }
</style>
</head>
<body>
<div class="print-controls">
    <button class="btn-print" onclick="window.print()"><b>PDF保存 / 印刷</b></button>
    <button class="btn-close-page" onclick="window.close()">閉じる</button>
</div>
<div class="report">
    <div class="report-title">一般乗用旅客自動車運送事業実績報告書（第４号様式）</div>
    <div class="report-subtitle">報告年度：' . htmlspecialchars($year) . '年度（' . $fiscal_start . ' ～ ' . $fiscal_end . '）　作成日：' . $report_date . '</div>

    <h3>事業者情報</h3>
    <table class="company-info">
        <tr><td class="label">事業者名</td><td>' . htmlspecialchars($company['company_name']) . '</td></tr>
        <tr><td class="label">代表者名</td><td>' . htmlspecialchars($company['representative_name']) . '</td></tr>
        <tr><td class="label">住所</td><td>' . htmlspecialchars(($company['postal_code'] ? '〒' . $company['postal_code'] . ' ' : '') . $company['address']) . '</td></tr>
        <tr><td class="label">電話番号</td><td>' . htmlspecialchars($company['phone']) . '</td></tr>
        <tr><td class="label">事業種別</td><td>' . htmlspecialchars($company['business_type']) . '</td></tr>
        <tr><td class="label">許可番号</td><td>' . htmlspecialchars($company['license_number'] ?: '―') . '</td></tr>
    </table>

    <h3>事業概況（' . htmlspecialchars($year) . '年3月31日現在）</h3>
    <table>
        <tr><td class="label">事業用自動車数（台）</td><td class="num">' . number_format($business['vehicle_count']) . '</td></tr>
        <tr><td class="label">従業員数（人）</td><td class="num">' . number_format($business['employee_count']) . '</td></tr>
        <tr><td class="label">　うち運転者数（人）</td><td class="num">' . number_format($business['driver_count']) . '</td></tr>
    </table>

    <h3>輸送実績（' . $fiscal_start . ' ～ ' . $fiscal_end . '）</h3>
    <table>
        <tr><td class="label">走行キロ（キロメートル）</td><td class="num">' . number_format($transport['total_distance']) . '</td></tr>
        <tr><td class="label">運送回数（回）</td><td class="num">' . number_format($transport['ride_count']) . '</td></tr>
        <tr><td class="label">輸送人員（人）</td><td class="num">' . number_format($transport['total_passengers']) . '</td></tr>
        <tr><td class="label">営業収入（千円）</td><td class="num">' . number_format(floor($transport['total_revenue'] / 1000)) . '</td></tr>
    </table>

    <h3>事故件数（' . $fiscal_start . ' ～ ' . $fiscal_end . '）</h3>
    <table>
        <tr><td class="label">交通事故件数（件）</td><td class="num">' . number_format($accident['traffic_accidents']) . '</td></tr>
        <tr><td class="label">重大事故件数（件）</td><td class="num">' . number_format($accident['serious_accidents']) . '</td></tr>
        <tr><td class="label">死者数（人）</td><td class="num">' . number_format($accident['total_deaths']) . '</td></tr>
        <tr><td class="label">負傷者数（人）</td><td class="num">' . number_format($accident['total_injuries']) . '</td></tr>
    </table>

    ' . $categories_html . '

    <div class="notes">
        <h3>備考</h3>
        <ol>
            <li>営業実績については、当事業年度の月毎の記録に基づいて記載</li>
            <li>運転者数は運転業務に従事した者の数を記載</li>
            <li>住所記載について、運輸支局等の認可に基づく</li>
            <li>交通事故については、運輸支局等への報告に基づく</li>
            <li>重大事故については、自動車事故報告規則の事故を記載</li>
        </ol>
    </div>

    <div class="footer-line">以上 ― ' . htmlspecialchars($company['company_name']) . '</div>
</div>
</body>
</html>';
}

// エクセル出力関数
function generateForm4Excel($company, $business, $transport, $accident, $year) {
    $filename = 'transport_report_form4_' . $year . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    $fiscal_start = ($year - 1) . '年4月1日';
    $fiscal_end = $year . '年3月31日';
    $report_date = date('Y年m月d日');

    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8">';
    echo '<style>
        td, th { font-family: "Yu Gothic", "Meiryo", sans-serif; font-size: 11pt; }
        th { background: #d9e1f2; font-weight: bold; text-align: center; }
        td.num { text-align: right; mso-number-format:"\#\,\#\#0"; }
        td.label { background: #f2f2f2; }
        .title { font-size: 14pt; font-weight: bold; text-align: center; }
        .subtitle { font-size: 10pt; text-align: center; color: #555; }
        .section-header { font-size: 12pt; font-weight: bold; background: #e2efda; }
    </style></head><body>';

    echo '<table border="1" cellpadding="4" cellspacing="0">';

    // タイトル
    echo '<tr><td colspan="4" class="title">一般乗用旅客自動車運送事業実績報告書（第４号様式）</td></tr>';
    echo '<tr><td colspan="4" class="subtitle">報告年度：' . htmlspecialchars($year) . '年度（' . $fiscal_start . ' ～ ' . $fiscal_end . '）　作成日：' . $report_date . '</td></tr>';
    echo '<tr><td colspan="4"></td></tr>';

    // 事業者情報
    echo '<tr><td colspan="4" class="section-header">事業者情報</td></tr>';
    echo '<tr><td class="label">事業者名</td><td colspan="3">' . htmlspecialchars($company['company_name']) . '</td></tr>';
    echo '<tr><td class="label">代表者名</td><td colspan="3">' . htmlspecialchars($company['representative_name']) . '</td></tr>';
    echo '<tr><td class="label">住所</td><td colspan="3">' . htmlspecialchars(($company['postal_code'] ? '〒' . $company['postal_code'] . ' ' : '') . $company['address']) . '</td></tr>';
    echo '<tr><td class="label">電話番号</td><td colspan="3">' . htmlspecialchars($company['phone']) . '</td></tr>';
    echo '<tr><td class="label">事業種別</td><td colspan="3">' . htmlspecialchars($company['business_type']) . '</td></tr>';
    echo '<tr><td class="label">許可番号</td><td colspan="3">' . htmlspecialchars($company['license_number'] ?: '―') . '</td></tr>';
    echo '<tr><td colspan="4"></td></tr>';

    // 事業概況
    echo '<tr><td colspan="4" class="section-header">事業概況（' . htmlspecialchars($year) . '年3月31日現在）</td></tr>';
    echo '<tr><td class="label" colspan="2">事業用自動車数（台）</td><td class="num" colspan="2">' . $business['vehicle_count'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">従業員数（人）</td><td class="num" colspan="2">' . $business['employee_count'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">　うち運転者数（人）</td><td class="num" colspan="2">' . $business['driver_count'] . '</td></tr>';
    echo '<tr><td colspan="4"></td></tr>';

    // 輸送実績
    echo '<tr><td colspan="4" class="section-header">輸送実績（' . $fiscal_start . ' ～ ' . $fiscal_end . '）</td></tr>';
    echo '<tr><td class="label" colspan="2">走行キロ（キロメートル）</td><td class="num" colspan="2">' . $transport['total_distance'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">運送回数（回）</td><td class="num" colspan="2">' . $transport['ride_count'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">輸送人員（人）</td><td class="num" colspan="2">' . $transport['total_passengers'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">営業収入（千円）</td><td class="num" colspan="2">' . floor($transport['total_revenue'] / 1000) . '</td></tr>';
    echo '<tr><td colspan="4"></td></tr>';

    // 事故件数
    echo '<tr><td colspan="4" class="section-header">事故件数（' . $fiscal_start . ' ～ ' . $fiscal_end . '）</td></tr>';
    echo '<tr><td class="label" colspan="2">交通事故件数（件）</td><td class="num" colspan="2">' . $accident['traffic_accidents'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">重大事故件数（件）</td><td class="num" colspan="2">' . $accident['serious_accidents'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">死者数（人）</td><td class="num" colspan="2">' . $accident['total_deaths'] . '</td></tr>';
    echo '<tr><td class="label" colspan="2">負傷者数（人）</td><td class="num" colspan="2">' . $accident['total_injuries'] . '</td></tr>';

    // 輸送種別詳細
    if (!empty($transport['transport_categories'])) {
        echo '<tr><td colspan="4"></td></tr>';
        echo '<tr><td colspan="4" class="section-header">輸送種別詳細</td></tr>';
        echo '<tr><th>輸送種別</th><th>運送回数</th><th>輸送人員</th><th>営業収入</th></tr>';
        foreach ($transport['transport_categories'] as $cat) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($cat['transportation_type']) . '</td>';
            echo '<td class="num">' . $cat['count'] . '</td>';
            echo '<td class="num">' . $cat['passengers'] . '</td>';
            echo '<td class="num">' . $cat['revenue'] . '</td>';
            echo '</tr>';
        }
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
    $user['name'],
    $user['permission_level'],
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
                        <div class="col-md-6">
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
                        <div class="col-md-6">
                            <table class="table table-borderless table-sm">
                                <tr>
                                    <td class="fw-bold" style="width: 120px;">電話番号:</td>
                                    <td><?= htmlspecialchars($company_info['phone']) ?></td>
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
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= $business_overview['vehicle_count'] ?></div>
                    <div class="h5">事業用自動車数</div>
                    <small><?= $selected_year ?>年3月31日現在</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= $business_overview['employee_count'] ?></div>
                    <div class="h5">従業員数</div>
                    <small>運転者<?= $business_overview['driver_count'] ?>名含む</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= number_format($transport_results['ride_count']) ?></div>
                    <div class="h5">運送回数</div>
                    <small>年度期間合計</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
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
        <div class="col-md-4">
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
        
        <div class="col-md-4">
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
        
        <div class="col-md-4">
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
            <div class="card border-success">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-file-pdf me-2"></i>第4号様式（輸送実績報告書）</h5>
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#createReportModal">
                        <i class="fas fa-plus me-1"></i>新規作成
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <h6>陸運局提出用書類の作成・管理</h6>
                            <p class="text-muted mb-0">
                                道路運送法に基づく年次報告書（第4号様式）を作成・管理します。<br>
                                事業者情報・事業概況・輸送実績・事故件数を記載した正式な報告書をPDF形式で出力できます。
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <form method="POST" class="d-inline" target="_blank">
                                <input type="hidden" name="action" value="export_form4">
                                <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-file-pdf me-2"></i>PDF出力
                                </button>
                            </form>
                            <form method="POST" class="d-inline ms-2">
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
                                                        <input type="hidden" name="action" value="export_form4">
                                                        <input type="hidden" name="fiscal_year" value="<?= $report['fiscal_year'] ?>">
                                                        <button type="submit" class="btn btn-outline-success btn-sm" title="PDF出力">
                                                            <i class="fas fa-file-pdf"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline">
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
                        <div class="col-md-3">
                            <a href="accident_management.php" class="btn btn-warning w-100">
                                <i class="fas fa-exclamation-triangle me-1"></i>事故管理
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="vehicle_management.php" class="btn btn-info w-100">
                                <i class="fas fa-car me-1"></i>車両管理
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="ride_records.php" class="btn btn-primary w-100">
                                <i class="fas fa-route me-1"></i>乗車記録
                            </a>
                        </div>
                        <div class="col-md-3">
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
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_company_info">
                    
                    <div class="row">
                        <div class="col-md-6">
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
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="address" class="form-label">住所 <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="address" rows="3" required><?= htmlspecialchars($company_info['address']) ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">電話番号</label>
                                <input type="text" class="form-control" name="phone" 
                                       value="<?= htmlspecialchars($company_info['phone']) ?>"
                                       placeholder="06-6949-6446">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="license_number" class="form-label">許可番号</label>
                                <input type="text" class="form-control" name="license_number" 
                                       value="<?= htmlspecialchars($company_info['license_number']) ?>"
                                       placeholder="近運輸第○○号">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
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
                    <button type="submit" class="btn btn-primary">
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
                    <button type="submit" class="btn btn-primary">
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
                    <button type="submit" class="btn btn-primary">
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
