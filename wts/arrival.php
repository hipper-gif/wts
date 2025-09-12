<!-- 休憩記録（新機能） -->
                    <?= renderSectionHeader('break', '休憩記録', '任意', []) ?>
                    <div class="card mb-4 border-dashed">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="break_location" class="form-label">
                                        <i class="fas fa-map-marker-alt me-1"></i>休憩場所
                                    </label>
                                    <input type="text" class="form-control" id="break_location" name="break_location" 
                                           placeholder="例：○○SA、△△道の駅">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="break_start_time" class="form-label">
                                        <i class="fas fa-play me-1"></i>休憩開始
                                    </label>
                                    <input type="time" class="form-control" id="break_start_time" name="break_start_time">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="break_end_time" class="form-label">
                                        <i class="fas fa-stop me-1"></i>休憩終了
                                    </label>
                                    <input type="time" class="form-control" id="break_end_time" name="break_end_time">
                                </div>
                            </div>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                法定要件ではありませんが、労務管理のため記録を推奨します
                            </div>
                        </div>
                    </div>

                    <!-- 備考 -->
                    <div class="mb-4">
                        <label for="remarks" class="form-label">
                            <i class="fas fa-sticky-note me-1"></i>備考・特記事項
                        </label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="3" 
                                  placeholder="特記事項があれば入力してください"></textarea>
                    </div>

                    <!-- 保存ボタン -->
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save me-2"></i>入庫記録を保存
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 操作ヘルプ -->
        <?= renderSectionHeader('help', '操作ガイド', 'ヘルプ', []) ?>
        <div class="card">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6><i class="fas fa-mouse-pointer me-2 text-primary"></i>未入庫車両から選択</h6>
                        <p class="small text-muted mb-0">上の「未入庫車両一覧」から該当車両をクリックすると、運転者・車両・出庫メーターが自動入力<?php
/**
 * 入庫処理システム v3.1 - 改善実装版
 * 仕様書完全準拠・モダンミニマルデザイン
 * 
 * 改善項目：
 * 1. 統一ヘッダー適用
 * 2. 前提条件チェック機能
 * 3. 7段階業務フロー導線
 * 4. 休憩記録UI実装
 * 5. バリデーション強化
 * 6. モダンミニマルデザイン
 */

session_start();
require_once 'config/database.php';

// セッション確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'ユーザー';
$user_role = $_SESSION['permission_level'] ?? 'User';

// データベース接続
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("データベース接続エラー");
}

