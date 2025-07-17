<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$today = date('Y-m-d');

$success_message = '';
$error_message = '';

// デバッグ: 車両取得
echo "<!-- Debug: Starting vehicle fetch -->\n";
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- Debug: Found " . count($vehicles) . " vehicles -->\n";
} catch (Exception $e) {
    echo "<!-- Debug: Vehicle fetch error: " . $e->getMessage() . " -->\n";
    $vehicles = [];
}

// デバッグ: 点検者取得
echo "<!-- Debug: Starting inspector fetch -->\n";
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE role IN ('driver', 'manager', 'admin') AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $inspectors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<!-- Debug: Found " . count($inspectors) . " inspectors -->\n";
} catch (Exception $e) {
    echo "<!-- Debug: Inspector fetch error: " . $e->getMessage() . " -->\n";
    $inspectors = [];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>定期点検（テスト版）</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>定期点検（テスト版）</h1>
        
        <div class="alert alert-info">
            <p>車両数: <?php echo count($vehicles); ?></p>
            <p>点検者数: <?php echo count($inspectors); ?></p>
        </div>
        
        <div class="card">
            <div class="card-body">
                <h5>基本情報</h5>
                <form method="POST">
                    <div class="mb-3">
                        <label>車両</label>
                        <select class="form-select" name="vehicle_id">
                            <option value="">選択してください</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['id']; ?>">
                                    <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label>点検者</label>
                        <select class="form-select" name="inspector_id">
                            <option value="">選択してください</option>
                            <?php foreach ($inspectors as $inspector): ?>
                                <option value="<?php echo $inspector['id']; ?>">
                                    <?php echo htmlspecialchars($inspector['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-body">
                <h5>点検項目テスト</h5>
                <?php
                $test_items = [
                    'item1' => 'テスト項目1',
                    'item2' => 'テスト項目2'
                ];
                ?>
                
                <?php foreach ($test_items as $key => $label): ?>
                <div class="mb-3 p-3 bg-light">
                    <strong><?php echo $label; ?></strong>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-success">○</button>
                        <button type="button" class="btn btn-sm btn-warning">△</button>
                        <button type="button" class="btn btn-sm btn-danger">×</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="mt-3">
            <a href="dashboard.php" class="btn btn-secondary">ダッシュボードに戻る</a>
        </div>
    </div>
</body>
</html>