<?php
session_start();

// データベース接続設定
$host = 'localhost';
$dbname = 'twinklemark_wts';
$username = 'twinklemark_taxi';
$password = 'Smiley2525';

$messages = [];
$errors = [];
$setup_complete = false;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>🔧 remarks カラム追加 + 完全版乗車記録システム</h1>";
    echo "<p><strong>実行日時:</strong> " . date('Y-m-d H:i:s') . "</p>";
    
    // Step 1: remarks カラムの存在確認
    echo "<h3>🔍 Step 1: remarks カラム存在確認</h3>";
    
    $stmt = $pdo->query("DESCRIBE ride_records");
    $existing_columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    
    $has_remarks = in_array('remarks', $existing_columns);
    
    if ($has_remarks) {
        $messages[] = "✅ remarks カラムは既に存在します";
    } else {
        $messages[] = "⚠️ remarks カラムが存在しません - 追加が必要";
        
        // Step 2: remarks カラムを追加
        echo "<h3>🔧 Step 2: remarks カラム追加</h3>";
        
        try {
            $pdo->exec("ALTER TABLE ride_records ADD COLUMN remarks TEXT COMMENT '備考・特記事項・安全管理情報'");
            $messages[] = "✅ remarks カラムを正常に追加しました";
            $has_remarks = true;
        } catch (PDOException $e) {
            $errors[] = "❌ remarks カラム追加エラー: " . $e->getMessage();
        }
    }
    
    // Step 3: 完全版システムの準備確認
    if ($has_remarks) {
        $setup_complete = true;
        echo "<h3>✅ Step 3: 完全版システム準備完了</h3>";
        $messages[] = "🎉 完全版乗車記録システムの準備が整いました";
    }
    
} catch (PDOException $e) {
    $errors[] = "❌ データベース接続エラー: " . $e->getMessage();
}

// 結果表示
foreach ($messages as $message) {
    echo "<div style='padding: 10px; margin: 10px 0; background-color: #d4edda; color: #155724; border-left: 4px solid #28a745; border-radius: 3px;'>";
    echo $message;
    echo "</div>";
}

foreach ($errors as $error) {
    echo "<div style='padding: 10px; margin: 10px 0; background-color: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; border-radius: 3px;'>";
    echo $error;
    echo "</div>";
}

if ($setup_complete) {
    echo "<div style='background-color: #d1ecf1; color: #0c5460; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
    echo "<h3>🚀 次のアクション</h3>";
    echo "<p><strong>完全版乗車記録システム</strong>にアクセスして動作確認してください：</p>";
    echo "<p><a href='#complete-system' style='background-color: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>➤ 完全版システムを表示</a></p>";
    echo "</div>";
}
?>

<?php if ($setup_complete): ?>
<div id="complete-system">
<hr style="margin: 40px 0;">

<?php
// 完全版乗車記録システム開始
// 認証チェック（セットアップ時はスキップ）
// if (!isset($_SESSION['user_id'])) {
//     header('Location: index.php');
//     exit;
// }

$errors = [];
$success_message = '';

