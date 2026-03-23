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
$page_config = getPageConfiguration('vehicle_management');

// 監査ログ関数
function logVehicleAudit($pdo, $vehicle_id, $action, $user_id, $changes = [], $reason = null) {
    logAudit($pdo, $vehicle_id, $action, $user_id, 'vehicle', $changes, $reason);
}

$success_message = '';
$error_message = '';

// ホワイトリスト定義
$valid_vehicle_types = ['welfare', 'taxi', 'other'];
$valid_statuses = ['active', 'maintenance', 'reserved', 'inactive'];

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $vehicle_number = trim($_POST['vehicle_number']);
            $vehicle_name = trim($_POST['vehicle_name']);
            $model = trim($_POST['model']) ?: null;
            $registration_date = $_POST['registration_date'] ?: null;
            $vehicle_type = in_array($_POST['vehicle_type'] ?? '', $valid_vehicle_types) ? $_POST['vehicle_type'] : 'welfare';
            $capacity = intval($_POST['capacity']) ?: 4;
            $current_mileage = intval($_POST['current_mileage']) ?: 0;
            $next_inspection_date = $_POST['next_inspection_date'] ?: null;
            $status = in_array($_POST['status'] ?? '', $valid_statuses) ? $_POST['status'] : 'active';

            if (empty($vehicle_number) || empty($vehicle_name)) {
                throw new Exception('車両番号と車両名は必須です。');
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_number = ? AND is_active = 1");
            $stmt->execute([$vehicle_number]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両番号は既に使用されています。');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles (
                        vehicle_number, vehicle_name, model, registration_date,
                        vehicle_type, capacity, current_mileage, next_inspection_date,
                        status, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt->execute([
                    $vehicle_number, $vehicle_name, $model, $registration_date,
                    $vehicle_type, $capacity, $current_mileage, $next_inspection_date,
                    $status
                ]);
                $new_id = $pdo->lastInsertId();

                logVehicleAudit($pdo, $new_id, 'create', $user_id);

                $pdo->commit();
                $success_message = "車両「{$vehicle_number}」を追加しました。";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'edit') {
            $vehicle_id = intval($_POST['vehicle_id']);
            $vehicle_number = trim($_POST['vehicle_number']);
            $vehicle_name = trim($_POST['vehicle_name']);
            $model = trim($_POST['model']) ?: null;
            $registration_date = $_POST['registration_date'] ?: null;
            $vehicle_type = in_array($_POST['vehicle_type'] ?? '', $valid_vehicle_types) ? $_POST['vehicle_type'] : 'welfare';
            $capacity = intval($_POST['capacity']) ?: 4;
            $current_mileage = intval($_POST['current_mileage']) ?: 0;
            $next_inspection_date = $_POST['next_inspection_date'] ?: null;
            $status = in_array($_POST['status'] ?? '', $valid_statuses) ? $_POST['status'] : 'active';
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($vehicle_number) || empty($vehicle_name)) {
                throw new Exception('車両番号と車両名は必須です。');
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_number = ? AND id != ? AND is_active = 1");
            $stmt->execute([$vehicle_number, $vehicle_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両番号は既に使用されています。');
            }

            // 既存データ取得（変更差分記録用）
            $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new Exception('車両が見つかりません。');
            }

            // 変更差分を検出
            $changes = [];
            $field_map = [
                'vehicle_number' => $vehicle_number, 'vehicle_name' => $vehicle_name,
                'model' => $model, 'registration_date' => $registration_date,
                'vehicle_type' => $vehicle_type, 'capacity' => $capacity,
                'current_mileage' => $current_mileage, 'next_inspection_date' => $next_inspection_date,
                'status' => $status, 'is_active' => $is_active
            ];
            foreach ($field_map as $field => $new_val) {
                $old_val = $existing[$field] ?? '';
                if (strval($old_val) !== strval($new_val)) {
                    $changes[] = ['field' => $field, 'old' => $old_val, 'new' => $new_val];
                }
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE vehicles SET
                        vehicle_number = ?, vehicle_name = ?, model = ?, registration_date = ?,
                        vehicle_type = ?, capacity = ?, current_mileage = ?, next_inspection_date = ?,
                        status = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $vehicle_number, $vehicle_name, $model, $registration_date,
                    $vehicle_type, $capacity, $current_mileage, $next_inspection_date,
                    $status, $is_active, $vehicle_id
                ]);

                logVehicleAudit($pdo, $vehicle_id, 'edit', $user_id, $changes);

                $pdo->commit();
                $success_message = "車両「{$vehicle_number}」を更新しました。";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'delete') {
            $vehicle_id = intval($_POST['vehicle_id']);

            // 使用中チェック
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM departure_records
                WHERE vehicle_id = ? AND departure_date = CURDATE()
            ");
            $stmt->execute([$vehicle_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('この車両は本日使用中のため削除できません。');
            }

            // 車両名取得（ログ・メッセージ用）
            $stmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            $del_vehicle_number = $stmt->fetchColumn() ?: '不明';

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE vehicles SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$vehicle_id]);

                logVehicleAudit($pdo, $vehicle_id, 'delete', $user_id, [], '論理削除');

                $pdo->commit();
                $success_message = "車両「{$del_vehicle_number}」を削除しました。";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'update_inspection') {
            $vehicle_id = intval($_POST['vehicle_id']);
            $next_inspection_date = $_POST['next_inspection_date'];

            if (!$next_inspection_date) {
                throw new Exception('点検日を入力してください。');
            }

            // 既存値取得
            $stmt = $pdo->prepare("SELECT next_inspection_date, vehicle_number FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE vehicles SET
                        next_inspection_date = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$next_inspection_date, $vehicle_id]);

                $changes = [['field' => 'next_inspection_date', 'old' => $existing['next_inspection_date'] ?? '', 'new' => $next_inspection_date]];
                logVehicleAudit($pdo, $vehicle_id, 'edit', $user_id, $changes, '点検期限更新');

                $pdo->commit();
                $success_message = "「{$existing['vehicle_number']}」の点検期限を更新しました。";
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 車両一覧取得
$stmt = $pdo->prepare("
    SELECT v.*,
           DATEDIFF(v.next_inspection_date, CURDATE()) as days_to_inspection,
           CASE
               WHEN v.next_inspection_date IS NULL THEN 'unknown'
               WHEN DATEDIFF(v.next_inspection_date, CURDATE()) < 0 THEN 'overdue'
               WHEN DATEDIFF(v.next_inspection_date, CURDATE()) <= 7 THEN 'urgent'
               WHEN DATEDIFF(v.next_inspection_date, CURDATE()) <= 30 THEN 'warning'
               ELSE 'ok'
           END as inspection_status
    FROM vehicles v
    WHERE v.is_active = 1
    ORDER BY v.vehicle_number
");
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報取得
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_vehicles,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_vehicles,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_vehicles,
        SUM(CASE WHEN next_inspection_date IS NOT NULL AND DATEDIFF(next_inspection_date, CURDATE()) <= 30 THEN 1 ELSE 0 END) as inspection_due_soon
    FROM vehicles
    WHERE is_active = 1
");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// ステータス・車両タイプ選択肢
$statuses = [
    'active' => '運用中',
    'maintenance' => '整備中',
    'reserved' => '予約済み',
    'inactive' => '使用停止'
];

$vehicle_types = [
    'welfare' => '福祉車両',
    'taxi' => 'タクシー',
    'other' => 'その他'
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
        ['text' => '車両管理', 'url' => 'vehicle_management.php']
    ]
];

