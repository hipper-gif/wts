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

    // 点呼者取得（permission_levelがAdminまたはis_callerフラグ）
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE (permission_level = 'Admin' OR is_caller = 1) AND is_active = 1 ORDER BY name");
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
    $stmt = $pdo->prepare("SELECT * FROM post_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$selected_driver_id, $today]);
    $existing_call = $stmt->fetch();
    $is_edit_mode = (bool)$existing_call;
}

// 対応する乗務前点呼の取得
$pre_duty_call = null;
if ($selected_driver_id) {
    $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$selected_driver_id, $today]);
    $pre_duty_call = $stmt->fetch();
}

// 修正・削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM post_duty_calls WHERE driver_id = ? AND call_date = ?");
            $stmt->execute([$_POST['driver_id'], $today]);
            $success_message = '乗務後点呼記録を削除しました。';
            $existing_call = null;
            $is_edit_mode = false;
        } catch (Exception $e) {
            $error_message = '削除中にエラーが発生しました: ' . $e->getMessage();
            error_log("Post duty call delete error: " . $e->getMessage());
        }
    }
}

// フォーム送信処理（登録・更新）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $driver_id = $_POST['driver_id'];
    $call_time = $_POST['call_time'];

    // 今日の出庫記録から車両IDを取得（出庫記録がない場合はNULL）
    $stmt = $pdo->prepare("SELECT vehicle_id FROM departure_records WHERE driver_id = ? AND departure_date = ? LIMIT 1");
    $stmt->execute([$driver_id, $today]);
    $departure_record = $stmt->fetch();

    // 出庫記録がなくても点呼は実施可能
    $vehicle_id = $departure_record ? $departure_record['vehicle_id'] : null;

    // 点呼者名の処理
    $caller_name = $_POST['caller_name'];
    if ($caller_name === 'その他') {
        $caller_name = $_POST['other_caller'];
    }

    $alcohol_check_value = $_POST['alcohol_check_value'];

    // 12項目確認事項のチェック（仕様書準拠）
    $check_items = [
        'duty_record_check',         // 1. 乗務記録確認
        'vehicle_condition_check',   // 2. 車両状態確認
        'health_condition_check',    // 3. 健康状態確認
        'fatigue_check',            // 4. 疲労度確認
        'alcohol_drug_check',       // 5. 酒気・薬物確認
        'accident_violation_check', // 6. 事故・違反確認
        'equipment_return_check',   // 7. 用具返却確認
        'report_completion_check',  // 8. 報告完了確認
        'lost_items_check',        // 9. 忘れ物確認
        'violation_accident_check', // 10. 違反・事故確認（重複チェック）
        'route_operation_check',   // 11. 路線運行確認
        'passenger_condition_check' // 12. 乗客状態確認
    ];

    try {
        // 対応する乗務前点呼の確認
        $stmt = $pdo->prepare("SELECT id FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
        $stmt->execute([$driver_id, $today]);
        $pre_duty_record = $stmt->fetch();

        if (!$pre_duty_record) {
            throw new Exception('対応する乗務前点呼記録が見つかりません。先に乗務前点呼を実施してください。');
        }

        $pre_duty_call_id = $pre_duty_record['id'];

        // 既存レコードの確認
        $stmt = $pdo->prepare("SELECT id FROM post_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
        $stmt->execute([$driver_id, $today]);
        $existing = $stmt->fetch();

        if ($existing) {
            // 更新
            $sql = "UPDATE post_duty_calls SET
                    call_time = ?, caller_name = ?, alcohol_check_value = ?, alcohol_check_time = ?,
                    pre_duty_call_id = ?,";

            foreach ($check_items as $item) {
                $sql .= " $item = ?,";
            }

            $sql .= " remarks = ?, is_completed = TRUE, updated_at = NOW()
                    WHERE driver_id = ? AND call_date = ?";

            $stmt = $pdo->prepare($sql);
            $params = [$call_time, $caller_name, $alcohol_check_value, $call_time, $pre_duty_call_id];

            foreach ($check_items as $item) {
                $params[] = isset($_POST[$item]) ? 1 : 0;
            }

            $params[] = $_POST['remarks'] ?? '';
            $params[] = $driver_id;
            $params[] = $today;

            $stmt->execute($params);
            $success_message = '乗務後点呼記録を更新しました。';
        } else {
            // 新規挿入
            $sql = "INSERT INTO post_duty_calls (
                    driver_id, vehicle_id, call_date, call_time, caller_name,
                    alcohol_check_value, alcohol_check_time, pre_duty_call_id,";

            foreach ($check_items as $item) {
                $sql .= " $item,";
            }

            $sql .= " remarks, is_completed) VALUES (?, ?, ?, ?, ?, ?, ?, ?,";

            $sql .= str_repeat('?,', count($check_items));
            $sql .= " ?, TRUE)";

            $stmt = $pdo->prepare($sql);
            $params = [$driver_id, $vehicle_id, $today, $call_time, $caller_name, $alcohol_check_value, $call_time, $pre_duty_call_id];

            foreach ($check_items as $item) {
                $params[] = isset($_POST[$item]) ? 1 : 0;
            }

            $params[] = $_POST['remarks'] ?? '';

            $stmt->execute($params);
            $success_message = '乗務後点呼記録を登録しました。';
        }

        // 記録を再取得
        $stmt = $pdo->prepare("SELECT * FROM post_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
        $stmt->execute([$driver_id, $today]);
        $existing_call = $stmt->fetch();
        $is_edit_mode = true;

    } catch (Exception $e) {
        $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
        error_log("Post duty call error: " . $e->getMessage());
    }
}

// ページ設定
$page_config = getPageConfiguration('post_duty_call');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => ['css/post-duty-styles.css'],
    'additional_js' => ['js/post-duty-interactions.js'],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '乗務後点呼', 'url' => 'post_duty_call.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'post_duty_call',
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
                    <h5 class="alert-heading mb-1">乗務後点呼完了</h5>
                    <p class="mb-0">業務お疲れさまでした。最後に集金管理を確認してください。</p>
                </div>
                <a href="cash_management.php?driver_id=<?= $existing_call['driver_id'] ?>" 
                   class="btn btn-success btn-lg">
                    <i class="fas fa-money-bill-wave me-2"></i>集金管理へ進む
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

        <!-- 乗務前点呼情報表示 -->
        <?php if ($pre_duty_call): ?>
        <div class="alert alert-info border-0 shadow-sm mb-4">
            <h6><i class="fas fa-link me-2"></i>対応する乗務前点呼情報</h6>
            <div class="row">
                <div class="col-md-6">
                    <small><strong>点呼時刻:</strong> <?= substr($pre_duty_call['call_time'], 0, 5) ?></small>
                </div>
                <div class="col-md-6">
                    <small><strong>点呼者:</strong> <?= htmlspecialchars($pre_duty_call['caller_name']) ?></small>
                </div>
                <div class="col-md-6">
                    <small><strong>アルコールチェック:</strong> <?= $pre_duty_call['alcohol_check_value'] ?> mg/L</small>
                </div>
                <div class="col-md-6">
                    <small><span class="badge bg-success">乗務前点呼完了</span></small>
                </div>
            </div>
        </div>
        <?php endif; ?>



        <form method="POST" id="postDutyForm">
            <!-- 基本情報セクション -->
            <?= renderSectionHeader('info', '基本情報', '運転者・点呼者・時刻') ?>
            
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">運転者 <span class="text-danger">*</span></label>
                            <select class="form-select" name="driver_id" <?= $is_edit_mode ? 'readonly' : '' ?> required>
                                <option value="">選択してください</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" 
                                    <?= ($driver['id'] == $user_id || ($existing_call && $existing_call['driver_id'] == $driver['id'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">点呼時刻 <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="call_time" 
                                   value="<?= $existing_call ? $existing_call['call_time'] : $current_time ?>" 
                                   <?= $is_edit_mode ? 'readonly' : '' ?> required>
                        </div>
                        <div class="col-md-4 mb-3">
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
            echo renderSectionHeader('tasks', '確認事項', '12項目の法定チェック', $check_actions);
            ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <?php
                    $check_items_labels = [
                        'duty_record_check' => '乗務記録の記載は完了しているか',
                        'vehicle_condition_check' => '車両に異常・損傷はないか', 
                        'health_condition_check' => '健康状態に異常はないか',
                        'fatigue_check' => '疲労・睡眠不足はないか',
                        'alcohol_drug_check' => '酒気・薬物の影響はないか',
                        'accident_violation_check' => '事故・違反の発生はないか',
                        'equipment_return_check' => '業務用品は適切に返却されているか',
                        'report_completion_check' => '業務報告は完了しているか',
                        'lost_items_check' => '車内の忘れ物はないか',
                        'violation_accident_check' => '事故・違反の最終確認',
                        'route_operation_check' => '予定路線での運行は適切だったか',
                        'passenger_condition_check' => '乗客に関する特記事項はないか'
                    ];
                    ?>

                    <div class="row g-3">
                        <?php $count = 1; foreach ($check_items_labels as $key => $label): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-check p-3 border rounded <?= $is_edit_mode ? '' : 'check-item-clickable' ?> <?= ($existing_call && $existing_call[$key]) ? 'bg-success bg-opacity-10 border-success' : '' ?>" 
                                 <?= $is_edit_mode ? '' : 'onclick="toggleCheck(\'' . $key . '\')"' ?>>
                                <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>"
                                       <?= ($existing_call && $existing_call[$key]) ? 'checked' : '' ?>
                                       <?= $is_edit_mode ? 'disabled' : '' ?>>
                                <label class="form-check-label d-block" for="<?= $key ?>">
                                    <span class="fw-bold text-primary"><?= $count ?>.</span> 
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                        </div>
                        <?php $count++; endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- アルコールチェックセクション -->
            <?= renderSectionHeader('wine-bottle', 'アルコールチェック', '法定義務', [
                ['icon' => 'check', 'text' => '0.000設定', 'url' => 'javascript:setAlcoholZero()', 'class' => 'btn-success btn-sm']
            ]) ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <label class="form-label fw-bold mb-0">測定値 <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-auto">
                            <input type="number" class="form-control" name="alcohol_check_value" id="alcohol_check_value"
                                   step="0.001" min="0" max="1" style="width: 120px;"
                                   value="<?= $existing_call ? $existing_call['alcohol_check_value'] : '0.000' ?>"
                                   <?= $is_edit_mode ? 'readonly' : '' ?> required>
                        </div>
                        <div class="col-auto">
                            <span class="text-muted">mg/L</span>
                        </div>
                        <div class="col">
                            <small class="text-muted">通常は 0.000 mg/L です</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 備考セクション -->
            <?= renderSectionHeader('comment', '備考', '特記事項・報告事項') ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <textarea class="form-control" name="remarks" rows="4" 
                              placeholder="特記事項、報告事項、業務中の出来事などがあれば記入してください"
                              <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_call ? htmlspecialchars($existing_call['remarks']) : '' ?></textarea>
                </div>
            </div>

            <!-- アクションボタン -->
            <div class="text-center">
                <?php if (!$is_edit_mode): ?>
                    <button type="submit" class="btn btn-primary btn-lg shadow-sm">
                        <i class="fas fa-save me-2"></i>
                        <?= $existing_call ? '更新する' : '登録する' ?>
                    </button>
                <?php else: ?>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-outline-primary btn-lg" onclick="enableEditMode()">
                            <i class="fas fa-edit me-2"></i>修正する
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('本当に削除しますか？')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="driver_id" value="<?= $existing_call['driver_id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-lg">
                                <i class="fas fa-trash me-2"></i>削除する
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</main>

<script>
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

// 全てチェック
function checkAll() {
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]:not(:disabled)');
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = true;
        updateCheckItemStyle(checkbox);
    });
}

// 全て解除
function uncheckAll() {
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]:not(:disabled)');
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = false;
        updateCheckItemStyle(checkbox);
    });
}

