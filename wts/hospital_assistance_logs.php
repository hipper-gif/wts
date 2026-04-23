<?php
/**
 * 院内介助ログ
 * 院内介助の記録を管理・共有
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/session_check.php';

$pdo = getDBConnection();

// 権限チェック（$user_role は session_check.php で設定済み）
$is_admin = ($user_role === 'Admin');

// 統一ヘッダーシステム
require_once 'includes/unified-header.php';
$page_config = getPageConfiguration('hospital_assistance_logs');

// フィルター条件
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_staff = $_GET['staff_id'] ?? '';

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
                    INSERT INTO hospital_assistance_logs (
                        assistance_date, assistance_time, customer_name,
                        staff_id, facility_name, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['assistance_date'],
                    $_POST['assistance_time'] ?: null,
                    $_POST['customer_name'],
                    (int)$_POST['staff_id'],
                    $_POST['facility_name'],
                    $_POST['notes'] ?: null,
                    $user_id
                ]);
                $success_message = '院内介助記録を登録しました。';
                break;

            case 'update':
                $stmt = $pdo->prepare("
                    UPDATE hospital_assistance_logs SET
                        assistance_date = ?, assistance_time = ?, customer_name = ?,
                        staff_id = ?, facility_name = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['assistance_date'],
                    $_POST['assistance_time'] ?: null,
                    $_POST['customer_name'],
                    (int)$_POST['staff_id'],
                    $_POST['facility_name'],
                    $_POST['notes'] ?: null,
                    (int)$_POST['record_id']
                ]);
                $success_message = '院内介助記録を更新しました。';
                break;

            case 'delete':
                if (!$is_admin) {
                    throw new Exception('削除権限がありません。');
                }
                $stmt = $pdo->prepare("DELETE FROM hospital_assistance_logs WHERE id = ?");
                $stmt->execute([(int)$_POST['record_id']]);
                $success_message = '院内介助記録を削除しました。';
                break;
        }
    } catch (Exception $e) {
        error_log("Hospital assistance log error: " . $e->getMessage());
        $error_message = 'エラーが発生しました。管理者にお問い合わせください。';
    }
}

// スタッフ一覧取得
$staff_stmt = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 AND login_id NOT LIKE 'sysop%' ORDER BY name");
$staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報取得
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$current_year_start = date('Y-01-01');
$current_year_end = date('Y-12-31');

// 今月の記録数
$stmt_month = $pdo->prepare("SELECT COUNT(*) FROM hospital_assistance_logs WHERE assistance_date BETWEEN ? AND ?");
$stmt_month->execute([$current_month_start, $current_month_end]);
$month_count = (int)$stmt_month->fetchColumn();

// 今年の記録数
$stmt_year = $pdo->prepare("SELECT COUNT(*) FROM hospital_assistance_logs WHERE assistance_date BETWEEN ? AND ?");
$stmt_year->execute([$current_year_start, $current_year_end]);
$year_count = (int)$stmt_year->fetchColumn();

// 一覧データ取得
$sql = "
    SELECT hal.*,
           u.name AS staff_name,
           c.name AS creator_name
    FROM hospital_assistance_logs hal
    LEFT JOIN users u ON hal.staff_id = u.id
    LEFT JOIN users c ON hal.created_by = c.id
    WHERE 1=1
";
$params = [];

if ($filter_month) {
    $sql .= " AND DATE_FORMAT(hal.assistance_date, '%Y-%m') = ?";
    $params[] = $filter_month;
}
if ($filter_staff) {
    $sql .= " AND hal.staff_id = ?";
    $params[] = (int)$filter_staff;
}

$sql .= " ORDER BY hal.assistance_date DESC, hal.assistance_time DESC, hal.id DESC";

$stmt_list = $pdo->prepare($sql);
$stmt_list->execute($params);
$records = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// ページ生成（統一ヘッダーシステム）
$page_options = [
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => 'マスターメニュー', 'url' => 'master_menu.php'],
        ['text' => $page_config['title'], 'url' => 'hospital_assistance_logs.php']
    ]
];
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'hospital_assistance_logs',
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
    .record-row {
        transition: background-color 0.2s;
    }
    .record-row:hover {
        background-color: #f8f9fa;
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
            <div class="col-md-6 mb-3">
                <div class="card stats-card text-white" style="background: linear-gradient(135deg, #20c997 0%, #0ca678 100%);">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $month_count ?></div>
                        <div>今月の記録数</div>
                        <small><?= date('Y年n月') ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card stats-card text-white" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $year_count ?></div>
                        <div>今年の記録数</div>
                        <small><?= date('Y') ?>年</small>
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
                            <i class="fas fa-plus me-1"></i>記録追加
                        </button>
                    </div>

                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">年月</label>
                            <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($filter_month) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">担当スタッフ</label>
                            <select name="staff_id" class="form-select">
                                <option value="">全員</option>
                                <?php foreach ($staff_list as $staff): ?>
                                    <option value="<?= $staff['id'] ?>" <?= $filter_staff == $staff['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($staff['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
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
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>院内介助記録一覧</h5>
                        <span class="badge bg-secondary"><?= count($records) ?>件</span>
                    </div>
                    <div class="card-body">
                        <?php if ($records): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>日付</th>
                                            <th>時刻</th>
                                            <th>利用者名</th>
                                            <th>担当スタッフ</th>
                                            <th>施設名</th>
                                            <th>備考</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $record): ?>
                                            <tr class="record-row">
                                                <td><?= date('Y/m/d', strtotime($record['assistance_date'])) ?></td>
                                                <td><?= $record['assistance_time'] ? date('H:i', strtotime($record['assistance_time'])) : '-' ?></td>
                                                <td><?= htmlspecialchars($record['customer_name']) ?></td>
                                                <td><?= htmlspecialchars($record['staff_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($record['facility_name']) ?></td>
                                                <td>
                                                    <?php if ($record['notes']): ?>
                                                        <span class="d-inline-block text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($record['notes']) ?>">
                                                            <?= htmlspecialchars($record['notes']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-primary btn-sm" onclick="editRecord(<?= $record['id'] ?>)" title="編集">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <?php if ($is_admin): ?>
                                                        <button class="btn btn-outline-danger btn-sm" onclick="deleteRecord(<?= $record['id'] ?>)" title="削除">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-hospital fa-3x text-muted mb-3"></i>
                                <p class="text-muted">院内介助記録はありません</p>
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
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>院内介助記録 追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addForm" onsubmit="return handleSubmit(this)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">介助日 <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="assistance_date" required value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">開始時刻</label>
                                    <input type="time" class="form-control" name="assistance_time">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">利用者名 <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="customer_name" required placeholder="利用者名を入力">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">担当スタッフ <span class="text-danger">*</span></label>
                                    <select name="staff_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($staff_list as $staff): ?>
                                            <option value="<?= $staff['id'] ?>"><?= htmlspecialchars($staff['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">病院・施設名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="facility_name" required placeholder="病院・施設名を入力">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">備考</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="介助内容・気づき等（任意）"></textarea>
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
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>院内介助記録 編集</h5>
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
                                    <label class="form-label">介助日 <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="assistance_date" id="edit_assistance_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">開始時刻</label>
                                    <input type="time" class="form-control" name="assistance_time" id="edit_assistance_time">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">利用者名 <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="customer_name" id="edit_customer_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">担当スタッフ <span class="text-danger">*</span></label>
                                    <select name="staff_id" id="edit_staff_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($staff_list as $staff): ?>
                                            <option value="<?= $staff['id'] ?>"><?= htmlspecialchars($staff['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">病院・施設名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="facility_name" id="edit_facility_name" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">備考</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
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
        document.getElementById('edit_assistance_date').value = record.assistance_date;
        document.getElementById('edit_assistance_time').value = record.assistance_time || '';
        document.getElementById('edit_customer_name').value = record.customer_name;
        document.getElementById('edit_staff_id').value = record.staff_id;
        document.getElementById('edit_facility_name').value = record.facility_name;
        document.getElementById('edit_notes').value = record.notes || '';

        new bootstrap.Modal(document.getElementById('editRecordModal')).show();
    }

    // 削除確認
    function deleteRecord(id) {
        if (!confirm('この院内介助記録を削除しますか？\n\nこの操作は取り消せません。')) {
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

            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalHtml;
                    submitBtn.disabled = false;
                }
            }, 10000);
        }
        return true;
    }

    // 通知表示
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

    function showToast(message, type) {
        showNotification(message, type);
    }
    </script>

    </main>
<?php echo $page_data['html_footer']; ?>
