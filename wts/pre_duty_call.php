<?php
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

function canEditCall($call, $user_role) {
    return canEditByDate($call, 'call_date', $user_role);
}

// $pdo, $user_id, $user_name, $user_role は session_check.php で設定済み
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$current_time = date('H:i');

$success_message = '';
$error_message = '';
$is_edit_mode = false;
$form_old = null; // 保存が通らなかった時に、入れた値を残して出し直す

// 保存・削除のあとは画面を出し直してから知らせる（再読込で同じ保存がもう1回走らないように）
if (!empty($_SESSION['pre_duty_flash'])) {
    $success_message = $_SESSION['pre_duty_flash'];
    unset($_SESSION['pre_duty_flash']);
}

// 16項目確認事項（保存の列）
$check_items = [
    'health_check', 'clothing_check', 'footwear_check', 'pre_inspection_check',
    'license_check', 'vehicle_registration_check', 'insurance_check', 'emergency_tools_check',
    'map_check', 'taxi_card_check', 'emergency_signal_check', 'change_money_check',
    'crew_id_check', 'operation_record_check', 'receipt_check', 'stop_sign_check'
];

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

// どの運転者の・どの日の点呼か（送信された時は送信された値、開いただけの時はURLの値）。乗務後点呼と同じ形（ADR-0016）
$is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$selected_driver_id = null;
if ($is_post && !empty($_POST['driver_id'])) {
    $selected_driver_id = (int)$_POST['driver_id'];
} elseif (!empty($_GET['driver_id'])) {
    $selected_driver_id = (int)$_GET['driver_id'];
} elseif ($is_current_user_driver) {
    // ログインユーザーが運転者の場合はデフォルト選択
    $selected_driver_id = (int)$user_id;
}
$call_date = normalizeDutyCallDate($is_post ? ($_POST['call_date'] ?? $today) : ($_GET['date'] ?? $today), $today);

$self_url = function ($driver_id, $date) use ($today) {
    $q = [];
    if ($driver_id) $q['driver_id'] = (int)$driver_id;
    if ($date && $date !== $today) $q['date'] = $date;
    return 'pre_duty_call.php' . ($q ? '?' . http_build_query($q) : '');
};

// 削除処理
if ($is_post && isset($_POST['action'])) {
    validateCsrfToken();
    if ($_POST['action'] === 'delete') {
        try {
            $delete_driver_id = (int)$_POST['driver_id'];
            $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$delete_driver_id, $call_date]);
            $delete_target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$delete_target) {
                throw new InvalidArgumentException('この乗務前点呼記録は見つかりません。すでに削除されている可能性があります。');
            }
            if ($user_role !== 'Admin' && (int)$delete_target['driver_id'] !== (int)$user_id) {
                throw new InvalidArgumentException('削除は管理者または記録した運転者のみ実行できます。');
            }
            $edit_check = canEditCall($delete_target, $user_role);
            if (!$edit_check['can_edit']) {
                throw new InvalidArgumentException($edit_check['lock_reason']);
            }

            $pdo->beginTransaction();
            $delete_reason = $_POST['delete_reason'] ?? '削除';
            archiveRowsBeforeDelete($pdo, 'pre_duty_calls', 'id = ?', [$delete_target['id']], '乗務前点呼の削除', $user_id);
            $stmt = $pdo->prepare("DELETE FROM pre_duty_calls WHERE id = ?");
            $stmt->execute([$delete_target['id']]);
            logAudit($pdo, $delete_target['id'], 'delete', $user_id, 'pre_duty_call', [], $delete_reason);
            $pdo->commit();

            $_SESSION['pre_duty_flash'] = '乗務前点呼記録を削除しました。';
            header('Location: ' . $self_url($delete_driver_id, $call_date));
            exit;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = '削除できませんでした。記録は変わっていません。もう一度試しても同じ場合は、システム担当に連絡してください。';
            error_log("Pre duty call delete error: " . $e->getMessage());
        }
    }
}

