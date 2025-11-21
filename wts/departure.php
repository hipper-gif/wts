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
$user_role = $_SESSION['user_role'] ?? 'User';

// 今日の日付
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';
$edit_mode = false;
$edit_record = null;

// 修正モードの確認
if (isset($_GET['edit']) && $_GET['edit'] == 'true' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $edit_stmt = $pdo->prepare("SELECT * FROM departure_records WHERE id = ? AND departure_date = ?");
    $edit_stmt->execute([$edit_id, $today]);
    $edit_record = $edit_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($edit_record) {
        $edit_mode = true;
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
        $departure_mileage = intval($_POST['departure_mileage']);
        $edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : null;
        
        if ($edit_id) {
            // 修正処理
            $update_sql = "UPDATE departure_records SET 
                departure_time = ?, weather = ?, departure_mileage = ?, updated_at = NOW() 
                WHERE id = ? AND departure_date = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$departure_time, $weather, $departure_mileage, $edit_id, $departure_date]);
            
            // 車両の走行距離も更新
            $update_vehicle_sql = "UPDATE vehicles SET current_mileage = ?, updated_at = NOW() WHERE id = ?";
            $update_vehicle_stmt = $pdo->prepare($update_vehicle_sql);
            $update_vehicle_stmt->execute([$departure_mileage, $vehicle_id]);
            
            $success_message = '出庫記録を修正しました。';
            $edit_mode = false;
            $edit_record = null;
            
        } else {
            // 新規登録処理
            
            // 複数出庫対応：入庫済みの場合は再出庫を許可
            $duplicate_check_sql = "
                SELECT dr.id, ar.id as arrival_id 
                FROM departure_records dr
                LEFT JOIN arrival_records ar ON dr.id = ar.departure_record_id
                WHERE dr.vehicle_id = ? AND dr.departure_date = ?
            ";
            $duplicate_stmt = $pdo->prepare($duplicate_check_sql);
            $duplicate_stmt->execute([$vehicle_id, $departure_date]);
            $existing_records = $duplicate_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 入庫していない出庫記録があるかチェック
            $unfinished_departures = array_filter($existing_records, function($record) {
                return !$record['arrival_id']; // arrival_idがnullの場合は未入庫
            });
            
            if (!empty($unfinished_departures)) {
                throw new Exception('この車両は本日まだ入庫されていません。先に入庫処理を完了してください。');
            }
            
            // データ保存
            $insert_sql = "INSERT INTO departure_records 
                (driver_id, vehicle_id, departure_date, departure_time, weather, departure_mileage, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([$driver_id, $vehicle_id, $departure_date, $departure_time, $weather, $departure_mileage]);
            
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

// 本日の出庫記録一覧取得（入庫状態も含む）
$today_departures_sql = "
    SELECT dr.*, u.name as driver_name, v.vehicle_number, v.vehicle_name,
           ar.id as arrival_id, ar.arrival_time
    FROM departure_records dr 
    JOIN users u ON dr.driver_id = u.id 
    JOIN vehicles v ON dr.vehicle_id = v.id 
    LEFT JOIN arrival_records ar ON dr.id = ar.departure_record_id
    WHERE dr.departure_date = ? 
    ORDER BY dr.departure_time DESC";
$today_departures_stmt = $pdo->prepare($today_departures_sql);
$today_departures_stmt->execute([$today]);
$today_departures = $today_departures_stmt->fetchAll(PDO::FETCH_ASSOC);

// 天候選択肢
$weather_options = ['晴', '曇', '雨', '雪', '霧'];

// ページ設定
$page_config = getPageConfiguration('departure');

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => ['css/ui-unified-v3.css'],
    'additional_js' => ['js/departure-interactions.js'],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '出庫処理', 'url' => 'departure.php']
    ]
];

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
        
        <!-- 修正モードのアラート -->
        <?php if ($edit_mode && $edit_record): ?>
        <div class="alert alert-warning border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-edit text-warning fs-3 me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">出庫記録修正モード</h5>
                    <p class="mb-0">
                        <?= date('n月j日', strtotime($edit_record['departure_date'])) ?> 
                        <?= substr($edit_record['departure_time'], 0, 5) ?> の記録を修正中
                    </p>
                </div>
                <a href="departure.php" class="btn btn-outline-warning">
                    <i class="fas fa-times me-2"></i>修正を中止
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

        <div class="row">
            <!-- メイン入力フォーム -->
            <div class="col-lg-8">
                <form method="POST" id="departureForm">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="edit_id" value="<?= $edit_record['id'] ?>">
                    <?php endif; ?>

                    <!-- 基本情報セクション -->
                    <?= renderSectionHeader('info-circle', '基本情報', $edit_mode ? '出庫記録修正' : '運転者・車両選択') ?>
                    <div class="card mb-4">
                        <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="driver_id" class="form-label">
                                            <i class="fas fa-user me-1"></i>運転者 <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="driver_id" name="driver_id" required 
                                                <?= $edit_mode ? 'disabled' : '' ?>>
                                            <option value="">運転者を選択</option>
                                            <?php foreach ($drivers as $driver): ?>
                                                <option value="<?= $driver['id'] ?>" 
                                                    <?php if ($edit_mode): ?>
                                                        <?= ($driver['id'] == $edit_record['driver_id']) ? 'selected' : '' ?>
                                                    <?php else: ?>
                                                        <?= ($driver['id'] == $user_id) ? 'selected' : '' ?>
                                                    <?php endif; ?>>
                                                    <?= htmlspecialchars($driver['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($edit_mode): ?>
                                            <input type="hidden" name="driver_id" value="<?= $edit_record['driver_id'] ?>">
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="vehicle_id" class="form-label">
                                            <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="vehicle_id" name="vehicle_id" required 
                                                <?= $edit_mode ? 'disabled' : '' ?> onchange="getVehicleInfo()">
                                            <option value="">車両を選択</option>
                                            <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?= $vehicle['id'] ?>" 
                                                        data-mileage="<?= $vehicle['current_mileage'] ?>"
                                                    <?php if ($edit_mode): ?>
                                                        <?= ($vehicle['id'] == $edit_record['vehicle_id']) ? 'selected' : '' ?>
                                                    <?php endif; ?>>
                                                    <?= htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($edit_mode): ?>
                                            <input type="hidden" name="vehicle_id" value="<?= $edit_record['vehicle_id'] ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- 車両情報表示 -->
                                <div id="vehicleInfo" class="vehicle-info" style="display: none;">
                                    <div id="vehicleDetails"></div>
                                </div>
                        </div>
                    </div>
                </div>

                <!-- 出庫情報セクション -->
                <?= renderSectionHeader('clock', '出庫情報', '日時・天候・メーター') ?>
                <div class="card mb-4">
                    <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="departure_date" class="form-label">
                                            <i class="fas fa-calendar me-1"></i>出庫日 <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" class="form-control" id="departure_date" name="departure_date" 
                                               value="<?= $edit_mode ? $edit_record['departure_date'] : $today ?>" 
                                               <?= $edit_mode ? 'readonly' : '' ?> required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="departure_time" class="form-label">
                                            <i class="fas fa-clock me-1"></i>出庫時刻 <span class="text-danger">*</span>
                                        </label>
                                        <input type="time" class="form-control" id="departure_time" name="departure_time" 
                                               value="<?= $edit_mode ? substr($edit_record['departure_time'], 0, 5) : $current_time ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-cloud-sun me-1"></i>天候 <span class="text-danger">*</span>
                                    </label>
                                    <!-- 改善：よりコンパクトなレイアウト -->
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php foreach ($weather_options as $weather): ?>
                                            <div class="weather-option">
                                                <input type="radio" class="btn-check" name="weather" 
                                                       id="weather_<?= $weather ?>" value="<?= $weather ?>" required
                                                       <?= ($edit_mode && $edit_record['weather'] == $weather) ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-primary" for="weather_<?= $weather ?>">
                                                    <?= $weather ?>
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
                                               value="<?= $edit_mode ? $edit_record['departure_mileage'] : '' ?>">
                                        <span class="input-group-text">km</span>
                                        <button type="button" class="btn btn-outline-secondary" onclick="setCurrentMileage()" id="autoSetBtn">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                    <div class="form-text" id="mileageInfo"></div>
                                </div>
                        </div>
                    </div>
                </div>

                <!-- 送信ボタン -->
                <div class="text-center mb-4">
                    <?php if ($edit_mode): ?>
                        <button type="submit" class="btn btn-warning btn-lg me-2">
                            <i class="fas fa-save me-2"></i>修正を保存
                        </button>
                        <a href="departure.php" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times me-2"></i>修正を中止
                        </a>
                    <?php else: ?>
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-sign-out-alt me-2"></i>出庫登録
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <!-- 次のステップへの案内 -->
            <?php if ($success_message && !$edit_mode): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle text-success fs-3 me-3"></i>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-1">出庫処理完了</h5>
                        <p class="mb-0">次は乗車記録を登録してください</p>
                    </div>
                    <a href="ride_records.php" class="btn btn-success btn-lg">
                        <i class="fas fa-users me-2"></i>乗車記録へ進む
                    </a>
                </div>
            </div>
            <?php endif; ?>
            </div>

            <!-- サイドバー（本日の出庫記録） -->
            <div class="col-lg-4">
                <?= renderSectionHeader('list', '本日の出庫記録', '複数出庫対応') ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <?php if (empty($today_departures)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-info-circle fa-2x mb-2 d-block opacity-50"></i>
                                本日の出庫記録はありません。
                            </div>
                        <?php else: ?>
                            <?php foreach ($today_departures as $departure): ?>
                                <div class="departure-record position-relative">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= htmlspecialchars($departure['vehicle_number']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($departure['driver_name']) ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="time-display">
                                                <?= substr($departure['departure_time'], 0, 5) ?>
                                            </div>
                                            <?php if ($departure['arrival_id']): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check me-1"></i>入庫済み
                                                    <?= substr($departure['arrival_time'], 0, 5) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-clock me-1"></i>出庫中
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mt-2 d-flex justify-content-between align-items-center">
                                        <small>
                                            <i class="fas fa-cloud-sun me-1"></i><?= htmlspecialchars($departure['weather']) ?>
                                            <i class="fas fa-tachometer-alt ms-2 me-1"></i><?= number_format($departure['departure_mileage']) ?>km
                                        </small>
                                        <div>
                                            <a href="departure.php?edit=true&id=<?= $departure['id'] ?>" 
                                               class="btn btn-sm btn-outline-warning" title="修正">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- クイックアクション -->
                <?= renderSectionHeader('bolt', '関連業務', 'クイックアクセス') ?>
                <div class="card mb-4">
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
                
                <!-- 複数出庫説明 -->
                <div class="card bg-light mb-4">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">
                            <i class="fas fa-info-circle me-1"></i>複数出庫について
                        </h6>
                        <small class="text-muted">
                            入庫済みの車両は、同日中に再度出庫できます。出庫中の車両は先に入庫処理を完了してください。
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* カスタムスタイル */
.form-section {
    background: white;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e3f2fd;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #37474f;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e3f2fd;
}

.weather-option {
    margin-right: 8px;
    margin-bottom: 8px;
}

.weather-option .btn {
    border-radius: 20px;
    font-weight: 500;
    padding: 8px 16px;
    font-size: 0.9rem;
    transition: all 0.3s;
}

.weather-option .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.vehicle-info {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    padding: 16px;
    border-radius: 12px;
    margin-top: 15px;
    border: 1px solid #bbdefb;
}

.departure-record {
    background: #f8f9fa;
    padding: 16px;
    margin-bottom: 12px;
    border-radius: 12px;
    border-left: 4px solid #28a745;
    transition: all 0.3s;
}

.departure-record:hover {
    background: #e8f5e8;
    transform: translateX(3px);
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
}

.time-display {
    font-size: 1.1em;
    font-weight: 600;
    color: #37474f;
}

.gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.gradient-info {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
}

.gradient-secondary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

#autoSetBtn {
    border-left: none;
}

#autoSetBtn:hover {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-lg {
    padding: 12px 32px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 25px;
}
</style>

<script>
// 車両情報取得関数（改善版）
function getVehicleInfo() {
    const vehicleId = document.getElementById('vehicle_id').value;
    const vehicleSelect = document.getElementById('vehicle_id');
    
    if (!vehicleId) {
        document.getElementById('vehicleInfo').style.display = 'none';
        document.getElementById('mileageInfo').textContent = '';
        if (!<?= $edit_mode ? 'true' : 'false' ?>) {
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
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-1 text-primary">${selectedOption.textContent}</h6>
                <small class="text-muted">
                    <i class="fas fa-tachometer-alt me-1"></i>現在走行距離: ${parseInt(currentMileage).toLocaleString()}km
                </small>
            </div>
            <div>
                <span class="badge bg-primary">${parseInt(currentMileage).toLocaleString()}km</span>
            </div>
        </div>
    `;
    
    // 出庫メーターに自動設定（修正モード以外）
    if (!<?= $edit_mode ? 'true' : 'false' ?> && currentMileage && currentMileage > 0) {
        document.getElementById('departure_mileage').value = currentMileage;
        document.getElementById('mileageInfo').innerHTML = 
            `<i class="fas fa-check-circle text-success me-1"></i>車両マスタから自動設定: ${parseInt(currentMileage).toLocaleString()}km`;
    } else if (currentMileage && currentMileage > 0) {
        document.getElementById('mileageInfo').innerHTML = 
            `<i class="fas fa-info-circle text-info me-1"></i>車両の現在走行距離: ${parseInt(currentMileage).toLocaleString()}km`;
    } else {
        document.getElementById('mileageInfo').innerHTML = 
            '<i class="fas fa-exclamation-circle text-warning me-1"></i>走行距離情報がありません。手動で入力してください。';
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
            `<i class="fas fa-check-circle text-success me-1"></i>設定完了: ${parseInt(currentMileage).toLocaleString()}km`;
    } else {
        alert('車両の走行距離情報が取得できません。');
    }
}

// イベントリスナー
document.addEventListener('DOMContentLoaded', function() {
    // 初期選択時の処理
    if (document.getElementById('vehicle_id').value) {
        getVehicleInfo();
    }
    
    // 天候選択の記憶機能
    const savedWeather = localStorage.getItem('today_weather_' + '<?= $today ?>');
    if (savedWeather && !<?= $edit_mode ? 'true' : 'false' ?>) {
        const weatherInput = document.getElementById('weather_' + savedWeather);
        if (weatherInput && !weatherInput.checked) {
            weatherInput.checked = true;
        }
    }
    
    // 天候選択時の保存
    document.querySelectorAll('input[name="weather"]').forEach(function(input) {
        input.addEventListener('change', function() {
            if (this.checked) {
                localStorage.setItem('today_weather_' + '<?= $today ?>', this.value);
            }
        });
    });
});

// フォーム送信前の確認
document.getElementById('departureForm').addEventListener('submit', function(e) {
    const editMode = <?= $edit_mode ? 'true' : 'false' ?>;
    const confirmMsg = editMode ? '出庫記録を修正しますか？' : '出庫処理を登録しますか？';
    
    if (!confirm(confirmMsg)) {
        e.preventDefault();
        return false;
    }
    
    // 必須項目チェック
    const requiredFields = ['driver_id', 'vehicle_id', 'departure_date', 'departure_time', 'departure_mileage'];
    const weather = document.querySelector('input[name="weather"]:checked');
    
    for (let field of requiredFields) {
        const element = document.getElementById(field);
        if (!element.value.trim()) {
            e.preventDefault();
            alert('すべての必須項目を入力してください。');
            element.focus();
            return false;
        }
    }
    
    if (!weather) {
        e.preventDefault();
        alert('天候を選択してください。');
        return false;
    }
});
</script>

</body>
</html>
