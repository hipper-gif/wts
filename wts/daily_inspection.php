<?php
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

/**
 * 監査ログ記録関数
 * 点検記録の作成・更新・削除時に変更履歴を記録する
 */
function logInspectionAudit($pdo, $inspection_id, $action, $user_id, $changes = [], $reason = null) {
    logAudit($pdo, $inspection_id, $action, $user_id, 'inspection', $changes, $reason);
}

function canEditInspection($inspection, $user_role) {
    return canEditByDate($inspection, 'inspection_date', $user_role);
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// モード判定を追加
$mode = $_GET['mode'] ?? 'normal';

if ($mode === 'historical') {
    // 過去データ入力は管理者のみ（$user_role は session_check.php で設定）
    if ($user_role !== 'Admin') {
        header('Location: daily_inspection.php');
        exit;
    }
    include 'includes/historical_daily_inspection.php';
    exit;
}

// $pdo, $user_id, $user_name, $user_role は session_check.php で設定済み
$today = date('Y-m-d');

$success_message = '';
$error_message = '';
$is_edit_mode = false;

// 車両とドライバーの取得
try {
    $vehicles = getActiveVehicles($pdo, 'with_mileage');
    $drivers = getActiveDrivers($pdo);
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $vehicles = [];
    $drivers = [];
}

// 特定日の点検記録があるかチェック（日付指定対応）
$target_date = $_GET['date'] ?? $today;
$existing_inspection = null;
if (($_GET['inspector_id'] ?? null) && ($_GET['vehicle_id'] ?? null)) {
    $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
    $stmt->execute([$_GET['inspector_id'], $_GET['vehicle_id'], $target_date]);
    $existing_inspection = $stmt->fetch();
    $is_edit_mode = (bool)$existing_inspection;
}

// 削除処理（管理者のみ、監査ログ付き）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    validateCsrfToken();
    try {
        // 削除対象のデータを取得（監査ログ用・権限チェック用）
        $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
        $stmt->execute([$_POST['inspector_id'], $_POST['vehicle_id'], $_POST['inspection_date']]);
        $delete_target = $stmt->fetch();

        if (!$delete_target) {
            $error_message = '削除対象の記録が見つかりません。';
        // 権限チェック: 管理者または元の運転者のみ削除可能
        } elseif ($user_role !== 'Admin' && $delete_target['driver_id'] != $user_id) {
            $error_message = '削除は管理者または記録した運転者のみ実行できます。';
        // ロックチェック: 過去日の記録は管理者のみ削除可能
        } elseif ($delete_target['inspection_date'] < date('Y-m-d') && $user_role !== 'Admin') {
            $error_message = '過去日の記録はロックされています。削除するには管理者にお問い合わせください。';
        } else {
            // 編集可否チェック
            $edit_check = canEditInspection($delete_target, $user_role);
            if (!$edit_check['can_edit']) {
                $error_message = $edit_check['lock_reason'];
            } else {
                $pdo->beginTransaction();

                // ソフトデリート（deleted_atカラムがある場合）またはハードデリート
                // まずソフトデリートを試みる
                $soft_delete_success = false;
                try {
                    $stmt = $pdo->prepare("UPDATE daily_inspections SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
                    $stmt->execute([$user_id, $delete_target['id']]);
                    $soft_delete_success = true;
                } catch (Exception $soft_e) {
                    // deleted_atカラムが存在しない場合はハードデリート
                    $stmt = $pdo->prepare("DELETE FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
                    $stmt->execute([$_POST['inspector_id'], $_POST['vehicle_id'], $_POST['inspection_date']]);
                }

                // 監査ログに削除データを記録
                $delete_reason = $_POST['edit_reason'] ?? '管理者による削除';
                logInspectionAudit($pdo, $delete_target['id'], 'delete', $user_id, [], $delete_reason);

                $pdo->commit();
                $success_message = '日常点検記録を削除しました。';
                $existing_inspection = null;
                $is_edit_mode = false;
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Daily inspection delete error: " . $e->getMessage());
        $error_message = 'エラーが発生しました。管理者にお問い合わせください。';
    }
}

// フォーム送信処理（登録・更新）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    validateCsrfToken();
    $inspector_id = $_POST['inspector_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $inspection_date = $_POST['inspection_date'] ?? $today;
    $mileage = $_POST['mileage'];
    $inspection_time = $_POST['inspection_time'] ?? date('H:i');
    
    // 点検者の確認（統一された権限チェック）
    $stmt = $pdo->prepare("SELECT is_driver FROM users WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$inspector_id]);
    $inspector = $stmt->fetch();
    
    if (!$inspector || $inspector['is_driver'] != 1) {
        $error_message = 'エラー: 点検者は運転手のみ選択できます。';
    } else {
        // 点検項目の結果
        $inspection_items = [
            // 運転室内
            'foot_brake_result', 'parking_brake_result', 'engine_start_result', 
            'engine_performance_result', 'wiper_result', 'washer_spray_result',
            // エンジンルーム内
            'brake_fluid_result', 'coolant_result', 'engine_oil_result', 
            'battery_fluid_result', 'washer_fluid_result', 'fan_belt_result',
            // 灯火類とタイヤ
            'lights_result', 'lens_result', 'tire_pressure_result',
            'tire_damage_result', 'tire_tread_result'
        ];

        // 点検結果値のバリデーション
        $allowed_values = ['可', '否', '省略'];
        $invalid_items = [];
        foreach ($inspection_items as $item) {
            $value = $_POST[$item] ?? '省略';
            if (!in_array($value, $allowed_values, true)) {
                $invalid_items[] = $item;
            }
        }
        if (!empty($invalid_items)) {
            $error_message = 'エラー: 点検結果に不正な値が含まれています。';
        }

        if (empty($error_message)) {
        try {
            // 既存レコードの確認
            $stmt = $pdo->prepare("SELECT id FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
            $stmt->execute([$inspector_id, $vehicle_id, $inspection_date]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 既存レコードの全データを取得（変更差分比較用）
                $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE id = ?");
                $stmt->execute([$existing['id']]);
                $old_record = $stmt->fetch();

                // 編集可否チェック
                $edit_check = canEditInspection($old_record, $user_role);
                if (!$edit_check['can_edit']) {
                    $error_message = $edit_check['lock_reason'];
                } elseif ($edit_check['needs_reason'] && empty(trim($_POST['edit_reason'] ?? ''))) {
                    $error_message = '過去日の記録を修正する場合は修正理由を入力してください。';
                } else {
                    $edit_reason = trim($_POST['edit_reason'] ?? '') ?: null;

                    $pdo->beginTransaction();

                    // 更新SQL構築
                    $sql = "UPDATE daily_inspections SET
                        mileage = ?, inspection_time = ?,";

                    foreach ($inspection_items as $item) {
                        $sql .= " $item = ?,";
                    }

                    $sql .= " defect_details = ?, remarks = ?,
                        edit_count = COALESCE(edit_count, 0) + 1,
                        last_edited_by = ?,
                        last_edited_at = NOW(),
                        updated_at = NOW()
                        WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?";

                    $stmt = $pdo->prepare($sql);
                    $params = [$mileage, $inspection_time];

                    foreach ($inspection_items as $item) {
                        $params[] = $_POST[$item] ?? '省略';
                    }

                    $params[] = $_POST['defect_details'] ?? '';
                    $params[] = $_POST['remarks'] ?? '';
                    $params[] = $user_id;
                    $params[] = $inspector_id;
                    $params[] = $vehicle_id;
                    $params[] = $inspection_date;

                    $stmt->execute($params);

                    // 変更差分を検出して監査ログに記録
                    $changes = [];
                    $compare_fields = array_merge(
                        ['mileage', 'inspection_time'],
                        $inspection_items,
                        ['defect_details', 'remarks']
                    );
                    $new_values = [
                        'mileage' => $mileage,
                        'inspection_time' => $inspection_time,
                        'defect_details' => $_POST['defect_details'] ?? '',
                        'remarks' => $_POST['remarks'] ?? ''
                    ];
                    foreach ($inspection_items as $item) {
                        $new_values[$item] = $_POST[$item] ?? '省略';
                    }

                    foreach ($compare_fields as $field) {
                        $old_val = (string)($old_record[$field] ?? '');
                        $new_val = (string)($new_values[$field] ?? '');
                        if ($old_val !== $new_val) {
                            $changes[] = [
                                'field' => $field,
                                'old' => $old_val,
                                'new' => $new_val
                            ];
                        }
                    }

                    if (!empty($changes)) {
                        logInspectionAudit($pdo, $old_record['id'], 'edit', $user_id, $changes, $edit_reason);
                    }

                    $pdo->commit();
                    $success_message = '日常点検記録を更新しました。';
                }
            } else {
                // 新規挿入
                $pdo->beginTransaction();

                $sql = "INSERT INTO daily_inspections (
                    vehicle_id, driver_id, inspection_date, inspection_time, mileage,";

                foreach ($inspection_items as $item) {
                    $sql .= " $item,";
                }

                $sql .= " defect_details, remarks) VALUES (?, ?, ?, ?, ?,";

                $sql .= str_repeat('?,', count($inspection_items));
                $sql .= " ?, ?)";

                $stmt = $pdo->prepare($sql);
                $params = [$vehicle_id, $inspector_id, $inspection_date, $inspection_time, $mileage];

                foreach ($inspection_items as $item) {
                    $params[] = $_POST[$item] ?? '省略';
                }

                $params[] = $_POST['defect_details'] ?? '';
                $params[] = $_POST['remarks'] ?? '';

                $stmt->execute($params);
                $new_inspection_id = $pdo->lastInsertId();

                // 新規作成の監査ログ
                logInspectionAudit($pdo, $new_inspection_id, 'create', $user_id);

                $pdo->commit();
                $success_message = '日常点検記録を登録しました。';
            }
            
            // 記録を再取得（成功時のみ）
            if ($success_message) {
                $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
                $stmt->execute([$inspector_id, $vehicle_id, $inspection_date]);
                $existing_inspection = $stmt->fetch();
                $is_edit_mode = true;

                // 車両の走行距離を更新
                if ($mileage) {
                    $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?");
                    $stmt->execute([$mileage, $vehicle_id]);
                }
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Daily inspection error: " . $e->getMessage());
            $error_message = 'エラーが発生しました。管理者にお問い合わせください。';
        }
        } // end if (empty($error_message))
    }
}

// ロック状態の判定（テンプレート用変数）
$is_locked = false;
$can_edit = true;
$needs_reason = false;
$lock_reason = '';
$edit_count = 0;
$audit_logs = [];

if ($existing_inspection) {
    $edit_permission = canEditInspection($existing_inspection, $user_role);
    $can_edit = $edit_permission['can_edit'];
    $needs_reason = $edit_permission['needs_reason'];
    $lock_reason = $edit_permission['lock_reason'];
    $is_locked = ($existing_inspection['inspection_date'] < $today);
    $edit_count = (int)($existing_inspection['edit_count'] ?? 0);

    // 監査ログを取得
    try {
        $stmt_logs = $pdo->prepare("
            SELECT al.*, u.name as user_name
            FROM inspection_audit_logs al
            LEFT JOIN users u ON al.edited_by = u.id
            WHERE al.inspection_id = ?
            ORDER BY al.edited_at DESC
        ");
        $stmt_logs->execute([$existing_inspection['id']]);
        $audit_logs = $stmt_logs->fetchAll();
    } catch (Exception $e) {
        $audit_logs = [];
    }
}

// 今日の点検状況を取得
$today_inspections = [];
try {
    $stmt = $pdo->prepare("
        SELECT di.*, v.vehicle_number, v.model as vehicle_model
        FROM daily_inspections di
        LEFT JOIN vehicles v ON di.vehicle_id = v.id
        WHERE di.driver_id = ? AND di.inspection_date = CURDATE()
        ORDER BY di.inspection_time DESC
    ");
    $stmt->execute([$user_id]);
    $today_inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

// 最近の点検履歴（7件）を取得
$recent_inspections = [];
try {
    $stmt = $pdo->prepare("
        SELECT di.id, di.vehicle_id, di.driver_id, di.inspection_date, di.inspection_time, di.mileage,
               u.name as driver_name, v.vehicle_number,
               di.defect_details, di.edit_count
        FROM daily_inspections di
        LEFT JOIN users u ON di.driver_id = u.id
        LEFT JOIN vehicles v ON di.vehicle_id = v.id
        WHERE di.driver_id = ?
        ORDER BY di.inspection_date DESC, di.inspection_time DESC
        LIMIT 7
    ");
    $stmt->execute([$user_id]);
    $recent_inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

// ページ設定を取得
$page_config = getPageConfiguration('daily_inspection');
?>
<!DOCTYPE html>
<?= renderCompleteHTMLHead($page_config['title'], [
    'description' => $page_config['description'],
    'additional_css' => [],
    'additional_js' => []
]) ?>
<body>
    <link rel="stylesheet" href="css/daily_inspection.css">
    <link rel="stylesheet" href="css/workflow-stepper.css">
    <?= renderSystemHeader($user_name, $user_role, 'daily_inspection') ?>
    <?= renderPageHeader($page_config['icon'], $page_config['title'], $page_config['subtitle'], $page_config['category'], [], renderWorkflowStepper(
        'inspection',
        getWorkflowCompletionStatus($pdo, $user_id),
        null,
        ['url' => 'pre_duty_call.php', 'label' => '乗務前点呼']
    )) ?>
    
    <div class="container mt-4" id="main-content" tabindex="-1">
        <div id="printHeader">
            <h2 style="text-align:center; margin-bottom:2px; font-size:18pt;">日常点検記録表</h2>
            <p style="text-align:center; margin:0; font-size:10pt; color:#666;">道路運送車両法第47条の2に基づく日常点検</p>
            <table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:10pt;">
                <tr>
                    <td style="border:1px solid #000; padding:4px; width:15%; background:#f0f0f0;"><strong>点検日</strong></td>
                    <td style="border:1px solid #000; padding:4px; width:35%;" id="printDate"></td>
                    <td style="border:1px solid #000; padding:4px; width:15%; background:#f0f0f0;"><strong>点検時間</strong></td>
                    <td style="border:1px solid #000; padding:4px; width:35%;" id="printTime"></td>
                </tr>
                <tr>
                    <td style="border:1px solid #000; padding:4px; background:#f0f0f0;"><strong>点検者</strong></td>
                    <td style="border:1px solid #000; padding:4px;" id="printInspector"></td>
                    <td style="border:1px solid #000; padding:4px; background:#f0f0f0;"><strong>車両</strong></td>
                    <td style="border:1px solid #000; padding:4px;" id="printVehicle"></td>
                </tr>
                <tr>
                    <td style="border:1px solid #000; padding:4px; background:#f0f0f0;"><strong>走行距離</strong></td>
                    <td style="border:1px solid #000; padding:4px;" id="printMileage" colspan="3"></td>
                </tr>
            </table>
            <hr style="margin:8px 0; border:1px solid #000;">
        </div>
        <!-- モード切替ボタン -->
        <?php if (!empty($today_inspections)): ?>
        <!-- 本日の点検完了：コンパクト表示 -->
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <span class="badge bg-success py-2 px-3"><i class="fas fa-check-circle me-1"></i>本日の点検完了済み</span>
            <?php foreach ($today_inspections as $ti): ?>
            <a href="daily_inspection.php?inspector_id=<?= urlencode($ti['driver_id']) ?>&vehicle_id=<?= urlencode($ti['vehicle_id']) ?>&date=<?= urlencode($ti['inspection_date']) ?>"
               class="btn btn-outline-secondary btn-sm">
                <?= htmlspecialchars($ti['vehicle_number'] ?? '') ?> <?= htmlspecialchars($ti['inspection_time'] ?? '') ?>
            </a>
            <?php endforeach; ?>
            <?php if ($user_role === 'Admin'): ?>
            <a href="daily_inspection.php?mode=historical" class="btn btn-outline-secondary btn-sm ms-auto">
                <i class="fas fa-history me-1"></i>過去データ
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 下書き復元バナー -->
        <div id="draftBanner" class="alert alert-info alert-dismissible d-none" role="alert">
            <i class="fas fa-save me-2"></i>
            <strong>下書きデータがあります。</strong> 前回入力途中のデータを復元できます。
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-primary me-2" onclick="restoreDraft()">
                    <i class="fas fa-undo me-1"></i>復元する
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="discardDraft()">
                    <i class="fas fa-trash me-1"></i>破棄する
                </button>
            </div>
        </div>

        <?php if ($is_edit_mode && $existing_inspection): ?>
        <div class="mb-2">
            <?php if (!$is_locked): ?>
                <span class="badge bg-success"><i class="fas fa-unlock me-1"></i>編集可能</span>
            <?php elseif ($is_locked && $can_edit): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-lock me-1"></i>ロック中</span>
            <?php else: ?>
                <span class="badge bg-danger"><i class="fas fa-lock me-1"></i>変更不可</span>
            <?php endif; ?>
            <?php if ($edit_count > 0): ?>
                <span class="badge bg-info ms-1">修正<?= $edit_count ?>回</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
        <script>document.addEventListener('DOMContentLoaded', function() { showToast('<?= addslashes($success_message) ?>', 'success'); });</script>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>
        
        <form method="POST" id="inspectionForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <?php if ($is_edit_mode): ?>
                <input type="hidden" name="inspector_id" value="<?= htmlspecialchars($existing_inspection['driver_id']) ?>">
                <input type="hidden" name="vehicle_id" value="<?= htmlspecialchars($existing_inspection['vehicle_id']) ?>">
                <input type="hidden" name="inspection_date" value="<?= htmlspecialchars($existing_inspection['inspection_date']) ?>">
            <?php endif; ?>

            <!-- 進捗バー + クイック操作（1行） -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap" id="progressCard">
                <div class="flex-grow-1">
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-success" role="progressbar" id="progressBar"
                             style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
                <small class="fw-bold text-nowrap" id="progressText">0/7</small>
                <?php if (!$is_edit_mode): ?>
                <button type="button" class="btn btn-success btn-sm" onclick="setAllOk()">全て可</button>
                <?php endif; ?>
            </div>
            
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検日 <span class="text-danger fw-bold small">（必須）</span></label>
                            <input type="<?= $is_edit_mode ? 'text' : 'date' ?>" class="form-control" name="<?= $is_edit_mode ? 'inspection_date_display' : 'inspection_date' ?>"
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_date'] : $target_date ?>"
                                   <?= $is_edit_mode ? 'readonly' : 'required' ?>>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検時間</label>
                            <input type="time" class="form-control" name="inspection_time"
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_time'] : date('H:i') ?>"
                                   <?= $is_edit_mode ? 'readonly' : '' ?>>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検者（運転手） <span class="text-danger fw-bold small">（必須）</span></label>
                            <?php if ($is_edit_mode): ?>
                                <?php
                                $inspector_name = '';
                                foreach ($drivers as $driver) {
                                    if ($driver['id'] == $existing_inspection['driver_id']) {
                                        $inspector_name = htmlspecialchars($driver['name']);
                                        break;
                                    }
                                }
                                ?>
                                <input type="text" class="form-control" value="<?= $inspector_name ?>" readonly>
                            <?php else: ?>
                                <select class="form-select" name="inspector_id" required>
                                    <option value="">運転者を選択</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>"
                                            <?= ($driver['id'] == $user_id) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($driver['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">車両 <span class="text-danger fw-bold small">（必須）</span></label>
                            <?php if ($is_edit_mode): ?>
                                <?php
                                $vehicle_name = '';
                                foreach ($vehicles as $vehicle) {
                                    if ($vehicle['id'] == $existing_inspection['vehicle_id']) {
                                        $vehicle_name = htmlspecialchars($vehicle['vehicle_number']);
                                        if ($vehicle['model']) {
                                            $vehicle_name .= ' (' . htmlspecialchars($vehicle['model']) . ')';
                                        }
                                        break;
                                    }
                                }
                                ?>
                                <input type="text" class="form-control" value="<?= $vehicle_name ?>" readonly>
                            <?php else: ?>
                                <select class="form-select" name="vehicle_id" required onchange="updateMileage()">
                                    <option value="">車両を選択してください</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle['id'] ?>"
                                            data-mileage="<?= $vehicle['current_mileage'] ?>"
                                            <?= ($existing_inspection && $existing_inspection['vehicle_id'] == $vehicle['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                        <?= $vehicle['model'] ? ' (' . htmlspecialchars($vehicle['model']) . ')' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">走行距離 <span class="text-danger fw-bold small">（必須・メーター確認）</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="mileage" id="mileage" inputmode="numeric"
                                       value="<?= $existing_inspection ? $existing_inspection['mileage'] : '' ?>"
                                       placeholder="メーターの数値を入力" required
                                       <?= $is_edit_mode ? 'readonly' : '' ?>>
                                <span class="input-group-text">km</span>
                            </div>
                            <?php if (!$is_edit_mode): ?>
                            <small class="form-text text-warning" id="mileageInfo" style="display: none;">
                                <i class="fas fa-exclamation-triangle me-1"></i><span id="mileageText">前回: </span>
                                <strong>車両メーターを確認して入力してください</strong>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 運転室内点検 -->
            <?= renderSectionHeader('car', '運転室内点検', '必須項目含む6項目') ?>
            <div class="card mb-4">
                <div class="card-body">
                    <?php
                    $cabin_items = [
                        'foot_brake_result' => ['label' => 'フットブレーキの踏み代・効き', 'required' => true],
                        'parking_brake_result' => ['label' => 'パーキングブレーキの引き代', 'required' => true],
                        'engine_start_result' => ['label' => 'エンジンのかかり具合・異音', 'required' => false],
                        'engine_performance_result' => ['label' => 'エンジンの低速・加速', 'required' => false],
                        'wiper_result' => ['label' => 'ワイパーのふき取り能力', 'required' => false],
                        'washer_spray_result' => ['label' => 'ウインドウォッシャー液の噴射状態', 'required' => false]
                    ];
                    ?>
                    
                    <?php foreach ($cabin_items as $key => $item): ?>
                    <div class="inspection-item border rounded p-3 mb-3" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                    <span class="badge bg-danger ms-2">必須</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">省略可</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可（良好）</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否（要修理）</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略（対象外）</label>
                                    <?php endif; ?>
                                </div>
                                <span class="print-result-text" data-print-for="<?= $key ?>"></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- エンジンルーム内点検 -->
            <?= renderSectionHeader('cog', 'エンジンルーム内点検', '必須項目含む6項目') ?>
            <div class="card mb-4">
                <div class="card-body">
                    <?php
                    $engine_items = [
                        'brake_fluid_result' => ['label' => 'ブレーキ液量', 'required' => true],
                        'coolant_result' => ['label' => '冷却水量', 'required' => false],
                        'engine_oil_result' => ['label' => 'エンジンオイル量', 'required' => false],
                        'battery_fluid_result' => ['label' => 'バッテリー液量', 'required' => false],
                        'washer_fluid_result' => ['label' => 'ウインドウォッシャー液量', 'required' => false],
                        'fan_belt_result' => ['label' => 'ファンベルトの張り・損傷', 'required' => false]
                    ];
                    ?>
                    
                    <?php foreach ($engine_items as $key => $item): ?>
                    <div class="inspection-item border rounded p-3 mb-3" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                    <span class="badge bg-danger ms-2">必須</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">省略可</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可（良好）</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否（要修理）</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略（対象外）</label>
                                    <?php endif; ?>
                                </div>
                                <span class="print-result-text" data-print-for="<?= $key ?>"></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 灯火類とタイヤ点検 -->
            <?= renderSectionHeader('lightbulb', '灯火類とタイヤ点検', '必須項目含む5項目') ?>
            <div class="card mb-4">
                <div class="card-body">
                    <?php
                    $lights_tire_items = [
                        'lights_result' => ['label' => '灯火類の点灯・点滅', 'required' => true],
                        'lens_result' => ['label' => 'レンズの損傷・汚れ', 'required' => true],
                        'tire_pressure_result' => ['label' => 'タイヤの空気圧', 'required' => true],
                        'tire_damage_result' => ['label' => 'タイヤの亀裂・損傷', 'required' => true],
                        'tire_tread_result' => ['label' => 'タイヤ溝の深さ', 'required' => false]
                    ];
                    ?>
                    
                    <?php foreach ($lights_tire_items as $key => $item): ?>
                    <div class="inspection-item border rounded p-3 mb-3" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                    <span class="badge bg-danger ms-2">必須</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">省略可</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可（良好）</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否（要修理）</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略（対象外）</label>
                                    <?php endif; ?>
                                </div>
                                <span class="print-result-text" data-print-for="<?= $key ?>"></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 不良個所・備考 -->
            <?= renderSectionHeader('exclamation-triangle', '不良個所及び処置・備考', '詳細記録') ?>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">不良個所及び処置</label>
                        <textarea class="form-control" name="defect_details" rows="3"
                                  placeholder="点検で「否」となった項目の詳細と処置内容を記入"
                                  <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_inspection ? htmlspecialchars($existing_inspection['defect_details']) : '' ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea class="form-control" name="remarks" rows="2"
                                  placeholder="その他特記事項があれば記入"
                                  <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_inspection ? htmlspecialchars($existing_inspection['remarks']) : '' ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="print-defect-section">
                <table style="width:100%; border-collapse:collapse; font-size:10pt; margin-top:8px;">
                    <tr>
                        <td style="border:1px solid #000; padding:6px; width:20%; background:#f0f0f0; vertical-align:top;"><strong>不良個所及び処置</strong></td>
                        <td style="border:1px solid #000; padding:6px;" id="printDefects"></td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #000; padding:6px; background:#f0f0f0; vertical-align:top;"><strong>備考</strong></td>
                        <td style="border:1px solid #000; padding:6px;" id="printRemarks"></td>
                    </tr>
                </table>
            </div>

            <!-- 修正理由（ロック済みレコードの編集時に表示） -->
            <div class="card mb-3 border-warning" id="editReasonSection" style="display:none;">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>修正理由（必須）</h6>
                </div>
                <div class="card-body">
                    <textarea name="edit_reason" id="editReason" class="form-control" rows="3"
                              placeholder="修正理由を入力してください（例：記入ミス、再確認の結果等）"></textarea>
                    <small class="text-muted">ロック済みレコードの修正には理由の記入が必要です。監査ログに記録されます。</small>
                </div>
            </div>

            <!-- 安全項目変更理由（否→可への変更時に表示） -->
            <div class="card mb-3 border-info" id="safetyCriticalReasonSection" style="display:none;">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-shield-alt me-2"></i>安全項目変更理由</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">安全に関わる項目を「否」から「可」に変更しました。理由を記入してください。</p>
                    <textarea name="safety_change_reason" id="safetyCriticalReason" class="form-control" rows="2"
                              placeholder="変更理由を入力してください（例：再点検により正常と確認）"></textarea>
                </div>
            </div>

            <!-- 操作ボタン -->
            <div class="text-center mb-4" id="actionButtons" style="position: sticky; bottom: 0; z-index: 50; background: white; padding: 12px 0; border-top: 1px solid #dee2e6;">
                <div id="printBtnWrap" style="position:absolute; right:16px; top:50%; transform:translateY(-50%);">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="printInspection()">
                        <i class="fas fa-print me-1"></i>印刷
                    </button>
                </div>
                <?php if ($is_edit_mode && $is_locked && !$can_edit): ?>
                    <!-- ロック中：編集不可 -->
                    <div class="text-muted">
                        <i class="fas fa-lock me-2"></i>この記録は編集できません
                    </div>
                <?php elseif ($is_edit_mode): ?>
                    <button type="button" class="btn btn-warning btn-lg me-2" onclick="enableEditMode()">
                        <i class="fas fa-edit me-2"></i>修正
                    </button>
                    <?php if ($user_role === 'Admin'): ?>
                    <button type="button" class="btn btn-danger btn-lg" onclick="confirmDelete()">
                        <i class="fas fa-trash me-2"></i>削除
                    </button>
                    <?php endif; ?>
                <?php else: ?>
                    <button type="submit" class="btn btn-success btn-lg" data-loading-text="保存中...">
                        <i class="fas fa-save me-2"></i>
                        <?= $existing_inspection ? '更新する' : '登録する' ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <!-- 削除フォーム -->
        <?php if ($is_edit_mode): ?>
        <form method="POST" id="deleteForm" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="inspector_id" value="<?= htmlspecialchars($existing_inspection['driver_id']) ?>">
            <input type="hidden" name="vehicle_id" value="<?= htmlspecialchars($existing_inspection['vehicle_id']) ?>">
            <input type="hidden" name="inspection_date" value="<?= htmlspecialchars($existing_inspection['inspection_date']) ?>">
            <input type="hidden" name="edit_reason" id="deleteReason" value="">
        </form>
        <?php endif; ?>
        
        <!-- 変更履歴 -->
        <?php if ($is_edit_mode && !empty($audit_logs)): ?>
        <div class="card mb-3" id="auditHistorySection">
            <div class="card-header" data-bs-toggle="collapse" data-bs-target="#auditHistoryBody" style="cursor:pointer;">
                <h6 class="mb-0">
                    <i class="fas fa-history me-2"></i>変更履歴
                    <span class="badge bg-secondary ms-1"><?= count($audit_logs) ?>件</span>
                    <i class="fas fa-chevron-down float-end mt-1"></i>
                </h6>
            </div>
            <div class="collapse" id="auditHistoryBody">
                <div class="card-body">
                    <div class="position-relative" style="padding-left: 30px;">
                        <?php
                        $action_labels = [
                            'create' => ['label' => '新規作成', 'icon' => 'plus-circle', 'color' => 'success'],
                            'edit' => ['label' => '編集', 'icon' => 'edit', 'color' => 'primary'],
                            'delete' => ['label' => '削除', 'icon' => 'trash', 'color' => 'danger'],
                            'admin_unlock' => ['label' => '管理者ロック解除', 'icon' => 'unlock', 'color' => 'warning'],
                        ];
                        ?>
                        <?php foreach ($audit_logs as $index => $log): ?>
                        <?php
                            $action_info = $action_labels[$log['action']] ?? ['label' => $log['action'], 'icon' => 'circle', 'color' => 'secondary'];
                            $is_last = ($index === count($audit_logs) - 1);
                        ?>
                        <div class="mb-3 position-relative">
                            <!-- タイムライン線 -->
                            <?php if (!$is_last): ?>
                            <div style="position:absolute; left:-20px; top:10px; bottom:-20px; width:2px; background:#dee2e6;"></div>
                            <?php endif; ?>
                            <!-- タイムラインドット -->
                            <div style="position:absolute; left:-25px; top:5px; width:12px; height:12px; border-radius:50%; background:var(--bs-<?= $action_info['color'] ?>); border:2px solid white;"></div>

                            <div class="card border-<?= $action_info['color'] ?> border-start border-start-3">
                                <div class="card-body py-2 px-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="badge bg-<?= $action_info['color'] ?>">
                                            <i class="fas fa-<?= $action_info['icon'] ?> me-1"></i><?= $action_info['label'] ?>
                                        </span>
                                        <small class="text-muted"><?= htmlspecialchars($log['edited_at']) ?></small>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($log['user_name'] ?? '不明') ?>
                                    </small>
                                    <?php if (!empty($log['field_changed'])): ?>
                                    <div class="mt-1">
                                        <small>
                                            <strong><?= htmlspecialchars($log['field_changed']) ?></strong>:
                                            <span class="text-danger"><?= htmlspecialchars($log['old_value'] ?? '') ?></span>
                                            <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                            <span class="text-success"><?= htmlspecialchars($log['new_value'] ?? '') ?></span>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($log['reason'])): ?>
                                    <div class="mt-1">
                                        <small class="text-info"><i class="fas fa-comment me-1"></i>理由: <?= htmlspecialchars($log['reason']) ?></small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 最近の点検履歴 -->
        <?php if (!empty($recent_inspections)): ?>
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-header bg-light py-2" data-bs-toggle="collapse" data-bs-target="#recentInspectionsBody" style="cursor:pointer;">
                <div class="d-flex align-items-center justify-content-between">
                    <h6 class="mb-0">
                        <i class="fas fa-history me-2"></i>最近の点検履歴
                        <span class="badge bg-secondary ms-1"><?= count($recent_inspections) ?>件</span>
                    </h6>
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="collapse" id="recentInspectionsBody">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">日付</th>
                                    <th>車両</th>
                                    <th>点検者</th>
                                    <th class="text-center">状態</th>
                                    <th class="text-end pe-3">走行距離</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_inspections as $ri): ?>
                                <tr style="cursor:pointer;" onclick="location.href='daily_inspection.php?inspector_id=<?= urlencode($ri['driver_id'] ?? '') ?>&vehicle_id=<?= urlencode($ri['vehicle_id'] ?? '') ?>&date=<?= urlencode($ri['inspection_date']) ?>'">
                                    <td class="ps-3">
                                        <small><?= htmlspecialchars($ri['inspection_date']) ?></small>
                                        <?php if (!empty($ri['inspection_time'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($ri['inspection_time'], 0, 5)) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= htmlspecialchars($ri['vehicle_number'] ?? '-') ?></small></td>
                                    <td><small><?= htmlspecialchars($ri['driver_name'] ?? '-') ?></small></td>
                                    <td class="text-center">
                                        <?php if (empty($ri['defect_details'])): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i> OK</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> 要確認</span>
                                        <?php endif; ?>
                                        <?php if (($ri['edit_count'] ?? 0) > 0): ?>
                                            <span class="badge bg-info ms-1"><i class="fas fa-pen"></i> <?= $ri['edit_count'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <small><?= $ri['mileage'] ? number_format($ri['mileage']) . ' km' : '-' ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="printFooter">
            <hr style="border:1px solid #000; margin:12px 0;">
            <table style="width:100%; font-size:9pt;">
                <tr>
                    <td style="width:50%;">確認者署名: ___________________</td>
                    <td style="text-align:right;">印刷日: <span id="printDateStamp"></span></td>
                </tr>
            </table>
        </div>

        <!-- ナビゲーションリンク -->
        <div class="card bg-light">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-4 mb-2">
                        <h6 class="text-muted mb-2">次の作業</h6>
                        <a href="pre_duty_call.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-clipboard-check me-1"></i>乗務前点呼
                        </a>
                    </div>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-muted mb-2">他の点検</h6>
                        <a href="periodic_inspection.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-wrench me-1"></i>定期点検
                        </a>
                    </div>
                    <?php if ($user_role === 'Admin'): ?>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-muted mb-2">記録管理</h6>
                        <a href="daily_inspection.php?mode=historical" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-history me-1"></i>履歴・編集
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?= renderCompleteHTMLFooter() ?>
    
    <script>
        // PHP→JS変数ブリッジ
        window.inspectionLockStatus = {
            isLocked: <?= json_encode($is_locked) ?>,
            canEdit: <?= json_encode($can_edit) ?>,
            needsReason: <?= json_encode($needs_reason) ?>,
            editCount: <?= json_encode($edit_count) ?>,
            lockReason: <?= json_encode($lock_reason ?? '') ?>
        };
    </script>
    <script src="js/daily_inspection.js" defer></script>
