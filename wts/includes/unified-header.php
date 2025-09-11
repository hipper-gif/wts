<?php
session_start();
require_once 'config/database.php';
require_once 'includes/unified-header.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'] ?? 'User';
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';
$is_edit_mode = false;

// ドライバーと点呼者の取得
try {
    // 運転者取得（is_driverフラグのみ）
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// 修正・削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM pre_duty_calls WHERE driver_id = ? AND call_date = ?");
            $stmt->execute([$_POST['driver_id'], $today]);
            $success_message = '乗務前点呼記録を削除しました。';
            $existing_call = null;
            $is_edit_mode = false;
        } catch (Exception $e) {
            $error_message = '削除中にエラーが発生しました: ' . $e->getMessage();
            error_log("Pre duty call delete error: " . $e->getMessage());
        }
    }
}

// フォーム送信処理（登録・更新）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
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
            // 既存レコードの確認（車両IDは使わず、運転者と日付のみで検索）
            $stmt = $pdo->prepare("SELECT id FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing = $stmt->fetch();

            if ($existing) {
                // 更新
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
                $success_message = '乗務前点呼記録を更新しました。';
            } else {
                // 新規挿入
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
                $success_message = '乗務前点呼記録を登録しました。';
            }

            // 記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing_call = $stmt->fetch();
            $is_edit_mode = true;

        } catch (Exception $e) {
            $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
            error_log("Pre duty call error: " . $e->getMessage());
        }
    }
}

