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
                <div class="step-icon <?= $flow_status['departed'] ? 'step-completed' : 'step-pending' ?>">
                    <i class="fas fa-<?= $flow_status['departed'] ? 'check' : 'truck-pickup' ?>"></i>
                </div>
                <div>
                    <strong>3. 出庫処理</strong>
                    <div class="text-muted small"><?= $flow_status['departed'] ? '完了' : '未実施' ?></div>
                </div>
            </div>
            <div class="flow-step">
                <div class="step-icon <?= $flow_status['in_operation'] ? 'step-current' : ($flow_status['departed'] ? 'step-pending' : 'step-pending') ?>">
                    <i class="fas fa-<?= $flow_status['in_operation'] ? 'car' : 'car' ?>"></i>
                </div>
                <div>
                    <strong>4. 運行（乗車記録）</strong>
                    <div class="text-muted small">
                        <?= $flow_status['in_operation'] ? '運行中' : ($flow_status['departed'] ? '待機中' : '出庫待ち') ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 本日の売上サマリー -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-amount" style="color: #3498db;">¥<?= number_format($summary['total_revenue']) ?></div>
                <div class="summary-label">本日売上</div>
                <div class="text-muted small"><?= $summary['ride_count'] ?>回</div>
            </div>
            <div class="summary-card">
                <div class="summary-amount" style="color: #27ae60;">¥<?= number_format($summary['cash_revenue']) ?></div>
                <div class="summary-label">現金売上</div>
                <div class="text-muted small">集金対象</div>
            </div>
        </div>

        <!-- 乗車記録入力フォーム -->
        <div class="record-form">
            <form method="POST" id="recordForm">
                <input type="hidden" name="action" value="save_record">
                
                <div class="form-section">
                    <h6><i class="fas fa-info-circle me-2"></i>基本情報</h6>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">乗車日</label>
                            <input type="date" name="ride_date" class="form-control" value="<?= $today ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">乗車時刻</label>
                            <input type="time" name="ride_time" class="form-control" value="<?= date('H:i') ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h6><i class="fas fa-map-marker-alt me-2"></i>乗降地情報</h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">乗車場所</label>
                            <input type="text" name="pickup_location" class="form-control" 
                                   placeholder="例：田中様宅" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">降車場所</label>
                            <input type="text" name="destination" class="form-control" 
                                   placeholder="例：○○病院" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h6><i class="fas fa-users me-2"></i>乗客・輸送情報</h6>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">乗客人数</label>
                            <select name="passenger_count" class="form-select" required>
                                <option value="1" selected>1人</option>
                                <option value="2">2人</option>
                                <option value="3">3人</option>
                                <option value="4">4人</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">輸送種類</label>
                            <select name="transportation_type" class="form-select" required>
                                <option value="通院">通院</option>
                                <option value="通所">通所</option>
                                <option value="買い物">買い物</option>
                                <option value="その他">その他</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h6><i class="fas fa-yen-sign me-2"></i>料金情報</h6>
                    <div class="fare-inputs">
                        <div>
                            <label class="form-label">基本料金</label>
                            <input type="number" name="fare" id="fare" class="form-control" 
                                   value="1200" min="0" step="10" onchange="calculateTotal()">
                        </div>
                        <div>
                            <label class="form-label">追加料金</label>
                            <input type="number" name="charge" id="charge" class="form-control" 
                                   value="0" min="0" step="10" onchange="calculateTotal()">
                        </div>
                    </div>
                    <div class="fare-result">
                        <div class="total-fare" id="totalFare">¥1,200</div>
                        <div class="text-muted">合計料金</div>
                    </div>
                </div>

                <div class="form-section">
                    <h6><i class="fas fa-credit-card me-2"></i>支払方法</h6>
                    <select name="payment_method" class="form-select" required>
                        <option value="現金" selected>現金</option>
                        <option value="カード">カード</option>
                    </select>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="fas fa-save me-2"></i>記録保存
                    </button>
                    <button type="button" class="btn btn-success-custom" onclick="quickSave()">
                        <i class="fas fa-bolt me-2"></i>クイック保存
                    </button>
                </div>
            </form>
        </div>

        <!-- 本日の乗車記録一覧 -->
        <div class="records-section">
            <h5 class="mb-3">
                <i class="fas fa-list me-2"></i>本日の乗車記録
                <span class="badge bg-primary ms-2"><?= count($today_records) ?>件</span>
            </h5>
            
            <?php if (empty($today_records)): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-car fa-3x mb-3 opacity-50"></i>
                <p>まだ乗車記録がありません</p>
            </div>
            <?php else: ?>
            <?php foreach ($today_records as $record): ?>
            <div class="record-item">
                <div class="record-time">
                    <i class="fas fa-clock me-2"></i><?= date('H:i', strtotime($record['ride_time'])) ?>
                    <span class="badge bg-secondary ms-2"><?= htmlspecialchars($record['transportation_type']) ?></span>
                </div>
                <div class="record-route">
                    <i class="fas fa-map-marker-alt me-2 text-success"></i>
                    <?= htmlspecialchars($record['pickup_location']) ?>
                    <i class="fas fa-arrow-right mx-2 text-muted"></i>
                    <i class="fas fa-map-marker-alt me-2 text-danger"></i>
                    <?= htmlspecialchars($record['destination']) ?>
                </div>
                <div class="record-details">
                    <div>
                        <span class="record-fare">¥<?= number_format($record['total_fare']) ?></span>
                        <span class="text-muted ms-2">
                            <?= $record['passenger_count'] ?>人・<?= htmlspecialchars($record['payment_method']) ?>
                        </span>
                    </div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="create_return">
                        <input type="hidden" name="original_id" value="<?= $record['id'] ?>">
                        <button type="submit" class="return-btn" 
                                onclick="return confirm('復路を作成しますか？\n乗車場所と降車場所が入れ替わります。')">
                            <i class="fas fa-sync-alt me-1"></i>復路作成
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- 次のフロー案内 -->
        <?php if (!empty($today_records) && $flow_status['in_operation']): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>次のステップ</strong><br>
            運行が完了したら <a href="arrival.php" class="alert-link">入庫処理</a> を行ってください。
        </div>
        <?php endif; ?>
    </div>

    <!-- フローティング追加ボタン -->
    <a href="#recordForm" class="floating-btn" title="乗車記録追加">
        <i class="fas fa-plus"></i>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 料金合計計算
        function calculateTotal() {
            const fare = parseInt(document.getElementById('fare').value) || 0;
            const charge = parseInt(document.getElementById('charge').value) || 0;
            const total = fare + charge;
            
            document.getElementById('totalFare').textContent = '¥' + total.toLocaleString();
        }

        // クイック保存（デフォルト値で即座保存）
        function quickSave() {
            // デフォルト値設定
            const pickupInput = document.querySelector('[name="pickup_location"]');
            const destinationInput = document.querySelector('[name="destination"]');
            
            if (!pickupInput.value) {
                pickupInput.value = '乗車場所';
            }
            if (!destinationInput.value) {
                destinationInput.value = '降車場所';
            }
            
            if (confirm('クイック保存しますか？\n空欄項目にはデフォルト値が入力されます。')) {
                document.getElementById('recordForm').submit();
            }
        }

        // フォーム最適化
        document.addEventListener('DOMContentLoaded', function() {
            // 初回計算
            calculateTotal();
            
            // 乗車場所の入力候補（よく使用される場所）
            const commonPickups = ['田中様宅', '佐藤様宅', '山田様宅', '○○団地', '○○マンション'];
            const commonDestinations = ['○○病院', '△△クリニック', '□□薬局', '○○スーパー', '○○駅'];
            
            // 入力フィールドにオートコンプリート機能を追加
            addAutocomplete('pickup_location', commonPickups);
            addAutocomplete('destination', commonDestinations);
            
            // 乗車時刻の自動調整（30分単位）
            const timeInput = document.querySelector('[name="ride_time"]');
            timeInput.addEventListener('change', function() {
                const time = this.value.split(':');
                const minutes = Math.round(parseInt(time[1]) / 30) * 30;
                const adjustedTime = time[0] + ':' + (minutes < 10 ? '0' : '') + minutes;
                this.value = adjustedTime;
            });

            // フォーム送信前確認
            document.getElementById('recordForm').addEventListener('submit', function(e) {
                const pickup = this.pickup_location.value;
                const destination = this.destination.value;
                const total = document.getElementById('totalFare').textContent;
                
                if (!confirm(`乗車記録を保存しますか？\n\n${pickup} → ${destination}\n${total}`)) {
                    e.preventDefault();
                }
            });

            // 復路作成後の自動スクロール
            if (window.location.hash === '#records') {
                document.querySelector('.records-section').scrollIntoView({ behavior: 'smooth' });
            }
        });

        // オートコンプリート機能
        function addAutocomplete(inputName, suggestions) {
            const input = document.querySelector(`[name="${inputName}"]`);
            const datalist = document.createElement('datalist');
            datalist.id = inputName + '_list';
            
            suggestions.forEach(suggestion => {
                const option = document.createElement('option');
                option.value = suggestion;
                datalist.appendChild(option);
            });
            
            input.setAttribute('list', datalist.id);
            input.parentNode.appendChild(datalist);
        }

        // キーボードショートカット
        document.addEventListener('keydown', function(e) {
            // Ctrl + Enter でクイック保存
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                quickSave();
            }
            
            // Ctrl + N で新規記録（フォームリセット）
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                document.getElementById('recordForm').reset();
                calculateTotal();
            }
        });

        // タッチデバイス最適化
        if ('ontouchstart' in window) {
            const buttons = document.querySelectorAll('button, .btn');
            buttons.forEach(button => {
                button.addEventListener('touchstart', function() {
                    this.style.opacity = '0.7';
                }, {passive: true});
                
                button.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                }, {passive: true});
            });
        }

        // 定期的な自動保存（下書き機能）
        let autoSaveTimer;
        const formInputs = document.querySelectorAll('#recordForm input, #recordForm select');
        
        formInputs.forEach(input => {
            input.addEventListener('input', function() {
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(saveDraft, 2000); // 2秒後に下書き保存
            });
        });

        function saveDraft() {
            const formData = new FormData(document.getElementById('recordForm'));
            const draftData = {};
            
            for (let [key, value] of formData.entries()) {
                if (key !== 'action' && value) {
                    draftData[key] = value;
                }
            }
            
            if (Object.keys(draftData).length > 0) {
                localStorage.setItem('ride_record_draft', JSON.stringify(draftData));
                
                // 下書き保存通知（控えめに）
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed; top: 10px; right: 10px; 
                    background: rgba(0,0,0,0.8); color: white; 
                    padding: 8px 12px; border-radius: 20px; 
                    font-size: 0.8rem; z-index: 9999;
                    opacity: 0; transition: opacity 0.3s;
                `;
                notification.textContent = '下書き保存済み';
                
                document.body.appendChild(notification);
                setTimeout(() => notification.style.opacity = '1', 100);
                setTimeout(() => {
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 300);
                }, 2000);
            }
        }

        // 下書き復元
        function restoreDraft() {
            const draft = localStorage.getItem('ride_record_draft');
            if (draft) {
                try {
                    const draftData = JSON.parse(draft);
                    Object.keys(draftData).forEach(key => {
                        const input = document.querySelector(`[name="${key}"]`);
                        if (input) {
                            input.value = draftData[key];
                        }
                    });
                    calculateTotal();
                    console.log('下書きを復元しました');
                } catch (e) {
                    console.error('下書き復元エラー:', e);
                }
            }
        }

        // ページ読み込み時に下書き復元
        window.addEventListener('load', restoreDraft);

        // フォーム送信成功時に下書きクリア
        document.getElementById('recordForm').addEventListener('submit', function() {
            setTimeout(() => {
                localStorage.removeItem('ride_record_draft');
            }, 1000);
        });
    </script>
</body>
</html>
