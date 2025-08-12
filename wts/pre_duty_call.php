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

// 🚀 自動遷移モードの判定
$auto_flow = isset($_GET['auto_flow']) && $_GET['auto_flow'] == '1';
$from_inspection = isset($_GET['from']) && $_GET['from'] == 'inspection';

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

} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $drivers = [];
    $callers = [];
}

// 今日の点呼記録があるかチェック
$existing_call = null;
if ($_GET['driver_id'] ?? null) {
    $driver_id = $_GET['driver_id'];
    $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$driver_id, $today]);
    $existing_call = $stmt->fetch();
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $error_message = '使用可能な車両が見つかりません。';
    } else {
        $vehicle_id = $vehicle_record['id'];

        // 点呼者名の処理
        $caller_name = $_POST['caller_name'];
        if ($caller_name === 'その他') {
            $caller_name = $_POST['other_caller'];
        }

        $alcohol_check_value = $_POST['alcohol_check_value'];

        // 確認事項のチェック
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

            // 🚀 自動遷移モードの場合
            if ($auto_flow) {
                // 日常点検が完了しているかチェック（既存DBに合わせて修正）
                $stmt = $pdo->prepare("
                    SELECT id FROM daily_inspections 
                    WHERE vehicle_id = ? AND inspection_date = ?
                    LIMIT 1
                ");
                $stmt->execute([$vehicle_id, $today]);
                $inspection_completed = $stmt->fetch();

                if ($inspection_completed) {
                    // 日常点検が存在する場合、出庫処理へ自動遷移
                    $redirect_url = "departure.php?auto_flow=1&driver_id={$driver_id}&vehicle_id={$vehicle_id}";
                    header("Location: {$redirect_url}");
                    exit;
                } else {
                    // 日常点検が未実施の場合、日常点検へ遷移
                    $redirect_url = "daily_inspection.php?auto_flow=1&driver_id={$driver_id}&vehicle_id={$vehicle_id}";
                    header("Location: {$redirect_url}");
                    exit;
                }
            }

            // 記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing_call = $stmt->fetch();

        } catch (Exception $e) {
            $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
            error_log("Pre duty call error: " . $e->getMessage());
        }
    }
}