// 共通関数
function getDrivers($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

function getVehicles($pdo) {
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

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

// 前提条件チェック（新機能）
function validateDepartureCompleted($pdo, $driver_id, $date) {
    if (!$driver_id || !$date) return false;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM departure_records 
        WHERE driver_id = ? AND departure_date = ?
    ");
    $stmt->execute([$driver_id, $date]);
    return $stmt->fetchColumn() > 0;
}

function checkRideRecordsExist($pdo, $driver_id, $date) {
    if (!$driver_id || !$date) return false;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ride_records 
        WHERE driver_id = ? AND ride_date = ?
    ");
    $stmt->execute([$driver_id, $date]);
    return $stmt->fetchColumn() > 0;
}

// データ取得
$drivers = getDrivers($pdo);
$vehicles = getVehicles($pdo);
$unreturned_departures = getUnreturnedDepartures($pdo);

// メッセージ変数初期化
$success_message = null;
$error_message = null;
$warning_message = null;

// フォーム処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $departure_record_id = $_POST['departure_record_id'] ?? null;
        $driver_id = filter_var($_POST['driver_id'], FILTER_VALIDATE_INT);
        $vehicle_id = filter_var($_POST['vehicle_id'], FILTER_VALIDATE_INT);
        $arrival_date = $_POST['arrival_date'];
        $arrival_time = $_POST['arrival_time'];
        $arrival_mileage = filter_var($_POST['arrival_mileage'], FILTER_VALIDATE_INT);
        $fuel_cost = filter_var($_POST['fuel_cost'], FILTER_VALIDATE_INT) ?? 0;
        $highway_cost = filter_var($_POST['highway_cost'], FILTER_VALIDATE_INT) ?? 0;
        $other_cost = filter_var($_POST['other_cost'], FILTER_VALIDATE_INT) ?? 0;
        
        // 休憩記録（新機能）
        $break_location = trim($_POST['break_location'] ?? '');
        $break_start_time = $_POST['break_start_time'] ?? null;
        $break_end_time = $_POST['break_end_time'] ?? null;
        $remarks = trim($_POST['remarks'] ?? '');

        // バリデーション
        if (!$driver_id || !$vehicle_id || !$arrival_date || !$arrival_time || !$arrival_mileage) {
            throw new Exception('必須項目が入力されていません。');
        }

        // 前提条件チェック
        if (!validateDepartureCompleted($pdo, $driver_id, $arrival_date)) {
            $warning_message = "⚠️ この運転者の出庫記録が見つかりません。出庫処理を先に完了してください。";
        }

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

        // 走行距離の妥当性チェック（バリデーション強化）
        if ($total_distance < 0) {
            throw new Exception('入庫メーターが出庫メーターより小さくなっています。確認してください。');
        }

        if ($total_distance > 1000) {
            $warning_message = "⚠️ 走行距離が1000kmを超えています。正しい値かご確認ください。";
        }

        // 乗車記録の存在確認
        $has_rides = checkRideRecordsExist($pdo, $driver_id, $arrival_date);

        // 入庫記録保存
        $stmt = $pdo->prepare("
            INSERT INTO arrival_records 
            (departure_record_id, driver_id, vehicle_id, arrival_date, arrival_time, arrival_mileage, 
             total_distance, fuel_cost, highway_cost, other_cost, break_location, break_start_time, 
             break_end_time, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
            $other_cost,
            $break_location ?: null,
            $break_start_time ?: null,
            $break_end_time ?: null,
            $remarks ?: null
        ]);

        // 車両の走行距離を更新
        $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?");
        $stmt->execute([$arrival_mileage, $vehicle_id]);

        $success_message = "入庫記録を保存しました。";
        
        // 次のステップへのデータを保持
        $saved_driver_id = $driver_id;
        
        // リダイレクトしてフォーム再送信を防ぐ
        header("Location: arrival.php?success=1&driver_id=" . $driver_id . ($has_rides ? "&has_rides=1" : ""));
        exit;
        
    } catch (Exception $e) {
        $error_message = "エラーが発生しました: " . $e->getMessage();
        error_log("Arrival record error: " . $e->getMessage());
    }
}

// 成功メッセージの表示
if (isset($_GET['success'])) {
    $success_message = "入庫記録を保存しました。";
    $saved_driver_id = $_GET['driver_id'] ?? null;
    $has_rides = isset($_GET['has_rides']);
}