// アルコール濃度を0.000に設定
function setAlcoholZero() {
    document.getElementById('alcohol_check_value').value = '0.000';
}

// チェック項目のクリック処理
function toggleCheck(itemId) {
    const checkbox = document.getElementById(itemId);
    if (checkbox.disabled) return;
    
    checkbox.checked = !checkbox.checked;
    updateCheckItemStyle(checkbox);
}

// チェック項目のスタイル更新
function updateCheckItemStyle(checkbox) {
    const container = checkbox.closest('.form-check');
    
    if (checkbox.checked) {
        container.classList.add('bg-success', 'bg-opacity-10', 'border-success');
    } else {
        container.classList.remove('bg-success', 'bg-opacity-10', 'border-success');
    }
}

// 編集モード有効化
function enableEditMode() {
    // フィールドを編集可能に
    const fields = document.querySelectorAll('input, select, textarea');
    fields.forEach(field => {
        field.removeAttribute('readonly');
        field.removeAttribute('disabled');
    });
    
    // チェックボックスを有効化
    const checkItems = document.querySelectorAll('.check-item-clickable');
    checkItems.forEach(item => {
        item.onclick = function() {
            const checkbox = item.querySelector('input[type="checkbox"]');
            toggleCheck(checkbox.id);
        };
    });
    
    // ボタンを変更
    document.querySelector('.text-center').innerHTML = `
        <button type="submit" class="btn btn-primary btn-lg shadow-sm">
            <i class="fas fa-save me-2"></i>更新する
        </button>
    `;
}

