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

// ユーザー情報取得（roleをpermission_levelに修正）
$stmt = $pdo->prepare("SELECT name, permission_level FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// 権限チェック（permission_levelを使用）
if ($user['permission_level'] !== 'Admin') {
    die('アクセス権限がありません。管理者のみ利用可能です。');
}

// 年度マスタの確認・作成
try {
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
                    
                case 'submit_report':
                    $report_id = $_POST['report_id'];
                    $submission_date = $_POST['submission_date'];
                    
                    $stmt = $pdo->prepare("
                        UPDATE annual_reports 
                        SET status = '提出済み', submission_date = ?, submitted_by = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$submission_date, $_SESSION['user_id'], $report_id]);
                    
                    $message = "陸運局への提出を記録しました。";
                    break;
                    
                case 'export_form4':
                    // 第4号様式出力処理
                    header('Location: ?action=export&type=form4&year=' . $_POST['fiscal_year']);
                    exit();
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// 年度データ取得関数群
function getBusinessOverview($pdo, $year) {
    // 事業概況データ（3月31日現在）
    $end_date = $year . '-03-31';
    
    // 車両数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE created_at <= ? AND is_active = 1");
    $stmt->execute([$end_date]);
    $vehicle_count = $stmt->fetchColumn();
    
    // 従業員数（permission_levelとis_driverフラグを使用）
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM users 
        WHERE is_active = 1 
        AND (is_driver = 1 OR permission_level = 'Admin') 
        AND created_at <= ?
    ");
    $stmt->execute([$end_date]);
    $employee_count = $stmt->fetchColumn();
    
    return [
        'vehicle_count' => $vehicle_count,
        'employee_count' => $employee_count,
        'as_of_date' => $end_date
    ];
}

function getTransportResults($pdo, $year) {
    // 輸送実績データ（年度集計）
    $start_date = ($year - 1) . '-04-01';
    $end_date = $year . '-03-31';
    
    // 走行距離
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_distance), 0) as total_distance
        FROM arrival_records 
        WHERE arrival_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    $total_distance = $stmt->fetchColumn();
    
    // 運送実績（fare_amountではなくfare + chargeを使用）
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
    
    return [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_distance' => $total_distance,
        'ride_count' => $transport_data['ride_count'] ?? 0,
        'total_passengers' => $transport_data['total_passengers'] ?? 0,
        'total_revenue' => $transport_data['total_revenue'] ?? 0
    ];
}

function getAccidentData($pdo, $year) {
    // 事故データ（年度集計）
    $start_date = ($year - 1) . '-04-01';
    $end_date = $year . '-03-31';
    
    $stmt = $pdo->prepare("
        SELECT 
            accident_type,
            COUNT(*) as count,
            SUM(deaths) as total_deaths,
            SUM(injuries) as total_injuries
        FROM accidents 
        WHERE accident_date BETWEEN ? AND ?
        GROUP BY accident_type
    ");
    $stmt->execute([$start_date, $end_date]);
    $accidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [
        'traffic_accidents' => 0,
        'serious_accidents' => 0,
        'total_deaths' => 0,
        'total_injuries' => 0
    ];
    
    foreach ($accidents as $accident) {
        if ($accident['accident_type'] === '交通事故') {
            $result['traffic_accidents'] = $accident['count'];
        } elseif ($accident['accident_type'] === '重大事故') {
            $result['serious_accidents'] = $accident['count'];
        }
        $result['total_deaths'] += $accident['total_deaths'];
        $result['total_injuries'] += $accident['total_injuries'];
    }
    
    return $result;
}

function getAnnualReports($pdo, $year = null) {
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
}

// データ取得
$business_overview = getBusinessOverview($pdo, $selected_year);
$transport_results = getTransportResults($pdo, $selected_year);
$accident_data = getAccidentData($pdo, $selected_year);
$annual_reports = getAnnualReports($pdo, $selected_year);

// 出力処理
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $export_type = $_GET['type'];
    $export_year = $_GET['year'];
    
    if ($export_type === 'form4') {
        // 第4号様式PDF出力
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="transport_report_' . $export_year . '.pdf"');
        
        // 簡易PDF出力（実際の運用では TCPDF 等を使用）
        echo generateForm4PDF($business_overview, $transport_results, $accident_data, $export_year);
        exit();
    }
}

