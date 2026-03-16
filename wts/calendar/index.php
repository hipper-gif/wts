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

require_once '../includes/session_check.php';

// 基盤システム読み込み
require_once '../functions.php';
require_once '../includes/unified-header.php';

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
$drivers = getActiveDrivers($pdo);

// 車両一覧取得
$vehicles = getActiveVehicles($pdo, 'with_model');

// カスタマイズ選択肢取得（テーブル未作成の場合は自動作成）
$field_options = [];
try {
    $pdo->query("SELECT 1 FROM reservation_field_options LIMIT 1");
    $stmt = $pdo->query("SELECT field_name, option_value, option_label FROM reservation_field_options WHERE is_active = 1 ORDER BY field_name, sort_order, id");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $field_options[$row['field_name']][] = ['value' => $row['option_value'], 'label' => $row['option_label']];
    }
} catch (Exception $e) {
    // テーブル未作成 → 自動作成
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_field_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            field_name VARCHAR(50) NOT NULL,
            option_value VARCHAR(100) NOT NULL,
            option_label VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_field_option (field_name, option_value),
            INDEX idx_field_active (field_name, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Exception $e2) {
        // 作成失敗時はデフォルト値を使用
    }
}

// ページ設定
$page_config = [
    'title' => '予約カレンダー',
    'subtitle' => '',
    'description' => '予約管理システム',
    'icon' => 'calendar-alt',
    'category' => '予約管理'
];

// 統一ヘッダーでページ生成（正しいパス指定）
// キャッシュバスティング: ファイル更新時にブラウザキャッシュを自動無効化
$cache_bust = function($path) {
    $full_path = __DIR__ . '/' . $path;
    $v = file_exists($full_path) ? filemtime($full_path) : time();
    return $path . '?v=' . $v;
};

