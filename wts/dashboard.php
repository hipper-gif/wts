<?php
session_start();

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];
$today = date('Y-m-d');

// 今日のデータを取得
$today_stats = [
    'pre_duty_calls' => 0,
    'post_duty_calls' => 0,
    'daily_inspections' => 0,
    'departures' => 0,
    'arrivals' => 0,
    'ride_records' => 0,
    'total_revenue' => 0
];

try {
    // 今日の乗務前点呼完了数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_stats['pre_duty_calls'] = $stmt->fetchColumn();
    
    // 今日の乗務後点呼完了数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_stats['post_duty_calls'] = $stmt->fetchColumn();
    
    // 今日の日常点検完了数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date = ?");
    $stmt->execute([$today]);
    $today_stats['daily_inspections'] = $stmt->fetchColumn();
    
    // 新システム: 今日の出庫記録数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_stats['departures'] = $stmt->fetchColumn();
    
    // 新システム: 今日の入庫記録数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute([$today]);
    $today_stats['arrivals'] = $stmt->fetchColumn();
    
    // 新システム: 今日の乗車記録数と売上
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    $today_stats['ride_records'] = $result ? $result['count'] : 0;
    $today_stats['total_revenue'] = $result ? $result['revenue'] : 0;
    
    // 車両情報とアラート
    $stmt = $pdo->query("
        SELECT v.*, 
               DATEDIFF(v.next_inspection_date, CURDATE()) as days_to_inspection
        FROM vehicles v 
        WHERE v.is_active = TRUE 
        ORDER BY v.next_inspection_date ASC
    ");
    $vehicles = $stmt->fetchAll();
    
    // 点検期限アラート（7日以内）
    $alerts = [];
    foreach ($vehicles as $vehicle) {
        if ($vehicle['days_to_inspection'] !== null && $vehicle['days_to_inspection'] <= 7) {
            $alerts[] = [
                'type' => 'inspection',
                'message' => "{$vehicle['vehicle_number']} の定期点検期限が近づいています（{$vehicle['days_to_inspection']}日後）",
                'urgency' => $vehicle['days_to_inspection'] <= 3 ? 'danger' : 'warning'
            ];
        }
    }
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 1.5rem;
            margin: 0;
            font-weight: 600;
        }
        
        .user-info {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
            transition: transform 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0;
        }
        
        .stats-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        
        .quick-action-btn {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1.5rem;
            text-decoration: none;
            color: #333;
            display: block;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.15);
        }
        
        .quick-action-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .alert-item {
            border-radius: 10px;
            border: none;
            margin-bottom: 0.5rem;
        }
        
        .new-system-badge {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        @media (max-width: 768px) {
            .stats-number {
                font-size: 2rem;
            }
            .quick-action-btn {
                padding: 1rem;
            }
            .header h1 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-taxi me-2"></i>福祉輸送管理システム</h1>
                    <div class="user-info">
                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($user_name) ?> 
                        (<?= $user_role === 'admin' ? 'システム管理者' : ($user_role === 'manager' ? '管理者' : '運転者') ?>)
                        | <?= date('Y年n月j日 (D)', strtotime($today)) ?>
                    </div>
                </div>
                <div class="col-auto">
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container mt-4">
        <!-- アラート -->
        <?php if (!empty($alerts)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>重要なお知らせ</h5>
                <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-<?= $alert['urgency'] ?> alert-item">
                    <i class="fas fa-bell me-2"></i>
                    <?= htmlspecialchars($alert['message']) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 新システム移行案内 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-info alert-item">
                    <i class="fas fa-rocket me-2"></i>
                    <strong>新システムのご案内:</strong> 出庫・入庫・乗車記録が分離され、より使いやすくなりました！
                    <span class="new-system-badge">NEW</span>
                </div>
            </div>
        </div>
        
        <!-- 今日の業務状況 -->
        <div class="row mb-4">
            <div class="col-12">
                <h5><i class="fas fa-calendar-day me-2"></i>今日の業務状況</h5>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 col-lg-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stats-number text-primary"><?= $today_stats['pre_duty_calls'] ?></p>
                            <p class="stats-label">乗務前点呼</p>
                        </div>
                        <div class="stats-icon text-primary">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stats-number text-success"><?= $today_stats['daily_inspections'] ?></p>
                            <p class="stats-label">日常点検</p>
                        </div>
                        <div class="stats-icon text-success">
                            <i class="fas fa-tools"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stats-number text-info"><?= $today_stats['departures'] ?></p>
                            <p class="stats-label">出庫記録 <span class="new-system-badge">NEW</span></p>
                        </div>
                        <div class="stats-icon text-info">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stats-number text-warning">¥<?= number_format($today_stats['total_revenue']) ?></p>
                            <p class="stats-label">今日の売上</p>
                        </div>
                        <div class="stats-icon text-warning">
                            <i class="fas fa-yen-sign"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 col-lg-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stats-number text-secondary"><?= $today_stats['arrivals'] ?></p>
                            <p class="stats-label">入庫記録 <span class="new-system-badge">NEW</span></p>
                        </div>
                        <div class="stats-icon text-secondary">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="stats-number text-primary"><?= $today_stats['ride_records'] ?></p>
                            <p class="stats-label">乗車記録 <span class="new-system-badge">NEW</span></p>
                        </div>
                        <div class="stats-icon text-primary">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- クイックアクション -->
        <div class="row mt-4">
            <div class="col-md-6">
                <h5><i class="fas fa-rocket me-2"></i>新システム - クイックアクション</h5>
                
                <a href="departure.php" class="quick-action-btn">
                    <div class="text-center">
                        <div class="quick-action-icon text-primary">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                        <h6 class="mb-1">出庫処理 <span class="new-system-badge">NEW</span></h6>
                        <small class="text-muted">車両の出庫記録（前提条件自動チェック）</small>
                    </div>
                </a>
                
                <a href="ride_records.php" class="quick-action-btn">
                    <div class="text-center">
                        <div class="quick-action-icon text-success">
                            <i class="fas fa-users"></i>
                        </div>
                        <h6 class="mb-1">乗車記録 <span class="new-system-badge">NEW</span></h6>
                        <small class="text-muted">独立した乗車記録（復路作成機能付き）</small>
                    </div>
                </a>
                
                <a href="arrival.php" class="quick-action-btn">
                    <div class="text-center">
                        <div class="quick-action-icon text-info">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <h6 class="mb-1">入庫処理 <span class="new-system-badge">NEW</span></h6>
                        <small class="text-muted">車両の入庫記録（走行距離自動計算）</small>
                    </div>
                </a>
            </div>
            
            <div class="col-md-6">
                <h5><i class="fas fa-clipboard-check me-2"></i>従来システム</h5>
                
                <a href="pre_duty_call.php" class="quick-action-btn">
                    <div class="text-center">
                        <div class="quick-action-icon text-primary">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <h6 class="mb-1">乗務前点呼</h6>
                        <small class="text-muted">出庫前の点呼記録</small>
                    </div>
                </a>
                
                <a href="daily_inspection.php" class="quick-action-btn">
                    <div class="text-center">
                        <div class="quick-action-icon text-success">
                            <i class="fas fa-tools"></i>
                        </div>
                        <h6 class="mb-1">日常点検</h6>
                        <small class="text-muted">車両の日常点検記録</small>
                    </div>
                </a>
                <!-- dashboard.php の従来システム部分に追加 -->
<a href="post_duty_call.php" class="quick-action-btn">
    <div class="text-center">
        <div class="quick-action-icon text-danger">
            <i class="fas fa-clipboard-check"></i>
        </div>
        <h6 class="mb-1">乗務後点呼 <span class="new-system-badge">NEW</span></h6>
        <small class="text-muted">入庫後の点呼記録</small>
    </div>
</a>
                <a href="operation.php" class="quick-action-btn" style="opacity: 0.6;">
                    <div class="text-center">
                        <div class="quick-action-icon text-secondary">
                            <i class="fas fa-route"></i>
                        </div>
                        <h6 class="mb-1">運行記録 <small class="text-muted">(旧版)</small></h6>
                        <small class="text-muted">従来の一体型運行記録</small>
                    </div>
                </a>
            </div>
        </div>
        
        <!-- 車両情報 -->
        <div class="row mt-4">
            <div class="col-12">
                <h5><i class="fas fa-car me-2"></i>車両情報</h5>
                <div class="row">
                    <?php foreach ($vehicles as $vehicle): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="stats-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?= htmlspecialchars($vehicle['vehicle_number']) ?></h6>
                                    <small class="text-muted"><?= htmlspecialchars($vehicle['model'] ?? '車種未設定') ?></small>
                                </div>
                                <div class="text-end">
                                    <?php if ($vehicle['days_to_inspection'] !== null): ?>
                                        <?php if ($vehicle['days_to_inspection'] <= 3): ?>
                                            <span class="badge bg-danger">点検期限: <?= $vehicle['days_to_inspection'] ?>日後</span>
                                        <?php elseif ($vehicle['days_to_inspection'] <= 7): ?>
                                            <span class="badge bg-warning">点検期限: <?= $vehicle['days_to_inspection'] ?>日後</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">点検OK</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">点検日未設定</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>