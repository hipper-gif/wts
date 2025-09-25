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

// 修正モードチェック
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === 'true';
$edit_record_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$existing_record = null;

if ($edit_mode && $edit_record_id) {
    // 修正対象記録取得
    $edit_stmt = $pdo->prepare("
        SELECT dr.*, u.name as driver_name, v.vehicle_number, v.vehicle_name 
        FROM departure_records dr 
        JOIN users u ON dr.driver_id = u.id 
        JOIN vehicles v ON dr.vehicle_id = v.id 
        WHERE dr.id = ?
    ");
    $edit_stmt->execute([$edit_record_id]);
    $existing_record = $edit_stmt->fetch();
    
    if (!$existing_record) {
        $error_message = '修正対象の記録が見つかりません。';
        $edit_mode = false;
    }
}

// 前提条件チェック関数
function checkPrerequisites($pdo, $driver_id, $date) {
    try {
        // 日常点検完了確認（記録の存在をチェック）
        $daily_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM daily_inspections 
            WHERE driver_id = ? AND inspection_date = ?
        ");
        $daily_stmt->execute([$driver_id, $date]);
        $daily_completed = $daily_stmt->fetchColumn() > 0;

        // 乗務前点呼完了確認（is_completedカラム存在可能性を考慮）
        $preduty_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM pre_duty_calls 
            WHERE driver_id = ? AND call_date = ?
        ");
        $preduty_stmt->execute([$driver_id, $date]);
        $preduty_completed = $preduty_stmt->fetchColumn() > 0;

        return [
            'daily_inspection' => $daily_completed,
            'pre_duty_call' => $preduty_completed,
            'can_proceed' => $daily_completed && $preduty_completed
        ];
    } catch (Exception $e) {
        return [
            'daily_inspection' => false,
            'pre_duty_call' => false,
            'can_proceed' => false,
            'error' => $e->getMessage()
        ];
    }
}

// POSTデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $driver_id = $_POST['driver_id'];
        $vehicle_id = $_POST['vehicle_id'];
        $departure_date = $_POST['departure_date'];
        $departure_time = $_POST['departure_time'];
        $weather = $_POST['weather'];
        $departure_mileage = $_POST['departure_mileage'];
        $remarks = $_POST['remarks'] ?? '';

        // 前提条件チェック
        $prerequisites = checkPrerequisites($pdo, $driver_id, $departure_date);
        
        if ($edit_mode && $edit_record_id) {
            // 修正処理
            $update_sql = "UPDATE departure_records SET 
                departure_time = ?, weather = ?, departure_mileage = ?, 
                pre_duty_completed = ?, daily_inspection_completed = ?,
                remarks = ?, updated_at = NOW()
                WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $departure_time, $weather, $departure_mileage,
                $prerequisites['pre_duty_call'] ? 1 : 0,
                $prerequisites['daily_inspection'] ? 1 : 0,
                $remarks, $edit_record_id
            ]);
            
            $success_message = '出庫記録を修正しました。';
            
        } else {
            // 新規登録処理
            // 重複チェック（同日同車両の出庫記録）
            $duplicate_sql = "SELECT COUNT(*) FROM departure_records WHERE vehicle_id = ? AND departure_date = ?";
            $duplicate_stmt = $pdo->prepare($duplicate_sql);
            $duplicate_stmt->execute([$vehicle_id, $departure_date]);
            
            if ($duplicate_stmt->fetchColumn() > 0) {
                throw new Exception('本日、この車両は既に出庫記録が登録されています。');
            }
            
            // データ保存
            $insert_sql = "INSERT INTO departure_records 
                (driver_id, vehicle_id, departure_date, departure_time, weather, departure_mileage, 
                 pre_duty_completed, daily_inspection_completed, remarks, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $driver_id, $vehicle_id, $departure_date, $departure_time, $weather, $departure_mileage,
                $prerequisites['pre_duty_call'] ? 1 : 0,
                $prerequisites['daily_inspection'] ? 1 : 0,
                $remarks
            ]);
            
            $success_message = '出庫処理が完了しました。';
        }
        
        // 車両の走行距離を更新
        $update_sql = "UPDATE vehicles SET current_mileage = ?, updated_at = NOW() WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$departure_mileage, $vehicle_id]);
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者取得
$drivers_stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

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
$page_config = getPageConfiguration('departure');

