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

<!-- カレンダー制御JavaScript（インライン） -->
<script>
console.log('🔧 カレンダーシステム初期化開始');

document.addEventListener('DOMContentLoaded', function() {
    console.log('📅 FullCalendar初期化中...');
    
    // FullCalendar 読み込み確認
    if (typeof FullCalendar === 'undefined') {
        console.error('❌ FullCalendar が読み込まれていません');
        alert('カレンダーライブラリの読み込みに失敗しました。');
        return;
    }
    
    const calendarEl = document.getElementById('calendar');
    if (!calendarEl) {
        console.error('❌ カレンダー要素が見つかりません');
        return;
    }
    
    // 設定値
    const currentDate = '<?= $current_date ?>';
    const viewMode = '<?= $view_mode ?>';
    const driverFilter = '<?= $driver_filter ?>';
    
    console.log('📊 設定値:', { currentDate, viewMode, driverFilter });
    
    // FullCalendar初期化
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: getViewName(viewMode),
        initialDate: currentDate,
        locale: 'ja',
        headerToolbar: false,
        slotMinTime: '08:00:00',
        slotMaxTime: '19:00:00',
        slotDuration: '01:00:00',
        height: 'auto',
        expandRows: true,
        nowIndicator: true,
        weekends: true,
        
        events: function(info, successCallback, failureCallback) {
            console.log('🔄 予約データ取得中...', info);
            
            const apiUrl = `api/get_reservations.php?start=${info.startStr}&end=${info.endStr}&driver_id=${driverFilter}&view=${viewMode}`;
            
            fetch(apiUrl)
                .then(response => {
                    console.log('📡 API レスポンス:', response);
                    if (!response.ok) throw new Error('API呼び出しエラー: ' + response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('✅ データ取得成功:', data);
                    if (data.success) {
                        successCallback(data.data || []);
                        updateDashboardStats(data.data || []);
                    } else {
                        console.warn('⚠️ データ取得失敗:', data.error);
                        successCallback([]);
                    }
                })
                .catch(error => {
                    console.error('❌ 予約データ取得エラー:', error);
                    successCallback([]);
                });
        },
        
        eventClick: function(info) {
            console.log('🖱️ イベントクリック:', info.event);
            alert('予約詳細（実装予定）\n\n' + info.event.title);
        },
        
        dateClick: function(info) {
            console.log('📅 日付クリック:', info.dateStr);
            if (confirm('この日付で新規予約を作成しますか？\n日付: ' + info.dateStr)) {
                alert('予約作成機能は次のフェーズで実装予定です');
            }
        }
    });
    
    try {
        calendar.render();
        console.log('✅ カレンダーレンダリング成功');
    } catch (error) {
        console.error('❌ カレンダーレンダリングエラー:', error);
        alert('カレンダーの表示に失敗しました: ' + error.message);
    }
    
    window.mainCalendar = calendar;
    
    // コントロールボタン設定
    document.querySelectorAll('input[name="viewMode"]').forEach(radio => {
        radio.addEventListener('change', function() {
            calendar.changeView(getViewName(this.value));
            updateUrlParam('view', this.value);
        });
    });
    
    document.getElementById('todayBtn')?.addEventListener('click', () => {
        calendar.today();
        updateUrlParam('date', new Date().toISOString().split('T')[0]);
    });
    
    document.getElementById('prevBtn')?.addEventListener('click', () => {
        calendar.prev();
        updateUrlParam('date', calendar.getDate().toISOString().split('T')[0]);
    });
    
    document.getElementById('nextBtn')?.addEventListener('click', () => {
        calendar.next();
        updateUrlParam('date', calendar.getDate().toISOString().split('T')[0]);
    });
    
    document.getElementById('driverFilter')?.addEventListener('change', function() {
        updateUrlParam('driver', this.value);
        calendar.refetchEvents();
    });
    
    document.getElementById('createReservationBtn')?.addEventListener('click', () => {
        alert('新規予約作成機能は次のフェーズで実装予定です');
    });
    
    console.log('✅ カレンダーシステム初期化完了');
});

function getViewName(mode) {
    const viewMap = { 'month': 'dayGridMonth', 'week': 'timeGridWeek', 'day': 'timeGridDay' };
    return viewMap[mode] || 'dayGridMonth';
}

function updateUrlParam(key, value) {
    const url = new URL(window.location);
    url.searchParams.set(key, value);
    window.history.pushState({}, '', url);
}

function updateDashboardStats(events) {
    const today = new Date().toISOString().split('T')[0];
    const todayEvents = events.filter(e => e.start && e.start.startsWith(today));
    const completedEvents = todayEvents.filter(e => e.extendedProps?.status === '完了');
    const inProgressEvents = todayEvents.filter(e => e.extendedProps?.status === '進行中');
    
    document.getElementById('todayReservationCount').textContent = todayEvents.length + '件';
    document.getElementById('todayCompletedCount').textContent = completedEvents.length + '件';
    document.getElementById('todayInProgressCount').textContent = inProgressEvents.length + '件';
}

console.log('✅ カレンダースクリプト読み込み完了');
</script>

<?php echo $page_data['html_footer']; ?>
