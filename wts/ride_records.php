<?php
/**
 * 乗車記録管理システム - 完全修正版
 * total_trips エラーを解消し、集計値を動的計算に変更
 */

session_start();
require_once 'config/database.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// 🎯 集計関数群（total_tripsの代替）
function getDailyStats($pdo, $date) {
    $query = "
        SELECT 
            COUNT(*) as total_trips,
            SUM(fare) as total_fare,
            SUM(passenger_count) as total_passengers,
            AVG(fare) as avg_fare,
            COUNT(DISTINCT driver_id) as active_drivers,
            COUNT(DISTINCT vehicle_id) as active_vehicles
        FROM ride_records 
        WHERE DATE(ride_date) = ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getDriverTripCount($pdo, $driver_id, $date) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM ride_records 
        WHERE driver_id = ? AND DATE(ride_date) = ?
    ");
    $stmt->execute([$driver_id, $date]);
    return $stmt->fetchColumn();
}

function getVehicleTripCount($pdo, $vehicle_id, $date) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM ride_records 
        WHERE vehicle_id = ? AND DATE(ride_date) = ?
    ");
    $stmt->execute([$vehicle_id, $date]);
    return $stmt->fetchColumn();
}

// 処理分岐
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// 新規登録処理（total_trips除去版）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    try {
        // ✅ total_tripsを除去したINSERT文
        $stmt = $pdo->prepare("
            INSERT INTO ride_records (
                driver_id, vehicle_id, ride_date, ride_time, passenger_count,
                pickup_location, dropoff_location, fare, payment_method,
                transportation_type, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $_POST['driver_id'],
            $_POST['vehicle_id'],
            $_POST['ride_date'],
            $_POST['ride_time'],
            $_POST['passenger_count'],
            $_POST['pickup_location'],
            $_POST['dropoff_location'],
            $_POST['fare'],
            $_POST['payment_method'],
            $_POST['transportation_type'],
            $_POST['remarks'] ?? ''
        ]);
        
        if ($result) {
            $message = "乗車記録を登録しました。";
        }
        
    } catch (Exception $e) {
        $error = "登録エラー: " . $e->getMessage();
    }
}

// 復路作成処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_return') {
    try {
        $original_id = $_POST['original_id'];
        
        // 元の記録を取得
        $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE id = ?");
        $stmt->execute([$original_id]);
        $original = $stmt->fetch();
        
        // 復路作成（乗降地を入れ替え）
        $stmt = $pdo->prepare("
            INSERT INTO ride_records (
                driver_id, vehicle_id, ride_date, ride_time, passenger_count,
                pickup_location, dropoff_location, fare, payment_method,
                transportation_type, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $original['driver_id'],
            $original['vehicle_id'],
            $_POST['return_date'] ?? $original['ride_date'],
            $_POST['return_time'],
            $original['passenger_count'],
            $original['dropoff_location'], // 🔄 乗降地入れ替え
            $original['pickup_location'],   // 🔄 乗降地入れ替え
            $_POST['return_fare'] ?? $original['fare'],
            $original['payment_method'],
            $original['transportation_type'],
            "復路記録（元記録ID: {$original_id}）" . ($_POST['return_remarks'] ?? '')
        ]);
        
        if ($result) {
            $message = "復路記録を作成しました。";
        }
        
    } catch (Exception $e) {
        $error = "復路作成エラー: " . $e->getMessage();
    }
}

// データ取得（total_trips列を除去）
$rides_query = "
    SELECT 
        r.*,
        u.name as driver_name,
        v.vehicle_number
    FROM ride_records r
    JOIN users u ON r.driver_id = u.id
    JOIN vehicles v ON r.vehicle_id = v.id
    ORDER BY r.ride_date DESC, r.ride_time DESC
    LIMIT 50
";

$stmt = $pdo->query($rides_query);
$rides = $stmt->fetchAll();

// 今日の統計取得
$today = date('Y-m-d');
$today_stats = getDailyStats($pdo, $today);

