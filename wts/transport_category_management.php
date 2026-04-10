<?php
/**
 * 輸送分類管理
 * 通院・外出等の輸送分類の追加・編集・並び順設定
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

if ($user_role !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add':
                $name = trim($_POST['category_name']);
                $code = trim($_POST['category_code'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if (empty($name)) throw new Exception('分類名は必須です。');

                // 重複チェック
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM transport_categories WHERE category_name = ?");
                $stmt->execute([$name]);
                if ($stmt->fetchColumn() > 0) throw new Exception('この分類名は既に登録されています。');

                // 最大sort_order取得
                $max_sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM transport_categories")->fetchColumn();

                $stmt = $pdo->prepare("INSERT INTO transport_categories (category_name, category_code, description, sort_order) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $code, $desc, $max_sort + 1]);
                $message = "「{$name}」を追加しました。";
                break;

            case 'edit':
                $id = (int)$_POST['category_id'];
                $name = trim($_POST['category_name']);
                $code = trim($_POST['category_code'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if (empty($name)) throw new Exception('分類名は必須です。');

                // 重複チェック（自分以外）
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM transport_categories WHERE category_name = ? AND id != ?");
                $stmt->execute([$name, $id]);
                if ($stmt->fetchColumn() > 0) throw new Exception('この分類名は既に登録されています。');

                $stmt = $pdo->prepare("UPDATE transport_categories SET category_name = ?, category_code = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $code, $desc, $id]);
                $message = "「{$name}」を更新しました。";
                break;

            case 'toggle':
                $id = (int)$_POST['category_id'];
                $stmt = $pdo->prepare("UPDATE transport_categories SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$id]);
                $message = "状態を変更しました。";
                break;

            case 'reorder':
                $order = json_decode($_POST['order'] ?? '[]', true);
                if (is_array($order)) {
                    $stmt = $pdo->prepare("UPDATE transport_categories SET sort_order = ? WHERE id = ?");
                    foreach ($order as $i => $id) {
                        $stmt->execute([$i + 1, (int)$id]);
                    }
                    $message = "並び順を更新しました。";
                }
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// データ取得
$categories = $pdo->query("SELECT * FROM transport_categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

// ページヘッダー
$page_data = renderCompletePage(
    '輸送分類管理',
    $user_name, $user_role, 'transport_category_management',
    'fas fa-tags', '輸送分類管理', '通院・外出等の輸送分類設定', 'master',
    [
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => 'マスタ管理', 'url' => 'master_menu.php'],
            ['text' => '輸送分類管理']
        ]
    ]
);
?>
<?= $page_data['html_head'] ?>
<style>
.category-card {
    background: white; border-radius: 10px; padding: 1rem 1.25rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid rgba(0,0,0,0.06);
    display: flex; align-items: center; gap: 1rem;
    transition: all 0.2s;
}
.category-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.category-card.inactive { opacity: 0.5; }
.drag-handle { cursor: grab; color: #adb5bd; font-size: 1.2rem; }
.drag-handle:active { cursor: grabbing; }
.category-name { font-weight: 600; font-size: 1rem; }
.category-code { color: #6c757d; font-size: 0.85rem; font-family: monospace; }
.category-desc { color: #6c757d; font-size: 0.85rem; }
.category-actions { margin-left: auto; display: flex; gap: 0.5rem; }
.sortable-ghost { opacity: 0.3; }
</style>
</head>
<body>
    <?= $page_data['system_header'] ?>
    <?= $page_data['page_header'] ?>

<main class="main-content">
<div class="container-fluid px-4">

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

    <div class="row">
        <!-- 分類一覧 -->
        <div class="col-lg-8 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>登録済み分類</h5>
                <button id="saveOrderBtn" class="btn btn-outline-primary btn-sm" style="display:none;" onclick="saveOrder()">
                    <i class="fas fa-save me-1"></i>並び順を保存
                </button>
            </div>

            <div id="categoryList" class="d-flex flex-column gap-2">
                <?php foreach ($categories as $cat): ?>
                <div class="category-card <?= $cat['is_active'] ? '' : 'inactive' ?>" data-id="<?= $cat['id'] ?>">
                    <div class="drag-handle" title="ドラッグで並び替え"><i class="fas fa-grip-vertical"></i></div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="category-name"><?= htmlspecialchars($cat['category_name']) ?></span>
                            <?php if ($cat['category_code']): ?>
                                <span class="category-code">[<?= htmlspecialchars($cat['category_code']) ?>]</span>
                            <?php endif; ?>
                            <?php if (!$cat['is_active']): ?>
                                <span class="badge bg-secondary">無効</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($cat['description']): ?>
                            <div class="category-desc"><?= htmlspecialchars($cat['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="category-actions">
                        <button class="btn btn-outline-secondary btn-sm" onclick="editCategory(<?= htmlspecialchars(json_encode($cat)) ?>)" title="編集">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('状態を変更しますか？')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                            <button type="submit" class="btn btn-outline-<?= $cat['is_active'] ? 'warning' : 'success' ?> btn-sm"
                                    title="<?= $cat['is_active'] ? '無効化' : '有効化' ?>">
                                <i class="fas fa-<?= $cat['is_active'] ? 'ban' : 'check' ?>"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($categories)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-2x mb-2"></i>
                    <p>分類が登録されていません</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- 追加・編集フォーム -->
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0" id="formTitle"><i class="fas fa-plus me-2"></i>分類を追加</h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="categoryForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="add" id="formAction">
                        <input type="hidden" name="category_id" value="" id="formCategoryId">

                        <div class="mb-3">
                            <label class="form-label">分類名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="category_name" id="categoryName" required placeholder="例: 通院">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">コード</label>
                            <input type="text" class="form-control" name="category_code" id="categoryCode" maxlength="10" placeholder="例: MED">
                            <small class="text-muted">帳票出力時に使用（任意）</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">説明</label>
                            <textarea class="form-control" name="description" id="categoryDesc" rows="2" placeholder="補足説明（任意）"></textarea>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success" id="formSubmitBtn">
                                <i class="fas fa-plus me-1"></i>追加
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="cancelEditBtn" style="display:none;" onclick="cancelEdit()">
                                キャンセル
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h6 class="mb-2"><i class="fas fa-info-circle text-info me-2"></i>使い方</h6>
                    <ul class="small mb-0">
                        <li>乗車記録の輸送目的として選択されます</li>
                        <li>事業報告書（第4号様式）の分類に使用</li>
                        <li>ドラッグで表示順を変更できます</li>
                        <li>無効化すると選択肢から非表示になります</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-3">
        <a href="master_menu.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>マスタ管理に戻る</a>
    </div>

</div>
</main>

<!-- 並び順保存フォーム -->
<form method="POST" id="reorderForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="action" value="reorder">
    <input type="hidden" name="order" id="reorderInput">
</form>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// ドラッグ＆ドロップ並び替え
const list = document.getElementById('categoryList');
if (list) {
    new Sortable(list, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: function() {
            document.getElementById('saveOrderBtn').style.display = '';
        }
    });
}

function saveOrder() {
    const items = list.querySelectorAll('.category-card');
    const order = Array.from(items).map(el => el.dataset.id);
    document.getElementById('reorderInput').value = JSON.stringify(order);
    document.getElementById('reorderForm').submit();
}

function editCategory(cat) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formCategoryId').value = cat.id;
    document.getElementById('categoryName').value = cat.category_name;
    document.getElementById('categoryCode').value = cat.category_code || '';
    document.getElementById('categoryDesc').value = cat.description || '';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit me-2"></i>分類を編集';
    document.getElementById('formSubmitBtn').innerHTML = '<i class="fas fa-save me-1"></i>更新';
    document.getElementById('formSubmitBtn').classList.replace('btn-success', 'btn-primary');
    document.getElementById('cancelEditBtn').style.display = '';
}

function cancelEdit() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('formCategoryId').value = '';
    document.getElementById('categoryName').value = '';
    document.getElementById('categoryCode').value = '';
    document.getElementById('categoryDesc').value = '';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus me-2"></i>分類を追加';
    document.getElementById('formSubmitBtn').innerHTML = '<i class="fas fa-plus me-1"></i>追加';
    document.getElementById('formSubmitBtn').classList.replace('btn-primary', 'btn-success');
    document.getElementById('cancelEditBtn').style.display = 'none';
}
</script>

<?php echo $page_data['html_footer']; ?>