// フォーム送信処理（登録・更新）
if ($is_post && !isset($_POST['action'])) {
    validateCsrfToken();
    $driver_id = (int)($_POST['driver_id'] ?? 0);
    $call_time = trim($_POST['call_time'] ?? '');

    try {
        if (!in_array($driver_id, array_map('intval', array_column($drivers, 'id')), true)) {
            throw new InvalidArgumentException('運転者を選択してください。');
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $call_time)) {
            throw new InvalidArgumentException('点呼時刻を入力してください。');
        }

        // 点呼者名の処理
        $caller_name = trim($_POST['caller_name'] ?? '');
        if ($caller_name === 'その他') {
            $caller_name = trim($_POST['other_caller'] ?? '');
        }
        if ($caller_name === '') {
            throw new InvalidArgumentException('点呼者を選択してください。');
        }

        // アルコール測定値と必須の確認項目（決まりは functions.php の1か所・ADR-0013）
        $alcohol_check_value = validateDutyCallInput('pre', $_POST);

        // 既存レコードの確認（その運転者・その点呼日）
        $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
        $stmt->execute([$driver_id, $call_date]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // ロック判定（過去日は管理者だけ・理由が要る）
            $edit_check = canEditCall($existing, $user_role);
            if (!$edit_check['can_edit']) {
                throw new InvalidArgumentException($edit_check['lock_reason']);
            }
            $edit_reason = null;
            if ($edit_check['needs_reason']) {
                $edit_reason = trim($_POST['edit_reason'] ?? '');
                if ($edit_reason === '') {
                    throw new InvalidArgumentException('過去日の記録を修正するには修正理由が必要です。');
                }
            }

            $pdo->beginTransaction();
            $sql = "UPDATE pre_duty_calls SET
                    call_time = ?, caller_name = ?, alcohol_check_value = ?, alcohol_check_time = ?,";
            foreach ($check_items as $item) {
                $sql .= " $item = ?,";
            }
            $sql .= " remarks = ?, is_completed = TRUE, updated_at = NOW()
                    WHERE id = ?";
            $params = [$call_time, $caller_name, $alcohol_check_value, $call_time];
            foreach ($check_items as $item) {
                $params[] = isset($_POST[$item]) ? 1 : 0;
            }
            $params[] = $_POST['remarks'] ?? '';
            $params[] = $existing['id'];
            $pdo->prepare($sql)->execute($params);
            logAudit($pdo, $existing['id'], 'edit', $user_id, 'pre_duty_call', [], $edit_reason);
            $pdo->commit();
            $_SESSION['pre_duty_flash'] = '乗務前点呼記録を更新しました。';
        } else {
            if (!canRegisterDutyCallDate($call_date, $user_role, $yesterday)) {
                throw new InvalidArgumentException('前日より前の乗務前点呼は、管理者が登録してください。');
            }

            // 使用予定車両を取得: その日の出庫 → その日の日常点検 → それまでの最後の入庫の車両 の順
            // （前回入庫だけで推定すると、車両を替えた日に必ずずれていた。出庫時にも当日の点呼の車両を揃え直す）
            $stmt = $pdo->prepare("
                SELECT v.id
                FROM vehicles v
                WHERE v.is_active = TRUE
                AND v.id = COALESCE(
                    (SELECT dr.vehicle_id FROM departure_records dr
                      WHERE dr.driver_id = ? AND dr.departure_date = ?
                      ORDER BY dr.departure_time DESC, dr.id DESC LIMIT 1),
                    (SELECT di.vehicle_id FROM daily_inspections di
                      WHERE di.driver_id = ? AND di.inspection_date = ?
                      ORDER BY di.id DESC LIMIT 1),
                    (SELECT ar.vehicle_id FROM arrival_records ar
                      WHERE ar.driver_id = ? AND ar.arrival_date <= ?
                      ORDER BY ar.arrival_date DESC, ar.id DESC LIMIT 1)
                )
            ");
            $stmt->execute([$driver_id, $call_date, $driver_id, $call_date, $driver_id, $call_date]);
            $vehicle_record = $stmt->fetch();
            if (!$vehicle_record) {
                // デフォルト車両を使用
                $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number LIMIT 1");
                $stmt->execute();
                $vehicle_record = $stmt->fetch();
            }
            if (!$vehicle_record) {
                throw new InvalidArgumentException('使用可能な車両が見つかりません。車両管理画面で車両を登録してください。');
            }
            $vehicle_id = $vehicle_record['id'];

            // 過去日の分を後から登録した時は、備考に事後登録と分かる印を付ける（ADR-0002）
            $remarks = trim($_POST['remarks'] ?? '');
            if ($call_date < $today) {
                $remarks = trim('【' . $today . ' 事後登録】' . $remarks);
            }

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
            $params = [$driver_id, $vehicle_id, $call_date, $call_time, $caller_name, $alcohol_check_value, $call_time];
            foreach ($check_items as $item) {
                $params[] = isset($_POST[$item]) ? 1 : 0;
            }
            $params[] = $remarks;
            $pdo->prepare($sql)->execute($params);
            $new_call_id = $pdo->lastInsertId();
            logAudit($pdo, $new_call_id, 'create', $user_id, 'pre_duty_call');
            $pdo->commit();
            $_SESSION['pre_duty_flash'] = ($call_date === $today)
                ? '乗務前点呼記録を登録しました。'
                : date('n/j', strtotime($call_date)) . ' の乗務前点呼記録を登録しました。';
        }

        header('Location: ' . $self_url($driver_id, $call_date));
        exit;

    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = $e->getMessage() . '（入力した内容は残しています）';
        $form_old = $_POST;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = '保存できませんでした。入力した内容は残しています。もう一度保存しても同じ場合は、システム担当に連絡してください。';
        $form_old = $_POST;
        error_log("Pre duty call error: " . $e->getMessage());
    }
}