// 今日の日常点検完了状況をチェック（自動遷移用）
$inspection_status = [];
if ($existing_call) {
    $stmt = $pdo->prepare("
        SELECT v.id, v.vehicle_number, 
               CASE WHEN di.id IS NOT NULL THEN 1 ELSE 0 END as inspection_completed
        FROM vehicles v
        LEFT JOIN daily_inspections di ON v.id = di.vehicle_id 
            AND di.inspection_date = ?
        WHERE v.is_active = TRUE
        ORDER BY v.vehicle_number
    ");
    $stmt->execute([$today]);
    $inspection_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗務前点呼<?= $auto_flow ? ' - 連続業務モード' : '' ?> - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        /* 🚀 自動遷移モード用スタイル */
        .auto-flow-mode .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .workflow-indicator {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 0.5rem 1rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .workflow-step {
            display: inline-block;
            margin: 0 0.5rem;
        }

        .workflow-step.completed {
            color: #28a745;
        }

        .workflow-step.current {
            color: #ffc107;
            font-weight: bold;
        }

        .workflow-step.pending {
            color: #6c757d;
        }

        .form-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .form-card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 15px 15px 0 0;
            margin: 0;
        }

        .auto-flow-mode .form-card-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .form-card-body {
            padding: 1.5rem;
        }

        .check-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
        }

        .check-item:hover {
            background: #e3f2fd;
            border-color: #2196f3;
        }

        .check-item.checked {
            background: #e8f5e8;
            border-color: #28a745;
        }

        .form-check-input:checked {
            background-color: #28a745;
            border-color: #28a745;
        }

        .alcohol-input {
            max-width: 150px;
        }

        .btn-save {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }

        /* 🚀 自動遷移専用ボタンスタイル */
        .btn-next-step {
            background: linear-gradient(135deg, #17a2b8 0%, #007bff 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            position: relative;
        }

        .btn-next-step:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            color: white;
        }

        .btn-next-step::after {
            content: '→';
            margin-left: 0.5rem;
            font-weight: bold;
        }

        .next-step-info {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .required-mark {
            color: #dc3545;
        }

        .vehicle-info {
            background: #e8f5e8;
            border: 1px solid #28a745;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        /* スマートフォン用レイアウト修正 */
        @media (max-width: 768px) {
            .form-card-header {
                padding: 0.75rem 1rem;
            }

            /* ヘッダーボタンのスマホ対応 - ヘッダー外に配置 */
            .mobile-buttons {
                display: flex;
                gap: 0.5rem;
                margin-bottom: 1rem;
                justify-content: center;
            }

            .mobile-buttons .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
                flex: 1;
                max-width: 150px;
            }

            /* デスクトップのボタンを非表示 */
            .header-buttons {
                display: none;
            }

            /* タイトル部分はシンプルに */
            .form-card-header-content {
                display: block;
            }

            .form-card-title {
                margin-bottom: 0;
                font-size: 1.1rem;
            }

            .form-card-body {
                padding: 1rem;
            }

            .workflow-indicator {
                font-size: 0.8rem;
                padding: 0.3rem 0.8rem;
            }

            .next-step-info {
                padding: 0.75rem;
                margin: 0.75rem 0;
            }
        }

        /* 極小画面（320px以下）対応 */
        @media (max-width: 320px) {
            .mobile-buttons .btn {
                font-size: 0.8rem;
                padding: 0.5rem 0.8rem;
            }
        }
    </style>
</head>
<body class="<?= $auto_flow ? 'auto-flow-mode' : '' ?>">
<!-- ヘッダー -->
<div class="header">
    <div class="container">
        <div class="row align-items-center">
            <div class="col">
                <h1><i class="fas fa-clipboard-check me-2"></i>乗務前点呼<?= $auto_flow ? ' - 連続業務モード' : '' ?></h1>
                <small><?= date('Y年n月j日 (D)') ?></small>
                
                <?php if ($auto_flow): ?>
                <div class="workflow-indicator">
                    <span class="workflow-step completed">
                        <i class="fas fa-check-circle"></i> 日常点検
                    </span>
                    <i class="fas fa-arrow-right mx-1"></i>
                    <span class="workflow-step current">
                        <i class="fas fa-clipboard-check"></i> 乗務前点呼
                    </span>
                    <i class="fas fa-arrow-right mx-1"></i>
                    <span class="workflow-step pending">
                        <i class="fas fa-car"></i> 出庫処理
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-auto">
                <?php if ($auto_flow): ?>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-times me-1"></i>連続業務中止
                </a>
                <?php else: ?>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>ダッシュボード
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="container mt-4">
    <!-- アラート -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>
        <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    
    <!-- 🚀 自動遷移モード：成功時の次ステップ案内 -->
    <?php if ($auto_flow && $existing_call): ?>
    <div class="next-step-info">
        <h6><i class="fas fa-route me-2"></i>次のステップ</h6>
        <p class="mb-3">乗務前点呼が完了しました。日常点検の状況に応じて次の処理を選択してください。</p>
        
        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
            <?php
            // 日常点検の完了状況をチェック（既存DBに合わせて修正）
            $stmt = $pdo->prepare("
                SELECT id FROM daily_inspections 
                WHERE vehicle_id = (
                    SELECT vehicle_id FROM pre_duty_calls 
                    WHERE driver_id = ? AND call_date = ? 
                    LIMIT 1
                ) AND inspection_date = ?
                LIMIT 1
            ");
            $stmt->execute([$driver_id, $today, $today]);
            $inspection_completed = $stmt->fetch();
            ?>
            
            <?php if ($inspection_completed): ?>
            <a href="departure.php?auto_flow=1&driver_id=<?= $driver_id ?>" class="btn btn-next-step btn-lg">
                <i class="fas fa-car me-2"></i>出庫処理へ進む
            </a>
            <?php else: ?>
            <a href="daily_inspection.php?auto_flow=1&driver_id=<?= $driver_id ?>" class="btn btn-warning btn-lg">
                <i class="fas fa-tools me-2"></i>日常点検へ戻る
            </a>
            <a href="departure.php?auto_flow=1&driver_id=<?= $driver_id ?>" class="btn btn-next-step btn-lg">
                <i class="fas fa-car me-2"></i>点検なしで出庫処理
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- 🚀 自動遷移モード：現在の状況表示 -->
    <?php if ($auto_flow && $from_inspection): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <strong>連続業務モード：</strong>日常点検から続けて乗務前点呼を行います。
        完了後、自動的に出庫処理画面に移動します。
    </div>
    <?php endif; ?>

    <form method="POST" id="predutyForm">
        <!-- 自動遷移モード用の隠しフィールド -->
        <?php if ($auto_flow): ?>
        <input type="hidden" name="auto_flow" value="1">
        <?php endif; ?>
        
        <!-- 基本情報 -->
        <div class="form-card">
            <div class="form-card-header">
                <div class="form-card-header-content">
                    <h5 class="form-card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>基本情報
                        <?php if ($auto_flow): ?>
                        <span class="badge bg-light text-dark ms-2">連続業務モード</span>
                        <?php endif; ?>
                    </h5>
                </div>
            </div>
            <div class="form-card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">運転者 <span class="required-mark">*</span></label>
                        <select class="form-select" name="driver_id" required <?= $auto_flow ? 'onchange="updateAutoFlowVehicle()"' : '' ?>>
                            <option value="">選択してください</option>
                            <?php foreach ($drivers as $driver): ?>
                            <option value="<?= $driver['id'] ?>" 
                                <?= ($existing_call && $existing_call['driver_id'] == $driver['id']) ? 'selected' : '' ?>
                                <?= (isset($_GET['driver_id']) && $_GET['driver_id'] == $driver['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($driver['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">点呼時刻 <span class="required-mark">*</span></label>
                        <input type="time" class="form-control" name="call_time" 
                               value="<?= $existing_call ? $existing_call['call_time'] : $current_time ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">点呼者 <span class="required-mark">*</span></label>
                        <select class="form-select" name="caller_name" required>
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
                               value="<?= ($existing_call && !in_array($existing_call['caller_name'], array_column($callers, 'name')) && $existing_call['caller_name'] != '') ? htmlspecialchars($existing_call['caller_name']) : '' ?>">
                    </div>
                </div>

                <!-- 🚀 自動遷移モード：車両状況表示 -->
                <?php if ($auto_flow && !empty($inspection_status)): ?>
                <div class="vehicle-info">
                    <h6><i class="fas fa-car me-2"></i>車両点検状況</h6>
                    <div class="row">
                        <?php foreach ($inspection_status as $vehicle): ?>
                        <div class="col-md-6 mb-2">
                            <span class="badge bg-<?= $vehicle['inspection_completed'] ? 'success' : 'warning' ?> me-2">
                                <?= $vehicle['vehicle_number'] ?>
                            </span>
                            <?php if ($vehicle['inspection_completed']): ?>
                            <i class="fas fa-check-circle text-success"></i> 点検完了
                            <?php else: ?>
                            <i class="fas fa-exclamation-triangle text-warning"></i> 点検未完了
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 確認事項 -->
        <div class="form-card">
            <div class="form-card-header">
                <div class="form-card-header-content">
                    <h5 class="form-card-title mb-0">
                        <i class="fas fa-tasks me-2"></i>確認事項（15項目）
                    </h5>
                    <div class="header-buttons d-none d-md-flex float-end">
                        <button type="button" class="btn btn-outline-light btn-sm me-2" id="checkAllBtn">
                            <i class="fas fa-check-double me-1"></i>全てチェック
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" id="uncheckAllBtn">
                            <i class="fas fa-times me-1"></i>全て解除
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-card-body">
                <!-- スマホ用ボタン（ヘッダー外に配置） -->
                <div class="mobile-buttons d-md-none">
                    <button type="button" class="btn btn-success" id="checkAllBtnMobile">
                        <i class="fas fa-check-double me-1"></i>全てチェック
                    </button>
                    <button type="button" class="btn btn-warning" id="uncheckAllBtnMobile">
                        <i class="fas fa-times me-1"></i>全て解除
                    </button>
                </div>

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

                <div class="row">
                    <?php foreach ($check_items as $key => $label): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="check-item" onclick="toggleCheck('<?= $key ?>')">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>"
                                       <?= ($existing_call && $existing_call[$key]) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= $key ?>">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- アルコールチェック -->
        <div class="form-card">
            <div class="form-card-header">
                <h5 class="form-card-title mb-0">
                    <i class="fas fa-wine-bottle me-2"></i>アルコールチェック
                </h5>
            </div>
            <div class="form-card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <label class="form-label mb-0">測定値 <span class="required-mark">*</span></label>
                    </div>
                    <div class="col-auto">
                        <input type="number" class="form-control alcohol-input" name="alcohol_check_value" 
                               step="0.001" min="0" max="1" 
                               value="<?= $existing_call ? $existing_call['alcohol_check_value'] : '0.000' ?>" required>
                    </div>
                    <div class="col-auto">
                        <span class="text-muted">mg/L</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 備考 -->
        <div class="form-card">
            <div class="form-card-header">
                <h5 class="form-card-title mb-0">
                    <i class="fas fa-comment me-2"></i>備考
                </h5>
            </div>
            <div class="form-card-body">
                <textarea class="form-control" name="remarks" rows="3" 
                          placeholder="特記事項があれば記入してください"><?= $existing_call ? htmlspecialchars($existing_call['remarks']) : '' ?></textarea>
            </div>
        </div>

        <!-- 保存・遷移ボタン -->
        <div class="text-center mb-4">
            <?php if ($auto_flow): ?>
            <!-- 🚀 自動遷移モード用ボタン -->
            <button type="submit" class="btn btn-next-step btn-lg me-3">
                <i class="fas fa-rocket me-2"></i>
                <?= $existing_call ? '更新して次へ進む' : '登録して次へ進む' ?>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-lg" onclick="exitAutoFlow()">
                <i class="fas fa-pause me-2"></i>連続業務を中止
            </button>
            <?php else: ?>
            <!-- 通常モード用ボタン -->
            <button type="submit" class="btn btn-save btn-lg">
                <i class="fas fa-save me-2"></i>
                <?= $existing_call ? '更新する' : '登録する' ?>
            </button>
            <?php endif; ?>
        </div>
    </form>

    <!-- 🚀 自動遷移モード：クイックアクションボタン -->
    <?php if ($auto_flow): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-lightning-bolt me-2"></i>クイックアクション</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted">朝の定型業務を効率的に進めるためのショートカット</p>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                        <button type="button" class="btn btn-success" onclick="quickComplete()">
                            <i class="fas fa-magic me-2"></i>標準設定で完了
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="showQuickSettings()">
                            <i class="fas fa-cog me-2"></i>設定変更
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 🚀 クイック設定モーダル -->
<?php if ($auto_flow): ?>
<div class="modal fade" id="quickSettingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-magic me-2"></i>標準設定内容
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>自動設定される内容：</strong></p>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">✓ 全15項目の確認事項をチェック</li>
                    <li class="list-group-item">✓ アルコール値: 0.000 mg/L</li>
                    <li class="list-group-item">✓ 点呼者: 現在のログインユーザー</li>
                    <li class="list-group-item">✓ 点呼時刻: 現在時刻</li>
                </ul>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    設定後、自動的に次のステップ（出庫処理）に進みます。
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-success" onclick="executeQuickComplete()">
                    <i class="fas fa-check me-2"></i>この内容で実行
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
    const checkboxes = document.querySelectorAll('.check-item .form-check-input[type="checkbox"]');
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = true;
        const container = checkbox.closest('.check-item');
        if (container) {
            container.classList.add('checked');
        }
    });
}

// 全て解除
function uncheckAll() {
    const checkboxes = document.querySelectorAll('.check-item .form-check-input[type="checkbox"]');
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = false;
        const container = checkbox.closest('.check-item');
        if (container) {
            container.classList.remove('checked');
        }
    });
}

