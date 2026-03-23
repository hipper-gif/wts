<?php
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/session_check.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 管理者権限チェック - 柔軟な権限確認
$has_admin_permission = false;
if (isset($_SESSION['permission_level']) && $_SESSION['permission_level'] === 'Admin') {
    $has_admin_permission = true;
} elseif (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    $has_admin_permission = true;
} elseif (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
    $has_admin_permission = true;
} elseif (isset($_SESSION['is_manager']) && $_SESSION['is_manager'] == 1) {
    $has_admin_permission = true;
}

if (!$has_admin_permission) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'ユーザー';
$user_role = $_SESSION['permission_level'] ?? $_SESSION['user_role'] ?? 'User';

// 統一ヘッダーシステム
require_once 'includes/unified-header.php';
$page_config = getPageConfiguration('location_management');

// 監査ログ関数
function logLocationAudit($pdo, $location_id, $action, $user_id, $changes = [], $reason = null) {
    logAudit($pdo, $location_id, $action, $user_id, 'location', $changes, $reason);
}

$success_message = '';
$error_message = '';

// ホワイトリスト定義
$valid_location_types = ['hospital', 'clinic', 'care_facility', 'home', 'station', 'pharmacy', 'other'];

// 種別の日本語マッピング
$location_types = [
    'hospital' => '病院',
    'clinic' => 'クリニック',
    'care_facility' => '介護施設',
    'home' => '自宅',
    'station' => '駅',
    'pharmacy' => '薬局',
    'other' => 'その他'
];

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $name = trim($_POST['name']);
            $name_kana = trim($_POST['name_kana']) ?: null;
            $location_type = in_array($_POST['location_type'] ?? '', $valid_location_types) ? $_POST['location_type'] : 'other';
            $postal_code = trim($_POST['postal_code']) ?: null;
            $address = trim($_POST['address']) ?: null;
            $phone = trim($_POST['phone']) ?: null;
            $notes = trim($_POST['notes']) ?: null;

            if (empty($name)) {
                throw new Exception('場所名は必須です。');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO location_master (
                        name, name_kana, location_type, postal_code, address,
                        phone, notes, usage_count, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())
                ");
                $stmt->execute([
                    $name, $name_kana, $location_type, $postal_code, $address,
                    $phone, $notes
                ]);
                $new_id = $pdo->lastInsertId();

                logLocationAudit($pdo, $new_id, 'create', $user_id);

                $pdo->commit();
                $success_message = "場所「{$name}」を追加しました。";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'edit') {
            $location_id = intval($_POST['location_id']);
            $name = trim($_POST['name']);
            $name_kana = trim($_POST['name_kana']) ?: null;
            $location_type = in_array($_POST['location_type'] ?? '', $valid_location_types) ? $_POST['location_type'] : 'other';
            $postal_code = trim($_POST['postal_code']) ?: null;
            $address = trim($_POST['address']) ?: null;
            $phone = trim($_POST['phone']) ?: null;
            $notes = trim($_POST['notes']) ?: null;

            if (empty($name)) {
                throw new Exception('場所名は必須です。');
            }

            // 既存データ取得（変更差分記録用）
            $stmt = $pdo->prepare("SELECT * FROM location_master WHERE id = ?");
            $stmt->execute([$location_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new Exception('場所が見つかりません。');
            }

            // 変更差分を検出
            $changes = [];
            $field_map = [
                'name' => $name, 'name_kana' => $name_kana,
                'location_type' => $location_type, 'postal_code' => $postal_code,
                'address' => $address, 'phone' => $phone, 'notes' => $notes
            ];
            foreach ($field_map as $field => $new_val) {
                $old_val = $existing[$field] ?? '';
                if (strval($old_val) !== strval($new_val ?? '')) {
                    $changes[] = ['field' => $field, 'old' => $old_val, 'new' => $new_val];
                }
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE location_master SET
                        name = ?, name_kana = ?, location_type = ?, postal_code = ?,
                        address = ?, phone = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name, $name_kana, $location_type, $postal_code,
                    $address, $phone, $notes, $location_id
                ]);

                logLocationAudit($pdo, $location_id, 'edit', $user_id, $changes);

                $pdo->commit();
                $success_message = "場所「{$name}」を更新しました。";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'delete') {
            $location_id = intval($_POST['location_id']);

            // 場所名取得（ログ・メッセージ用）
            $stmt = $pdo->prepare("SELECT name FROM location_master WHERE id = ?");
            $stmt->execute([$location_id]);
            $del_name = $stmt->fetchColumn() ?: '不明';

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE location_master SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$location_id]);

                logLocationAudit($pdo, $location_id, 'delete', $user_id, [], '論理削除');

                $pdo->commit();
                $success_message = "場所「{$del_name}」を削除しました。";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// フィルター・検索条件