// 初期化処理
document.addEventListener('DOMContentLoaded', function() {
    // 既存チェック項目のスタイル適用
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
    checkboxes.forEach(function(checkbox) {
        updateCheckItemStyle(checkbox);
        
        // チェック状態変更時のイベント
        checkbox.addEventListener('change', function() {
            updateCheckItemStyle(this);
        });
    });
    
    // 点呼者選択の初期設定
    const callerSelect = document.querySelector('select[name="caller_name"]');
    if (callerSelect) {
        callerSelect.addEventListener('change', toggleCallerInput);
        toggleCallerInput(); // 初期表示
    }
    
    // 現在時刻を自動設定（新規作成時のみ）
    <?php if (!$existing_call): ?>
    const timeInput = document.querySelector('input[name="call_time"]');
    if (timeInput && !timeInput.value) {
        const now = new Date();
        const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                          now.getMinutes().toString().padStart(2, '0');
        timeInput.value = timeString;
    }
    <?php endif; ?>
});

// フォーム送信前の確認
document.getElementById('postDutyForm').addEventListener('submit', function(e) {
    const driverId = document.querySelector('select[name="driver_id"]').value;
    
    if (!driverId) {
        e.preventDefault();
        alert('運転者を選択してください。');
        return;
    }
    
    // 点呼者名の確認
    const callerSelect = document.querySelector('select[name="caller_name"]');
    const otherInput = document.getElementById('other_caller');
    
    if (callerSelect.value === 'その他' && !otherInput.value.trim()) {
        e.preventDefault();
        alert('点呼者名を入力してください。');
        return;
    }
});
</script>

<?php
// フッター出力
echo $page_data['html_footer'];
?>
