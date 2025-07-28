<?php
// 修正版乗車記録システム - ride_records.php
session_start();
require_once 'config/database.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// POSTデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    // 新規乗車記録追加
                    $stmt = $pdo->prepare("
                        INSERT INTO ride_records (
                            driver_id, vehicle_id, ride_date, ride_time, 
                            passenger_count, pickup_location, dropoff_location, 
                            fare, transportation_type, payment_method, remarks
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $_POST['driver_id'],
                        $_POST['vehicle_id'],
                        $_POST['ride_date'],
                        $_POST['ride_time'],
                        $_POST['passenger_count'],
                        $_POST['pickup_location'],
                        $_POST['dropoff_location'],
                        $_POST['fare'],
                        $_POST['transportation_type'],
                        $_POST['payment_method'],
                        $_POST['remarks'] ?? ''
                    ]);
                    
                    $message = "乗車記録を追加しました";
                    break;
                    
                case 'create_return':
                    // 復路作成
                    $original_id = $_POST['original_id'];
                    
                    // 元の記録を取得
                    $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE id = ?");
                    $stmt->execute([$original_id]);
                    $original = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($original) {
                        // 復路記録作成（乗降地を入れ替え）
                        $stmt = $pdo->prepare("
                            INSERT INTO ride_records (
                                driver_id, vehicle_id, ride_date, ride_time,
                                passenger_count, pickup_location, dropoff_location,
                                fare, transportation_type, payment_method, remarks
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $original['driver_id'],
                            $original['vehicle_id'],
                            $original['ride_date'],
                            $_POST['return_time'] ?? $original['ride_time'],
                            $original['passenger_count'],
                            $original['dropoff_location'], // 乗降地入れ替え
                            $original['pickup_location'],   // 乗降地入れ替え
                            $_POST['return_fare'] ?? $original['fare'],
                            $original['transportation_type'],
                            $original['payment_method'],
                            '復路：' . $original['remarks']
                        ]);
                        
                        $message = "復路を作成しました";
                    }
                    break;
                    
                case 'delete':
                    // 削除
                    $stmt = $pdo->prepare("DELETE FROM ride_records WHERE id = ?");
                    $stmt->execute([$_POST['record_id']]);
                    $message = "乗車記録を削除しました";
                    break;
            }
        }
    } catch (PDOException $e) {
        $error = "データベースエラー: " . $e->getMessage();
    }
}