$filter_type = $_GET['type'] ?? '';
$search_name = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'name';

// 場所一覧取得
$where_clauses = ['l.is_active = 1'];
$params = [];

if ($filter_type && in_array($filter_type, $valid_location_types)) {
    $where_clauses[] = 'l.location_type = ?';
    $params[] = $filter_type;
}

if ($search_name !== '') {
    $where_clauses[] = '(l.name LIKE ? OR l.name_kana LIKE ? OR l.address LIKE ?)';
    $search_like = '%' . $search_name . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

$where_sql = implode(' AND ', $where_clauses);

$order_sql = 'l.name ASC';
if ($sort === 'usage_count') {
    $order_sql = 'l.usage_count DESC, l.name ASC';
} elseif ($sort === 'type') {
    $order_sql = 'l.location_type ASC, l.name ASC';
}

$stmt = $pdo->prepare("
    SELECT l.*
    FROM location_master l
    WHERE {$where_sql}
    ORDER BY {$order_sql}
");
$stmt->execute($params);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報取得
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_locations,
        SUM(CASE WHEN location_type IN ('hospital', 'clinic') THEN 1 ELSE 0 END) as hospital_clinic_count,
        SUM(CASE WHEN location_type = 'care_facility' THEN 1 ELSE 0 END) as care_facility_count,
        SUM(CASE WHEN location_type NOT IN ('hospital', 'clinic', 'care_facility') THEN 1 ELSE 0 END) as other_count
    FROM location_master
    WHERE is_active = 1
");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// 種別バッジカラー
$type_badge_colors = [
    'hospital' => 'danger',
    'clinic' => 'warning',
    'care_facility' => 'info',
    'home' => 'success',
    'station' => 'primary',
    'pharmacy' => 'secondary',
    'other' => 'dark'
];

// ページオプション設定
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
    ],
    'additional_js' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
        'js/ui-interactions.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '管理機能', 'url' => 'master_menu.php'],
        ['text' => '場所マスタ管理', 'url' => 'location_management.php']
    ]
];

