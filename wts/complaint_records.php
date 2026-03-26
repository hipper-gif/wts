<?php
/**
 * 苦情処理記録
 * 顧客からの苦情を受付から解決まで一元管理するページ
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'ユーザー';
$user_role = $_SESSION['permission_level'] ?? $_SESSION['user_role'] ?? 'User';

// 権限チェック（Admin または Manager）
$has_permission = false;
if (isset($_SESSION['permission_level']) && $_SESSION['permission_level'] === 'Admin') {
    $has_permission = true;
} elseif (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
    $has_permission = true;
} elseif (isset($_SESSION['is_manager']) && $_SESSION['is_manager'] == 1) {
    $has_permission = true;
}

if (!$has_permission) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

// テーブル作成（存在しない場合）
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS complaint_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            complaint_date DATE NOT NULL,
            complaint_time TIME,
            complainant_name VARCHAR(100) NOT NULL COMMENT '苦情申立者名',
            complainant_phone VARCHAR(20) COMMENT '連絡先',
            complaint_type ENUM('運転マナー', '接客態度', '遅刻・時間', '車両状態', '料金', 'その他') NOT NULL,
            severity ENUM('軽度', '中程度', '重度') DEFAULT '中程度',
            related_date DATE NULL COMMENT '事象発生日',
            driver_id INT NULL,
            vehicle_id INT NULL,
            description TEXT NOT NULL COMMENT '苦情内容',
            response TEXT COMMENT '対応内容',
            response_status ENUM('未対応', '対応中', '対応完了', '保留') DEFAULT '未対応',
            response_date DATE NULL COMMENT '対応完了日',
            handled_by INT NULL COMMENT '対応者',
            prevention_measures TEXT COMMENT '再発防止策',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_date (complaint_date),
            INDEX idx_status (response_status),
            INDEX idx_driver (driver_id),
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
            FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
} catch (PDOException $e) {
    error_log("Complaint records table creation error: " . $e->getMessage());
}

// フィルター
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    $stmt = $pdo->prepare("
                        INSERT INTO complaint_records (
                            complaint_date, complaint_time, complainant_name, complainant_phone,
                            complaint_type, severity, related_date, driver_id, vehicle_id,
                            description, response, response_status, response_date,
                            handled_by, prevention_measures, notes, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['complaint_date'],
                        $_POST['complaint_time'] ?: null,
                        $_POST['complainant_name'],
                        $_POST['complainant_phone'] ?: null,
                        $_POST['complaint_type'],
                        $_POST['severity'],
                        $_POST['related_date'] ?: null,
                        $_POST['driver_id'] ?: null,
                        $_POST['vehicle_id'] ?: null,
                        $_POST['description'],
                        $_POST['response'] ?: null,
                        $_POST['response_status'],
                        $_POST['response_date'] ?: null,
                        $_POST['handled_by'] ?: null,
                        $_POST['prevention_measures'] ?: null,
                        $_POST['notes'] ?: null,
                        $user_id
                    ]);
                    $message = "苦情記録を登録しました。";
                    break;

                case 'update':
                    $stmt = $pdo->prepare("
                        UPDATE complaint_records SET
                            complaint_date = ?, complaint_time = ?, complainant_name = ?, complainant_phone = ?,
                            complaint_type = ?, severity = ?, related_date = ?, driver_id = ?, vehicle_id = ?,
                            description = ?, response = ?, response_status = ?, response_date = ?,
                            handled_by = ?, prevention_measures = ?, notes = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['complaint_date'],
                        $_POST['complaint_time'] ?: null,
                        $_POST['complainant_name'],
                        $_POST['complainant_phone'] ?: null,
                        $_POST['complaint_type'],
                        $_POST['severity'],
                        $_POST['related_date'] ?: null,
                        $_POST['driver_id'] ?: null,
                        $_POST['vehicle_id'] ?: null,
                        $_POST['description'],
                        $_POST['response'] ?: null,
                        $_POST['response_status'],
                        $_POST['response_date'] ?: null,
                        $_POST['handled_by'] ?: null,
                        $_POST['prevention_measures'] ?: null,
                        $_POST['notes'] ?: null,
                        $_POST['complaint_id']
                    ]);
                    $message = "苦情記録を更新しました。";
                    break;

                case 'delete':
                    $stmt = $pdo->prepare("DELETE FROM complaint_records WHERE id = ?");
                    $stmt->execute([$_POST['complaint_id']]);
                    $message = "苦情記録を削除しました。";
                    break;
            }
        }
    } catch (Exception $e) {
        error_log("Complaint management error: " . $e->getMessage());
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// マスターデータ取得
$drivers = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // フォールバック
    try {
        $stmt = $pdo->query("SELECT id, name FROM users ORDER BY name");
        $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

$vehicles = [];
try {
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

$staff = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name");
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 統計データ取得
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');

$stats = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'resolved' => 0];
try {
    // 今月の苦情件数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM complaint_records WHERE complaint_date BETWEEN ? AND ?");
    $stmt->execute([$current_month_start, $current_month_end]);
    $stats['total'] = (int)$stmt->fetchColumn();

    // 未対応
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM complaint_records WHERE response_status = '未対応'");
    $stmt->execute();
    $stats['pending'] = (int)$stmt->fetchColumn();

    // 対応中
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM complaint_records WHERE response_status = '対応中'");
    $stmt->execute();
    $stats['in_progress'] = (int)$stmt->fetchColumn();

    // 今月対応完了
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM complaint_records WHERE response_status = '対応完了' AND response_date BETWEEN ? AND ?");
    $stmt->execute([$current_month_start, $current_month_end]);
    $stats['resolved'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // テーブルがない場合等
}

// 苦情一覧取得
$complaints = [];
try {
    $sql = "
        SELECT c.*,
               d.name as driver_name,
               v.vehicle_number,
               h.name as handler_name,
               cr.name as creator_name
        FROM complaint_records c
        LEFT JOIN users d ON c.driver_id = d.id
        LEFT JOIN vehicles v ON c.vehicle_id = v.id
        LEFT JOIN users h ON c.handled_by = h.id
        LEFT JOIN users cr ON c.created_by = cr.id
        WHERE 1=1
    ";
    $params = [];

    if ($filter_month) {
        $sql .= " AND DATE_FORMAT(c.complaint_date, '%Y-%m') = ?";
        $params[] = $filter_month;
    }
    if ($filter_status) {
        $sql .= " AND c.response_status = ?";
        $params[] = $filter_status;
    }
    if ($filter_type) {
        $sql .= " AND c.complaint_type = ?";
        $params[] = $filter_type;
    }

    $sql .= " ORDER BY c.complaint_date DESC, c.complaint_time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Complaint fetch error: " . $e->getMessage());
}

// ページ設定（統一ヘッダー）
$page_config = getPageConfiguration('complaint_records');
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'complaint_records',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    [
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => 'マスターメニュー', 'url' => 'master_menu.php'],
            ['text' => $page_config['title'], 'url' => 'complaint_records.php']
        ]
    ]
);
?>
<?= $page_data['html_head'] ?>
<style>
    .stats-card {
        border-radius: 12px;
        padding: 1.25rem;
        text-align: center;
        color: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: transform 0.2s;
    }
    .stats-card:hover {
        transform: translateY(-2px);
    }
    .stats-card .stats-number {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .stats-card .stats-label {
        font-size: 0.85rem;
        opacity: 0.9;
        margin-top: 0.25rem;
    }
    .stats-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .stats-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); }
    .stats-warning { background: linear-gradient(135deg, #f9ca24 0%, #f0932b 100%); color: #2d3436; }
    .stats-success { background: linear-gradient(135deg, #00b894 0%, #00cec9 100%); }

    .filter-section {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }
    .complaint-table th {
        font-size: 0.85rem;
        white-space: nowrap;
        background: #f1f3f5;
    }
    .complaint-table td {
        vertical-align: middle;
        font-size: 0.9rem;
    }
    .description-cell {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .severity-badge {
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 10px;
    }
    .modal-section-title {
        font-size: 0.9rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e9ecef;
    }

    /* トースト（中央表示） */
    .toast-center {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 9999;
        min-width: 300px;
        text-align: center;
        padding: 1.5rem 2rem;
        border-radius: 12px;
        font-size: 1.1rem;
        font-weight: 600;
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        opacity: 0;
        transition: opacity 0.3s;
        pointer-events: none;
    }
    .toast-center.show {
        opacity: 1;
    }
    .toast-center.toast-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .toast-center.toast-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
