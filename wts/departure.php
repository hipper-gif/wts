<?php
session_start();

// データベース接続
require_once 'config/database.php';
require_once 'includes/unified-header.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['permission_level'] ?? 'User';

// 今日の日付
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';
$is_edit_mode = isset($_GET['edit']) && $_GET['edit'] === 'true';
$edit_record_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 前提条件チェック関数
function checkPrerequisites($driver_id, $date) {
    global $pdo;
    
    // 日常点検完了チェック
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE driver_id = ? AND inspection_date = ? AND is_completed = 1");
    $stmt->execute([$driver_id, $date]);
    $daily_inspection_completed = $stmt->fetchColumn() > 0;
    
    // 乗務前点呼完了チェック
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? AND is_completed = 1");
    $stmt->execute([$driver_id, $date]);
    $pre_duty_call_completed = $stmt->fetchColumn() > 0;
    
    return [
        'daily_inspection' => $daily_inspection_completed,
        'pre_duty_call' => $pre_duty_call_completed,
        'can_proceed' => $daily_inspection_completed && $pre_duty_call_completed
    ];
}

// 既存記録取得関数
function getExistingDeparture($driver_id, $vehicle_id, $date) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT dr.*, u.name as driver_name, v.vehicle_number, v.vehicle_name 
        FROM departure_records dr 
        JOIN users u ON dr.driver_id = u.id 
        JOIN vehicles v ON dr.vehicle_id = v.id 
        WHERE dr.driver_id = ? AND dr.vehicle_id = ? AND dr.departure_date = ?
    ");
    $stmt->execute([$driver_id, $vehicle_id, $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ID指定での記録取得
function getDepartureById($id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT dr.*, u.name as driver_name, v.vehicle_number, v.vehicle_name 
        FROM departure_records dr 
        JOIN users u ON dr.driver_id = u.id 
        JOIN vehicles v ON dr.vehicle_id = v.id 
        WHERE dr.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 修正対象記録の取得
$edit_record = null;
if ($is_edit_mode && $edit_record_id > 0) {
    $edit_record = getDepartureById($edit_record_id);
    if (!$edit_record) {
        $error_message = "指定された出庫記録が見つかりません。";
        $is_edit_mode = false;
    }
}

// POSTデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $driver_id = (int)$_POST['driver_id'];
        $vehicle_id = (int)$_POST['vehicle_id'];
        $departure_date = $_POST['departure_date'];
        $departure_time = $_POST['departure_time'];
        $weather = $_POST['weather'];
        $departure_mileage = (int)$_POST['departure_mileage'];
        
        // 前提条件チェック
        $prerequisites = checkPrerequisites($driver_id, $departure_date);
        
        if ($is_edit_mode && $edit_record_id > 0) {
            // 更新処理
            $old_record = getDepartureById($edit_record_id);
            
            $update_sql = "UPDATE departure_records SET 
                departure_time = ?, weather = ?, departure_mileage = ?, 
                pre_duty_completed = ?, daily_inspection_completed = ?, 
                updated_at = NOW() 
                WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $departure_time, $weather, $departure_mileage,
                $prerequisites['pre_duty_call'] ? 1 : 0,
                $prerequisites['daily_inspection'] ? 1 : 0,
                $edit_record_id
            ]);
            
            // 車両の走行距離を更新
            $update_vehicle_sql = "UPDATE vehicles SET current_mileage = ?, updated_at = NOW() WHERE id = ?";
            $update_vehicle_stmt = $pdo->prepare($update_vehicle_sql);
            $update_vehicle_stmt->execute([$departure_mileage, $vehicle_id]);
            
            $success_message = '出庫記録を修正しました。';
            
            // 修正前後の比較情報
            $_SESSION['modification_info'] = [
                'old' => $old_record,
                'new' => [
                    'departure_time' => $departure_time,
                    'weather' => $weather,
                    'departure_mileage' => $departure_mileage
                ]
            ];
            
            $is_edit_mode = false;
            $edit_record_id = 0;
            
        } else {
            // 新規登録
            // 重複チェック（同日同車両の出庫記録）
            $duplicate_sql = "SELECT COUNT(*) FROM departure_records WHERE vehicle_id = ? AND departure_date = ?";
            $duplicate_stmt = $pdo->prepare($duplicate_sql);
            $duplicate_stmt->execute([$vehicle_id, $departure_date]);
            
            if ($duplicate_stmt->fetchColumn() > 0) {
                throw new Exception('本日、この車両は既に出庫記録が登録されています。修正する場合は修正ボタンをクリックしてください。');
            }
            
            // データ保存
            $insert_sql = "INSERT INTO departure_records 
                (driver_id, vehicle_id, departure_date, departure_time, weather, departure_mileage, 
                 pre_duty_completed, daily_inspection_completed, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $driver_id, $vehicle_id, $departure_date, $departure_time, $weather, $departure_mileage,
                $prerequisites['pre_duty_call'] ? 1 : 0,
                $prerequisites['daily_inspection'] ? 1 : 0
            ]);
            
            // 車両の走行距離を更新
            $update_sql = "UPDATE vehicles SET current_mileage = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$departure_mileage, $vehicle_id]);
            
            $success_message = '出庫処理が完了しました。';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者取得
$stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
$stmt->execute();
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 車両一覧取得
$vehicles_sql = "SELECT id, vehicle_number, vehicle_name, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute();
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

// 本日の出庫記録一覧取得
$today_departures_sql = "SELECT dr.*, u.name as driver_name, v.vehicle_number, v.vehicle_name 
    FROM departure_records dr 
    JOIN users u ON dr.driver_id = u.id 
    JOIN vehicles v ON dr.vehicle_id = v.id 
    WHERE dr.departure_date = ? 
    ORDER BY dr.departure_time DESC";
$today_departures_stmt = $pdo->prepare($today_departures_sql);
$today_departures_stmt->execute([$today]);
$today_departures = $today_departures_stmt->fetchAll(PDO::FETCH_ASSOC);

// 天候選択肢
$weather_options = ['晴', '曇', '雨', '雪', '霧'];

// ページ設定
$page_config = [
    'title' => '出庫処理',
    'icon' => 'sign-out-alt',
    'category' => '日次業務',
    'description' => '出庫時刻・天候・メーター記録'
];

// 統一ヘッダー出力（正しい引数の順序）
echo renderCompleteHTMLHead($page_config['title'], [
    'description' => $page_config['description'],
    'additional_css' => ['css/ui-unified-v3.css']
]);
echo renderSystemHeader($user_name, $user_role, 'departure', true);
echo renderPageHeader($page_config['icon'], $page_config['title'], $page_config['description'], 'daily');
?>

<main class="container-fluid mt-4">
    <div class="row">
        <!-- メイン入力フォーム -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">
                        <i class="fas fa-sign-out-alt me-2"></i>
                        <?php echo $is_edit_mode ? '出庫記録修正' : '出庫処理'; ?>
                    </h4>
                    <small><?php echo $is_edit_mode ? '既存の出庫記録を修正します' : '素早く簡単に出庫登録'; ?></small>
                </div>
                <div class="card-body">
                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                            
                            <?php if (isset($_SESSION['modification_info'])): ?>
                                <div class="mt-3">
                                    <h6>修正内容:</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>修正前:</strong><br>
                                            時刻: <?php echo substr($_SESSION['modification_info']['old']['departure_time'], 0, 5); ?><br>
                                            天候: <?php echo htmlspecialchars($_SESSION['modification_info']['old']['weather']); ?><br>
                                            メーター: <?php echo number_format($_SESSION['modification_info']['old']['departure_mileage']); ?>km
                                        </div>
                                        <div class="col-md-6">
                                            <strong>修正後:</strong><br>
                                            時刻: <?php echo substr($_SESSION['modification_info']['new']['departure_time'], 0, 5); ?><br>
                                            天候: <?php echo htmlspecialchars($_SESSION['modification_info']['new']['weather']); ?><br>
                                            メーター: <?php echo number_format($_SESSION['modification_info']['new']['departure_mileage']); ?>km
                                        </div>
                                    </div>
                                </div>
                                <?php unset($_SESSION['modification_info']); ?>
                            <?php endif; ?>
                            
                            <div class="quick-buttons mt-3">
                                <div class="quick-btn" onclick="window.location.href='ride_records.php'">
                                    <i class="fas fa-users me-1"></i>乗車記録へ
                                </div>
                                <div class="quick-btn" onclick="window.location.href='dashboard.php'">
                                    <i class="fas fa-tachometer-alt me-1"></i>ダッシュボードへ
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_edit_mode && $edit_record): ?>
                        <div class="alert alert-info">
                            <h5><i class="fas fa-edit me-2"></i>出庫記録を修正中</h5>
                            <div class="row">
                                <div class="col-md-8">
                                    <strong>対象記録:</strong> <?php echo htmlspecialchars($edit_record['vehicle_number']); ?> 
                                    (<?php echo htmlspecialchars($edit_record['driver_name']); ?>) 
                                    - <?php echo $edit_record['departure_date']; ?>
                                </div>
                                <div class="col-md-4 text-end">
                                    <a href="departure.php" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-times me-1"></i>修正キャンセル
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="departureForm">
                        <?php if ($is_edit_mode): ?>
                            <input type="hidden" name="edit_mode" value="1">
                        <?php endif; ?>

                        <!-- 基本情報セクション -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-info-circle me-2"></i>基本情報
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="driver_id" class="form-label">
                                        <i class="fas fa-user me-1"></i>運転者 <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="driver_id" name="driver_id" required <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                                        <option value="">運転者を選択</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?php echo $driver['id']; ?>" 
                                                <?php 
                                                if ($is_edit_mode) {
                                                    echo ($driver['id'] == $edit_record['driver_id']) ? 'selected' : '';
                                                } else {
                                                    echo ($driver['id'] == $user_id) ? 'selected' : '';
                                                }
                                                ?>>
                                                <?php echo htmlspecialchars($driver['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($is_edit_mode): ?>
                                        <input type="hidden" name="driver_id" value="<?php echo $edit_record['driver_id']; ?>">
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="vehicle_id" class="form-label">
                                        <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="vehicle_id" name="vehicle_id" required onchange="getVehicleInfo()" <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                                        <option value="">車両を選択</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?php echo $vehicle['id']; ?>" data-mileage="<?php echo $vehicle['current_mileage']; ?>"
                                                <?php 
                                                if ($is_edit_mode) {
                                                    echo ($vehicle['id'] == $edit_record['vehicle_id']) ? 'selected' : '';
                                                }
                                                ?>>
                                                <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($is_edit_mode): ?>
                                        <input type="hidden" name="vehicle_id" value="<?php echo $edit_record['vehicle_id']; ?>">
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 車両情報表示 -->
                            <div id="vehicleInfo" class="vehicle-info" style="display: none;">
                                <div id="vehicleDetails"></div>
                            </div>

                            <!-- 前提条件チェック表示 -->
                            <div id="prerequisiteAlerts"></div>
                        </div>

                        <!-- 出庫情報セクション -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-clock me-2"></i>出庫情報
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="departure_date" class="form-label">
                                        <i class="fas fa-calendar me-1"></i>出庫日 <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="departure_date" name="departure_date" 
                                           value="<?php echo $is_edit_mode ? $edit_record['departure_date'] : $today; ?>" 
                                           required <?php echo $is_edit_mode ? 'readonly' : ''; ?>>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="departure_time" class="form-label">
                                        <i class="fas fa-clock me-1"></i>出庫時刻 <span class="text-danger">*</span>
                                    </label>
                                    <input type="time" class="form-control" id="departure_time" name="departure_time" 
                                           value="<?php echo $is_edit_mode ? substr($edit_record['departure_time'], 0, 5) : $current_time; ?>" 
                                           required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-cloud-sun me-1"></i>天候 <span class="text-danger">*</span>
                                </label>
                                <div class="row">
                                    <?php foreach ($weather_options as $weather): ?>
                                        <div class="col weather-option">
                                            <input type="radio" class="btn-check" name="weather" 
                                                   id="weather_<?php echo $weather; ?>" value="<?php echo $weather; ?>" 
                                                   <?php 
                                                   if ($is_edit_mode) {
                                                       echo ($edit_record['weather'] === $weather) ? 'checked' : '';
                                                   }
                                                   ?> required>
                                            <label class="btn btn-outline-primary w-100" for="weather_<?php echo $weather; ?>">
                                                <?php echo $weather; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="departure_mileage" class="form-label">
                                    <i class="fas fa-tachometer-alt me-1"></i>出庫メーター <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="departure_mileage" 
                                           name="departure_mileage" required min="0" step="1"
                                           value="<?php echo $is_edit_mode ? $edit_record['departure_mileage'] : ''; ?>">
                                    <span class="input-group-text">km</span>
                                </div>
                                <div class="form-text" id="mileageInfo"></div>
                            </div>
                        </div>

                        <!-- 送信ボタン -->
                        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                            <?php if ($is_edit_mode): ?>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-save me-2"></i>修正を保存
                                </button>
                                <a href="departure.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>キャンセル
                                </a>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sign-out-alt me-2"></i>出庫登録
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- サイドバー（本日の出庫記録） -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>本日の出庫記録</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($today_departures)): ?>
                        <p class="text-muted text-center py-4">
                            <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                            本日の出庫記録はありません。
                        </p>
                    <?php else: ?>
                        <?php foreach ($today_departures as $departure): ?>
                            <div class="departure-record">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo htmlspecialchars($departure['vehicle_number']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($departure['driver_name']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <div class="time-display">
                                            <?php echo substr($departure['departure_time'], 0, 5); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($departure['weather']); ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="mt-2 d-flex justify-content-between align-items-center">
                                    <small>
                                        <i class="fas fa-tachometer-alt me-1"></i>
                                        <?php echo number_format($departure['departure_mileage']); ?>km
                                    </small>
                                    <a href="departure.php?edit=true&id=<?php echo $departure['id']; ?>" 
                                       class="btn btn-sm btn-outline-warning">
                                        <i class="fas fa-edit me-1"></i>修正
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- クイックアクション -->
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>関連業務</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="pre_duty_call.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-clipboard-check me-1"></i>乗務前点呼
                        </a>
                        <a href="daily_inspection.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-tools me-1"></i>日常点検
                        </a>
                        <a href="ride_records.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-users me-1"></i>乗車記録
                        </a>
                        <a href="arrival.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-sign-in-alt me-1"></i>入庫処理
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    // 車両情報取得・前提条件チェック関数
    function getVehicleInfo() {
        const vehicleId = document.getElementById('vehicle_id').value;
        const driverId = document.getElementById('driver_id').value;
        const departureDate = document.getElementById('departure_date').value;
        const vehicleSelect = document.getElementById('vehicle_id');
        
        if (!vehicleId) {
            document.getElementById('vehicleInfo').style.display = 'none';
            document.getElementById('prerequisiteAlerts').innerHTML = '';
            document.getElementById('mileageInfo').textContent = '';
            document.getElementById('departure_mileage').value = '';
            return;
        }
        
        // 選択された車両の情報を取得
        const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
        const currentMileage = selectedOption.getAttribute('data-mileage');
        
        // 車両情報表示
        document.getElementById('vehicleInfo').style.display = 'block';
        document.getElementById('vehicleDetails').innerHTML = `
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h6 class="mb-1">${selectedOption.textContent}</h6>
                    <small class="text-muted">現在走行距離: ${parseInt(currentMileage).toLocaleString()}km</small>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="setCurrentMileage()">
                        <i class="fas fa-sync-alt me-1"></i>自動設定
                    </button>
                </div>
            </div>
        `;
        
        // 出庫メーターに自動設定（編集モードでない場合のみ）
        if (!<?php echo $is_edit_mode ? 'true' : 'false'; ?>) {
            if (currentMileage && currentMileage > 0) {
                document.getElementById('departure_mileage').value = currentMileage;
                document.getElementById('mileageInfo').innerHTML = 
                    `<i class="fas fa-info-circle text-info"></i> 車両マスタから自動設定: ${parseInt(currentMileage).toLocaleString()}km`;
            } else {
                document.getElementById('mileageInfo').innerHTML = 
                    '<i class="fas fa-exclamation-circle text-warning"></i> 走行距離情報がありません。手動で入力してください。';
            }
        }
        
        // 前提条件チェック（非同期）
        if (driverId && departureDate) {
            checkPrerequisites(driverId, departureDate);
        }
    }
    
    // 現在走行距離の設定
    function setCurrentMileage() {
        const vehicleSelect = document.getElementById('vehicle_id');
        const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
        const currentMileage = selectedOption.getAttribute('data-mileage');
        
        if (currentMileage && currentMileage > 0) {
            document.getElementById('departure_mileage').value = currentMileage;
            document.getElementById('mileageInfo').innerHTML = 
                `<i class="fas fa-check-circle text-success"></i> 設定完了: ${parseInt(currentMileage).toLocaleString()}km`;
        }
    }
    
    // 前提条件チェック（簡易版）
    function checkPrerequisites(driverId, date) {
        // 実際の実装では fetch APIを使用してサーバーサイドチェック
        // ここではサンプル表示
        document.getElementById('prerequisiteAlerts').innerHTML = `
            <div class="alert alert-warning alert-sm">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>前提条件確認:</strong> 
                <a href="daily_inspection.php">日常点検</a> と 
                <a href="pre_duty_call.php">乗務前点呼</a> の完了を確認してください。
            </div>
        `;
    }
    
    // 運転者変更時の前提条件チェック
    function checkDriverPrerequisites() {
        const driverId = document.getElementById('driver_id').value;
        const departureDate = document.getElementById('departure_date').value;
        
        if (driverId && departureDate) {
            checkPrerequisites(driverId, departureDate);
        }
    }
    
    // イベントリスナー
    document.addEventListener('DOMContentLoaded', function() {
        // 初期選択時の処理
        if (document.getElementById('vehicle_id').value) {
            getVehicleInfo();
        }
        
        // 運転者変更監視
        document.getElementById('driver_id').addEventListener('change', checkDriverPrerequisites);
        
        // 日付変更監視
        document.getElementById('departure_date').addEventListener('change', function() {
            const driverId = document.getElementById('driver_id').value;
            if (driverId) {
                checkPrerequisites(driverId, this.value);
            }
        });
        
        // 時刻更新（編集モードでない場合のみ）
        if (!<?php echo $is_edit_mode ? 'true' : 'false'; ?>) {
            setInterval(function() {
                if (document.activeElement !== document.getElementById('departure_time')) {
                    const now = new Date();
                    const timeString = now.toTimeString().slice(0, 5);
                    document.getElementById('departure_time').value = timeString;
                }
            }, 60000); // 1分ごと
        }
    });
    
    // フォーム送信前の確認
    document.getElementById('departureForm').addEventListener('submit', function(e) {
        const driverId = document.getElementById('driver_id').value;
        const vehicleId = document.getElementById('vehicle_id').value;
        const departureTime = document.getElementById('departure_time').value;
        const weather = document.querySelector('input[name="weather"]:checked');
        const mileage = document.getElementById('departure_mileage').value;
        
        if (!driverId || !vehicleId || !departureTime || !weather || !mileage) {
            e.preventDefault();
            alert('すべての必須項目を入力してください。');
            return;
        }
        
        const action = <?php echo $is_edit_mode ? '"修正"' : '"登録"'; ?>;
        if (!confirm(`出庫処理を${action}しますか？`)) {
            e.preventDefault();
        }
    });
    
    // 天候クイック選択（本日の天候を記憶）
    const savedWeather = localStorage.getItem('today_weather_' + document.getElementById('departure_date').value);
    if (savedWeather && !<?php echo $is_edit_mode ? 'true' : 'false'; ?>) {
        const weatherInput = document.getElementById('weather_' + savedWeather);
        if (weatherInput) {
            weatherInput.checked = true;
        }
    }
    
    // 天候選択時に保存
    document.querySelectorAll('input[name="weather"]').forEach(function(input) {
        input.addEventListener('change', function() {
            if (this.checked) {
                localStorage.setItem('today_weather_' + document.getElementById('departure_date').value, this.value);
            }
        });
    });
</script>

<style>
    .form-section {
        background: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    .section-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e9ecef;
    }
    
    .weather-option {
        margin: 5px;
    }
    
    .weather-option .btn {
        border-radius: 20px;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .vehicle-info {
        background: #e3f2fd;
        padding: 15px;
        border-radius: 8px;
        margin-top: 15px;
        border-left: 4px solid #2196f3;
    }
    
    .quick-buttons {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }
    
    .quick-btn {
        flex: 1;
        padding: 8px;
        border-radius: 6px;
        border: 1px solid #dee2e6;
        background: #f8f9fa;
        cursor: pointer;
        transition: all 0.3s;
        text-align: center;
        font-size: 0.9rem;
    }
    
    .quick-btn:hover {
        background: #e9ecef;
        border-color: #667eea;
    }
    
    .departure-record {
        background: #f8f9fa;
        padding: 15px;
        margin: 10px 0;
        border-radius: 8px;
        border-left: 4px solid #28a745;
        transition: all 0.3s;
    }
    
    .departure-record:hover {
        background: #e8f5e8;
        transform: translateX(5px);
    }
    
    .time-display {
        font-size: 1.2em;
        font-weight: bold;
        color: #495057;
    }
    
    .alert-sm {
        padding: 0.5rem 0.75rem;
        margin-bottom: 1rem;
        font-size: 0.875rem;
    }
</style>

<?php echo renderCompleteHTMLFooter(); ?>
