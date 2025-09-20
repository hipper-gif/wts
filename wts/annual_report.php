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
    // 事業者情報テーブル拡張
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS company_info (
            id INT PRIMARY KEY AUTO_INCREMENT,
            company_name VARCHAR(200) NOT NULL DEFAULT '近畿介護タクシー株式会社',
            company_kana VARCHAR(200) DEFAULT 'キンキカイゴタクシーカブシキガイシャ',
            representative_name VARCHAR(100) DEFAULT '',
            postal_code VARCHAR(10) DEFAULT '',
            address VARCHAR(300) DEFAULT '大阪市中央区天満橋1-7-10',
            phone VARCHAR(20) DEFAULT '06-6949-6446',
            license_number VARCHAR(50) DEFAULT '',
            business_type VARCHAR(100) DEFAULT '一般乗用旅客自動車運送事業（福祉）',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // デフォルトデータの挿入
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company_info");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO company_info (company_name, address, phone, representative_name) 
            VALUES ('近畿介護タクシー株式会社', '大阪市中央区天満橋1-7-10', '06-6949-6446', '代表取締役　田中　太郎')
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
            INDEX idx_accident_date (accident_date),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
            FOREIGN KEY (driver_id) REFERENCES users(id)
        )
    ");
    
} catch (PDOException $e) {
    $error = "テーブル作成エラー: " . $e->getMessage();
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
                    // 第4号様式出力処理
                    $export_year = $_POST['fiscal_year'];
                    
                    // データ取得
                    $company_info = getCompanyInfo($pdo);
                    $business_overview = getBusinessOverview($pdo, $export_year);
                    $transport_results = getTransportResults($pdo, $export_year);
                    $accident_data = getAccidentData($pdo, $export_year);
                    
                    // PDF出力
                    generateForm4PDF($company_info, $business_overview, $transport_results, $accident_data, $export_year);
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
    
    // 車両数（アクティブな車両のみ）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE is_active = 1");
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
    
    // 走行距離（arrival_recordsから）
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_distance), 0) as total_distance
        FROM arrival_records 
        WHERE arrival_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    $total_distance = $stmt->fetchColumn();
    
    // 運送実績（ride_recordsから）
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as ride_count,
            SUM(passenger_count) as total_passengers,
            SUM(fare + COALESCE(charge, 0)) as total_revenue
        FROM ride_records 
        WHERE ride_date BETWEEN ? AND ? 
        AND is_sample_data = 0
    ");
    $stmt->execute([$start_date, $end_date]);
    $transport_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 輸送種別別の詳細データ
    $stmt = $pdo->prepare("
        SELECT 
            transportation_type,
            COUNT(*) as count,
            SUM(passenger_count) as passengers,
            SUM(fare + COALESCE(charge, 0)) as revenue
        FROM ride_records 
        WHERE ride_date BETWEEN ? AND ? 
        AND is_sample_data = 0
        GROUP BY transportation_type
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

// PDF出力関数
function generateForm4PDF($company, $business, $transport, $accident, $year) {
    // 実際の第4号様式PDF生成
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="transport_report_form4_' . $year . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // PDF内容（実際の運用ではTCPDFやmPDFを使用）
    $content = generateTextBasedPDF($company, $business, $transport, $accident, $year);
    
    // 簡易テキスト出力（実装時はPDFライブラリを使用）
    echo $content;
}

function generateTextBasedPDF($company, $business, $transport, $accident, $year) {
    $fiscal_start = ($year - 1) . '年4月1日';
    $fiscal_end = $year . '年3月31日';
    $report_date = date('Y年m月d日');
    
    $content = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $content .= "                一般乗用旅客自動車運送事業実績報告書（第４号様式）\n";
    $content .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $content .= "報告年度：{$year}年度（{$fiscal_start}～{$fiscal_end}）\n";
    $content .= "作成日：{$report_date}\n\n";
    
    $content .= "【事業者情報】\n";
    $content .= "住　　所：{$company['address']}\n";
    $content .= "事業者名：{$company['company_name']}\n";
    $content .= "代表者名：{$company['representative_name']}\n";
    $content .= "電話番号：{$company['phone']}\n\n";
    
    $content .= "【事業概況】（{$year}年3月31日現在）\n";
    $content .= "┌─────────────────┬──────────┐\n";
    $content .= "│ 事業用自動車数（台） │ " . str_pad($business['vehicle_count'], 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "├─────────────────┼──────────┤\n";
    $content .= "│ 従業員数（人）       │ " . str_pad($business['employee_count'], 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "└─────────────────┴──────────┘\n\n";
    
    $content .= "【輸送実績】（{$fiscal_start}～{$fiscal_end}）\n";
    $content .= "┌─────────────────┬──────────┐\n";
    $content .= "│ 走行キロ（キロメートル）│ " . str_pad(number_format($transport['total_distance']), 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "├─────────────────┼──────────┤\n";
    $content .= "│ 運送回数（回）       │ " . str_pad(number_format($transport['ride_count']), 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "├─────────────────┼──────────┤\n";
    $content .= "│ 輸送人員（人）       │ " . str_pad(number_format($transport['total_passengers']), 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "├─────────────────┼──────────┤\n";
    $content .= "│ 営業収入（千円）     │ " . str_pad(number_format(floor($transport['total_revenue'] / 1000)), 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "└─────────────────┴──────────┘\n\n";
    
    $content .= "【事故件数】（{$fiscal_start}～{$fiscal_end}）\n";
    $content .= "┌─────────────────┬──────────┐\n";
    $content .= "│ 交通事故件数（件）   │ " . str_pad($accident['traffic_accidents'], 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "├─────────────────┼──────────┤\n";
    $content .= "│ 重大事故件数（件）   │ " . str_pad($accident['serious_accidents'], 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "├─────────────────┼──────────┤\n";
    $content .= "│ 死者数（人）         │ " . str_pad($accident['total_deaths'], 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "├─────────────────┼──────────┤\n";
    $content .= "│ 負傷者数（人）       │ " . str_pad($accident['total_injuries'], 10, ' ', STR_PAD_LEFT) . " │\n";
    $content .= "└─────────────────┴──────────┘\n\n";
    
    if (!empty($transport['transport_categories'])) {
        $content .= "【輸送種別詳細】\n";
        foreach ($transport['transport_categories'] as $category) {
            $content .= "- {$category['transportation_type']}: {$category['count']}回 / {$category['passengers']}人\n";
        }
        $content .= "\n";
    }
    
    $content .= "【備考】\n";
    $content .= "1. 営業実績については、当事業年度の月毎の記録に基づいて記載\n";
    $content .= "2. 運転者数は運転業務に従事した者の数を記載\n";
    $content .= "3. 住所記載について、運輸支局等の認可に基づく\n";
    $content .= "4. 交通事故については、運輸支局等への報告に基づく\n";
    $content .= "5. 重大事故については、自動車事故報告規則の事故を記載\n\n";
    
    $content .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $content .= "以上\n";
    
    return $content;
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
                    <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#companyInfoModal">
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
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#createReportModal">
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
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="export_form4">
                                <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-download me-2"></i>第4号様式PDF出力
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
                                                    
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="export_form4">
                                                        <input type="hidden" name="fiscal_year" value="<?= $report['fiscal_year'] ?>">
                                                        <button type="submit" class="btn btn-outline-success btn-sm" title="PDF出力">
                                                            <i class="fas fa-download"></i>
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
<div class="modal fade" id="companyInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-building me-2"></i>事業者情報編集</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
<div class="modal fade" id="createReportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>新規レポート作成</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>レポート状態更新</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
    // 状態更新モーダル
    function updateStatus(reportId, currentStatus) {
        document.getElementById('update_report_id').value = reportId;
        document.getElementById('status').value = currentStatus;
        
        new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
    }
</script>

<?php
// 統一フッターの出力
echo $page_data['system_footer'] ?? '';
?>
