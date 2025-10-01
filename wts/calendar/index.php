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
require_once '../functions.php';
require_once '../includes/unified-header.php';
require_once 'includes/calendar_functions.php';

// セッション・権限チェック
checkLogin();

// データベース接続
$pdo = getDatabaseConnection();

// ユーザー情報取得
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

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
$stmt = $pdo->query("SELECT id, full_name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY full_name");
$drivers = $stmt->fetchAll();

// 車両一覧取得
$stmt = $pdo->query("SELECT id, vehicle_number, model FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
$vehicles = $stmt->fetchAll();

// 協力会社一覧取得
$stmt = $pdo->query("SELECT id, company_name, display_color FROM partner_companies WHERE is_active = 1 ORDER BY sort_order");
$partner_companies = $stmt->fetchAll();

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
                                            <?= htmlspecialchars($driver['full_name']) ?>
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
                                    <i class="fas fa-today me-1"></i>今日
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
                
                <!-- 運転者状況 -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-warning text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-users me-2"></i>運転者状況
                        </h5>
                    </div>
                    <div class="card-body" id="driverStatusArea">
                        <!-- 運転者状況はJavaScriptで動的生成 -->
                    </div>
                </div>
                
                <!-- よく使う場所 -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-map-marker-alt me-2"></i>よく使う場所
                        </h5>
                    </div>
                    <div class="card-body" id="frequentLocationsArea">
                        <!-- よく使う場所はJavaScriptで動的生成 -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- 予約作成・編集モーダル -->
<div class="modal fade" id="reservationModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reservationModalTitle">新規予約作成</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="reservationForm">
                    <input type="hidden" id="reservationId" name="id">
                    <input type="hidden" id="parentReservationId" name="parent_reservation_id">
                    
                    <!-- 基本情報行 -->
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label required">予約日</label>
                            <input type="date" class="form-control" id="reservationDate" name="reservation_date" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">予約時刻</label>
                            <input type="time" class="form-control" id="reservationTime" name="reservation_time" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">利用者様名</label>
                            <input type="text" class="form-control" id="clientName" name="client_name" required>
                        </div>
                    </div>
                    
                    <!-- 移動情報行 -->
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label required">お迎え場所</label>
                            <input type="text" class="form-control" id="pickupLocation" name="pickup_location" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">目的地</label>
                            <input type="text" class="form-control" id="dropoffLocation" name="dropoff_location" required>
                        </div>
                    </div>
                    
                    <!-- 詳細設定行 -->
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <label class="form-label">乗車人数</label>
                            <select class="form-select" id="passengerCount" name="passenger_count">
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i === 1 ? 'selected' : '' ?>><?= $i ?>名</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">サービス種別</label>
                            <select class="form-select" id="serviceType" name="service_type" required>
                                <option value="お迎え">お迎え</option>
                                <option value="お送り">お送り</option>
                                <option value="入院">入院</option>
                                <option value="退院">退院</option>
                                <option value="転院">転院</option>
                                <option value="その他">その他</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">運転者</label>
                            <select class="form-select" id="driverId" name="driver_id">
                                <option value="">選択してください</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">車両</label>
                            <select class="form-select" id="vehicleId" name="vehicle_id">
                                <option value="">選択してください</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle['id'] ?>" data-model="<?= $vehicle['model'] ?>">
                                        <?= htmlspecialchars($vehicle['vehicle_number']) ?> (<?= htmlspecialchars($vehicle['model']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- レンタルサービス・支援項目行 -->
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <label class="form-label">レンタルサービス</label>
                            <select class="form-select" id="rentalService" name="rental_service">
                                <option value="なし">なし</option>
                                <option value="車いす">車いす</option>
                                <option value="リクライニング">リクライニング</option>
                                <option value="ストレッチャー">ストレッチャー</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="entranceAssistance" name="entrance_assistance">
                                <label class="form-check-label" for="entranceAssistance">玄関まで送迎</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="disabilityCard" name="disability_card">
                                <label class="form-check-label" for="disabilityCard">障がい者手帳</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="careServiceUser" name="care_service_user">
                                <label class="form-check-label" for="careServiceUser">介護サービス利用</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 紹介者情報行 -->
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <label class="form-label required">紹介者区分</label>
                            <select class="form-select" id="referrerType" name="referrer_type" required>
                                <option value="CM">CM（ケアマネージャー）</option>
                                <option value="SW">SW（ソーシャルワーカー）</option>
                                <option value="家族">ご家族</option>
                                <option value="本人">ご本人</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">紹介者名</label>
                            <input type="text" class="form-control" id="referrerName" name="referrer_name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">連絡先</label>
                            <input type="text" class="form-control" id="referrerContact" name="referrer_contact">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="isTimeCritical" name="is_time_critical" checked>
                                <label class="form-check-label" for="isTimeCritical">時間厳守</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 料金・備考行 -->
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <label class="form-label">見積料金</label>
                            <input type="number" class="form-control" id="estimatedFare" name="estimated_fare" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">支払方法</label>
                            <select class="form-select" id="paymentMethod" name="payment_method">
                                <option value="現金">現金</option>
                                <option value="カード">カード</option>
                                <option value="その他">その他</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">特記事項・備考</label>
                            <textarea class="form-control" id="specialNotes" name="special_notes" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-primary" id="saveReservationBtn">保存</button>
                <button type="button" class="btn btn-success" id="saveAndCreateReturnBtn" style="display:none;">保存して復路作成</button>
            </div>
        </div>
    </div>
</div>

<!-- 車両制約警告モーダル -->
<div class="modal fade" id="vehicleConstraintModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>車両制約エラー
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="constraintMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">確認</button>
            </div>
        </div>
    </div>
</div>

<!-- タイムツリー移行モーダル -->
<div class="modal fade" id="migrationModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-database me-2"></i>タイムツリーデータ移行
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- ステップインジケーター -->
                <div class="step-indicator">
                    <div class="step active">1</div>
                    <div class="step">2</div>
                    <div class="step">3</div>
                </div>
                
                <!-- ステップ1: ファイルアップロード -->
                <div id="migrationStep1" class="migration-step">
                    <h4 class="text-center mb-4">📁 ファイルアップロード</h4>
                    
                    <div class="row">
                        <div class="col-md-8 mx-auto">
                            <div id="dropZone" class="drop-zone">
                                <div class="drop-zone-content">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                    <h5>ファイルをドラッグ&ドロップ</h5>
                                    <p class="text-muted">または下のボタンでファイルを選択</p>
                                    
                                    <input type="file" id="timetreeFile" class="d-none" accept=".csv,.json,.xlsx">
                                    <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('timetreeFile').click()">
                                        <i class="fas fa-folder-open me-2"></i>ファイルを選択
                                    </button>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            対応形式: CSV, JSON, Excel (.xlsx)<br>
                                            最大ファイルサイズ: 10MB
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="fileInfo" style="display: none;"></div>
                        </div>
                    </div>
                </div>
                
                <!-- ステップ2: データプレビュー -->
                <div id="migrationStep2" class="migration-step" style="display: none;">
                    <h4 class="text-center mb-4">📊 データプレビュー</h4>
                    <div id="previewArea"></div>
                </div>
                
                <!-- ステップ3: 移行実行 -->
                <div id="migrationStep3" class="migration-step" style="display: none;">
                    <h4 class="text-center mb-4">⚡ 移行実行</h4>
                    
                    <div class="text-center mb-4">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            データの移行を実行します。この操作は取り消すことができません。
                        </div>
                    </div>
                    
                    <div id="progressArea" style="display: none;"></div>
                    <div id="resultArea" style="display: none;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-outline-secondary" id="migrationPrevBtn" style="display: none;">
                    <i class="fas fa-chevron-left me-1"></i>戻る
                </button>
                <button type="button" class="btn btn-primary" id="migrationNextBtn" disabled>
                    次へ<i class="fas fa-chevron-right ms-1"></i>
                </button>
                <button type="button" class="btn btn-success" id="migrationExecuteBtn" style="display: none;" disabled>
                    <i class="fas fa-play me-2"></i>移行実行
                </button>
            </div>
        </div>
    </div>
</div>

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
echo $page_data['system_footer'];
?>