// データ取得
try {
    // ユーザー一覧
    $stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('運転者', 'システム管理者') ORDER BY name");
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 車両一覧
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 乗車記録一覧（今日の分）
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT r.*, u.name as driver_name, v.vehicle_number 
        FROM ride_records r
        LEFT JOIN users u ON r.driver_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.ride_date = ?
        ORDER BY r.ride_time DESC
    ");
    $stmt->execute([$today]);
    $today_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 今日の集計
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_rides,
            SUM(passenger_count) as total_passengers,
            SUM(fare) as total_fare,
            COUNT(CASE WHEN payment_method = '現金' THEN 1 END) as cash_count,
            COUNT(CASE WHEN payment_method = 'カード' THEN 1 END) as card_count,
            SUM(CASE WHEN payment_method = '現金' THEN fare ELSE 0 END) as cash_total,
            SUM(CASE WHEN payment_method = 'カード' THEN fare ELSE 0 END) as card_total
        FROM ride_records 
        WHERE ride_date = ?
    ");
    $stmt->execute([$today]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "データ取得エラー: " . $e->getMessage();
}
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
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .record-card {
            border-left: 4px solid #007bff;
            margin-bottom: 10px;
        }
        .btn-return {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
        }
        .btn-return:hover {
            background: linear-gradient(45deg, #20c997, #28a745);
            color: white;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-taxi me-2"></i>福祉輸送管理システム
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-home me-1"></i>ダッシュボード
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <?php if (isset($message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- 今日の実績 -->
            <div class="col-12">
                <div class="summary-card">
                    <h4><i class="fas fa-chart-line me-2"></i>今日の実績 (<?= date('n月j日') ?>)</h4>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center">
                                <h3><?= $summary['total_rides'] ?? 0 ?></h3>
                                <small>乗車回数</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h3><?= $summary['total_passengers'] ?? 0 ?></h3>
                                <small>乗客数</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h3>¥<?= number_format($summary['total_fare'] ?? 0) ?></h3>
                                <small>総売上</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <h3>現金:<?= $summary['cash_count'] ?? 0 ?> / カード:<?= $summary['card_count'] ?? 0 ?></h3>
                                <small>支払方法別</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- 新規追加フォーム -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-plus me-2"></i>新規乗車記録</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="add">
                            
                            <div class="mb-3">
                                <label class="form-label">運転者</label>
                                <select name="driver_id" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">車両</label>
                                <select name="vehicle_id" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle['id'] ?>"><?= htmlspecialchars($vehicle['vehicle_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">日付</label>
                                        <input type="date" name="ride_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">時刻</label>
                                        <input type="time" name="ride_time" class="form-control" value="<?= date('H:i') ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">人員数</label>
                                <input type="number" name="passenger_count" class="form-control" value="1" min="1" max="4" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">乗車地</label>
                                <input type="text" name="pickup_location" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">降車地</label>
                                <input type="text" name="dropoff_location" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">運賃・料金</label>
                                <input type="number" name="fare" class="form-control" min="0" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">輸送分類</label>
                                        <select name="transportation_type" class="form-select" required>
                                            <option value="">選択</option>
                                            <option value="通院">通院</option>
                                            <option value="外出等">外出等</option>
                                            <option value="退院">退院</option>
                                            <option value="転院">転院</option>
                                            <option value="施設入所">施設入所</option>
                                            <option value="その他">その他</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">支払方法</label>
                                        <select name="payment_method" class="form-select" required>
                                            <option value="">選択</option>
                                            <option value="現金">現金</option>
                                            <option value="カード">カード</option>
                                            <option value="その他">その他</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">備考</label>
                                <input type="text" name="remarks" class="form-control">
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-plus me-2"></i>追加
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 乗車記録一覧 -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-list me-2"></i>今日の乗車記録</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($today_records)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>今日の乗車記録はまだありません</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($today_records as $record): ?>
                                <div class="card record-card mb-2">
                                    <div class="card-body py-2">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <div class="d-flex align-items-center">
                                                    <span class="badge bg-primary me-2"><?= htmlspecialchars($record['ride_time']) ?></span>
                                                    <span class="me-2"><?= htmlspecialchars($record['driver_name']) ?></span>
                                                    <span class="me-2"><?= htmlspecialchars($record['vehicle_number']) ?></span>
                                                    <span class="me-2"><?= $record['passenger_count'] ?>名</span>
                                                </div>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($record['pickup_location']) ?> → 
                                                    <?= htmlspecialchars($record['dropoff_location']) ?>
                                                    (¥<?= number_format($record['fare']) ?> / <?= htmlspecialchars($record['payment_method']) ?>)
                                                </small>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <button class="btn btn-return btn-sm me-1" 
                                                        onclick="createReturn(<?= $record['id'] ?>, '<?= htmlspecialchars($record['pickup_location']) ?>', '<?= htmlspecialchars($record['dropoff_location']) ?>', <?= $record['fare'] ?>)">
                                                    <i class="fas fa-undo-alt me-1"></i>復路
                                                </button>
                                                <button class="btn btn-danger btn-sm"
                                                        onclick="deleteRecord(<?= $record['id'] ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 復路作成モーダル -->
    <div class="modal fade" id="returnModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">復路作成</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_return">
                    <input type="hidden" name="original_id" id="return_original_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">復路時刻</label>
                            <input type="time" name="return_time" id="return_time" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">復路料金</label>
                            <input type="number" name="return_fare" id="return_fare" class="form-control" min="0" required>
                        </div>
                        <div class="alert alert-info">
                            <strong>乗降地が自動的に入れ替わります：</strong><br>
                            <span id="return_route_preview"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-success">復路作成</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function createReturn(id, pickup, dropoff, fare) {
            document.getElementById('return_original_id').value = id;
            document.getElementById('return_time').value = '';
            document.getElementById('return_fare').value = fare;
            document.getElementById('return_route_preview').textContent = dropoff + ' → ' + pickup;
            
            new bootstrap.Modal(document.getElementById('returnModal')).show();
        }
        
        function deleteRecord(id) {
            if (confirm('この乗車記録を削除しますか？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="record_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
