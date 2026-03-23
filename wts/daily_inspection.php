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
    <style>
    .hover-bg-light:hover { background: #f8f9fa; }

    @media (max-width: 767px) {
        .inspection-item .btn-group {
            width: 100%;
            justify-content: center;
        }
        .inspection-item .btn-group .btn {
            flex: 1;
            min-height: 44px;
        }
        .inspection-item label.form-label {
            text-align: left !important;
            margin-bottom: 4px;
        }
    }

    /* 印刷用スタイル */
    @media print {
        /* 非印刷要素を非表示 */
        .system-header-container, .system-header, .page-header, #draftBanner,
        #progressCard, #actionButtons, #auditHistorySection, .bottom-nav-card,
        .mode-switch, #editReasonSection, #safetyCriticalReasonSection,
        .inspection-lock-badge, .form-select, .form-text,
        input[type="date"], input[type="time"], input[type="number"],
        input[type="text"].form-control, textarea.form-control,
        .alert, #deleteForm, .input-group-text,
        #printBtnWrap, .badge { display: none !important; }

        /* ラジオボタンのbtn-groupを非表示（ラベル含む） */
        .inspection-item .btn-group { display: none !important; }
        .inspection-item input[type="radio"] { display: none !important; }

        /* 印刷ヘッダー表示 */
        #printHeader { display: block !important; }

        /* 印刷フッター表示 */
        #printFooter { display: block !important; }

        /* 印刷用結果テキスト表示 */
        .print-result-text { display: inline !important; font-weight: bold; font-size: 12pt; }
        .print-result-ok { color: #000; }
        .print-result-ng { color: #000; text-decoration: underline; }
        .print-result-skip { color: #666; }
        .print-result-none { color: #999; font-style: italic; }

        /* 印刷用テキスト値表示 */
        .print-value-text { display: inline !important; font-size: 11pt; }

        /* クリーンレイアウト */
        body { font-size: 11pt; color: #000; background: #fff; padding: 0; margin: 0; }
        .container { max-width: 100%; padding: 0 10mm; margin: 0; }
        .main-content { margin: 0; padding: 0; }
        .card { border: 1px solid #000 !important; box-shadow: none !important; break-inside: avoid; margin-bottom: 8px !important; }
        .card-body { padding: 8px 12px !important; }
        .card-header, .section-header { background: #f0f0f0 !important; color: #000 !important; padding: 4px 12px !important; border-bottom: 1px solid #000 !important; }
        .section-header .section-title { color: #000 !important; }
        .inspection-item { border: none !important; border-bottom: 1px solid #ccc !important; padding: 4px 0 !important; margin-bottom: 0 !important; border-radius: 0 !important; }
        .inspection-item:last-child { border-bottom: none !important; }
        .inspection-item .row { display: flex; align-items: center; }
        .inspection-item .col-md-6:first-child { flex: 0 0 65%; max-width: 65%; }
        .inspection-item .col-md-6:last-child,
        .inspection-item .col-md-6.text-end { flex: 0 0 35%; max-width: 35%; text-align: right !important; }

        /* 不良個所・備考セクション */
        .print-defect-section { display: block !important; }

        /* A4縦レイアウト */
        @page { size: A4 portrait; margin: 10mm 15mm; }
    }

    /* 画面表示では非表示 */
    #printHeader, #printFooter, .print-result-text, .print-value-text, .print-defect-section { display: none; }
    </style>
    <?= renderSystemHeader($user_name, $user_role, 'daily_inspection') ?>
    <?= renderPageHeader($page_config['icon'], $page_config['title'], $page_config['subtitle'], $page_config['category'], [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '日常点検', 'url' => 'daily_inspection.php']
    ]) ?>
    
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
        <div class="mode-switch mb-4">
            <div class="btn-group" role="group">
                <a href="daily_inspection.php" class="btn btn-primary">
                    <i class="fas fa-edit"></i> 通常入力
                </a>
                <?php if ($user_role === 'Admin'): ?>
                <a href="daily_inspection.php?mode=historical" class="btn btn-outline-success">
                    <i class="fas fa-history"></i> 過去データ入力
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- 本日の点検状況サマリー -->
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header <?= !empty($today_inspections) ? 'bg-success text-white' : 'bg-warning text-dark' ?> py-2"
                 data-bs-toggle="collapse" data-bs-target="#todaySummaryBody" style="cursor:pointer;">
                <div class="d-flex align-items-center justify-content-between">
                    <h6 class="mb-0">
                        <?php if (!empty($today_inspections)): ?>
                            <i class="fas fa-check-circle me-2"></i>本日の点検完了済み
                        <?php else: ?>
                            <i class="fas fa-exclamation-circle me-2"></i>本日の点検がまだ完了していません
                        <?php endif; ?>
                    </h6>
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            <div class="collapse <?= !empty($today_inspections) ? 'show' : '' ?>" id="todaySummaryBody">
                <div class="card-body py-2">
                    <?php if (!empty($today_inspections)): ?>
                        <?php foreach ($today_inspections as $ti): ?>
                        <a href="daily_inspection.php?inspector_id=<?= urlencode($ti['driver_id']) ?>&vehicle_id=<?= urlencode($ti['vehicle_id']) ?>&date=<?= urlencode($ti['inspection_date']) ?>"
                           class="d-flex align-items-center justify-content-between py-2 px-2 border-bottom text-decoration-none text-dark rounded hover-bg-light"
                           style="transition: background 0.15s;">
                            <div>
                                <i class="fas fa-car me-1 text-muted"></i>
                                <strong><?= htmlspecialchars($ti['vehicle_number'] ?? '') ?></strong>
                                <?php if (!empty($ti['vehicle_model'])): ?>
                                    <small class="text-muted">(<?= htmlspecialchars($ti['vehicle_model']) ?>)</small>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="badge bg-success"><i class="fas fa-clock me-1"></i><?= htmlspecialchars($ti['inspection_time'] ?? '') ?></span>
                                <i class="fas fa-chevron-right ms-2 text-muted"></i>
                            </div>
                        </a>
                        <?php endforeach; ?>
                        <div class="text-muted small mt-2">
                            <i class="fas fa-info-circle me-1"></i>クリックで点検記録を表示・編集できます。新規入力は下のフォームから行えます。
                        </div>
                    <?php else: ?>
                        <p class="mb-0 text-muted"><i class="fas fa-info-circle me-1"></i>下のフォームから日常点検を実施してください。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

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

        <!-- ロック状態バッジ -->
        <?php if ($is_edit_mode && $existing_inspection): ?>
        <div class="mb-3">
            <?php if (!$is_locked): ?>
                <span class="badge bg-success fs-6"><i class="fas fa-unlock me-1"></i>編集可能（本日中）</span>
            <?php elseif ($is_locked && $can_edit): ?>
                <span class="badge bg-warning text-dark fs-6"><i class="fas fa-lock me-1"></i>ロック中（管理者解除可）</span>
            <?php else: ?>
                <span class="badge bg-danger fs-6"><i class="fas fa-lock me-1"></i>ロック済み（変更不可）</span>
            <?php endif; ?>
            <?php if ($edit_count > 0): ?>
                <span class="badge bg-info fs-6 ms-2"><i class="fas fa-pen me-1"></i>修正 <?= $edit_count ?>回</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 次のステップへの案内バナー -->
        <?php if ($success_message && $existing_inspection): ?>
        <div class="alert alert-success border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle text-success fs-3 me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">日常点検完了</h5>
                    <p class="mb-0">次は乗務前点呼を行ってください</p>
                </div>
                <a href="pre_duty_call.php?driver_id=<?= $existing_inspection['driver_id'] ?>"
                   class="btn btn-success btn-lg">
                    <i class="fas fa-clipboard-check me-2"></i>乗務前点呼へ進む
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- アラート表示 -->
        <?php if ($success_message): ?>
            <?= renderAlert('success', '完了', $success_message) ?>
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

            <!-- 進捗インジケーター -->
            <div class="card mb-3 border-0 shadow-sm" id="progressCard">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <small class="text-muted"><i class="fas fa-tasks me-1"></i>必須項目の入力状況</small>
                        <small class="fw-bold" id="progressText">0 / 7 完了</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" role="progressbar" id="progressBar"
                             style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
            </div>

            <!-- 基本情報 -->
            <?php
            $actions = [];
            if (!$is_edit_mode) {
                $actions = [
                    [
                        'icon' => 'check-circle',
                        'text' => '必須項目 全て可',
                        'url' => 'javascript:setAllOk()',
                        'class' => 'btn-success btn-sm'
                    ],
                    [
                        'icon' => 'check-double',
                        'text' => '全項目 可',
                        'url' => 'javascript:setAllOkIncludingOptional()',
                        'class' => 'btn-outline-success btn-sm'
                    ],
                    [
                        'icon' => 'times-circle',
                        'text' => '全て否',
                        'url' => 'javascript:setAllNg()',
                        'class' => 'btn-danger btn-sm'
                    ]
                ];
            }
            echo renderSectionHeader('info-circle', '基本情報', '必須項目の入力', $actions);
            ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検日 <span class="text-danger">*</span></label>
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
                            <label class="form-label">点検者（運転手） <span class="text-danger">*</span></label>
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
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    日常点検は運転手が実施します
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">車両 <span class="text-danger">*</span></label>
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
                            <label class="form-label">走行距離</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="mileage" id="mileage"
                                       value="<?= $existing_inspection ? $existing_inspection['mileage'] : '' ?>"
                                       placeholder="現在の走行距離"
                                       <?= $is_edit_mode ? 'readonly' : '' ?>>
                                <span class="input-group-text">km</span>
                            </div>
                            <?php if (!$is_edit_mode): ?>
                            <div class="alert alert-info mt-2" id="mileageInfo" style="display: none;">
                                <i class="fas fa-info-circle me-1"></i>
                                <span id="mileageText">前回記録: </span>
                            </div>
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
                                    <div class="text-muted small">※走行距離・運行状態により省略可</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略</label>
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
                                    <div class="text-muted small">※走行距離・運行状態により省略可</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略</label>
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
                                    <div class="text-muted small">※走行距離・運行状態により省略可</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略</label>
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
                    <button type="submit" class="btn btn-success btn-lg">
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
        // ロック状態をPHPからJSに渡す
        window.inspectionLockStatus = {
            isLocked: <?= json_encode($is_locked) ?>,
            canEdit: <?= json_encode($can_edit) ?>,
            needsReason: <?= json_encode($needs_reason) ?>,
            editCount: <?= json_encode($edit_count) ?>,
            lockReason: <?= json_encode($lock_reason ?? '') ?>
        };

        // 安全に関わる重要項目リスト
        const safetyCriticalItems = [
            'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
            'lights_result', 'tire_pressure_result', 'tire_damage_result'
        ];

        // 必須項目リスト（プログレスバー用）
        const requiredItemsForProgress = [
            'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
            'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
        ];

        // 元の値を保持（否→可の検出用）
        const originalValues = {};

        // プログレスバー更新
        function updateProgressBar() {
            const total = requiredItemsForProgress.length;
            let completed = 0;
            requiredItemsForProgress.forEach(function(item) {
                const checked = document.querySelector(`input[name="${item}"]:checked`);
                if (checked) completed++;
            });
            const percent = Math.round((completed / total) * 100);
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            if (progressBar) {
                progressBar.style.width = percent + '%';
                progressBar.setAttribute('aria-valuenow', percent);
            }
            if (progressText) {
                progressText.textContent = completed + ' / ' + total + ' 完了';
            }
        }

        // 安全項目の否→可変更を検出
        function checkSafetyCriticalChanges() {
            let hasChange = false;
            safetyCriticalItems.forEach(function(item) {
                const checked = document.querySelector(`input[name="${item}"]:checked`);
                if (checked && originalValues[item] === '否' && checked.value === '可') {
                    hasChange = true;
                }
            });
            const section = document.getElementById('safetyCriticalReasonSection');
            if (section) {
                section.style.display = hasChange ? 'block' : 'none';
                const textarea = document.getElementById('safetyCriticalReason');
                if (textarea) {
                    textarea.required = hasChange;
                }
            }
        }

        // 車両選択時の走行距離更新
        function updateMileage() {
            const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
            const mileageInput = document.getElementById('mileage');
            const mileageInfo = document.getElementById('mileageInfo');
            const mileageText = document.getElementById('mileageText');
            
            if (vehicleSelect.value) {
                const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
                const currentMileage = selectedOption.getAttribute('data-mileage');
                
                if (currentMileage && currentMileage !== '0') {
                    mileageText.textContent = `前回記録: ${currentMileage}km`;
                    mileageInfo.style.display = 'block';
                    
                    if (!mileageInput.value) {
                        mileageInput.value = currentMileage;
                    }
                } else {
                    mileageInfo.style.display = 'none';
                }
            } else {
                mileageInfo.style.display = 'none';
            }
        }
        
        // 点検結果の変更時にスタイル更新
        function updateInspectionItemStyle(itemName, value) {
            const item = document.querySelector(`[data-item="${itemName}"]`);
            if (item) {
                item.classList.remove('border-success', 'border-danger', 'border-warning', 'bg-success', 'bg-danger', 'bg-warning');
                
                if (value === '可') {
                    item.classList.add('border-success', 'bg-success', 'bg-opacity-10');
                } else if (value === '否') {
                    item.classList.add('border-danger', 'bg-danger', 'bg-opacity-10');
                } else if (value === '省略') {
                    item.classList.add('border-warning', 'bg-warning', 'bg-opacity-10');
                }
            }
        }
        
        // 一括選択機能
        function setAllResults(value) {
            // 必須項目のみ対象
            const requiredItems = [
                'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
                'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
            ];
            
            requiredItems.forEach(function(itemName) {
                const radio = document.querySelector(`input[name="${itemName}"][value="${value}"]`);
                if (radio) {
                    radio.checked = true;
                    updateInspectionItemStyle(itemName, value);
                }
            });
        }
        
        // 必須項目のみ全て可
        function setAllOk() {
            setAllResults('可');
            updateProgressBar();
        }
        
        // 全て否
        function setAllNg() {
            setAllResults('否');
        }

        // 全項目（必須＋省略可）を可に設定
        function setAllOkIncludingOptional() {
            const allItems = [
                'foot_brake_result', 'parking_brake_result', 'engine_start_result',
                'engine_performance_result', 'wiper_result', 'washer_spray_result',
                'brake_fluid_result', 'coolant_result', 'engine_oil_result',
                'battery_fluid_result', 'washer_fluid_result', 'fan_belt_result',
                'lights_result', 'lens_result', 'tire_pressure_result',
                'tire_damage_result', 'tire_tread_result'
            ];

            allItems.forEach(function(itemName) {
                const radio = document.querySelector('input[name="' + itemName + '"][value="可"]');
                if (radio) {
                    radio.checked = true;
                    updateInspectionItemStyle(itemName, '可');
                }
            });
            updateProgressBar();
        }
        
        // 初期化処理
        document.addEventListener('DOMContentLoaded', function() {
            // 車両選択の初期設定
            updateMileage();

            // 既存の点検結果のスタイル適用 & 元の値を記録
            const radioButtons = document.querySelectorAll('input[type="radio"]:checked');
            radioButtons.forEach(function(radio) {
                const itemName = radio.name;
                const value = radio.value;
                updateInspectionItemStyle(itemName, value);
                originalValues[itemName] = value;
            });

            // ラジオボタンの変更イベント
            const allRadios = document.querySelectorAll('input[type="radio"]');
            allRadios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    updateInspectionItemStyle(this.name, this.value);
                    updateProgressBar();
                    checkSafetyCriticalChanges();
                });
            });

            // 初期プログレスバー更新
            updateProgressBar();

            // 下書き機能（編集モードでない場合のみ）
            const isEditMode = !!document.querySelector('input[name="inspector_id"][type="hidden"]');
            if (!isEditMode) {
                // 下書きが存在する場合、バナーを表示
                const draft = localStorage.getItem('inspection_draft');
                if (draft) {
                    const draftBanner = document.getElementById('draftBanner');
                    if (draftBanner) {
                        draftBanner.classList.remove('d-none');
                    }
                }

                // 30秒ごとに自動保存
                setInterval(function() {
                    saveDraft();
                }, 30000);
            }
        });
        
        // フォーム送信前の確認
        document.getElementById('inspectionForm').addEventListener('submit', function(e) {
            var inspectorField = document.querySelector('select[name="inspector_id"]') || document.querySelector('input[name="inspector_id"]');
            var vehicleField = document.querySelector('select[name="vehicle_id"]') || document.querySelector('input[name="vehicle_id"]');
            const inspectorId = inspectorField ? inspectorField.value : '';
            const vehicleId = vehicleField ? vehicleField.value : '';
            
            if (!inspectorId || !vehicleId) {
                e.preventDefault();
                alert('点検者と車両を選択してください。');
                return;
            }
            
            // 必須項目のチェック
            const requiredItems = [
                'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
                'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
            ];
            
            let missingItems = [];
            requiredItems.forEach(function(item) {
                const checked = document.querySelector(`input[name="${item}"]:checked`);
                if (!checked) {
                    missingItems.push(item);
                }
            });
            
            if (missingItems.length > 0) {
                e.preventDefault();
                alert('必須点検項目に未選択があります。すべての必須項目を選択してください。');
                return;
            }
            
            // 修正理由が必須の場合のチェック
            const editReasonSection = document.getElementById('editReasonSection');
            if (editReasonSection && editReasonSection.style.display !== 'none') {
                const editReason = document.getElementById('editReason');
                if (editReason && !editReason.value.trim()) {
                    e.preventDefault();
                    alert('ロック済みレコードの修正には理由の記入が必要です。');
                    editReason.focus();
                    return;
                }
            }

            // 安全項目変更理由のチェック
            const safetyCriticalSection = document.getElementById('safetyCriticalReasonSection');
            if (safetyCriticalSection && safetyCriticalSection.style.display !== 'none') {
                const safetyReason = document.getElementById('safetyCriticalReason');
                if (safetyReason && !safetyReason.value.trim()) {
                    e.preventDefault();
                    alert('安全に関わる項目の変更理由を入力してください。');
                    safetyReason.focus();
                    return;
                }
            }

            // 「否」の項目がある場合の確認
            const ngItems = document.querySelectorAll('input[value="否"]:checked');
            if (ngItems.length > 0) {
                const defectDetails = document.querySelector('textarea[name="defect_details"]').value.trim();
                if (!defectDetails) {
                    if (!confirm('点検結果に「否」がありますが、不良個所の詳細が未記入です。このまま保存しますか？')) {
                        e.preventDefault();
                        return;
                    }
                }
            }

            // 送信成功時に下書きをクリア
            localStorage.removeItem('inspection_draft');
        });

        // 編集モード有効化
        function enableEditMode() {
            const lockStatus = window.inspectionLockStatus || {};

            // ロック中かつ編集不可の場合は何もしない
            if (lockStatus.isLocked && !lockStatus.canEdit) {
                alert(lockStatus.lockReason || 'この記録は編集できません。');
                return;
            }

            // readonly/disabled属性を削除
            document.querySelectorAll('input[readonly], textarea[readonly]').forEach(element => {
                element.removeAttribute('readonly');
            });

            document.querySelectorAll('input[disabled]').forEach(element => {
                element.removeAttribute('disabled');
            });

            // 基本情報の選択フィールドを復元
            const inspectorId = document.querySelector('input[name="inspector_id"]').value;
            const vehicleId = document.querySelector('input[name="vehicle_id"]').value;
            const inspectionDate = document.querySelector('input[name="inspection_date"]').value;

            // 点検日フィールドを復元
            const dateInput = document.querySelector('input[name="inspection_date_display"]');
            if (dateInput) {
                dateInput.name = 'inspection_date';
                dateInput.type = 'date';
                dateInput.removeAttribute('readonly');
            }

            // ロック済みレコードの場合：修正理由セクションを表示
            if (lockStatus.needsReason) {
                const reasonSection = document.getElementById('editReasonSection');
                if (reasonSection) {
                    reasonSection.style.display = 'block';
                    const reasonTextarea = document.getElementById('editReason');
                    if (reasonTextarea) {
                        reasonTextarea.required = true;
                    }
                }
            }

            // プログレスバー更新
            updateProgressBar();

            // ボタンを変更
            const actionButtons = document.getElementById('actionButtons');
            actionButtons.innerHTML = `
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-save me-2"></i>変更を保存
                </button>
                <button type="button" class="btn btn-secondary btn-lg ms-2" onclick="location.reload()">
                    <i class="fas fa-times me-2"></i>キャンセル
                </button>
            `;
        }

        // フォーム未保存データの離脱警告
        var formDirty = false;
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('inspectionForm');
            if (form) {
                form.addEventListener('input', function() { formDirty = true; });
                form.addEventListener('change', function() { formDirty = true; });
                form.addEventListener('submit', function() { formDirty = false; });
            }
        });
        window.addEventListener('beforeunload', function(e) {
            if (formDirty) { e.preventDefault(); }
        });

        // 削除確認（管理者のみ、理由入力付き・監査ログ記録）
        function confirmDelete() {
            const reason = prompt('削除理由を入力してください（監査ログに記録されます）:');
            if (reason === null) return;
            if (reason.trim() === '') {
                alert('削除理由を入力してください。');
                return;
            }
            if (confirm('本当に削除しますか？この操作は監査ログに記録されます。')) {
                const deleteReasonInput = document.getElementById('deleteReason');
                if (deleteReasonInput) {
                    deleteReasonInput.value = reason;
                }
                document.getElementById('deleteForm').submit();
            }
        }
        // 印刷機能
        function printInspection() {
            // 印刷ヘッダーに値をセット
            var dateEl = document.querySelector('input[name="inspection_date"]') || document.querySelector('input[name="inspection_date_display"]');
            var timeEl = document.querySelector('input[name="inspection_time"]');
            var inspectorEl = document.querySelector('select[name="inspector_id"]') || document.querySelector('input[name="inspector_id"]');
            var vehicleEl = document.querySelector('select[name="vehicle_id"]') || document.querySelector('input[name="vehicle_id"]');
            var mileageEl = document.getElementById('mileage');

            document.getElementById('printDate').textContent = dateEl ? dateEl.value : '';
            document.getElementById('printTime').textContent = timeEl ? timeEl.value : '';

            // 点検者名
            if (inspectorEl && inspectorEl.tagName === 'SELECT') {
                document.getElementById('printInspector').textContent = inspectorEl.options[inspectorEl.selectedIndex]?.text || '';
            } else {
                var inspectorText = inspectorEl ? inspectorEl.closest('.col-md-6').querySelector('input[type="text"][readonly]') : null;
                document.getElementById('printInspector').textContent = inspectorText ? inspectorText.value : (inspectorEl ? inspectorEl.value : '');
            }

            // 車両名
            if (vehicleEl && vehicleEl.tagName === 'SELECT') {
                document.getElementById('printVehicle').textContent = vehicleEl.options[vehicleEl.selectedIndex]?.text || '';
            } else {
                var vehicleText = vehicleEl ? vehicleEl.closest('.col-md-6').querySelector('input[type="text"][readonly]') : null;
                document.getElementById('printVehicle').textContent = vehicleText ? vehicleText.value : (vehicleEl ? vehicleEl.value : '');
            }

            document.getElementById('printMileage').textContent = mileageEl && mileageEl.value ? mileageEl.value + ' km' : '未記入';

            // 各点検項目の結果テキストをセット
            document.querySelectorAll('.print-result-text').forEach(function(span) {
                var itemName = span.getAttribute('data-print-for');
                var checked = document.querySelector('input[name="' + itemName + '"]:checked');
                span.textContent = '';
                span.className = 'print-result-text';
                if (checked) {
                    span.textContent = checked.value;
                    if (checked.value === '可') span.classList.add('print-result-ok');
                    else if (checked.value === '否') span.classList.add('print-result-ng');
                    else span.classList.add('print-result-skip');
                } else {
                    span.textContent = '未選択';
                    span.classList.add('print-result-none');
                }
            });

            // 不良個所・備考
            var defectsEl = document.querySelector('textarea[name="defect_details"]');
            var remarksEl = document.querySelector('textarea[name="remarks"]');
            document.getElementById('printDefects').textContent = defectsEl ? (defectsEl.value || 'なし') : 'なし';
            document.getElementById('printRemarks').textContent = remarksEl ? (remarksEl.value || 'なし') : 'なし';

            // 印刷日
            document.getElementById('printDateStamp').textContent = new Date().toLocaleDateString('ja-JP');

            // 印刷実行
            window.print();
        }

        // === 下書き保存・復元機能 ===
        const draftRadioNames = [
            'foot_brake_result', 'parking_brake_result', 'engine_start_result',
            'engine_performance_result', 'wiper_result', 'washer_spray_result',
            'brake_fluid_result', 'coolant_result', 'engine_oil_result',
            'battery_fluid_result', 'washer_fluid_result', 'fan_belt_result',
            'lights_result', 'lens_result', 'tire_pressure_result',
            'tire_damage_result', 'tire_tread_result'
        ];

        function saveDraft() {
            const form = document.getElementById('inspectionForm');
            if (!form) return;

            const draft = {
                timestamp: new Date().toISOString()
            };

            // セレクト要素
            const inspectorSelect = form.querySelector('select[name="inspector_id"]');
            if (inspectorSelect) draft.inspector_id = inspectorSelect.value;

            const vehicleSelect = form.querySelector('select[name="vehicle_id"]');
            if (vehicleSelect) draft.vehicle_id = vehicleSelect.value;

            // 入力要素
            const inspectionDate = form.querySelector('input[name="inspection_date"]');
            if (inspectionDate) draft.inspection_date = inspectionDate.value;

            const inspectionTime = form.querySelector('input[name="inspection_time"]');
            if (inspectionTime) draft.inspection_time = inspectionTime.value;

            const mileage = form.querySelector('input[name="mileage"]');
            if (mileage) draft.mileage = mileage.value;

            // ラジオボタン（17項目）
            draft.radios = {};
            draftRadioNames.forEach(function(name) {
                const checked = form.querySelector('input[name="' + name + '"]:checked');
                if (checked) {
                    draft.radios[name] = checked.value;
                }
            });

            // テキストエリア
            const defectDetails = form.querySelector('textarea[name="defect_details"]');
            if (defectDetails) draft.defect_details = defectDetails.value;

            const remarks = form.querySelector('textarea[name="remarks"]');
            if (remarks) draft.remarks = remarks.value;

            localStorage.setItem('inspection_draft', JSON.stringify(draft));
        }

        function restoreDraft() {
            const draftJson = localStorage.getItem('inspection_draft');
            if (!draftJson) return;

            const draft = JSON.parse(draftJson);
            const form = document.getElementById('inspectionForm');
            if (!form) return;

            // セレクト要素
            if (draft.inspector_id) {
                const inspectorSelect = form.querySelector('select[name="inspector_id"]');
                if (inspectorSelect) inspectorSelect.value = draft.inspector_id;
            }

            if (draft.vehicle_id) {
                const vehicleSelect = form.querySelector('select[name="vehicle_id"]');
                if (vehicleSelect) {
                    vehicleSelect.value = draft.vehicle_id;
                    if (typeof updateMileage === 'function') updateMileage();
                }
            }

            // 入力要素
            if (draft.inspection_date) {
                const inspectionDate = form.querySelector('input[name="inspection_date"]');
                if (inspectionDate) inspectionDate.value = draft.inspection_date;
            }

            if (draft.inspection_time) {
                const inspectionTime = form.querySelector('input[name="inspection_time"]');
                if (inspectionTime) inspectionTime.value = draft.inspection_time;
            }

            if (draft.mileage) {
                const mileage = form.querySelector('input[name="mileage"]');
                if (mileage) mileage.value = draft.mileage;
            }

            // ラジオボタン復元
            if (draft.radios) {
                Object.keys(draft.radios).forEach(function(name) {
                    const radio = form.querySelector('input[name="' + name + '"][value="' + draft.radios[name] + '"]');
                    if (radio) {
                        radio.checked = true;
                        updateInspectionItemStyle(name, draft.radios[name]);
                    }
                });
                updateProgressBar();
            }

            // テキストエリア
            if (draft.defect_details) {
                const defectDetails = form.querySelector('textarea[name="defect_details"]');
                if (defectDetails) defectDetails.value = draft.defect_details;
            }

            if (draft.remarks) {
                const remarks = form.querySelector('textarea[name="remarks"]');
                if (remarks) remarks.value = draft.remarks;
            }

            // バナーを非表示
            const draftBanner = document.getElementById('draftBanner');
            if (draftBanner) draftBanner.classList.add('d-none');
        }

        function discardDraft() {
            localStorage.removeItem('inspection_draft');
            const draftBanner = document.getElementById('draftBanner');
            if (draftBanner) draftBanner.classList.add('d-none');
        }
    </script>
