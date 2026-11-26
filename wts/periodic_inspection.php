<?php
require_once 'config/database.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';
require_once 'functions.php';

$today = date('Y-m-d');
$success_message = takeFlashMessage(); // 保存後に出し直した時の知らせ
$error_message = '';

// 点検項目（保存の列）。画面の並びと同じ
$PERIODIC_CATEGORIES = [
    'steering' => ['steering_play', 'steering_effort', 'steering_connection'],
    'braking' => ['brake_pedal_play', 'brake_pedal_clearance', 'brake_effectiveness',
                 'parking_brake_effectiveness', 'brake_fluid_amount', 'brake_line_condition'],
    'running' => ['wheel_bearing_play', 'wheel_nut_looseness'],
    'suspension' => ['spring_damage', 'shock_absorber_condition'],
    'power_transmission' => ['clutch_pedal_play', 'clutch_effectiveness', 'transmission_oil_amount',
                           'transmission_oil_leakage', 'propeller_shaft_connection'],
    'electrical' => ['spark_plug_condition', 'battery_terminal', 'ignition_timing', 'distributor_cap_condition'],
    'engine' => ['valve_clearance', 'engine_oil_leakage', 'exhaust_gas_condition', 'air_cleaner_element']
];
$PERIODIC_RESULTS = ['良好', '要注意', '不良', '該当なし']; // 該当なし＝その車に無い項目（AT車のクラッチ等・ADR-0018）

/** 点検項目の結果から総合結果を決める（決まりはここ1か所）。不良があれば不合格、要注意があれば条件付合格 */
function periodicOverallResult(array $results) {
    if (in_array('不良', $results, true)) return '不合格';
    if (in_array('要注意', $results, true)) return '条件付合格';
    return '合格';
}

/**
 * 定期点検の記録を直せるか（ADR-0018）。登録した日のうちは誰でも（打ち間違いをすぐ直す）。
 * それより後は管理者だけで、理由が要る。
 */
function canEditPeriodic(array $record, $user_role, $today) {
    if (substr((string)$record['created_at'], 0, 10) >= $today) {
        return ['can_edit' => true, 'needs_reason' => false, 'lock_reason' => ''];
    }
    if ($user_role === 'Admin') {
        return ['can_edit' => true, 'needs_reason' => true, 'lock_reason' => ''];
    }
    return ['can_edit' => false, 'needs_reason' => false, 'lock_reason' => '登録した日より後の修正は、管理者が行います。管理者に依頼してください。'];
}

/** 車両の次回点検日を、残っている一番新しい定期点検に合わせ直す（記録が無ければそのまま） */
function syncVehicleNextInspection($pdo, $vehicle_id) {
    $stmt = $pdo->prepare("SELECT next_inspection_date FROM periodic_inspections WHERE vehicle_id = ? ORDER BY inspection_date DESC, id DESC LIMIT 1");
    $stmt->execute([$vehicle_id]);
    $next = $stmt->fetchColumn();
    if ($next) {
        $pdo->prepare("UPDATE vehicles SET next_inspection_date = ? WHERE id = ?")->execute([$next, $vehicle_id]);
    }
}

// 車両とユーザーの取得
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model, current_mileage, next_inspection_date FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE (is_driver = 1 OR is_manager = 1 OR is_mechanic = 1 OR is_inspector = 1) AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $inspectors = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $vehicles = [];
    $inspectors = [];
}

// 開いている記録（一覧の「開く」＝?id=）
$is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$edit_id = (int)($is_post ? ($_POST['inspection_id'] ?? 0) : ($_GET['id'] ?? 0));
$editing = null;
$editing_items = [];
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT p.*, v.vehicle_number FROM periodic_inspections p LEFT JOIN vehicles v ON v.id = p.vehicle_id WHERE p.id = ?");
    $stmt->execute([$edit_id]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editing) {
        $stmt = $pdo->prepare("SELECT item_name, result, note FROM periodic_inspection_items WHERE inspection_id = ?");
        $stmt->execute([$edit_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $editing_items[$row['item_name']] = $row;
        }
    } elseif (!$is_post) {
        $error_message = 'この定期点検の記録は見つかりません。削除された可能性があります。';
    }
}
$edit_check = $editing ? canEditPeriodic($editing, $user_role, $today) : ['can_edit' => true, 'needs_reason' => false, 'lock_reason' => ''];