// 完全ページ生成（統一ヘッダーシステム使用）
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'vehicle_management',
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
                            <div class="small opacity-75">総車両数</div>
                            <h3 class="mb-0"><?= $stats['total_vehicles'] ?></h3>
                        </div>
                        <i class="fas fa-car fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">運用中</div>
                            <h3 class="mb-0"><?= $stats['active_vehicles'] ?></h3>
                        </div>
                        <i class="fas fa-check-circle fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">整備中</div>
                            <h3 class="mb-0"><?= $stats['maintenance_vehicles'] ?></h3>
                        </div>
                        <i class="fas fa-tools fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">点検期限間近</div>
                            <h3 class="mb-0"><?= $stats['inspection_due_soon'] ?></h3>
                        </div>
                        <i class="fas fa-exclamation-triangle fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 点検期限アラート -->
    <?php
    $urgent_vehicles = array_filter($vehicles, function($v) {
        return in_array($v['inspection_status'], ['overdue', 'urgent']);
    });
    if (!empty($urgent_vehicles)):
    ?>
    <div class="alert alert-warning">
        <h6 class="alert-heading mb-2"><i class="fas fa-exclamation-triangle me-2"></i>点検期限アラート</h6>
        <?php foreach ($urgent_vehicles as $vehicle): ?>
            <div class="mb-1">
                <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong>
                (<?= htmlspecialchars($vehicle['vehicle_name']) ?>):
                <?php if ($vehicle['inspection_status'] === 'overdue'): ?>
                    <span class="text-danger fw-bold">期限切れ（<?= abs($vehicle['days_to_inspection']) ?>日経過）</span>
                <?php else: ?>
                    <span class="text-warning fw-bold">あと<?= $vehicle['days_to_inspection'] ?>日</span>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-warning ms-2"
                        onclick="quickUpdateInspection(<?= $vehicle['id'] ?>, '<?= $vehicle['next_inspection_date'] ?>')">
                    <i class="fas fa-calendar-check"></i> 更新
                </button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 車両一覧 -->
    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>車両一覧</h5>
            <button type="button" class="btn btn-primary btn-sm" onclick="showAddModal()">
                <i class="fas fa-plus me-1"></i>新規車両追加
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (empty($vehicles)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-car fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">車両が登録されていません</h5>
                    <p class="text-muted mb-0">「新規車両追加」ボタンから車両を登録してください。</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>車両番号</th>
                                <th>車両名</th>
                                <th class="d-none d-md-table-cell">車種</th>
                                <th>タイプ</th>
                                <th>ステータス</th>
                                <th class="text-end">走行距離</th>
                                <th>次回点検</th>
                                <th class="text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $vehicle): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary" style="cursor:pointer;"
                                            onclick="editVehicle(<?= htmlspecialchars(json_encode($vehicle), ENT_QUOTES) ?>)">
                                        <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                    </strong>
                                </td>
                                <td><?= htmlspecialchars($vehicle['vehicle_name']) ?></td>
                                <td class="d-none d-md-table-cell text-muted"><?= htmlspecialchars($vehicle['model'] ?? '-') ?></td>
                                <td>
                                    <span class="badge bg-<?= $vehicle['vehicle_type'] === 'welfare' ? 'info' : ($vehicle['vehicle_type'] === 'taxi' ? 'warning' : 'secondary') ?>">
                                        <?= $vehicle_types[$vehicle['vehicle_type']] ?? 'その他' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?=
                                        $vehicle['status'] === 'active' ? 'success' :
                                        ($vehicle['status'] === 'maintenance' ? 'warning' :
                                        ($vehicle['status'] === 'reserved' ? 'info' : 'secondary'))
                                    ?>">
                                        <?= $statuses[$vehicle['status']] ?? $vehicle['status'] ?>
                                    </span>
                                </td>
                                <td class="text-end font-monospace">
                                    <?= number_format($vehicle['current_mileage']) ?> <small class="text-muted">km</small>
                                </td>
                                <td>
                                    <?php if ($vehicle['next_inspection_date']): ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="badge bg-<?=
                                                $vehicle['inspection_status'] === 'ok' ? 'success' :
                                                ($vehicle['inspection_status'] === 'warning' ? 'warning' :
                                                ($vehicle['inspection_status'] === 'urgent' ? 'danger' : 'dark'))
                                            ?>">
                                                <?= $vehicle['next_inspection_date'] ?>
                                            </span>
                                            <?php if ($vehicle['inspection_status'] !== 'ok'): ?>
                                                <small class="text-<?= $vehicle['inspection_status'] === 'overdue' ? 'danger' : 'warning' ?>">
                                                    <?= $vehicle['inspection_status'] === 'overdue' ? abs($vehicle['days_to_inspection']) . '日超過' : $vehicle['days_to_inspection'] . '日' ?>
                                                </small>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                    onclick="quickUpdateInspection(<?= $vehicle['id'] ?>, '<?= $vehicle['next_inspection_date'] ?>')"
                                                    title="点検日更新">
                                                <i class="fas fa-edit" style="font-size:11px;"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">未設定</span>
                                        <button type="button" class="btn btn-sm btn-outline-warning py-0 px-1 ms-1"
                                                onclick="quickUpdateInspection(<?= $vehicle['id'] ?>, '')"
                                                title="点検日設定">
                                            <i class="fas fa-calendar-plus" style="font-size:11px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary"
                                                onclick="editVehicle(<?= htmlspecialchars(json_encode($vehicle), ENT_QUOTES) ?>)"
                                                title="編集">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger"
                                                onclick="deleteVehicle(<?= $vehicle['id'] ?>, '<?= htmlspecialchars($vehicle['vehicle_number'], ENT_QUOTES) ?>')"
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

<!-- 車両追加・編集モーダル（統一UIパターン） -->
<div class="modal fade" id="vehicleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content unified-modal">
            <div class="modal-header unified-modal-header">
                <h5 class="modal-title" id="vehicleModalTitle">
                    <i class="fas fa-car me-2"></i>車両追加
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="vehicleForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="vehicle_id" id="modalVehicleId">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleNumber" class="form-label unified-label">
                                <i class="fas fa-hashtag me-1"></i>車両番号 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control unified-input" id="modalVehicleNumber"
                                   name="vehicle_number" required placeholder="例: 大阪801あ16-72">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleName" class="form-label unified-label">
                                <i class="fas fa-car me-1"></i>車両名 <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control unified-input" id="modalVehicleName"
                                   name="vehicle_name" required placeholder="例: スマイルカー1号">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalModel" class="form-label unified-label">
                                <i class="fas fa-cog me-1"></i>車種・型式
                            </label>
                            <input type="text" class="form-control unified-input" id="modalModel"
                                   name="model" placeholder="例: トヨタ ハイエース">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalRegistrationDate" class="form-label unified-label">
                                <i class="fas fa-calendar me-1"></i>登録日
                            </label>
                            <input type="date" class="form-control unified-input" id="modalRegistrationDate"
                                   name="registration_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalVehicleType" class="form-label unified-label">
                                <i class="fas fa-tag me-1"></i>車両タイプ
                            </label>
                            <select class="form-select unified-select" id="modalVehicleType" name="vehicle_type">
                                <?php foreach ($vehicle_types as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalCapacity" class="form-label unified-label">
                                <i class="fas fa-users me-1"></i>定員
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control unified-input" id="modalCapacity"
                                       name="capacity" min="1" max="20" value="4">
                                <span class="input-group-text">人</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalCurrentMileage" class="form-label unified-label">
                                <i class="fas fa-tachometer-alt me-1"></i>現在走行距離
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control unified-input" id="modalCurrentMileage"
                                       name="current_mileage" min="0" value="0">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalNextInspectionDate" class="form-label unified-label">
                                <i class="fas fa-clipboard-check me-1"></i>次回点検日
                            </label>
                            <input type="date" class="form-control unified-input" id="modalNextInspectionDate"
                                   name="next_inspection_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="modalStatus" class="form-label unified-label">
                                <i class="fas fa-info-circle me-1"></i>ステータス
                            </label>
                            <select class="form-select unified-select" id="modalStatus" name="status">
                                <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="activeField" style="display: none;">
                            <label class="form-label unified-label">
                                <i class="fas fa-toggle-on me-1"></i>有効状態
                            </label>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="modalIsActive"
                                       name="is_active" checked>
                                <label class="form-check-label" for="modalIsActive">
                                    有効（チェックを外すと論理削除）
                                </label>
                            </div>
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

<!-- 点検日更新モーダル（統一UIパターン） -->
<div class="modal fade" id="inspectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content unified-modal">
            <div class="modal-header unified-modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-check me-2"></i>点検期限更新
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="update_inspection">
                <input type="hidden" name="vehicle_id" id="inspectionVehicleId">

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="inspectionDate" class="form-label unified-label">
                            <i class="fas fa-calendar-alt me-1"></i>新しい点検期限 <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control unified-input" id="inspectionDate"
                               name="next_inspection_date" required>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        法定点検は3ヶ月ごとに実施が必要です。
                    </div>
                </div>

                <div class="modal-footer unified-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>キャンセル
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calendar-check me-1"></i>更新
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 新規追加モーダル表示
function showAddModal() {
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>車両追加';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalVehicleId').value = '';
    document.getElementById('activeField').style.display = 'none';

    document.getElementById('vehicleForm').reset();
    document.getElementById('modalCurrentMileage').value = '0';
    document.getElementById('modalCapacity').value = '4';

    // 3か月後の日付を自動設定
    const today = new Date();
    const threeMonthsLater = new Date(today.getFullYear(), today.getMonth() + 3, today.getDate());
    document.getElementById('modalNextInspectionDate').value = threeMonthsLater.toISOString().split('T')[0];

    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}

// 編集モーダル表示
function editVehicle(vehicle) {
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>車両編集';
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalVehicleId').value = vehicle.id;
    document.getElementById('modalVehicleNumber').value = vehicle.vehicle_number;
    document.getElementById('modalVehicleName').value = vehicle.vehicle_name || '';
    document.getElementById('modalModel').value = vehicle.model || '';
    document.getElementById('modalRegistrationDate').value = vehicle.registration_date || '';
    document.getElementById('modalVehicleType').value = vehicle.vehicle_type || 'welfare';
    document.getElementById('modalCapacity').value = vehicle.capacity || 4;
    document.getElementById('modalCurrentMileage').value = vehicle.current_mileage || 0;
    document.getElementById('modalNextInspectionDate').value = vehicle.next_inspection_date || '';
    document.getElementById('modalStatus').value = vehicle.status || 'active';
    document.getElementById('modalIsActive').checked = vehicle.is_active == 1;
    document.getElementById('activeField').style.display = 'block';

    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}

// 削除確認（統一パターン）
function deleteVehicle(vehicleId, vehicleNumber) {
    showConfirm('車両「' + vehicleNumber + '」を削除しますか？\n※論理削除のため、後で復旧可能です。', function() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML =
            '<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">' +
            '<input type="hidden" name="action" value="delete">' +
            '<input type="hidden" name="vehicle_id" value="' + vehicleId + '">';
        document.body.appendChild(form);
        form.submit();
    }, {
        type: 'danger',
        confirmText: '削除する'
    });
}

// 点検日更新モーダル
function quickUpdateInspection(vehicleId, currentDate) {
    document.getElementById('inspectionVehicleId').value = vehicleId;

    if (currentDate) {
        document.getElementById('inspectionDate').value = currentDate;
    } else {
        const today = new Date();
        const threeMonthsLater = new Date(today.getFullYear(), today.getMonth() + 3, today.getDate());
        document.getElementById('inspectionDate').value = threeMonthsLater.toISOString().split('T')[0];
    }

    new bootstrap.Modal(document.getElementById('inspectionModal')).show();
}
</script>

<?php echo $page_data['html_footer']; ?>