// その運転者・その日の点呼記録
$existing_call = null;
$missed_yesterday = false; // 昨日出庫したのに乗務前点呼が無い（今日の画面で1回だけ知らせる）
if ($selected_driver_id) {
    $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$selected_driver_id, $call_date]);
    $existing_call = $stmt->fetch();
    $is_edit_mode = (bool)$existing_call;

    if ($call_date === $today) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM departure_records d
            WHERE d.driver_id = ? AND d.departure_date = ? AND COALESCE(d.is_sample_data, 0) = 0
              AND NOT EXISTS (SELECT 1 FROM pre_duty_calls p WHERE p.driver_id = d.driver_id AND p.call_date = d.departure_date)");
        $stmt->execute([$selected_driver_id, $yesterday]);
        $missed_yesterday = (int)$stmt->fetchColumn() > 0;
    }
}

// いまの画面で新しく登録できるか（できない時は、入力の前に理由を出す）
$can_register_date = canRegisterDutyCallDate($call_date, $user_role, $yesterday);
$date_label = date('n/j', strtotime($call_date)) . ($call_date === $yesterday ? '（昨日）' : '');

// 入力欄に出す値: 保存が通らなかった時は入れた値、記録があれば記録の値、どちらも無ければ既定値
$fv = function ($key, $default = '') use (&$form_old, &$existing_call) {
    if ($form_old !== null) return $form_old[$key] ?? '';
    if ($existing_call) return $existing_call[$key] ?? '';
    return $default;
};
$fchecked = function ($key) use (&$form_old, &$existing_call) {
    if ($form_old !== null) return isset($form_old[$key]);
    return $existing_call && !empty($existing_call[$key]);
};
// 点呼者: 一覧に無い名前は「その他」＋手入力
$caller_names = array_column($callers, 'name');
if ($form_old !== null) {
    $caller_selected = $form_old['caller_name'] ?? '';
    $caller_other = $form_old['other_caller'] ?? '';
} elseif ($existing_call && $existing_call['caller_name'] != '') {
    $in_list = in_array($existing_call['caller_name'], $caller_names);
    $caller_selected = $in_list ? $existing_call['caller_name'] : 'その他';
    $caller_other = $in_list ? '' : $existing_call['caller_name'];
} else {
    $caller_selected = '';
    $caller_other = '';
}

// ロック状態判定
$edit_check = $existing_call ? canEditCall($existing_call, $user_role) : ['can_edit' => true, 'needs_reason' => false, 'lock_reason' => ''];