function generateForm4PDF($business, $transport, $accident, $year) {
    // 簡易テキスト形式での出力（実際はTCPDFを使用）
    $content = "輸送実績報告書（第4号様式）\n";
    $content .= "年度: {$year}年度\n";
    $content .= "作成日: " . date('Y/m/d') . "\n\n";
    
    $content .= "【事業概況】（{$year}年3月31日現在）\n";
    $content .= "事業用自動車数: {$business['vehicle_count']}台\n";
    $content .= "従業員数: {$business['employee_count']}名\n\n";
    
    $content .= "【輸送実績】（" . date('Y/m/d', strtotime($transport['start_date'])) . "〜" . date('Y/m/d', strtotime($transport['end_date'])) . "）\n";
    $content .= "走行キロ: " . number_format($transport['total_distance']) . "km\n";
    $content .= "運送回数: " . number_format($transport['ride_count']) . "回\n";
    $content .= "輸送人員: " . number_format($transport['total_passengers']) . "人\n";
    $content .= "営業収入: " . number_format($transport['total_revenue']) . "円\n\n";
    
    $content .= "【事故件数】\n";
    $content .= "交通事故: {$accident['traffic_accidents']}件\n";
    $content .= "重大事故: {$accident['serious_accidents']}件\n";
    $content .= "死者数: {$accident['total_deaths']}名\n";
    $content .= "負傷者数: {$accident['total_injuries']}名\n";
    
    return $content;
}

// ページ設定を取得
$page_config = getPageConfiguration();
$page_title = $page_config['title'];
$breadcrumbs = $page_config['breadcrumbs'];

