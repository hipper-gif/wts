<?php
// =================================================================
// 介護タクシー予約管理カレンダーシステム - メイン画面
// 
// ファイル: /Smiley/taxi/wts/calendar/index.php
// 機能: カレンダー表示・予約管理・WTS連携
// 基盤: 福祉輸送管理システム v3.1 統一ヘッダー対応
// 作成日: 2025年9月27日
// 最終更新: 2025年10月6日（パス修正版）
// =================================================================

session_start();

// 基盤システム読み込み
require_once '../config/database.php';
require_once '../includes/unified-header.php';

// ログインチェック
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
$view_mode = $_GET['view'] ?? 'month';
$current_date = $_GET['date'] ?? date('Y-m-d');
$driver_filter = $_GET['driver'] ?? 'all';
$access_level = 'full';

// 運転者一覧取得
$stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
$drivers = $stmt->fetchAll();

// 車両一覧取得
$stmt = $pdo->query("SELECT id, vehicle_number, model FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
$vehicles = $stmt->fetchAll();

// ページ設定
$page_config = [
    'title' => '予約管理カレンダー',
    'subtitle' => '介護タクシー予約の作成・管理・スケジュール確認',
    'description' => 'タイムツリーから移行した予約管理システム',
    'icon' => 'calendar-alt',
    'category' => '予約管理'
];

// 統一ヘッダーでページ生成（正しいパス指定）
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        // FullCalendar CDN
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/main.min.css',
        // カレンダー専用CSS
        'css/calendar-custom.css'
    ],
    'additional_js' => [
        // FullCalendar CDN
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/main.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/locales/ja.min.js',
        // カレンダー専用JS
        'js/calendar.js',
        'js/reservation.js',
        'js/vehicle_constraints.js',
        // 既存のWTS統一JS（相対パスで正しく指定）
        '../js/ui-interactions.js'
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
                                <button type="button" class="btn btn-success btn-lg me-2" id="createReservationBtn">
                                    <i class="fas fa-plus me-2"></i>新規予約作成
                                </button>
                                
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
                        <p class="text-muted text-center">データを読み込み中...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- 予約作成・編集モーダル -->
<div class="modal fade" id="reservationModal" tabindex="-1" aria-labelledby="reservationModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="reservationModalTitle">新規予約作成</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <form id="reservationForm">
                    <!-- 隠しフィールド -->
                    <input type="hidden" id="reservationId" name="reservationId">
                    <input type="hidden" id="parentReservationId" name="parentReservationId">

                    <!-- 基本情報 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-calendar-check me-2"></i>基本情報</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="reservationDate" class="form-label">予約日 <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="reservationDate" name="reservationDate" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="reservationTime" class="form-label">予約時刻 <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="reservationTime" name="reservationTime" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="clientName" class="form-label">利用者名 <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="clientName" name="clientName" placeholder="例: 山田太郎" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="pickupLocation" class="form-label">乗車場所 <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="pickupLocation" name="pickupLocation" placeholder="例: 東京都渋谷区..." required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="dropoffLocation" class="form-label">降車場所 <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="dropoffLocation" name="dropoffLocation" placeholder="例: 渋谷駅" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- サービス詳細 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>サービス詳細</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="passengerCount" class="form-label">乗客数</label>
                                    <input type="number" class="form-control" id="passengerCount" name="passengerCount" min="1" max="4" value="1">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="serviceType" class="form-label">サービス種別 <span class="text-danger">*</span></label>
                                    <select class="form-select" id="serviceType" name="serviceType" required>
                                        <option value="お迎え">お迎え</option>
                                        <option value="送り">送り</option>
                                        <option value="往復">往復</option>
                                        <option value="病院">病院</option>
                                        <option value="買い物">買い物</option>
                                        <option value="その他">その他</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="driverId" class="form-label">運転者</label>
                                    <select class="form-select" id="driverId" name="driverId">
                                        <option value="">未指定</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="vehicleId" class="form-label">車両</label>
                                    <select class="form-select" id="vehicleId" name="vehicleId">
                                        <option value="">未指定</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?= $vehicle['id'] ?>"><?= htmlspecialchars($vehicle['vehicle_number']) ?> (<?= htmlspecialchars($vehicle['model']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="rentalService" class="form-label">レンタルサービス</label>
                                    <select class="form-select" id="rentalService" name="rentalService">
                                        <option value="なし">なし</option>
                                        <option value="車椅子">車椅子</option>
                                        <option value="ストレッチャー">ストレッチャー</option>
                                        <option value="酸素ボンベ">酸素ボンベ</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">追加サービス</label>
                                    <div class="d-flex flex-wrap gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="entranceAssistance" name="entranceAssistance">
                                            <label class="form-check-label" for="entranceAssistance">玄関介助</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="disabilityCard" name="disabilityCard">
                                            <label class="form-check-label" for="disabilityCard">障害者手帳</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="careServiceUser" name="careServiceUser">
                                            <label class="form-check-label" for="careServiceUser">介護保険利用</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="isTimeCritical" name="isTimeCritical" checked>
                                            <label class="form-check-label" for="isTimeCritical">時間厳守</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="hospitalEscortStaff" class="form-label">病院付き添いスタッフ</label>
                                    <input type="text" class="form-control" id="hospitalEscortStaff" name="hospitalEscortStaff" placeholder="スタッフ名">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="dualAssistanceStaff" class="form-label">2名介助スタッフ</label>
                                    <input type="text" class="form-control" id="dualAssistanceStaff" name="dualAssistanceStaff" placeholder="スタッフ名">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 紹介者情報 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-user-tie me-2"></i>紹介者情報</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="referrerType" class="form-label">紹介者種別 <span class="text-danger">*</span></label>
                                    <select class="form-select" id="referrerType" name="referrerType" required>
                                        <option value="CM">CM (ケアマネージャー)</option>
                                        <option value="MSW">MSW (医療ソーシャルワーカー)</option>
                                        <option value="病院">病院</option>
                                        <option value="施設">施設</option>
                                        <option value="個人">個人</option>
                                        <option value="その他">その他</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="referrerName" class="form-label">紹介者名</label>
                                    <input type="text" class="form-control" id="referrerName" name="referrerName" placeholder="紹介者の氏名">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="referrerContact" class="form-label">紹介者連絡先</label>
                                    <input type="text" class="form-control" id="referrerContact" name="referrerContact" placeholder="電話番号またはメール">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 料金・支払い -->
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-yen-sign me-2"></i>料金・支払い</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="estimatedFare" class="form-label">見積料金</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="estimatedFare" name="estimatedFare" placeholder="0">
                                        <span class="input-group-text">円</span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="actualFare" class="form-label">実際の料金</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="actualFare" name="actualFare" placeholder="0">
                                        <span class="input-group-text">円</span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="paymentMethod" class="form-label">支払い方法</label>
                                    <select class="form-select" id="paymentMethod" name="paymentMethod">
                                        <option value="現金">現金</option>
                                        <option value="クレジットカード">クレジットカード</option>
                                        <option value="請求書">請求書</option>
                                        <option value="介護保険">介護保険</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 備考 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>備考・特記事項</h6>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control" id="specialNotes" name="specialNotes" rows="3" placeholder="特記事項や注意事項を入力してください"></textarea>
                        </div>
                    </div>

                    <!-- エラー表示エリア -->
                    <div id="reservationFormErrors" class="alert alert-danger d-none" role="alert"></div>

                    <!-- 制約警告エリア -->
                    <div id="constraintWarnings" class="alert alert-warning d-none" role="alert"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-info" id="saveAndCreateReturnBtn">
                    <i class="fas fa-exchange-alt me-2"></i>保存して復路作成
                </button>
                <button type="button" class="btn btn-primary" id="saveReservationBtn">
                    <i class="fas fa-save me-2"></i>保存
                </button>
            </div>
        </div>
    </div>
</div>

<!-- カレンダー設定オブジェクト初期化 -->
<script>
// グローバルカレンダー設定
window.calendarConfig = {
    // 初期設定
    initialDate: '<?= $current_date ?>',
    initialView: '<?= $view_mode ?>',
    driverFilter: '<?= $driver_filter ?>',
    accessLevel: '<?= $access_level ?>',

    // API URLs
    apiUrls: {
        getReservations: 'api/get_reservations.php',
        saveReservation: 'api/save_reservation.php',
        getAvailability: 'api/get_availability.php',
        createReturnTrip: 'api/create_return_trip.php',
        convertToRide: 'api/convert_to_ride.php'
    },

    // マスターデータ
    drivers: <?= json_encode($drivers, JSON_UNESCAPED_UNICODE) ?>,
    vehicles: <?= json_encode($vehicles, JSON_UNESCAPED_UNICODE) ?>,

    // ユーザー情報
    currentUser: {
        id: <?= $user_id ?>,
        name: '<?= addslashes($user_name) ?>',
        role: '<?= $user_role ?>'
    },

    // カレンダー表示設定
    businessHours: {
        startTime: '08:00:00',
        endTime: '19:00:00'
    },

    // ステータス色設定
    statusColors: {
        '予約': '#2196F3',
        '進行中': '#FF9800',
        '完了': '#4CAF50',
        'キャンセル': '#757575'
    }
};

console.log('✅ カレンダー設定初期化完了', window.calendarConfig);
</script>

<?php echo $page_data['html_footer']; ?>