// ページ設定
$page_config = getPageConfiguration('pre_duty_call');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => ['css/wts-design-tokens.css', 'css/workflow-stepper.css'],
    'additional_js' => [
        'js/ui-interactions.js'
    ],
    'workflow_stepper' => renderWorkflowStepper(
        'pre_duty',
        getWorkflowCompletionStatus($pdo, $user_id),
        ['url' => 'daily_inspection.php', 'label' => '日常点検'],
        ['url' => 'departure.php', 'label' => '出庫処理']
    )
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

        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show text-center py-3" style="font-size: 1.1rem; font-weight: 600; border-radius: 12px; box-shadow: 0 4px 15px rgba(25,135,84,0.3);">
            <i class="fas fa-check-circle me-2 fa-lg"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>document.addEventListener('DOMContentLoaded', function() { showToast(<?= json_encode($success_message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, 'success'); });</script>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>

        <?= $existing_call ? renderAlcoholDetectedAlert($existing_call['alcohol_check_value'], 'pre') : '' ?>

        <?php if ($existing_call): ?>
        <div class="mb-3">
            <?php if ($edit_check['can_edit'] && !$edit_check['needs_reason']): ?>
                <span class="badge bg-success fs-6"><i class="fas fa-unlock me-1"></i>編集可能（本日中）</span>
            <?php elseif ($edit_check['can_edit'] && $edit_check['needs_reason']): ?>
                <span class="badge bg-warning text-dark fs-6"><i class="fas fa-lock me-1"></i>ロック中（管理者解除可）</span>
            <?php else: ?>
                <span class="badge wts-ref fs-6"><i class="fas fa-lock me-1"></i>ロック済み（変更不可）</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($call_date !== $today): ?>
        <!-- 今日以外の日の点呼を開いている時は、いつの分かを先に出す -->
        <div class="alert alert-warning border-0 shadow-sm mb-3 d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1">
                <i class="fas fa-calendar-day me-2"></i><strong><?= htmlspecialchars($date_label) ?> の乗務前点呼</strong>を開いています。
                <?php if (!$existing_call && $can_register_date): ?>新しく登録すると、備考の頭に「事後登録」の印が付きます。<?php endif; ?>
            </div>
            <a href="<?= htmlspecialchars($self_url($selected_driver_id, $today)) ?>" class="btn btn-outline-secondary">今日の分に戻る</a>
        </div>
        <?php elseif ($missed_yesterday && !$existing_call): ?>
        <div class="alert alert-warning border-0 shadow-sm mb-3 d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1">
                <i class="fas fa-exclamation-triangle me-2"></i>昨日（<?= date('n/j', strtotime($yesterday)) ?>）出庫した日の乗務前点呼がまだ登録されていません。
            </div>
            <a href="<?= htmlspecialchars($self_url($selected_driver_id, $yesterday)) ?>" class="btn wts-btn-blue">昨日の分を入力する</a>
        </div>
        <?php endif; ?>

        <?php if ($selected_driver_id && !$existing_call && !$can_register_date): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4">
            <i class="fas fa-lock me-2"></i>前日より前の乗務前点呼は、管理者が登録します。管理者に依頼してください。
        </div>
        <?php endif; ?>

        <!-- メインフォーム -->
        <div class="row">
            <div class="col-lg-8">
                <form method="POST" id="pre-duty-form" onsubmit="return validateForm(this)">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                    <!-- 基本情報 -->
                    <?php
                    echo renderSectionHeader('user', '基本情報', '運転者・点呼日・点呼者・時刻');
                    ?>

                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="pre_driver_id" class="form-label fw-bold">運転者 <span class="text-danger fw-bold small">（必須）</span></label>
                                    <select class="form-select" id="pre_driver_id" name="driver_id" onchange="reloadForSelection()" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>"
                                            <?= ((int)$selected_driver_id === (int)$driver['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($driver['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="pre_call_date" class="form-label fw-bold">点呼日 <span class="text-danger fw-bold small">（必須）</span></label>
                                    <input type="date" class="form-control" id="pre_call_date" name="call_date"
                                        value="<?= htmlspecialchars($call_date) ?>" max="<?= $today ?>"
                                        <?= $user_role === 'Admin' ? '' : 'min="' . $yesterday . '"' ?>
                                        onchange="reloadForSelection()" required>
                                    <?php if (!$is_edit_mode): ?><div class="form-text">日付をまたいだ時・前日の入れ忘れは前日を選びます。</div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label for="pre_call_time" class="form-label fw-bold">点呼時刻 <span class="text-danger fw-bold small">（必須）</span></label>
                                    <input type="time" class="form-control" id="pre_call_time" name="call_time"
                                        value="<?= htmlspecialchars(substr((string)$fv('call_time', $current_time), 0, 5)) ?>"
                                        <?= $is_edit_mode ? 'readonly' : '' ?> required>
                                </div>
                                <div class="col-md-6">
                                    <label for="caller_name" class="form-label fw-bold">点呼者 <span class="text-danger fw-bold small">（必須）</span></label>
                                    <select class="form-select" name="caller_name" id="caller_name" <?= $is_edit_mode ? 'disabled' : '' ?> required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($callers as $caller): ?>
                                        <option value="<?= htmlspecialchars($caller['name']) ?>"
                                            <?= ($caller_selected === $caller['name']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($caller['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <option value="その他" <?= ($caller_selected === 'その他') ? 'selected' : '' ?>>その他</option>
                                    </select>
                                    <input type="text" class="form-control mt-2" id="other_caller" name="other_caller"
                                        placeholder="その他の場合は名前を入力" style="display: none;"
                                        <?= $is_edit_mode ? 'readonly' : '' ?>
                                        value="<?= htmlspecialchars($caller_other) ?>">
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
                            'class' => 'wts-btn-blue btn-sm'
                        ];
                        $check_actions[] = [
                            'icon' => 'times',
                            'text' => '全て解除',
                            'url' => 'javascript:uncheckAll()',
                            'class' => 'wts-btn-slate-outline btn-sm'
                        ];
                    }
                    echo renderSectionHeader('tasks', '確認事項', '16項目の法定確認事項', $check_actions);
                    ?>

                    <div class="card mb-4">
                        <div class="card-body">
                            <?php
                            // 付けないと登録できない3項目（決まりは functions.php）
                            $check_items_required = array_map(function ($label) { return [$label, true]; }, getRequiredDutyCallChecks('pre'));
                            $check_items_optional = [
                                'clothing_check' => ['服装', false],
                                'footwear_check' => ['履物', false],
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
                            $check_items_labels = array_merge($check_items_required, $check_items_optional);
                            ?>

                            <!-- 必須確認項目 -->
                            <div class="row g-3">
                                <?php $i = 1; foreach ($check_items_required as $key => $item): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="form-check p-3 border rounded <?= $is_edit_mode ? '' : 'check-item-clickable' ?> <?= $fchecked($key) ? 'bg-success bg-opacity-10 border-success' : '' ?>"
                                         <?= $is_edit_mode ? '' : 'onclick="toggleCheck(\'' . $key . '\')"' ?>>
                                        <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>"
                                            <?= $fchecked($key) ? 'checked' : '' ?>
                                            <?= $is_edit_mode ? 'disabled' : '' ?>>
                                        <label class="form-check-label fw-bold" for="<?= $key ?>">
                                            <span class="badge bg-primary me-2"><?= $i ?></span>
                                            <?= $item[0] ?>
                                            <span class="text-danger fw-bold small">（必須）</span>
                                        </label>
                                    </div>
                                </div>
                                <?php $i++; endforeach; ?>
                            </div>

                            <!-- 任意確認項目（折りたたみ） -->
                            <div class="mt-3">
                                <button class="btn btn-outline-secondary btn-sm w-100" type="button" data-bs-toggle="collapse" data-bs-target="#optionalCheckItems" aria-expanded="false">
                                    <i class="fas fa-chevron-down me-1"></i>その他の確認項目を表示（13項目）
                                </button>
                            </div>
                            <div class="collapse mt-3" id="optionalCheckItems">
                                <div class="row g-3">
                                    <?php foreach ($check_items_optional as $key => $item): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="form-check p-3 border rounded <?= $is_edit_mode ? '' : 'check-item-clickable' ?> <?= $fchecked($key) ? 'bg-success bg-opacity-10 border-success' : '' ?>"
                                             <?= $is_edit_mode ? '' : 'onclick="toggleCheck(\'' . $key . '\')"' ?>>
                                            <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>"
                                                <?= $fchecked($key) ? 'checked' : '' ?>
                                                <?= $is_edit_mode ? 'disabled' : '' ?>>
                                            <label class="form-check-label fw-bold" for="<?= $key ?>">
                                                <span class="badge bg-secondary me-2"><?= $i ?></span>
                                                <?= $item[0] ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php $i++; endforeach; ?>
                                </div>
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
                                    <label for="pre_alcohol_check_value" class="form-label fw-bold">測定値 (mg/L) <span class="text-danger fw-bold small">（必須）</span></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="pre_alcohol_check_value" name="alcohol_check_value"
                                            step="0.001" min="0" max="1" inputmode="decimal"
                                            value="<?= htmlspecialchars((string)$fv('alcohol_check_value', '0.000')) ?>"
                                            placeholder="0.000"
                                            <?= $is_edit_mode ? 'readonly' : '' ?> required>
                                        <span class="input-group-text">mg/L</span>
                                        <?php if (!$is_edit_mode): ?>
                                        <button type="button" class="btn wts-btn-slate-outline" onclick="setAlcoholZero()">
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
                                <?= $is_edit_mode ? 'readonly' : '' ?>><?= htmlspecialchars((string)$fv('remarks')) ?></textarea>
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
                            <small class="text-muted">過去日の記録の修正には理由が必要です。監査ログに記録されます。</small>
                        </div>
                    </div>

                    <!-- 操作ボタン -->
                    <div class="text-center mb-4" id="actionButtons" style="position: sticky; bottom: 0; z-index: 50; background: white; padding: 12px 0; border-top: 1px solid #dee2e6;">
                        <?php if ($existing_call && !$edit_check['can_edit']): ?>
                            <div class="text-muted">
                                <i class="fas fa-lock me-2"></i>この記録は編集できません
                            </div>
                        <?php elseif ($existing_call): ?>
                            <button type="button" class="btn btn-warning btn-lg me-2" onclick="enableEditMode()">
                                <i class="fas fa-edit me-2"></i>修正
                            </button>
                            <?php if ($user_role === 'Admin'): ?>
                            <button type="button" class="btn btn-danger btn-lg" onclick="confirmDelete()">
                                <i class="fas fa-trash me-2"></i>削除
                            </button>
                            <?php endif; ?>
                        <?php elseif (!$can_register_date): ?>
                            <div class="text-muted">
                                <i class="fas fa-lock me-2"></i>前日より前の乗務前点呼は、管理者が登録します
                            </div>
                        <?php else: ?>
                            <button type="submit" class="btn btn-success btn-lg" data-loading-text="保存中...">
                                <i class="fas fa-save me-2"></i><?= $call_date === $today ? '登録する' : htmlspecialchars(date('n/j', strtotime($call_date))) . ' の分を登録する' ?>
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
                echo renderSectionHeader('calendar-day', $call_date === $today ? '本日の記録' : $date_label . 'の記録', date('Y年m月d日', strtotime($call_date)));
                ?>

                <div class="card mb-4">
                    <div class="card-body">
                        <?php if ($existing_call): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                乗務前点呼完了済み
                            </div>
                            <ul class="list-unstyled mb-0">
                                <li><strong>点呼時刻:</strong> <?= htmlspecialchars(substr((string)$existing_call['call_time'], 0, 5)) ?></li>
                                <li><strong>点呼者:</strong> <?= htmlspecialchars($existing_call['caller_name']) ?></li>
                                <li><strong>アルコール値:</strong> <?= htmlspecialchars((string)$existing_call['alcohol_check_value']) ?> mg/L</li>
                            </ul>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                未実施
                            </div>
                            <p class="mb-0 text-muted"><?= $call_date === $today ? '本日' : htmlspecialchars($date_label) ?>の乗務前点呼はまだ登録されていません。</p>
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
                            <?php if ($existing_call && $existing_call['is_completed'] && $call_date === $today): ?>
                            <a href="departure.php?driver_id=<?= $existing_call['driver_id'] ?>" class="btn btn-outline-primary btn-sm">
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
/* WTSデザイントークン適用（1色=1意味: スレート=中立操作 / グレー=参照情報） */
.wts-btn-slate {
    background: var(--wts-slate); border: 1px solid var(--wts-slate); color: #fff;
}
.wts-btn-slate:hover, .wts-btn-slate:focus {
    background: #263238; border-color: #263238; color: #fff;
}
.wts-btn-slate-outline {
    background: #fff; border: 1px solid var(--wts-slate); color: var(--wts-slate);
}
.wts-btn-slate-outline:hover, .wts-btn-slate-outline:focus {
    background: var(--wts-gray-bg); color: var(--wts-slate);
}
.badge.wts-ref { background-color: var(--wts-gray); }

/* フォーカス可視化（青=現在地・フォーカス） */
.form-control:focus, .form-select:focus {
    outline: 3px solid var(--wts-blue); outline-offset: 1px;
}
.btn:focus-visible, .form-check-input:focus-visible {
    outline: 3px solid var(--wts-blue); outline-offset: 2px;
}

/* モバイル主要ボタンのタップターゲット */
@media (max-width: 768px) {
    .section-actions .btn,
    button[data-bs-target="#optionalCheckItems"] { min-height: 44px; }
}

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

@keyframes slideDown {
    from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}
</style>

<script>
// ロック状態
window.callLockStatus = {
    canEdit: <?= json_encode((bool)$edit_check['can_edit']) ?>,
    needsReason: <?= json_encode((bool)$edit_check['needs_reason']) ?>,
    lockReason: <?= json_encode((string)$edit_check['lock_reason'], JSON_UNESCAPED_UNICODE) ?>
};

// 運転者か点呼日を替えたら、その人・その日の画面に読み直す（ADR-0016。乗務後点呼と同じ）
function reloadForSelection() {
    var driverId = document.getElementById('pre_driver_id').value;
    var callDate = document.getElementById('pre_call_date').value;
    if (!driverId || !callDate) return;
    formDirty = false;
    var url = 'pre_duty_call.php?driver_id=' + encodeURIComponent(driverId);
    if (callDate !== <?= json_encode($today) ?>) url += '&date=' + encodeURIComponent(callDate);
    window.location.href = url;
}

// 点呼者選択の「その他」処理
function toggleCallerInput() {
    var callerSelect = document.getElementById('caller_name');
    var otherInput = document.getElementById('other_caller');
    if (callerSelect.value === 'その他') {
        otherInput.style.display = 'block';
        otherInput.required = true;
    } else {
        otherInput.style.display = 'none';
        otherInput.required = false;
    }
}
document.getElementById('caller_name').addEventListener('change', toggleCallerInput);
toggleCallerInput();

// チェック項目の個別切り替え
function toggleCheck(itemId) {
    const checkbox = document.getElementById(itemId);
    if (checkbox.disabled) return;
    wtsToggleCheckFromContainer(checkbox, window.event);
    // 四角・ラベルを直接押した時も、枠の色を合わせる
    setTimeout(function() {
        var c = checkbox.closest('.form-check');
        if (!c) return;
        c.classList.remove('border-danger');
        if (checkbox.checked) { c.classList.add('bg-success', 'bg-opacity-10', 'border-success'); }
        else { c.classList.remove('bg-success', 'bg-opacity-10', 'border-success'); }
    }, 0);
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
    var field = document.querySelector('input[name="alcohol_check_value"]');
    field.value = '0.000';
    field.classList.remove('is-invalid');
    formDirty = true;
    field.dispatchEvent(new Event('input'));
}

// 編集モード有効化
function enableEditMode() {
    var lockStatus = window.callLockStatus || {};
    if (!lockStatus.canEdit) {
        showToast(lockStatus.lockReason || 'この記録は編集できません。', 'warning');
        return;
    }

    // フォーム要素を編集可能に
    document.querySelectorAll('input[readonly], textarea[readonly]').forEach(function(el) {
        el.removeAttribute('readonly');
    });
    document.querySelectorAll('input[disabled], select[disabled]').forEach(function(el) {
        el.removeAttribute('disabled');
    });
    // 点呼日は修正で変えない（日付を変えると別の記録になる）。変えたい時は削除して登録し直す
    var dateInput = document.getElementById('pre_call_date');
    dateInput.setAttribute('readonly', 'readonly');
    dateInput.removeAttribute('onchange');
    // 運転者も修正では変えない（選んである人以外を選べなくする。別の人の記録を上書きしないように・A-19）
    var driverSelect = document.getElementById('pre_driver_id');
    driverSelect.removeAttribute('onchange');
    Array.prototype.forEach.call(driverSelect.options, function(option) {
        if (!option.selected) option.disabled = true;
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
        actionButtons.innerHTML = '<button type="submit" class="btn btn-success btn-lg" data-loading-text="保存中..."><i class="fas fa-save me-2"></i>変更を保存</button>' +
            '<button type="button" class="btn btn-secondary" onclick="formDirty = false; location.href = <?= htmlspecialchars(json_encode($self_url($selected_driver_id, $call_date)), ENT_QUOTES) ?>"><i class="fas fa-times me-2"></i>キャンセル</button>';
    }
    formDirty = true;
}

// 削除確認（理由入力付き）
function confirmDelete() {
    showConfirm('この乗務前点呼記録を削除しますか？', function(reason) {
        var deleteReasonInput = document.getElementById('deleteReason');
        if (deleteReasonInput) deleteReasonInput.value = reason;
        document.getElementById('delete-form').submit();
    }, {
        inputLabel: '削除理由',
        inputRequired: true,
        inputPlaceholder: '削除理由を入力してください（監査ログに記録されます）',
        type: 'danger',
        confirmText: '削除する'
    });
}

function showToast(message, type) {
    showNotification(message, type);
}

function showNotification(message, type) {
    var existing = document.getElementById('centralToast');
    if (existing) existing.remove();
    var colors = { success: '#198754', warning: '#ffc107', error: '#dc3545', info: '#0d6efd' };
    var icons = { success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle', info: 'info-circle' };
    var textColor = type === 'warning' ? '#000' : '#fff';
    var html = '<div id="centralToast" style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999;'
        + 'background:' + colors[type] + ';color:' + textColor + ';padding:16px 32px;border-radius:12px;'
        + 'box-shadow:0 8px 32px rgba(0,0,0,0.3);font-size:1.1rem;font-weight:600;'
        + 'display:flex;align-items:center;gap:10px;animation:slideDown 0.4s ease;">'
        + '<i class="fas fa-' + icons[type] + ' fa-lg"></i><span>' + message + '</span></div>';
    document.body.insertAdjacentHTML('beforeend', html);
    setTimeout(function() {
        var el = document.getElementById('centralToast');
        if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.5s'; setTimeout(function() { el.remove(); }, 500); }
    }, 8000);
}

// フォーム未保存データの離脱警告
var formDirty = false;
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('pre-duty-form');
    if (form) {
        form.addEventListener('input', function() { formDirty = true; });
        form.addEventListener('change', function() { formDirty = true; });
        form.setAttribute('data-required-checks', <?= json_encode(json_encode(array_keys(getRequiredDutyCallChecks('pre')))) ?>);
        form.addEventListener('submit', function(e) {
            // 削除などの別の操作の時は検査しない。登録・更新の時だけ、必須の確認項目とアルコール測定値を送る前に確かめる
            if (!form.querySelector('input[name="action"]') && !wtsCheckDutyCallForm(form)) {
                e.preventDefault();
                return;
            }
            var reasonSection = document.getElementById('editReasonSection');
            if (reasonSection && reasonSection.style.display !== 'none' && !document.getElementById('editReason').value.trim()) {
                e.preventDefault();
                showToast('修正理由を入力してください。', 'warning');
                return;
            }
            formDirty = false;
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>保存中...';
            }
        });
    }
});
window.addEventListener('beforeunload', function(e) {
    if (formDirty) { e.preventDefault(); }
});
</script>

<?php if ($form_old !== null && $existing_call): ?>
<script>document.addEventListener('DOMContentLoaded', function () { enableEditMode(); }); // 修正の保存が通らなかった時は、修正の続きの形で出す</script>
<?php endif; ?>
<?= renderAlcoholLiveWarningScript('pre_alcohol_check_value') ?>
<?= $page_data['html_footer'] ?>
