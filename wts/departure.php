<?php
session_start();

// データベース接続
require_once 'config/database.php';

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
$user_role = $_SESSION['user_role'];

// 今日の日付
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';

// POSTデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $driver_id = $_POST['driver_id'];
        $vehicle_id = $_POST['vehicle_id'];
        $departure_date = $_POST['departure_date'];
        $departure_time = $_POST['departure_time'];
        $weather = $_POST['weather'];
        $departure_mileage = $_POST['departure_mileage'];
        
        // 重複チェック（同日同車両の出庫記録）のみ実施
        $duplicate_sql = "SELECT COUNT(*) FROM departure_records WHERE vehicle_id = ? AND departure_date = ?";
        $duplicate_stmt = $pdo->prepare($duplicate_sql);
        $duplicate_stmt->execute([$vehicle_id, $departure_date]);
        
        if ($duplicate_stmt->fetchColumn() > 0) {
            throw new Exception('本日、この車両は既に出庫記録が登録されています。');
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
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者一覧取得
$drivers_sql = "SELECT id, name FROM users WHERE (role IN ('driver', 'admin') OR is_driver = 1) AND is_active = 1 ORDER BY name";
$drivers_stmt = $pdo->prepare($drivers_sql);
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
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>出庫処理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Hiragino Kaku Gothic Pro', 'ヒラギノ角ゴ Pro', 'Yu Gothic Medium', '游ゴシック Medium', YuGothic, '游ゴシック体', 'Meiryo', sans-serif;
        }
        .navbar-brand {
            font-weight: bold;
            color: #2c3e50 !important;
        }
        .card {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: none;
            border-radius: 10px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            padding: 12px 40px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            font-size: 1rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .alert {
            border-radius: 8px;
            border: none;
        }
        .weather-option {
            margin: 5px;
        }
        .weather-option .btn {
            border-radius: 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .weather-option .btn:checked + label {
            transform: scale(1.05);
        }
        .time-display {
            font-size: 1.2em;
            font-weight: bold;
            color: #495057;
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
    </style>
</head>
<body>
    <!-- ナビゲーションバー -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-taxi me-2"></i>福祉輸送管理システム
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt me-1"></i>ダッシュボード</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="departure.php"><i class="fas fa-sign-out-alt me-1"></i>出庫処理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="ride_records.php"><i class="fas fa-users me-1"></i>乗車記録</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="arrival.php"><i class="fas fa-sign-in-alt me-1"></i>入庫処理</a>
                    </li>
                </ul>
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($user_name); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- メイン入力フォーム -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-sign-out-alt me-2"></i>出庫処理</h4>
                        <small>素早く簡単に出庫登録</small>
                    </div>
                    <div class="card-body">
                        <?php if ($success_message): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                                <div class="quick-buttons">
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

                        <form method="POST" id="departureForm">
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
                                        <select class="form-select" id="driver_id" name="driver_id" required>
                                            <option value="">運転者を選択</option>
                                            <?php foreach ($drivers as $driver): ?>
                                                <option value="<?php echo $driver['id']; ?>" 
                                                    <?php echo ($user_role === 'driver' && $driver['id'] == $user_id) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($driver['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="vehicle_id" class="form-label">
                                            <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="vehicle_id" name="vehicle_id" required onchange="getVehicleInfo()">
                                            <option value="">車両を選択</option>
                                            <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?php echo $vehicle['id']; ?>" data-mileage="<?php echo $vehicle['current_mileage']; ?>">
                                                    <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- 車両情報表示 -->
                                <div id="vehicleInfo" class="vehicle-info" style="display: none;">
                                    <div id="vehicleDetails"></div>
                                </div>
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
                                               value="<?php echo $today; ?>" required>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="departure_time" class="form-label">
                                            <i class="fas fa-clock me-1"></i>出庫時刻 <span class="text-danger">*</span>
                                        </label>
                                        <input type="time" class="form-control" id="departure_time" name="departure_time" 
                                               value="<?php echo $current_time; ?>" required>
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
                                                       id="weather_<?php echo $weather; ?>" value="<?php echo $weather; ?>" required>
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
                                               name="departure_mileage" required min="0" step="1">
                                        <span class="input-group-text">km</span>
                                    </div>
                                    <div class="form-text" id="mileageInfo"></div>
                                </div>
                            </div>

                            <!-- 送信ボタン -->
                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sign-out-alt me-2"></i>出庫登録
                                </button>
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
                                    <div class="mt-2">
                                        <small>
                                            <i class="fas fa-tachometer-alt me-1"></i>
                                            <?php echo number_format($departure['departure_mileage']); ?>km
                                        </small>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 車両情報取得関数（シンプル版）
        function getVehicleInfo() {
            const vehicleId = document.getElementById('vehicle_id').value;
            const vehicleSelect = document.getElementById('vehicle_id');
            
            if (!vehicleId) {
                document.getElementById('vehicleInfo').style.display = 'none';
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
            
            // 出庫メーターに自動設定
            if (currentMileage && currentMileage > 0) {
                document.getElementById('departure_mileage').value = currentMileage;
                document.getElementById('mileageInfo').innerHTML = 
                    `<i class="fas fa-info-circle text-info"></i> 車両マスタから自動設定: ${parseInt(currentMileage).toLocaleString()}km`;
            } else {
                document.getElementById('mileageInfo').innerHTML = 
                    '<i class="fas fa-exclamation-circle text-warning"></i> 走行距離情報がありません。手動で入力してください。';
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
        
        // 時刻自動更新
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toTimeString().slice(0, 5);
            document.getElementById('departure_time').value = timeString;
        }
        
        // イベントリスナー
        document.addEventListener('DOMContentLoaded', function() {
            // 初期選択時の処理
            if (document.getElementById('vehicle_id').value) {
                getVehicleInfo();
            }
            
            // 時刻更新ボタン（任意）
            setInterval(function() {
                if (document.activeElement !== document.getElementById('departure_time')) {
                    // フォーカスされていない時のみ更新
                    updateCurrentTime();
                }
            }, 60000); // 1分ごと
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
            
            if (!confirm('出庫処理を登録しますか？')) {
                e.preventDefault();
            }
        });
        
        // 天候クイック選択（本日の天候を記憶）
        const savedWeather = localStorage.getItem('today_weather_' + '<?php echo $today; ?>');
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
                    localStorage.setItem('today_weather_' + '<?php echo $today; ?>', this.value);
                }
            });
        });
    </script>
</body>
</html>
