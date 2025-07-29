<?php
// ride_records.php の修正版 - total_trips エラー解消

session_start();
require_once 'config/database.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_OBJ);

// 新規登録処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    try {
        // total_trips を除外したINSERT文に修正
        $stmt = $pdo->prepare("
            INSERT INTO ride_records (
                driver_id, vehicle_id, ride_date, ride_time, 
                passenger_count, pickup_location, dropoff_location, 
                fare_amount, transportation_type, payment_method, 
                remarks, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $_POST['driver_id'],
            $_POST['vehicle_id'],
            $_POST['ride_date'],
            $_POST['ride_time'],
            $_POST['passenger_count'],
            $_POST['pickup_location'],
            $_POST['dropoff_location'],
            $_POST['fare_amount'],
            $_POST['transportation_type'],
            $_POST['payment_method'],
            $_POST['remarks'] ?? ''
        ]);
        
        $success_message = "乗車記録を登録しました。";
        
    } catch(PDOException $e) {
        $error_message = "登録エラー: " . $e->getMessage();
    }
}

// 復路作成処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_return') {
    try {
        $original_id = $_POST['original_id'];
        
        // 元の記録を取得
        $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE id = ?");
        $stmt->execute([$original_id]);
        $original = $stmt->fetch(PDO::FETCH_OBJ);
        
        if ($original) {
            // 復路作成（乗降地入れ替え）
            $stmt = $pdo->prepare("
                INSERT INTO ride_records (
                    driver_id, vehicle_id, ride_date, ride_time, 
                    passenger_count, pickup_location, dropoff_location, 
                    fare_amount, transportation_type, payment_method, 
                    remarks, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $original->driver_id,
                $original->vehicle_id,
                $original->ride_date,
                date('H:i', strtotime($original->ride_time) + 3600), // 1時間後
                $original->passenger_count,
                $original->dropoff_location, // 乗降地入れ替え
                $original->pickup_location,  // 乗降地入れ替え
                $original->fare_amount,
                $original->transportation_type,
                $original->payment_method,
                '復路: ' . ($original->remarks ?? '')
            ]);
            
            $success_message = "復路を作成しました。";
        }
        
    } catch(PDOException $e) {
        $error_message = "復路作成エラー: " . $e->getMessage();
    }
}

// 編集処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    try {
        // total_trips を除外したUPDATE文
        $stmt = $pdo->prepare("
            UPDATE ride_records SET 
                driver_id = ?, vehicle_id = ?, ride_date = ?, ride_time = ?,
                passenger_count = ?, pickup_location = ?, dropoff_location = ?,
                fare_amount = ?, transportation_type = ?, payment_method = ?,
                remarks = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['driver_id'],
            $_POST['vehicle_id'],
            $_POST['ride_date'],
            $_POST['ride_time'],
            $_POST['passenger_count'],
            $_POST['pickup_location'],
            $_POST['dropoff_location'],
            $_POST['fare_amount'],
            $_POST['transportation_type'],
            $_POST['payment_method'],
            $_POST['remarks'] ?? '',
            $_POST['record_id']
        ]);
        
        $success_message = "乗車記録を更新しました。";
        
    } catch(PDOException $e) {
        $error_message = "更新エラー: " . $e->getMessage();
    }
}

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM ride_records WHERE id = ?");
        $stmt->execute([$_POST['record_id']]);
        
        $success_message = "乗車記録を削除しました。";
        
    } catch(PDOException $e) {
        $error_message = "削除エラー: " . $e->getMessage();
    }
}

// ユーザー・車両データ取得
$users = $pdo->query("SELECT * FROM users WHERE role IN ('運転者', 'システム管理者') ORDER BY name")->fetchAll(PDO::FETCH_OBJ);
$vehicles = $pdo->query("SELECT * FROM vehicles ORDER BY vehicle_number")->fetchAll(PDO::FETCH_OBJ);

// 乗車記録一覧取得（計算型total_trips）
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$records_query = "
    SELECT rr.*, u.name as driver_name, v.vehicle_number,
           (SELECT COUNT(*) FROM ride_records WHERE driver_id = rr.driver_id AND DATE(ride_date) = DATE(rr.ride_date)) as daily_trips
    FROM ride_records rr 
    LEFT JOIN users u ON rr.driver_id = u.id 
    LEFT JOIN vehicles v ON rr.vehicle_id = v.id 
    ORDER BY rr.ride_date DESC, rr.ride_time DESC 
    LIMIT $limit OFFSET $offset
