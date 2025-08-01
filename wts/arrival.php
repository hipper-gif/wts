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

// ユーザー情報取得（運転者のみ）- 新権限システム対応
function getDrivers($pdo) {
    // is_driver = TRUE のユーザーのみ取得
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 車両情報取得
function getVehicles($pdo) {
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 未入庫の出庫記録取得
function getUnreturnedDepartures($pdo) {
    $stmt = $pdo->query("
        SELECT d.*, u.name as driver_name, v.vehicle_number
        FROM departure_records d
        JOIN users u ON d.driver_id = u.id
        JOIN vehicles v ON d.vehicle_id = v.id
        WHERE d.id NOT IN (SELECT COALESCE(departure_record_id, 0) FROM arrival_records WHERE departure_record_id IS NOT NULL)
        ORDER BY d.departure_date DESC, d.departure_time DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

$drivers = getDrivers($pdo);
$vehicles = getVehicles($pdo);
$unreturned_departures = getUnreturnedDepartures($pdo);

// フォーム処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $departure_record_id = $_POST['departure_record_id'] ?? null;
        $driver_id = $_POST['driver_id'];
        $vehicle_id = $_POST['vehicle_id'];
        $arrival_date = $_POST['arrival_date'];
        $arrival_time = $_POST['arrival_time'];
        $arrival_mileage = $_POST['arrival_mileage'];
        $fuel_cost = $_POST['fuel_cost'] ?? 0;
        $highway_cost = $_POST['highway_cost'] ?? 0;
        $other_cost = $_POST['other_cost'] ?? 0;

        // 出庫メーターを取得して走行距離を計算
        $departure_mileage = 0;
        if ($departure_record_id) {
            $stmt = $pdo->prepare("SELECT departure_mileage FROM departure_records WHERE id = ?");
            $stmt->execute([$departure_record_id]);
            $departure_record = $stmt->fetch(PDO::FETCH_OBJ);
            if ($departure_record) {
                $departure_mileage = $departure_record->departure_mileage;
            }
        }

        $total_distance = $arrival_mileage - $departure_mileage;

        // 入庫記録保存
        $stmt = $pdo->prepare("
            INSERT INTO arrival_records 
            (departure_record_id, driver_id, vehicle_id, arrival_date, arrival_time, arrival_mileage, total_distance, fuel_cost, highway_cost, other_cost, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $departure_record_id,
            $driver_id,
            $vehicle_id,
            $arrival_date,
            $arrival_time,
            $arrival_mileage,
            $total_distance,
            $fuel_cost,
            $highway_cost,
            $other_cost
        ]);

        // 車両の走行距離を更新
        $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?");
        $stmt->execute([$arrival_mileage, $vehicle_id]);

        $success_message = "入庫記録を保存しました。";
        
        // リダイレクトしてフォーム再送信を防ぐ
        header("Location: arrival.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $error_message = "エラーが発生しました: " . $e->getMessage();
        error_log("Arrival record error: " . $e->getMessage());
    }
}

// 成功メッセージの表示
$success_message = isset($_GET['success']) ? "入庫記録を保存しました。" : null;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>入庫処理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Noto Sans JP', sans-serif; background-color: #f8f9fa; }
        .main-container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .card { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 10px; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px 10px 0 0 !important; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); }
        .alert { border-radius: 10px; }
        .unreturned-list { max-height: 300px; overflow-y: auto; }
        .unreturned-item { cursor: pointer; transition: all 0.3s; }
        .unreturned-item:hover { background-color: #e3f2fd; transform: translateX(5px); }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- ヘッダー -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-sign-in-alt text-primary"></i> 入庫処理</h1>
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
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 未入庫一覧 -->
        <?php if (!empty($unreturned_departures)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-exclamation-triangle"></i> 未入庫車両一覧
            </div>
            <div class="card-body p-0">
                <div class="unreturned-list">
                    <?php foreach ($unreturned_departures as $departure): ?>
                    <div class="unreturned-item p-3 border-bottom" onclick="selectDeparture(<?= $departure->id ?>, '<?= htmlspecialchars($departure->driver_name) ?>', '<?= htmlspecialchars($departure->vehicle_number) ?>', <?= $departure->departure_mileage ?>, <?= $departure->vehicle_id ?>, <?= $departure->driver_id ?>)">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($departure->driver_name) ?></strong> - 
                                <span class="text-primary"><?= htmlspecialchars($departure->vehicle_number) ?></span>
                            </div>
                            <div class="text-end">
                                <div><?= $departure->departure_date ?> <?= $departure->departure_time ?></div>
                                <small class="text-muted">出庫メーター: <?= number_format($departure->departure_mileage) ?>km</small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 入庫記録フォーム -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-edit"></i> 入庫記録入力
            </div>
            <div class="card-body">
                <form method="POST" id="arrivalForm">
                    <input type="hidden" id="departure_record_id" name="departure_record_id" value="">
                    
                    <div class="row">
                        <!-- 運転者選択 -->
                        <div class="col-md-6 mb-3">
                            <label for="driver_id" class="form-label">運転者 <span class="text-danger">*</span></label>
                            <select class="form-select" id="driver_id" name="driver_id" required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver->id ?>"><?= htmlspecialchars($driver->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 車両選択 -->
                        <div class="col-md-6 mb-3">
                            <label for="vehicle_id" class="form-label">車両 <span class="text-danger">*</span></label>
                            <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                <option value="">車両を選択</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle->id ?>"><?= htmlspecialchars($vehicle->vehicle_number) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <!-- 入庫日 -->
                        <div class="col-md-6 mb-3">
                            <label for="arrival_date" class="form-label">入庫日 <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="arrival_date" name="arrival_date" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- 入庫時刻 -->
                        <div class="col-md-6 mb-3">
                            <label for="arrival_time" class="form-label">入庫時刻 <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="arrival_time" name="arrival_time" 
                                   value="<?= date('H:i') ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <!-- 入庫メーター -->
                        <div class="col-md-6 mb-3">
                            <label for="arrival_mileage" class="form-label">入庫メーター(km) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="arrival_mileage" name="arrival_mileage" required>
                        </div>

                        <!-- 走行距離（自動計算） -->
                        <div class="col-md-6 mb-3">
                            <label for="total_distance" class="form-label">走行距離(km)</label>
                            <input type="number" class="form-control" id="total_distance" name="total_distance" readonly>
                        </div>
                    </div>

                    <div class="row">
                        <!-- 燃料代 -->
                        <div class="col-md-4 mb-3">
                            <label for="fuel_cost" class="form-label">燃料代(円)</label>
                            <input type="number" class="form-control" id="fuel_cost" name="fuel_cost" value="0">
                        </div>

                        <!-- 高速代 -->
                        <div class="col-md-4 mb-3">
                            <label for="highway_cost" class="form-label">高速代(円)</label>
                            <input type="number" class="form-control" id="highway_cost" name="highway_cost" value="0">
                        </div>

                        <!-- その他費用 -->
                        <div class="col-md-4 mb-3">
                            <label for="other_cost" class="form-label">その他費用(円)</label>
                            <input type="number" class="form-control" id="other_cost" name="other_cost" value="0">
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> 入庫記録を保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 未入庫項目選択時の処理
        function selectDeparture(departureId, driverName, vehicleNumber, departureMileage, vehicleId, driverId) {
            document.getElementById('departure_record_id').value = departureId;
            document.getElementById('driver_id').value = driverId;
            document.getElementById('vehicle_id').value = vehicleId;
            
            // 出庫メーターを保存
            window.departureMileage = departureMileage;
            
            // 走行距離を再計算
            calculateDistance();
            
            // 選択されたアイテムをハイライト
            document.querySelectorAll('.unreturned-item').forEach(item => {
                item.classList.remove('bg-light');
            });
            event.currentTarget.classList.add('bg-light');
        }

        // 走行距離自動計算
        function calculateDistance() {
            const arrivalMileage = parseInt(document.getElementById('arrival_mileage').value) || 0;
            const departureMileage = window.departureMileage || 0;
            const totalDistance = arrivalMileage - departureMileage;
            
            if (totalDistance >= 0) {
                document.getElementById('total_distance').value = totalDistance;
            } else {
                document.getElementById('total_distance').value = '';
            }
        }

        // 入庫メーター変更時に走行距離を再計算
        document.getElementById('arrival_mileage').addEventListener('input', calculateDistance);

        // 現在時刻を自動設定
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                              now.getMinutes().toString().padStart(2, '0');
            document.getElementById('arrival_time').value = timeString;
        });
    </script>
</body>
</html>