// 完全ページ生成（統一ヘッダーシステム使用）
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'location_management',
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
    <!-- アラート（統一パターン） -->
    <?php if ($success_message): ?>
        <?= renderAlert('success', '操作完了', $success_message) ?>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <?= renderAlert('danger', 'エラー', $error_message) ?>
    <?php endif; ?>

    <!-- 統計情報ダッシュボード -->
    <div class="row mb-4 g-2">
        <div class="col-6 col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">総場所数</div>
                            <h3 class="mb-0"><?= $stats['total_locations'] ?></h3>
                        </div>
                        <i class="fas fa-map-marker-alt fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">病院・クリニック</div>
                            <h3 class="mb-0"><?= $stats['hospital_clinic_count'] ?></h3>
                        </div>
                        <i class="fas fa-hospital fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">介護施設</div>
                            <h3 class="mb-0"><?= $stats['care_facility_count'] ?></h3>
                        </div>
                        <i class="fas fa-hands-helping fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-secondary text-white">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">その他</div>
                            <h3 class="mb-0"><?= $stats['other_count'] ?></h3>
                        </div>
                        <i class="fas fa-building fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- フィルター・検索 -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label unified-label small mb-1">種別フィルター</label>
                    <select class="form-select form-select-sm unified-select" name="type" onchange="this.form.submit()">
                        <option value="">すべて</option>
                        <?php foreach ($location_types as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filter_type === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label unified-label small mb-1">名前・住所で検索</label>
                    <input type="text" class="form-control form-control-sm unified-input" name="search"
                           value="<?= htmlspecialchars($search_name) ?>" placeholder="場所名・かな・住所で検索">
                </div>
                <div class="col-md-3">
                    <label class="form-label unified-label small mb-1">並び順</label>
                    <select class="form-select form-select-sm unified-select" name="sort" onchange="this.form.submit()">
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>名前順</option>
                        <option value="usage_count" <?= $sort === 'usage_count' ? 'selected' : '' ?>>利用回数順</option>
                        <option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>種別順</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        <i class="fas fa-search me-1"></i>検索
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 場所一覧 -->
    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>場所一覧</h5>
            <button type="button" class="btn btn-primary btn-sm" onclick="showAddModal()">
                <i class="fas fa-plus me-1"></i>新規場所追加
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (empty($locations)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">場所が登録されていません</h5>
                    <p class="text-muted mb-0">「新規場所追加」ボタンから場所を登録してください。</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>場所名</th>
                                <th>種別</th>
                                <th class="d-none d-md-table-cell">住所</th>
                                <th class="d-none d-md-table-cell">電話番号</th>
                                <th class="text-end">利用回数</th>
                                <th class="text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $location): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary" style="cursor:pointer;"
                                            onclick="editLocation(<?= htmlspecialchars(json_encode($location), ENT_QUOTES) ?>)">
                                        <?= htmlspecialchars($location['name']) ?>
                                    </strong>
                                    <?php if ($location['name_kana']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($location['name_kana']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $type_badge_colors[$location['location_type']] ?? 'dark' ?>">
                                        <?= $location_types[$location['location_type']] ?? 'その他' ?>
                                    </span>
                                </td>
                                <td class="d-none d-md-table-cell text-muted">
                                    <?= htmlspecialchars($location['address'] ?? '-') ?>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?= htmlspecialchars($location['phone'] ?? '-') ?>
                                </td>
                                <td class="text-end font-monospace">
                                    <?= number_format($location['usage_count'] ?? 0) ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary"
                                                onclick="editLocation(<?= htmlspecialchars(json_encode($location), ENT_QUOTES) ?>)"
                                                title="編集">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger"
                                                onclick="deleteLocation(<?= $location['id'] ?>, '<?= htmlspecialchars($location['name'], ENT_QUOTES) ?>')"
                                                title="削除">
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
</main>

<!-- 場所追加・編集モーダル（統一UIパターン） -->
<div class="modal fade" id="locationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content unified-modal">
            <div class="modal-header unified-modal-header">
                <h5 class="modal-title" id="locationModalTitle">
                    <i class="fas fa-map-marker-alt me-2"></i>場所追加
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="locationForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="location_id" id="modalLocationId">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalName" class="form-label unified-label">
                                <i class="fas fa-map-marker-alt me-1"></i>場所名 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control unified-input" id="modalName"
                                   name="name" required placeholder="例: ○○病院">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalNameKana" class="form-label unified-label">
                                <i class="fas fa-font me-1"></i>ふりがな
                            </label>
                            <input type="text" class="form-control unified-input" id="modalNameKana"
                                   name="name_kana" placeholder="例: まるまるびょういん">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalLocationType" class="form-label unified-label">
                                <i class="fas fa-tag me-1"></i>種別
                            </label>
                            <select class="form-select unified-select" id="modalLocationType" name="location_type">
                                <?php foreach ($location_types as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalPostalCode" class="form-label unified-label">
                                <i class="fas fa-envelope me-1"></i>郵便番号
                            </label>
                            <input type="text" class="form-control unified-input" id="modalPostalCode"
                                   name="postal_code" placeholder="例: 123-4567">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="modalAddress" class="form-label unified-label">
                                <i class="fas fa-home me-1"></i>住所
                            </label>
                            <input type="text" class="form-control unified-input" id="modalAddress"
                                   name="address" placeholder="例: 大阪府大阪市中央区○○1-2-3">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalPhone" class="form-label unified-label">
                                <i class="fas fa-phone me-1"></i>電話番号
                            </label>
                            <input type="text" class="form-control unified-input" id="modalPhone"
                                   name="phone" placeholder="例: 06-1234-5678">
                        </div>
                        <div class="col-md-6 mb-3">
                            <!-- 空きスペース -->
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="modalNotes" class="form-label unified-label">
                                <i class="fas fa-sticky-note me-1"></i>備考
                            </label>
                            <textarea class="form-control unified-input" id="modalNotes"
                                      name="notes" rows="3" placeholder="メモや注意事項など"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer unified-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>キャンセル
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 新規追加モーダル表示
function showAddModal() {
    document.getElementById('locationModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>場所追加';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalLocationId').value = '';

    document.getElementById('locationForm').reset();

    new bootstrap.Modal(document.getElementById('locationModal')).show();
}

// 編集モーダル表示
function editLocation(location) {
    document.getElementById('locationModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>場所編集';
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalLocationId').value = location.id;
    document.getElementById('modalName').value = location.name || '';
    document.getElementById('modalNameKana').value = location.name_kana || '';
    document.getElementById('modalLocationType').value = location.location_type || 'other';
    document.getElementById('modalPostalCode').value = location.postal_code || '';
    document.getElementById('modalAddress').value = location.address || '';
    document.getElementById('modalPhone').value = location.phone || '';
    document.getElementById('modalNotes').value = location.notes || '';

    new bootstrap.Modal(document.getElementById('locationModal')).show();
}

// 削除確認（統一パターン）
function deleteLocation(locationId, locationName) {
    showConfirm('場所「' + locationName + '」を削除しますか？\n※論理削除のため、後で復旧可能です。', function() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML =
            '<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">' +
            '<input type="hidden" name="action" value="delete">' +
            '<input type="hidden" name="location_id" value="' + locationId + '">';
        document.body.appendChild(form);
        form.submit();
    }, {
        type: 'danger',
        confirmText: '削除する'
    });
}
</script>

<?php echo $page_data['html_footer']; ?>