";

$records = $pdo->query($records_query)->fetchAll(PDO::FETCH_OBJ);

// ページング用の総件数取得
$total_records = $pdo->query("SELECT COUNT(*) FROM ride_records")->fetchColumn();
$total_pages = ceil($total_records / $limit);

// 当日の統計情報（計算型）
$today = date('Y-m-d');
$today_stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_rides,
        SUM(passenger_count) as total_passengers,
        SUM(fare_amount) as total_revenue,
        AVG(fare_amount) as avg_fare,
        COUNT(DISTINCT driver_id) as active_drivers,
        COUNT(DISTINCT vehicle_id) as active_vehicles
    FROM ride_records 
    WHERE DATE(ride_date) = ?
");
$today_stats->execute([$today]);
$stats = $today_stats->fetch(PDO::FETCH_OBJ);

// 輸送分類別統計（計算型）
$classification_stats = $pdo->prepare("
    SELECT 
        transportation_type,
        COUNT(*) as count,
        SUM(passenger_count) as passengers,
        SUM(fare_amount) as revenue
    FROM ride_records 
    WHERE DATE(ride_date) = ?
    GROUP BY transportation_type
");
$classification_stats->execute([$today]);
$classification_data = $classification_stats->fetchAll(PDO::FETCH_OBJ);

// 支払方法別統計（計算型）
$payment_stats = $pdo->prepare("
    SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(fare_amount) as amount
    FROM ride_records 
    WHERE DATE(ride_date) = ?
    GROUP BY payment_method
");
$payment_stats->execute([$today]);
$payment_data = $payment_stats->fetchAll(PDO::FETCH_OBJ);
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
        .record-card {
            border-left: 4px solid #0d6efd;
            margin-bottom: 1rem;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
        }
        .classification-badge {
            font-size: 0.75rem;
        }
        .return-btn {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            border: none;
            border-radius: 20px;
        }
        .edit-btn {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            border: none;
            border-radius: 20px;
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-taxi me-2"></i>福祉輸送管理システム
        </a>
        <div class="navbar-nav ms-auto">
            <span class="navbar-text me-3">
                <i class="fas fa-user me-1"></i><?= htmlspecialchars($user->name) ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sign-out-alt me-1"></i>ログアウト
            </a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- メッセージ表示 -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ページヘッダー -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-road me-2"></i>乗車記録管理</h2>
            <p class="text-muted">乗車記録の登録・編集・削除・復路作成が可能です</p>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addRecordModal">
                <i class="fas fa-plus me-2"></i>新規登録
            </button>
        </div>
    </div>

    <!-- 当日統計 -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card stats-card">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-chart-line me-2"></i>本日の実績 (<?= date('Y年m月d日') ?>)
                    </h5>
                    <div class="row">
                        <div class="col-md-2">
                            <div class="text-center">
                                <h4><?= $stats->total_rides ?? 0 ?>回</h4>
                                <small>乗車回数</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <h4><?= $stats->total_passengers ?? 0 ?>名</h4>
                                <small>輸送人員</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <h4>¥<?= number_format($stats->total_revenue ?? 0) ?></h4>
                                <small>総売上</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <h4>¥<?= number_format($stats->avg_fare ?? 0) ?></h4>
                                <small>平均単価</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <h4><?= $stats->active_drivers ?? 0 ?>名</h4>
                                <small>稼働運転者</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <h4><?= $stats->active_vehicles ?? 0 ?>台</h4>
                                <small>稼働車両</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 分類別・支払方法別統計 -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-tags me-2"></i>輸送分類別実績</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($classification_data as $item): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge classification-badge bg-info">
                                <?= htmlspecialchars($item->transportation_type) ?>
                            </span>
                            <span><?= $item->count ?>回 (<?= $item->passengers ?>名)</span>
                            <span class="fw-bold">¥<?= number_format($item->revenue) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-credit-card me-2"></i>支払方法別実績</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($payment_data as $item): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge classification-badge bg-success">
                                <?= htmlspecialchars($item->payment_method) ?>
                            </span>
                            <span><?= $item->count ?>回</span>
                            <span class="fw-bold">¥<?= number_format($item->amount) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 乗車記録一覧 -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-list me-2"></i>乗車記録一覧</h5>
                    <span class="badge bg-primary"><?= $total_records ?>件</span>
                </div>
                <div class="card-body">
                    <?php if (empty($records)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">まだ乗車記録がありません</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <div class="card record-card">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <h6 class="card-title">
                                                <i class="fas fa-map-marker-alt me-1 text-success"></i>
                                                <?= htmlspecialchars($record->pickup_location) ?>
                                                <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                                <i class="fas fa-map-marker-alt me-1 text-danger"></i>
                                                <?= htmlspecialchars($record->dropoff_location) ?>
                                            </h6>
                                            <div class="row">
                                                <div class="col-sm-6">
                                                    <small class="text-muted">
                                                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($record->driver_name) ?>
                                                        <i class="fas fa-car ms-2 me-1"></i><?= htmlspecialchars($record->vehicle_number) ?>
                                                    </small>
                                                </div>
                                                <div class="col-sm-6">
                                                    <small class="text-muted">
                                                        <i class="fas fa-clock me-1"></i><?= date('m/d H:i', strtotime($record->ride_date . ' ' . $record->ride_time)) ?>
                                                        <i class="fas fa-users ms-2 me-1"></i><?= $record->passenger_count ?>名
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <span class="badge bg-info me-2"><?= htmlspecialchars($record->transportation_type) ?></span>
                                                <span class="badge bg-success me-2"><?= htmlspecialchars($record->payment_method) ?></span>
                                                <span class="badge bg-secondary">当日<?= $record->daily_trips ?>回目</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <h5 class="text-primary mb-2">¥<?= number_format($record->fare_amount) ?></h5>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm return-btn" 
                                                        onclick="createReturn(<?= $record->id ?>, '<?= htmlspecialchars($record->dropoff_location, ENT_QUOTES) ?>', '<?= htmlspecialchars($record->pickup_location, ENT_QUOTES) ?>')">
                                                    <i class="fas fa-undo me-1"></i>復路
                                                </button>
                                                <button class="btn btn-sm edit-btn" 
                                                        onclick="editRecord(<?= htmlspecialchars(json_encode($record), ENT_QUOTES) ?>)">
                                                    <i class="fas fa-edit me-1"></i>編集
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" 
                                                        onclick="deleteRecord(<?= $record->id ?>, '<?= htmlspecialchars($record->pickup_location, ENT_QUOTES) ?>')">
                                                    <i class="fas fa-trash me-1"></i>削除
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($record->remarks)): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-comment me-1"></i><?= htmlspecialchars($record->remarks) ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- ページング -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="乗車記録ページング">
                                <ul class="pagination justify-content-center">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 新規登録モーダル -->
<div class="modal fade" id="addRecordModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus me-2"></i>新規乗車記録登録
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">運転者 <span class="text-danger">*</span></label>
                                <select name="driver_id" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($users as $user_option): ?>
                                        <option value="<?= $user_option->id ?>"><?= htmlspecialchars($user_option->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">車両 <span class="text-danger">*</span></label>
                                <select name="vehicle_id" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle->id ?>"><?= htmlspecialchars($vehicle->vehicle_number) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">乗車日 <span class="text-danger">*</span></label>
                                <input type="date" name="ride_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">乗車時間 <span class="text-danger">*</span></label>
                                <input type="time" name="ride_time" class="form-control" value="<?= date('H:i') ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">人員数 <span class="text-danger">*</span></label>
                                <input type="number" name="passenger_count" class="form-control" min="1" max="9" value="1" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">乗車地 <span class="text-danger">*</span></label>
                                <input type="text" name="pickup_location" class="form-control" maxlength="200" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">降車地 <span class="text-danger">*</span></label>
                                <input type="text" name="dropoff_location" class="form-control" maxlength="200" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">運賃・料金 <span class="text-danger">*</span></label>
                                <input type="number" name="fare_amount" class="form-control" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">輸送分類 <span class="text-danger">*</span></label>
                                <select name="transportation_type" class="form-select" required>
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
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">支払方法 <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-select" required>
                                    <option value="">選択してください</option>
                                    <option value="現金">現金</option>
                                    <option value="カード">カード</option>
                                    <option value="その他">その他</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea name="remarks" class="form-control" rows="3" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>登録
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 編集モーダル -->
<div class="modal fade" id="editRecordModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>乗車記録編集
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div
