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

// 集金ステータス取得関数（cash_count_detailsテーブルを参照）
function getCollectionStatus($pdo, $start_date, $end_date, $driver_id = null) {
    $sql = "SELECT
                c.driver_id,
                c.confirmation_date,
                c.total_amount,
                c.created_at,
                c.updated_at,
                u.name as driver_name
            FROM cash_count_details c
            LEFT JOIN users u ON c.driver_id = u.id
            WHERE c.confirmation_date BETWEEN ? AND ?";

    $params = [$start_date, $end_date];

    if ($driver_id && $driver_id !== 'all') {
        $sql .= " AND c.driver_id = ?";
        $params[] = $driver_id;
    }

    $sql .= " ORDER BY c.confirmation_date DESC, c.driver_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // driver_id => [date => record] のマップに変換
    $status_map = [];
    foreach ($records as $record) {
        $did = $record['driver_id'];
        $date = $record['confirmation_date'];
        if (!isset($status_map[$did])) {
            $status_map[$did] = [];
        }
        $status_map[$did][$date] = $record;
    }

    return $status_map;
}

// 期間内の営業日数を取得（ride_recordsが存在する日数）
function getWorkingDates($pdo, $start_date, $end_date, $driver_id = null) {
    $sql = "SELECT DISTINCT ride_date FROM ride_records WHERE ride_date BETWEEN ? AND ?";
    $params = [$start_date, $end_date];

    if ($driver_id && $driver_id !== 'all') {
        $sql .= " AND driver_id = ?";
        $params[] = $driver_id;
    }

    $sql .= " ORDER BY ride_date";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
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

// 集金ステータス取得
$collection_status = getCollectionStatus($pdo, $start_date, $end_date, $selected_driver_id);

// 運転者別詳細データ（「すべて」選択時のみ）
$driver_details = [];
if ($selected_driver_id === 'all') {
    $driver_details = getRevenueByDriver($pdo, $start_date, $end_date);

    // 各運転者ごとの営業日と集金完了日数を計算
    $collection_summary = ['total_drivers' => 0, 'collected_drivers' => 0];
    foreach ($driver_details as &$detail) {
        // この運転者の乗車がある場合のみカウント
        if (($detail['ride_count'] ?? 0) > 0) {
            $collection_summary['total_drivers']++;
            // 期間内に集金レコードがあるか確認
            $driver_working_dates = getWorkingDates($pdo, $start_date, $end_date, $detail['id']);
            $collected_dates = isset($collection_status[$detail['id']]) ? array_keys($collection_status[$detail['id']]) : [];
            $uncollected_dates = array_diff($driver_working_dates, $collected_dates);

            $detail['working_dates'] = $driver_working_dates;
            $detail['collected_dates'] = $collected_dates;
            $detail['is_fully_collected'] = empty($uncollected_dates);
            $detail['collection_count'] = count($collected_dates);
            $detail['working_count'] = count($driver_working_dates);

            if ($detail['is_fully_collected']) {
                $collection_summary['collected_drivers']++;
            }
        } else {
            $detail['is_fully_collected'] = null; // 乗車なし
            $detail['collection_count'] = 0;
            $detail['working_count'] = 0;
        }
    }
    unset($detail);
} else {
    // 個別運転者の集金ステータス
    $driver_working_dates = getWorkingDates($pdo, $start_date, $end_date, $selected_driver_id);
    $collected_dates = isset($collection_status[$selected_driver_id]) ? array_keys($collection_status[$selected_driver_id]) : [];
    $uncollected_dates = array_diff($driver_working_dates, $collected_dates);
    $single_driver_collection = [
        'working_dates' => $driver_working_dates,
        'collected_dates' => $collected_dates,
        'uncollected_dates' => array_values($uncollected_dates),
        'is_fully_collected' => empty($uncollected_dates) && !empty($driver_working_dates),
        'collection_count' => count($collected_dates),
        'working_count' => count($driver_working_dates),
    ];
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

/* 集金ステータスインジケーター */
.collection-summary {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
    padding: 16px 20px;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
}

.collection-summary-icon {
    font-size: 1.5rem;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    flex-shrink: 0;
}

.collection-summary-icon.all-done {
    background: #e8f5e9;
    color: #2e7d32;
}

.collection-summary-icon.partial {
    background: #fff3e0;
    color: #e65100;
}

.collection-summary-icon.none {
    background: #f5f5f5;
    color: #9e9e9e;
}

.collection-summary-text {
    flex: 1;
}

.collection-summary-title {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 2px;
}

.collection-summary-value {
    font-size: 1.1rem;
    font-weight: 600;
    color: #333;
}

.collection-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.4;
}

.collection-badge.collected {
    background: #e8f5e9;
    color: #2e7d32;
}

.collection-badge.uncollected {
    background: #fbe9e7;
    color: #c62828;
}

.collection-badge.no-rides {
    background: #f5f5f5;
    color: #9e9e9e;
}

.collection-status-card {
    margin-bottom: 24px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
}

.collection-status-card .status-header {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.collection-status-card .status-header.collected {
    background: #e8f5e9;
    border-bottom: 2px solid #4caf50;
}

.collection-status-card .status-header.uncollected {
    background: #fbe9e7;
    border-bottom: 2px solid #e53935;
}

.collection-status-card .status-header.no-data {
    background: #f5f5f5;
    border-bottom: 2px solid #bdbdbd;
}

.collection-status-card .status-icon {
    font-size: 1.5rem;
}

.collection-status-card .status-text {
    font-size: 1rem;
    font-weight: 600;
}

.collection-status-card .status-detail {
    padding: 12px 20px;
    background: white;
    font-size: 0.875rem;
    color: #555;
}

.collection-progress {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}

.collection-progress-bar {
    flex: 1;
    height: 6px;
    background: #e0e0e0;
    border-radius: 3px;
    overflow: hidden;
}

.collection-progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

.collection-progress-fill.complete {
    background: #4caf50;
}

.collection-progress-fill.partial {
    background: #ff9800;
}

.collection-progress-text {
    font-size: 0.75rem;
    color: #666;
    white-space: nowrap;
}

.driver-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
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

    <!-- 集金状況サマリー -->
    <?php if ($selected_driver_id === 'all' && !empty($driver_details)): ?>
    <?php
        $summary_icon_class = 'none';
        $summary_icon = 'fa-clock';
        if ($collection_summary['total_drivers'] > 0) {
            if ($collection_summary['collected_drivers'] === $collection_summary['total_drivers']) {
                $summary_icon_class = 'all-done';
                $summary_icon = 'fa-check-circle';
            } elseif ($collection_summary['collected_drivers'] > 0) {
                $summary_icon_class = 'partial';
                $summary_icon = 'fa-exclamation-circle';
            }
        }
        $progress_pct = $collection_summary['total_drivers'] > 0
            ? round($collection_summary['collected_drivers'] / $collection_summary['total_drivers'] * 100)
            : 0;
    ?>
    <div class="collection-summary">
        <div class="collection-summary-icon <?php echo $summary_icon_class; ?>">
            <i class="fas <?php echo $summary_icon; ?>"></i>
        </div>
        <div class="collection-summary-text">
            <div class="collection-summary-title">集金処理状況</div>
            <div class="collection-summary-value">
                <?php echo $collection_summary['collected_drivers']; ?> / <?php echo $collection_summary['total_drivers']; ?> 名完了
            </div>
            <div class="collection-progress">
                <div class="collection-progress-bar">
                    <div class="collection-progress-fill <?php echo $progress_pct === 100 ? 'complete' : 'partial'; ?>"
                         style="width: <?php echo $progress_pct; ?>%"></div>
                </div>
                <span class="collection-progress-text"><?php echo $progress_pct; ?>%</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
                        <span><?php echo htmlspecialchars($detail['name']); ?></span>
                        <?php if ($detail['is_fully_collected'] === true): ?>
                            <span class="collection-badge collected">
                                <i class="fas fa-check-circle"></i> 集金済み
                            </span>
                        <?php elseif ($detail['is_fully_collected'] === false): ?>
                            <span class="collection-badge uncollected">
                                <i class="fas fa-exclamation-circle"></i> 未集金
                                <?php if ($detail['working_count'] > 1): ?>
                                    (<?php echo $detail['collection_count']; ?>/<?php echo $detail['working_count']; ?>日)
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="collection-badge no-rides">
                                <i class="fas fa-minus-circle"></i> 乗車なし
                            </span>
                        <?php endif; ?>
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

    <!-- 個別運転者選択時の集金ステータス -->
    <?php if ($selected_driver_id !== 'all'): ?>
    <?php
        $sc = $single_driver_collection;
        if ($sc['working_count'] === 0) {
            $status_class = 'no-data';
            $status_icon = 'fa-minus-circle';
            $status_text = '該当期間に乗車記録がありません';
            $status_color = '#9e9e9e';
        } elseif ($sc['is_fully_collected']) {
            $status_class = 'collected';
            $status_icon = 'fa-check-circle';
            $status_text = '集金処理完了';
            $status_color = '#2e7d32';
        } else {
            $status_class = 'uncollected';
            $status_icon = 'fa-exclamation-circle';
            $status_text = '未集金あり（' . $sc['collection_count'] . '/' . $sc['working_count'] . '日完了）';
            $status_color = '#c62828';
        }
    ?>
    <div class="collection-status-card">
        <div class="status-header <?php echo $status_class; ?>">
            <span class="status-icon" style="color: <?php echo $status_color; ?>">
                <i class="fas <?php echo $status_icon; ?>"></i>
            </span>
            <span class="status-text"><?php echo $status_text; ?></span>
        </div>
        <?php if ($sc['working_count'] > 0): ?>
        <div class="status-detail">
            <?php if (!empty($sc['uncollected_dates'])): ?>
                <strong>未集金の日付:</strong>
                <?php foreach ($sc['uncollected_dates'] as $ud): ?>
                    <span class="collection-badge uncollected ms-1">
                        <?php echo date('n/j', strtotime($ud)); ?>
                    </span>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="color: #2e7d32;">すべての営業日で集金処理が完了しています。</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
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
