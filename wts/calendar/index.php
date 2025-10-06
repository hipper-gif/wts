<?php
// =================================================================
// 介護タクシー予約管理カレンダーシステム - メイン画面
// 
// ファイル: /Smiley/taxi/wts/calendar/index.php
// 機能: カレンダー表示・予約管理・WTS連携
// 基盤: 福祉輸送管理システム v3.1 統一ヘッダー対応
// 作成日: 2025年9月27日
// =================================================================

session_start();

// 基盤システム読み込み
require_once '../config/database.php';
require_once '../includes/unified-header.php';

// ログインチェック（正しい方法）
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// データベース接続
$pdo = getDBConnection();

// ユーザー情報取得
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'] ?? 'User';

// カレンダー設定取得
$view_mode = $_GET['view'] ?? 'month'; // month, week, day
$current_date = $_GET['date'] ?? date('Y-m-d');
$driver_filter = $_GET['driver'] ?? 'all';

// 権限チェック - 協力会社の場合は制限
$access_level = 'full';
if ($user_role === 'partner_company') {
    $stmt = $pdo->prepare("SELECT access_level FROM partner_companies WHERE id = ?");
    $stmt->execute([$user_id]);
    $company_data = $stmt->fetch();
    $access_level = $company_data['access_level'] ?? '閲覧のみ';
}

// 運転者一覧取得
$stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
$drivers = $stmt->fetchAll();

// 車両一覧取得
$stmt = $pdo->query("SELECT id, vehicle_number, model FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
$vehicles = $stmt->fetchAll();

// 協力会社一覧取得（テーブルが存在する場合）
try {
    $stmt = $pdo->query("SELECT id, company_name, display_color FROM partner_companies WHERE is_active = 1 ORDER BY sort_order");
    $partner_companies = $stmt->fetchAll();
} catch (Exception $e) {
    // partner_companiesテーブルが存在しない場合は空配列
    $partner_companies = [];
}

// ページ設定
$page_config = [
    'title' => '予約管理カレンダー',
    'subtitle' => '介護タクシー予約の作成・管理・スケジュール確認',
    'description' => 'タイムツリーから移行した予約管理システム。復路作成機能、車両制約、協力会社管理に対応',
    'icon' => 'calendar-alt',
    'category' => '予約管理'
];

// 統一ヘッダーでページ生成
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/main.min.css',
        'calendar/css/calendar.css',
        'calendar/css/reservation.css'
    ],
    'additional_js' => [
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/main.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/locales/ja.min.js',
        'calendar/js/calendar.js',
        'calendar/js/reservation.js',
        'calendar/js/vehicle_constraints.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => '../dashboard.php'],
        ['text' => '予約管理', 'url' => '#'],
        ['text' => 'カレンダー', 'url' => 'index.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'calendar_system',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

// HTMLヘッダー出力
echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<!-- メインコンテンツ開始 -->
<main class="main-content">
    <div class="container-fluid py-4">
        
        <!-- カレンダーコントロールパネル -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <!-- 表示切り替え -->
                            <div class="col-lg-4 col-md-6 mb-3 mb-lg-0">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="viewMode" id="monthView" value="month" <?= $view_mode === 'month' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="monthView">
                                        <i class="fas fa-calendar me-1"></i>月表示
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="viewMode" id="weekView" value="week" <?= $view_mode === 'week' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="weekView">
                                        <i class="fas fa-calendar-week me-1"></i>週表示
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="viewMode" id="dayView" value="day" <?= $view_mode === 'day' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="dayView">
                                        <i class="fas fa-calendar-day me-1"></i>日表示
                                    </label>
                                </div>
                            </div>
                            
                            <!-- 運転者フィルター -->
                            <div class="col-lg-3 col-md-6 mb-3 mb-lg-0">
                                <select class="form-select" id="driverFilter">
                                    <option value="all">全運転者</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>" <?= $driver_filter == $driver['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($driver['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- 新規予約作成ボタン -->
                            <div class="col-lg-5 text-lg-end">
                                <?php if ($access_level !== '閲覧のみ'): ?>
                                    <button type="button" class="btn btn-success btn-lg me-2" id="createReservationBtn">
                                        <i class="fas fa-plus me-2"></i>新規予約作成
                                    </button>
                                <?php endif; ?>
                                
                                <button type="button" class="btn btn-primary" id="todayBtn">
                                    <i class="fas fa-calendar-day me-1"></i>今日
                                    </button>
                                
                                <div class="btn-group ms-2">
                                    <button type="button" class="btn btn-outline-secondary" id="prevBtn">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="nextBtn">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- カレンダー表示エリア -->
        <div class="row">
            <!-- メインカレンダー -->
            <div class="col-lg-9">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <div id="calendar"></div>
                    </div>
                </div>
            </div>
            
            <!-- サイドバー -->
            <div class="col-lg-3">
                <!-- 本日の概要 -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calendar-day me-2"></i>本日の概要
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>予約件数</span>
                            <span class="badge bg-primary fs-6" id="todayReservationCount">0件</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>完了件数</span>
                            <span class="badge bg-success fs-6" id="todayCompletedCount">0件</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span>進行中</span>
                            <span class="badge bg-warning fs-6" id="todayInProgressCount">0件</span>
                        </div>
                    </div>
                </div>
                
                <!-- 車両状況 -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-car me-2"></i>車両状況
                        </h5>
                    </div>
                    <div class="card-body" id="vehicleStatusArea">
                        <!-- 車両状況はJavaScriptで動的生成 -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// JavaScript変数として必要なデータを出力
window.calendarConfig = {
    currentDate: '<?= $current_date ?>',
    viewMode: '<?= $view_mode ?>',
    driverFilter: '<?= $driver_filter ?>',
    accessLevel: '<?= $access_level ?>',
    userId: <?= $user_id ?>,
    userRole: '<?= $user_role ?>',
    apiUrls: {
        getReservations: 'api/get_reservations.php',
        saveReservation: 'api/save_reservation.php',
        createReturnTrip: 'api/create_return_trip.php',
        getAvailability: 'api/get_availability.php',
        convertToRide: 'api/convert_to_ride.php'
    },
    drivers: <?= json_encode($drivers) ?>,
    vehicles: <?= json_encode($vehicles) ?>,
    partnerCompanies: <?= json_encode($partner_companies) ?>
};
</script>

<?php
// 統一フッター出力
echo $page_data['html_footer'];
?>
