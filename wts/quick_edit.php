<?php
session_start();
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$task = $_GET['task'] ?? 'overview';
$message = '';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'fix_duplicate_rides') {
            // 重複乗車記録の削除
            $sql = "DELETE r1 FROM ride_records r1, ride_records r2 
                    WHERE r1.id > r2.id 
                    AND r1.ride_date = r2.ride_date 
                    AND r1.ride_time = r2.ride_time 
                    AND r1.driver_id = r2.driver_id 
                    AND r1.pickup_location = r2.pickup_location 
                    AND r1.dropoff_location = r2.dropoff_location";
            $stmt = $pdo->exec($sql);
            $message = "{$stmt}件の重複乗車記録を削除しました。";
            
        } elseif ($action === 'fix_zero_amounts') {
            // 金額0円の記録を修正
            $default_fare = $_POST['default_fare'] ?? 1000;
            $sql = "UPDATE ride_records SET fare = ? WHERE fare = 0 OR fare IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$default_fare]);
            $message = "{$stmt->rowCount()}件の金額を{$default_fare}円に修正しました。";
            
        } elseif ($action === 'delete_test_data') {
            // テストデータの削除
            $test_keywords = $_POST['test_keywords'] ?? 'テスト,test,サンプル,sample';
            $keywords = explode(',', $test_keywords);
            
            $deleted_total = 0;
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (empty($keyword)) continue;
                
                $sql = "DELETE FROM ride_records WHERE 
                        pickup_location LIKE ? OR dropoff_location LIKE ? OR notes LIKE ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"]);
                $deleted_total += $stmt->rowCount();
            }
            $message = "テストデータ {$deleted_total}件を削除しました。";
            
        } elseif ($action === 'update_old_records') {
            // 古いレコードの一括更新
            $cutoff_date = $_POST['cutoff_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $new_category = $_POST['new_category'] ?? 'その他';
            
            $sql = "UPDATE ride_records SET transport_category = ? WHERE ride_date < ? AND transport_category IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_category, $cutoff_date]);
            $message = "古いレコード {$stmt->rowCount()}件の輸送分類を更新しました。";
            
        } elseif ($action === 'fix_null_values') {
            // NULL値の修正
            $updates = [
                "UPDATE ride_records SET passenger_count = 1 WHERE passenger_count IS NULL OR passenger_count = 0",
                "UPDATE ride_records SET charge = 0 WHERE charge IS NULL",
                "UPDATE ride_records SET payment_method = '現金' WHERE payment_method IS NULL OR payment_method = ''",
                "UPDATE ride_records SET transport_category = 'その他' WHERE transport_category IS NULL OR transport_category = ''"
            ];
            
            $total_updated = 0;
            foreach ($updates as $sql) {
                $stmt = $pdo->exec($sql);
                $total_updated += $stmt;
            }
            $message = "NULL値 {$total_updated}件を修正しました。";
        }
    } catch (Exception $e) {
        $message = "エラー: " . $e->getMessage();
    }
}

// データ分析
function analyzeData($pdo) {
    $analysis = [];
    
    // 重複データ確認
    $sql = "SELECT COUNT(*) as duplicates FROM (
        SELECT ride_date, ride_time, driver_id, pickup_location, dropoff_location, COUNT(*) as cnt
        FROM ride_records 
        GROUP BY ride_date, ride_time, driver_id, pickup_location, dropoff_location
        HAVING cnt > 1
    ) as dup";
    $stmt = $pdo->query($sql);
    $analysis['duplicates'] = $stmt->fetchColumn();
    
    // 金額0円の記録
    $sql = "SELECT COUNT(*) FROM ride_records WHERE fare = 0 OR fare IS NULL";
    $stmt = $pdo->query($sql);
    $analysis['zero_amounts'] = $stmt->fetchColumn();
    
    // NULL値の記録
    $sql = "SELECT COUNT(*) FROM ride_records WHERE 
            passenger_count IS NULL OR passenger_count = 0 OR
            payment_method IS NULL OR payment_method = '' OR
            transport_category IS NULL OR transport_category = ''";
    $stmt = $pdo->query($sql);
    $analysis['null_values'] = $stmt->fetchColumn();
    
    // テストデータの可能性
    $sql = "SELECT COUNT(*) FROM ride_records WHERE 
            pickup_location LIKE '%テスト%' OR pickup_location LIKE '%test%' OR
            dropoff_location LIKE '%テスト%' OR dropoff_location LIKE '%test%' OR
            pickup_location LIKE '%サンプル%' OR pickup_location LIKE '%sample%' OR
            notes LIKE '%テスト%' OR notes LIKE '%test%'";
    $stmt = $pdo->query($sql);
    $analysis['test_data'] = $stmt->fetchColumn();
    
    // 古いデータ（30日以前）
    $sql = "SELECT COUNT(*) FROM ride_records WHERE ride_date < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $stmt = $pdo->query($sql);
    $analysis['old_data'] = $stmt->fetchColumn();
    
    return $analysis;
}