// ページ設定
$page_config = getPageConfiguration('pre_duty_call');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => ['css/pre-duty-styles.css'],
    'additional_js' => ['js/pre-duty-interactions.js'],
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

        <!-- 業務フロー進捗表示 -->
        <?= renderWorkflowProgress(2, $existing_call ? [1, 2] : [], $today) ?>

        <form method="POST" id="predutyForm">
            <!-- 基本情報セクション -->
            <?php
            $basic_actions = [];
            if ($is_edit_mode) {
                $basic_actions[] = [
                    'icon' => 'edit',
                    'text' => '修正',
                    'url' => 'javascript:enableEdit()',
                    'class' => 'btn-warning btn-sm'
                ];
                $basic_actions[] = [
                    'icon' => 'trash',
                    'text' => '削除',
                    'url' => 'javascript:confirmDelete()',
                    'class' => 'btn-danger btn-sm'
                ];
            }
            echo renderSectionHeader('info-circle', '基本情報', '', $basic_actions);
            ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">運転者 <span class="text-danger">*</span></label>
                            <select class="form-select" name="driver_id" id="driverSelect" required <?= $is_edit_mode ? 'disabled' : '' ?>>
                                <option value="">選択してください</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" 
                                    <?= (($existing_call && $existing_call['driver_id'] == $driver['id']) || 
                                         (!$existing_call && $selected_driver_id == $driver['id'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['name']) ?>
                                    <?= $driver['id'] == $user_id ? ' (ログイン中)' : '' ?>
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
                            <select class="form-select" name="caller_name" <?= $is_edit_mode ? 'disabled' : '' ?> required>
                                <option value="">選択してください</option>
                                <?php foreach ($callers as $caller): ?>
                                <option value="<?= htmlspecialchars($caller['name']) ?>" 
                                    <?= ($existing_call && $existing_call['caller_name'] == $caller['name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($caller['name']) ?>
                                </option>
                                <?php endforeach; ?>
                                <option value="その他" <?= ($existing_call && !in_array($existing_call['caller_name'], array_column($callers, 'name')) && $existing_call['caller_name'] != '') ? 'selected' : '' ?>>その他</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="other_caller" name="other_caller" 
                                placeholder="その他の場合は名前を入力" style="display: none;"
                                <?= $is_edit_mode ? 'readonly' : '' ?>
                                value="<?= ($existing_call && !in_array($existing_call['caller_name'], array_column($callers, 'name')) && $existing_call['caller_name'] != '') ? htmlspecialchars($existing_call['caller_name']) : '' ?>">
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
            echo renderSectionHeader('tasks', '確認事項', '16項目', $check_actions);
            ?>

            <div class="card mb-4">
                <div class="card-body">
                    <?php
                    $check_items = [
                        'health_check' => '健康状態',
                        'clothing_check' => '服装',
                        'footwear_check' => '履物',
                        'pre_inspection_check' => '運行前点検',
                        'license_check' => '免許証',
                        'vehicle_registration_check' => '車検証',
                        'insurance_check' => '保険証',
                        'emergency_tools_check' => '応急工具',
                        'map_check' => '地図',
                        'taxi_card_check' => 'タクシーカード',
                        'emergency_signal_check' => '非常信号用具',
                        'change_money_check' => '釣銭',
                        'crew_id_check' => '乗務員証',
                        'operation_record_check' => '運行記録用用紙',
                        'receipt_check' => '領収書',
                        'stop_sign_check' => '停止表示機'
                    ];
                    ?>

                    <div class="row g-3">
                        <?php foreach ($check_items as $key => $label): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-check p-3 border rounded <?= $is_edit_mode ? '' : 'check-item-clickable' ?> <?= ($existing_call && $existing_call[$key]) ? 'bg-success bg-opacity-10 border-success' : '' ?>" 
                                 <?= $is_edit_mode ? '' : "onclick=\"toggleCheck('$key')\"" ?>>
                                <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>"
                                    <?= ($existing_call && $existing_call[$key]) ? 'checked' : '' ?>
                                    <?= $is_edit_mode ? 'disabled' : '' ?>>
                                <label class="form-check-label fw-semibold" for="<?= $key ?>">
                                    <?= htmlspecialchars($label) ?>
                                    <?php if (in_array($key, ['health_check', 'pre_inspection_check', 'license_check'])): ?>
                                        <span class="text-danger ms-1">*</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- アルコールチェックセクション -->
            <?= renderSectionHeader('wine-bottle', 'アルコールチェック', '') ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <label class="form-label fw-bold mb-0">測定値 <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-auto">
                            <div class="input-group">
                                <input type="number" class="form-control" name="alcohol_check_value" 
                                    step="0.001" min="0" max="1" style="max-width: 120px;"
                                    value="<?= $existing_call ? $existing_call['alcohol_check_value'] : '0.000' ?>" 
                                    <?= $is_edit_mode ? 'readonly' : '' ?> required>
                                <span class="input-group-text">mg/L</span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <small class="text-muted">基準値: 0.000 mg/L</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 備考セクション -->
            <?= renderSectionHeader('comment', '備考', '') ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <textarea class="form-control" name="remarks" rows="3" 
                        placeholder="特記事項があれば記入してください"
                        <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_call ? htmlspecialchars($existing_call['remarks']) : '' ?></textarea>
                </div>
            </div>

            <!-- 保存ボタン -->
            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                <button type="submit" class="btn btn-primary btn-lg px-5" id="saveBtn" <?= $is_edit_mode ? 'style="display:none;"' : '' ?>>
                    <i class="fas fa-save me-2"></i>
                    <?= $existing_call ? '更新する' : '登録する' ?>
                </button>
            </div>
        </form>

        <!-- 削除確認フォーム（非表示） -->
        <form method="POST" id="deleteForm" style="display: none;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="driver_id" value="<?= $existing_call ? $existing_call['driver_id'] : '' ?>">
        </form>
    </div>
</main>

<!-- カスタムスタイル -->
<style>
.check-item-clickable {
    cursor: pointer;
    transition: all 0.2s ease;
}

.check-item-clickable:hover {
    background-color: rgba(var(--bs-primary-rgb), 0.1) !important;
    border-color: var(--bs-primary) !important;
}

.form-check-input:checked {
    background-color: var(--bs-success);
    border-color: var(--bs-success);
}

.main-content {
    margin-top: 120px; /* 統一ヘッダー分のマージン */
}

@media (max-width: 768px) {
    .main-content {
        margin-top: 100px;
    }
}
</style>

<!-- カスタムJavaScript -->
<script>
let editMode = <?= $is_edit_mode ? 'true' : 'false' ?>;

// 点呼者選択の表示切替
function toggleCallerInput() {
    const callerSelect = document.querySelector('select[name="caller_name"]');
    const otherInput = document.getElementById('other_caller');

    if (callerSelect.value === 'その他') {
        otherInput.style.display = 'block';
        otherInput.required = true;
    } else {
        otherInput.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}

// 修正モードの有効化
function enableEdit() {
    editMode = false;
    
    // フォーム要素の有効化
    const formElements = document.querySelectorAll('select, input, textarea');
    formElements.forEach(element => {
        element.disabled = false;
        element.removeAttribute('readonly');
    });

    // 運転者選択は変更不可のまま
    document.getElementById('driverSelect').disabled = true;

    // チェックボックスの有効化
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        checkbox.disabled = false;
    });

    // チェック項目のクリック有効化
    const checkItems = document.querySelectorAll('.check-item-clickable');
    checkItems.forEach(item => {
        const checkboxId = item.querySelector('.form-check-input').id;
        item.setAttribute('onclick', `toggleCheck('${checkboxId}')`);
    });

    // 保存ボタンの表示
    document.getElementById('saveBtn').style.display = 'inline-block';
    
    showPWANotification('編集モードを有効にしました', 'info');
}

// 削除確認
function confirmDelete() {
    if (confirm('この乗務前点呼記録を削除してもよろしいですか？\n削除すると復元できません。')) {
        document.getElementById('deleteForm').submit();
    }
}

// 全てチェック
function checkAll() {
    if (editMode) return;
    
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
    checkboxes.forEach(function(checkbox) {
        if (!checkbox.disabled) {
            checkbox.checked = true;
            updateCheckStyle(checkbox);
        }
    });
    showPWANotification('全項目をチェックしました', 'success');
}

// 全て解除
function uncheckAll() {
    if (editMode) return;
    
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
    checkboxes.forEach(function(checkbox) {
        if (!checkbox.disabled) {
            checkbox.checked = false;
            updateCheckStyle(checkbox);
        }
    });
    showPWANotification('全項目のチェックを解除しました', 'warning');
}

// チェック項目のクリック処理
function toggleCheck(itemId) {
    if (editMode) return;
    
    const checkbox = document.getElementById(itemId);
    if (!checkbox.disabled) {
        checkbox.checked = !checkbox.checked;
        updateCheckStyle(checkbox);
    }
}

// チェックスタイル更新
function updateCheckStyle(checkbox) {
    const container = checkbox.closest('.form-check');
    if (checkbox.checked) {
        container.classList.add('bg-success', 'bg-opacity-10', 'border-success');
    } else {
        container.classList.remove('bg-success', 'bg-opacity-10', 'border-success');
    }
}

// 運転者選択変更時の処理
function onDriverChange() {
    const driverSelect = document.getElementById('driverSelect');
    if (driverSelect.value) {
        // 選択された運転者の既存記録をチェック
        window.location.href = `pre_duty_call.php?driver_id=${driverSelect.value}`;
    }
}

// 初期化処理
document.addEventListener('DOMContentLoaded', function() {
    // 既存チェック項目のスタイル適用
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
    checkboxes.forEach(updateCheckStyle);

    // 点呼者選択の初期設定
    const callerSelect = document.querySelector('select[name="caller_name"]');
    if (callerSelect) {
        callerSelect.addEventListener('change', toggleCallerInput);
        toggleCallerInput(); // 初期表示
    }

    // 運転者選択の変更イベント
    const driverSelect = document.getElementById('driverSelect');
    if (driverSelect && !editMode) {
        driverSelect.addEventListener('change', onDriverChange);
    }
});

// フォーム送信前の確認
document.getElementById('predutyForm').addEventListener('submit', function(e) {
    const driverId = document.querySelector('select[name="driver_id"]').value;

    if (!driverId) {
        e.preventDefault();
        showPWANotification('運転者を選択してください', 'warning');
        return;
    }

    // 点呼者名の確認
    const callerSelect = document.querySelector('select[name="caller_name"]');
    const otherInput = document.getElementById('other_caller');

    if (callerSelect.value === 'その他' && !otherInput.value.trim()) {
        e.preventDefault();
        showPWANotification('点呼者名を入力してください', 'warning');
        return;
    }

    // 必須チェック項目の確認
    const requiredChecks = ['health_check', 'pre_inspection_check', 'license_check'];
    let allChecked = true;
    let uncheckedItems = [];

    requiredChecks.forEach(function(checkId) {
        const checkbox = document.getElementById(checkId);
        if (!checkbox.checked) {
            allChecked = false;
            uncheckedItems.push(checkbox.closest('.form-check').querySelector('label').textContent.replace('*', '').trim());
        }
    });

    if (!allChecked) {
        if (!confirm(`必須項目が未チェックです：\n・${uncheckedItems.join('\n・')}\n\nこのまま保存しますか？`)) {
            e.preventDefault();
            return;
        }
    }

    // アルコールチェック値の確認
    const alcoholValue = parseFloat(document.querySelector('input[name="alcohol_check_value"]').value);
    if (alcoholValue > 0) {
        if (!confirm(`アルコールチェック値が ${alcoholValue} mg/L です。\n基準値を超えていないか確認してください。\n\nこのまま保存しますか？`)) {
            e.preventDefault();
            return;
        }
    }

    // 保存中のローディング表示
    const submitBtn = document.getElementById('saveBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>保存中...';
    }
});
</script>

<?= $page_data['html_footer'] ?>
