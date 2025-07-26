<?php
session_start();

// データベース接続
try {
    $host = 'localhost';
    $dbname = 'twinklemark_wts';
    $username = 'twinklemark_taxi';
    $password = 'Smiley2525';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("データベース接続エラー: " . htmlspecialchars($e->getMessage()));
}

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ユーザー情報取得
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_role = $stmt->fetchColumn();
    
    if (!$user_role) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    $user_role = '運転者';
}

// 今日の日付
$today = date('Y-m-d');
$current_month = date('Y-m');

// 実データのみ取得（サンプルデータ・固定値なし）
$statistics = [
    'today_departures' => 0,
    'today_arrivals' => 0,
    'today_rides' => 0,
    'today_sales' => 0,
    'month_rides' => 0,
    'month_sales' => 0,
    'pending_arrivals' => []
];

// テーブル存在確認用関数
function tableExists($pdo, $tableName) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// カラム存在確認用関数
function columnExists($pdo, $tableName, $columnName) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$tableName} LIKE '{$columnName}'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// 実データ取得（エラー時は0を返す）
try {
    // 1. 出庫記録（実データのみ）
    if (tableExists($pdo, 'departure_records')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
        $stmt->execute([$today]);
        $statistics['today_departures'] = (int)$stmt->fetchColumn();
    }
    
    // 2. 入庫記録（実データのみ）
    if (tableExists($pdo, 'arrival_records')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
        $stmt->execute([$today]);
        $statistics['today_arrivals'] = (int)$stmt->fetchColumn();
    }
    
    // 3. 乗車記録（実データのみ・カラム名を動的に判定）
    if (tableExists($pdo, 'ride_records')) {
        // 料金カラム名を動的に判定
        $fare_column = null;
        $possible_fare_columns = ['fare_amount', 'price', 'amount', 'revenue', 'total_amount'];
        
        foreach ($possible_fare_columns as $col) {
            if (columnExists($pdo, 'ride_records', $col)) {
                $fare_column = $col;
                break;
            }
        }
        
        // 日付カラム名を動的に判定
        $date_column = null;
        $possible_date_columns = ['ride_date', 'date', 'operation_date', 'created_at'];
        
        foreach ($possible_date_columns as $col) {
            if (columnExists($pdo, 'ride_records', $col)) {
                $date_column = $col;
                break;
            }
        }
        
        if ($date_column) {
            // 今日の乗車記録
            if ($fare_column) {
                $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM({$fare_column}), 0) FROM ride_records WHERE DATE({$date_column}) = ?");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*), 0 FROM ride_records WHERE DATE({$date_column}) = ?");
            }
            $stmt->execute([$today]);
            $ride_data = $stmt->fetch();
            $statistics['today_rides'] = (int)($ride_data[0] ?? 0);
            $statistics['today_sales'] = (int)($ride_data[1] ?? 0);
            
            // 月間統計
            if ($fare_column) {
                $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM({$fare_column}), 0) FROM ride_records WHERE DATE_FORMAT({$date_column}, '%Y-%m') = ?");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*), 0 FROM ride_records WHERE DATE_FORMAT({$date_column}, '%Y-%m') = ?");
            }
            $stmt->execute([$current_month]);
            $month_data = $stmt->fetch();
            $statistics['month_rides'] = (int)($month_data[0] ?? 0);
            $statistics['month_sales'] = (int)($month_data[1] ?? 0);
        }
    }
    
    // 旧システムの運行記録も確認（参考用）
    if (tableExists($pdo, 'daily_operations') && $statistics['today_rides'] == 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_operations WHERE operation_date = ?");
        $stmt->execute([$today]);
        $old_operations = (int)$stmt->fetchColumn();
        
        if ($old_operations > 0) {
            $statistics['today_rides'] = $old_operations;
            // 旧システムからの売上データ取得を試行
            if (columnExists($pdo, 'daily_operations', 'total_sales')) {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_sales), 0) FROM daily_operations WHERE operation_date = ?");
                $stmt->execute([$today]);
                $statistics['today_sales'] = (int)$stmt->fetchColumn();
            }
        }
    }
    
    // 4. 未入庫車両（実データのみ）
    if (tableExists($pdo, 'departure_records') && tableExists($pdo, 'arrival_records') && 
        tableExists($pdo, 'vehicles') && tableExists($pdo, 'users')) {
        
        $stmt = $pdo->prepare("
            SELECT v.vehicle_number, d.departure_time, u.name as driver_name, d.id as departure_id
            FROM departure_records d
            JOIN vehicles v ON d.vehicle_id = v.id
            JOIN users u ON d.driver_id = u.id
            LEFT JOIN arrival_records a ON d.id = a.departure_record_id
            WHERE d.departure_date = ? AND a.id IS NULL
            ORDER BY d.departure_time
        ");
        $stmt->execute([$today]);
        $statistics['pending_arrivals'] = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    // エラーログを記録（本番環境では適切なログシステムを使用）
    error_log("統計データ取得エラー: " . $e->getMessage());
}

// デバッグ情報（開発時のみ表示）
$debug_mode = isset($_GET['debug']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - ダッシュボード（実データ版）</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .dashboard-card {
            transition: transform 0.2s ease-in-out;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
        }
        .emergency-section {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            border-radius: 15px;
            animation: pulse-glow 2s infinite;
        }
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 20px rgba(255, 107, 107, 0.3); }
            50% { box-shadow: 0 0 30px rgba(255, 107, 107, 0.6); }
        }
        .function-btn {
            border: none;
            border-radius: 10px;
            padding: 15px;
            margin: 5px 0;
            width: 100%;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            text-align: center;
            color: white;
        }
        .function-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            text-decoration: none;
            color: white;
        }
        .priority-high { background: linear-gradient(135deg, #ff6b6b, #ee5a52); }
        .priority-medium { background: linear-gradient(135deg, #4ecdc4, #44a08d); }
        .priority-normal { background: linear-gradient(135deg, #45b7d1, #96c93d); }
        .priority-low { background: linear-gradient(135deg, #f7971e, #ffd200); }
        .real-data-badge { background: #28a745; color: white; padding: 3px 8px; border-radius: 10px; font-size: 0.8em; }
        .no-data { opacity: 0.6; background: #f8f9fa !important; color: #6c757d !important; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-bus"></i> 福祉輸送管理システム
                <span class="real-data-badge">実データ版</span>
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'ユーザー') ?>
                    <span class="badge bg-light text-dark"><?= htmlspecialchars($user_role) ?></span>
                </span>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i> ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- デバッグ情報（開発時のみ） -->
        <?php if ($debug_mode): ?>
        <div class="alert alert-info">
            <h5>🔍 デバッグ情報</h5>
            <pre><?= htmlspecialchars(print_r($statistics, true)) ?></pre>
            <p><strong>データソース:</strong> 実データベースから直接取得（サンプルデータなし）</p>
        </div>
        <?php endif; ?>

        <!-- 実データ統計表示 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card stat-card dashboard-card">
                    <div class="card-body">
                        <h4 class="card-title">
                            <i class="fas fa-chart-line"></i> 今日の業務状況（実データ）
                            <small class="float-end"><?= date('n月j日(D)') ?></small>
                        </h4>
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h2 class="<?= $statistics['today_departures'] == 0 ? 'text-light' : '' ?>">
                                    <?= $statistics['today_departures'] ?>
                                </h2>
                                <p><i class="fas fa-play-circle"></i> 出庫</p>
                                <?php if ($statistics['today_departures'] == 0): ?>
                                <small class="text-light">データなし</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <h2 class="<?= $statistics['today_arrivals'] == 0 ? 'text-light' : '' ?>">
                                    <?= $statistics['today_arrivals'] ?>
                                </h2>
                                <p><i class="fas fa-stop-circle"></i> 入庫</p>
                                <?php if ($statistics['today_arrivals'] == 0): ?>
                                <small class="text-light">データなし</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <h2 class="<?= $statistics['today_rides'] == 0 ? 'text-light' : '' ?>">
                                    <?= $statistics['today_rides'] ?>
                                </h2>
                                <p><i class="fas fa-users"></i> 乗車回数</p>
                                <?php if ($statistics['today_rides'] == 0): ?>
                                <small class="text-light">データなし</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <h2 class="<?= $statistics['today_sales'] == 0 ? 'text-light' : '' ?>">
                                    ¥<?= number_format($statistics['today_sales']) ?>
                                </h2>
                                <p><i class="fas fa-yen-sign"></i> 売上</p>
                                <?php if ($statistics['today_sales'] == 0): ?>
                                <small class="text-light">データなし</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- データソース表示 -->
                        <div class="mt-3 text-center">
                            <small class="text-light">
                                <i class="fas fa-database"></i> 実データベース連動 
                                | 最終更新: <?= date('H:i:s') ?>
                                <?php if ($debug_mode): ?>
                                | <a href="?" class="text-light">デバッグOFF</a>
                                <?php else: ?>
                                | <a href="?debug=1" class="text-light">デバッグON</a>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 緊急監査対応システム -->
        <?php if (in_array($user_role, ['システム管理者', '管理者'])): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card emergency-section dashboard-card">
                    <div class="card-body">
                        <h4 class="card-title">
                            <i class="fas fa-exclamation-triangle"></i> 緊急監査対応システム
                            <span class="badge bg-warning text-dark ms-2">5分で完了</span>
                        </h4>
                        <p class="mb-3">国土交通省・陸運局の突然の監査に対応。実データを基に完璧な書類を生成</p>
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <a href="emergency_audit_kit.php" class="function-btn priority-high">
                                    <i class="fas fa-shield-alt fa-2x"></i><br>
                                    <strong>緊急監査対応キット</strong><br>
                                    <small>実データから即座に生成</small>
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="adaptive_export_document.php" class="function-btn priority-high">
                                    <i class="fas fa-file-export fa-2x"></i><br>
                                    <strong>適応型出力システム</strong><br>
                                    <small>法定書類一括出力</small>
                                </a>
                            </div>
                            <div class="col-md-4 mb-2">
                                <a href="audit_data_manager.php" class="function-btn priority-high">
                                    <i class="fas fa-database fa-2x"></i><br>
                                    <strong>監査データ管理</strong><br>
                                    <small>実データ整合性確保</small>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 機能メニュー -->
        <div class="row">
            <!-- 日常業務 -->
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-calendar-day"></i> 日常業務</h5>
                    </div>
                    <div class="card-body">
                        <a href="departure.php" class="function-btn priority-medium">
                            <i class="fas fa-play-circle fa-lg"></i> 出庫処理
                        </a>
                        <a href="ride_records.php" class="function-btn priority-medium">
                            <i class="fas fa-users fa-lg"></i> 乗車記録
                            <span class="badge bg-success ms-2">復路作成</span>
                        </a>
                        <a href="arrival.php" class="function-btn priority-medium">
                            <i class="fas fa-stop-circle fa-lg"></i> 入庫処理
                        </a>
                    </div>
                </div>
            </div>

            <!-- 点呼・点検 -->
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-clipboard-check"></i> 点呼・点検</h5>
                    </div>
                    <div class="card-body">
                        <a href="pre_duty_call.php" class="function-btn priority-normal">
                            <i class="fas fa-clipboard-list fa-lg"></i> 乗務前点呼
                        </a>
                        <a href="post_duty_call.php" class="function-btn priority-normal">
                            <i class="fas fa-clipboard-check fa-lg"></i> 乗務後点呼
                        </a>
                        <a href="daily_inspection.php" class="function-btn priority-normal">
                            <i class="fas fa-wrench fa-lg"></i> 日常点検
                        </a>
                        <a href="periodic_inspection.php" class="function-btn priority-normal">
                            <i class="fas fa-cogs fa-lg"></i> 定期点検（3ヶ月）
                        </a>
                    </div>
                </div>
            </div>

            <!-- 管理機能 -->
            <?php if (in_array($user_role, ['システム管理者', '管理者'])): ?>
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="fas fa-chart-bar"></i> 管理・集計</h5>
                    </div>
                    <div class="card-body">
                        <a href="cash_management.php" class="function-btn priority-low">
                            <i class="fas fa-yen-sign fa-lg"></i> 集金管理
                        </a>
                        <a href="annual_report.php" class="function-btn priority-low">
                            <i class="fas fa-file-alt fa-lg"></i> 陸運局提出
                        </a>
                        <a href="accident_management.php" class="function-btn priority-low">
                            <i class="fas fa-exclamation-circle fa-lg"></i> 事故管理
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- システム管理 -->
            <?php if ($user_role === 'システム管理者'): ?>
            <div class="col-lg-6 mb-4">
                <div class="card dashboard-card">
                    <div class="card-header bg-secondary text-white">
                        <h5><i class="fas fa-cog"></i> システム管理</h5>
                    </div>
                    <div class="card-body">
                        <a href="user_management.php" class="function-btn priority-low">
                            <i class="fas fa-users-cog fa-lg"></i> ユーザー管理
                        </a>
                        <a href="vehicle_management.php" class="function-btn priority-low">
                            <i class="fas fa-car fa-lg"></i> 車両管理
                        </a>
                        <a href="check_real_database.php" class="function-btn priority-low">
                            <i class="fas fa-database fa-lg"></i> 実データ確認
                        </a>
                        <a href="fix_table_structure.php" class="function-btn priority-low">
                            <i class="fas fa-tools fa-lg"></i> 構造修正
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 未入庫アラート（実データ） -->
        <?php if (!empty($statistics['pending_arrivals'])): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle"></i> 未入庫車両あり（実データ）</h5>
                    <?php foreach ($statistics['pending_arrivals'] as $pending): ?>
                    <p class="mb-1">
                        <strong><?= htmlspecialchars($pending['vehicle_number']) ?></strong> - 
                        <?= htmlspecialchars($pending['driver_name']) ?> 
                        (出庫: <?= date('H:i', strtotime($pending['departure_time'])) ?>)
                        <a href="arrival.php?departure_id=<?= $pending['departure_id'] ?>" class="btn btn-sm btn-warning ms-2">入庫処理</a>
                    </p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 月間実績（実データ） -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card dashboard-card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-calendar-alt"></i> 月間実績（<?= date('Y年n月') ?>）- 実データ</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <h3 class="text-primary"><?= $statistics['month_rides'] ?></h3>
                                <p>総乗車回数</p>
                                <?php if ($statistics['month_rides'] == 0): ?>
                                <small class="text-muted">まだデータがありません</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-success">¥<?= number_format($statistics['month_sales']) ?></h3>
                                <p>総売上</p>
                                <?php if ($statistics['month_sales'] == 0): ?>
                                <small class="text-muted">まだデータがありません</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-info">¥<?= $statistics['month_rides'] > 0 ? number_format($statistics['month_sales'] / $statistics['month_rides']) : 0 ?></h3>
                                <p>平均単価</p>
                                <?php if ($statistics['month_rides'] == 0): ?>
                                <small class="text-muted">乗車データなし</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 実データ自動更新（2分ごと）
        setTimeout(function() {
            location.reload();
        }, 120000);
        
        // カードアニメーション
        document.addEventListener("DOMContentLoaded", function() {
            const cards = document.querySelectorAll(".dashboard-card");
            cards.forEach((card, index) => {
                card.style.opacity = "0";
                card.style.transform = "translateY(20px)";
                setTimeout(() => {
                    card.style.transition = "all 0.5s ease";
                    card.style.opacity = "1";
                    card.style.transform = "translateY(0)";
                }, index * 100);
            });
        });
    </script>
</body>
</html>
