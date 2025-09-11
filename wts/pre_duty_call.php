<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';

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
$default_driver_id = null;

// URLパラメータから運転者指定がある場合
if (isset($_GET['driver_id']) && !empty($_GET['driver_id'])) {
    $default_driver_id = $_GET['driver_id'];
} elseif ($is_current_user_driver) {
    // ログインユーザーが運転者の場合、デフォルト選択
    $default_driver_id = $user_id;
}

if ($default_driver_id) {
    $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$default_driver_id, $today]);
    $existing_call = $stmt->fetch();
}

// 修正モードかどうかの判定
$is_edit_mode = (bool)$existing_call;

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'];
    $call_time = $_POST['call_time'];

    try {
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
            throw new Exception('使用可能な車両が見つかりません。車両管理画面で車両を登録してください。');
        }

        $vehicle_id = $vehicle_record['id'];

        // 点呼者名の処理
        $caller_name = $_POST['caller_name'];
        if ($caller_name === 'その他') {
            $caller_name = $_POST['other_caller'];
            if (empty(trim($caller_name))) {
                throw new Exception('点呼者名を入力してください。');
            }
        }

        $alcohol_check_value = $_POST['alcohol_check_value'];

        // アルコール値の検証
        if (!is_numeric($alcohol_check_value) || $alcohol_check_value < 0 || $alcohol_check_value > 1) {
            throw new Exception('アルコール測定値は0.000-1.000の範囲で入力してください。');
        }

        // 確認事項のチェック
        $check_items = [
            'health_check', 'clothing_check', 'footwear_check', 'pre_inspection_check',
            'license_check', 'vehicle_registration_check', 'insurance_check', 'emergency_tools_check',
            'map_check', 'taxi_card_check', 'emergency_signal_check', 'change_money_check',
            'crew_id_check', 'operation_record_check', 'receipt_check', 'stop_sign_check'
        ];

        // 既存レコードの確認
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

// 統一ヘッダー用の設定
$page_config = [
    'title' => '乗務前点呼',
    'icon' => 'clipboard-check',
    'category' => '日次業務',
    'step' => 2,
    'max_steps' => 7,
    'description' => '16項目の安全確認とアルコールチェック'
];

// 統一ヘッダーの読み込み
function renderPageHeader($config) {
    return '<div class="unified-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <div class="header-content">
                        <div class="header-icon">
                            <i class="fas fa-' . $config['icon'] . '"></i>
                        </div>
                        <div class="header-text">
                            <h1 class="header-title">' . $config['title'] . '</h1>
                            <div class="header-subtitle">
                                <span class="badge bg-primary me-2">' . $config['category'] . '</span>
                                <span class="text-muted">ステップ ' . $config['step'] . '/' . $config['max_steps'] . '</span>
                            </div>
                            <p class="header-description">' . $config['description'] . '</p>
                        </div>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="header-actions">
                        <a href="dashboard.php" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left me-1"></i>ダッシュボード
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>';
}

