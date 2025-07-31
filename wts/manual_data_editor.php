<?php
session_start();

// 管理者権限チェック
if (!isset($_SESSION['user_id'])) {
    die('ログインが必要です。');
}

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// 処理結果メッセージ
$message = '';
$message_type = '';

// データ更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'update_record':
                $stmt = $pdo->prepare("
                    UPDATE ride_records 
                    SET fare_amount = ?, pickup_location = ?, dropoff_location = ?, 
                        passenger_count = ?, transportation_type = ?, payment_method = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['fare_amount'],
                    $_POST['pickup_location'],
                    $_POST['dropoff_location'],
                    $_POST['passenger_count'],
                    $_POST['transportation_type'],
                    $_POST['payment_method'],
                    $_POST['record_id']
                ]);
                $message = "ID {$_POST['record_id']} のデータを更新しました。";
                $message_type = 'success';
                break;
                
            case 'delete_record':
                $stmt = $pdo->prepare("DELETE FROM ride_records WHERE id = ?");
                $stmt->execute([$_POST['record_id']]);
                $message = "ID {$_POST['record_id']} のデータを削除しました。";
                $message_type = 'warning';
                break;
        }
    } catch(PDOException $e) {
        $message = "エラー: " . $e->getMessage();
        $message_type = 'danger';
    }
}

// 検索条件
$search_date_from = $_GET['date_from'] ?? '2025-07-01';
$search_date_to = $_GET['date_to'] ?? '2025-07-26';
$show_only_problems = isset($_GET['problems_only']) ? $_GET['problems_only'] : '1';

// データ取得
$where_conditions = ["ride_date BETWEEN ? AND ?"];
$params = [$search_date_from, $search_date_to];