// ページ設定（統一ヘッダー対応）
$page_config = [
    'title' => '入庫処理',
    'icon' => 'sign-in-alt',
    'category' => '日次業務',
    'step' => 5,
    'max_steps' => 7,
    'description' => '入庫時刻・走行距離・費用記録'
];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_config['title'] ?> - 福祉輸送管理システム v3.1</title>
    
    <!-- Bootstrap & Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- PWA対応 -->
    <link rel="manifest" href="/Smiley/taxi/wts/manifest.json">
    <meta name="theme-color" content="#2196F3">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="WTS v3.1">
    
    <!-- モダンミニマルデザイン -->
    <style>
        :root {
            --primary: #2196F3;
            --primary-dark: #1976D2;
            --success: #4CAF50;
            --warning: #FFC107;
            --danger: #F44336;
            --white: #FFFFFF;
            --light-gray: #F8F9FA;
            --medium-gray: #6C757D;
            --dark-gray: #343A40;
            --shadow-light: 0 2px 8px rgba(0,0,0,0.1);
            --shadow-medium: 0 4px 12px rgba(0,0,0,0.15);
            --border-radius: 12px;
        }

        body {
            font-family: 'Noto Sans JP', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: var(--dark-gray);
            line-height: 1.6;
        }

        /* 統一ヘッダー */
        .system-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: var(--shadow-medium);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            margin-bottom: 2rem;
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark-gray);
            margin: 0;
        }

        .page-description {
            color: var(--medium-gray);
            margin: 0.5rem 0 0 0;
            font-size: 0.9rem;
        }

        /* カード */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            margin-bottom: 1.5rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
            font-weight: 600;
            padding: 1rem 1.5rem;
        }

        /* フォーム */
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(33, 150, 243, 0.25);
        }

        .form-label {
            font-weight: 500;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        /* ボタン */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.6rem 1.2rem;
            transition: all 0.2s ease;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #2e7d32 100%);
        }

        .btn-outline-primary {
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        /* アラート */
        .alert {
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            box-shadow: var(--shadow-light);
        }

        .alert-success {
            background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%);
            color: #155724;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fdeaea 0%, #f8d7da 100%);
            color: #721c24;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fffbf0 0%, #fff3cd 100%);
            color: #856404;
        }

        /* 未入庫一覧 */
        .unreturned-item {
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 8px;
            margin: 0.5rem;
            padding: 1rem;
        }

        .unreturned-item:hover {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            transform: translateX(5px);
        }

        .unreturned-item.selected {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        /* 業務フロー進捗 */
        .workflow-progress {
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-light);
        }

        .progress-step {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-radius: 6px;
            margin: 0.25rem 0;
            transition: all 0.2s ease;
        }

        .progress-step.current {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 600;
        }

        .progress-step.completed {
            background: linear-gradient(135deg, var(--success) 0%, #2e7d32 100%);
            color: white;
        }

        .progress-step.pending {
            background: var(--light-gray);
            color: var(--medium-gray);
        }

        /* レスポンシブ */
        @media (max-width: 768px) {
            .container-fluid {
                padding: 1rem;
            }
            
            .page-header {
                padding: 1rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .col-md-6 {
                margin-bottom: 1rem;
            }
        }

        /* PWA対応 */
        @media (display-mode: standalone) {
            .system-header {
                padding-top: env(safe-area-inset-top, 1rem);
            }
        }
    </style>
</head>
<body>
    <!-- システムヘッダー -->
    <div class="system-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <i class="fas fa-<?= $page_config['icon'] ?> me-2 fs-4"></i>
                    <div>
                        <h1 class="h5 mb-0">福祉輸送管理システム v3.1</h1>
                        <small class="opacity-75"><?= $page_config['category'] ?></small>
                    </div>
                </div>
                <div class="d-flex align-items-center">
                    <span class="me-3"><?= htmlspecialchars($user_name) ?></span>
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm me-2">
                        <i class="fas fa-home"></i>
                    </a>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid py-4">
        <!-- ページヘッダー -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h2 class="page-title">
                        <i class="fas fa-<?= $page_config['icon'] ?> me-2"></i>
                        <?= $page_config['title'] ?>
                    </h2>
                    <p class="page-description"><?= $page_config['description'] ?></p>
                    <div class="badge bg-primary">Step <?= $page_config['step'] ?>/<?= $page_config['max_steps'] ?></div>
                </div>
            </div>
        </div>

        <!-- 7段階業務フロー進捗 -->
        <div class="workflow-progress">
            <h6 class="mb-3"><i class="fas fa-route me-2"></i>業務フロー進捗</h6>
            <div class="row g-2">
                <div class="col-12 col-md-auto"><div class="progress-step completed"><i class="fas fa-check me-2"></i>1. 日常点検</div></div>
                <div class="col-12 col-md-auto"><div class="progress-step completed"><i class="fas fa-check me-2"></i>2. 乗務前点呼</div></div>
                <div class="col-12 col-md-auto"><div class="progress-step completed"><i class="fas fa-check me-2"></i>3. 出庫処理</div></div>
                <div class="col-12 col-md-auto"><div class="progress-step completed"><i class="fas fa-check me-2"></i>4. 乗車記録</div></div>
                <div class="col-12 col-md-auto"><div class="progress-step current"><i class="fas fa-car me-2"></i>5. 入庫処理</div></div>
                <div class="col-12 col-md-auto"><div class="progress-step pending"><i class="fas fa-circle me-2"></i>6. 乗務後点呼</div></div>
                <div class="col-12 col-md-auto"><div class="progress-step pending"><i class="fas fa-circle me-2"></i>7. 売上金確認</div></div>
            </div>
        </div>

        <!-- 次のステップへの案内バナー -->
        <?php if ($success_message && isset($saved_driver_id)): ?>
        <div class="alert alert-success border-0 mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle fs-3 me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">入庫処理完了</h5>
                    <p class="mb-0">次は乗務後点呼を行ってください</p>
                </div>
                <a href="post_duty_call.php?driver_id=<?= $saved_driver_id ?>" class="btn btn-success btn-lg">
                    <i class="fas fa-clipboard-check me-2"></i>乗務後点呼へ進む
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- アラート表示 -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($warning_message): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($warning_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 未入庫一覧 -->
        <?php if (!empty($unreturned_departures)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-exclamation-triangle me-2"></i>未入庫車両一覧
                <span class="badge bg-warning ms-2"><?= count($unreturned_departures) ?></span>
            </div>
            <div class="card-body p-0">
                <div style="max-height: 300px; overflow-y: auto;">
                    <?php foreach ($unreturned_departures as $departure): ?>
                    <div class="unreturned-item" onclick="selectDeparture(<?= $departure->id ?>, '<?= htmlspecialchars($departure->driver_name) ?>', '<?= htmlspecialchars($departure->vehicle_number) ?>', <?= $departure->departure_mileage ?>, <?= $departure->vehicle_id ?>, <?= $departure->driver_id ?>)">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="d-block"><?= htmlspecialchars($departure->driver_name) ?></strong>
                                <span class="text-primary fw-bold"><?= htmlspecialchars($departure->vehicle_number) ?></span>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?= $departure->departure_date ?> <?= $departure->departure_time ?></div>
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
                <i class="fas fa-edit me-2"></i>入庫記録入力
            </div>
            <div class="card-body">
                <form method="POST" id="arrivalForm">
                    <input type="hidden" id="departure_record_id" name="departure_record_id" value="">
                    
                    <!-- 基本情報 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label for="total_distance" class="form-label">
                                <i class="fas fa-route me-1"></i>走行距離(km)
                            </label>
                            <input type="number" class="form-control" id="total_distance" name="total_distance" readonly>
                            <div class="form-text">自動計算されます</div>
                        </div>
                    </div>

                    <!-- 費用情報 -->
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <label for="fuel_cost" class="form-label">
                                <i class="fas fa-gas-pump me-1"></i>燃料代(円)
                            </label>
                            <input type="number" class="form-control" id="fuel_cost" name="fuel_cost" value="0" min="0">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="highway_cost" class="form-label">
                                <i class="fas fa-road me-1"></i>高速代(円)
                            </label>
                            <input type="number" class="form-control" id="highway_cost" name="highway_cost" value="0" min="0">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="other_cost" class="form-label">
                                <i class="fas fa-receipt me-1"></i>その他費用(円)
                            </label>
                            <input type="number" class="form-control" id="other_cost" name="other_cost" value="0" min="0">
                        </div>
                    </div>

されます。</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-calculator me-2 text-success"></i>走行距離自動計算</h6>
                        <p class="small text-muted mb-0">入庫メーターを入力すると、出庫メーターとの差から走行距離が自動計算されます。</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-shield-alt me-2 text-warning"></i>前提条件チェック</h6>
                        <p class="small text-muted mb-0">出庫記録が存在しない場合は警告が表示されます。必ず出庫処理を先に完了してください。</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-right me-2 text-info"></i>次のステップ</h6>
                        <p class="small text-muted mb-0">保存完了後、「乗務後点呼」へ進むボタンが表示されます。7段階フローを順序通りに進めてください。</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- カスタムスタイル（統一ヘッダー対応） -->
<style>
.main-content {
    margin-top: 0; /* 統一ヘッダーが既に適用済み */
}

.unreturned-item {
    cursor: pointer;
    transition: all 0.2s ease;
    border-radius: 8px;
    margin: 0.5rem;
    padding: 1rem;
}

.unreturned-item:hover {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    transform: translateX(5px);
}

.unreturned-item.selected {
    background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
    color: white;
}

.border-dashed {
    border: 2px dashed #dee2e6 !important;
}

.form-control.border-warning {
    border-color: #ffc107 !important;
}

.form-control.border-danger {
    border-color: #dc3545 !important;
}

/* レスポンシブ調整 */
@media (max-width: 768px) {
    .btn {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    .col-md-6 {
        margin-bottom: 1rem;
    }
}
</style>

<!-- JavaScript（統一ヘッダー対応） -->
<script>
    // 未入庫項目選択時の処理（改善版）
    function selectDeparture(departureId, driverName, vehicleNumber, departureMileage, vehicleId, driverId) {
        // フォームに値を設定
        document.getElementById('departure_record_id').value = departureId;
        document.getElementById('driver_id').value = driverId;
        document.getElementById('vehicle_id').value = vehicleId;
        
        // 出庫メーターを保存
        window.departureMileage = departureMileage;
        
        // 走行距離を再計算
        calculateDistance();
        
        // ビジュアルフィードバック（改善）
        document.querySelectorAll('.unreturned-item').forEach(item => {
            item.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
        
        // 成功メッセージ表示（統一ヘッダー対応）
        showNotification(`${driverName} (${vehicleNumber}) を選択しました`, 'success');
    }

    // 走行距離自動計算（バリデーション強化）
    function calculateDistance() {
        const arrivalMileage = parseInt(document.getElementById('arrival_mileage').value) || 0;
        const departureMileage = window.departureMileage || 0;
        const totalDistance = arrivalMileage - departureMileage;
        
        const distanceField = document.getElementById('total_distance');
        
        if (totalDistance >= 0) {
            distanceField.value = totalDistance;
            distanceField.className = 'form-control';
            
            // 異常値チェック
            if (totalDistance > 500) {
                distanceField.className = 'form-control border-warning';
                showNotification('走行距離が500kmを超えています。確認してください。', 'warning');
            }
        } else if (arrivalMileage > 0) {
            distanceField.value = '';
            distanceField.className = 'form-control border-danger';
            showNotification('入庫メーターが出庫メーターより小さくなっています。', 'error');
        }
    }

    // 通知メッセージ表示（統一ヘッダー対応）
    function showNotification(message, type = 'info') {
        // 統一ヘッダーの通知システムを使用
        if (typeof window.showSystemNotification === 'function') {
            window.showSystemNotification(message, type);
        } else {
            // フォールバック：シンプルなアラート
            console.log(`[${type.toUpperCase()}] ${message}`);
        }
    }

    // フォームバリデーション強化
    function validateForm() {
        const requiredFields = ['driver_id', 'vehicle_id', 'arrival_date', 'arrival_time', 'arrival_mileage'];
        let isValid = true;

        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (!field.value.trim()) {
                field.classList.add('border-danger');
                isValid = false;
            } else {
                field.classList.remove('border-danger');
            }
        });

        // 走行距離チェック
        const totalDistance = parseInt(document.getElementById('total_distance').value) || 0;
        if (totalDistance < 0) {
            showNotification('走行距離がマイナスになっています。メーター値を確認してください。', 'error');
            return false;
        }

        if (!isValid) {
            showNotification('必須項目が入力されていません。', 'error');
            return false;
        }

        return true;
    }

    // 休憩時間の妥当性チェック
    function validateBreakTime() {
        const startTime = document.getElementById('break_start_time').value;
        const endTime = document.getElementById('break_end_time').value;

        if (startTime && endTime) {
            const start = new Date(`2000-01-01 ${startTime}`);
            const end = new Date(`2000-01-01 ${endTime}`);

            if (end <= start) {
                showNotification('休憩終了時刻は開始時刻より後に設定してください。', 'warning');
                return false;
            }

            const breakMinutes = (end - start) / (1000 * 60);
            if (breakMinutes > 480) { // 8時間
                showNotification('休憩時間が8時間を超えています。確認してください。', 'warning');
            }
        }
        return true;
    }

    // イベントリスナー設定
    document.addEventListener('DOMContentLoaded', function() {
        // 現在時刻を自動設定
        const now = new Date();
        const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                          now.getMinutes().toString().padStart(2, '0');
        document.getElementById('arrival_time').value = timeString;

        // 入庫メーター変更時に走行距離を再計算
        document.getElementById('arrival_mileage').addEventListener('input', calculateDistance);

        // 休憩時間変更時の検証
        document.getElementById('break_start_time').addEventListener('change', validateBreakTime);
        document.getElementById('break_end_time').addEventListener('change', validateBreakTime);

        // フォーム送信時の検証
        document.getElementById('arrivalForm').addEventListener('submit', function(e) {
            if (!validateForm() || !validateBreakTime()) {
                e.preventDefault();
            }
        });

        // リアルタイムバリデーション
        const requiredFields = ['driver_id', 'vehicle_id', 'arrival_date', 'arrival_time', 'arrival_mileage'];
        requiredFields.forEach(fieldId => {
            document.getElementById(fieldId).addEventListener('input', function() {
                this.classList.remove('border-danger');
            });
        });
    });

    // PWA対応：オフライン検知
    window.addEventListener('online', function() {
        showNotification('ネットワークに接続されました', 'success');
    });

    window.addEventListener('offline', function() {
        showNotification('オフラインです。データは一時保存され、接続復旧時に同期されます。', 'warning');
    });

    // ショートカットキー対応
    document.addEventListener('keydown', function(e) {
        // Ctrl+S: 保存
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if (validateForm()) {
                document.getElementById('arrivalForm').submit();
            }
        }
        
        // Ctrl+R: リセット
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            if (confirm('入力内容をリセットしますか？')) {
                document.getElementById('arrivalForm').reset();
                window.departureMileage = 0;
                calculateDistance();
            }
        }
    });
</script>

<?php
// 統一ヘッダーのフッター出力
echo $page_data['html_footer'];
?>
</body>
</html>
                            <label for="driver_id" class="form-label">
                                <i class="fas fa-user me-1"></i>運転者 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="driver_id" name="driver_id" required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver->id ?>"><?= htmlspecialchars($driver->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="vehicle_id" class="form-label">
                                <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                <option value="">車両を選択</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle->id ?>"><?= htmlspecialchars($vehicle->vehicle_number) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- 入庫情報 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label for="arrival_date" class="form-label">
                                <i class="fas fa-calendar me-1"></i>入庫日 <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="arrival_date" name="arrival_date" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="arrival_time" class="form-label">
                                <i class="fas fa-clock me-1"></i>入庫時刻 <span class="text-danger">*</span>
                            </label>
                            <input type="time" class="form-control" id="arrival_time" name="arrival_time" 
                                   value="<?= date('H:i') ?>" required>
                        </div>
                    </div>

                    <!-- メーター情報 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label for="arrival_mileage" class="form-label">
                                <i class="fas fa-tachometer-alt me-1"></i>入庫メーター(km) <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control" id="arrival_mileage" name="arrival_mileage" required>
                        </div>

                        <div class="col-md-6 mb-3">
