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

// 🆕 自動フロー対応パラメータの処理
$auto_flow_data = null;
if (isset($_GET['auto_flow']) && $_GET['auto_flow'] === '1') {
    $auto_flow_data = [
        'from_page' => $_GET['from'] ?? '',
        'driver_id' => $_GET['driver_id'] ?? '',
        'vehicle_id' => $_GET['vehicle_id'] ?? '',
        'duty_date' => $_GET['duty_date'] ?? date('Y-m-d')
    ];
    
    // 入庫完了を前提とした初期値設定
    $initial_values = [
        'driver_id' => $auto_flow_data['driver_id'],
        'vehicle_id' => $auto_flow_data['vehicle_id'],
        'duty_date' => $auto_flow_data['duty_date'],
        'call_time' => date('H:i')  // 現在時刻を自動設定
    ];
}

// ユーザー情報取得（運転者のみ）- 新権限システム対応
function getDrivers($pdo) {
    // is_driver = TRUE のユーザーのみ取得
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 点呼者取得（新権限システム対応）
function getCallers($pdo) {
    // is_caller = TRUE のユーザーのみ取得
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_caller = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 車両情報取得
function getVehicles($pdo) {
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 🆕 乗務前点呼取得（関連付け用）
function getPreDutyCalls($pdo, $date = null) {
    if (!$date) $date = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT p.id, p.driver_id, p.vehicle_id, p.call_time,
               u.name as driver_name, v.vehicle_number
        FROM pre_duty_calls p
        JOIN users u ON p.driver_id = u.id
        JOIN vehicles v ON p.vehicle_id = v.id
        WHERE p.call_date = ?
        ORDER BY p.call_time DESC
    ");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

$drivers = getDrivers($pdo);
$callers = getCallers($pdo);
$vehicles = getVehicles($pdo);
$pre_duty_calls = getPreDutyCalls($pdo, $auto_flow_data['duty_date'] ?? date('Y-m-d'));

// フォーム処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $driver_id = $_POST['driver_id'];
        $caller_id = $_POST['caller_id'];
        $vehicle_id = $_POST['vehicle_id'];
        $call_date = $_POST['call_date'];
        $call_time = $_POST['call_time'];
        $pre_duty_call_id = $_POST['pre_duty_call_id'] ?? null;

        // 確認事項（7項目）
        $items = [
            'health_condition' => $_POST['health_condition'] ?? 0,
            'driving_ability' => $_POST['driving_ability'] ?? 0, 
            'vehicle_condition' => $_POST['vehicle_condition'] ?? 0,
            'accident_report' => $_POST['accident_report'] ?? 0,
            'route_report' => $_POST['route_report'] ?? 0,
            'equipment_check' => $_POST['equipment_check'] ?? 0,
            'duty_completion' => $_POST['duty_completion'] ?? 0
        ];

        // アルコール検査結果
        $alcohol_level = (float)$_POST['alcohol_level'];
        $alcohol_result = ($alcohol_level == 0.000) ? '検出されず' : '検出';

        // 特記事項
        $remarks = $_POST['remarks'] ?? '';

        // 乗務後点呼記録保存
        $stmt = $pdo->prepare("
            INSERT INTO post_duty_calls 
            (driver_id, caller_id, vehicle_id, call_date, call_time, pre_duty_call_id,
             health_condition, driving_ability, vehicle_condition, accident_report, 
             route_report, equipment_check, duty_completion,
             alcohol_level, alcohol_result, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $driver_id, $caller_id, $vehicle_id, $call_date, $call_time, $pre_duty_call_id,
            $items['health_condition'], $items['driving_ability'], $items['vehicle_condition'],
            $items['accident_report'], $items['route_report'], $items['equipment_check'],
            $items['duty_completion'], $alcohol_level, $alcohol_result, $remarks
        ]);

        $success_message = "乗務後点呼を記録しました。";
        
        // リダイレクトしてフォーム再送信を防ぐ
        header("Location: post_duty_call.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $error_message = "エラーが発生しました: " . $e->getMessage();
        error_log("Post duty call error: " . $e->getMessage());
    }
}

// 成功メッセージの表示
$success_message = isset($_GET['success']) ? "乗務後点呼を記録しました。" : null;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗務後点呼 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Noto Sans JP', sans-serif; background-color: #f8f9fa; }
        .main-container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .card { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 10px; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border-radius: 10px 10px 0 0 !important; }
        .btn-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); border: none; }
        .btn-success:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .form-control:focus { border-color: #28a745; box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25); }
        .alert { border-radius: 10px; }
        .check-item { background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 10px; }
        .check-item:hover { background-color: #e9ecef; }
        .auto-flow-banner { background: linear-gradient(135deg, #17a2b8 0%, #20c997 100%); color: white; padding: 10px 20px; border-radius: 10px; margin-bottom: 20px; }
        .pre-duty-list { max-height: 200px; overflow-y: auto; }
        .pre-duty-item { cursor: pointer; transition: all 0.3s; }
        .pre-duty-item:hover { background-color: #e3f2fd; transform: translateX(5px); }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 🆕 自動フローバナー -->
        <?php if ($auto_flow_data): ?>
        <div class="auto-flow-banner">
            <i class="fas fa-route"></i> 
            <strong>入庫処理からの連続フロー</strong> - 
            <?= $auto_flow_data['from_page'] === 'arrival' ? '入庫処理完了後' : '自動遷移' ?>の乗務後点呼
        </div>
        <?php endif; ?>

        <!-- ヘッダー -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-clipboard-check text-success"></i> 乗務後点呼</h1>
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
                
                <!-- 🆕 乗務完了後のアクション -->
                <div class="mt-3">
                    <a href="dashboard.php" class="btn btn-primary btn-sm">
