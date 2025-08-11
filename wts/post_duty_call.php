<?php
session_start();
require_once 'config/database.php';

// セッション確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("データベース接続エラー");
}

// 🆕 自動フロー対応パラメータの処理
$auto_flow_data = null;
if (isset($_GET['auto_flow']) && $_GET['auto_flow'] === '1') {
    $auto_flow_data = [
        'from_page' => $_GET['from'] ?? '',
        'driver_id' => $_GET['driver_id'] ?? '',
        'vehicle_id' => $_GET['vehicle_id'] ?? '',
        'duty_date' => $_GET['duty_date'] ?? date('Y-m-d')
    ];
    
    // 入庫完了を前提とした初期値設定
    $initial_values = [
        'driver_id' => $auto_flow_data['driver_id'],
        'vehicle_id' => $auto_flow_data['vehicle_id'],
        'duty_date' => $auto_flow_data['duty_date'],
        'call_time' => date('H:i')  // 現在時刻を自動設定
    ];
}

// ユーザー情報取得（運転者のみ）- 新権限システム対応
function getDrivers($pdo) {
    // is_driver = TRUE のユーザーのみ取得
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 点呼者取得（新権限システム対応）
function getCallers($pdo) {
    // is_caller = TRUE のユーザーのみ取得
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_caller = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 車両情報取得
function getVehicles($pdo) {
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 🆕 乗務前点呼取得（関連付け用）
function getPreDutyCalls($pdo, $date = null) {
    if (!$date) $date = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT p.id, p.driver_id, p.vehicle_id, p.call_time,
               u.name as driver_name, v.vehicle_number
        FROM pre_duty_calls p
        JOIN users u ON p.driver_id = u.id
        JOIN vehicles v ON p.vehicle_id = v.id
        WHERE p.call_date = ?
        ORDER BY p.call_time DESC
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

$drivers = getDrivers($pdo);
$callers = getCallers($pdo);
$vehicles = getVehicles($pdo);
$pre_duty_calls = getPreDutyCalls($pdo, $auto_flow_data['duty_date'] ?? date('Y-m-d'));

// フォーム処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $driver_id = $_POST['driver_id'];
        $caller_id = $_POST['caller_id'];
        $vehicle_id = $_POST['vehicle_id'];
        $call_date = $_POST['call_date'];
        $call_time = $_POST['call_time'];
        $pre_duty_call_id = $_POST['pre_duty_call_id'] ?? null;

        // 確認事項（7項目）
        $items = [
            'health_condition' => $_POST['health_condition'] ?? 0,
            'driving_ability' => $_POST['driving_ability'] ?? 0, 
            'vehicle_condition' => $_POST['vehicle_condition'] ?? 0,
            'accident_report' => $_POST['accident_report'] ?? 0,
            'route_report' => $_POST['route_report'] ?? 0,
            'equipment_check' => $_POST['equipment_check'] ?? 0,
            'duty_completion' => $_POST['duty_completion'] ?? 0
        ];

        // アルコール検査結果
        $alcohol_level = (float)$_POST['alcohol_level'];
        $alcohol_result = ($alcohol_level == 0.000) ? '検出されず' : '検出';

        // 特記事項
        $remarks = $_POST['remarks'] ?? '';

        // 乗務後点呼記録保存
        $stmt = $pdo->prepare("
            INSERT INTO post_duty_calls 
            (driver_id, caller_id, vehicle_id, call_date, call_time, pre_duty_call_id,
             health_condition, driving_ability, vehicle_condition, accident_report, 
             route_report, equipment_check, duty_completion,
             alcohol_level, alcohol_result, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $driver_id, $caller_id, $vehicle_id, $call_date, $call_time, $pre_duty_call_id,
            $items['health_condition'], $items['driving_ability'], $items['vehicle_condition'],
            $items['accident_report'], $items['route_report'], $items['equipment_check'],
            $items['duty_completion'], $alcohol_level, $alcohol_result, $remarks
        ]);

        $success_message = "乗務後点呼を記録しました。";
        
        // リダイレクトしてフォーム再送信を防ぐ
        header("Location: post_duty_call.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $error_message = "エラーが発生しました: " . $e->getMessage();
        error_log("Post duty call error: " . $e->getMessage());
    }
}

// 成功メッセージの表示
$success_message = isset($_GET['success']) ? "乗務後点呼を記録しました。" : null;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗務後点呼 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Noto Sans JP', sans-serif; background-color: #f8f9fa; }
        .main-container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .card { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 10px; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border-radius: 10px 10px 0 0 !important; }
        .btn-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); border: none; }
        .btn-success:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .form-control:focus { border-color: #28a745; box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25); }
        .alert { border-radius: 10px; }
        .check-item { background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 10px; }
        .check-item:hover { background-color: #e9ecef; }
        .auto-flow-banner { background: linear-gradient(135deg, #17a2b8 0%, #20c997 100%); color: white; padding: 10px 20px; border-radius: 10px; margin-bottom: 20px; }
        .pre-duty-list { max-height: 200px; overflow-y: auto; }
        .pre-duty-item { cursor: pointer; transition: all 0.3s; }
        .pre-duty-item:hover { background-color: #e3f2fd; transform: translateX(5px); }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 🆕 自動フローバナー -->
        <?php if ($auto_flow_data): ?>
        <div class="auto-flow-banner">
            <i class="fas fa-route"></i> 
            <strong>入庫処理からの連続フロー</strong> - 
            <?= $auto_flow_data['from_page'] === 'arrival' ? '入庫処理完了後' : '自動遷移' ?>の乗務後点呼
        </div>
        <?php endif; ?>

        <!-- ヘッダー -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-clipboard-check text-success"></i> 乗務後点呼</h1>
            <div>
                <a href="dashboard.php" class="btn btn-outline-primary">
                    <i class="fas fa-home"></i> ダッシュボード
                </a>
                <a href="logout.php" class="btn btn-outline-secondary">
                    <i class="fas fa-sign-out-alt"></i> ログアウト
                </a>
            </div>
        </div>

        <!-- 成功・エラーメッセージ -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                
                <!-- 🆕 乗務完了後のアクション -->
                <div class="mt-3">
                    <a href="dashboard.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-home"></i> ダッシュボードへ
                    </a>
                    <a href="cash_management.php" class="btn btn-warning btn-sm ms-2">
                        <i class="fas fa-money-bill-wave"></i> 集金管理へ
                    </a>
                </div>
                
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 🆕 乗務前点呼一覧（関連付け用） -->
        <?php if (!empty($pre_duty_calls)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-link"></i> 今日の乗務前点呼（関連付け用）
            </div>
            <div class="card-body p-0">
                <div class="pre-duty-list">
                    <?php foreach ($pre_duty_calls as $pre_call): ?>
                    <div class="pre-duty-item p-3 border-bottom" onclick="selectPreDutyCall(<?= $pre_call->id ?>, <?= $pre_call->driver_id ?>, <?= $pre_call->vehicle_id ?>, '<?= htmlspecialchars($pre_call->driver_name) ?>', '<?= htmlspecialchars($pre_call->vehicle_number) ?>')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($pre_call->driver_name) ?></strong> - 
                                <span class="text-success"><?= htmlspecialchars($pre_call->vehicle_number) ?></span>
                            </div>
                            <div class="text-end">
                                <div class="text-muted">乗務前点呼時刻: <?= $pre_call->call_time ?></div>
                                <small class="text-success">クリックで自動入力</small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 乗務後点呼フォーム -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-edit"></i> 乗務後点呼記録
            </div>
            <div class="card-body">
                <form method="POST" id="postDutyForm">
                    <input type="hidden" id="pre_duty_call_id" name="pre_duty_call_id" value="">
                    
                    <div class="row">
                        <!-- 運転者選択 -->
                        <div class="col-md-4 mb-3">
                            <label for="driver_id" class="form-label">運転者 <span class="text-danger">*</span></label>
                            <select class="form-select" id="driver_id" name="driver_id" required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver->id ?>" 
                                        <?= ($auto_flow_data && $driver->id == $auto_flow_data['driver_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($driver->name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 点呼者選択 -->
                        <div class="col-md-4 mb-3">
                            <label for="caller_id" class="form-label">点呼者 <span class="text-danger">*</span></label>
                            <select class="form-select" id="caller_id" name="caller_id" required>
                                <option value="">点呼者を選択</option>
                                <?php foreach ($callers as $caller): ?>
                                    <option value="<?= $caller->id ?>"><?= htmlspecialchars($caller->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 車両選択 -->
                        <div class="col-md-4 mb-3">
                            <label for="vehicle_id" class="form-label">車両 <span class="text-danger">*</span></label>
                            <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                <option value="">車両を選択</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle->id ?>"
                                        <?= ($auto_flow_data && $vehicle->id == $auto_flow_data['vehicle_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($vehicle->vehicle_number) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <!-- 点呼日 -->
                        <div class="col-md-6 mb-3">
                            <label for="call_date" class="form-label">点呼日 <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="call_date" name="call_date" 
                                   value="<?= $auto_flow_data['duty_date'] ?? date('Y-m-d') ?>" required>
                        </div>

                        <!-- 点呼時刻 -->
                        <div class="col-md-6 mb-3">
                            <label for="call_time" class="form-label">点呼時刻 <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="call_time" name="call_time" 
                                   value="<?= date('H:i') ?>" required>
                        </div>
                    </div>

                    <!-- 確認事項（7項目） -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-tasks"></i> 確認事項（7項目）</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- 1. 健康状態 -->
                                <div class="col-md-6 mb-3">
                                    <div class="check-item">
                                        <label class="form-label"><strong>1. 健康状態の確認</strong></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="health_condition" value="1" id="health_ok" required>
                                            <label class="form-check-label" for="health_ok">
                                                <i class="fas fa-check text-success"></i> 良好
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="health_condition" value="0" id="health_ng">
                                            <label class="form-check-label" for="health_ng">
                                                <i class="fas fa-times text-danger"></i> 不良
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- 2. 運転能力 -->
                                <div class="col-md-6 mb-3">
                                    <div class="check-item">
                                        <label class="form-label"><strong>2. 運転能力の確認</strong></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="driving_ability" value="1" id="driving_ok" required>
                                            <label class="form-check-label" for="driving_ok">
                                                <i class="fas fa-check text-success"></i> 問題なし
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="driving_ability" value="0" id="driving_ng">
                                            <label class="form-check-label" for="driving_ng">
                                                <i class="fas fa-times text-danger"></i> 問題あり
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- 3. 車両状態 -->
                                <div class="col-md-6 mb-3">
                                    <div class="check-item">
                                        <label class="form-label"><strong>3. 車両状態の確認</strong></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="vehicle_condition" value="1" id="vehicle_ok" required>
                                            <label class="form-check-label" for="vehicle_ok">
                                                <i class="fas fa-check text-success"></i> 異常なし
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="vehicle_condition" value="0" id="vehicle_ng">
                                            <label class="form-check-label" for="vehicle_ng">
                                                <i class="fas fa-times text-danger"></i> 異常あり
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- 4. 事故・交通違反 -->
                                <div class="col-md-6 mb-3">
                                    <div class="check-item">
                                        <label class="form-label"><strong>4. 事故・交通違反の報告</strong></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="accident_report" value="1" id="accident_none" required>
                                            <label class="form-check-label" for="accident_none">
                                                <i class="fas fa-check text-success"></i> なし
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="accident_report" value="0" id="accident_occur">
                                            <label class="form-check-label" for="accident_occur">
                                                <i class="fas fa-exclamation-triangle text-warning"></i> あり
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- 5. 運行経路・時間 -->
                                <div class="col-md-6 mb-3">
                                    <div class="check-item">
                                        <label class="form-label"><strong>5. 運行経路・時間の報告</strong></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="route_report" value="1" id="route_ok" required>
                                            <label class="form-check-label" for="route_ok">
                                                <i class="fas fa-check text-success"></i> 適切
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="route_report" value="0" id="route_ng">
                                            <label class="form-check-label" for="route_ng">
                                                <i class="fas fa-times text-danger"></i> 不適切
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- 6. 運転者装着用具 -->
                                <div class="col-md-6 mb-3">
                                    <div class="check-item">
                                        <label class="form-label"><strong>6. 運転者装着用具の確認</strong></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="equipment_check" value="1" id="equipment_ok" required>
                                            <label class="form-check-label" for="equipment_ok">
                                                <i class="fas fa-check text-success"></i> 適切
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="equipment_check" value="0" id="equipment_ng">
                                            <label class="form-check-label" for="equipment_ng">
                                                <i class="fas fa-times text-danger"></i> 不適切
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- 7. 乗務完了確認 -->
                                <div class="col-md-6 mb-3">
                                    <div class="check-item">
                                        <label class="form-label"><strong>7. 乗務完了の確認</strong></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duty_completion" value="1" id="duty_completed" required>
                                            <label class="form-check-label" for="duty_completed">
                                                <i class="fas fa-check text-success"></i> 完了
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="duty_completion" value="0" id="duty_incomplete">
                                            <label class="form-check-label" for="duty_incomplete">
                                                <i class="fas fa-times text-danger"></i> 未完了
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- アルコール検査 -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-wine-bottle"></i> アルコール検査</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="alcohol_level" class="form-label">アルコール濃度 (mg/L) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="alcohol_level" name="alcohol_level" 
                                           step="0.001" min="0" max="1" value="0.000" required>
                                    <small class="form-text text-muted">通常は0.000です</small>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-success" onclick="setAlcoholZero()">
                                        <i class="fas fa-check"></i> 0.000に設定
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 特記事項 -->
                    <div class="mt-4">
                        <label for="remarks" class="form-label">特記事項・その他報告</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="3" 
                                  placeholder="特別な事項や報告事項があれば記載してください"></textarea>
                    </div>

                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-save"></i> 乗務後点呼を記録
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 🆕 乗務前点呼選択時の処理
        function selectPreDutyCall(preDutyCallId, driverId, vehicleId, driverName, vehicleNumber) {
            document.getElementById('pre_duty_call_id').value = preDutyCallId;
            document.getElementById('driver_id').value = driverId;
            document.getElementById('vehicle_id').value = vehicleId;
            
            // 選択されたアイテムをハイライト
            document.querySelectorAll('.pre-duty-item').forEach(item => {
                item.classList.remove('bg-light');
            });
            event.currentTarget.classList.add('bg-light');
            
            // ユーザーに通知
            const notification = document.createElement('div');
            notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; width: 300px;';
            notification.innerHTML = `
                <i class="fas fa-link"></i> 
                <strong>${driverName}</strong> (${vehicleNumber}) の乗務前点呼と関連付けました
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(notification);
            
            // 3秒後に自動削除
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 3000);
        }

        // アルコール濃度を0.000に設定
        function setAlcoholZero() {
            document.getElementById('alcohol_level').value = '0.000';
        }

        // 全て「良好」「問題なし」「なし」「適切」「完了」に一括設定
        function setAllGood() {
            // 7項目すべてをOK(1)に設定
            const items = ['health_condition', 'driving_ability', 'vehicle_condition', 'accident_report', 'route_report', 'equipment_check', 'duty_completion'];
            items.forEach(item => {
                const okRadio = document.querySelector(`input[name="${item}"][value="1"]`);
                if (okRadio) okRadio.checked = true;
            });
            
            // アルコール濃度も0.000に設定
            setAlcoholZero();
        }

        // 現在時刻を自動設定
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                              now.getMinutes().toString().padStart(2, '0');
            document.getElementById('call_time').value = timeString;
            
            // 🆕 自動フローの場合、全項目を良好に設定
            <?php if ($auto_flow_data): ?>
            setTimeout(() => {
                setAllGood();
                
                // 自動設定の通知
                const notification = document.createElement('div');
                notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
                notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; width: 350px;';
                notification.innerHTML = `
                    <i class="fas fa-magic"></i> 
                    <strong>自動設定完了</strong><br>
                    入庫処理からの連続フローにより、確認事項を自動設定しました
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 5000);
            }, 500);
            <?php endif; ?>
        });

        // 一括設定ボタンを動的に追加
        document.addEventListener('DOMContentLoaded', function() {
            const cardHeader = document.querySelector('.card-header h5');
            if (cardHeader) {
                const quickSetBtn = document.createElement('button');
                quickSetBtn.type = 'button';
                quickSetBtn.className = 'btn btn-outline-light btn-sm float-end';
                quickSetBtn.onclick = setAllGood;
                quickSetBtn.innerHTML = '<i class="fas fa-magic"></i> 一括OK設定';
                cardHeader.parentNode.appendChild(quickSetBtn);
            }
        });
    </script>
</body>
</html>