// 削除（管理者だけ・消す前に控える）
if ($is_post && ($_POST['action'] ?? '') === 'delete') {
    validateCsrfToken();
    try {
        if ($user_role !== 'Admin') {
            throw new InvalidArgumentException('定期点検の記録の削除は、管理者が行います。');
        }
        if (!$editing) {
            throw new InvalidArgumentException('この定期点検の記録は見つかりません。すでに削除されている可能性があります。');
        }
        $reason = trim($_POST['delete_reason'] ?? '');
        if ($reason === '') {
            throw new InvalidArgumentException('削除の理由を入れてください。');
        }
        $pdo->beginTransaction();
        $batch = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        archiveRowsBeforeDelete($pdo, 'periodic_inspection_items', 'inspection_id = ?', [$edit_id], '定期点検の削除', $user_id, $batch);
        archiveRowsBeforeDelete($pdo, 'periodic_inspections', 'id = ?', [$edit_id], '定期点検の削除', $user_id, $batch);
        $pdo->prepare("DELETE FROM periodic_inspection_items WHERE inspection_id = ?")->execute([$edit_id]);
        $pdo->prepare("DELETE FROM periodic_inspections WHERE id = ?")->execute([$edit_id]);
        syncVehicleNextInspection($pdo, $editing['vehicle_id']);
        $pdo->commit();
        logAudit($pdo, $edit_id, 'delete', $user_id, 'periodic_inspection', [], $reason);
        $_SESSION['flash_messages']['periodic_inspection.php'] = date('n/j', strtotime($editing['inspection_date'])) . ' ' . $editing['vehicle_number'] . ' の定期点検記録を削除しました（控えを残しています）。';
        header('Location: periodic_inspection.php', true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = $e->getMessage();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = '削除できませんでした。記録は変わっていません。もう一度試しても同じ場合は、システム担当に連絡してください。';
        error_log("Periodic inspection delete error: " . $e->getMessage());
    }
}

// 登録・修正
if ($is_post && !isset($_POST['action'])) {
    validateCsrfToken();
    try {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $inspection_date = trim($_POST['inspection_date'] ?? '');
        $inspector_id = (int)($_POST['inspector_id'] ?? 0);
        $mileage = trim((string)($_POST['mileage'] ?? ''));
        $inspection_type = $_POST['inspection_type'] ?? '3months';

        if (!in_array($vehicle_id, array_map('intval', array_column($vehicles, 'id')), true)) {
            throw new InvalidArgumentException('車両を選んでください。');
        }
        if (!in_array($inspector_id, array_map('intval', array_column($inspectors, 'id')), true)) {
            throw new InvalidArgumentException('点検者を選んでください。');
        }
        $d = DateTime::createFromFormat('Y-m-d', $inspection_date);
        if (!$d || $d->format('Y-m-d') !== $inspection_date) {
            throw new InvalidArgumentException('点検日を正しく入れてください。');
        }
        if ($inspection_date > $today) {
            throw new InvalidArgumentException('未来の日付の点検は登録できません。点検した日を選んでください。');
        }
        if (!preg_match('/^\d+$/', $mileage)) {
            throw new InvalidArgumentException('走行距離を数字で入れてください。');
        }
        if (!in_array($inspection_type, ['3months', '6months', '12months'], true)) {
            throw new InvalidArgumentException('点検の種類を選んでください。');
        }
        if ($edit_id > 0 && !$editing) {
            throw new InvalidArgumentException('修正しようとした記録が見つかりません。削除された可能性があります。');
        }
        $edit_reason = null;
        if ($editing) {
            if (!$edit_check['can_edit']) {
                throw new InvalidArgumentException($edit_check['lock_reason']);
            }
            if ($edit_check['needs_reason']) {
                $edit_reason = trim($_POST['edit_reason'] ?? '');
                if ($edit_reason === '') {
                    throw new InvalidArgumentException('登録した日より後の修正には、修正の理由が要ります。');
                }
            }
        }

        // 項目の結果（結果が1つも選ばれていない点検は登録しない＝何も押さずに「合格」の記録ができていた）
        $item_rows = [];
        $results = [];
        foreach ($PERIODIC_CATEGORIES as $category => $items) {
            foreach ($items as $item) {
                $result = $_POST[$item] ?? '';
                if ($result === '') continue;
                if (!in_array($result, $PERIODIC_RESULTS, true)) {
                    throw new InvalidArgumentException('点検結果に選べない値が入っています。画面を開き直してください。');
                }
                $item_rows[] = [$category, $item, $result, (string)($_POST[$item . '_note'] ?? '')];
                $results[] = $result;
            }
        }
        if (!$item_rows) {
            throw new InvalidArgumentException('点検結果が1つも選ばれていません。各項目の「良好・要注意・不良・該当なし」を選んでから登録してください。');
        }
        $missing_count = count(array_merge(...array_values($PERIODIC_CATEGORIES))) - count($item_rows);
        $final_result = periodicOverallResult($results);
        // 次回点検日（3か月後）
        $next_inspection_date = date('Y-m-d', strtotime($inspection_date . ' +3 months'));
        $co_concentration = ($_POST['co_concentration'] ?? '') !== '' ? (float)$_POST['co_concentration'] : null;
        $hc_concentration = ($_POST['hc_concentration'] ?? '') !== '' ? (int)$_POST['hc_concentration'] : null;
        $header = [
            $vehicle_id, $inspector_id, $inspection_date, $inspection_type, (int)$mileage,
            $_POST['service_provider_name'] ?? '', $_POST['service_provider_address'] ?? '',
            $co_concentration, $hc_concentration, $final_result, $_POST['remarks'] ?? '', $next_inspection_date
        ];

        $pdo->beginTransaction();
        if ($editing) {
            $pdo->prepare("UPDATE periodic_inspections SET
                    vehicle_id = ?, inspector_id = ?, inspection_date = ?, inspection_type = ?, mileage = ?,
                    service_provider_name = ?, service_provider_address = ?,
                    co_concentration = ?, hc_concentration = ?, overall_result = ?,
                    remarks = ?, next_inspection_date = ?, updated_at = NOW()
                WHERE id = ?")->execute(array_merge($header, [$edit_id]));
            $pdo->prepare("DELETE FROM periodic_inspection_items WHERE inspection_id = ?")->execute([$edit_id]);
            $inspection_id = $edit_id;
        } else {
            $pdo->prepare("INSERT INTO periodic_inspections (
                    vehicle_id, inspector_id, inspection_date, inspection_type, mileage,
                    service_provider_name, service_provider_address,
                    co_concentration, hc_concentration, overall_result,
                    remarks, next_inspection_date, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute($header);
            $inspection_id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO periodic_inspection_items (inspection_id, category, item_name, result, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($item_rows as $r) {
            $ins->execute([$inspection_id, $r[0], $r[1], $r[2], $r[3]]);
        }

        // 車両の次回点検日は、一番新しい定期点検に合わせる（古い日の点検を直しても、新しい点検の予定を戻さない）
        syncVehicleNextInspection($pdo, $vehicle_id);
        if ($editing && (int)$editing['vehicle_id'] !== $vehicle_id) {
            syncVehicleNextInspection($pdo, $editing['vehicle_id']);
        }
        // 現在メーターは大きい方を残す（過去日の点検を後から登録・修正しても、その後の運行で進んだメーターを巻き戻さない）
        $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(COALESCE(current_mileage, 0), ?) WHERE id = ?")->execute([(int)$mileage, $vehicle_id]);

        $pdo->commit();
        logAudit($pdo, $inspection_id, $editing ? 'edit' : 'create', $user_id, 'periodic_inspection', [], $edit_reason); // 操作種別は create/edit/delete のみ
        $success_message = ($editing ? '定期点検記録を更新しました。' : '定期点検記録を登録しました。') .
                          '総合結果: ' . $final_result . ' / ' .
                          '次回点検日: ' . $next_inspection_date .
                          ($missing_count > 0 ? ' / 結果を選んでいない項目: ' . $missing_count . '件' : '');

    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = $e->getMessage() . '（入力した内容は残しています）';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = '保存できませんでした。入力した内容は残しています。もう一度試しても同じ場合は、システム担当に連絡してください。';
        error_log("Periodic inspection error: " . $e->getMessage());
    }
}

// 保存が通った後は画面を出し直す（再読込で同じ記録がもう1件入らないように。決まりは functions.php）
redirectAfterSuccessfulPost($success_message, $error_message);

// 登録済みの一覧（新しい順・直近30件）
$recent_periodic = [];
try {
    $stmt = $pdo->query("
        SELECT p.id, p.inspection_date, p.inspection_type, p.overall_result, p.mileage, v.vehicle_number, u.name AS inspector_name
        FROM periodic_inspections p
        LEFT JOIN vehicles v ON v.id = p.vehicle_id
        LEFT JOIN users u ON u.id = p.inspector_id
        ORDER BY p.inspection_date DESC, p.id DESC
        LIMIT 30");
    $recent_periodic = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_periodic = [];
}
$type_labels = ['3months' => '3か月', '6months' => '6か月', '12months' => '12か月'];

// 開いた記録を画面に入れる値（保存が通らなかった時は、送った値を renderRestoreInputScript が戻す）
$prefill = null;
if ($editing && !$is_post) {
    $prefill = [
        'vehicle_id' => (string)$editing['vehicle_id'],
        'inspection_date' => $editing['inspection_date'],
        'inspector_id' => (string)$editing['inspector_id'],
        'mileage' => (string)$editing['mileage'],
        'inspection_type' => $editing['inspection_type'],
        'co_concentration' => $editing['co_concentration'] === null ? '' : (string)$editing['co_concentration'],
        'hc_concentration' => $editing['hc_concentration'] === null ? '' : (string)$editing['hc_concentration'],
        'service_provider_name' => (string)$editing['service_provider_name'],
        'service_provider_address' => (string)$editing['service_provider_address'],
        'remarks' => (string)$editing['remarks'],
    ];
    foreach ($editing_items as $name => $row) {
        $prefill[$name] = $row['result'];
        $prefill[$name . '_note'] = (string)$row['note'];
    }
}

// ページ設定
$page_config = getPageConfiguration('periodic_inspection');
$page_options = [
    'additional_css' => [
        'css/wts-design-tokens.css'
    ],
    'additional_js' => [
        'js/ui-interactions.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '定期業務', 'url' => '#'],
        ['text' => '定期点検', 'url' => 'periodic_inspection.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'periodic_inspection',
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
<main class="main-content" id="main-content" tabindex="-1">

<div class="container-fluid px-4">
    <!-- アラート表示 -->
    <?php if ($success_message) { ?>
        <div class="alert alert-success alert-dismissible fade show text-center py-3" style="font-size: 1.1rem; font-weight: 600; border-radius: 12px; box-shadow: 0 4px 15px rgba(25,135,84,0.3);">
            <i class="fas fa-check-circle me-2 fa-lg"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php } ?>

    <?php if ($error_message) { ?>
        <?php echo renderAlert('danger', 'エラー', $error_message, true); ?>
    <?php } ?>

    <?php if ($editing) { ?>
    <!-- 開いている記録（何を直しているかを先に出す） -->
    <div class="alert alert-info border-0 shadow-sm d-flex flex-wrap align-items-center gap-2 mb-3" id="editingBanner">
        <div class="flex-grow-1">
            <i class="fas fa-folder-open me-2"></i><strong><?= htmlspecialchars(date('Y/n/j', strtotime($editing['inspection_date']))) ?> <?= htmlspecialchars((string)$editing['vehicle_number']) ?> の定期点検</strong>を開いています（総合結果: <?= htmlspecialchars((string)$editing['overall_result']) ?>）。
            <?php if (!$edit_check['can_edit']) { ?><br><span class="text-danger"><?= htmlspecialchars($edit_check['lock_reason']) ?></span><?php } ?>
        </div>
        <a href="periodic_inspection.php" class="btn btn-outline-secondary">新しく登録する画面へ</a>
        <?php if ($user_role === 'Admin') { ?>
        <button type="button" class="btn btn-outline-danger" onclick="confirmDeletePeriodic()"><i class="fas fa-trash me-1"></i>削除</button>
        <?php } ?>
    </div>
    <?php } ?>

    <!-- 登録済みの定期点検（開いて直す・消す入口・D-21） -->
    <details class="card mb-4 periodic-list">
        <summary class="card-body py-3 fw-bold">
            <i class="fas fa-list me-2"></i>登録済みの定期点検（新しい順・<?= count($recent_periodic) ?>件<?= count($recent_periodic) >= 30 ? '・直近30件' : '' ?>）
            <span class="text-muted fw-normal small ms-1">開いて直す・消す時はここから</span>
        </summary>
        <div class="list-group list-group-flush">
            <?php if (!$recent_periodic) { ?>
            <div class="list-group-item text-muted">まだ登録された定期点検はありません。</div>
            <?php } ?>
            <?php foreach ($recent_periodic as $rp) {
                $res_class = $rp['overall_result'] === '不合格' ? 'bg-danger' : ($rp['overall_result'] === '条件付合格' ? 'bg-warning text-dark' : 'bg-success'); ?>
            <a href="periodic_inspection.php?id=<?= (int)$rp['id'] ?>" class="list-group-item list-group-item-action d-flex flex-wrap align-items-center gap-2 <?= ($editing && (int)$editing['id'] === (int)$rp['id']) ? 'active' : '' ?>">
                <span class="fw-bold"><?= htmlspecialchars(date('Y/n/j', strtotime($rp['inspection_date']))) ?></span>
                <span><?= htmlspecialchars((string)$rp['vehicle_number']) ?></span>
                <span class="text-muted small"><?= htmlspecialchars($type_labels[$rp['inspection_type']] ?? (string)$rp['inspection_type']) ?>・<?= htmlspecialchars((string)$rp['inspector_name']) ?></span>
                <span class="badge <?= $res_class ?> ms-auto"><?= htmlspecialchars((string)$rp['overall_result']) ?></span>
            </a>
            <?php } ?>
        </div>
    </details>

    <form method="POST" id="inspectionForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <?php if ($editing) { ?><input type="hidden" name="inspection_id" value="<?= (int)$editing['id'] ?>"><?php } ?>
        <!-- 基本情報セクション -->
        <?php
        $basic_info_actions = [
            [
                'icon' => 'check-circle',
                'text' => '自動入力',
                'url' => 'javascript:updateMileage()',
                'class' => 'btn-slate btn-sm'
            ]
        ];
        echo renderSectionHeader('info-circle', '基本情報', '必須項目を入力してください', $basic_info_actions);
        ?>

        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label required">車両</label>
                        <select class="form-select" name="vehicle_id" required onchange="updateMileage()">
                            <option value="">選択してください</option>
                            <?php foreach ($vehicles as $vehicle) { ?>
                            <option value="<?php echo $vehicle['id']; ?>"
                                    data-mileage="<?php echo $vehicle['current_mileage']; ?>"
                                    data-next-date="<?php echo $vehicle['next_inspection_date']; ?>">
                                <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                <?php if ($vehicle['model']) { ?>
                                    (<?php echo htmlspecialchars($vehicle['model']); ?>)
                                <?php } ?>
                            </option>
                            <?php } ?>
                        </select>
                        <div id="nextInspectionInfo" class="form-text"></div>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label required">点検日</label>
                        <input type="date" class="form-control" name="inspection_date" value="<?php echo $today; ?>" required>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label required">点検者</label>
                        <select class="form-select" name="inspector_id" required>
                            <option value="">選択してください</option>
                            <?php foreach ($inspectors as $inspector) { ?>
                            <option value="<?php echo $inspector['id']; ?>">
                                <?php echo htmlspecialchars($inspector['name']); ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label required">走行距離</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="mileage" id="mileage" required>
                            <span class="input-group-text">km</span>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label required">点検の種類</label>
                        <select class="form-select" name="inspection_type" required>
                            <option value="3months" selected>3か月点検</option>
                            <option value="6months">6か月点検</option>
                            <option value="12months">12か月点検</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- 1. かじ取り装置 -->
        <?php echo renderSectionHeader('steering-wheel', 'かじ取り装置', '3項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $steering_items = [
                    'steering_play' => 'ハンドルの遊び',
                    'steering_effort' => 'ハンドルの操作具合',
                    'steering_connection' => 'ロッド及びアーム類の緩み、がた、損傷'
                ];
                foreach ($steering_items as $key => $label) { ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-lg-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-lg-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-2" onclick="setResult('<?php echo $key; ?>', '該当なし')">該当なし</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- 2. 制動装置 -->
        <?php echo renderSectionHeader('stop-circle', '制動装置', '6項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $braking_items = [
                    'brake_pedal_play' => 'ブレーキ・ペダルの遊び',
                    'brake_pedal_clearance' => 'ブレーキ・ペダルの踏み込み時の床板とのすき間',
                    'brake_effectiveness' => 'ブレーキの効き具合',
                    'parking_brake_effectiveness' => '駐車ブレーキ機構の効き具合',
                    'brake_fluid_amount' => 'ブレーキ液の量',
                    'brake_line_condition' => 'ホース及びパイプの液漏れ'
                ];
                foreach ($braking_items as $key => $label) { ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-lg-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-lg-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-2" onclick="setResult('<?php echo $key; ?>', '該当なし')">該当なし</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- 3. 走行装置 -->
        <?php echo renderSectionHeader('circle', '走行装置', '2項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $running_items = [
                    'wheel_bearing_play' => 'ホイール・ベアリングのがた',
                    'wheel_nut_looseness' => 'ホイール・ナットの緩み'
                ];
                foreach ($running_items as $key => $label) { ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-lg-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-lg-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-2" onclick="setResult('<?php echo $key; ?>', '該当なし')">該当なし</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- 4. 緩衝装置 -->
        <?php echo renderSectionHeader('wave-square', '緩衝装置', '2項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $suspension_items = [
                    'spring_damage' => 'スプリングの損傷',
                    'shock_absorber_condition' => 'ショック・アブソーバーの損傷及び油の漏れ'
                ];
                foreach ($suspension_items as $key => $label) { ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-lg-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-lg-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-2" onclick="setResult('<?php echo $key; ?>', '該当なし')">該当なし</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- 5. 動力伝達装置 -->
        <?php echo renderSectionHeader('cogs', '動力伝達装置', '5項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $power_transmission_items = [
                    'clutch_pedal_play' => 'クラッチ・ペダルの遊び',
                    'clutch_effectiveness' => 'クラッチの効き具合',
                    'transmission_oil_amount' => 'トランスミッション・オイルの量',
                    'transmission_oil_leakage' => 'トランスミッション・オイルの漏れ',
                    'propeller_shaft_connection' => 'プロペラ・シャフト等の連結部の緩み'
                ];
                foreach ($power_transmission_items as $key => $label) { ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-lg-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-lg-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-2" onclick="setResult('<?php echo $key; ?>', '該当なし')">該当なし</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- 6. 電気装置 -->
        <?php echo renderSectionHeader('bolt', '電気装置', '4項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $electrical_items = [
                    'spark_plug_condition' => '点火プラグの状況',
                    'battery_terminal' => 'バッテリー・ターミナルの接続状況',
                    'ignition_timing' => '点火時期',
                    'distributor_cap_condition' => 'デストリビューター・キャップの状況'
                ];
                foreach ($electrical_items as $key => $label) { ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-lg-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-lg-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-2" onclick="setResult('<?php echo $key; ?>', '該当なし')">該当なし</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- 7. 原動機 -->
        <?php echo renderSectionHeader('car-side', '原動機', '4項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $engine_items = [
                    'valve_clearance' => 'バルブ・クリアランス',
                    'engine_oil_leakage' => 'エンジン・オイルの漏れ',
                    'exhaust_gas_condition' => '排気の状況',
                    'air_cleaner_element' => 'エア・クリーナー・エレメントの状況'
                ];
                foreach ($engine_items as $key => $label) { ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-lg-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-lg-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-2" onclick="setResult('<?php echo $key; ?>', '該当なし')">該当なし</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- CO・HC濃度測定 -->
        <?php echo renderSectionHeader('cloud', 'CO・HC濃度測定', '排気ガス測定値'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label">CO濃度</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="co_concentration" step="0.01" min="0" placeholder="0.00">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">基準値：1.0%以下</div>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label">HC濃度</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="hc_concentration" step="1" min="0" placeholder="0">
                            <span class="input-group-text">ppm</span>
                        </div>
                        <div class="form-text">基準値：300ppm以下</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 整備事業者情報 -->
        <?php echo renderSectionHeader('building', '整備事業者情報', '整備実施者の情報'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label">事業者名</label>
                        <input type="text" class="form-control" name="service_provider_name"
                               placeholder="整備を実施した事業者名" value="スマイリーケアタクシー">
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label">事業者住所</label>
                        <input type="text" class="form-control" name="service_provider_address"
                               placeholder="事業者の住所">
                    </div>
                </div>
            </div>
        </div>

        <!-- 備考 -->
        <?php echo renderSectionHeader('comment', '備考', '特記事項の記載'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <textarea class="form-control" name="remarks" rows="3"
                          placeholder="整備内容や特記事項があれば記入してください"></textarea>
            </div>
        </div>

        <?php if ($editing && $edit_check['needs_reason']) { ?>
        <!-- 登録した日より後の修正は理由が要る（監査ログに残る） -->
        <div class="card mb-4 border-warning">
            <div class="card-body">
                <label for="editReason" class="form-label fw-bold">修正の理由 <span class="text-danger small">（必須）</span></label>
                <input type="text" class="form-control" name="edit_reason" id="editReason" required placeholder="例: 走行距離の打ち間違い">
            </div>
        </div>
        <?php } ?>

        <!-- 固定ボタン用スペーサー -->
        <div style="height: 80px;"></div>
    </form>

    <?php if ($editing && $user_role === 'Admin') { ?>
    <form method="POST" id="deleteForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="inspection_id" value="<?= (int)$editing['id'] ?>">
        <input type="hidden" name="delete_reason" id="deleteReason" value="">
    </form>
    <?php } ?>
</div>

<!-- 保存ボタン（画面下部固定） -->
<div style="position: fixed; bottom: 0; left: 0; right: 0; z-index: 1050; background: #fff; padding: 12px 20px; border-top: 2px solid var(--wts-green);">
    <?php if ($editing && !$edit_check['can_edit']) { ?>
    <div class="text-muted text-center py-2"><i class="fas fa-lock me-2"></i>この記録は直せません（管理者に依頼してください）</div>
    <?php } else { ?>
    <button type="submit" form="inspectionForm" class="btn btn-success btn-lg w-100" data-loading-text="保存中..." style="font-size: 1.1rem; font-weight: 600; padding: 14px;">
        <i class="fas fa-save me-2"></i><?= $editing ? '変更を保存' : '登録する' ?>
    </button>
    <?php } ?>
</div>

<style>
@keyframes slideDown {
    from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* 点検項目のスタイル */
.inspection-item {
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
    transition: all 0.2s ease;
}

.inspection-item:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
}

/* 結果ボタンのスタイル（3段階評価・タップターゲット44px以上） */
.result-btn {
    min-width: 80px;
    min-height: 44px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    border: 2px solid;
    padding: 0.25rem 0.75rem;
}

.result-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.result-btn.active {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

/* 3段階評価の色設定（1色=1意味: 緑=良好 / アンバー=要注意 / 赤=不良） */
.result-btn-good {
    background: var(--wts-green-bg);
    color: var(--wts-green);
    border-color: var(--wts-green);
}
.result-btn-good.active {
    background: var(--wts-green);
    color: white;
}

.result-btn-caution {
    background: var(--wts-amber-bg);
    color: #b26a00;
    border-color: var(--wts-amber-dark);
}
.result-btn-caution.active {
    background: var(--wts-amber-dark);
    color: white;
}

.result-btn-bad {
    background: var(--wts-red-bg);
    color: var(--wts-red);
    border-color: var(--wts-red);
}
.result-btn-bad.active {
    background: var(--wts-red);
    color: white;
}

/* 該当なし＝その車に無い項目（灰色＝参照・ADR-0018） */
.result-btn-na {
    background: #f1f3f5;
    color: #495057;
    border-color: #adb5bd;
}
.result-btn-na.active {
    background: #6c757d;
    color: white;
}

.periodic-list summary { cursor: pointer; list-style-position: inside; }

/* フォーム必須マーク */
.required::after {
    content: " *";
    color: var(--wts-red);
    font-weight: bold;
}

/* スレート=中立操作（自動入力） */
.btn-slate { background: var(--wts-slate); border-color: var(--wts-slate); color: #fff; }
.btn-slate:hover, .btn-slate:focus { background: #455a64; border-color: #455a64; color: #fff; }

/* アンバー文字は視認性の高い濃色に */
.text-warning { color: #b26a00 !important; }

/* フォーカス可視化（青=現在地・フォーカス） */
.btn:focus-visible, .result-btn:focus-visible { outline: 3px solid var(--wts-blue); outline-offset: 2px; }
.form-control:focus, .form-select:focus { border-color: var(--wts-blue); }

/* カード統一スタイル */
.card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.card-body {
    padding: 1.5rem;
}

/* レスポンシブ対応 */
@media (max-width: 768px) {
    .result-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .result-btn {
        min-width: 64px;
        min-height: 44px;
        font-size: 0.8rem;
        padding: 0.25rem 0.5rem;
    }

    .inspection-item .col-lg-4,
    .inspection-item .col-lg-8 {
        margin-bottom: 0.5rem;
    }
}
</style>

<script>
// 中央表示版showToast
function showToast(message, type) {
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
window.showToast = showToast;

// 車両選択時の走行距離更新
function updateMileage() {
    const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
    const mileageInput = document.getElementById('mileage');
    const nextInfo = document.getElementById('nextInspectionInfo');

    if (vehicleSelect.value) {
        const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
        const currentMileage = selectedOption.getAttribute('data-mileage');
        const nextDate = selectedOption.getAttribute('data-next-date');

        if (currentMileage && currentMileage !== '0') {
            mileageInput.value = currentMileage;
        }

        if (nextDate && nextDate !== 'null' && nextDate !== '') {
            const today = new Date();
            const next = new Date(nextDate);
            const diffTime = next - today;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            if (diffDays < 0) {
                nextInfo.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>点検期限切れ（' + Math.abs(diffDays) + '日経過）</span>';
            } else if (diffDays <= 7) {
                nextInfo.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>点検期限まであと' + diffDays + '日</span>';
            } else {
                nextInfo.innerHTML = '<span class="text-muted">次回点検予定日: ' + nextDate + '</span>';
            }
        } else {
            nextInfo.innerHTML = '<span class="text-muted">点検予定日未設定</span>';
        }
    } else {
        nextInfo.innerHTML = '';
    }
}

// 点検結果の設定
function setResult(itemName, value) {
    // 隠しフィールドに値を設定
    document.getElementById(itemName).value = value;

    // ボタンのアクティブ状態を更新
    const buttons = document.querySelectorAll('[data-item="' + itemName + '"] .result-btn');
    buttons.forEach(function(btn) {
        btn.classList.remove('active');
        if (btn.textContent.trim() === value) {
            btn.classList.add('active');
        }
    });
}

// 全て良好にする
function setAllGood() {
    const allItems = document.querySelectorAll('.result-buttons');
    allItems.forEach(function(item) {
        const itemName = item.getAttribute('data-item');
        setResult(itemName, '良好');
    });

    // 成功メッセージを表示
    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            全ての項目を「良好」に設定しました。
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);

    // 3秒後にアラートを自動で閉じる
    setTimeout(() => {
        const alert = document.querySelector('.alert-success');
        if (alert) {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }
    }, 3000);
}

// 初期化処理
document.addEventListener('DOMContentLoaded', function() {
    // 開いた記録・戻した入力の走行距離は、車両の今のメーターで上書きしない
    var keepMileage = document.getElementById('mileage').value;
    updateMileage();
    if (keepMileage !== '') document.getElementById('mileage').value = keepMileage;

    // フォーム送信前の確認
    document.getElementById('inspectionForm').addEventListener('submit', function(e) {
        const vehicleId = document.querySelector('select[name="vehicle_id"]').value;
        const inspectorId = document.querySelector('select[name="inspector_id"]').value;
        const mileage = document.getElementById('mileage').value;

        if (!vehicleId || !inspectorId || !mileage) {
            e.preventDefault();
            showToast('必須項目（車両、点検者、走行距離）を入力してください。', 'warning');
            return;
        }

        // 結果を選んでいない項目を、送る前に知らせる
        var itemFields = document.querySelectorAll('.result-buttons');
        var missing = [];
        itemFields.forEach(function(group) {
            var field = document.getElementById(group.getAttribute('data-item'));
            if (field && field.value === '') missing.push(group);
        });
        if (missing.length === itemFields.length) {
            e.preventDefault();
            missing[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            showToast('点検結果が1つも選ばれていません。各項目の「良好・要注意・不良・該当なし」を選んでください。', 'warning');
            return;
        }
        if (missing.length > 0 && !confirm('結果を選んでいない項目が ' + missing.length + ' 件あります。このまま登録しますか？（「キャンセル」で、選んでいない最初の項目へ移ります）')) {
            e.preventDefault();
            missing[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        e.preventDefault();
        var form = this;
        var reasonEl = document.getElementById('editReason');
        if (reasonEl && !reasonEl.value.trim()) {
            reasonEl.focus();
            showToast('修正の理由を入れてください。', 'warning');
            return;
        }
        showConfirm(<?= json_encode($editing ? "定期点検記録の変更を保存しますか？\n\n次回点検日は点検日の3か月後になります。" : "定期点検記録を保存しますか？\n\n次回点検日が自動的に3か月後に設定されます。", JSON_UNESCAPED_UNICODE) ?>, function() {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>保存中...';
            }
            form.submit();
        }, {
            type: 'info',
            confirmText: '登録する'
        });
    });
});
</script>

<script>
// 開いた記録の値を画面に入れる（一覧の「開く」）
(function () {
    var data = <?= json_encode($prefill, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    if (!data) return;
    var form = document.getElementById('inspectionForm');
    Object.keys(data).forEach(function (k) {
        if (<?= json_encode($PERIODIC_RESULTS, JSON_UNESCAPED_UNICODE) ?>.indexOf(data[k]) !== -1 && document.getElementById(k) && document.querySelector('[data-item="' + k + '"]')) {
            setResult(k, data[k]);
            return;
        }
        var el = form.querySelector('[name="' + k + '"]');
        if (el) el.value = data[k];
    });
})();

// 削除（管理者だけ・理由を残す）
function confirmDeletePeriodic() {
    showConfirm('この定期点検の記録を削除しますか？（消す前に控えを残します）', function (reason) {
        document.getElementById('deleteReason').value = reason;
        formChanged = false;
        document.getElementById('deleteForm').submit();
    }, {
        inputLabel: '削除の理由',
        inputRequired: true,
        inputPlaceholder: '例: 同じ点検を二重に登録した',
        type: 'danger',
        confirmText: '削除する'
    });
}

// --- beforeunload 離脱警告 ---
let formChanged = false;
document.getElementById('inspectionForm').addEventListener('input', function() { formChanged = true; });
document.getElementById('inspectionForm').addEventListener('change', function() { formChanged = true; });
document.getElementById('inspectionForm').addEventListener('submit', function() { formChanged = false; });
window.addEventListener('beforeunload', function(e) {
    if (formChanged) { e.preventDefault(); e.returnValue = ''; }
});
// ボタン操作でも変更フラグを立てる
document.querySelectorAll('.result-btn').forEach(function(btn) {
    btn.addEventListener('click', function() { formChanged = true; });
});
</script>

</main>
<?= renderRestoreInputScript($error_message, ['' => '#inspectionForm'], 'function (data) { if (typeof setResult !== "function") return; Object.keys(data).forEach(function (k) { if (["良好", "要注意", "不良", "該当なし"].indexOf(data[k]) !== -1 && document.getElementById(k)) setResult(k, data[k]); }); }') ?>
<?php echo $page_data['html_footer'] ?? ''; ?>
