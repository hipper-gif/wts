<?php
session_start();
require_once 'config/database.php';

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$pdo = getDBConnection();

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT permission_level, NAME, is_driver FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// 権限確認（運転者またはAdmin）
if (!$user['is_driver'] && $user['permission_level'] !== 'Admin') {
    header('Location: dashboard.php');
    exit();
}

$today = date('Y-m-d');
$message = '';

// POST処理：乗車記録保存
if ($_POST && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();
        
        if ($_POST['action'] === 'save_record') {
            // 基本情報
            $ride_date = $_POST['ride_date'] ?: $today;
            $ride_time = $_POST['ride_time'];
            $pickup_location = $_POST['pickup_location'];
            $destination = $_POST['destination'];
            $passenger_count = intval($_POST['passenger_count']);
            $transportation_type = $_POST['transportation_type'];
            
            // 料金計算
            $fare = intval($_POST['fare'] ?? 0);
            $charge = intval($_POST['charge'] ?? 0);
            $total_fare = $fare + $charge;
            
            // 支払方法別金額
            $payment_method = $_POST['payment_method'];
            $cash_amount = $payment_method === '現金' ? $total_fare : 0;
            $card_amount = $payment_method === 'カード' ? $total_fare : 0;
            
            // 乗車記録保存
            $stmt = $pdo->prepare("
                INSERT INTO ride_records 
                (ride_date, ride_time, pickup_location, destination, passenger_count, 
                 transportation_type, fare, charge, total_fare, payment_method, 
                 cash_amount, card_amount, driver_id, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            
            $stmt->execute([
                $ride_date, $ride_time, $pickup_location, $destination, $passenger_count,
                $transportation_type, $fare, $charge, $total_fare, $payment_method,
                $cash_amount, $card_amount, $_SESSION['user_id']
            ]);
            
            $record_id = $pdo->lastInsertId();
            
            $pdo->commit();
            $message = '乗車記録を保存しました。';
            
        } elseif ($_POST['action'] === 'create_return') {
            // 復路作成
            $original_id = $_POST['original_id'];
            
            // 元記録取得
            $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE id = ?");
            $stmt->execute([$original_id]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($original) {
                // 復路記録作成（乗降地入れ替え）
                $return_time = date('H:i', strtotime($original['ride_time']) + 1800); // 30分後
                
                $stmt = $pdo->prepare("
                    INSERT INTO ride_records 
                    (ride_date, ride_time, pickup_location, destination, passenger_count, 
                     transportation_type, fare, charge, total_fare, payment_method, 
                     cash_amount, card_amount, driver_id, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                
                $stmt->execute([
                    $original['ride_date'],
                    $return_time,
                    $original['destination'],  // 復路なので乗降地入れ替え
                    $original['pickup_location'],
                    $original['passenger_count'],
                    $original['transportation_type'],
                    $original['fare'],
                    $original['charge'],
                    $original['total_fare'],
                    $original['payment_method'],
                    $original['cash_amount'],
                    $original['card_amount'],
                    $_SESSION['user_id']
                ]);
                
                $pdo->commit();
                $message = '復路を作成しました。';
            }
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        $message = 'エラーが発生しました: ' . $e->getMessage();
    }
}

// 本日の乗車記録取得
$stmt = $pdo->prepare("
    SELECT r.*, u.NAME as driver_name 
    FROM ride_records r 
    LEFT JOIN users u ON r.driver_id = u.id 
    WHERE r.ride_date = ? 
    ORDER BY r.ride_time DESC
");
$stmt->execute([$today]);
$today_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 本日の売上サマリー
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as ride_count,
        COALESCE(SUM(total_fare), 0) as total_revenue,
        COALESCE(SUM(cash_amount), 0) as cash_revenue,
        COALESCE(SUM(card_amount), 0) as card_revenue
    FROM ride_records 
    WHERE ride_date = ?
");
$stmt->execute([$today]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// フロー進行状況確認
$flow_status = [];

// 出庫状況確認
$stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
$stmt->execute([$today]);
$flow_status['departed'] = $stmt->fetchColumn() > 0;

// 運行中ステータス（出庫済み且つ未入庫）
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM departure_records d 
    LEFT JOIN arrival_records a ON d.id = a.departure_record_id 
    WHERE d.departure_date = ? AND a.id IS NULL
");
$stmt->execute([$today]);
$flow_status['in_operation'] = $stmt->fetchColumn() > 0;

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>乗車記録 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            --info-gradient: linear-gradient(135deg, #17a2b8 0%, #20c997 100%);
            --shadow: 0 4px 15px rgba(0,0,0,0.1);
            --border-radius: 15px;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding-bottom: 80px;
        }

        /* ヘッダー */
        .system-header {
            background: var(--primary-gradient);
            color: white;
            padding: 15px 0;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        /* フロー状況表示 */
        .flow-status {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .flow-step {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .flow-step:last-child {
            border-bottom: none;
        }

        .step-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 0.9rem;
        }

        .step-completed {
            background: var(--success-gradient);
            color: white;
        }

        .step-current {
            background: var(--warning-gradient);
            color: white;
        }

        .step-pending {
            background: #e9ecef;
            color: #6c757d;
        }

        /* 売上サマリー */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .summary-amount {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-label {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        /* 入力フォーム */
        .record-form {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-section h6 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            font-size: 1rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        /* 料金入力 */
        .fare-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .fare-result {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            margin-top: 15px;
        }

        .total-fare {
            font-size: 1.8rem;
            font-weight: 700;
            color: #27ae60;
        }

        /* アクションボタン */
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-primary-custom {
            background: var(--primary-gradient);
            border: none;
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            flex: 1;
        }

        .btn-success-custom {
            background: var(--success-gradient);
            border: none;
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            flex: 1;
        }

        /* 記録一覧 */
        .records-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .record-item {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            position: relative;
        }

        .record-item:last-child {
            margin-bottom: 0;
        }

        .record-time {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .record-route {
            color: #7f8c8d;
            margin-bottom: 10px;
        }

        .record-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .record-fare {
            font-size: 1.2rem;
            font-weight: 600;
            color: #27ae60;
        }

        .return-btn {
            background: var(--info-gradient);
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 15px;
            font-size: 0.8rem;
        }

        /* フローティングボタン */
        .floating-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: var(--success-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            z-index: 999;
        }

        /* レスポンシブ */
        @media (max-width: 430px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .fare-inputs {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .record-details {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- システムヘッダー -->
    <div class="system-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-taxi me-2"></i>スマイリーケアタクシー</h1>
                </div>
                <div class="col-auto">
                    <a href="dashboard.php" class="text-white text-decoration-none">
                        <i class="fas fa-tachometer-alt me-1"></i>ダッシュボード
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- ページヘッダー -->
        <div class="page-header">
            <h2><i class="fas fa-car me-3"></i>乗車記録管理</h2>
            <p class="mb-0 text-muted">
                <i class="fas fa-calendar me-2"></i><?= date('Y年m月d日') ?>
                <span class="ms-3"><i class="fas fa-user me-2"></i><?= htmlspecialchars($user['NAME']) ?></span>
            </p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- フロー進行状況 -->
        <div class="flow-status">
            <h6><i class="fas fa-route me-2"></i>運行フロー状況</h6>
            <div class="flow-step">
                <div class="step-icon <?= $flow