// ユーザー・車両マスタ取得
$drivers = $pdo->query("SELECT id, name FROM users WHERE role = '運転者' OR role = 'システム管理者' ORDER BY name")->fetchAll();
$vehicles = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗車記録管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .stat-value {
            font-weight: bold;
            font-size: 1.1em;
        }
        .return-trip-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8em;
        }
        .trip-counter {
            background: #007bff;
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8em;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
            <div class="container">
                <a class="navbar-brand" href="dashboard.php">
                    <i class="fas fa-route"></i> 乗車記録管理
                </a>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($user['name']) ?>
                    </span>
                    <a href="dashboard.php" class="btn btn-outline-light">
                        <i class="fas fa-home"></i> ダッシュボード
                    </a>
                </div>
            </div>
        </nav>

        <div class="container">
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- 📊 今日の統計（動的計算版） -->
            <div class="stats-card">
                <h4><i class="fas fa-chart-bar"></i> 今日の実績（<?= $today ?>）</h4>
                <div class="row">
                    <div class="col-md-2">
                        <div class="stat-item">
                            <span>総乗車回数:</span>
                            <span class="stat-value"><?= $today_stats['total_trips'] ?>回</span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-item">
                            <span>総売上:</span>
                            <span class="stat-value">¥<?= number_format($today_stats['total_fare']) ?></span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-item">
                            <span>総乗客数:</span>
                            <span class="stat-value"><?= $today_stats['total_passengers'] ?>名</span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-item">
                            <span>平均料金:</span>
                            <span class="stat-value">¥<?= number_format($today_stats['avg_fare']) ?></span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-item">
                            <span>稼働運転者:</span>
                            <span class="stat-value"><?= $today_stats['active_drivers'] ?>名</span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-item">
                            <span>稼働車両:</span>
                            <span class="stat-value"><?= $today_stats['active_vehicles'] ?>台</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 乗車記録フォーム -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-plus-circle"></i> 新規乗車記録</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="?action=add">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label">運転者 <span class="text-danger">*</span></label>
                                <select name="driver_id" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">車両 <span class="text-danger">*</span></label>
                                <select name="vehicle_id" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle['id'] ?>"><?= htmlspecialchars($vehicle['vehicle_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">乗車日 <span class="text-danger">*</span></label>
                                <input type="date" name="ride_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">乗車時間 <span class="text-danger">*</span></label>
                                <input type="time" name="ride_time" class="form-control" value="<?= date('H:i') ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-2">
                                <label class="form-label">人員数 <span class="text-danger">*</span></label>
                                <input type="number" name="passenger_count" class="form-control" value="1" min="1" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">乗車地 <span class="text-danger">*</span></label>
                                <input type="text" name="pickup_location" class="form-control" placeholder="乗車場所を入力" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">降車地 <span class="text-danger">*</span></label>
                                <input type="text" name="dropoff_location" class="form-control" placeholder="降車場所を入力" required>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-3">
                                <label class="form-label">運賃・料金 <span class="text-danger">*</span></label>
                                <input type="number" name="fare" class="form-control" min="0" step="10" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">支払方法</label>
                                <select name="payment_method" class="form-select">
                                    <option value="現金">現金</option>
                                    <option value="カード">カード</option>
                                    <option value="その他">その他</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">輸送分類</label>
                                <select name="transportation_type" class="form-select">
                                    <option value="通院">通院</option>
                                    <option value="外出等">外出等</option>
                                    <option value="退院">退院</option>
                                    <option value="転院">転院</option>
                                    <option value="施設入所">施設入所</option>
                                    <option value="その他">その他</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">備考</label>
                                <input type="text" name="remarks" class="form-control" placeholder="任意">
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> 登録
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> リセット
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 乗車記録一覧 -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-list"></i> 乗車記録一覧</h5>
                </div>
                <div class="card-body">
                    <?php if (count($rides) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>日時</th>
                                        <th>運転者</th>
                                        <th>車両</th>
                                        <th>乗降地</th>
                                        <th>人員</th>
                                        <th>料金</th>
                                        <th>支払</th>
                                        <th>分類</th>
                                        <th>備考</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rides as $ride): ?>
                                        <tr>
                                            <td>
                                                <?= date('m/d', strtotime($ride['ride_date'])) ?><br>
                                                <small class="text-muted"><?= date('H:i', strtotime($ride['ride_time'])) ?></small>
                                                <?php 
                                                // 🎯 動的に当日の回数を計算
                                                $daily_count = getDriverTripCount($pdo, $ride['driver_id'], $ride['ride_date']);
                                                if ($daily_count > 1): 
                                                ?>
                                                    <span class="trip-counter"><?= $daily_count ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($ride['driver_name']) ?></td>
                                            <td><?= htmlspecialchars($ride['vehicle_number']) ?></td>
                                            <td>
                                                <small>
                                                    <i class="fas fa-arrow-up text-success"></i> <?= htmlspecialchars($ride['pickup_location']) ?><br>
                                                    <i class="fas fa-arrow-down text-danger"></i> <?= htmlspecialchars($ride['dropoff_location']) ?>
                                                </small>
                                            </td>
                                            <td><?= $ride['passenger_count'] ?>名</td>
                                            <td>¥<?= number_format($ride['fare']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $ride['payment_method'] === '現金' ? 'success' : ($ride['payment_method'] === 'カード' ? 'primary' : 'secondary') ?>">
                                                    <?= htmlspecialchars($ride['payment_method']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($ride['transportation_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($ride['remarks']) ?></small>
                                            </td>
                                            <td>
                                                <!-- 復路作成ボタン -->
                                                <button class="return-trip-btn" onclick="createReturn(<?= $ride['id'] ?>, '<?= htmlspecialchars($ride['pickup_location']) ?>', '<?= htmlspecialchars($ride['dropoff_location']) ?>', <?= $ride['fare'] ?>)">
                                                    <i class="fas fa-undo"></i> 復路
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">乗車記録がありません。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 復路作成モーダル -->
    <div class="modal fade" id="returnTripModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-undo"></i> 復路作成
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="?action=create_return">
                    <div class="modal-body">
                        <input type="hidden" name="original_id" id="return_original_id">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            乗降地が自動的に入れ替わります。時間と料金を調整してください。
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">復路乗車地（元の降車地）</label>
                            <input type="text" class="form-control" id="return_pickup" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">復路降車地（元の乗車地）</label>
                            <input type="text" class="form-control" id="return_dropoff" readonly>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">復路乗車日</label>
                                <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">復路乗車時間 <span class="text-danger">*</span></label>
                                <input type="time" name="return_time" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">復路料金</label>
                                <input type="number" name="return_fare" class="form-control" id="return_fare" min="0" step="10">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">復路備考</label>
                                <input type="text" name="return_remarks" class="form-control" placeholder="任意">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> キャンセル
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus"></i> 復路作成
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 復路作成機能
        function createReturn(originalId, pickupLocation, dropoffLocation, fare) {
            document.getElementById('return_original_id').value = originalId;
            document.getElementById('return_pickup').value = dropoffLocation; // 🔄 入れ替え
            document.getElementById('return_dropoff').value = pickupLocation;  // 🔄 入れ替え
            document.getElementById('return_fare').value = fare;
            
            // 現在時刻を復路時間に設定
            const now = new Date();
            const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                             now.getMinutes().toString().padStart(2, '0');
            document.querySelector('input[name="return_time"]').value = timeString;
            
            // モーダル表示
            new bootstrap.Modal(document.getElementById('returnTripModal')).show();
        }

        // 🎯 リアルタイム集計更新（5秒間隔）
        setInterval(function() {
            // 統計を更新（AJAX）
            fetch('?action=get_stats&date=' + new Date().toISOString().split('T')[0])
                .then(response => response.json())
                .then(data => {
                    // 統計値を更新
                    document.querySelector('.stats-card .stat-value:nth-of-type(1)').textContent = data.total_trips + '回';
                    document.querySelector('.stats-card .stat-value:nth-of-type(2)').textContent = '¥' + data.total_fare.toLocaleString();
                    // 他の統計値も同様に更新
                })
                .catch(error => console.log('統計更新エラー:', error));
        }, 30000); // 30秒間隔で更新
    </script>
</body>
</html>

<?php
// 🎯 AJAX用統計API（同じファイル内）
if ($_GET['action'] === 'get_stats' && isset($_GET['date'])) {
    header('Content-Type: application/json');
    $stats = getDailyStats($pdo, $_GET['date']);
    echo json_encode($stats);
    exit;
}
?>
