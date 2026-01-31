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

// --- テーブル自動作成（cash_collections が存在しなければ作成） ---
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cash_collections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            driver_id INT NOT NULL,
            collection_date DATE NOT NULL,
            is_collected TINYINT(1) NOT NULL DEFAULT 1,
            collected_by INT NULL,
            memo TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_driver_date (driver_id, collection_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (PDOException $e) {
    // テーブルが既に存在する場合は無視
}

// --- データ取得関数 ---

function getDriverList($pdo) {
    $stmt = $pdo->prepare("
        SELECT id, name FROM users
        WHERE is_driver = 1 AND is_active = 1
        ORDER BY name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

// 集金チェック状態を取得（cash_collections テーブル）
function getCollectionChecks($pdo, $start_date, $end_date, $driver_id = null) {
    $sql = "SELECT driver_id, collection_date, is_collected, collected_by, updated_at
            FROM cash_collections
            WHERE collection_date BETWEEN ? AND ? AND is_collected = 1";
    $params = [$start_date, $end_date];
    if ($driver_id && $driver_id !== 'all') {
        $sql .= " AND driver_id = ?";
        $params[] = $driver_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $did = $row['driver_id'];
        $date = $row['collection_date'];
        $map[$did][$date] = $row;
    }
    return $map;
}

// 運転者ごとに乗車がある日を取得
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

// 乗務記録の内訳取得
function getRideBreakdown($pdo, $start_date, $end_date, $driver_id) {
    $sql = "SELECT
                r.id, r.ride_date, r.ride_time,
                r.pickup_location, r.dropoff_location,
                r.passenger_count,
                COALESCE(r.total_fare, r.fare + COALESCE(r.charge, 0)) as total_fare,
                r.fare, COALESCE(r.charge, 0) as charge,
                r.payment_method,
                COALESCE(r.cash_amount, 0) as cash_amount,
                COALESCE(r.card_amount, 0) as card_amount
            FROM ride_records r
            WHERE r.driver_id = ? AND r.ride_date BETWEEN ? AND ?
            ORDER BY r.ride_date, r.ride_time";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$driver_id, $start_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- パラメータ取得 ---
$driver_list = getDriverList($pdo);
$selected_driver_id = $_GET['driver_id'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// --- データ取得 ---
$revenue_data = getRevenueByDateRange($pdo, $start_date, $end_date, $selected_driver_id);
$collection_checks = getCollectionChecks($pdo, $start_date, $end_date, $selected_driver_id);

$driver_details = [];
$collection_summary = ['total_drivers' => 0, 'collected_drivers' => 0];
$single_driver_collection = null;
$ride_breakdown = [];

if ($selected_driver_id === 'all') {
    $driver_details = getRevenueByDriver($pdo, $start_date, $end_date);
    foreach ($driver_details as &$detail) {
        if (($detail['ride_count'] ?? 0) > 0) {
            $collection_summary['total_drivers']++;
            $working_dates = getWorkingDates($pdo, $start_date, $end_date, $detail['id']);
            $checked_dates = isset($collection_checks[$detail['id']]) ? array_keys($collection_checks[$detail['id']]) : [];
            $unchecked = array_diff($working_dates, $checked_dates);

            $detail['working_dates'] = $working_dates;
            $detail['checked_dates'] = $checked_dates;
            $detail['is_fully_collected'] = empty($unchecked);
            $detail['collection_count'] = count($checked_dates);
            $detail['working_count'] = count($working_dates);

            if ($detail['is_fully_collected']) {
                $collection_summary['collected_drivers']++;
            }
        } else {
            $detail['is_fully_collected'] = null;
            $detail['collection_count'] = 0;
            $detail['working_count'] = 0;
        }
    }
    unset($detail);
} else {
    $working_dates = getWorkingDates($pdo, $start_date, $end_date, $selected_driver_id);
    $checked_dates = isset($collection_checks[$selected_driver_id]) ? array_keys($collection_checks[$selected_driver_id]) : [];
    $unchecked = array_diff($working_dates, $checked_dates);
    $single_driver_collection = [
        'working_dates' => $working_dates,
        'checked_dates' => $checked_dates,
        'unchecked_dates' => array_values($unchecked),
        'is_fully_collected' => empty($unchecked) && !empty($working_dates),
        'collection_count' => count($checked_dates),
        'working_count' => count($working_dates),
    ];
    // 個別運転者の内訳も取得
    $ride_breakdown = getRideBreakdown($pdo, $start_date, $end_date, $selected_driver_id);
}

// --- ページ設定 ---
$page_config = getPageConfiguration('cash_management');
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
        'css/ui-unified-v3.css'
    ]
];
$page_data = renderCompletePage(
    $page_config['title'], $user_name, $user_role,
    'cash_management', $page_config['icon'],
    $page_config['title'], $page_config['subtitle'],
    $page_config['category'], $page_options
);
echo $page_data['html_head'];
?>

<style>
.filter-card { margin-bottom: 24px; }
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px; margin-bottom: 24px;
}
.stat-item {
    background: white; border: 1px solid #e0e0e0;
    border-radius: 8px; padding: 20px; text-align: center;
}
.stat-value { font-size: 1.75rem; font-weight: 600; color: #333; margin-bottom: 4px; }
.stat-label { font-size: 0.875rem; color: #666; font-weight: 500; }
.period-badge {
    display: inline-block; padding: 8px 16px;
    background: #f5f5f5; border-radius: 4px;
    font-size: 0.9rem; margin-bottom: 24px;
}
.driver-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}
.driver-card {
    background: white; border: 1px solid #e0e0e0;
    border-radius: 8px; padding: 16px;
    transition: box-shadow 0.2s;
}
.driver-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.driver-card-header {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 1.1rem; font-weight: 600; color: #333;
    margin-bottom: 12px; padding-bottom: 8px;
    border-bottom: 2px solid #f5f5f5;
}
.driver-stat-row {
    display: flex; justify-content: space-between;
    padding: 6px 0; font-size: 0.9rem;
}
.driver-stat-label { color: #666; }
.driver-stat-value { font-weight: 600; color: #333; }
.additional-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px; margin-top: 24px;
}

/* === 集金チェックボックス === */
.collection-check {
    display: flex; align-items: center; gap: 6px;
    cursor: pointer; user-select: none;
}
.collection-check input[type="checkbox"] { display: none; }
.collection-check .check-box {
    width: 22px; height: 22px; border-radius: 4px;
    border: 2px solid #bdbdbd; display: flex;
    align-items: center; justify-content: center;
    transition: all 0.15s; flex-shrink: 0;
}
.collection-check input:checked + .check-box {
    background: #4caf50; border-color: #4caf50;
}
.collection-check input:checked + .check-box::after {
    content: '\f00c'; font-family: 'Font Awesome 6 Free';
    font-weight: 900; color: white; font-size: 12px;
}
.collection-check .check-label {
    font-size: 0.75rem; font-weight: 600; line-height: 1.2;
}
.collection-check .check-label.collected { color: #2e7d32; }
.collection-check .check-label.uncollected { color: #999; }

/* === 集金サマリー === */
.collection-summary {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 24px; padding: 16px 20px;
    background: white; border: 1px solid #e0e0e0; border-radius: 8px;
}
.collection-summary-icon {
    font-size: 1.5rem; width: 48px; height: 48px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; flex-shrink: 0;
}
.collection-summary-icon.all-done { background: #e8f5e9; color: #2e7d32; }
.collection-summary-icon.partial  { background: #fff3e0; color: #e65100; }
.collection-summary-icon.none     { background: #f5f5f5; color: #9e9e9e; }
.collection-summary-text { flex: 1; }
.collection-summary-title { font-size: 0.875rem; color: #666; margin-bottom: 2px; }
.collection-summary-value { font-size: 1.1rem; font-weight: 600; color: #333; }
.collection-progress { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.collection-progress-bar {
    flex: 1; height: 6px; background: #e0e0e0;
    border-radius: 3px; overflow: hidden;
}
.collection-progress-fill {
    height: 100%; border-radius: 3px; transition: width 0.3s;
}
.collection-progress-fill.complete { background: #4caf50; }
.collection-progress-fill.partial  { background: #ff9800; }
.collection-progress-text { font-size: 0.75rem; color: #666; white-space: nowrap; }

/* === 個別ドライバー集金ステータスカード === */
.collection-status-card {
    margin-bottom: 24px; border: 1px solid #e0e0e0;
    border-radius: 8px; overflow: hidden;
}
.collection-status-card .status-header {
    padding: 16px 20px; display: flex;
    align-items: center; gap: 12px;
}
.collection-status-card .status-header.collected   { background: #e8f5e9; border-bottom: 2px solid #4caf50; }
.collection-status-card .status-header.uncollected  { background: #fbe9e7; border-bottom: 2px solid #e53935; }
.collection-status-card .status-header.no-data      { background: #f5f5f5; border-bottom: 2px solid #bdbdbd; }
.collection-status-card .status-icon { font-size: 1.5rem; }
.collection-status-card .status-text { font-size: 1rem; font-weight: 600; flex: 1; }
.collection-status-card .status-detail {
    padding: 12px 20px; background: white;
    font-size: 0.875rem; color: #555;
}

.date-check-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 12px;
    font-size: 0.75rem; font-weight: 600; margin: 2px;
}
.date-check-badge.checked   { background: #e8f5e9; color: #2e7d32; }
.date-check-badge.unchecked { background: #fbe9e7; color: #c62828; }

/* === 乗務記録内訳テーブル === */
.breakdown-section { margin-top: 24px; }
.breakdown-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.875rem;
}
.breakdown-table th {
    background: #f5f5f5; padding: 10px 12px;
    text-align: left; font-weight: 600; color: #555;
    border-bottom: 2px solid #e0e0e0;
    white-space: nowrap;
}
.breakdown-table td {
    padding: 10px 12px; border-bottom: 1px solid #f0f0f0;
}
.breakdown-table tr:hover td { background: #fafafa; }
.breakdown-table .text-right { text-align: right; }
.payment-badge {
    display: inline-block; padding: 2px 8px;
    border-radius: 10px; font-size: 0.7rem; font-weight: 600;
}
.payment-badge.cash { background: #e8f5e9; color: #2e7d32; }
.payment-badge.card { background: #e3f2fd; color: #1565c0; }
.payment-badge.other { background: #f5f5f5; color: #666; }
.breakdown-date-header {
    background: #f8f9fa; font-weight: 600;
    padding: 8px 12px; border-bottom: 1px solid #e0e0e0;
}
.breakdown-date-header td { color: #333; }
.breakdown-subtotal td {
    font-weight: 600; background: #fafafa;
    border-top: 1px solid #ddd;
}

/* カウントツールリンク */
.tool-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 6px;
    font-size: 0.8rem; font-weight: 500;
    text-decoration: none; transition: background 0.15s;
}
.tool-link.cash-count {
    background: #f3e5f5; color: #6a1b9a;
}
.tool-link.cash-count:hover { background: #e1bee7; }

@media (max-width: 768px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .driver-grid { grid-template-columns: 1fr; }
    .breakdown-table { font-size: 0.8rem; }
    .breakdown-table th, .breakdown-table td { padding: 8px 6px; }
}
</style>

<?php echo $page_data['system_header']; ?>
<?php echo $page_data['page_header']; ?>

<div class="container-fluid mt-4">
    <!-- フィルター -->
    <div class="card filter-card">
        <div class="card-body">
            <h6 class="card-title mb-3">検索条件</h6>
            <form method="GET" action="cash_management.php">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="driver_id" class="form-label">運転者</label>
                        <select class="form-select" id="driver_id" name="driver_id">
                            <option value="all" <?php echo $selected_driver_id === 'all' ? 'selected' : ''; ?>>すべて</option>
                            <?php foreach ($driver_list as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $selected_driver_id == $d['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['name']); ?>
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
                        <button type="submit" class="btn btn-primary w-100">検索</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 期間表示 -->
    <div class="period-badge">
        <?php echo date('Y年m月d日', strtotime($start_date)); ?> 〜
        <?php echo date('Y年m月d日', strtotime($end_date)); ?>
        <?php if ($selected_driver_id === 'all'): ?>
            <strong class="ms-2">（全運転者）</strong>
        <?php else: ?>
            <?php
                $selected_driver_name = '';
                foreach ($driver_list as $d) {
                    if ($d['id'] == $selected_driver_id) { $selected_driver_name = $d['name']; break; }
                }
            ?>
            <strong class="ms-2">（<?php echo htmlspecialchars($selected_driver_name); ?>）</strong>
        <?php endif; ?>
        <a href="driver_cash_count.php" class="tool-link cash-count ms-3">
            <i class="fas fa-calculator"></i> 現金カウントツール
        </a>
    </div>

    <!-- ========== 全運転者ビュー ========== -->
    <?php if ($selected_driver_id === 'all'): ?>

        <!-- 集金処理状況サマリー -->
        <?php if (!empty($driver_details)):
            $icon_class = 'none'; $icon = 'fa-clock';
            if ($collection_summary['total_drivers'] > 0) {
                if ($collection_summary['collected_drivers'] === $collection_summary['total_drivers']) {
                    $icon_class = 'all-done'; $icon = 'fa-check-circle';
                } elseif ($collection_summary['collected_drivers'] > 0) {
                    $icon_class = 'partial'; $icon = 'fa-exclamation-circle';
                }
            }
            $pct = $collection_summary['total_drivers'] > 0
                ? round($collection_summary['collected_drivers'] / $collection_summary['total_drivers'] * 100) : 0;
        ?>
        <div class="collection-summary" id="collectionSummary">
            <div class="collection-summary-icon <?php echo $icon_class; ?>" id="summaryIcon">
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            <div class="collection-summary-text">
                <div class="collection-summary-title">集金処理状況</div>
                <div class="collection-summary-value" id="summaryValue">
                    <?php echo $collection_summary['collected_drivers']; ?> / <?php echo $collection_summary['total_drivers']; ?> 名完了
                </div>
                <div class="collection-progress">
                    <div class="collection-progress-bar">
                        <div class="collection-progress-fill <?php echo $pct === 100 ? 'complete' : 'partial'; ?>"
                             id="summaryBar" style="width: <?php echo $pct; ?>%"></div>
                    </div>
                    <span class="collection-progress-text" id="summaryPct"><?php echo $pct; ?>%</span>
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

        <!-- 運転者別詳細 -->
        <?php if (!empty($driver_details)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h6 class="card-title mb-3">運転者別詳細</h6>
                <div class="driver-grid">
                    <?php foreach ($driver_details as $detail): ?>
                    <div class="driver-card" id="driver-card-<?php echo $detail['id']; ?>">
                        <div class="driver-card-header">
                            <span><?php echo htmlspecialchars($detail['name']); ?></span>
                            <?php if (($detail['ride_count'] ?? 0) > 0): ?>
                                <?php
                                    // 単日の場合のみチェックボックス表示
                                    $is_single_day = ($start_date === $end_date);
                                    $is_checked = $detail['is_fully_collected'] === true;
                                ?>
                                <?php if ($is_single_day): ?>
                                <label class="collection-check">
                                    <input type="checkbox"
                                           data-driver-id="<?php echo $detail['id']; ?>"
                                           data-date="<?php echo $start_date; ?>"
                                           <?php echo $is_checked ? 'checked' : ''; ?>
                                           onchange="toggleCollection(this)">
                                    <span class="check-box"></span>
                                    <span class="check-label <?php echo $is_checked ? 'collected' : 'uncollected'; ?>">
                                        <?php echo $is_checked ? '集金済' : '未集金'; ?>
                                    </span>
                                </label>
                                <?php else: ?>
                                    <?php if ($is_checked): ?>
                                        <span class="date-check-badge checked"><i class="fas fa-check-circle"></i> 集金済み</span>
                                    <?php else: ?>
                                        <span class="date-check-badge unchecked">
                                            <i class="fas fa-exclamation-circle"></i>
                                            未集金 <?php if ($detail['working_count'] > 1) echo '(' . $detail['collection_count'] . '/' . $detail['working_count'] . '日)'; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size:0.75rem;color:#999;">乗車なし</span>
                            <?php endif; ?>
                        </div>
                        <div class="driver-stat-row">
                            <span class="driver-stat-label">総売上</span>
                            <span class="driver-stat-value">¥<?php echo number_format($detail['total_revenue'] ?? 0); ?></span>
                        </div>
                        <div class="driver-stat-row">
                            <span class="driver-stat-label">乗車回数</span>
                            <span class="driver-stat-value"><?php echo number_format($detail['ride_count'] ?? 0); ?>回</span>
                        </div>
                        <div class="driver-stat-row">
                            <span class="driver-stat-label">現金売上</span>
                            <span class="driver-stat-value">¥<?php echo number_format($detail['cash_total'] ?? 0); ?></span>
                        </div>
                        <div class="driver-stat-row">
                            <span class="driver-stat-label">カード売上</span>
                            <span class="driver-stat-value">¥<?php echo number_format($detail['card_total'] ?? 0); ?></span>
                        </div>
                        <?php if (($detail['ride_count'] ?? 0) > 0): ?>
                        <div style="margin-top:8px;text-align:right;">
                            <a href="?driver_id=<?php echo $detail['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>"
                               style="font-size:0.8rem;color:#1976d2;text-decoration:none;">
                                内訳を見る <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info">選択した期間には乗車記録がありません。</div>
        <?php endif; ?>

    <?php else: ?>
    <!-- ========== 個別運転者ビュー ========== -->

        <!-- 集金ステータスカード -->
        <?php
            $sc = $single_driver_collection;
            if ($sc['working_count'] === 0) {
                $s_class = 'no-data'; $s_icon = 'fa-minus-circle';
                $s_text = '該当期間に乗車記録がありません'; $s_color = '#9e9e9e';
            } elseif ($sc['is_fully_collected']) {
                $s_class = 'collected'; $s_icon = 'fa-check-circle';
                $s_text = '集金処理完了'; $s_color = '#2e7d32';
            } else {
                $s_class = 'uncollected'; $s_icon = 'fa-exclamation-circle';
                $s_text = '未集金あり（' . $sc['collection_count'] . '/' . $sc['working_count'] . '日完了）';
                $s_color = '#c62828';
            }
        ?>
        <div class="collection-status-card" id="singleStatusCard">
            <div class="status-header <?php echo $s_class; ?>" id="singleStatusHeader">
                <span class="status-icon" style="color:<?php echo $s_color; ?>" id="singleStatusIcon">
                    <i class="fas <?php echo $s_icon; ?>"></i>
                </span>
                <span class="status-text" id="singleStatusText"><?php echo $s_text; ?></span>

                <?php if ($sc['working_count'] > 0): ?>
                <!-- 日付ごとのチェックボックス -->
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-left:auto;">
                    <?php foreach ($sc['working_dates'] as $wd):
                        $wd_checked = in_array($wd, $sc['checked_dates']);
                    ?>
                    <label class="collection-check">
                        <input type="checkbox"
                               data-driver-id="<?php echo $selected_driver_id; ?>"
                               data-date="<?php echo $wd; ?>"
                               <?php echo $wd_checked ? 'checked' : ''; ?>
                               onchange="toggleCollection(this)">
                        <span class="check-box"></span>
                        <span class="check-label <?php echo $wd_checked ? 'collected' : 'uncollected'; ?>">
                            <?php echo date('n/j', strtotime($wd)); ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($sc['working_count'] > 0 && !empty($sc['unchecked_dates'])): ?>
            <div class="status-detail" id="singleStatusDetail">
                <strong>未集金の日付:</strong>
                <?php foreach ($sc['unchecked_dates'] as $ud): ?>
                    <span class="date-check-badge unchecked ms-1"><?php echo date('n/j', strtotime($ud)); ?></span>
                <?php endforeach; ?>
            </div>
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

        <!-- 詳細統計 -->
        <div class="card mb-4">
            <div class="card-body">
                <h6 class="card-title mb-3">詳細統計</h6>
                <div class="additional-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($revenue_data['total_passengers'] ?? 0); ?>人</div>
                        <div class="stat-label">総乗客数</div>
                    </div>
                    <div class="stat-item">
                        <?php
                        $avg = ($revenue_data['ride_count'] ?? 0) > 0
                            ? round(($revenue_data['total_revenue'] ?? 0) / $revenue_data['ride_count']) : 0;
                        ?>
                        <div class="stat-value">¥<?php echo number_format($avg); ?></div>
                        <div class="stat-label">平均単価</div>
                    </div>
                    <div class="stat-item">
                        <?php
                        $cash_rate = ($revenue_data['total_revenue'] ?? 0) > 0
                            ? round(($revenue_data['cash_total'] ?? 0) / $revenue_data['total_revenue'] * 100, 1) : 0;
                        ?>
                        <div class="stat-value"><?php echo $cash_rate; ?>%</div>
                        <div class="stat-label">現金利用率</div>
                    </div>
                    <div class="stat-item">
                        <?php
                        $card_rate = ($revenue_data['total_revenue'] ?? 0) > 0
                            ? round(($revenue_data['card_total'] ?? 0) / $revenue_data['total_revenue'] * 100, 1) : 0;
                        ?>
                        <div class="stat-value"><?php echo $card_rate; ?>%</div>
                        <div class="stat-label">カード利用率</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 乗務記録の内訳 -->
        <?php if (!empty($ride_breakdown)): ?>
        <div class="card mb-4 breakdown-section">
            <div class="card-body">
                <h6 class="card-title mb-3">
                    <i class="fas fa-receipt"></i> 乗務記録 内訳
                    <span style="font-size:0.8rem;font-weight:400;color:#666;margin-left:8px;">
                        <?php echo count($ride_breakdown); ?>件
                    </span>
                </h6>
                <div style="overflow-x:auto;">
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>時刻</th>
                            <th>乗車地</th>
                            <th>降車地</th>
                            <th class="text-right">人数</th>
                            <th class="text-right">運賃</th>
                            <th class="text-right">料金</th>
                            <th class="text-right">合計</th>
                            <th>支払</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $current_date = '';
                        $date_cash = 0;
                        $date_card = 0;
                        $date_total = 0;
                        $date_count = 0;

                        foreach ($ride_breakdown as $i => $ride):
                            $ride_date = $ride['ride_date'];
                            $next_date = isset($ride_breakdown[$i + 1]) ? $ride_breakdown[$i + 1]['ride_date'] : null;

                            // 日付ヘッダー
                            if ($ride_date !== $current_date):
                                if ($current_date !== '' && $date_count > 0):
                        ?>
                        <tr class="breakdown-subtotal">
                            <td colspan="4" style="text-align:right;">小計 (<?php echo $date_count; ?>件)</td>
                            <td></td>
                            <td></td>
                            <td class="text-right">¥<?php echo number_format($date_total); ?></td>
                            <td>
                                <?php if ($date_cash > 0): ?><span class="payment-badge cash">現金 ¥<?php echo number_format($date_cash); ?></span><?php endif; ?>
                                <?php if ($date_card > 0): ?><span class="payment-badge card">カード ¥<?php echo number_format($date_card); ?></span><?php endif; ?>
                            </td>
                        </tr>
                        <?php
                                endif;
                                $current_date = $ride_date;
                                $date_cash = 0; $date_card = 0; $date_total = 0; $date_count = 0;
                        ?>
                        <tr class="breakdown-date-header">
                            <td colspan="8">
                                <i class="fas fa-calendar-day" style="margin-right:6px;color:#1976d2;"></i>
                                <?php echo date('Y年m月d日（', strtotime($ride_date)) . ['日','月','火','水','木','金','土'][date('w', strtotime($ride_date))] . '）'; ?>
                            </td>
                        </tr>
                        <?php endif;

                            $fare_total = $ride['total_fare'] ?? 0;
                            $date_total += $fare_total;
                            $date_cash += $ride['cash_amount'];
                            $date_card += $ride['card_amount'];
                            $date_count++;

                            $pm = $ride['payment_method'] ?? '';
                            if (mb_strpos($pm, '現金') !== false) $pm_class = 'cash';
                            elseif (mb_strpos($pm, 'カード') !== false) $pm_class = 'card';
                            else $pm_class = 'other';
                        ?>
                        <tr>
                            <td><?php echo $ride['ride_time'] ? date('H:i', strtotime($ride['ride_time'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($ride['pickup_location'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($ride['dropoff_location'] ?? '-'); ?></td>
                            <td class="text-right"><?php echo $ride['passenger_count'] ?? '-'; ?></td>
                            <td class="text-right">¥<?php echo number_format($ride['fare'] ?? 0); ?></td>
                            <td class="text-right">¥<?php echo number_format($ride['charge'] ?? 0); ?></td>
                            <td class="text-right"><strong>¥<?php echo number_format($fare_total); ?></strong></td>
                            <td><span class="payment-badge <?php echo $pm_class; ?>"><?php echo htmlspecialchars($pm ?: '-'); ?></span></td>
                        </tr>
                        <?php
                            // 最後のレコード or 次が別日 → 小計出力
                            if ($next_date === null || $next_date !== $current_date):
                        ?>
                        <tr class="breakdown-subtotal">
                            <td colspan="4" style="text-align:right;">小計 (<?php echo $date_count; ?>件)</td>
                            <td></td>
                            <td></td>
                            <td class="text-right">¥<?php echo number_format($date_total); ?></td>
                            <td>
                                <?php if ($date_cash > 0): ?><span class="payment-badge cash">現金 ¥<?php echo number_format($date_cash); ?></span><?php endif; ?>
                                <?php if ($date_card > 0): ?><span class="payment-badge card">カード ¥<?php echo number_format($date_card); ?></span><?php endif; ?>
                            </td>
                        </tr>
                        <?php
                                $date_cash = 0; $date_card = 0; $date_total = 0; $date_count = 0;
                                $current_date = $next_date;
                            endif;
                        endforeach;
                        ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php elseif ($selected_driver_id !== 'all'): ?>
        <div class="alert alert-info">この期間に乗務記録はありません。</div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
// 集金チェックボックスのトグル
function toggleCollection(checkbox) {
    const driverId = checkbox.dataset.driverId;
    const date = checkbox.dataset.date;
    const label = checkbox.parentElement.querySelector('.check-label');

    checkbox.disabled = true;

    fetch('api/toggle_collection.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ driver_id: parseInt(driverId), collection_date: date })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const isCollected = data.is_collected;
            checkbox.checked = isCollected;
            if (label) {
                // 全運転者ビュー: ラベルテキスト更新
                if (label.textContent.includes('集金済') || label.textContent.includes('未集金')) {
                    label.textContent = isCollected ? '集金済' : '未集金';
                }
                label.className = 'check-label ' + (isCollected ? 'collected' : 'uncollected');
            }
            updateSummary();
        } else {
            // 失敗時は元に戻す
            checkbox.checked = !checkbox.checked;
            alert('エラー: ' + (data.message || '保存に失敗しました'));
        }
    })
    .catch(err => {
        checkbox.checked = !checkbox.checked;
        console.error('通信エラー:', err);
    })
    .finally(() => {
        checkbox.disabled = false;
    });
}

// サマリーの動的更新（全運転者ビュー用）
function updateSummary() {
    const summaryEl = document.getElementById('summaryValue');
    if (!summaryEl) return;

    const cards = document.querySelectorAll('.driver-card');
    let total = 0, collected = 0;
    cards.forEach(card => {
        const cb = card.querySelector('input[type="checkbox"]');
        if (cb) {
            total++;
            if (cb.checked) collected++;
        }
    });

    if (total === 0) return;
    summaryEl.textContent = collected + ' / ' + total + ' 名完了';

    const pct = Math.round(collected / total * 100);
    const bar = document.getElementById('summaryBar');
    const pctText = document.getElementById('summaryPct');
    if (bar) {
        bar.style.width = pct + '%';
        bar.className = 'collection-progress-fill ' + (pct === 100 ? 'complete' : 'partial');
    }
    if (pctText) pctText.textContent = pct + '%';

    const icon = document.getElementById('summaryIcon');
    if (icon) {
        icon.className = 'collection-summary-icon ' + (pct === 100 ? 'all-done' : (collected > 0 ? 'partial' : 'none'));
        icon.innerHTML = '<i class="fas ' + (pct === 100 ? 'fa-check-circle' : (collected > 0 ? 'fa-exclamation-circle' : 'fa-clock')) + '"></i>';
    }
}
</script>

<?php echo $page_data['footer'] ?? ''; ?>
</body>
</html>