$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        // FullCalendar CDN (v6.1.11)
        'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css',
        // カレンダー専用CSS
        $cache_bust('css/calendar.css'),
        $cache_bust('css/calendar-custom.css'),
        $cache_bust('css/reservation.css')
    ],
    'additional_js' => [
        // FullCalendar CDN (v6.1.11 - includes all plugins)
        'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js',
        // カレンダー専用JS
        $cache_bust('js/calendar.js'),
        $cache_bust('js/reservation.js'),
        $cache_bust('js/vehicle_constraints.js'),
        // 既存のWTS統一JS（相対パスで正しく指定）
        '../js/ui-interactions.js?v=' . (file_exists(__DIR__ . '/../js/ui-interactions.js') ? filemtime(__DIR__ . '/../js/ui-interactions.js') : time())
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
<main class="main-content" id="main-content" tabindex="-1">
    <div class="container-fluid py-4">

        <!-- カレンダーコントロールパネル -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <!-- 表示切り替え -->
                            <div class="col-12 col-md-4 mb-3 mb-md-0">
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="viewMode" id="monthView" value="dayGridMonth" <?= $view_mode === 'month' || $view_mode === 'dayGridMonth' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="monthView">
                                        <i class="fas fa-calendar d-md-none"></i>
                                        <span class="d-none d-md-inline"><i class="fas fa-calendar me-1"></i>月表示</span>
                                    </label>

                                    <input type="radio" class="btn-check" name="viewMode" id="weekView" value="timeGridWeek" <?= $view_mode === 'week' || $view_mode === 'timeGridWeek' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="weekView">
                                        <i class="fas fa-calendar-week d-md-none"></i>
                                        <span class="d-none d-md-inline"><i class="fas fa-calendar-week me-1"></i>週表示</span>
                                    </label>

                                    <input type="radio" class="btn-check" name="viewMode" id="dayView" value="timeGridDay" <?= $view_mode === 'day' || $view_mode === 'timeGridDay' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="dayView">
                                        <i class="fas fa-calendar-day d-md-none"></i>
                                        <span class="d-none d-md-inline"><i class="fas fa-calendar-day me-1"></i>日表示</span>
                                    </label>
                                </div>
                            </div>

                            <!-- 運転者フィルター -->
                            <div class="col-12 col-md-3 mb-3 mb-md-0">
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
                            <div class="col-12 col-md-5">
                                <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                    <button type="button" class="btn btn-success flex-grow-1 flex-md-grow-0" id="createReservationBtn">
                                        <i class="fas fa-plus me-2"></i>新規予約作成
                                    </button>

                                    <button type="button" class="btn btn-primary" id="todayBtn">
                                        <i class="fas fa-calendar-day me-1"></i>今日
                                    </button>

                                    <div class="btn-group">
                                        <button type="button" class="btn btn-outline-secondary" id="prevBtn">
                                            <i class="fas fa-chevron-left"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" id="nextBtn">
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>

                                    <button type="button" class="btn btn-outline-info" id="customerManagementBtn" title="顧客管理">
                                        <i class="fas fa-address-book me-1"></i><span class="d-none d-md-inline">顧客管理</span>
                                    </button>

                                    <a href="settings.php" class="btn btn-outline-secondary" title="項目カスタマイズ">
                                        <i class="fas fa-sliders-h"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 色分け凡例 -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body py-2">
                <div class="calendar-legend">
                    <span class="text-muted fw-bold me-1"><i class="fas fa-palette me-1"></i>ステータス:</span>
                    <span class="calendar-legend-item"><span class="calendar-legend-color" style="background:#2196F3"></span>予約</span>
                    <span class="calendar-legend-item"><span class="calendar-legend-color" style="background:#FF9800"></span>進行中</span>
                    <span class="calendar-legend-item"><span class="calendar-legend-color" style="background:#4CAF50"></span>完了</span>
                    <span class="calendar-legend-item"><span class="calendar-legend-color" style="background:#757575"></span>キャンセル</span>
                    <span class="text-muted fw-bold ms-2 me-1">|</span>
                    <span class="calendar-legend-item"><span class="calendar-legend-color dashed"></span>復路</span>
                    <span class="calendar-legend-item"><span class="calendar-legend-color rental" style="background:#2196F3"></span>レンタルあり</span>
                </div>
            </div>
        </div>

        <!-- カレンダー表示エリア -->
        <div class="row">
            <!-- メインカレンダー -->
            <div class="col-12 col-lg-9 mb-4 mb-lg-0">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <div id="calendar"></div>
                    </div>
                </div>
            </div>

            <!-- サイドバー -->
            <div class="col-12 col-lg-3">
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
                <div class="card border-0 shadow-sm mb-4 d-none d-lg-block">
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
                    <input type="hidden" id="customer_id" name="customer_id" value="">

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
                                    <div id="customerInfoBadge" class="mt-1"></div>
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
                                        <?php if (!empty($field_options['service_type'])): ?>
                                            <?php foreach ($field_options['service_type'] as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="お迎え">お迎え</option>
                                            <option value="送り">送り</option>
                                            <option value="往復">往復</option>
                                            <option value="病院">病院</option>
                                            <option value="買い物">買い物</option>
                                            <option value="その他">その他</option>
                                        <?php endif; ?>
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
                                        <?php if (!empty($field_options['rental_service'])): ?>
                                            <?php foreach ($field_options['rental_service'] as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="なし">なし</option>
                                            <option value="車椅子">車椅子</option>
                                            <option value="ストレッチャー">ストレッチャー</option>
                                            <option value="酸素ボンベ">酸素ボンベ</option>
                                        <?php endif; ?>
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
                                        <?php if (!empty($field_options['referrer_type'])): ?>
                                            <?php foreach ($field_options['referrer_type'] as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="CM">CM (ケアマネージャー)</option>
                                            <option value="MSW">MSW (医療ソーシャルワーカー)</option>
                                            <option value="病院">病院</option>
                                            <option value="施設">施設</option>
                                            <option value="個人">個人</option>
                                            <option value="その他">その他</option>
                                        <?php endif; ?>
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
                                        <?php if (!empty($field_options['payment_method'])): ?>
                                            <?php foreach ($field_options['payment_method'] as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="現金">現金</option>
                                            <option value="クレジットカード">クレジットカード</option>
                                            <option value="請求書">請求書</option>
                                            <option value="介護保険">介護保険</option>
                                        <?php endif; ?>
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

<!-- 予約確認モーダル -->
<div class="modal fade" id="reservationDetailModal" tabindex="-1" aria-labelledby="reservationDetailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="reservationDetailModalTitle">予約詳細</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>予約日時</strong>
                        <p id="detailDateTime" class="mb-0"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>利用者名</strong>
                        <p id="detailClientName" class="mb-0"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>乗車場所</strong>
                        <p id="detailPickupLocation" class="mb-0"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>降車場所</strong>
                        <p id="detailDropoffLocation" class="mb-0"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <strong>乗客数</strong>
                        <p id="detailPassengerCount" class="mb-0"></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <strong>サービス種別</strong>
                        <p id="detailServiceType" class="mb-0"></p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <strong>ステータス</strong>
                        <p id="detailStatus" class="mb-0"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>運転者</strong>
                        <p id="detailDriverName" class="mb-0"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>車両</strong>
                        <p id="detailVehicleInfo" class="mb-0"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <strong>レンタルサービス</strong>
                        <p id="detailRentalService" class="mb-0"></p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <strong>料金</strong>
                        <p id="detailFare" class="mb-0"></p>
                    </div>
                </div>
                <div class="row" id="detailNotesRow">
                    <div class="col-12 mb-3">
                        <strong>備考</strong>
                        <p id="detailSpecialNotes" class="mb-0"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                <button type="button" class="btn btn-danger" id="deleteReservationBtn">
                    <i class="fas fa-trash me-2"></i>削除
                </button>
                <button type="button" class="btn btn-primary" id="editReservationBtn">
                    <i class="fas fa-edit me-2"></i>編集
                </button>
            </div>
        </div>
    </div>
</div>

<?php echo $page_data['html_footer']; ?>

<!-- カレンダー設定オブジェクト初期化（FullCalendar読み込み後に実行） -->
<script>
// ビューモード変換ヘルパー
function convertViewMode(mode) {
    const viewMap = {
        'month': 'dayGridMonth',
        'week': 'timeGridWeek',
        'day': 'timeGridDay',
        'dayGridMonth': 'dayGridMonth',
        'timeGridWeek': 'timeGridWeek',
        'timeGridDay': 'timeGridDay'
    };
    return viewMap[mode] || 'dayGridMonth';
}

// グローバルカレンダー設定
window.calendarConfig = {
    // 初期設定（calendar.jsが期待するプロパティ名）
    currentDate: '<?= $current_date ?>',
    viewMode: convertViewMode('<?= $view_mode ?>'),
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
        name: <?= json_encode($user_name, JSON_UNESCAPED_UNICODE) ?>
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
</script>

<!-- 顧客マスター連携JS -->
<script src="<?= $cache_bust('js/customer_master.js') ?>"></script>
<!-- クイック予約JS -->
<script src="<?= $cache_bust('js/quick_booking.js') ?>"></script>

<!-- クイック予約FAB -->
<div id="quickBookingFAB"></div>
</body>
</html>