// ページオプション設定
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => ['css/ui-unified-v3.css'],
    'additional_js' => ['js/ui-interactions.js'],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '出庫処理', 'url' => 'departure.php']
    ]
];

// 完全ページ生成
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'departure',
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
        
        <!-- 前提条件アラート -->
        <?php if (!$edit_mode): ?>
        <div id="prerequisiteAlert" class="alert alert-info border-0 shadow-sm mb-4" style="display: none;">
            <div class="d-flex align-items-center">
                <i class="fas fa-info-circle fs-4 me-3"></i>
                <div>
                    <h6 class="mb-1">出庫前の準備状況</h6>
                    <div id="prerequisiteStatus"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 成功・エラーメッセージ -->
        <?php if ($success_message): ?>
            <?= renderAlert('success', '処理完了', $success_message) ?>
            <?php if (!$edit_mode): ?>
                <div class="alert alert-success border-0 shadow-sm">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <a href="ride_records.php" class="btn btn-success w-100">
                                <i class="fas fa-users me-2"></i>乗車記録へ進む
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="dashboard.php" class="btn btn-outline-success w-100">
                                <i class="fas fa-tachometer-alt me-2"></i>ダッシュボードへ
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>

        <div class="row g-4">
            <!-- メイン入力フォーム -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white py-3">
                        <h4 class="mb-0">
                            <i class="fas fa-<?= $edit_mode ? 'edit' : 'sign-out-alt' ?> me-2"></i>
                            <?= $edit_mode ? '出庫記録修正' : '出庫処理' ?>
                        </h4>
                        <?php if ($edit_mode): ?>
                            <small class="opacity-75">
                                修正対象: <?= htmlspecialchars($existing_record['vehicle_number']) ?> 
                                (<?= htmlspecialchars($existing_record['driver_name']) ?>)
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        
                        <form method="POST" id="departureForm">
                            <!-- 基本情報セクション -->
                            <div class="form-section mb-4">
                                <h6 class="section-title text-primary mb-3">
                                    <i class="fas fa-info-circle me-2"></i>基本情報
                                </h6>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="driver_id" class="form-label fw-semibold">
                                            <i class="fas fa-user me-1"></i>運転者 <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="driver_id" name="driver_id" required <?= $edit_mode ? 'disabled' : '' ?> onchange="checkPrerequisitesJS()">
                                            <option value="">運転者を選択</option>
                                            <?php foreach ($drivers as $driver): ?>
                                                <option value="<?= $driver['id'] ?>" 
                                                    <?= ($edit_mode && $existing_record['driver_id'] == $driver['id']) ? 'selected' : 
                                                        ((!$edit_mode && $driver['id'] == $user_id) ? 'selected' : '') ?>>
                                                    <?= htmlspecialchars($driver['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($edit_mode): ?>
                                            <input type="hidden" name="driver_id" value="<?= $existing_record['driver_id'] ?>">
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="vehicle_id" class="form-label fw-semibold">
                                            <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="vehicle_id" name="vehicle_id" required <?= $edit_mode ? 'disabled' : '' ?> onchange="getVehicleInfo()">
                                            <option value="">車両を選択</option>
                                            <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?= $vehicle['id'] ?>" data-mileage="<?= $vehicle['current_mileage'] ?>">
                                                    <?= htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($edit_mode): ?>
                                            <input type="hidden" name="vehicle_id" value="<?= $existing_record['vehicle_id'] ?>">
                                            <script>
                                                document.addEventListener('DOMContentLoaded', function() {
                                                    document.getElementById('vehicle_id').value = '<?= $existing_record['vehicle_id'] ?>';
                                                    getVehicleInfo();
                                                });
                                            </script>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- 車両情報表示 -->
                                <div id="vehicleInfo" class="mt-3" style="display: none;">
                                    <div class="alert alert-info border-0">
                                        <div id="vehicleDetails"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- 出庫情報セクション -->
                            <div class="form-section mb-4">
                                <h6 class="section-title text-primary mb-3">
                                    <i class="fas fa-clock me-2"></i>出庫情報
                                </h6>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="departure_date" class="form-label fw-semibold">
                                            <i class="fas fa-calendar me-1"></i>出庫日 <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" class="form-control" id="departure_date" name="departure_date" 
                                               value="<?= $edit_mode ? $existing_record['departure_date'] : $today ?>" 
                                               required <?= $edit_mode ? 'disabled' : '' ?>>
                                        <?php if ($edit_mode): ?>
                                            <input type="hidden" name="departure_date" value="<?= $existing_record['departure_date'] ?>">
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="departure_time" class="form-label fw-semibold">
                                            <i class="fas fa-clock me-1"></i>出庫時刻 <span class="text-danger">*</span>
                                        </label>
                                        <input type="time" class="form-control" id="departure_time" name="departure_time" 
                                               value="<?= $edit_mode ? substr($existing_record['departure_time'], 0, 5) : $current_time ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">
                                        <i class="fas fa-cloud-sun me-1"></i>天候 <span class="text-danger">*</span>
                                    </label>
                                    <div class="row g-2">
                                        <?php foreach ($weather_options as $weather): ?>
                                            <div class="col-auto">
                                                <input type="radio" class="btn-check" name="weather" 
                                                       id="weather_<?= $weather ?>" value="<?= $weather ?>" required
                                                       <?= ($edit_mode && $existing_record['weather'] === $weather) ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-primary" for="weather_<?= $weather ?>">
                                                    <?= $weather ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="departure_mileage" class="form-label fw-semibold">
                                        <i class="fas fa-tachometer-alt me-1"></i>出庫メーター <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="departure_mileage" 
                                               name="departure_mileage" required min="0" step="1"
                                               value="<?= $edit_mode ? $existing_record['departure_mileage'] : '' ?>">
                                        <span class="input-group-text">km</span>
                                    </div>
                                    <div class="form-text" id="mileageInfo"></div>
                                </div>

                                <div class="mb-3">
                                    <label for="remarks" class="form-label fw-semibold">
                                        <i class="fas fa-sticky-note me-1"></i>備考
                                    </label>
                                    <textarea class="form-control" id="remarks" name="remarks" rows="3" 
                                              placeholder="特記事項があれば記入してください"><?= $edit_mode ? htmlspecialchars($existing_record['remarks'] ?? '') : '' ?></textarea>
                                </div>
                            </div>

                            <!-- 送信ボタン -->
                            <div class="d-grid gap-2">
                                <?php if ($edit_mode): ?>
                                    <div class="row g-2">
                                        <div class="col">
                                            <button type="submit" class="btn btn-warning w-100 py-3">
                                                <i class="fas fa-save me-2"></i>修正を保存
                                            </button>
                                        </div>
                                        <div class="col-auto">
                                            <a href="departure.php" class="btn btn-secondary py-3">
                                                <i class="fas fa-times me-2"></i>キャンセル
                                            </a>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-primary py-3">
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
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light py-3">
                        <h5 class="mb-0 text-dark">
                            <i class="fas fa-list me-2"></i>本日の出庫記録
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($today_departures)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <p class="text-muted">本日の出庫記録はありません。</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($today_departures as $departure): ?>
                                <div class="border-bottom p-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="fw-bold"><?= htmlspecialchars($departure['vehicle_number']) ?></div>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($departure['driver_name']) ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-primary">
                                                <?= substr($departure['departure_time'], 0, 5) ?>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-cloud me-1"></i><?= htmlspecialchars($departure['weather']) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="mt-2 d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="fas fa-tachometer-alt me-1"></i>
                                            <?= number_format($departure['departure_mileage']) ?>km
                                        </small>
                                        <div>
                                            <a href="departure.php?edit=true&id=<?= $departure['id'] ?>" 
                                               class="btn btn-sm btn-outline-warning">
                                                <i class="fas fa-edit me-1"></i>修正
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 関連業務クイックアクセス -->
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-header bg-light py-3">
                        <h6 class="mb-0 text-dark">
                            <i class="fas fa-bolt me-2"></i>関連業務
                        </h6>
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
    </div>
</main>

<?= renderFooter() ?>

<script>
// 前提条件チェック関数（JavaScript）
function checkPrerequisitesJS() {
    const driverId = document.getElementById('driver_id').value;
    const departureDate = document.getElementById('departure_date').value;
    
    if (!driverId || !departureDate) {
        document.getElementById('prerequisiteAlert').style.display = 'none';
        return;
    }

    // AJAX呼び出しでサーバーサイドチェック
    fetch('api/check_prerequisites.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            driver_id: driverId,
            date: departureDate
        })
    })
    .then(response => response.json())
    .then(data => {
        const alertElement = document.getElementById('prerequisiteAlert');
        const statusElement = document.getElementById('prerequisiteStatus');
        
        let statusHtml = '<div class="row g-2">';
        statusHtml += `<div class="col-6">
            <i class="fas fa-${data.daily_inspection ? 'check-circle text-success' : 'times-circle text-danger'} me-1"></i>
            <small>日常点検: ${data.daily_inspection ? '完了' : '未実施'}</small>
        </div>`;
        statusHtml += `<div class="col-6">
            <i class="fas fa-${data.pre_duty_call ? 'check-circle text-success' : 'times-circle text-danger'} me-1"></i>
            <small>乗務前点呼: ${data.pre_duty_call ? '完了' : '未実施'}</small>
        </div>`;
        statusHtml += '</div>';
        
        statusElement.innerHTML = statusHtml;
        
        if (!data.can_proceed) {
            alertElement.className = 'alert alert-warning border-0 shadow-sm mb-4';
        } else {
            alertElement.className = 'alert alert-success border-0 shadow-sm mb-4';
        }
        
        alertElement.style.display = 'block';
    })
    .catch(error => {
        console.error('前提条件チェックエラー:', error);
    });
}