// 統一ヘッダーの出力
outputUnifiedHeader($page_title, $breadcrumbs, $page_config);
?>

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

    <!-- 年度選択 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>年度選択</h5>
                        <form method="GET" class="d-flex">
                            <select name="year" class="form-select me-2" style="width: auto;">
                                <?php for ($year = $current_year; $year >= $current_year - 5; $year--): ?>
                                    <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                                        <?= $year ?>年度
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 年度データサマリー -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= $business_overview['vehicle_count'] + $business_overview['employee_count'] ?></div>
                    <div class="h5">事業規模</div>
                    <small>車両<?= $business_overview['vehicle_count'] ?>台・従業員<?= $business_overview['employee_count'] ?>名</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= number_format($transport_results['ride_count']) ?></div>
                    <div class="h5">年間運送回数</div>
                    <small>収入¥<?= number_format($transport_results['total_revenue']) ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-warning text-dark">
                <div class="card-body text-center">
                    <div class="display-4 mb-0"><?= $accident_data['traffic_accidents'] + $accident_data['serious_accidents'] ?></div>
                    <div class="h5">事故件数</div>
                    <small>死傷者<?= $accident_data['total_deaths'] + $accident_data['total_injuries'] ?>名</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 詳細データ -->
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
                            <td class="text-end"><?= $business_overview['vehicle_count'] ?>台</td>
                        </tr>
                        <tr>
                            <td>従業員数</td>
                            <td class="text-end"><?= $business_overview['employee_count'] ?>名</td>
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
                            <td>走行距離</td>
                            <td class="text-end"><?= number_format($transport_results['total_distance']) ?>km</td>
                        </tr>
                        <tr>
                            <td>運送回数</td>
                            <td class="text-end"><?= number_format($transport_results['ride_count']) ?>回</td>
                        </tr>
                        <tr>
                            <td>輸送人員</td>
                            <td class="text-end"><?= number_format($transport_results['total_passengers']) ?>人</td>
                        </tr>
                        <tr>
                            <td>営業収入</td>
                            <td class="text-end">¥<?= number_format($transport_results['total_revenue']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>事故データ（<?= $selected_year ?>年度）</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>交通事故</td>
                            <td class="text-end"><?= $accident_data['traffic_accidents'] ?>件</td>
                        </tr>
                        <tr>
                            <td>重大事故</td>
                            <td class="text-end"><?= $accident_data['serious_accidents'] ?>件</td>
                        </tr>
                        <tr>
                            <td>死者数</td>
                            <td class="text-end"><?= $accident_data['total_deaths'] ?>名</td>
                        </tr>
                        <tr>
                            <td>負傷者数</td>
                            <td class="text-end"><?= $accident_data['total_injuries'] ?>名</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- レポート作成・管理 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>陸運局提出レポート</h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createReportModal">
                        <i class="fas fa-plus me-1"></i>新規作成
                    </button>
                </div>
                <div class="card-body">
                    <?php if ($annual_reports): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
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
                                            <td><?= $report['fiscal_year'] ?>年度</td>
                                            <td><?= htmlspecialchars($report['report_type']) ?></td>
                                            <td>
                                                <span class="badge 
                                                    <?php
                                                    switch($report['status']) {
                                                        case '未作成': echo 'bg-secondary'; break;
                                                        case '作成中': echo 'bg-warning'; break;
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
                                                        <button type="submit" class="btn btn-outline-success btn-sm">
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
                        <div class="text-center py-4">
                            <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                            <p class="text-muted">まだレポートが作成されていません</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createReportModal">
                                <i class="fas fa-plus me-1"></i>最初のレポートを作成
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 年度操作 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="mb-3"><i class="fas fa-tools me-2"></i>レポート操作</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="export_form4">
                                <input type="hidden" name="fiscal_year" value="<?= $selected_year ?>">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-file-pdf me-1"></i>第4号様式PDF出力
                                </button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <a href="accident_management.php" class="btn btn-warning w-100">
                                <i class="fas fa-exclamation-triangle me-1"></i>事故管理
                            </a>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-info w-100" onclick="exportExcel()">
                                <i class="fas fa-file-excel me-1"></i>Excel出力
                            </button>
                        </div>
                    </div>
                </div>
            </div>
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
                            <?php for ($year = $current_year; $year >= $current_year - 5; $year--): ?>
                                <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                                    <?= $year ?>年度
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
                    
                    <div class="mb-3" id="submissionDateGroup" style="display: none;">
                        <label for="submission_date" class="form-label">提出日</label>
                        <input type="date" class="form-control" name="submission_date" id="submission_date">
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
        
        // 提出済みを選択した場合のみ提出日を表示
        toggleSubmissionDate(currentStatus);
        
        new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
    }
    
    // 状態変更時の処理
    document.getElementById('status').addEventListener('change', function() {
        toggleSubmissionDate(this.value);
    });
    
    function toggleSubmissionDate(status) {
        const submissionDateGroup = document.getElementById('submissionDateGroup');
        const submissionDateInput = document.getElementById('submission_date');
        
        if (status === '提出済み') {
            submissionDateGroup.style.display = 'block';
            submissionDateInput.required = true;
            if (!submissionDateInput.value) {
                submissionDateInput.value = new Date().toISOString().split('T')[0];
            }
        } else {
            submissionDateGroup.style.display = 'none';
            submissionDateInput.required = false;
            submissionDateInput.value = '';
        }
    }
    
    // Excel出力
    function exportExcel() {
        const year = <?= $selected_year ?>;
        window.open(`?action=export&type=excel&year=${year}`, '_blank');
    }
    
    // フォーム送信確認
    document.getElementById('updateStatusForm').addEventListener('submit', function(e) {
        const status = document.getElementById('status').value;
        if (status === '提出済み') {
            if (!confirm('レポートを「提出済み」に変更します。よろしいですか？')) {
                e.preventDefault();
            }
        }
    });
</script>

<?php
// 統一フッターの出力
require_once 'includes/footer.php';
?>