// 乗車記録新規登録処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_ride') {
    
    $driver_id = (int)$_POST['driver_id'];
    $vehicle_id = (int)$_POST['vehicle_id'];
    $ride_date = $_POST['ride_date'];
    $ride_time = $_POST['ride_time'];
    $passenger_count = (int)$_POST['passenger_count'];
    $pickup_location = trim($_POST['pickup_location']);
    $dropoff_location = trim($_POST['dropoff_location']);
    $fare = (int)$_POST['fare'];
    $transportation_type = $_POST['transportation_type'] ?? '通院';
    $payment_method = $_POST['payment_method'] ?? '現金';
    $remarks = trim($_POST['remarks'] ?? '');
    
    // バリデーション
    if (empty($driver_id)) $errors[] = "運転者を選択してください";
    if (empty($vehicle_id)) $errors[] = "車両を選択してください";
    if (empty($ride_date)) $errors[] = "乗車日を入力してください";
    if (empty($ride_time)) $errors[] = "乗車時間を入力してください";
    if (empty($pickup_location)) $errors[] = "乗車地を入力してください";
    if (empty($dropoff_location)) $errors[] = "降車地を入力してください";
    if ($fare < 0) $errors[] = "運賃は0以上で入力してください";
    if ($passenger_count < 1) $errors[] = "人員数は1以上で入力してください";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 完全版INSERT（remarks 含む）
            $insert_sql = "
                INSERT INTO ride_records (
                    driver_id, vehicle_id, ride_date, ride_time, 
                    passenger_count, pickup_location, dropoff_location, 
                    fare, transportation_type, payment_method, remarks, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $pdo->prepare($insert_sql);
            $result = $stmt->execute([
                $driver_id, $vehicle_id, $ride_date, $ride_time,
                $passenger_count, $pickup_location, $dropoff_location,
                $fare, $transportation_type, $payment_method, $remarks
            ]);
            
            if ($result) {
                $pdo->commit();
                $success_message = "乗車記録を正常に登録しました";
                $_POST = []; // フォームリセット
            } else {
                throw new Exception("乗車記録の登録に失敗しました");
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "登録エラー: " . $e->getMessage();
        }
    }
}

// 復路作成処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_return') {
    $original_id = (int)$_POST['original_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM ride_records WHERE id = ?");
        $stmt->execute([$original_id]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($original) {
            $insert_sql = "
                INSERT INTO ride_records (
                    driver_id, vehicle_id, ride_date, ride_time, 
                    passenger_count, pickup_location, dropoff_location, 
                    fare, transportation_type, payment_method, remarks, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $pdo->prepare($insert_sql);
            $result = $stmt->execute([
                $original['driver_id'], $original['vehicle_id'], 
                $original['ride_date'], $original['ride_time'],
                $original['passenger_count'], 
                $original['dropoff_location'], // 乗降地入れ替え
                $original['pickup_location'],  // 乗降地入れ替え
                $original['fare'], $original['transportation_type'], 
                $original['payment_method'], 
                '復路: ' . ($original['remarks'] ?? '')
            ]);
            
            if ($result) {
                $success_message = "復路を作成しました";
            }
        }
    } catch (Exception $e) {
        $errors[] = "復路作成エラー: " . $e->getMessage();
    }
}

// 乗車記録削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_ride') {
    $ride_id = (int)$_POST['ride_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM ride_records WHERE id = ?");
        if ($stmt->execute([$ride_id])) {
            $success_message = "乗車記録を削除しました";
        }
    } catch (Exception $e) {
        $errors[] = "削除エラー: " . $e->getMessage();
    }
}

// ユーザー・車両データ取得
try {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE role LIKE '%運転者%' ORDER BY name");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $vehicles = [];
}