// 車両情報取得関数
function getVehicleInfo() {
    const vehicleId = document.getElementById('vehicle_id').value;
    const vehicleSelect = document.getElementById('vehicle_id');
    
    if (!vehicleId) {
        document.getElementById('vehicleInfo').style.display = 'none';
        document.getElementById('mileageInfo').textContent = '';
        if (!document.getElementById('departure_mileage').hasAttribute('readonly')) {
            document.getElementById('departure_mileage').value = '';
        }
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
                <small class="text-muted">現在走行距離: ${parseInt(currentMileage || 0).toLocaleString()}km</small>
            </div>
            <div class="col-md-4 text-end">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="setCurrentMileage()">
                    <i class="fas fa-sync-alt me-1"></i>自動設定
                </button>
            </div>
        </div>
    `;
    
    // 修正モードでなければ出庫メーターに自動設定
    if (!<?= $edit_mode ? 'true' : 'false' ?> && currentMileage && currentMileage > 0) {
        document.getElementById('departure_mileage').value = currentMileage;
        document.getElementById('mileageInfo').innerHTML = 
            `<i class="fas fa-info-circle text-info"></i> 車両マスタから自動設定: ${parseInt(currentMileage).toLocaleString()}km`;
    } else if (currentMileage) {
        document.getElementById('mileageInfo').innerHTML = 
            `<i class="fas fa-info-circle text-muted"></i> 車両の現在走行距離: ${parseInt(currentMileage).toLocaleString()}km`;
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

// 初期化処理
document.addEventListener('DOMContentLoaded', function() {
    // 修正モードでない場合の初期処理
    <?php if (!$edit_mode): ?>
    // 前提条件チェック
    checkPrerequisitesJS();
    
    // 天候の記憶機能
    const savedWeather = localStorage.getItem('today_weather_<?= $today ?>');
    if (savedWeather) {
        const weatherInput = document.getElementById('weather_' + savedWeather);
        if (weatherInput) {
            weatherInput.checked = true;
        }
    }
    
    // 天候選択時に保存
    document.querySelectorAll('input[name="weather"]').forEach(function(input) {
        input.addEventListener('change', function() {
            if (this.checked) {
                localStorage.setItem('today_weather_<?= $today ?>', this.value);
            }
        });
    });
    <?php endif; ?>
    
    // 車両選択時の処理
    if (document.getElementById('vehicle_id').value) {
        getVehicleInfo();
    }
    
    // フォーム送信前の確認
    document.getElementById('departureForm').addEventListener('submit', function(e) {
        const isEditMode = <?= $edit_mode ? 'true' : 'false' ?>;
        const confirmMessage = isEditMode ? '出庫記録を修正しますか？' : '出庫処理を登録しますか？';
        
        if (!confirm(confirmMessage)) {
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>