$analysis = analyzeData($pdo);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>クイック編集ツール - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .task-card { 
            background: white; 
            border-radius: 12px; 
            padding: 20px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        .task-card:hover { transform: translateY(-2px); }
        .danger-card { border-left: 4px solid #dc3545; }
        .warning-card { border-left: 4px solid #ffc107; }
        .info-card { border-left: 4px solid #17a2b8; }
        .success-card { border-left: 4px solid #28a745; }
        .stat-badge { font-size: 1.2em; font-weight: bold; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-magic me-2"></i>クイック編集ツール
            </a>
            <div class="navbar-nav ms-auto">
                <a href="manual_data_manager.php" class="nav-link">詳細管理</a>
                <a href="dashboard.php" class="nav-link">ダッシュボード</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h1><i class="fas fa-magic me-2"></i>クイック編集ツール</h1>
        <p class="text-muted">よくあるデータ問題を簡単に修正できます</p>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- データ状況サマリー -->
        <div class="task-card info-card">
            <h4><i class="fas fa-chart-pie me-2"></i>データ状況サマリー</h4>
            <div class="row text-center">
                <div class="col-md-2">
                    <div class="stat-badge text-danger"><?= $analysis['duplicates'] ?></div>
                    <div>重複データ</div>
                </div>
                <div class="col-md-2">
                    <div class="stat-badge text-warning"><?= $analysis['zero_amounts'] ?></div>
                    <div>金額0円</div>
                </div>
                <div class="col-md-2">
                    <div class="stat-badge text-info"><?= $analysis['null_values'] ?></div>
                    <div>NULL値</div>
                </div>
                <div class="col-md-2">
                    <div class="stat-badge text-secondary"><?= $analysis['test_data'] ?></div>
                    <div>テストデータ</div>
                </div>
                <div class="col-md-2">
                    <div class="stat-badge text-muted"><?= $analysis['old_data'] ?></div>
                    <div>古いデータ</div>
                </div>
                <div class="col-md-2">
                    <a href="manual_data_manager.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit me-1"></i>詳細編集
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- 重複データ削除 -->
            <?php if ($analysis['duplicates'] > 0): ?>
            <div class="col-md-6">
                <div class="task-card danger-card">
                    <h5><i class="fas fa-copy me-2 text-danger"></i>重複データ削除</h5>
                    <p>同一日時・運転者・乗降地の重複記録を削除します。</p>
                    <p><strong>検出数: <?= $analysis['duplicates'] ?>件</strong></p>
                    
                    <form method="POST" onsubmit="return confirm('重複データを削除しますか？')">
                        <input type="hidden" name="action" value="fix_duplicate_rides">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>重複データを削除
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- 金額0円修正 -->
            <?php if ($analysis['zero_amounts'] > 0): ?>
            <div class="col-md-6">
                <div class="task-card warning-card">
                    <h5><i class="fas fa-yen-sign me-2 text-warning"></i>金額0円の修正</h5>
                    <p>運賃が0円または未入力の記録を修正します。</p>
                    <p><strong>対象数: <?= $analysis['zero_amounts'] ?>件</strong></p>
                    
                    <form method="POST" onsubmit="return confirm('金額0円のレコードを修正しますか？')">
                        <input type="hidden" name="action" value="fix_zero_amounts">
                        <div class="input-group mb-3">
                            <span class="input-group-text">デフォルト運賃</span>
                            <input type="number" class="form-control" name="default_fare" value="1000" min="0">
                            <span class="input-group-text">円</span>
                        </div>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-edit me-1"></i>金額を修正
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- NULL値修正 -->
            <?php if ($analysis['null_values'] > 0): ?>
            <div class="col-md-6">
                <div class="task-card info-card">
                    <h5><i class="fas fa-question-circle me-2 text-info"></i>NULL値の修正</h5>
                    <p>未入力項目にデフォルト値を設定します。</p>
                    <p><strong>対象数: <?= $analysis['null_values'] ?>件</strong></p>
                    
                    <form method="POST" onsubmit="return confirm('NULL値を修正しますか？')">
                        <input type="hidden" name="action" value="fix_null_values">
                        <ul class="small mb-3">
                            <li>人員数 → 1名</li>
                            <li>料金 → 0円</li>
                            <li>支払方法 → 現金</li>
                            <li>輸送分類 → その他</li>
                        </ul>
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-fix me-1"></i>NULL値を修正
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- テストデータ削除 -->
            <?php if ($analysis['test_data'] > 0): ?>
            <div class="col-md-6">
                <div class="task-card warning-card">
                    <h5><i class="fas fa-flask me-2 text-warning"></i>テストデータ削除</h5>
                    <p>テスト・サンプル関連の記録を削除します。</p>
                    <p><strong>検出数: <?= $analysis['test_data'] ?>件</strong></p>
                    
                    <form method="POST" onsubmit="return confirm('テストデータを削除しますか？この操作は元に戻せません。')">
                        <input type="hidden" name="action" value="delete_test_data">
                        <div class="mb-3">
                            <label class="form-label">削除キーワード（カンマ区切り）</label>
                            <input type="text" class="form-control" name="test_keywords" 
                                   value="テスト,test,サンプル,sample">
                        </div>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-trash me-1"></i>テストデータを削除
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- 古いデータ更新 -->
            <?php if ($analysis['old_data'] > 0): ?>
            <div class="col-md-6">
                <div class="task-card success-card">
                    <h5><i class="fas fa-calendar me-2 text-success"></i>古いデータ更新</h5>
                    <p>古いレコードの輸送分類を一括更新します。</p>
                    <p><strong>対象数: <?= $analysis['old_data'] ?>件</strong></p>
                    
                    <form method="POST" onsubmit="return confirm('古いデータを更新しますか？')">
                        <input type="hidden" name="action" value="update_old_records">
                        <div class="row">
                            <div class="col-6">
                                <label class="form-label">基準日</label>
                                <input type="date" class="form-control" name="cutoff_date" 
                                       value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">新しい分類</label>
                                <select class="form-select" name="new_category">
                                    <option value="その他">その他</option>
                                    <option value="通院">通院</option>
                                    <option value="外出等">外出等</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success mt-3">
                            <i class="fas fa-update me-1"></i>一括更新
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- データ正常時のメッセージ -->
        <?php if (array_sum($analysis) == 0): ?>
        <div class="task-card success-card text-center">
            <h4><i class="fas fa-check-circle text-success me-2"></i>データは正常です</h4>
            <p>修正が必要な問題は見つかりませんでした。</p>
            <a href="manual_data_manager.php" class="btn btn-primary">
                <i class="fas fa-edit me-1"></i>詳細編集画面へ
            </a>
        </div>
        <?php endif; ?>

        <!-- 便利ツール -->
        <div class="task-card">
            <h4><i class="fas fa-toolbox me-2"></i>その他の便利ツール</h4>
            <div class="row">
                <div class="col-md-3">
                    <a href="manual_data_manager.php?table=ride_records" class="btn btn-outline-primary w-100 mb-2">
                        <i class="fas fa-users me-1"></i>乗車記録管理
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="manual_data_manager.php?table=users" class="btn btn-outline-secondary w-100 mb-2">
                        <i class="fas fa-user me-1"></i>ユーザー管理
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="manual_data_manager.php?table=vehicles" class="btn btn-outline-success w-100 mb-2">
                        <i class="fas fa-car me-1"></i>車両管理
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="safe_check.php" class="btn btn-outline-info w-100 mb-2">
                        <i class="fas fa-shield-alt me-1"></i>安全確認
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