// 乗車記録一覧取得（完全版・remarks 含む）
try {
    $stmt = $pdo->query("
        SELECT 
            rr.id,
            rr.ride_date,
            rr.ride_time,
            rr.passenger_count,
            rr.pickup_location,
            rr.dropoff_location,
            rr.fare,
            rr.transportation_type,
            rr.payment_method,
            rr.remarks,
            u.name as driver_name,
            v.vehicle_number
        FROM ride_records rr
        LEFT JOIN users u ON rr.driver_id = u.id
        LEFT JOIN vehicles v ON rr.vehicle_id = v.id
        ORDER BY rr.ride_date DESC, rr.ride_time DESC
        LIMIT 50
    ");
    $ride_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ride_records = [];
}

// 統計情報取得
$stats = ['total_rides' => 0, 'total_revenue' => 0, 'today_rides' => 0, 'today_revenue' => 0];

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records");
    $stats['total_rides'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(fare), 0) FROM ride_records");
    $stats['total_revenue'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE ride_date = CURDATE()");
    $stats['today_rides'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(fare), 0) FROM ride_records WHERE ride_date = CURDATE()");
    $stats['today_revenue'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    // 統計取得エラーは警告のみ
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗車記録管理（完全版）</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card-header { 
            background-color: #007cba; 
            color: white; 
        }
        .stats-card { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            border: none; 
        }
        .btn-return { 
            background-color: #28a745; 
            border-color: #28a745; 
            color: white; 
            font-size: 0.8rem; 
            padding: 0.25rem 0.5rem; 
        }
        .btn-return:hover { 
            background-color: #218838; 
            border-color: #1e7e34; 
            color: white; 
        }
        .remarks-column {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .remarks-full {
            max-width: none;
            white-space: normal;
        }
        .complete-system-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
    </style>
</head>
<body class="bg-light">
    <div class="complete-system-header">
        <h1><i class="fas fa-check-circle"></i> 乗車記録管理システム（完全版）</h1>
        <p class="mb-0"><i class="fas fa-info-circle"></i> remarks カラム追加済み - 安全管理・法令遵守対応</p>
    </div>

    <div class="container mt-4">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 統計カード -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <i class="fas fa-chart-line fa-2x mb-2"></i>
                        <h5 class="card-title">総乗車回数</h5>
                        <h3 class="mb-0"><?php echo number_format($stats['total_rides']); ?> 回</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <i class="fas fa-yen-sign fa-2x mb-2"></i>
                        <h5 class="card-title">総売上</h5>
                        <h3 class="mb-0">¥<?php echo number_format($stats['total_revenue']); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <i class="fas fa-calendar-day fa-2x mb-2"></i>
                        <h5 class="card-title">今日の乗車</h5>
                        <h3 class="mb-0"><?php echo number_format($stats['today_rides']); ?> 回</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <i class="fas fa-coins fa-2x mb-2"></i>
                        <h5 class="card-title">今日の売上</h5>
                        <h3 class="mb-0">¥<?php echo number_format($stats['today_revenue']); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- 新規登録フォーム -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-plus"></i> 新規乗車記録登録</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="add_ride">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">運転者 <span class="text-danger">*</span></label>
                            <select name="driver_id" class="form-select" required>
                                <option value="">選択してください</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo (isset($_POST['driver_id']) && $_POST['driver_id'] == $user['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">車両 <span class="text-danger">*</span></label>
                            <select name="vehicle_id" class="form-select" required>
                                <option value="">選択してください</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['id']; ?>" <?php echo (isset($_POST['vehicle_id']) && $_POST['vehicle_id'] == $vehicle['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">乗車日 <span class="text-danger">*</span></label>
                            <input type="date" name="ride_date" class="form-control" 
                                   value="<?php echo $_POST['ride_date'] ?? date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">乗車時間 <span class="text-danger">*</span></label>
                            <input type="time" name="ride_time" class="form-control" 
                                   value="<?php echo $_POST['ride_time'] ?? date('H:i'); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">乗車地 <span class="text-danger">*</span></label>
                            <input type="text" name="pickup_location" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['pickup_location'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">降車地 <span class="text-danger">*</span></label>
                            <input type="text" name="dropoff_location" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['dropoff_location'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">人員数</label>
                            <input type="number" name="passenger_count" class="form-control" 
                                   value="<?php echo $_POST['passenger_count'] ?? '1'; ?>" min="1" max="10">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">運賃 <span class="text-danger">*</span></label>
                            <input type="number" name="fare" class="form-control" 
                                   value="<?php echo $_POST['fare'] ?? ''; ?>" min="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">輸送分類</label>
                            <select name="transportation_type" class="form-select">
                                <option value="通院" <?php echo (($_POST['transportation_type'] ?? '') === '通院') ? 'selected' : ''; ?>>通院</option>
                                <option value="外出等" <?php echo (($_POST['transportation_type'] ?? '') === '外出等') ? 'selected' : ''; ?>>外出等</option>
                                <option value="退院" <?php echo (($_POST['transportation_type'] ?? '') === '退院') ? 'selected' : ''; ?>>退院</option>
                                <option value="転院" <?php echo (($_POST['transportation_type'] ?? '') === '転院') ? 'selected' : ''; ?>>転院</option>
                                <option value="施設入所" <?php echo (($_POST['transportation_type'] ?? '') === '施設入所') ? 'selected' : ''; ?>>施設入所</option>
                                <option value="その他" <?php echo (($_POST['transportation_type'] ?? '') === 'その他') ? 'selected' : ''; ?>>その他</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">支払方法</label>
                            <select name="payment_method" class="form-select">
                                <option value="現金" <?php echo (($_POST['payment_method'] ?? '') === '現金') ? 'selected' : ''; ?>>現金</option>
                                <option value="カード" <?php echo (($_POST['payment_method'] ?? '') === 'カード') ? 'selected' : ''; ?>>カード</option>
                                <option value="その他" <?php echo (($_POST['payment_method'] ?? '') === 'その他') ? 'selected' : ''; ?>>その他</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">備考・特記事項 <i class="fas fa-info-circle text-info" title="乗客の身体状況、安全上の注意事項、サービス向上のためのメモなど"></i></label>
                        <textarea name="remarks" class="form-control" rows="3" 
                                  placeholder="例: 車椅子利用、歩行器使用、血圧薬服用中、酸素ボンベ携帯、転倒リスク高など"><?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?></textarea>
                        <div class="form-text">
                            <i class="fas fa-shield-alt text-warning"></i> 
                            乗客の安全管理・身体状況・特記事項を記録してください（法令遵守・事故防止のため重要）
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 登録
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary ms-2">
                        <i class="fas fa-arrow-left"></i> ダッシュボードに戻る
                    </a>
                </form>
            </div>
        </div>

        <!-- 乗車記録一覧 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> 乗車記録一覧（備考・特記事項表示対応）</h5>
            </div>
            <div class="card-body">
                <?php if (empty($ride_records)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">まだ乗車記録がありません</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>日時</th>
                                    <th>運転者</th>
                                    <th>車両</th>
                                    <th>乗車地</th>
                                    <th>降車地</th>
                                    <th>人員</th>
                                    <th>運賃</th>
                                    <th>分類</th>
                                    <th>支払</th>
                                    <th>備考・特記事項</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ride_records as $record): ?>
                                    <tr>
                                        <td><?php echo date('m/d H:i', strtotime($record['ride_date'] . ' ' . $record['ride_time'])); ?></td>
                                        <td><?php echo htmlspecialchars($record['driver_name'] ?? '不明'); ?></td>
                                        <td><?php echo htmlspecialchars($record['vehicle_number'] ?? '不明'); ?></td>
                                        <td><?php echo htmlspecialchars($record['pickup_location']); ?></td>
                                        <td><?php echo htmlspecialchars($record['dropoff_location']); ?></td>
                                        <td><?php echo $record['passenger_count']; ?>名</td>
                                        <td>¥<?php echo number_format($record['fare']); ?></td>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($record['transportation_type']); ?></span></td>
                                        <td><span class="badge bg-success"><?php echo htmlspecialchars($record['payment_method']); ?></span></td>
                                        <td>
                                            <?php if (!empty($record['remarks'])): ?>
                                                <span class="remarks-column" title="<?php echo htmlspecialchars($record['remarks']); ?>">
                                                    <i class="fas fa-sticky-note text-warning"></i>
                                                    <?php echo htmlspecialchars(mb_substr($record['remarks'], 0, 20) . (mb_strlen($record['remarks']) > 20 ? '...' : '')); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="create_return">
                                                <input type="hidden" name="original_id" value="<?php echo $record['id']; ?>">
                                                <button type="submit" class="btn btn-return btn-sm" title="復路作成">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('この記録を削除しますか？')">
                                                <input type="hidden" name="action" value="delete_ride">
                                                <input type="hidden" name="ride_id" value="<?php echo $record['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="削除">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // フォームバリデーション
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();
        
        // 備考欄のツールチップ
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>

</div>
<?php endif; ?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background-color: #f5f5f5;
}

h1, h3 {
    color: #333;
}

pre {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}

code {
    font-family: monospace;
    font-size: 14px;
}
</style>
