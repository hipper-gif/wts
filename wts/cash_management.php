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
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
        'css/ui-unified-v3.css'
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
/* モダンミニマル追加スタイル */
.filter-card {
    margin-bottom: 24px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-item {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.875rem;
    color: #666;
    font-weight: 500;
}

.period-badge {
    display: inline-block;
    padding: 8px 16px;
    background: #f5f5f5;
    border-radius: 4px;
    font-size: 0.9rem;
    margin-bottom: 24px;
}

.driver-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.driver-card {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 16px;
}

.driver-card-header {
    font-size: 1.1rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #f5f5f5;
}

.driver-stat-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 0.9rem;
}

.driver-stat-label {
    color: #666;
}

.driver-stat-value {
    font-weight: 600;
    color: #333;
}

.additional-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-top: 24px;
}
</style>

<?php echo $page_data['system_header']; ?>
<?php echo $page_data['page_header']; ?>

<div class="container-fluid mt-4">
    <!-- フィルターセクション -->
    <div class="card filter-card">
        <div class="card-body">
            <h6 class="card-title mb-3">検索条件</h6>
            <form method="GET" action="cash_management.php">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="driver_id" class="form-label">運転者</label>
                        <select class="form-select" id="driver_id" name="driver_id">
                            <option value="all" <?php echo $selected_driver_id === 'all' ? 'selected' : ''; ?>>
                                すべて
                            </option>
                            <?php foreach ($driver_list as $driver): ?>
                            <option value="<?php echo $driver['id']; ?>" 
                                    <?php echo $selected_driver_id == $driver['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($driver['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="start_date" class="form-label">開始日</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" 
                               value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="end_date" class="form-label">終了日</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" 
                               value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            検索
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 集計期間表示 -->
    <div class="period-badge">
        <?php echo date('Y年m月d日', strtotime($start_date)); ?> 〜 
        <?php echo date('Y年m月d日', strtotime($end_date)); ?>
        <?php if ($selected_driver_id === 'all'): ?>
        <strong class="ms-2">（全運転者）</strong>
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
        <strong class="ms-2">（<?php echo htmlspecialchars($selected_driver_name); ?>）</strong>
        <?php endif; ?>
    </div>

    <!-- 合計サマリー -->
    <div class="stats-grid">
        <div class="stat-item">
            <div class="stat-value">¥<?php echo number_format($revenue_data['total_revenue'] ?? 0); ?></div>
            <div class="stat-label">総売上</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?php echo number_format($revenue_data['ride_count'] ?? 0); ?>回</div>
            <div class="stat-label">総回数</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">¥<?php echo number_format($revenue_data['cash_total'] ?? 0); ?></div>
            <div class="stat-label">現金売上</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">¥<?php echo number_format($revenue_data['card_total'] ?? 0); ?></div>
            <div class="stat-label">カード売上</div>
        </div>
    </div>

    <!-- 運転者別詳細（「すべて」選択時のみ表示） -->
    <?php if ($selected_driver_id === 'all' && !empty($driver_details)): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="card-title mb-3">運転者別詳細</h6>
            <div class="driver-grid">
                <?php foreach ($driver_details as $detail): ?>
                <div class="driver-card">
                    <div class="driver-card-header">
                        <?php echo htmlspecialchars($detail['name']); ?>
                    </div>
                    <div class="driver-stat-row">
                        <span class="driver-stat-label">総売上</span>
                        <span class="driver-stat-value">
                            ¥<?php echo number_format($detail['total_revenue'] ?? 0); ?>
                        </span>
                    </div>
                    <div class="driver-stat-row">
                        <span class="driver-stat-label">乗車回数</span>
                        <span class="driver-stat-value">
                            <?php echo number_format($detail['ride_count'] ?? 0); ?>回
                        </span>
                    </div>
                    <div class="driver-stat-row">
                        <span class="driver-stat-label">現金売上</span>
                        <span class="driver-stat-value">
                            ¥<?php echo number_format($detail['cash_total'] ?? 0); ?>
                        </span>
                    </div>
                    <div class="driver-stat-row">
                        <span class="driver-stat-label">カード売上</span>
                        <span class="driver-stat-value">
                            ¥<?php echo number_format($detail['card_total'] ?? 0); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php elseif ($selected_driver_id === 'all'): ?>
    <div class="alert alert-info">
        選択した期間には乗車記録がありません。
    </div>
    <?php endif; ?>

    <!-- 個別運転者選択時の追加情報 -->
    <?php if ($selected_driver_id !== 'all'): ?>
    <div class="card">
        <div class="card-body">
            <h6 class="card-title mb-3">詳細統計</h6>
            <div class="additional-stats">
                <div class="stat-item">
                    <div class="stat-value">
                        <?php echo number_format($revenue_data['total_passengers'] ?? 0); ?>人
                    </div>
                    <div class="stat-label">総乗客数</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php 
                        $avg_per_ride = ($revenue_data['ride_count'] ?? 0) > 0 
                            ? round(($revenue_data['total_revenue'] ?? 0) / $revenue_data['ride_count']) 
                            : 0;
                        echo '¥' . number_format($avg_per_ride); 
                        ?>
                    </div>
                    <div class="stat-label">平均単価</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php 
                        $cash_rate = ($revenue_data['total_revenue'] ?? 0) > 0 
                            ? round(($revenue_data['cash_total'] ?? 0) / $revenue_data['total_revenue'] * 100, 1) 
                            : 0;
                        echo $cash_rate . '%'; 
                        ?>
                    </div>
                    <div class="stat-label">現金利用率</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php 
                        $card_rate = ($revenue_data['total_revenue'] ?? 0) > 0 
                            ? round(($revenue_data['card_total'] ?? 0) / $revenue_data['total_revenue'] * 100, 1) 
                            : 0;
                        echo $card_rate . '%'; 
                        ?>
                    </div>
                    <div class="stat-label">カード利用率</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php echo $page_data['footer'] ?? ''; ?>
</body>
</html>
