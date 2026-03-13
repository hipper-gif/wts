<?php
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

/**
 * 点呼記録の監査ログ
 */
function logCallAudit($pdo, $call_id, $action, $user_id, $changes = [], $reason = null) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (empty($changes)) {
            $stmt = $pdo->prepare("INSERT INTO inspection_audit_logs
                (inspection_id, action, edited_by, field_changed, old_value, new_value, reason, ip_address, user_agent)
                VALUES (?, ?, ?, 'pre_duty_call', NULL, NULL, ?, ?, ?)");
            $stmt->execute([$call_id, $action, $user_id, $reason, $ip_address, $user_agent]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO inspection_audit_logs
                (inspection_id, action, edited_by, field_changed, old_value, new_value, reason, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($changes as $change) {
                $stmt->execute([$call_id, $action, $user_id, $change['field'], $change['old'], $change['new'], $reason, $ip_address, $user_agent]);
            }
        }
    } catch (Exception $e) {
        error_log("点呼監査ログエラー: " . $e->getMessage());
    }
}

/**
 * 点呼記録の編集可否判定（当日は自由、過去は管理者のみ理由必須）
 */
function canEditCall($call, $user_role) {
    $today = date('Y-m-d');
    $call_date = $call['call_date'];
    if ($call_date === $today) {
        return ['can_edit' => true, 'needs_reason' => false, 'lock_reason' => ''];
    }
    if ($call_date < $today && $user_role === 'Admin') {
        return ['can_edit' => true, 'needs_reason' => true, 'lock_reason' => '過去日の記録です。管理者権限で修正できます。'];
    }
    return ['can_edit' => false, 'needs_reason' => false, 'lock_reason' => '過去日の記録はロックされています。管理者にお問い合わせください。'];
}

// $pdo, $user_id, $user_name, $user_role は session_check.php で設定済み
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';
$is_edit_mode = false;

// ドライバーと点呼者の取得
try {
    // 運転者取得（is_driverフラグのみ）
    $drivers = getActiveDrivers($pdo);

    // 点呼者取得（is_callerフラグのみ）
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_caller = 1 AND is_active = 1 ORDER BY name");
    $stmt->execute();
    $callers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ログインユーザーが運転者かチェック
    $stmt = $pdo->prepare("SELECT is_driver FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch();
    $is_current_user_driver = $current_user && $current_user['is_driver'];

} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $drivers = [];
    $callers = [];
    $is_current_user_driver = false;
}

// 今日の点呼記録があるかチェック
$existing_call = null;
$selected_driver_id = null;

if ($_GET['driver_id'] ?? null) {
    $selected_driver_id = $_GET['driver_id'];
} elseif ($is_current_user_driver) {
    // ログインユーザーが運転者の場合はデフォルト選択
    $selected_driver_id = $user_id;
}

if ($selected_driver_id) {
    $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$selected_driver_id, $today]);
    $existing_call = $stmt->fetch();
    $is_edit_mode = (bool)$existing_call;
}

// ロック状態の判定
$is_locked = false;
$can_edit = true;
$needs_reason = false;
$lock_reason = '';

if ($existing_call) {
    $edit_permission = canEditCall($existing_call, $user_role);
    $can_edit = $edit_permission['can_edit'];
    $needs_reason = $edit_permission['needs_reason'];
    $lock_reason = $edit_permission['lock_reason'];
    $is_locked = ($existing_call['call_date'] < $today);
}

// 修正・削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    if ($_POST['action'] === 'delete') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$_POST['driver_id'], $_POST['call_date'] ?? $today]);
            $delete_target = $stmt->fetch();

            if (!$delete_target) {
                $error_message = '削除対象の記録が見つかりません。';
            } elseif ($user_role !== 'Admin' && $delete_target['driver_id'] != $user_id) {
                $error_message = '削除は管理者または記録した運転者のみ実行できます。';
            } else {
                $edit_check = canEditCall($delete_target, $user_role);
                if (!$edit_check['can_edit']) {
                    $error_message = $edit_check['lock_reason'];
                } else {
                    $pdo->beginTransaction();
                    $delete_reason = $_POST['delete_reason'] ?? '削除';
                    $stmt = $pdo->prepare("DELETE FROM pre_duty_calls WHERE driver_id = ? AND call_date = ?");
                    $stmt->execute([$_POST['driver_id'], $_POST['call_date'] ?? $today]);
                    logCallAudit($pdo, $delete_target['id'], 'delete', $user_id, [], $delete_reason);
                    $pdo->commit();
                    $success_message = '乗務前点呼記録を削除しました。';
                    $existing_call = null;
                    $is_edit_mode = false;
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = '削除中にエラーが発生しました。';
            error_log("Pre duty call delete error: " . $e->getMessage());
        }
    }
}