</style>
</head>
<body>
    <?= $page_data['system_header'] ?>
    <?= $page_data['page_header'] ?>

    <div class="container mt-4">

        <!-- 成功/エラーメッセージ -->
        <?php if ($message): ?>
            <?= renderAlert('success', '完了', $message) ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?= renderAlert('danger', 'エラー', $error) ?>
        <?php endif; ?>

        <!-- 統計カード -->
        <div class="row mb-4">
            <div class="col-6 col-md-3 mb-3">
                <div class="stats-card stats-primary">
                    <div class="stats-number"><?= $stats['total'] ?></div>
                    <div class="stats-label"><i class="fas fa-envelope-open-text me-1"></i>今月の苦情件数</div>
                </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <div class="stats-card stats-danger">
                    <div class="stats-number"><?= $stats['pending'] ?></div>
                    <div class="stats-label"><i class="fas fa-exclamation-circle me-1"></i>未対応</div>
                </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <div class="stats-card stats-warning">
                    <div class="stats-number"><?= $stats['in_progress'] ?></div>
                    <div class="stats-label"><i class="fas fa-spinner me-1"></i>対応中</div>
                </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <div class="stats-card stats-success">
                    <div class="stats-number"><?= $stats['resolved'] ?></div>
                    <div class="stats-label"><i class="fas fa-check-circle me-1"></i>今月対応完了</div>
                </div>
            </div>
        </div>

        <!-- フィルター -->
        <div class="filter-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="fas fa-filter me-2"></i>検索・フィルター</h6>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#complaintModal" onclick="resetModal()">
                    <i class="fas fa-plus me-1"></i>苦情記録追加
                </button>
            </div>
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">年月</label>
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_month) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">対応ステータス</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">全て</option>
                        <option value="未対応" <?= $filter_status === '未対応' ? 'selected' : '' ?>>未対応</option>
                        <option value="対応中" <?= $filter_status === '対応中' ? 'selected' : '' ?>>対応中</option>
                        <option value="対応完了" <?= $filter_status === '対応完了' ? 'selected' : '' ?>>対応完了</option>
                        <option value="保留" <?= $filter_status === '保留' ? 'selected' : '' ?>>保留</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">苦情種別</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">全て</option>
                        <option value="運転マナー" <?= $filter_type === '運転マナー' ? 'selected' : '' ?>>運転マナー</option>
                        <option value="接客態度" <?= $filter_type === '接客態度' ? 'selected' : '' ?>>接客態度</option>
                        <option value="遅刻・時間" <?= $filter_type === '遅刻・時間' ? 'selected' : '' ?>>遅刻・時間</option>
                        <option value="車両状態" <?= $filter_type === '車両状態' ? 'selected' : '' ?>>車両状態</option>
                        <option value="料金" <?= $filter_type === '料金' ? 'selected' : '' ?>>料金</option>
                        <option value="その他" <?= $filter_type === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-search me-1"></i>検索
                    </button>
                </div>
            </form>
        </div>

        <!-- 一覧テーブル -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-list me-2"></i>苦情記録一覧</h6>
                <span class="badge bg-secondary"><?= count($complaints) ?>件</span>
            </div>
            <div class="card-body p-0">
                <?php if ($complaints): ?>
                    <div class="table-responsive">
                        <table class="table table-hover complaint-table mb-0">
                            <thead>
                                <tr>
                                    <th>受付日</th>
                                    <th>種別</th>
                                    <th>重度</th>
                                    <th>申立者</th>
                                    <th>関連乗務員</th>
                                    <th>内容</th>
                                    <th>ステータス</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($complaints as $c): ?>
                                    <tr>
                                        <td>
                                            <?= date('Y/m/d', strtotime($c['complaint_date'])) ?>
                                            <?php if ($c['complaint_time']): ?>
                                                <br><small class="text-muted"><?= date('H:i', strtotime($c['complaint_time'])) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($c['complaint_type']) ?></span></td>
                                        <td>
                                            <?php
                                            $severity_class = 'bg-info';
                                            if ($c['severity'] === '中程度') $severity_class = 'bg-warning text-dark';
                                            if ($c['severity'] === '重度') $severity_class = 'bg-danger';
                                            ?>
                                            <span class="badge severity-badge <?= $severity_class ?>"><?= htmlspecialchars($c['severity']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($c['complainant_name']) ?></td>
                                        <td><?= htmlspecialchars($c['driver_name'] ?? '-') ?></td>
                                        <td class="description-cell" title="<?= htmlspecialchars($c['description']) ?>">
                                            <?= htmlspecialchars(mb_strimwidth($c['description'], 0, 40, '...')) ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = 'bg-secondary';
                                            switch ($c['response_status']) {
                                                case '未対応': $status_class = 'bg-danger'; break;
                                                case '対応中': $status_class = 'bg-warning text-dark'; break;
                                                case '対応完了': $status_class = 'bg-success'; break;
                                                case '保留': $status_class = 'bg-secondary'; break;
                                            }
                                            ?>
                                            <span class="badge <?= $status_class ?>"><?= htmlspecialchars($c['response_status']) ?></span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-outline-primary btn-sm" onclick="editComplaint(<?= $c['id'] ?>)" title="編集">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm" onclick="deleteComplaint(<?= $c['id'] ?>)" title="削除">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-smile fa-3x text-success mb-3"></i>
                        <p class="text-muted mb-0">該当する苦情記録はありません</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- 苦情記録モーダル（追加/編集兼用） -->
    <div class="modal fade" id="complaintModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus me-2"></i>苦情記録追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="complaintForm" onsubmit="return handleSubmit(event)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="complaint_id" id="complaintId" value="">
                    <div class="modal-body">

                        <!-- 受付情報 -->
                        <div class="modal-section-title"><i class="fas fa-receipt me-1"></i>受付情報</div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">苦情受付日 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="complaint_date" id="fComplaintDate" required value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">受付時刻</label>
                                <input type="time" class="form-control" name="complaint_time" id="fComplaintTime">
                            </div>
                            <div class="col-md-4 mb-3">
                                <!-- spacer -->
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">苦情申立者名 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="complainant_name" id="fComplainantName" required placeholder="申立者のお名前">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">連絡先電話番号</label>
                                <input type="tel" class="form-control" name="complainant_phone" id="fComplainantPhone" placeholder="090-xxxx-xxxx">
                            </div>
                        </div>

                        <!-- 苦情内容 -->
                        <div class="modal-section-title"><i class="fas fa-exclamation-circle me-1"></i>苦情内容</div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">苦情種別 <span class="text-danger">*</span></label>
                                <select class="form-select" name="complaint_type" id="fComplaintType" required>
                                    <option value="">選択してください</option>
                                    <option value="運転マナー">運転マナー</option>
                                    <option value="接客態度">接客態度</option>
                                    <option value="遅刻・時間">遅刻・時間</option>
                                    <option value="車両状態">車両状態</option>
                                    <option value="料金">料金</option>
                                    <option value="その他">その他</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">重度</label>
                                <select class="form-select" name="severity" id="fSeverity">
                                    <option value="軽度">軽度</option>
                                    <option value="中程度" selected>中程度</option>
                                    <option value="重度">重度</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">事象発生日</label>
                                <input type="date" class="form-control" name="related_date" id="fRelatedDate">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">関連乗務員</label>
                                <select class="form-select" name="driver_id" id="fDriverId">
                                    <option value="">選択なし</option>
                                    <?php foreach ($drivers as $d): ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">関連車両</label>
                                <select class="form-select" name="vehicle_id" id="fVehicleId">
                                    <option value="">選択なし</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['vehicle_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">苦情詳細 <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" id="fDescription" rows="3" required placeholder="苦情の具体的な内容を記入してください"></textarea>
                        </div>

                        <!-- 対応情報 -->
                        <div class="modal-section-title"><i class="fas fa-tools me-1"></i>対応情報</div>
                        <div class="mb-3">
                            <label class="form-label">対応内容</label>
                            <textarea class="form-control" name="response" id="fResponse" rows="3" placeholder="実施した対応内容を記入してください"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">対応ステータス</label>
                                <select class="form-select" name="response_status" id="fResponseStatus">
                                    <option value="未対応">未対応</option>
                                    <option value="対応中">対応中</option>
                                    <option value="対応完了">対応完了</option>
                                    <option value="保留">保留</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">対応完了日</label>
                                <input type="date" class="form-control" name="response_date" id="fResponseDate">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">対応者</label>
                                <select class="form-select" name="handled_by" id="fHandledBy">
                                    <option value="">選択なし</option>
                                    <?php foreach ($staff as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- 再発防止策・備考 -->
                        <div class="modal-section-title"><i class="fas fa-shield-alt me-1"></i>再発防止・備考</div>
                        <div class="mb-3">
                            <label class="form-label">再発防止策</label>
                            <textarea class="form-control" name="prevention_measures" id="fPreventionMeasures" rows="2" placeholder="再発防止策を記入してください"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">備考</label>
                            <textarea class="form-control" name="notes" id="fNotes" rows="2" placeholder="その他メモ"></textarea>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <span id="submitBtnText"><i class="fas fa-save me-1"></i>保存</span>
                            <span id="submitBtnLoading" style="display:none;"><i class="fas fa-spinner fa-spin me-1"></i>保存中...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 中央トースト -->
    <div id="toastCenter" class="toast-center"></div>

    <!-- 削除用フォーム -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="complaint_id" id="deleteComplaintId" value="">
    </form>

    <script>
    // 全苦情データ（編集時に使用）
    const complaintsData = <?= json_encode($complaints, JSON_UNESCAPED_UNICODE) ?>;

    // モーダル初期化（追加モード）
    function resetModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>苦情記録追加';
        document.getElementById('formAction').value = 'add';
        document.getElementById('complaintId').value = '';
        document.getElementById('complaintForm').reset();
        document.getElementById('fComplaintDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('fSeverity').value = '中程度';
        document.getElementById('fResponseStatus').value = '未対応';
    }

    // 編集モード
    function editComplaint(id) {
        const data = complaintsData.find(c => c.id == id);
        if (!data) return;

        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>苦情記録編集';
        document.getElementById('formAction').value = 'update';
        document.getElementById('complaintId').value = data.id;

        document.getElementById('fComplaintDate').value = data.complaint_date || '';
        document.getElementById('fComplaintTime').value = data.complaint_time || '';
        document.getElementById('fComplainantName').value = data.complainant_name || '';
        document.getElementById('fComplainantPhone').value = data.complainant_phone || '';
        document.getElementById('fComplaintType').value = data.complaint_type || '';
        document.getElementById('fSeverity').value = data.severity || '中程度';
        document.getElementById('fRelatedDate').value = data.related_date || '';
        document.getElementById('fDriverId').value = data.driver_id || '';
        document.getElementById('fVehicleId').value = data.vehicle_id || '';
        document.getElementById('fDescription').value = data.description || '';
        document.getElementById('fResponse').value = data.response || '';
        document.getElementById('fResponseStatus').value = data.response_status || '未対応';
        document.getElementById('fResponseDate').value = data.response_date || '';
        document.getElementById('fHandledBy').value = data.handled_by || '';
        document.getElementById('fPreventionMeasures').value = data.prevention_measures || '';
        document.getElementById('fNotes').value = data.notes || '';

        new bootstrap.Modal(document.getElementById('complaintModal')).show();
    }

    // 削除
    function deleteComplaint(id) {
        if (!confirm('この苦情記録を削除してもよろしいですか？\n\nこの操作は取り消せません。')) return;
        document.getElementById('deleteComplaintId').value = id;
        document.getElementById('deleteForm').submit();
    }

    // 送信ハンドラ（ローディングボタン + トースト）
    function handleSubmit(event) {
        const btn = document.getElementById('submitBtn');
        const btnText = document.getElementById('submitBtnText');
        const btnLoading = document.getElementById('submitBtnLoading');

        btn.disabled = true;
        btnText.style.display = 'none';
        btnLoading.style.display = 'inline';

        return true; // フォーム送信を続行
    }

    // 中央トースト表示
    function showCenterToast(message, type) {
        const toast = document.getElementById('toastCenter');
        toast.className = 'toast-center toast-' + type;
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(function() {
            toast.classList.remove('show');
        }, 3000);
    }

    // ページ読み込み時にメッセージがある場合はトースト表示
    <?php if ($message): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showCenterToast('<?= addslashes($message) ?>', 'success');
    });
    <?php endif; ?>
    <?php if ($error): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showCenterToast('<?= addslashes($error) ?>', 'error');
    });
    <?php endif; ?>
    </script>

<?php echo $page_data['html_footer']; ?>
