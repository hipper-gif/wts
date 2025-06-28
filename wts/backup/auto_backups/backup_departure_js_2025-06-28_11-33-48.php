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
        
        // 前提条件チェック（乗務前点呼・日常点検完了）
        $check_sql = "SELECT 
            (SELECT COUNT(*) FROM pre_duty_calls WHERE driver_id = ? AND call_date = ?) as pre_duty_count,
            (SELECT COUNT(*) FROM daily_inspections WHERE vehicle_id = ? AND inspection_date = ?) as inspection_count";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$driver_id, $departure_date, $vehicle_id, $departure_date]);
        $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($check_result['pre_duty_count'] == 0) {
            throw new Exception('乗務前点呼が完了していません。先に乗務前点呼を実施してください。');
        }
        
        if ($check_result['inspection_count'] == 0) {
            throw new Exception('日常点検が完了していません。先に日常点検を実施してください。');
        }
        
        // 重複チェック（同日同車両の出庫記録）
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
        
        $success_message = '出庫処理が完了しました。';
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者一覧取得
$drivers_sql = "SELECT id, name FROM users WHERE role IN ('driver', 'admin') ORDER BY name";
$drivers_stmt = $pdo->prepare($drivers_sql);
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// 車両一覧取得
$vehicles_sql = "SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE status = 'active' ORDER BY vehicle_number";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute();
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

// 前日の入庫メーター取得用JavaScript関数で使用
$previous_mileage_sql = "SELECT ar.arrival_mileage, ar.arrival_date 
    FROM arrival_records ar 
    WHERE ar.vehicle_id = ? 
    ORDER BY ar.arrival_date DESC, ar.id DESC 
    LIMIT 1";
$previous_mileage_stmt = $pdo->prepare($previous_mileage_sql);

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
            padding: 10px 30px;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            transform: translateY(-1px);
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
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
        .time-display {
            font-size: 1.2em;
            font-weight: bold;
            color: #495057;
        }
        .departure-record {
            background: #f8f9fa;
            padding: 10px;
            margin: 5px 0;
            border-radius: 8px;
            border-left: 4px solid #28a745;
        }
        .status-check {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .status-item:last-child {
            border-bottom: none;
        }
        .status-ok {
            color: #28a745;
            font-weight: bold;
        }
        .status-ng {
            color: #dc3545;
            font-weight: bold;
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
                    </div>
                    <div class="card-body">
                        <?php if ($success_message): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="departureForm">
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
                                    <select class="form-select" id="vehicle_id" name="vehicle_id" required onchange="getPreviousMileage()">
                                        <option value="">車両を選択</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?php echo $vehicle['id']; ?>">
                                                <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- 前提条件チェック表示エリア -->
                            <div id="statusCheck" class="status-check" style="display: none;">
                                <h6><i class="fas fa-clipboard-check me-2"></i>前提条件チェック</h6>
                                <div class="status-item">
                                    <span>乗務前点呼</span>
                                    <span id="preDutyStatus">確認中...</span>
                                </div>
                                <div class="status-item">
                                    <span>日常点検</span>
                                    <span id="inspectionStatus">確認中...</span>
                                </div>
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
                                        <div class="col-md-2 weather-option">
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
                                <div class="form-text" id="previousMileageInfo"></div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-secondary me-md-2" onclick="window.location.href='dashboard.php'">
                                    <i class="fas fa-arrow-left me-1"></i>戻る
                                </button>
                                <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                                    <i class="fas fa-sign-out-alt me-1"></i>出庫登録
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
                            <p class="text-muted">本日の出庫記録はありません。</p>
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

                <!-- 使用方法ガイド -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>使用方法</h6>
                    </div>
                    <div class="card-body">
                        <small>
                            <ol>
                                <li>運転者と車両を選択</li>
                                <li>前提条件（点呼・点検）の確認</li>
                                <li>出庫時刻と天候を入力</li>
                                <li>メーター値を確認・入力</li>
                                <li>出庫登録ボタンで完了</li>
                            </ol>
                            <hr>
                            <div class="text-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <strong>注意：</strong>乗務前点呼と日常点検の完了が必要です。
                            </div>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 前提条件チェック関数
        function checkPrerequisites() {
            const driverId = document.getElementById('driver_id').value;
            const vehicleId = document.getElementById('vehicle_id').value;
            const departureDate = document.getElementById('departure_date').value;
            
            if (!driverId || !vehicleId || !departureDate) {
                document.getElementById('statusCheck').style.display = 'none';
                document.getElementById('submitBtn').disabled = true;
                return;
            }
            
            document.getElementById('statusCheck').style.display = 'block';
            document.getElementById('preDutyStatus').innerHTML = '確認中...';
            document.getElementById('inspectionStatus').innerHTML = '確認中...';
            
            // AJAX で前提条件をチェック
            fetch('api/check_prerequisites.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    driver_id: driverId,
                    vehicle_id: vehicleId,
                    date: departureDate
                })
            })
            .then(response => response.json())
            .then(data => {
                // 乗務前点呼チェック結果
                if (data.pre_duty_completed) {
                    document.getElementById('preDutyStatus').innerHTML = '<span class="status-ok"><i class="fas fa-check-circle"></i> 完了</span>';
                } else {
                    document.getElementById('preDutyStatus').innerHTML = '<span class="status-ng"><i class="fas fa-times-circle"></i> 未完了</span>';
                }
                
                // 日常点検チェック結果
                if (data.inspection_completed) {
                    document.getElementById('inspectionStatus').innerHTML = '<span class="status-ok"><i class="fas fa-check-circle"></i> 完了</span>';
                } else {
                    document.getElementById('inspectionStatus').innerHTML = '<span class="status-ng"><i class="fas fa-times-circle"></i> 未完了</span>';
                }
                
                // 送信ボタンの有効/無効切り替え
                document.getElementById('submitBtn').disabled = !(data.pre_duty_completed && data.inspection_completed);
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('preDutyStatus').innerHTML = '<span class="status-ng">エラー</span>';
                document.getElementById('inspectionStatus').innerHTML = '<span class="status-ng">エラー</span>';
                document.getElementById('submitBtn').disabled = true;
            });
        }
        
        // 前日入庫メーター取得
        function getPreviousMileage() {
            const vehicleId = document.getElementById('vehicle_id').value;
            if (!vehicleId) {
                document.getElementById('previousMileageInfo').textContent = '';
                return;
            }
            
            fetch('api/get_previous_mileage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    vehicle_id: vehicleId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.previous_mileage) {
                    document.getElementById('previousMileageInfo').innerHTML = 
                        `<i class="fas fa-info-circle text-info"></i> 前回入庫メーター: ${data.previous_mileage.toLocaleString()}km (${data.date})`;
                    // 前回の値+1を自動設定
                    document.getElementById('departure_mileage').value = parseInt(data.previous_mileage);
                } else {
                    document.getElementById('previousMileageInfo').innerHTML = 
                        '<i class="fas fa-info-circle text-warning"></i> 前回の入庫記録がありません';
                }
            });
        }
        
        // イベントリスナー
        document.getElementById('driver_id').addEventListener('change', checkPrerequisites);
        document.getElementById('vehicle_id').addEventListener('change', checkPrerequisites);
        document.getElementById('departure_date').addEventListener('change', checkPrerequisites);
        
        // フォーム送信前の最終確認
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
        
        // ページ読み込み時の初期化
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('vehicle_id').value) {
                getPreviousMileage();
                checkPrerequisites();
            }
        });
    </script>
</body>
</html>