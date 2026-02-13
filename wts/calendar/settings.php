<?php
// =================================================================
// 予約項目カスタマイズ設定ページ
//
// ファイル: /calendar/settings.php
// 機能: 予約フォームの選択肢（サービス種別・支払方法等）を管理
// 作成日: 2026年2月13日
// =================================================================

session_start();

require_once '../config/database.php';
require_once '../includes/unified-header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDBConnection();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'] ?? 'User';

// フィールド定義
$field_definitions = [
    'service_type'   => ['label' => 'サービス種別', 'icon' => 'concierge-bell'],
    'rental_service' => ['label' => 'レンタルサービス', 'icon' => 'wheelchair'],
    'referrer_type'  => ['label' => '紹介者種別', 'icon' => 'user-tie'],
    'payment_method' => ['label' => '支払い方法', 'icon' => 'yen-sign'],
];

// テーブル自動作成（未作成の場合）
try {
    $pdo->query("SELECT 1 FROM reservation_field_options LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_field_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        field_name VARCHAR(50) NOT NULL,
        option_value VARCHAR(100) NOT NULL,
        option_label VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_field_option (field_name, option_value),
        INDEX idx_field_active (field_name, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // デフォルト選択肢を投入
    $defaults = [
        ['service_type', 'お迎え', 'お迎え', 1], ['service_type', '送り', '送り', 2],
        ['service_type', '往復', '往復', 3], ['service_type', '病院', '病院', 4],
        ['service_type', '買い物', '買い物', 5], ['service_type', 'その他', 'その他', 99],
        ['rental_service', 'なし', 'なし', 0], ['rental_service', '車椅子', '車椅子', 1],
        ['rental_service', 'ストレッチャー', 'ストレッチャー', 2], ['rental_service', '酸素ボンベ', '酸素ボンベ', 3],
        ['referrer_type', 'CM', 'CM (ケアマネージャー)', 1], ['referrer_type', 'MSW', 'MSW (医療ソーシャルワーカー)', 2],
        ['referrer_type', '病院', '病院', 3], ['referrer_type', '施設', '施設', 4],
        ['referrer_type', '個人', '個人', 5], ['referrer_type', 'その他', 'その他', 99],
        ['payment_method', '現金', '現金', 1], ['payment_method', 'クレジットカード', 'クレジットカード', 2],
        ['payment_method', '請求書', '請求書', 3], ['payment_method', '介護保険', '介護保険', 4],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO reservation_field_options (field_name, option_value, option_label, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($defaults as $d) { $stmt->execute($d); }
}

// 既存データ取得
$stmt = $pdo->query("SELECT * FROM reservation_field_options ORDER BY field_name, sort_order, id");
$all_options = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped_options = [];
foreach ($all_options as $opt) {
    $grouped_options[$opt['field_name']][] = $opt;
}

// ページ設定
$page_config = [
    'title'       => '予約項目カスタマイズ',
    'subtitle'    => '',
    'description' => '予約フォームの選択肢を管理',
    'icon'        => 'sliders-h',
    'category'    => '予約管理'
];

$cache_bust = function($path) {
    $full_path = __DIR__ . '/' . $path;
    $v = file_exists($full_path) ? filemtime($full_path) : time();
    return $path . '?v=' . $v;
};

$page_options = [
    'description'    => $page_config['description'],
    'additional_css' => [$cache_bust('css/calendar-custom.css')],
    'additional_js'  => [],
    'breadcrumb'     => [
        ['text' => 'ダッシュボード', 'url' => '../dashboard.php'],
        ['text' => '予約管理', 'url' => 'index.php'],
        ['text' => '項目カスタマイズ', 'url' => 'settings.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'calendar_system',
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

<main class="main-content">
    <div class="container-fluid py-4">

        <!-- 説明 -->
        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle me-2"></i>
            予約フォームの各ドロップダウンに表示される選択肢を追加・編集・並び替えできます。
        </div>

        <!-- フィールド別カード -->
        <?php foreach ($field_definitions as $field_name => $def): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-<?= $def['icon'] ?> me-2 text-primary"></i><?= htmlspecialchars($def['label']) ?>
                </h5>
                <button class="btn btn-sm btn-success btn-add-option" data-field="<?= $field_name ?>">
                    <i class="fas fa-plus me-1"></i>追加
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px"></th>
                            <th>値</th>
                            <th>表示ラベル</th>
                            <th style="width:80px" class="text-center">有効</th>
                            <th style="width:120px" class="text-center">操作</th>
                        </tr>
                    </thead>
                    <tbody id="options-<?= $field_name ?>" data-field="<?= $field_name ?>">
                        <?php
                        $options = $grouped_options[$field_name] ?? [];
                        if (empty($options)):
                        ?>
                        <tr class="empty-row">
                            <td colspan="5" class="text-center text-muted py-3">選択肢がありません</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($options as $opt): ?>
                        <tr data-id="<?= $opt['id'] ?>">
                            <td class="text-center text-muted" style="cursor:grab"><i class="fas fa-grip-vertical"></i></td>
                            <td>
                                <span class="display-value"><?= htmlspecialchars($opt['option_value']) ?></span>
                                <input type="text" class="form-control form-control-sm d-none edit-value" value="<?= htmlspecialchars($opt['option_value']) ?>">
                            </td>
                            <td>
                                <span class="display-label"><?= htmlspecialchars($opt['option_label']) ?></span>
                                <input type="text" class="form-control form-control-sm d-none edit-label" value="<?= htmlspecialchars($opt['option_label']) ?>">
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input toggle-active" type="checkbox" <?= $opt['is_active'] ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary btn-edit me-1" title="編集"><i class="fas fa-pen"></i></button>
                                <button class="btn btn-sm btn-outline-primary btn-save me-1 d-none" title="保存"><i class="fas fa-check"></i></button>
                                <button class="btn btn-sm btn-outline-secondary btn-cancel me-1 d-none" title="キャンセル"><i class="fas fa-times"></i></button>
                                <button class="btn btn-sm btn-outline-danger btn-delete" title="削除"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- カレンダーに戻る -->
        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>カレンダーに戻る
            </a>
        </div>
    </div>
</main>

<!-- 追加モーダル -->
<div class="modal fade" id="addOptionModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">選択肢を追加</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="addFieldName">
                <div class="mb-3">
                    <label class="form-label">値 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="addOptionValue" placeholder="例: 通院">
                </div>
                <div class="mb-3">
                    <label class="form-label">表示ラベル（空欄なら値と同じ）</label>
                    <input type="text" class="form-control" id="addOptionLabel" placeholder="例: 通院（定期）">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-success" id="confirmAddBtn">
                    <i class="fas fa-plus me-1"></i>追加
                </button>
            </div>
        </div>
    </div>
</div>

<?php echo $page_data['html_footer']; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const API_URL = 'api/save_field_options.php';

    // ========== API通信 ==========
    function apiCall(data) {
        return fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(r => r.json());
    }

    function showToast(msg, type) {
        const el = document.createElement('div');
        el.className = `alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show position-fixed`;
        el.style.cssText = 'top:20px;right:20px;z-index:9999;min-width:280px;';
        el.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3000);
    }

    // ========== 追加 ==========
    const addModal = new bootstrap.Modal(document.getElementById('addOptionModal'));

    document.querySelectorAll('.btn-add-option').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('addFieldName').value = this.dataset.field;
            document.getElementById('addOptionValue').value = '';
            document.getElementById('addOptionLabel').value = '';
            addModal.show();
        });
    });

    document.getElementById('confirmAddBtn').addEventListener('click', function() {
        const fieldName = document.getElementById('addFieldName').value;
        const value = document.getElementById('addOptionValue').value.trim();
        const label = document.getElementById('addOptionLabel').value.trim();

        if (!value) { showToast('値を入力してください', 'error'); return; }

        apiCall({
            action: 'add',
            field_name: fieldName,
            option_value: value,
            option_label: label || value
        }).then(res => {
            if (res.success) {
                showToast(res.message, 'success');
                addModal.hide();
                location.reload();
            } else {
                showToast(res.message, 'error');
            }
        });
    });

    // ========== 編集 ==========
    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', function() {
            const row = this.closest('tr');
            row.querySelectorAll('.display-value, .display-label').forEach(el => el.classList.add('d-none'));
            row.querySelectorAll('.edit-value, .edit-label').forEach(el => el.classList.remove('d-none'));
            row.querySelector('.btn-edit').classList.add('d-none');
            row.querySelector('.btn-save').classList.remove('d-none');
            row.querySelector('.btn-cancel').classList.remove('d-none');
        });
    });

    document.querySelectorAll('.btn-cancel').forEach(btn => {
        btn.addEventListener('click', function() {
            const row = this.closest('tr');
            row.querySelectorAll('.display-value, .display-label').forEach(el => el.classList.remove('d-none'));
            row.querySelectorAll('.edit-value, .edit-label').forEach(el => el.classList.add('d-none'));
            row.querySelector('.btn-edit').classList.remove('d-none');
            row.querySelector('.btn-save').classList.add('d-none');
            row.querySelector('.btn-cancel').classList.add('d-none');

            // 元の値に戻す
            row.querySelector('.edit-value').value = row.querySelector('.display-value').textContent;
            row.querySelector('.edit-label').value = row.querySelector('.display-label').textContent;
        });
    });

    document.querySelectorAll('.btn-save').forEach(btn => {
        btn.addEventListener('click', function() {
            const row = this.closest('tr');
            const id = row.dataset.id;
            const newValue = row.querySelector('.edit-value').value.trim();
            const newLabel = row.querySelector('.edit-label').value.trim();

            if (!newValue) { showToast('値は必須です', 'error'); return; }

            apiCall({
                action: 'update',
                id: id,
                option_value: newValue,
                option_label: newLabel || newValue
            }).then(res => {
                if (res.success) {
                    showToast(res.message, 'success');
                    row.querySelector('.display-value').textContent = newValue;
                    row.querySelector('.display-label').textContent = newLabel || newValue;

                    row.querySelectorAll('.display-value, .display-label').forEach(el => el.classList.remove('d-none'));
                    row.querySelectorAll('.edit-value, .edit-label').forEach(el => el.classList.add('d-none'));
                    row.querySelector('.btn-edit').classList.remove('d-none');
                    row.querySelector('.btn-save').classList.add('d-none');
                    row.querySelector('.btn-cancel').classList.add('d-none');
                } else {
                    showToast(res.message, 'error');
                }
            });
        });
    });

    // ========== 有効/無効切り替え ==========
    document.querySelectorAll('.toggle-active').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const id = this.closest('tr').dataset.id;
            apiCall({ action: 'update', id: id, is_active: this.checked ? 1 : 0 })
                .then(res => {
                    if (!res.success) {
                        showToast(res.message, 'error');
                        this.checked = !this.checked;
                    }
                });
        });
    });

    // ========== 削除 ==========
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('この選択肢を削除しますか？')) return;

            const row = this.closest('tr');
            const id = row.dataset.id;

            apiCall({ action: 'delete', id: id }).then(res => {
                if (res.success) {
                    showToast(res.message, 'success');
                    row.remove();
                } else {
                    showToast(res.message, 'error');
                }
            });
        });
    });
});
</script>
</body>
</html>
