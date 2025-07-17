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
        $arrival_date = $_POST['arrival_date'];
        $arrival_time = $_POST['arrival_time'];
        $arrival_mileage = $_POST['arrival_mileage'];
        $fuel_cost = $_POST['fuel_cost'] ?? 0;
        $toll_cost = $_POST['toll_cost'] ?? 0;
        $other_cost = $_POST['other_cost'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        
        // 対応する出庫記録を取得
        $departure_sql = "SELECT id, departure_mileage FROM departure_records 
                         WHERE driver_id = ? AND vehicle_id = ? AND departure_date = ?";
        $departure_stmt = $pdo->prepare($departure_sql);
        $departure_stmt->execute([$driver_id, $vehicle_id, $arrival_date]);
        $departure_record = $departure_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$departure_record) {
            throw new Exception('対応する出庫記録が見つかりません。先に出庫処理を行ってください。');
        }
        
        // 走行距離の妥当性チェック
        $total_distance = $arrival_mileage - $departure_record['departure_mileage'];
        if ($total_distance < 0) {
            throw new Exception('入庫メーターが出庫メーターより小さい値です。正しい値を入力してください。');
        }
        
        if ($total_distance > 500) {
            throw new Exception('走行距離が500kmを超えています。メーター値を確認してください。');
        }
        
        // 重複チェック（同日同車両の入庫記録）
        $duplicate_sql = "SELECT COUNT(*) FROM arrival_records WHERE vehicle_id = ? AND arrival_date = ?";
        $duplicate_stmt = $pdo->prepare($duplicate_sql);
        $duplicate_stmt->execute([$vehicle_id, $arrival_date]);
        
        if ($duplicate_stmt->fetchColumn() > 0) {
            throw new Exception('本日、この車両は既に入庫記録が登録されています。');
        }
        
        // データ保存
        $insert_sql = "INSERT INTO arrival_records 
            (departure_record_id, driver_id, vehicle_id, arrival_date, arrival_time, 
             arrival_mileage, fuel_cost, toll_cost, other_cost, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([
            $departure_record['id'], $driver_id, $vehicle_id, $arrival_date, 
            $arrival_time, $arrival_mileage, $fuel_cost, $toll_cost, $other_cost, $notes
        ]);
        
        // 車両の走行距離を更新
        $update_vehicle_sql = "UPDATE vehicles SET current_mileage = ?, updated_at = NOW() WHERE id = ?";
        $update_vehicle_stmt = $pdo->prepare($update_vehicle_sql);
        $update_vehicle_stmt->execute([$arrival_mileage, $vehicle_id]);
        
        $success_message = "入庫処理が完了しました。走行距離: {$total_distance}km";
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者一覧取得（権限チェックを緩和）
$drivers_sql = "SELECT id, name FROM users WHERE (role IN ('driver', 'admin') OR is_driver = 1) AND is_active = 1 ORDER BY name";
$drivers_stmt = $pdo->prepare($drivers_sql);
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// 車両一覧取得
$vehicles_sql = "SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE status = 'active' ORDER BY vehicle_number";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute();
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

// 本日の出庫済み・未入庫車両取得
$pending_arrivals_sql = "SELECT 
    dr.id, dr.driver_id, dr.vehicle_id, dr.departure_time, dr.departure_mileage,
    u.name as driver_name, v.vehicle_number, v.vehicle_name
    FROM departure_records dr
    JOIN users u ON dr.driver_id = u.id
    JOIN vehicles v ON dr.vehicle_id = v.id
    LEFT JOIN arrival_records ar ON dr.id = ar.departure_record_id
    WHERE dr.departure_date = ? AND ar.id IS NULL
    ORDER BY dr.departure_time";
$pending_stmt = $pdo->prepare($pending_arrivals_sql);
$pending_stmt->execute([$today]);
$pending_arrivals = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// 本日の入庫記録一覧取得
$today_arrivals_sql = "SELECT ar.*, u.name as driver_name, v.vehicle_number, v.vehicle_name,
    dr.departure_mileage, dr.departure_time,
    (ar.arrival_mileage - dr.departure_mileage) as total_distance
    FROM arrival_records ar 
    JOIN users u ON ar.driver_id = u.id 
    JOIN vehicles v ON ar.vehicle_id = v.id 
    LEFT JOIN departure_records dr ON ar.departure_record_id = dr.id
    WHERE ar.arrival_date = ? 
    ORDER BY ar.arrival_time DESC";
$today_arrivals_stmt = $pdo->prepare($today_arrivals_sql);
$today_arrivals_stmt->execute([$today]);
$today_arrivals = $today_arrivals_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>入庫処理 - 福祉輸送管理システム</title>
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
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 25px;
            padding: 10px 30px;
        }
        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, #1c9c85 100%);
            transform: translateY(-1px);
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }
        .alert {
            border-radius: 8px;
            border: none;
        }
        .pending-item {
            background: #fff3cd;
            padding: 15px;
            margin: 8px 0;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
            cursor: pointer;
            transition: all 0.3s;
        }
        .pending-item:hover {
            background: #fff8dc;
            transform: translateX(5px);
        }
        .arrival-record {
            background: #d4edda;
            padding: 15px;
            margin: 8px 0;
            border-radius: 8px;
            border-left: 4px solid #28a745;
        }
        .cost-input {
            max-width: 120px;
        }
        .distance-display {
            font-size: 1.3em;
            font-weight: bold;
            color: #28a745;
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #28a745;
        }
        .departure-info {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
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
                        <a class="nav-link" href="departure.php"><i class="fas fa-sign-out-alt me-1"></i>出庫処理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="ride_records.php"><i class="fas fa-users me-1"></i>乗車記録</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="arrival.php"><i class="fas fa-sign-in-alt me-1"></i>入庫処理</a>
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
            <!-- 未入庫車両一覧 -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>未入庫車両</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_arrivals)): ?>
                            <p class="text-muted">未入庫の車両はありません。</p>
                        <?php else: ?>
                            <?php foreach ($pending_arrivals as $pending): ?>
                                <div class="pending-item" onclick="selectPendingArrival(<?php echo htmlspecialchars(json_encode($pending)); ?>)">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?php echo htmlspecialchars($pending['vehicle_number']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($pending['driver_name']); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div style="font-weight: bold; color: #856404;">
                                                <?php echo substr($pending['departure_time'], 0, 5); ?> 出庫
                                            </div>
                                            <small class="text-muted">
                                                <?php echo number_format($pending['departure_mileage']); ?>km
                                            </small>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <small class="text-warning">
                                            <i class="fas fa-clock me-1"></i>クリックして入庫処理
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 本日の入庫記録 -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>本日の入庫記録</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($today_arrivals)): ?>
                            <p class="text-muted">本日の入庫記録はありません。</p>
                        <?php else: ?>
                            <?php foreach ($today_arrivals as $arrival): ?>
                                <div class="arrival-record">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?php echo htmlspecialchars($arrival['vehicle_number']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($arrival['driver_name']); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div style="font-weight: bold; color: #155724;">
                                                <?php echo substr($arrival['arrival_time'], 0, 5); ?> 入庫
                                            </div>
                                            <small class="text-muted">
                                                走行: <?php echo number_format($arrival['total_distance']); ?>km
                                            </small>
                                        </div>
                                    </div>
                                    <?php if ($arrival['fuel_cost'] > 0 || $arrival['toll_cost'] > 0 || $arrival['other_cost'] > 0): ?>
                                        <div class="mt-2">
                                            <small>
                                                費用計: ¥<?php echo number_format($arrival['fuel_cost'] + $arrival['toll_cost'] + $arrival['other_cost']); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- メイン入力フォーム -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-sign-in-alt me-2"></i>入庫処理</h4>
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

                        <!-- 出庫情報表示エリア -->
                        <div id="departureInfo" class="departure-info" style="display: none;">
                            <h6><i class="fas fa-info-circle me-2"></i>出庫情報</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <small><strong>出庫時刻:</strong> <span id="depTime"></span></small>
                                </div>
                                <div class="col-md-6">
                                    <small><strong>出庫メーター:</strong> <span id="depMileage"></span>km</small>
                                </div>
                            </div>
                        </div>

                        <form method="POST" id="arrivalForm">
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
                                    <select class="form-select" id="vehicle_id" name="vehicle_id" required onchange="updateDistanceCalculation()">
                                        <option value="">車両を選択</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?php echo $vehicle['id']; ?>">
                                                <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="arrival_date" class="form-label">
                                        <i class="fas fa-calendar me-1"></i>入庫日 <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="arrival_date" name="arrival_date" 
                                           value="<?php echo $today; ?>" required onchange="updateDistanceCalculation()">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="arrival_time" class="form-label">
                                        <i class="fas fa-clock me-1"></i>入庫時刻 <span class="text-danger">*</span>
                                    </label>
                                    <input type="time" class="form-control" id="arrival_time" name="arrival_time" 
                                           value="<?php echo $current_time; ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="arrival_mileage" class="form-label">
                                    <i class="fas fa-tachometer-alt me-1"></i>入庫メーター <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="arrival_mileage" 
                                           name="arrival_mileage" required min="0" step="1" onchange="updateDistanceCalculation()">
                                    <span class="input-group-text">km</span>
                                </div>
                            </div>

                            <!-- 走行距離表示 -->
                            <div id="distanceDisplay" class="distance-display mb-3" style="display: none;">
                                <i class="fas fa-route me-2"></i>走行距離: <span id="totalDistance">0</span>km
                            </div>

                            <!-- 費用入力 -->
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-yen-sign me-2"></i>費用入力</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4 mb-2">
                                            <label for="fuel_cost" class="form-label">燃料代</label>
                                            <div class="input-group cost-input">
                                                <span class="input-group-text">¥</span>
                                                <input type="number" class="form-control" id="fuel_cost" 
                                                       name="fuel_cost" min="0" step="1" value="0">
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-2">
                                            <label for="toll_cost" class="form-label">高速代等</label>
                                            <div class="input-group cost-input">
                                                <span class="input-group-text">¥</span>
                                                <input type="number" class="form-control" id="toll_cost" 
                                                       name="toll_cost" min="0" step="1" value="0">
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-2">
                                            <label for="other_cost" class="form-label">その他</label>
                                            <div class="input-group cost-input">
                                                <span class="input-group-text">¥</span>
                                                <input type="number" class="form-control" id="other_cost" 
                                                       name="other_cost" min="0" step="1" value="0">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <strong>合計: ¥<span id="totalCost">0</span></strong>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">
                                    <i class="fas fa-sticky-note me-1"></i>備考
                                </label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="特記事項があれば入力してください"></textarea>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-secondary me-md-2" onclick="clearForm()">
                                    <i class="fas fa-eraser me-1"></i>クリア
                                </button>
                                <button type="button" class="btn btn-secondary me-md-2" onclick="window.location.href='dashboard.php'">
                                    <i class="fas fa-arrow-left me-1"></i>戻る
                                </button>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-sign-in-alt me-1"></i>入庫登録
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 使用方法ガイド -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>使用方法</h6>
                    </div>
                    <div class="card-body">
                        <small>
                            <ol>
                                <li><strong>未入庫車両から選択:</strong> 左側の未入庫車両をクリックすると自動入力</li>
                                <li><strong>手動入力:</strong> 運転者・車両・日付を選択</li>
                                <li><strong>メーター入力:</strong> 入庫メーターを入力すると走行距離が自動計算</li>
                                <li><strong>費用入力:</strong> 燃料代・高速代等を入力（任意）</li>
                                <li><strong>入庫登録:</strong> 入庫登録ボタンで完了</li>
                            </ol>
                            <hr>
                            <div class="text-info">
                                <i class="fas fa-lightbulb me-1"></i>
                                <strong>ヒント：</strong>未入庫車両をクリックすると出庫情報が自動入力されます。
                            </div>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let departureData = null;

        // 未入庫車両選択時の自動入力
        function selectPendingArrival(data) {
            departureData = data;
            
            // フォームに値を設定
            document.getElementById('driver_id').value = data.driver_id;
            document.getElementById('vehicle_id').value = data.vehicle_id;
            document.getElementById('arrival_date').value = '<?php echo $today; ?>';
            
            // 出庫情報を表示
            document.getElementById('departureInfo').style.display = 'block';
            document.getElementById('depTime').textContent = data.departure_time.substring(0, 5);
            document.getElementById('depMileage').textContent = parseInt(data.departure_mileage).toLocaleString();
            
            // 入庫メーターにフォーカス
            document.getElementById('arrival_mileage').focus();
            
            updateDistanceCalculation();
        }

        // 走行距離計算・表示更新
        function updateDistanceCalculation() {
            const arrivalMileage = parseInt(document.getElementById('arrival_mileage').value) || 0;
            
            if (departureData && arrivalMileage > 0) {
                const departureMileage = parseInt(departureData.departure_mileage);
                const totalDistance = arrivalMileage - departureMileage;
                
                if (totalDistance >= 0) {
                    document.getElementById('distanceDisplay').style.display = 'block';
                    document.getElementById('totalDistance').textContent = totalDistance.toLocaleString();
                    
                    // 異常値チェック
                    if (totalDistance > 500) {
                        document.getElementById('distanceDisplay').style.borderColor = '#dc3545';
                        document.getElementById('totalDistance').style.color = '#dc3545';
                    } else {
                        document.getElementById('distanceDisplay').style.borderColor = '#28a745';
                        document.getElementById('totalDistance').style.color = '#28a745';
                    }
                } else {
                    document.getElementById('distanceDisplay').style.display = 'none';
                }
            } else {
                document.getElementById('distanceDisplay').style.display = 'none';
            }
        }

        // 費用合計計算
        function updateTotalCost() {
            const fuelCost = parseInt(document.getElementById('fuel_cost').value) || 0;
            const tollCost = parseInt(document.getElementById('toll_cost').value) || 0;
            const otherCost = parseInt(document.getElementById('other_cost').value) || 0;
            const total = fuelCost + tollCost + otherCost;
            
            document.getElementById('totalCost').textContent = total.toLocaleString();
        }

        // フォームクリア
        function clearForm() {
            if (confirm('入力内容をクリアしますか？')) {
                document.getElementById('arrivalForm').reset();
                document.getElementById('arrival_date').value = '<?php echo $today; ?>';
                document.getElementById('arrival_time').value = '<?php echo $current_time; ?>';
                document.getElementById('departureInfo').style.display = 'none';
                document.getElementById('distanceDisplay').style.display = 'none';
                departureData = null;
                updateTotalCost();
            }
        }

        // イベントリスナー
        document.getElementById('fuel_cost').addEventListener('input', updateTotalCost);
        document.getElementById('toll_cost').addEventListener('input', updateTotalCost);
        document.getElementById('other_cost').addEventListener('input', updateTotalCost);
        document.getElementById('arrival_mileage').addEventListener('input', updateDistanceCalculation);

        // フォーム送信前の確認
        document.getElementById('arrivalForm').addEventListener('submit', function(e) {
            const driverId = document.getElementById('driver_id').value;
            const vehicleId = document.getElementById('vehicle_id').value;
            const arrivalTime = document.getElementById('arrival_time').value;
            const arrivalMileage = document.getElementById('arrival_mileage').value;
            
            if (!driverId || !vehicleId || !arrivalTime || !arrivalMileage) {
                e.preventDefault();
                alert('すべての必須項目を入力してください。');
                return;
            }
            
            if (departureData) {
                const totalDistance = parseInt(arrivalMileage) - parseInt(departureData.departure_mileage);
                if (totalDistance < 0) {
                    e.preventDefault();
                    alert('入庫メーターが出庫メーターより小さい値です。正しい値を入力してください。');
                    return;
                }
                
                if (totalDistance > 500) {
                    if (!confirm(`走行距離が${totalDistance}kmと多めです。メーター値は正しいですか？`)) {
                        e.preventDefault();
                        return;
                    }
                }
            }
            
            if (!confirm('入庫処理を登録しますか？')) {
                e.preventDefault();
            }
        });

        // ページ読み込み時の初期化
        document.addEventListener('DOMContentLoaded', function() {
            updateTotalCost();
        });
    </script>
</body>
</html>