function renderSectionHeader($icon, $title, $subtitle = '', $actions = []) {
    $html = '<div class="section-header">
        <div class="section-title-group">
            <div class="section-icon">
                <i class="fas fa-' . $icon . '"></i>
            </div>
            <div class="section-text">
                <h5 class="section-title">' . $title . '</h5>';
    
    if ($subtitle) {
        $html .= '<div class="section-subtitle">' . $subtitle . '</div>';
    }
    
    $html .= '</div>
        </div>';
    
    if (!empty($actions)) {
        $html .= '<div class="section-actions">';
        foreach ($actions as $action) {
            $html .= '<a href="' . $action['url'] . '" class="btn ' . $action['class'] . '">
                <i class="fas fa-' . $action['icon'] . ' me-1"></i>' . $action['text'] . '
            </a>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗務前点呼 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-bg: #f8f9fa;
        }

        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* 統一ヘッダースタイル */
        .unified-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .header-title {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 600;
        }

        .header-subtitle {
            margin: 0.5rem 0;
        }

        .header-description {
            margin: 0;
            opacity: 0.9;
        }

        /* セクションヘッダー */
        .section-header {
            background: white;
            border-radius: 15px 15px 0 0;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
        }

        .section-title-group {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .section-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color) 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .section-title {
            margin: 0;
            color: #2c3e50;
            font-weight: 600;
        }

        .section-subtitle {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .section-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* カードスタイル */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
            margin-bottom: 2rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* チェック項目スタイル */
        .check-item-clickable {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .check-item-clickable:hover {
            background-color: #e3f2fd !important;
            border-color: #2196f3 !important;
        }

        .check-item-clickable.checked {
            background-color: #e8f5e8 !important;
            border-color: var(--success-color) !important;
        }

        .form-check-input:checked {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }

        /* アルコール入力 */
        .alcohol-input {
            max-width: 150px;
        }

        /* ボタンスタイル */
        .btn-save {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }

        .btn-edit {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            color: white;
        }

        /* 次ステップ案内 */
        .next-step-alert {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
            border: none;
            color: white;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
        }

        .next-step-alert h5 {
            color: white;
            margin-bottom: 1rem;
        }

        .next-step-alert .btn {
            background: white;
            color: var(--success-color);
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .next-step-alert .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* 必須マーク */
        .required-mark {
            color: var(--danger-color);
        }

        /* レスポンシブ対応 */
        @media (max-width: 768px) {
            .unified-header {
                padding: 1.5rem 0;
            }

            .header-title {
                font-size: 1.5rem;
            }

            .section-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .section-actions {
                justify-content: center;
            }

            .header-actions {
                margin-top: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- 統一ヘッダー -->
    <?= renderPageHeader($page_config) ?>

    <div class="container mt-4">
        <!-- アラート -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- 点呼完了時の次ステップ案内 -->
        <?php if ($existing_call && $existing_call['is_completed']): ?>
        <div class="alert next-step-alert">
            <h5><i class="fas fa-check-circle me-2"></i>乗務前点呼が完了しました</h5>
            <p class="mb-3">次は出庫処理を行ってください。出庫処理では、出庫時刻・天候・メーター値を記録します。</p>
            <a href="departure.php?driver_id=<?= $existing_call['driver_id'] ?>" class="btn btn-lg">
                <i class="fas fa-arrow-right me-2"></i>出庫処理へ進む
            </a>
        </div>
        <?php endif; ?>

        <form method="POST" id="predutyForm">
            <!-- 基本情報セクション -->
            <?= renderSectionHeader('info-circle', '基本情報', '運転者・点呼者・時刻の設定') ?>
            
            <div class="card">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">運転者 <span class="required-mark">*</span></label>
                            <select class="form-select" name="driver_id" required <?= $is_edit_mode ? 'disabled' : '' ?>>
                                <option value="">選択してください</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" 
                                    <?= (($existing_call && $existing_call['driver_id'] == $driver['id']) || 
                                         (!$existing_call && $default_driver_id == $driver['id'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($is_edit_mode): ?>
                            <input type="hidden" name="driver_id" value="<?= $existing_call['driver_id'] ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">点呼時刻 <span class="required-mark">*</span></label>
                            <input type="time" class="form-control" name="call_time" 
                                   value="<?= $existing_call ? $existing_call['call_time'] : $current_time ?>" 
                                   required <?= $is_edit_mode ? 'readonly' : '' ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">点呼者 <span class="required-mark">*</span></label>
                            <select class="form-select" name="caller_name" required <?= $is_edit_mode ? 'disabled' : '' ?>>
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
                    'class' => 'btn btn-success btn-sm'
                ];
                $check_actions[] = [
                    'icon' => 'times',
                    'text' => '全て解除',
                    'url' => 'javascript:uncheckAll()',
                    'class' => 'btn btn-warning btn-sm'
                ];
            }
            echo renderSectionHeader('tasks', '確認事項', '16項目', $check_actions);
            ?>

            <div class="card">
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
                                 <?= $is_edit_mode ? '' : 'onclick="toggleCheck(\'' . $key . '\')"' ?>>
                                <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>"
                                       <?= ($existing_call && $existing_call[$key]) ? 'checked' : '' ?>
                                       <?= $is_edit_mode ? 'disabled' : '' ?>>
                                <label class="form-check-label w-100" for="<?= $key ?>">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- アルコールチェックセクション -->
            <?= renderSectionHeader('wine-bottle', 'アルコールチェック', '法定義務：0.000mg/L基準') ?>
            
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <label class="form-label mb-0">測定値 <span class="required-mark">*</span></label>
                        </div>
                        <div class="col-auto">
                            <input type="number" class="form-control alcohol-input" name="alcohol_check_value" 
                                   step="0.001" min="0" max="1" 
                                   value="<?= $existing_call ? $existing_call['alcohol_check_value'] : '0.000' ?>" 
                                   required <?= $is_edit_mode ? 'readonly' : '' ?>>
                        </div>
                        <div class="col-auto">
                            <span class="text-muted">mg/L</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 備考セクション -->
            <?= renderSectionHeader('comment', '備考', '特記事項があれば記入') ?>
            
            <div class="card">
                <div class="card-body">
                    <textarea class="form-control" name="remarks" rows="3" 
                              placeholder="特記事項があれば記入してください"
                              <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_call ? htmlspecialchars($existing_call['remarks']) : '' ?></textarea>
                </div>
            </div>

            <!-- 保存・修正ボタン -->
            <div class="text-center mb-4">
                <?php if ($is_edit_mode): ?>
                <button type="button" class="btn btn-edit btn-lg me-3" onclick="enableEdit()">
                    <i class="fas fa-edit me-2"></i>修正する
                </button>
                <button type="submit" class="btn btn-save btn-lg" id="saveBtn" style="display: none;">
                    <i class="fas fa-save me-2"></i>更新する
                </button>
                <?php else: ?>
                <button type="submit" class="btn btn-save btn-lg">
                    <i class="fas fa-save me-2"></i>登録する
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 修正モードの有効化
        function enableEdit() {
            // フォーム要素を編集可能にする
            document.querySelector('select[name="driver_id"]').disabled = false;
            document.querySelector('input[name="call_time"]').readOnly = false;
            document.querySelector('select[name="caller_name"]').disabled = false;
            document.getElementById('other_caller').readOnly = false;
            document.querySelector('input[name="alcohol_check_value"]').readOnly = false;
            document.querySelector('textarea[name="remarks"]').readOnly = false;

            // チェックボックスを有効化
            const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.disabled = false;
            });

            // チェック項目をクリック可能にする
            const checkItems = document.querySelectorAll('.form-check');
            checkItems.forEach(function(item) {
                const parentDiv = item.closest('div');
                parentDiv.classList.add('check-item-clickable');
                parentDiv.onclick = function() {
                    const checkbox = item.querySelector('input[type="checkbox"]');
                    toggleCheck(checkbox.id);
                };
            });

            // ボタンの表示切り替え
            document.querySelector('.btn-edit').style.display = 'none';
            document.getElementById('saveBtn').style.display = 'inline-block';

            // 一括操作ボタンを追加
            addBulkActionButtons();
        }

        // 一括操作ボタンの追加
        function addBulkActionButtons() {
            const sectionHeader = document.querySelector('.section-actions');
            if (sectionHeader && sectionHeader.children.length === 0) {
                sectionHeader.innerHTML = `
                    <a href="javascript:checkAll()" class="btn btn-success btn-sm">
                        <i class="fas fa-check-double me-1"></i>全てチェック
                    </a>
                    <a href="javascript:uncheckAll()" class="btn btn-warning btn-sm">
                        <i class="fas fa-times me-1"></i>全て解除
                    </a>
                `;
            }
        }

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
            const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
            checkboxes.forEach(function(checkbox) {
                if (!checkbox.disabled) {
                    checkbox.checked = true;
                    const container = checkbox.closest('.form-check').parentElement;
                    if (container) {
                        container.classList.add('bg-success', 'bg-opacity-10', 'border-success');
                    }
                }
            });
        }

        // 全て解除
        function uncheckAll() {
            const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
            checkboxes.forEach(function(checkbox) {
                if (!checkbox.disabled) {
                    checkbox.checked = false;
                    const container = checkbox.closest('.form-check').parentElement;
                    if (container) {
                        container.classList.remove('bg-success', 'bg-opacity-10', 'border-success');
                    }
                }
            });
        }

        // チェック項目のクリック処理
        function toggleCheck(itemId) {
            const checkbox = document.getElementById(itemId);
            if (checkbox.disabled) return;
            
            const container = checkbox.closest('.form-check').parentElement;

            checkbox.checked = !checkbox.checked;

            if (checkbox.checked) {
                container.classList.add('bg-success', 'bg-opacity-10', 'border-success');
            } else {
                container.classList.remove('bg-success', 'bg-opacity-10', 'border-success');
            }
        }

        // 初期化処理
        document.addEventListener('DOMContentLoaded', function() {
            // 既存チェック項目のスタイル適用
            const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
            checkboxes.forEach(function(checkbox) {
                const container = checkbox.closest('.form-check').parentElement;
                if (checkbox.checked && container) {
                    container.classList.add('bg-success', 'bg-opacity-10', 'border-success');
                }
            });

            // 点呼者選択の初期設定
            const callerSelect = document.querySelector('select[name="caller_name"]');
            if (callerSelect) {
                callerSelect.addEventListener('change', toggleCallerInput);
                toggleCallerInput(); // 初期表示
            }

            // 運転者変更時の既存記録チェック
            const driverSelect = document.querySelector('select[name="driver_id"]');
            if (driverSelect && !driverSelect.disabled) {
                driverSelect.addEventListener('change', function() {
                    if (this.value) {
                        // 既存記録がある場合は確認
                        const today = new Date().toISOString().split('T')[0];
                        window.location.href = `pre_duty_call.php?driver_id=${this.value}`;
                    }
                });
            }
        });

        // フォーム送信前の確認
        document.getElementById('predutyForm').addEventListener('submit', function(e) {
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

            // アルコール測定値の確認
            const alcoholValue = parseFloat(document.querySelector('input[name="alcohol_check_value"]').value);
            if (isNaN(alcoholValue) || alcoholValue < 0 || alcoholValue > 1) {
                e.preventDefault();
                alert('アルコール測定値は0.000-1.000の範囲で入力してください。');
                return;
            }

            // 基準値超過時の警告
            if (alcoholValue > 0.000) {
                if (!confirm(`アルコール測定値が${alcoholValue}mg/Lです。基準値（0.000mg/L）を超えています。\n本当に記録しますか？`)) {
                    e.preventDefault();
                    return;
                }
            }

            // 必須チェック項目の確認
            const requiredChecks = ['health_check', 'pre_inspection_check', 'license_check'];
            const uncheckedRequired = requiredChecks.filter(checkId => 
                !document.getElementById(checkId).checked
            );

            if (uncheckedRequired.length > 0) {
                const items = uncheckedRequired.map(id => {
                    const labels = {
                        'health_check': '健康状態',
                        'pre_inspection_check': '運行前点検',
                        'license_check': '免許証'
                    };
                    return labels[id];
                }).join('、');
                
                if (!confirm(`重要項目「${items}」が未チェックです。\nこのまま保存しますか？`)) {
                    e.preventDefault();
                    return;
                }
            }

            // 保存中の表示
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>保存中...';

            // 3秒後に元に戻す（エラー時対応）
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 3000);
        });

        // アルコール値入力時のリアルタイム検証
        document.addEventListener('DOMContentLoaded', function() {
            const alcoholInput = document.querySelector('input[name="alcohol_check_value"]');
            if (alcoholInput) {
                alcoholInput.addEventListener('input', function() {
                    const value = parseFloat(this.value);
                    const container = this.closest('.card-body');
                    
                    // 既存の警告を削除
                    const existingWarning = container.querySelector('.alcohol-warning');
                    if (existingWarning) {
                        existingWarning.remove();
                    }

                    if (!isNaN(value) && value > 0.000) {
                        const warning = document.createElement('div');
                        warning.className = 'alert alert-warning mt-2 alcohol-warning';
                        warning.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>基準値（0.000mg/L）を超えています。';
                        container.appendChild(warning);
                    }
                });
            }
        });
    </script>
</body>
</html>