// チェック項目のクリック処理
function toggleCheck(itemId) {
    const checkbox = document.getElementById(itemId);
    const container = checkbox.closest('.check-item');

    checkbox.checked = !checkbox.checked;

    if (checkbox.checked) {
        container.classList.add('checked');
    } else {
        container.classList.remove('checked');
    }
}

// 🚀 自動遷移モード：車両情報更新
function updateAutoFlowVehicle() {
    const driverId = document.querySelector('select[name="driver_id"]').value;
    if (driverId) {
        // 車両情報の更新処理（必要に応じて実装）
        console.log('Driver changed to:', driverId);
    }
}

// 🚀 自動遷移モード：連続業務中止
function exitAutoFlow() {
    if (confirm('連続業務モードを中止してダッシュボードに戻りますか？\n入力中のデータは保存されません。')) {
        window.location.href = 'dashboard.php';
    }
}

// 🚀 クイック完了機能
function quickComplete() {
    document.getElementById('quickSettingsModal').style.display = 'none';
    const modal = new bootstrap.Modal(document.getElementById('quickSettingsModal'));
    modal.show();
}

function showQuickSettings() {
    const modal = new bootstrap.Modal(document.getElementById('quickSettingsModal'));
    modal.show();
}

function executeQuickComplete() {
    // 全項目をチェック
    checkAll();
    
    // アルコール値を0.000に設定
    document.querySelector('input[name="alcohol_check_value"]').value = '0.000';
    
    // 点呼者を現在のユーザーに設定（最初の選択肢を選択）
    const callerSelect = document.querySelector('select[name="caller_name"]');
    if (callerSelect.options.length > 1) {
        callerSelect.selectedIndex = 1; // 「選択してください」の次を選択
    }
    
    // 現在時刻を設定
    const now = new Date();
    const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                      now.getMinutes().toString().padStart(2, '0');
    document.querySelector('input[name="call_time"]').value = timeString;
    
    // モーダルを閉じる
    const modal = bootstrap.Modal.getInstance(document.getElementById('quickSettingsModal'));
    modal.hide();
    
    // 確認メッセージ
    setTimeout(() => {
        alert('標準設定を適用しました。フォームを確認後、「登録して次へ進む」をクリックしてください。');
    }, 500);
}

