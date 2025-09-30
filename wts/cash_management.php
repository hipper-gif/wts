<?php
session_start();
require_once 'config/database.php';
require_once 'includes/unified-header.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'] ?? 'User';

// 運転者リスト取得関数
function getDriverList($pdo) {
    $stmt = $pdo->prepare("
        SELECT id, name FROM users 
        WHERE is_driver = 1 AND is_active = 1
        ORDER BY name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 運転者リスト取得
$driver_list = getDriverList($pdo);

// パラメータ取得
$selected_driver_id = $_GET['driver_id'] ?? 'all'; // デフォルトは「すべて」
$start_date = $_GET['start_date'] ?? date('Y-m-d'); // デフォルトは今日
$end_date = $_GET['end_date'] ?? date('Y-m-d');     // デフォルトは今日

// 日付範囲の売上取得関数
function getRevenueByDateRange($pdo, $start_date, $end_date, $driver_id = null) {
    $sql = "SELECT 
                COUNT(*) as ride_count,
                SUM(passenger_count) as total_passengers,
                COALESCE(SUM(total_fare), SUM(fare + COALESCE(charge, 0))) as total_revenue,
                COALESCE(SUM(cash_amount), 
                    SUM(CASE WHEN payment_method = '現金' 
                        THEN fare + COALESCE(charge, 0) ELSE 0 END)) as cash_total,
                COALESCE(SUM(card_amount), 
                    SUM(CASE WHEN payment_method = 'カード' 
                        THEN fare + COALESCE(charge, 0) ELSE 0 END)) as card_total
            FROM ride_records 
            WHERE ride_date BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    
    if ($driver_id && $driver_id !== 'all') {
        $sql .= " AND driver_id = ?";
        $params[] = $driver_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 運転者別の売上詳細取得関数（「すべて」選択時用）
function getRevenueByDriver($pdo, $start_date, $end_date) {
    $sql = "SELECT 
                u.id,
                u.name,
                COUNT(r.id) as ride_count,
                SUM(r.passenger_count) as total_passengers,
                COALESCE(SUM(r.total_fare), SUM(r.fare + COALESCE(r.charge, 0))) as total_revenue,
                COALESCE(SUM(r.cash_amount), 
                    SUM(CASE WHEN r.payment_method = '現金' 
                        THEN r.fare + COALESCE(r.charge, 0) ELSE 0 END)) as cash_total,
                COALESCE(SUM(r.card_amount), 
                    SUM(CASE WHEN r.payment_method = 'カード' 
                        THEN r.fare + COALESCE(r.charge, 0) ELSE 0 END)) as card_total
            FROM users u
            LEFT JOIN ride_records r ON u.id = r.driver_id 
                AND r.ride_date BETWEEN ? AND ?
            WHERE u.is_driver = 1 AND u.is_active = 1
            GROUP BY u.id, u.name
            ORDER BY u.name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 売上データ取得
$revenue_data = getRevenueByDateRange($pdo, $start_date, $end_date, $selected_driver_id);

// 運転者別詳細データ（「すべて」選択時のみ）
$driver_details = [];
if ($selected_driver_id === 'all') {
    $driver_details = getRevenueByDriver($pdo, $start_date, $end_date);
}

// ページ設定
$page_config = getPageConfiguration('cash_management');

$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
    ],
    'additional_js' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'cash_management',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

echo $page_data['html_head'];
?>

<style>
.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.stat-box {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    margin-bottom: 15px;
}

.stat-box h3 {
    font-size: 2rem;
    font-weight: bold;
    margin: 0;
    color: white;
}

.stat-box p {
    margin: 5px 0 0 0;
    font-size: 0.9rem;
    opacity: 0.9;
}

.driver-detail-card {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s;
}

.driver-detail-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.driver-name {
    font-size: 1.2rem;
    font-weight: bold;
    color: #333;
    margin-bottom: 10px;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: #666;
    font-weight: 500;
}

.detail-value {
    font-weight: bold;
    color: #333;
}

.filter-section {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.btn-apply-filter {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    padding: 10px 30px;
    border-radius: 25px;
    font-weight: bold;
    transition: all 0.3s;
}

.btn-apply-filter:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.date-range-info {
    background: #e3f2fd;
    border-left: 4px solid #2196F3;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 5px;
}

@media (max-width: 768px) {
    .stat-box h3 {
        font-size: 1.5rem;
    }
}
</style>

<?php echo $page_data['system_header']; ?>
<?php echo $page_data['page_header']; ?>

<div class="container-fluid mt-4">
    <!-- フィルターセクション -->
    <div class="filter-section">
        <h5 class="mb-3"><i class="fas fa-filter"></i> 検索条件</h5>
        <form method="GET" action="cash_management.php">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="driver_id" class="form-label">運転者</label>
                    <select class="form-select" id="driver_id" name="driver_id">
                        <option value="all" <?php echo $selected_driver_id === 'all' ? 'selected' : ''; ?>>
                            すべて（全運転者）
                        </option>
                        <?php foreach ($driver_list as $driver): ?>
                        <option value="<?php echo $driver['id']; ?>" 
                                <?php echo $selected_driver_id == $driver['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($driver['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label for="start_date" class="form-label">開始日</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" 
                           value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label for="end_date" class="form-label">終了日</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" 
                           value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label d-block">&nbsp;</label>
                    <button type="submit" class="btn btn-apply-filter w-100">
                        <i class="fas fa-search"></i> 検索
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- 日付範囲表示 -->
    <div class="date-range-info">
        <i class="fas fa-calendar-alt"></i>
        <strong>集計期間：</strong>
        <?php echo date('Y年m月d日', strtotime($start_date)); ?> 〜 
        <?php echo date('Y年m月d日', strtotime($end_date)); ?>
        <?php if ($selected_driver_id === 'all'): ?>
        <span class="badge bg-primary ms-2">全運転者</span>
        <?php else: ?>
        <?php 
            $selected_driver_name = '';
            foreach ($driver_list as $driver) {
                if ($driver['id'] == $selected_driver_id) {
                    $selected_driver_name = $driver['name'];
                    break;
                }
            }
        ?>
        <span class="badge bg-success ms-2"><?php echo htmlspecialchars($selected_driver_name); ?></span>
        <?php endif; ?>
    </div>

    <!-- 合計サマリー -->
    <div class="summary-card">
        <h4 class="mb-4">
            <i class="fas fa-chart-pie"></i> 
            <?php echo $selected_driver_id === 'all' ? '全運転者合計' : '売上集計'; ?>
        </h4>
        <div class="row">
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <h3>¥<?php echo number_format($revenue_data['total_revenue'] ?? 0); ?></h3>
                    <p>総売上</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <h3><?php echo number_format($revenue_data['ride_count'] ?? 0); ?>回</h3>
                    <p>総回数</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <h3>¥<?php echo number_format($revenue_data['cash_total'] ?? 0); ?></h3>
                    <p>現金売上</p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-box">
                    <h3>¥<?php echo number_format($revenue_data['card_total'] ?? 0); ?></h3>
                    <p>カード売上</p>
                </div>
            </div>
        </div>
    </div>

    <!-- 運転者別詳細（「すべて」選択時のみ表示） -->
    <?php if ($selected_driver_id === 'all' && !empty($driver_details)): ?>
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-users"></i> 運転者別詳細</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($driver_details as $detail): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="driver-detail-card">
                        <div class="driver-name">
                            <i class="fas fa-user-circle text-primary"></i>
                            <?php echo htmlspecialchars($detail['name']); ?>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">総売上</span>
                            <span class="detail-value text-primary">
                                ¥<?php echo number_format($detail['total_revenue'] ?? 0); ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">乗車回数</span>
                            <span class="detail-value text-success">
                                <?php echo number_format($detail['ride_count'] ?? 0); ?>回
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">現金売上</span>
                            <span class="detail-value text-warning">
                                ¥<?php echo number_format($detail['cash_total'] ?? 0); ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">カード売上</span>
                            <span class="detail-value text-info">
                                ¥<?php echo number_format($detail['card_total'] ?? 0); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php elseif ($selected_driver_id === 'all'): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        選択した期間には乗車記録がありません。
    </div>
    <?php endif; ?>

    <!-- 個別運転者選択時の追加情報 -->
    <?php if ($selected_driver_id !== 'all'): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> 詳細情報</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h4 class="text-primary">
                                <?php echo number_format($revenue_data['total_passengers'] ?? 0); ?>人
                            </h4>
                            <p class="text-muted">総乗客数</p>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-success">
                                <?php 
                                $avg_per_ride = ($revenue_data['ride_count'] ?? 0) > 0 
                                    ? round(($revenue_data['total_revenue'] ?? 0) / $revenue_data['ride_count']) 
                                    : 0;
                                echo '¥' . number_format($avg_per_ride); 
                                ?>
                            </h4>
                            <p class="text-muted">平均単価</p>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-warning">
                                <?php 
                                $cash_rate = ($revenue_data['total_revenue'] ?? 0) > 0 
                                    ? round(($revenue_data['cash_total'] ?? 0) / $revenue_data['total_revenue'] * 100, 1) 
                                    : 0;
                                echo $cash_rate . '%'; 
                                ?>
                            </h4>
                            <p class="text-muted">現金利用率</p>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-info">
                                <?php 
                                $card_rate = ($revenue_data['total_revenue'] ?? 0) > 0 
                                    ? round(($revenue_data['card_total'] ?? 0) / $revenue_data['total_revenue'] * 100, 1) 
                                    : 0;
                                echo $card_rate . '%'; 
                                ?>
                            </h4>
                            <p class="text-muted">カード利用率</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php echo $page_data['footer'] ?? ''; ?>
</body>
</html>