// フォーム送信処理（登録・更新）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    validateCsrfToken();
    $driver_id = $_POST['driver_id'];
    $call_time = $_POST['call_time'];

    // 使用予定車両を取得（前回使用車両から推定）
    $stmt = $pdo->prepare("
        SELECT v.id 
        FROM vehicles v 
        WHERE v.is_active = TRUE 
        AND v.id = (
            SELECT ar.vehicle_id 
            FROM arrival_records ar 
            WHERE ar.driver_id = ? 
            ORDER BY ar.arrival_date DESC 
            LIMIT 1
        )
    ");
    $stmt->execute([$driver_id]);
    $vehicle_record = $stmt->fetch();

    if (!$vehicle_record) {
        // デフォルト車両を使用
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number LIMIT 1");
        $stmt->execute();
        $vehicle_record = $stmt->fetch();
    }

    if (!$vehicle_record) {
        $error_message = '使用可能な車両が見つかりません。車両管理画面で車両を登録してください。';
    } else {
        $vehicle_id = $vehicle_record['id'];

        // 点呼者名の処理
        $caller_name = $_POST['caller_name'];
        if ($caller_name === 'その他') {
            $caller_name = $_POST['other_caller'];
        }

        $alcohol_check_value = $_POST['alcohol_check_value'];

        // 16項目確認事項のチェック
        $check_items = [
            'health_check', 'clothing_check', 'footwear_check', 'pre_inspection_check',
            'license_check', 'vehicle_registration_check', 'insurance_check', 'emergency_tools_check',
            'map_check', 'taxi_card_check', 'emergency_signal_check', 'change_money_check',
            'crew_id_check', 'operation_record_check', 'receipt_check', 'stop_sign_check'
        ];

        try {
            // 既存レコードの確認
            $stmt = $pdo->prepare("SELECT id FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing = $stmt->fetch();

            if ($existing) {
                // 更新
                $pdo->beginTransaction();
                $sql = "UPDATE pre_duty_calls SET
                        call_time = ?, caller_name = ?, alcohol_check_value = ?, alcohol_check_time = ?,";

                foreach ($check_items as $item) {
                    $sql .= " $item = ?,";
                }

                $sql .= " remarks = ?, is_completed = TRUE, updated_at = NOW()
                        WHERE driver_id = ? AND call_date = ?";

                $stmt = $pdo->prepare($sql);
                $params = [$call_time, $caller_name, $alcohol_check_value, $call_time];

                foreach ($check_items as $item) {
                    $params[] = isset($_POST[$item]) ? 1 : 0;
                }

                $params[] = $_POST['remarks'] ?? '';
                $params[] = $driver_id;
                $params[] = $today;

                $stmt->execute($params);
                logCallAudit($pdo, $existing['id'], 'edit', $user_id, [], $_POST['edit_reason'] ?? null);
                $pdo->commit();
                $success_message = '乗務前点呼記録を更新しました。';
            } else {
                // 新規挿入
                $pdo->beginTransaction();
                $sql = "INSERT INTO pre_duty_calls (
                        driver_id, vehicle_id, call_date, call_time, caller_name,
                        alcohol_check_value, alcohol_check_time,";

                foreach ($check_items as $item) {
                    $sql .= " $item,";
                }

                $sql .= " remarks, is_completed) VALUES (?, ?, ?, ?, ?, ?, ?,";

                $sql .= str_repeat('?,', count($check_items));
                $sql .= " ?, TRUE)";

                $stmt = $pdo->prepare($sql);
                $params = [$driver_id, $vehicle_id, $today, $call_time, $caller_name, $alcohol_check_value, $call_time];

                foreach ($check_items as $item) {
                    $params[] = isset($_POST[$item]) ? 1 : 0;
                }

                $params[] = $_POST['remarks'] ?? '';

                $stmt->execute($params);
                $new_call_id = $pdo->lastInsertId();
                logCallAudit($pdo, $new_call_id, 'create', $user_id);
                $pdo->commit();
                $success_message = '乗務前点呼記録を登録しました。';
            }

            // 記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing_call = $stmt->fetch();
            $is_edit_mode = true;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = '記録の保存中にエラーが発生しました。';
            error_log("Pre duty call error: " . $e->getMessage());
        }
    }
}

// ページ設定
$page_config = getPageConfiguration('pre_duty_call');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [],
    'additional_js' => [],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '乗務前点呼', 'url' => 'pre_duty_call.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'pre_duty_call',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

// HTMLヘッダー出力
echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<!-- メインコンテンツ開始 -->
<main class="main-content">
    <div class="container-fluid py-4">
        
        <!-- 次のステップへの案内バナー -->
        <?php if ($existing_call && $existing_call['is_completed']): ?>
        <div class="alert alert-success border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle text-success fs-3 me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">乗務前点呼完了</h5>
                    <p class="mb-0">次は出庫処理を行ってください</p>
                </div>
                <a href="departure.php?driver_id=<?= $existing_call['driver_id'] ?>" 
                   class="btn btn-success btn-lg">
                    <i class="fas fa-car me-2"></i>出庫処理へ進む
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- アラート表示 -->
        <?php if ($success_message): ?>
            <?= renderAlert('success', '保存完了', $success_message) ?>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>

        <?php if ($is_edit_mode && $existing_call): ?>
        <div class="mb-3">
            <?php if (!$is_locked): ?>
                <span class="badge bg-success fs-6"><i class="fas fa-unlock me-1"></i>編集可能（本日中）</span>
            <?php elseif ($is_locked && $can_edit): ?>
                <span class="badge bg-warning text-dark fs-6"><i class="fas fa-lock me-1"></i>ロック中（管理者解除可）</span>
            <?php else: ?>
                <span class="badge bg-danger fs-6"><i class="fas fa-lock me-1"></i>ロック済み（変更不可）</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- メインフォーム -->
        <div class="row">
            <div class="col-lg-8">
                <form method="POST" id="pre-duty-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <?php if ($is_edit_mode): ?>
                        <input type="hidden" name="driver_id" value="<?= $existing_call['driver_id'] ?>">
                    <?php endif; ?>

                    <!-- 基本情報 -->
                    <?php
                    echo renderSectionHeader('user', '基本情報', '点呼実施者・時刻');
                    ?>

                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">運転者 <span class="text-danger">*</span></label>
                                    <select class="form-select" name="driver_id" <?= $is_edit_mode ? 'disabled' : 'onchange="if(this.value) location.href=\'pre_duty_call.php?driver_id=\'+this.value"' ?> required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>" 
                                            <?= ($selected_driver_id == $driver['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($driver['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">点呼時刻 <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" name="call_time" 
                                        value="<?= $existing_call ? $existing_call['call_time'] : $current_time ?>" 
                                        <?= $is_edit_mode ? 'readonly' : '' ?> required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">点呼者 <span class="text-danger">*</span></label>
                                    <select class="form-select" name="caller_name" id="caller_name" <?= $is_edit_mode ? 'disabled' : '' ?> required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($callers as $caller): ?>
                                        <option value="<?= htmlspecialchars($caller['name']) ?>" 
                                            <?= ($existing_call && $existing_call['caller_name'] == $caller['name']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($caller['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <option value="その他">その他</option>
                                    </select>
                                    <input type="text" class="form-control mt-2" id="other_caller" name="other_caller" 
                                        placeholder="その他の場合は名前を入力" style="display: none;"
                                        <?= $is_edit_mode ? 'readonly' : '' ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 確認事項セクション -->
                    <?php
                    $check_actions = [];
                    if (!$is_edit_mode) {
                        $check_actions[] = [
                            'icon' => 'check-double',
                            'text' => '全てチェック',
                            'url' => 'javascript:checkAll()',
                            'class' => 'btn-success btn-sm'
                        ];
                        $check_actions[] = [
                            'icon' => 'times',
                            'text' => '全て解除',
                            'url' => 'javascript:uncheckAll()',
                            'class' => 'btn-warning btn-sm'
                        ];
                    }
                    echo renderSectionHeader('tasks', '確認事項', '16項目の法定確認事項', $check_actions);
                    ?>

                    <div class="card mb-4">
                        <div class="card-body">
                            <?php
                            $check_items = [
                                'health_check' => ['健康状態', true],
                                'clothing_check' => ['服装', false],
                                'footwear_check' => ['履物', false],
                                'pre_inspection_check' => ['運行前点検', true],
                                'license_check' => ['免許証', true],
                                'vehicle_registration_check' => ['車検証', false],
                                'insurance_check' => ['保険証', false],
                                'emergency_tools_check' => ['応急工具', false],
                                'map_check' => ['地図', false],
                                'taxi_card_check' => ['タクシーカード', false],
                                'emergency_signal_check' => ['非常信号用具', false],
                                'change_money_check' => ['釣銭', false],
                                'crew_id_check' => ['乗務員証', false],
                                'operation_record_check' => ['運行記録用用紙', false],
                                'receipt_check' => ['領収書', false],
                                'stop_sign_check' => ['停止表示機', false]
                            ];
                            ?>

                            <div class="row g-3">
                                <?php $i = 1; foreach ($check_items as $key => $item): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="form-check p-3 border rounded <?= $is_edit_mode ? '' : 'check-item-clickable' ?> <?= ($existing_call && $existing_call[$key]) ? 'bg-success bg-opacity-10 border-success' : '' ?>" 
                                         <?= $is_edit_mode ? '' : 'onclick="toggleCheck(\'' . $key . '\')"' ?>>
                                        <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>" 
                                            <?= ($existing_call && $existing_call[$key]) ? 'checked' : '' ?>
                                            <?= $is_edit_mode ? 'disabled' : '' ?>>
                                        <label class="form-check-label fw-bold" for="<?= $key ?>">
                                            <span class="badge bg-primary me-2"><?= $i ?></span>
                                            <?= $item[0] ?>
                                            <?php if ($item[1]): ?>
                                                <span class="text-danger">*</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                                <?php $i++; endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- アルコールチェック -->
                    <?php
                    echo renderSectionHeader('shield-alt', 'アルコールチェック', '法定義務化対応');
                    ?>

                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">測定値 (mg/L) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="alcohol_check_value" 
                                            step="0.001" min="0" max="1" 
                                            value="<?= $existing_call ? $existing_call['alcohol_check_value'] : '0.000' ?>"
                                            <?= $is_edit_mode ? 'readonly' : '' ?> required>
                                        <span class="input-group-text">mg/L</span>
                                        <?php if (!$is_edit_mode): ?>
                                        <button type="button" class="btn btn-outline-success" onclick="setAlcoholZero()">
                                            0.000設定
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 備考 -->
                    <?php
                    echo renderSectionHeader('sticky-note', '備考', '特記事項・注意点');
                    ?>

                    <div class="card mb-4">
                        <div class="card-body">
                            <textarea class="form-control" name="remarks" rows="3" 
                                placeholder="特記事項があれば記入してください..."
                                <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_call ? htmlspecialchars($existing_call['remarks']) : '' ?></textarea>
                        </div>
                    </div>

                    <!-- 修正理由（ロック済みレコード編集時） -->
                    <div class="card mb-3 border-warning" id="editReasonSection" style="display:none;">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>修正理由（必須）</h6>
                        </div>
                        <div class="card-body">
                            <textarea name="edit_reason" id="editReason" class="form-control" rows="2"
                                      placeholder="修正理由を入力してください"></textarea>
                            <small class="text-muted">ロック済みレコードの修正には理由が必要です。監査ログに記録されます。</small>
                        </div>
                    </div>

                    <!-- 操作ボタン -->
                    <div class="text-center mb-4" id="actionButtons" style="position: sticky; bottom: 0; z-index: 50; background: white; padding: 12px 0; border-top: 1px solid #dee2e6;">
                        <?php if ($is_edit_mode && $is_locked && !$can_edit): ?>
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
                                <i class="fas fa-save me-2"></i>登録する
                            </button>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- 削除フォーム -->
                <form method="POST" id="delete-form" style="display: none;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="driver_id" value="<?= $existing_call['driver_id'] ?? '' ?>">
                    <input type="hidden" name="call_date" value="<?= $existing_call['call_date'] ?? '' ?>">
                    <input type="hidden" name="delete_reason" id="deleteReason" value="">
                </form>
            </div>

            <!-- サイドバー -->
            <div class="col-lg-4">
                <!-- 本日の記録 -->
                <?php
                echo renderSectionHeader('calendar-day', '本日の記録', date('Y年m月d日'));
                ?>

                <div class="card mb-4">
                    <div class="card-body">
                        <?php if ($existing_call): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                乗務前点呼完了済み
                            </div>
                            <ul class="list-unstyled mb-0">
                                <li><strong>点呼時刻:</strong> <?= $existing_call['call_time'] ?></li>
                                <li><strong>点呼者:</strong> <?= htmlspecialchars($existing_call['caller_name']) ?></li>
                                <li><strong>アルコール値:</strong> <?= $existing_call['alcohol_check_value'] ?> mg/L</li>
                            </ul>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                未実施
                            </div>
                            <p class="mb-0 text-muted">本日の乗務前点呼はまだ実施されていません。</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 関連リンク -->
                <?php
                echo renderSectionHeader('link', '関連機能', 'クイックアクセス');
                ?>

                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="daily_inspection.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-tools me-2"></i>日常点検
                            </a>
                            <?php if ($existing_call && $existing_call['is_completed']): ?>
                            <a href="departure.php?driver_id=<?= $existing_call['driver_id'] ?>" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-car me-2"></i>出庫処理
                            </a>
                            <?php endif; ?>
                            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-home me-2"></i>ダッシュボード
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.check-item-clickable {
    cursor: pointer;
    transition: all 0.3s ease;
}

.check-item-clickable:hover {
    background-color: var(--bs-primary-bg-subtle) !important;
    border-color: var(--bs-primary) !important;
    transform: translateY(-1px);
}

.input-group .btn {
    border-left: 0;
}
</style>

<script>
// ロック状態
window.callLockStatus = {
    isLocked: <?= json_encode($is_locked ?? false) ?>,
    canEdit: <?= json_encode($can_edit ?? true) ?>,
    needsReason: <?= json_encode($needs_reason ?? false) ?>,
    lockReason: <?= json_encode($lock_reason ?? '') ?>
};

// 点呼者選択の「その他」処理
document.getElementById('caller_name').addEventListener('change', function() {
    const otherInput = document.getElementById('other_caller');
    if (this.value === 'その他') {
        otherInput.style.display = 'block';
        otherInput.required = true;
    } else {
        otherInput.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
});

// チェック項目の個別切り替え
function toggleCheck(itemId) {
    const checkbox = document.getElementById(itemId);
    const container = checkbox.closest('.form-check');
    checkbox.checked = !checkbox.checked;
    if (checkbox.checked) {
        container.classList.add('bg-success', 'bg-opacity-10', 'border-success');
    } else {
        container.classList.remove('bg-success', 'bg-opacity-10', 'border-success');
    }
}

// 全てチェック
function checkAll() {
    document.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
        cb.checked = true;
        var container = cb.closest('.form-check');
        if (container) container.classList.add('bg-success', 'bg-opacity-10', 'border-success');
    });
}

// 全て解除
function uncheckAll() {
    document.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
        cb.checked = false;
        var container = cb.closest('.form-check');
        if (container) container.classList.remove('bg-success', 'bg-opacity-10', 'border-success');
    });
}

// アルコール値0.000設定
function setAlcoholZero() {
    document.querySelector('input[name="alcohol_check_value"]').value = '0.000';
}

// 編集モード有効化
function enableEditMode() {
    var lockStatus = window.callLockStatus || {};
    if (lockStatus.isLocked && !lockStatus.canEdit) {
        alert(lockStatus.lockReason || 'この記録は編集できません。');
        return;
    }

    // フォーム要素を編集可能に
    document.querySelectorAll('input[readonly], textarea[readonly]').forEach(function(el) {
        el.removeAttribute('readonly');
    });
    document.querySelectorAll('input[disabled], select[disabled]').forEach(function(el) {
        el.removeAttribute('disabled');
    });

    // チェック項目をクリック可能に
    document.querySelectorAll('.form-check.border').forEach(function(item) {
        var cb = item.querySelector('input[type="checkbox"]');
        if (cb) {
            item.classList.add('check-item-clickable');
            item.setAttribute('onclick', "toggleCheck('" + cb.id + "')");
        }
    });

    // ロック済みレコードの場合：修正理由表示
    if (lockStatus.needsReason) {
        var reasonSection = document.getElementById('editReasonSection');
        if (reasonSection) {
            reasonSection.style.display = 'block';
            var textarea = document.getElementById('editReason');
            if (textarea) textarea.required = true;
        }
    }

    // ボタンを変更
    var actionButtons = document.getElementById('actionButtons');
    if (actionButtons) {
        actionButtons.innerHTML = '<button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-2"></i>変更を保存</button>' +
            '<button type="button" class="btn btn-secondary" onclick="location.reload()"><i class="fas fa-times me-2"></i>キャンセル</button>';
    }
}

// 削除確認（理由入力付き）
function confirmDelete() {
    var reason = prompt('削除理由を入力してください（監査ログに記録されます）:');
    if (reason === null) return;
    if (reason.trim() === '') {
        alert('削除理由を入力してください。');
        return;
    }
    if (confirm('本当に削除しますか？この操作は監査ログに記録されます。')) {
        var deleteReasonInput = document.getElementById('deleteReason');
        if (deleteReasonInput) deleteReasonInput.value = reason;
        document.getElementById('delete-form').submit();
    }
}

// フォーム未保存データの離脱警告
var formDirty = false;
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('pre-duty-form');
    if (form) {
        form.addEventListener('input', function() { formDirty = true; });
        form.addEventListener('change', function() { formDirty = true; });
        form.addEventListener('submit', function() { formDirty = false; });
    }
});
window.addEventListener('beforeunload', function(e) {
    if (formDirty) { e.preventDefault(); }
});
</script>

<?= $page_data['html_footer'] ?>
