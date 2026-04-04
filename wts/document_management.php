<?php
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
// $user_role は session_check.php で設定済み
$is_admin = ($user_role === 'Admin');

// 管理者権限チェック
if (!$is_admin) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

// カテゴリ名マッピング
$category_labels = [
    'license' => '許可証・認可',
    'insurance' => '保険関連',
    'vehicle' => '車両関連',
    'driver' => '乗務員関連',
    'contract' => '契約関連',
    'report' => '報告書',
    'other' => 'その他'
];
$category_colors = [
    'license' => 'primary',
    'insurance' => 'info',
    'vehicle' => 'success',
    'driver' => 'warning',
    'contract' => 'secondary',
    'report' => 'dark',
    'other' => 'light'
];

// 書類一覧取得
$category_filter = $_GET['category'] ?? '';
$expiry_filter = $_GET['expiry'] ?? '';
$search = $_GET['q'] ?? '';

$where = ['d.is_active = 1'];
$params = [];

if ($category_filter && array_key_exists($category_filter, $category_labels)) {
    $where[] = 'd.category = ?';
    $params[] = $category_filter;
}
if ($expiry_filter === 'expired') {
    $where[] = 'd.expiry_date < CURDATE()';
} elseif ($expiry_filter === 'soon') {
    $where[] = 'd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
}
if ($search) {
    $where[] = '(d.title LIKE ? OR d.original_filename LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql = "SELECT d.*, u.name as uploader_name,
               du.name as driver_name, v.vehicle_number
        FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        LEFT JOIN users du ON d.related_driver_id = du.id
        LEFT JOIN vehicles v ON d.related_vehicle_id = v.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 乗務員・車両リスト取得（モーダル用）
$drivers = getActiveDrivers($pdo);
$stmt_v = $pdo->prepare("SELECT id, vehicle_number, model FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
$stmt_v->execute();
$vehicles = $stmt_v->fetchAll(PDO::FETCH_ASSOC);

// 期限アラート取得
$stmt_alert = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()");
$stmt_alert->execute();
$expired_count = $stmt_alert->fetchColumn();

$stmt_alert2 = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stmt_alert2->execute();
$expiring_count = $stmt_alert2->fetchColumn();

// ファイルサイズフォーマット
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

// ページ設定
$page_config = getPageConfiguration('document_management');

$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'css/document_management.css'
    ],
    'additional_js' => [
        'js/ui-interactions.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '管理機能', 'url' => 'master_menu.php'],
        ['text' => '書類管理', 'url' => 'document_management.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'document_management',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<main class="container mt-4">

    <!-- 期限アラートバナー -->
    <?php if ($expired_count > 0): ?>
        <div class="alert alert-danger d-flex align-items-center" role="alert">
            <i class="fas fa-exclamation-circle me-2 fa-lg"></i>
            <div>
                <strong>期限切れの書類が<?= $expired_count ?>件あります。</strong>
                <a href="?expiry=expired" class="alert-link ms-2">確認する</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($expiring_count > 0): ?>
        <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="fas fa-clock me-2 fa-lg"></i>
            <div>
                <strong>30日以内に期限切れとなる書類が<?= $expiring_count ?>件あります。</strong>
                <a href="?expiry=soon" class="alert-link ms-2">確認する</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- ツールバー -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="fas fa-upload me-2"></i>書類をアップロード
        </button>

        <select class="form-select" style="width:auto;max-width:160px" onchange="applyFilter('category', this.value)">
            <option value="">全カテゴリ</option>
            <?php foreach ($category_labels as $key => $label): ?>
            <option value="<?= $key ?>" <?= $category_filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="form-select" style="width:auto;max-width:150px" onchange="applyFilter('expiry', this.value)">
            <option value="">期限：全て</option>
            <option value="expired" <?= $expiry_filter === 'expired' ? 'selected' : '' ?>>期限切れ</option>
            <option value="soon" <?= $expiry_filter === 'soon' ? 'selected' : '' ?>>30日以内</option>
        </select>

        <span class="text-muted ms-auto">全<?= count($documents) ?>件</span>
    </div>

    <!-- 書類一覧テーブル（PC表示） -->
    <div class="card d-none d-md-block">
        <div class="card-body p-0">
            <?php if (empty($documents)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">書類が登録されていません</h5>
                    <p class="text-muted mb-0">「書類をアップロード」ボタンからファイルを登録してください。</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>タイトル</th>
                                <th>カテゴリ</th>
                                <th>関連</th>
                                <th>有効期限</th>
                                <th>サイズ</th>
                                <th class="text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc):
                                $row_class = '';
                                if ($doc['expiry_date']) {
                                    $expiry = strtotime($doc['expiry_date']);
                                    $now = time();
                                    $days_left = ($expiry - $now) / 86400;
                                    if ($days_left < 0) {
                                        $row_class = 'table-danger';
                                    } elseif ($days_left <= 30) {
                                        $row_class = 'table-warning';
                                    }
                                }
                            ?>
                            <tr class="<?= $row_class ?>">
                                <td>
                                    <i class="fas <?= ($doc['mime_type'] === 'application/pdf') ? 'fa-file-pdf text-danger' : 'fa-file-image text-info' ?> me-2"></i>
                                    <strong><?= htmlspecialchars($doc['title']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($doc['original_filename']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $category_colors[$doc['category']] ?? 'light' ?>">
                                        <?= htmlspecialchars($category_labels[$doc['category']] ?? 'その他') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($doc['driver_name']): ?>
                                        <small><i class="fas fa-user me-1"></i><?= htmlspecialchars($doc['driver_name']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if ($doc['vehicle_number']): ?>
                                        <small><i class="fas fa-car me-1"></i><?= htmlspecialchars($doc['vehicle_number']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!$doc['driver_name'] && !$doc['vehicle_number']): ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($doc['expiry_date']): ?>
                                        <?php
                                            $expiry = strtotime($doc['expiry_date']);
                                            $days_left = ($expiry - time()) / 86400;
                                        ?>
                                        <span class="<?= $days_left < 0 ? 'text-danger fw-bold' : ($days_left <= 30 ? 'text-warning fw-bold' : '') ?>">
                                            <?= date('Y/m/d', $expiry) ?>
                                        </span>
                                        <?php if ($days_left < 0): ?>
                                            <br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> 期限切れ</small>
                                        <?php elseif ($days_left <= 30): ?>
                                            <br><small class="text-warning"><i class="fas fa-clock"></i> あと<?= ceil($days_left) ?>日</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted">
                                    <?= formatFileSize($doc['file_size'] ?? 0) ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary" title="プレビュー"
                                                onclick="previewDocument(<?= $doc['id'] ?>, '<?= htmlspecialchars($doc['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($doc['mime_type'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="api/document_download.php?id=<?= $doc['id'] ?>" class="btn btn-outline-success" title="ダウンロード">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" title="削除"
                                                onclick="deleteDocument(<?= $doc['id'] ?>, '<?= htmlspecialchars($doc['title'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 書類一覧カード（スマホ表示） -->
    <div class="d-md-none">
        <?php if (empty($documents)): ?>
            <div class="text-center py-5">
                <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">書類が登録されていません</h5>
            </div>
        <?php else: ?>
            <?php foreach ($documents as $doc):
                $card_class = '';
                if ($doc['expiry_date']) {
                    $expiry = strtotime($doc['expiry_date']);
                    $days_left = ($expiry - time()) / 86400;
                    if ($days_left < 0) {
                        $card_class = 'border-danger';
                    } elseif ($days_left <= 30) {
                        $card_class = 'border-warning';
                    }
                }
            ?>
            <div class="card mb-2 doc-card <?= $card_class ?>">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <i class="fas <?= ($doc['mime_type'] === 'application/pdf') ? 'fa-file-pdf text-danger' : 'fa-file-image text-info' ?> me-1"></i>
                            <strong><?= htmlspecialchars($doc['title']) ?></strong>
                            <span class="badge bg-<?= $category_colors[$doc['category']] ?? 'light' ?> ms-1">
                                <?= htmlspecialchars($category_labels[$doc['category']] ?? 'その他') ?>
                            </span>
                        </div>
                    </div>
                    <div class="small text-muted mb-2">
                        <?= htmlspecialchars($doc['original_filename']) ?>
                        <span class="ms-2"><?= formatFileSize($doc['file_size'] ?? 0) ?></span>
                    </div>
                    <?php if ($doc['expiry_date']): ?>
                        <?php
                            $expiry = strtotime($doc['expiry_date']);
                            $days_left = ($expiry - time()) / 86400;
                        ?>
                        <div class="small mb-2">
                            <i class="fas fa-calendar me-1"></i>期限：
                            <span class="<?= $days_left < 0 ? 'text-danger fw-bold' : ($days_left <= 30 ? 'text-warning fw-bold' : '') ?>">
                                <?= date('Y/m/d', $expiry) ?>
                                <?php if ($days_left < 0): ?>
                                    <span class="text-danger">（期限切れ）</span>
                                <?php elseif ($days_left <= 30): ?>
                                    <span class="text-warning">（あと<?= ceil($days_left) ?>日）</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($doc['driver_name'] || $doc['vehicle_number']): ?>
                        <div class="small text-muted mb-2">
                            <?php if ($doc['driver_name']): ?>
                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($doc['driver_name']) ?>
                            <?php endif; ?>
                            <?php if ($doc['vehicle_number']): ?>
                                <i class="fas fa-car me-1 ms-2"></i><?= htmlspecialchars($doc['vehicle_number']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-fill"
                                onclick="previewDocument(<?= $doc['id'] ?>, '<?= htmlspecialchars($doc['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($doc['mime_type'], ENT_QUOTES) ?>')">
                            <i class="fas fa-eye me-1"></i>プレビュー
                        </button>
                        <a href="api/document_download.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-outline-success flex-fill">
                            <i class="fas fa-download me-1"></i>DL
                        </a>
                        <button class="btn btn-sm btn-outline-danger"
                                onclick="deleteDocument(<?= $doc['id'] ?>, '<?= htmlspecialchars($doc['title'], ENT_QUOTES) ?>')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<!-- アップロードモーダル -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content unified-modal">
            <div class="modal-header unified-modal-header">
                <h5 class="modal-title"><i class="fas fa-upload me-2"></i>書類をアップロード</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="uploadForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="uploadTitle" class="form-label unified-label">
                            <i class="fas fa-heading me-1"></i>タイトル <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control unified-input" id="uploadTitle" name="title" required
                               placeholder="例: 一般乗用旅客自動車運送事業許可証">
                    </div>
                    <div class="mb-3">
                        <label for="uploadCategory" class="form-label unified-label">
                            <i class="fas fa-tag me-1"></i>カテゴリ <span class="text-danger">*</span>
                        </label>
                        <select class="form-select unified-select" id="uploadCategory" name="category" required>
                            <option value="">選択してください</option>
                            <?php foreach ($category_labels as $key => $label): ?>
                            <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="uploadFile" class="form-label unified-label">
                            <i class="fas fa-file me-1"></i>ファイル <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control unified-input" id="uploadFile" name="file" required
                               accept=".pdf,.jpg,.jpeg,.png">
                        <div class="form-text">PDF, JPG, PNG形式（最大10MB）</div>
                    </div>
                    <div class="mb-3">
                        <label for="uploadExpiry" class="form-label unified-label">
                            <i class="fas fa-calendar me-1"></i>有効期限
                        </label>
                        <input type="date" class="form-control unified-input" id="uploadExpiry" name="expiry_date">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="uploadDriver" class="form-label unified-label">
                                <i class="fas fa-user me-1"></i>関連乗務員
                            </label>
                            <select class="form-select unified-select" id="uploadDriver" name="related_driver_id">
                                <option value="">なし</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="uploadVehicle" class="form-label unified-label">
                                <i class="fas fa-car me-1"></i>関連車両
                            </label>
                            <select class="form-select unified-select" id="uploadVehicle" name="related_vehicle_id">
                                <option value="">なし</option>
                                <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['vehicle_number'] . ' ' . ($v['model'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="uploadNotes" class="form-label unified-label">
                            <i class="fas fa-sticky-note me-1"></i>メモ
                        </label>
                        <textarea class="form-control unified-input" id="uploadNotes" name="notes" rows="2"
                                  placeholder="補足事項があれば入力"></textarea>
                    </div>
                </div>
                <div class="modal-footer unified-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>キャンセル
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload me-1"></i>アップロード
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- プレビューモーダル -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" id="previewBody">
                <!-- iframe(PDF) or img を動的に挿入 -->
            </div>
            <div class="modal-footer">
                <a id="previewDownload" class="btn btn-primary" target="_blank">
                    <i class="fas fa-download me-1"></i>ダウンロード
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// フィルター適用
function applyFilter(key, value) {
    var params = new URLSearchParams(window.location.search);
    if (value) {
        params.set(key, value);
    } else {
        params.delete(key);
    }
    window.location.href = '?' + params.toString();
}

// アップロード送信
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var formData = new FormData(this);
    var btn = this.querySelector('button[type="submit"]');
    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>アップロード中...';

    fetch('api/document_upload.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (data.success) {
                showToast('書類をアップロードしました', 'success');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showToast(data.message || 'エラーが発生しました', 'danger');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            showToast('通信エラーが発生しました', 'danger');
        });
});

// プレビュー表示
function previewDocument(id, title, mimeType) {
    document.getElementById('previewTitle').textContent = title;
    var body = document.getElementById('previewBody');
    if (mimeType === 'application/pdf') {
        body.innerHTML = '<iframe src="api/document_preview.php?id=' + id + '" style="width:100%;height:70vh;border:none"></iframe>';
    } else {
        body.innerHTML = '<img src="api/document_preview.php?id=' + id + '" style="max-width:100%;max-height:70vh">';
    }
    document.getElementById('previewDownload').href = 'api/document_download.php?id=' + id;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}

// 削除
function deleteDocument(id, title) {
    showConfirm('「' + title + '」を削除しますか？', function() {
        fetch('api/document_delete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id + '&csrf_token=' + encodeURIComponent(document.querySelector('[name="csrf_token"]').value)
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.success) {
                showToast('削除しました', 'success');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showToast(data.message || 'エラーが発生しました', 'danger');
            }
        });
    }, { type: 'danger', confirmText: '削除する' });
}
</script>

<?php echo $page_data['html_footer']; ?>