// 初期化処理
document.addEventListener('DOMContentLoaded', function() {
    // 既存チェック項目のスタイル適用
    const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
    checkboxes.forEach(function(checkbox) {
        const container = checkbox.closest('.check-item');
        if (checkbox.checked && container) {
            container.classList.add('checked');
        }
    });

    // 点呼者選択の初期設定
    const callerSelect = document.querySelector('select[name="caller_name"]');
    if (callerSelect) {
        callerSelect.addEventListener('change', toggleCallerInput);
        toggleCallerInput(); // 初期表示
    }

    // 一括チェックボタンのイベント設定（デスクトップ・モバイル両対応）
    const checkAllBtn = document.getElementById('checkAllBtn');
    const uncheckAllBtn = document.getElementById('uncheckAllBtn');
    const checkAllBtnMobile = document.getElementById('checkAllBtnMobile');
    const uncheckAllBtnMobile = document.getElementById('uncheckAllBtnMobile');

    if (checkAllBtn) {
        checkAllBtn.addEventListener('click', checkAll);
    }

    if (uncheckAllBtn) {
        uncheckAllBtn.addEventListener('click', uncheckAll);
    }

    if (checkAllBtnMobile) {
        checkAllBtnMobile.addEventListener('click', checkAll);
    }

    if (uncheckAllBtnMobile) {
        uncheckAllBtnMobile.addEventListener('click', uncheckAll);
    }

    // 🚀 自動遷移モード：成功メッセージの自動消去
    const autoFlow = <?= $auto_flow ? 'true' : 'false' ?>;
    if (autoFlow) {
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.opacity = '0.7';
            }, 3000);
        }
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

    // 🚀 自動遷移モード：確認事項のチェック（緩和）
    const autoFlow = <?= $auto_flow ? 'true' : 'false' ?>;
    if (!autoFlow) {
        // 通常モードでは必須チェック項目の確認
        const requiredChecks = ['health_check', 'pre_inspection_check', 'license_check'];
        let allChecked = true;

        requiredChecks.forEach(function(checkId) {
            if (!document.getElementById(checkId).checked) {
                allChecked = false;
            }
        });

        if (!allChecked) {
            if (!confirm('必須項目が未チェックです。このまま保存しますか？')) {
                e.preventDefault();
                return;
            }
        }
    }

    // 🚀 自動遷移モード：送信時のローディング表示
    if (autoFlow) {
        const submitBtn = e.target.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>次のステップに移動中...';
            submitBtn.disabled = true;
        }
    }
});

