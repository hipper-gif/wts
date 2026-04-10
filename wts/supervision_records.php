<?php
/**
 * 指導監督記録管理
 * 乗務員への安全運転指導・教育の記録を管理
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/session_check.php';

$pdo = getDBConnection();

// 権限チェック（$user_role は session_check.php で設定済み）
$is_admin = ($user_role === 'Admin');
$is_manager = !empty($_SESSION['is_manager']);

if (!$is_admin && !$is_manager) {
    header('Location: dashboard.php');
    exit;
}

// 統一ヘッダーシステム
require_once 'includes/unified-header.php';
$page_config = getPageConfiguration('supervision_records');

// フィルター条件
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_type = $_GET['type'] ?? '';
$filter_driver = $_GET['driver_id'] ?? '';

// POST処理
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO supervision_records (
                        supervision_date, supervision_type, driver_id, supervisor_id,
                        duration_minutes, subject, content, result, follow_up_date, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['supervision_date'],
                    $_POST['supervision_type'],
                    (int)$_POST['driver_id'],
                    (int)$_POST['supervisor_id'],
                    (int)$_POST['duration_minutes'],
                    $_POST['subject'],
                    $_POST['content'],
                    $_POST['result'],
                    $_POST['follow_up_date'] ?: null,
                    $_POST['notes'] ?: null
                ]);
                $success_message = '指導監督記録を登録しました。';
                break;

            case 'update':
                $stmt = $pdo->prepare("
                    UPDATE supervision_records SET
                        supervision_date = ?, supervision_type = ?, driver_id = ?, supervisor_id = ?,
                        duration_minutes = ?, subject = ?, content = ?, result = ?,
                        follow_up_date = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['supervision_date'],
                    $_POST['supervision_type'],
                    (int)$_POST['driver_id'],
                    (int)$_POST['supervisor_id'],
                    (int)$_POST['duration_minutes'],
                    $_POST['subject'],
                    $_POST['content'],
                    $_POST['result'],
                    $_POST['follow_up_date'] ?: null,
                    $_POST['notes'] ?: null,
                    (int)$_POST['record_id']
                ]);
                $success_message = '指導監督記録を更新しました。';
                break;

            case 'delete':
                if (!$is_admin) {
                    throw new Exception('削除権限がありません。');
                }
                $stmt = $pdo->prepare("DELETE FROM supervision_records WHERE id = ?");
                $stmt->execute([(int)$_POST['record_id']]);
                $success_message = '指導監督記録を削除しました。';
                break;
        }
    } catch (Exception $e) {
        error_log("Supervision record error: " . $e->getMessage());
        $error_message = 'エラーが発生しました。管理者にお問い合わせください。';
    }
}

// 乗務員一覧取得（指導対象）
$drivers_stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// 指導者一覧取得（管理者・点呼者）
$supervisors_stmt = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 AND login_id NOT LIKE 'sysop%' AND (permission_level = 'Admin' OR is_manager = 1 OR is_caller = 1) ORDER BY name");
$supervisors = $supervisors_stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報取得
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$current_year_start = date('Y-01-01');
$current_year_end = date('Y-12-31');

// 今月の指導回数
$stmt_month = $pdo->prepare("SELECT COUNT(*) FROM supervision_records WHERE supervision_date BETWEEN ? AND ?");
$stmt_month->execute([$current_month_start, $current_month_end]);
$month_count = (int)$stmt_month->fetchColumn();

// 今年の指導回数
$stmt_year = $pdo->prepare("SELECT COUNT(*) FROM supervision_records WHERE supervision_date BETWEEN ? AND ?");
$stmt_year->execute([$current_year_start, $current_year_end]);
$year_count = (int)$stmt_year->fetchColumn();

// 要再指導
$stmt_redo = $pdo->prepare("SELECT COUNT(*) FROM supervision_records WHERE result = '要再指導'");
$stmt_redo->execute();
$redo_count = (int)$stmt_redo->fetchColumn();

// フォローアップ予定（今日以降）
$stmt_followup = $pdo->prepare("SELECT COUNT(*) FROM supervision_records WHERE follow_up_date IS NOT NULL AND follow_up_date >= CURDATE()");
$stmt_followup->execute();
$followup_count = (int)$stmt_followup->fetchColumn();

// 一覧データ取得
$sql = "
    SELECT sr.*,
           d.name AS driver_name,
           s.name AS supervisor_name
    FROM supervision_records sr
    LEFT JOIN users d ON sr.driver_id = d.id
    LEFT JOIN users s ON sr.supervisor_id = s.id
    WHERE 1=1
";
$params = [];

if ($filter_month) {
    $sql .= " AND DATE_FORMAT(sr.supervision_date, '%Y-%m') = ?";
    $params[] = $filter_month;
}
if ($filter_type) {
    $sql .= " AND sr.supervision_type = ?";
    $params[] = $filter_type;
}
if ($filter_driver) {
    $sql .= " AND sr.driver_id = ?";
    $params[] = (int)$filter_driver;
}

$sql .= " ORDER BY sr.supervision_date DESC, sr.id DESC";

$stmt_list = $pdo->prepare($sql);
$stmt_list->execute($params);
$records = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// 指導種別一覧
$supervision_types = ['初任教育', '適齢教育', '安全運転指導', '接遇マナー', '車椅子操作', '応急救護', 'その他'];
$result_options = ['良好', '概ね良好', '要改善', '要再指導'];

// ページ生成（統一ヘッダーシステム）
$page_options = [
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => 'マスターメニュー', 'url' => 'master_menu.php'],
        ['text' => $page_config['title'], 'url' => 'supervision_records.php']
    ]
];
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'supervision_records',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);
?>
<?= $page_data['html_head'] ?>

<style>
    .stats-card {
        border-radius: 15px;
        transition: transform 0.2s;
        border: none;
    }
    .stats-card:hover {
        transform: translateY(-2px);
    }
    .stats-number {
        font-size: 2rem;
        font-weight: bold;
    }
    .search-section {
        background-color: #f8f9fa;
        border-radius: 10px;
        padding: 1rem;
    }
    .result-badge {
        font-size: 0.85rem;
        padding: 4px 10px;
        border-radius: 8px;
    }
    .record-row {
        transition: background-color 0.2s;
    }
    .record-row:hover {
        background-color: #f8f9fa;
    }
    .type-badge {
        font-size: 0.8rem;
        padding: 3px 8px;
        border-radius: 6px;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translate(-50%, -20px); }
        to { opacity: 1; transform: translate(-50%, 0); }
    }
</style>
</head>
<body>
    <?= $page_data['system_header'] ?>
    <?= $page_data['page_header'] ?>
    <main class="main-content" id="main-content" tabindex="-1">

    <div class="container mt-4">

        <!-- 成功メッセージ -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert" style="font-weight:600; font-size:1.05rem;">
            <i class="fas fa-check-circle me-2 fa-lg"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>document.addEventListener('DOMContentLoaded', function() { showToast('<?= addslashes($success_message) ?>', 'success'); });</script>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert" style="font-weight:600; font-size:1.05rem;">
            <i class="fas fa-exclamation-circle me-2 fa-lg"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>document.addEventListener('DOMContentLoaded', function() { showToast('<?= addslashes($error_message) ?>', 'error'); });</script>
        <?php endif; ?>

        <!-- 統計カード -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stats-card text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $month_count ?></div>
                        <div>今月の指導回数</div>
                        <small><?= date('Y年n月') ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card text-white" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $year_count ?></div>
                        <div>今年の指導回数</div>
                        <small><?= date('Y') ?>年</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card text-white" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $redo_count ?></div>
                        <div>要再指導</div>
                        <small>対応が必要</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card text-dark" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $followup_count ?></div>
                        <div>フォローアップ予定</div>
                        <small>今日以降</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 検索・フィルター -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="search-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-search me-2"></i>検索・フィルター</h5>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecordModal">
                            <i class="fas fa-plus me-1"></i>指導記録追加
                        </button>
                    </div>

                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">年月</label>
                            <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($filter_month) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">指導種別</label>
                            <select name="type" class="form-select">
                                <option value="">全て</option>
                                <?php foreach ($supervision_types as $type): ?>
                                    <option value="<?= htmlspecialchars($type) ?>" <?= $filter_type === $type ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">乗務員</label>
                            <select name="driver_id" class="form-select">
                                <option value="">全員</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver['id'] ?>" <?= $filter_driver == $driver['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($driver['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i>検索
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 一覧テーブル -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>指導監督記録一覧</h5>
                        <span class="badge bg-secondary"><?= count($records) ?>件</span>
                    </div>
                    <div class="card-body">
                        <?php if ($records): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>日付</th>
                                            <th>種別</th>
                                            <th>対象乗務員</th>
                                            <th>指導者</th>
                                            <th>テーマ</th>
                                            <th>時間</th>
                                            <th>結果</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $record): ?>
                                            <tr class="record-row">
                                                <td><?= date('Y/m/d', strtotime($record['supervision_date'])) ?></td>
                                                <td>
                                                    <span class="badge type-badge bg-info text-white">
                                                        <?= htmlspecialchars($record['supervision_type']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($record['driver_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($record['supervisor_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($record['subject']) ?></td>
                                                <td><?= (int)$record['duration_minutes'] ?>分</td>
                                                <td>
                                                    <?php
                                                    $result_colors = [
                                                        '良好' => 'bg-success',
                                                        '概ね良好' => 'bg-primary',
                                                        '要改善' => 'bg-warning text-dark',
                                                        '要再指導' => 'bg-danger'
                                                    ];
                                                    $badge_class = $result_colors[$record['result']] ?? 'bg-secondary';
                                                    ?>
                                                    <span class="badge result-badge <?= $badge_class ?>">
                                                        <?= htmlspecialchars($record['result']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-primary btn-sm" onclick="editRecord(<?= $record['id'] ?>)" title="編集">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-sm" onclick="deleteRecord(<?= $record['id'] ?>)" title="削除">
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
                            <div class="text-center py-4">
                                <i class="fas fa-chalkboard-teacher fa-3x text-muted mb-3"></i>
                                <p class="text-muted">指導監督記録はありません</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecordModal">
                                    <i class="fas fa-plus me-1"></i>最初の記録を追加
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 戻るボタン -->
        <div class="row mb-4">
            <div class="col-12 text-center">
                <a href="master_menu.php" class="btn btn-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i>マスターメニュー
                </a>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-home me-1"></i>ダッシュボード
                </a>
            </div>
        </div>
    </div>

    <!-- 追加モーダル -->
    <div class="modal fade" id="addRecordModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>指導監督記録 追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addForm" onsubmit="return handleSubmit(this)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">指導日 <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="supervision_date" required value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">指導種別 <span class="text-danger">*</span></label>
                                    <select name="supervision_type" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($supervision_types as $type): ?>
                                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">対象乗務員 <span class="text-danger">*</span></label>
                                    <select name="driver_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">指導者 <span class="text-danger">*</span></label>
                                    <select name="supervisor_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($supervisors as $sup): ?>
                                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">指導時間（分）</label>
                                    <input type="number" class="form-control" name="duration_minutes" value="60" min="1" max="480">
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">テーマ <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="subject" required placeholder="指導テーマを入力">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">指導内容 <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="content" rows="4" required placeholder="指導内容の詳細を記入してください"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">結果</label>
                                    <select name="result" class="form-select">
                                        <?php foreach ($result_options as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">フォローアップ日</label>
                                    <input type="date" class="form-control" name="follow_up_date">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">備考</label>
                                    <textarea class="form-control" name="notes" rows="1" placeholder="備考（任意）"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary" data-loading-text="保存中...">
                            <i class="fas fa-save me-1"></i>登録
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 編集モーダル -->
    <div class="modal fade" id="editRecordModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>指導監督記録 編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm" onsubmit="return handleSubmit(this)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="record_id" id="edit_record_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">指導日 <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="supervision_date" id="edit_supervision_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">指導種別 <span class="text-danger">*</span></label>
                                    <select name="supervision_type" id="edit_supervision_type" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($supervision_types as $type): ?>
                                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">対象乗務員 <span class="text-danger">*</span></label>
                                    <select name="driver_id" id="edit_driver_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">指導者 <span class="text-danger">*</span></label>
                                    <select name="supervisor_id" id="edit_supervisor_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($supervisors as $sup): ?>
                                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">指導時間（分）</label>
                                    <input type="number" class="form-control" name="duration_minutes" id="edit_duration_minutes" value="60" min="1" max="480">
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">テーマ <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="subject" id="edit_subject" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">指導内容 <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="content" id="edit_content" rows="4" required></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">結果</label>
                                    <select name="result" id="edit_result" class="form-select">
                                        <?php foreach ($result_options as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">フォローアップ日</label>
                                    <input type="date" class="form-control" name="follow_up_date" id="edit_follow_up_date">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">備考</label>
                                    <textarea class="form-control" name="notes" id="edit_notes" rows="1"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary" data-loading-text="保存中...">
                            <i class="fas fa-save me-1"></i>更新
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 削除フォーム -->
    <form method="POST" id="deleteForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="record_id" id="delete_record_id">
    </form>

    <script>
    // レコードデータをJSに渡す
    const recordsData = <?= json_encode($records, JSON_UNESCAPED_UNICODE) ?>;

    // 編集モーダル表示
    function editRecord(id) {
        const record = recordsData.find(r => r.id == id);
        if (!record) return;

        document.getElementById('edit_record_id').value = record.id;
        document.getElementById('edit_supervision_date').value = record.supervision_date;
        document.getElementById('edit_supervision_type').value = record.supervision_type;
        document.getElementById('edit_driver_id').value = record.driver_id;
        document.getElementById('edit_supervisor_id').value = record.supervisor_id;
        document.getElementById('edit_duration_minutes').value = record.duration_minutes;
        document.getElementById('edit_subject').value = record.subject;
        document.getElementById('edit_content').value = record.content;
        document.getElementById('edit_result').value = record.result;
        document.getElementById('edit_follow_up_date').value = record.follow_up_date || '';
        document.getElementById('edit_notes').value = record.notes || '';

        new bootstrap.Modal(document.getElementById('editRecordModal')).show();
    }

    // 削除確認
    function deleteRecord(id) {
        if (!confirm('この指導監督記録を削除しますか？\n\nこの操作は取り消せません。')) {
            return;
        }
        document.getElementById('delete_record_id').value = id;
        document.getElementById('deleteForm').submit();
    }

    // フォーム送信ハンドラ（ローディングボタン）
    function handleSubmit(form) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            const loadingText = submitBtn.getAttribute('data-loading-text') || '処理中...';
            const originalHtml = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + loadingText;
            submitBtn.disabled = true;

            // フォーム送信失敗時にボタンを復元
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalHtml;
                    submitBtn.disabled = false;
                }
            }, 10000);
        }
        return true;
    }

    // 通知表示（中央表示・目立つ版）
    function showNotification(message, type = 'info') {
        const existingToast = document.getElementById('centralToast');
        if (existingToast) existingToast.remove();

        const colors = { success: '#198754', warning: '#ffc107', error: '#dc3545', info: '#0d6efd' };
        const icons = { success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle', info: 'info-circle' };
        const textColor = type === 'warning' ? '#000' : '#fff';

        const toastHtml = `
            <div id="centralToast" style="position:fixed; top:20px; left:50%; transform:translateX(-50%); z-index:9999;
                background:${colors[type]}; color:${textColor}; padding:16px 32px; border-radius:12px;
                box-shadow:0 8px 32px rgba(0,0,0,0.3); font-size:1.1rem; font-weight:600;
                display:flex; align-items:center; gap:10px; animation:slideDown 0.4s ease;">
                <i class="fas fa-${icons[type]} fa-lg"></i>
                <span>${message}</span>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', toastHtml);
        setTimeout(() => {
            const el = document.getElementById('centralToast');
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.5s'; setTimeout(() => el.remove(), 500); }
        }, 8000);
    }

    // showToast（成功時の中央表示）
    function showToast(message, type) {
        showNotification(message, type);
    }
    </script>

    </main>
<?php echo $page_data['html_footer']; ?>