if ($show_only_problems === '1') {
    $where_conditions[] = "(fare_amount = 0 OR fare_amount IS NULL OR fare_amount > 50000 OR fare_amount < 0)";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

$stmt = $pdo->prepare("
    SELECT r.*, u.name as driver_name, v.vehicle_number
    FROM ride_records r
    LEFT JOIN users u ON r.driver_id = u.id
    LEFT JOIN vehicles v ON r.vehicle_id = v.id
    {$where_clause}
    ORDER BY r.ride_date DESC, r.ride_time DESC, r.id DESC
    LIMIT 100
");
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        COUNT(CASE WHEN fare_amount = 0 OR fare_amount IS NULL THEN 1 END) as zero_null_count,
        COUNT(CASE WHEN fare_amount < 0 THEN 1 END) as negative_count,
        COUNT(CASE WHEN fare_amount > 50000 THEN 1 END) as high_amount_count,
        SUM(fare_amount) as total_amount
    FROM ride_records 
    {$where_clause}
");
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>手動データ修正ツール - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .problem-row { background-color: #fff3cd; }
        .zero-amount { background-color: #f8d7da; }
        .high-amount { background-color: #d1ecf1; }
        .negative-amount { background-color: #f5c6cb; }
        .edit-form { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 10px 0; }
        .compact-input { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
        .action-buttons { white-space: nowrap; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-edit me-2"></i>手動データ修正ツール
        </a>
        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i>ダッシュボードに戻る
        </a>
    </div>
</nav>

<div class="container-fluid mt-4">
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
            <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 検索・フィルター -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5><i class="fas fa-search me-2"></i>検索・フィルター</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">開始日</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($search_date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">終了日</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($search_date_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">表示対象</label>
                    <select name="problems_only" class="form-select">
                        <option value="1" <?= $show_only_problems === '1' ? 'selected' : '' ?>>問題のあるデータのみ</option>
                        <option value="0" <?= $show_only_problems === '0' ? 'selected' : '' ?>>全データ</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>検索
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 統計情報 -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-primary"><?= number_format($stats['total_count']) ?></h4>
                    <p class="card-text">総レコード数</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-danger"><?= number_format($stats['zero_null_count']) ?></h4>
                    <p class="card-text">0円・NULL</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-warning"><?= number_format($stats['negative_count']) ?></h4>
                    <p class="card-text">負の値</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-info"><?= number_format($stats['high_amount_count']) ?></h4>
                    <p class="card-text">異常に高額</p>
                </div>
            </div>
        </div>
    </div>

    <!-- データ一覧・編集 -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5><i class="fas fa-list me-2"></i>乗車記録データ (<?= count($records) ?>件表示)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>日付</th>
                            <th>時間</th>
                            <th>運転者</th>
                            <th>車両</th>
                            <th>乗車地</th>
                            <th>降車地</th>
                            <th>金額</th>
                            <th>人数</th>
                            <th>輸送分類</th>
                            <th>支払方法</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $row_class = '';
                            if ($record['fare_amount'] == 0 || is_null($record['fare_amount'])) {
                                $row_class = 'zero-amount';
                            } elseif ($record['fare_amount'] < 0) {
                                $row_class = 'negative-amount';
                            } elseif ($record['fare_amount'] > 50000) {
                                $row_class = 'high-amount';
                            }
                            ?>
                            <tr class="<?= $row_class ?>" id="row-<?= $record['id'] ?>">
                                <td><strong><?= htmlspecialchars($record['id']) ?></strong></td>
                                <td><?= htmlspecialchars($record['ride_date']) ?></td>
                                <td><?= htmlspecialchars($record['ride_time']) ?></td>
                                <td><?= htmlspecialchars($record['driver_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($record['vehicle_number'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($record['pickup_location']) ?></td>
                                <td><?= htmlspecialchars($record['dropoff_location']) ?></td>
                                <td><strong>¥<?= number_format($record['fare_amount'] ?? 0) ?></strong></td>
                                <td><?= htmlspecialchars($record['passenger_count'] ?? 1) ?>名</td>
                                <td><?= htmlspecialchars($record['transportation_type'] ?? '') ?></td>
                                <td><?= htmlspecialchars($record['payment_method'] ?? '') ?></td>
                                <td class="action-buttons">
                                    <button type="button" class="btn btn-sm btn-warning" onclick="editRecord(<?= $record['id'] ?>)">
                                        <i class="fas fa-edit"></i>編集
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="deleteRecord(<?= $record['id'] ?>)">
                                        <i class="fas fa-trash"></i>削除
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- 編集フォーム（非表示） -->
                            <tr id="edit-form-<?= $record['id'] ?>" style="display: none;">
                                <td colspan="12">
                                    <div class="edit-form">
                                        <form method="POST" class="row g-2">
                                            <input type="hidden" name="action" value="update_record">
                                            <input type="hidden" name="record_id" value="<?= $record['id'] ?>">
                                            
                                            <div class="col-md-2">
                                                <label class="form-label">乗車地</label>
                                                <input type="text" name="pickup_location" class="form-control compact-input" 
                                                       value="<?= htmlspecialchars($record['pickup_location']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">降車地</label>
                                                <input type="text" name="dropoff_location" class="form-control compact-input" 
                                                       value="<?= htmlspecialchars($record['dropoff_location']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">金額</label>
                                                <input type="number" name="fare_amount" class="form-control compact-input" 
                                                       value="<?= $record['fare_amount'] ?>" step="10" min="0" max="100000" required>
                                            </div>
                                            <div class="col-md-1">
                                                <label class="form-label">人数</label>
                                                <input type="number" name="passenger_count" class="form-control compact-input" 
                                                       value="<?= $record['passenger_count'] ?? 1 ?>" min="1" max="10" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">輸送分類</label>
                                                <select name="transportation_type" class="form-select compact-input">
                                                    <option value="通院" <?= $record['transportation_type'] === '通院' ? 'selected' : '' ?>>通院</option>
                                                    <option value="外出等" <?= $record['transportation_type'] === '外出等' ? 'selected' : '' ?>>外出等</option>
                                                    <option value="退院" <?= $record['transportation_type'] === '退院' ? 'selected' : '' ?>>退院</option>
                                                    <option value="転院" <?= $record['transportation_type'] === '転院' ? 'selected' : '' ?>>転院</option>
                                                    <option value="施設入所" <?= $record['transportation_type'] === '施設入所' ? 'selected' : '' ?>>施設入所</option>
                                                    <option value="その他" <?= $record['transportation_type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">支払方法</label>
                                                <select name="payment_method" class="form-select compact-input">
                                                    <option value="現金" <?= $record['payment_method'] === '現金' ? 'selected' : '' ?>>現金</option>
                                                    <option value="カード" <?= $record['payment_method'] === 'カード' ? 'selected' : '' ?>>カード</option>
                                                    <option value="その他" <?= $record['payment_method'] === 'その他' ? 'selected' : '' ?>>その他</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 d-flex align-items-end">
                                                <button type="submit" class="btn btn-success btn-sm me-1">
                                                    <i class="fas fa-save"></i>保存
                                                </button>
                                                <button type="button" class="btn btn-secondary btn-sm" onclick="cancelEdit(<?= $record['id'] ?>)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- よく使う金額のクイックボタン -->
    <div class="card mt-4">
        <div class="card-header bg-info text-white">
            <h5><i class="fas fa-money-bill me-2"></i>よく使う金額（クリックで入力）</h5>
        </div>
        <div class="card-body">
            <div class="btn-group flex-wrap" role="group">
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(1000)">¥1,000</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(1500)">¥1,500</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(2000)">¥2,000</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(2500)">¥2,500</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(3000)">¥3,000</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(3500)">¥3,500</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(4000)">¥4,000</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(5000)">¥5,000</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(6000)">¥6,000</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(8000)">¥8,000</button>
                <button type="button" class="btn btn-outline-primary" onclick="setQuickAmount(10000)">¥10,000</button>
            </div>
        </div>
    </div>

    <!-- 一括操作 -->
    <div class="card mt-4 mb-4">
        <div class="card-header bg-warning text-dark">
            <h5><i class="fas fa-tools me-2"></i>一括操作</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>注意：</strong>一括操作は元に戻せません。十分確認してから実行してください。
            </div>
            <button type="button" class="btn btn-warning me-2" onclick="bulkFixZeroAmounts()">
                <i class="fas fa-magic me-1"></i>0円データを一括で2000円に設定
            </button>
            <button type="button" class="btn btn-info me-2" onclick="bulkSetPaymentMethod()">
                <i class="fas fa-credit-card me-1"></i>空の支払方法を一括で現金に設定
            </button>
        </div>
    </div>

</div>

<!-- 削除確認モーダル -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">データ削除確認</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>ID <span id="deleteRecordId"></span> のデータを削除しますか？</p>
                <p class="text-danger"><strong>この操作は元に戻せません。</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete_record">
                    <input type="hidden" name="record_id" id="deleteRecordIdInput">
                    <button type="submit" class="btn btn-danger">削除実行</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentEditingId = null;
let currentFareAmountInput = null;

function editRecord(id) {
    // 他の編集フォームを閉じる
    if (currentEditingId && currentEditingId !== id) {
        cancelEdit(currentEditingId);
    }
    
    document.getElementById('edit-form-' + id).style.display = 'table-row';
    currentEditingId = id;
}

function cancelEdit(id) {
    document.getElementById('edit-form-' + id).style.display = 'none';
    currentEditingId = null;
    currentFareAmountInput = null;
}

function deleteRecord(id) {
    document.getElementById('deleteRecordId').textContent = id;
    document.getElementById('deleteRecordIdInput').value = id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function setQuickAmount(amount) {
    if (currentFareAmountInput) {
        currentFareAmountInput.value = amount;
    } else {
        alert('まず編集したい行の「編集」ボタンをクリックしてください。');
    }
}

// 金額入力フィールドをクリックした時の処理
document.addEventListener('click', function(e) {
    if (e.target.name === 'fare_amount') {
        currentFareAmountInput = e.target;
        e.target.style.backgroundColor = '#fff3cd';
    }
});

// 他の場所をクリックした時の処理
document.addEventListener('click', function(e) {
    if (e.target.name !== 'fare_amount' && currentFareAmountInput) {
        currentFareAmountInput.style.backgroundColor = '';
    }
});

function bulkFixZeroAmounts() {
    if (confirm('0円のデータを全て2000円に設定しますか？この操作は元に戻せません。')) {
        // 実装は必要に応じて追加
        alert('一括操作機能は実装予定です。現在は個別編集をお使いください。');
    }
}

function bulkSetPaymentMethod() {
    if (confirm('空の支払方法を全て「現金」に設定しますか？')) {
        // 実装は必要に応じて追加
        alert('一括操作機能は実装予定です。現在は個別編集をお使いください。');
    }
}

// キーボードショートカット
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's' && currentEditingId) {
        e.preventDefault();
        document.querySelector('#edit-form-' + currentEditingId + ' form').submit();
    }
    if (e.key === 'Escape' && currentEditingId) {
        cancelEdit(currentEditingId);
    }
});
</script>

</body>
</html>