// 🚀 自動遷移モード：ページ離脱時の確認
window.addEventListener('beforeunload', function(e) {
    const autoFlow = <?= $auto_flow ? 'true' : 'false' ?>;
    const hasUnsavedData = checkUnsavedData();
    
    if (autoFlow && hasUnsavedData) {
        e.preventDefault();
        e.returnValue = '連続業務モードを中止しますか？入力中のデータが失われます。';
        return e.returnValue;
    }
});

function checkUnsavedData() {
    // 基本的なフィールドチェック
    const driverId = document.querySelector('select[name="driver_id"]').value;
    const checkedBoxes = document.querySelectorAll('input[type="checkbox"]:checked').length;
    
    return driverId && checkedBoxes > 0;
}

// 🚀 自動遷移モード：キーボードショートカット
document.addEventListener('keydown', function(e) {
    const autoFlow = <?= $auto_flow ? 'true' : 'false' ?>;
    
    if (autoFlow && e.ctrlKey) {
        switch(e.key) {
            case 'Enter':
                e.preventDefault();
                document.getElementById('predutyForm').dispatchEvent(new Event('submit'));
                break;
            case 'a':
                e.preventDefault();
                checkAll();
                break;
            case 'q':
                e.preventDefault();
                quickComplete();
                break;
        }
    }
});
</script>
</body>
</html>
