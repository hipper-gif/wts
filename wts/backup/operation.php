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

$success_message = '';
$error_message = '';

// 車両とドライバーの取得
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model, current_mileage FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE role IN ('driver', 'manager', 'admin') AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $vehicles = [];
    $drivers = [];
}

// 今日の運行記録があるかチェック
$existing_operation = null;
$ride_records = [];
if ($_GET['driver_id'] ?? null && $_GET['vehicle_id'] ?? null) {
    $stmt = $pdo->prepare("SELECT * FROM daily_operations WHERE driver_id = ? AND vehicle_id = ? AND operation_date = ?");
    $stmt->execute([$_GET['driver_id'], $_GET['vehicle_id'], $today]);
    $existing_operation = $stmt->fetch();
    
    if ($existing_operation) {
        // 乗車記録も取得
        $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE operation_id = ? ORDER BY ride_number");
        $stmt->execute([$existing_operation['id']]);
        $ride_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_operation') {
        // 運行記録の保存
        $driver_id = $_POST['driver_id'];
        $vehicle_id = $_POST['vehicle_id'];
        $weather = $_POST['weather'];
        $departure_time = $_POST['departure_time'];
        $return_time = $_POST['return_time'];
        $departure_mileage = $_POST['departure_mileage'];
        $return_mileage = $_POST['return_mileage'];
        $break_location = $_POST['break_location'];
        $break_start_time = $_POST['break_start_time'];
        $break_end_time = $_POST['break_end_time'];
        $fuel_cost = $_POST['fuel_cost'] ?? 0;
        $highway_cost = $_POST['highway_cost'] ?? 0;
        $other_costs = $_POST['other_costs'] ?? 0;
        $remarks = $_POST['remarks'];
        
        // 走行距離計算
        $total_distance = $return_mileage && $departure_mileage ? ($return_mileage - $departure_mileage) : 0;
        
        try {
            $stmt = $pdo->prepare("SELECT id FROM daily_operations WHERE driver_id = ? AND vehicle_id = ? AND operation_date = ?");
            $stmt->execute([$driver_id, $vehicle_id, $today]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 更新
                $sql = "UPDATE daily_operations SET 
                    weather = ?, departure_time = ?, return_time = ?, 
                    departure_mileage = ?, return_mileage = ?, total_distance = ?,
                    break_location = ?, break_start_time = ?, break_end_time = ?,
                    fuel_cost = ?, highway_cost = ?, other_costs = ?, remarks = ?,
                    updated_at = NOW()
                    WHERE driver_id = ? AND vehicle_id = ? AND operation_date = ?";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $weather, $departure_time, $return_time,
                    $departure_mileage, $return_mileage, $total_distance,
                    $break_location, $break_start_time, $break_end_time,
                    $fuel_cost, $highway_cost, $other_costs, $remarks,
                    $driver_id, $vehicle_id, $today
                ]);
                
                $operation_id = $existing['id'];
                $success_message = '運行記録を更新しました。';
            } else {
                // 新規挿入
                $sql = "INSERT INTO daily_operations (
                    driver_id, vehicle_id, operation_date, weather,
                    departure_time, return_time, departure_mileage, return_mileage, total_distance,
                    break_location, break_start_time, break_end_time,
                    fuel_cost, highway_cost, other_costs, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $driver_id, $vehicle_id, $today, $weather,
                    $departure_time, $return_time, $departure_mileage, $return_mileage, $total_distance,
                    $break_location, $break_start_time, $break_end_time,
                    $fuel_cost, $highway_cost, $other_costs, $remarks
                ]);
                
                $operation_id = $pdo->lastInsertId();
                $success_message = '運行記録を登録しました。';
            }
            
            // 車両の走行距離を更新
            if ($return_mileage) {
                $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?");
                $stmt->execute([$return_mileage, $vehicle_id]);
            }
            
            // 記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM daily_operations WHERE id = ?");
            $stmt->execute([$operation_id]);
            $existing_operation = $stmt->fetch();
            
        } catch (Exception $e) {
            $error_message = '運行記録の保存中にエラーが発生しました: ' . $e->getMessage();
            error_log("Operation error: " . $e->getMessage());
        }
        
    } elseif ($action === 'add_ride') {
        // 乗車記録の追加
        $operation_id = $_POST['operation_id'];
        $ride_time = $_POST['ride_time'];
        $passenger_count = $_POST['passenger_count'];
        $pickup_location = $_POST['pickup_location'];
        $dropoff_location = $_POST['dropoff_location'];
        $fare = $_POST['fare'];
        $transport_type = $_POST['transport_type'];
        $payment_method = $_POST['payment_method'] ?? '現金';
        $notes = $_POST['notes'];
        
        try {
            // 次の乗車番号を取得
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(ride_number), 0) + 1 as next_number FROM ride_records WHERE operation_id = ?");
            $stmt->execute([$operation_id]);
            $next_number = $stmt->fetchColumn();
            
            $sql = "INSERT INTO ride_records (
                operation_id, ride_number, ride_time, passenger_count,
                pickup_location, dropoff_location, fare, transport_type,
                payment_method, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $operation_id, $next_number, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare, $transport_type,
                $payment_method, $notes
            ]);
            
            $success_message = '乗車記録を追加しました。';
            
            // 乗車記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE operation_id = ? ORDER BY ride_number");
            $stmt->execute([$operation_id]);
            $ride_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error_message = '乗車記録の追加中にエラーが発生しました: ' . $e->getMessage();
            error_log("Ride record error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>運行記録 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .form-card-header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 15px 15px 0 0;
            margin: 0;
        }
        
        .form-card-body {
            padding: 1.5rem;
        }
        
        .ride-record {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .ride-number {
            background: #17a2b8;
            color: white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
        }
        
        .required-mark {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-route me-2"></i>運行記録</h1>
                    <small><?= date('Y年n月j日 (D)') ?></small>
                </div>
                <div class="col-auto">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>ダッシュボード
                    </a>
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
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- 基本情報・運行記録 -->
        <form method="POST" id="operationForm">
            <input type="hidden" name="action" value="save_operation">
            
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-info-circle me-2"></i>基本情報・運行記録
                </h5>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">運転者 <span class="required-mark">*</span></label>
                            <select class="form-select" name="driver_id" required>
                                <option value="">選択してください</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" <?= ($existing_operation && $existing_operation['driver_id'] == $driver['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">車両 <span class="required-mark">*</span></label>
                            <select class="form-select" name="vehicle_id" required onchange="updateMileage()">
                                <option value="">選択してください</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?= $vehicle['id'] ?>" 
                                        data-mileage="<?= $vehicle['current_mileage'] ?>"
                                        <?= ($existing_operation && $existing_operation['vehicle_id'] == $vehicle['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                    <?= $vehicle['model'] ? ' (' . htmlspecialchars($vehicle['model']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">天候</label>
                            <select class="form-select" name="weather">
                                <option value="">選択してください</option>
                                <?php 
                                $weather_options = ['晴', '曇', '雨', '雪', '霧'];
                                foreach ($weather_options as $weather): 
                                ?>
                                <option value="<?= $weather ?>" <?= ($existing_operation && $existing_operation['weather'] == $weather) ? 'selected' : '' ?>>
                                    <?= $weather ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">出庫時刻</label>
                            <input type="time" class="form-control" name="departure_time" 
                                   value="<?= $existing_operation ? $existing_operation['departure_time'] : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">入庫時刻</label>
                            <input type="time" class="form-control" name="return_time" 
                                   value="<?= $existing_operation ? $existing_operation['return_time'] : '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">出庫メーター</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="departure_mileage" id="departure_mileage"
                                       value="<?= $existing_operation ? $existing_operation['departure_mileage'] : '' ?>"
                                       onchange="calculateDistance()">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">入庫メーター</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="return_mileage" id="return_mileage"
                                       value="<?= $existing_operation ? $existing_operation['return_mileage'] : '' ?>"
                                       onchange="calculateDistance()">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">走行距離</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="total_distance" readonly
                                       value="<?= $existing_operation ? $existing_operation['total_distance'] : '' ?>">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">燃料代</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="fuel_cost" 
                                       value="<?= $existing_operation ? $existing_operation['fuel_cost'] : '0' ?>">
                                <span class="input-group-text">円</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <button type="submit" class="btn btn-info btn-lg">
                            <i class="fas fa-save me-2"></i>
                            <?= $existing_operation ? '運行記録を更新' : '運行記録を登録' ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
        
        <!-- 乗車記録 -->
        <?php if ($existing_operation): ?>
        <div class="form-card">
            <h5 class="form-card-header">
                <i class="fas fa-users me-2"></i>乗車記録
                <div class="float-end">
                    <button type="button" class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#rideModal">
                        <i class="fas fa-plus me-1"></i>乗車記録追加
                    </button>
                </div>
            </h5>
            <div class="form-card-body">
                <?php if (empty($ride_records)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>まだ乗車記録がありません。</p>
                </div>
                <?php else: ?>
                <?php foreach ($ride_records as $ride): ?>
                <div class="ride-record">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="ride-number"><?= $ride['ride_number'] ?></div>
                        </div>
                        <div class="col">
                            <strong><?= htmlspecialchars($ride['pickup_location']) ?></strong> 
                            → <strong><?= htmlspecialchars($ride['dropoff_location']) ?></strong>
                            <br>
                            <small><?= $ride['ride_time'] ?> | <?= $ride['passenger_count'] ?>名 | ¥<?= number_format($ride['fare']) ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="row mt-3">
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="h4"><?= count($ride_records) ?></div>
                            <small>総回数</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="h4"><?= array_sum(array_column($ride_records, 'passenger_count')) ?></div>
                            <small>総人数</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="h4">¥<?= number_format(array_sum(array_column($ride_records, 'fare'))) ?></div>
                            <small>総売上</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="h4"><?= $existing_operation['total_distance'] ?? 0 ?>km</div>
                            <small>走行距離</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 乗車記録追加モーダル -->
    <div class="modal fade" id="rideModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">乗車記録追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="rideForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_ride">
                        <input type="hidden" name="operation_id" value="<?= $existing_operation['id'] ?? '' ?>">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">乗車時間 <span class="required-mark">*</span></label>
                                <input type="time" class="form-control" name="ride_time" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">人員 <span class="required-mark">*</span></label>
                                <input type="number" class="form-control" name="passenger_count" min="1" value="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">乗車地 <span class="required-mark">*</span></label>
                                <input type="text" class="form-control" name="pickup_location" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">降車地 <span class="required-mark">*</span></label>
                                <input type="text" class="form-control" name="dropoff_location" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">運賃・料金 <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="fare" min="0" required>
                                    <span class="input-group-text">円</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">輸送分類 <span class="required-mark">*</span></label>
                                <select class="form-select" name="transport_type" required>
                                    <option value="">選択してください</option>
                                    <option value="通院">通院</option>
                                    <option value="外出等">外出等</option>
                                    <option value="退院">退院</option>
                                    <option value="転院">転院</option>
                                    <option value="施設入所">施設入所</option>
                                    <option value="その他">その他</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">追加</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateMileage() {
            const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
            const departureMileageInput = document.getElementById('departure_mileage');
            
            if (vehicleSelect.value) {
                const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
                const currentMileage = selectedOption.getAttribute('data-mileage');
                
                if (currentMileage && !departureMileageInput.value) {
                    departureMileageInput.value = currentMileage;
                    calculateDistance();
                }
            }
        }
        
        function calculateDistance() {
            const departure = document.getElementById('departure_mileage').value;
            const returnMileage = document.getElementById('return_mileage').value;
            const totalDistance = document.getElementById('total_distance');
            
            if (departure && returnMileage) {
                const distance = parseInt(returnMileage) - parseInt(departure);
                totalDistance.value = distance >= 0 ? distance : 0;
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            updateMileage();
            calculateDistance();
        });
    </script>
</body>
</html>