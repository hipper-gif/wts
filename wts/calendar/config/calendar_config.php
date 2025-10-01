<?php
// =================================================================
// カレンダーシステム設定ファイル
// 
// ファイル: /Smiley/taxi/wts/calendar/config/calendar_config.php
// 機能: カレンダー固有設定・制約定義・デフォルト値
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

// 直接アクセス防止
if (!defined('WTS_SYSTEM')) {
    exit('Direct access denied');
}

// =================================================================
// カレンダーシステム基本設定
// =================================================================

// システム情報
define('CALENDAR_SYSTEM_NAME', '介護タクシー予約管理カレンダーシステム');
define('CALENDAR_SYSTEM_VERSION', '1.0.0');
define('CALENDAR_SYSTEM_CODENAME', 'TRCS');

// カレンダー表示設定
define('CALENDAR_DEFAULT_VIEW', 'dayGridMonth'); // month, week, day
define('CALENDAR_FIRST_DAY', 0); // 0=日曜日, 1=月曜日
define('CALENDAR_WEEKEND_ENABLED', true);
define('CALENDAR_TIME_FORMAT', '24h'); // 12h or 24h
define('CALENDAR_DATE_FORMAT', 'Y-m-d');
define('CALENDAR_DATETIME_FORMAT', 'Y-m-d H:i:s');

// 営業時間設定
define('BUSINESS_START_HOUR', 7);
define('BUSINESS_END_HOUR', 19);
define('BUSINESS_DAYS', [1, 2, 3, 4, 5, 6]); // 月-土
define('SLOT_DURATION_MINUTES', 30);

// 予約制限設定
define('MAX_ADVANCE_BOOKING_DAYS', 365); // 最大何日先まで予約可能
define('MIN_ADVANCE_BOOKING_HOURS', 2); // 最低何時間前まで予約可能
define('MAX_RESERVATIONS_PER_DAY', 50); // 1日の最大予約数
define('MAX_PASSENGER_COUNT', 10); // 最大乗車人数

// =================================================================
// 車両制約設定
// =================================================================

// 車両制約マトリックス
$CALENDAR_VEHICLE_CONSTRAINTS = [
    'rental_service_compatibility' => [
        'ハイエース' => ['なし', '車いす', 'リクライニング', 'ストレッチャー'],
        '普通車' => ['なし', '車いす', 'リクライニング'],
        'セレナ' => ['なし', '車いす', 'リクライニング']
    ],
    'passenger_capacity' => [
        'ハイエース' => 10,
        '普通車' => 4,
        'セレナ' => 8
    ],
    'special_requirements' => [
        'ストレッチャー' => ['ハイエース'], // ストレッチャーはハイエースのみ
        'wheelchair_lift' => ['ハイエース'] // 車いすリフトはハイエースのみ
    ]
];

// =================================================================
// サービス種別設定
// =================================================================

$CALENDAR_SERVICE_TYPES = [
    'お迎え' => [
        'description' => '自宅等から病院・施設への移動',
        'icon' => '🚐',
        'color' => '#2196F3',
        'can_create_return' => true,
        'default_duration_minutes' => 60
    ],
    'お送り' => [
        'description' => '病院・施設から自宅等への移動',
        'icon' => '🏠',
        'color' => '#4CAF50',
        'can_create_return' => false,
        'default_duration_minutes' => 60
    ],
    '入院' => [
        'description' => '入院手続きのための送迎',
        'icon' => '🏥',
        'color' => '#FF9800',
        'can_create_return' => false,
        'default_duration_minutes' => 90
    ],
    '退院' => [
        'description' => '退院時の送迎',
        'icon' => '🏠',
        'color' => '#9C27B0',
        'can_create_return' => false,
        'default_duration_minutes' => 90
    ],
    '転院' => [
        'description' => '病院間の移動',
        'icon' => '🏥',
        'color' => '#607D8B',
        'can_create_return' => false,
        'default_duration_minutes' => 120
    ],
    'その他' => [
        'description' => 'その他の目的での送迎',
        'icon' => '📍',
        'color' => '#795548',
        'can_create_return' => false,
        'default_duration_minutes' => 60
    ]
];

// =================================================================
// レンタルサービス設定
// =================================================================

$CALENDAR_RENTAL_SERVICES = [
    'なし' => [
        'description' => 'レンタルサービスなし',
        'icon' => '',
        'additional_fare' => 0,
        'vehicle_requirements' => []
    ],
    '車いす' => [
        'description' => '車いす貸出',
        'icon' => '♿',
        'additional_fare' => 0,
        'vehicle_requirements' => ['wheelchair_compatible']
    ],
    'リクライニング' => [
        'description' => 'リクライニング車いす貸出',
        'icon' => '🛏️',
        'additional_fare' => 500,
        'vehicle_requirements' => ['reclining_compatible']
    ],
    'ストレッチャー' => [
        'description' => 'ストレッチャー使用',
        'icon' => '🏥',
        'additional_fare' => 1000,
        'vehicle_requirements' => ['stretcher_compatible']
    ]
];

// =================================================================
// 紹介者タイプ設定
// =================================================================

$CALENDAR_REFERRER_TYPES = [
    'CM' => [
        'full_name' => 'ケアマネージャー',
        'description' => 'Care Manager',
        'icon' => '👩‍⚕️',
        'priority' => 1
    ],
    'SW' => [
        'full_name' => 'ソーシャルワーカー',
        'description' => 'Social Worker',
        'icon' => '👨‍💼',
        'priority' => 2
    ],
    '家族' => [
        'full_name' => 'ご家族',
        'description' => '家族からの依頼',
        'icon' => '👨‍👩‍👧‍👦',
        'priority' => 3
    ],
    '本人' => [
        'full_name' => 'ご本人',
        'description' => '利用者本人からの依頼',
        'icon' => '👤',
        'priority' => 4
    ]
];

// =================================================================
// 支払方法設定
// =================================================================

$CALENDAR_PAYMENT_METHODS = [
    '現金' => [
        'description' => '現金支払い',
        'icon' => '💴',
        'processing_fee' => 0,
        'requires_change' => true
    ],
    'カード' => [
        'description' => 'クレジットカード・交通系IC',
        'icon' => '💳',
        'processing_fee' => 0,
        'requires_change' => false
    ],
    'その他' => [
        'description' => 'その他の支払方法',
        'icon' => '📄',
        'processing_fee' => 0,
        'requires_change' => false
    ]
];

// =================================================================
// ステータス設定
// =================================================================

$CALENDAR_RESERVATION_STATUSES = [
    '予約' => [
        'description' => '予約受付済み',
        'color' => '#2196F3',
        'can_edit' => true,
        'can_cancel' => true,
        'can_convert_to_ride' => false,
        'next_status' => ['進行中', 'キャンセル']
    ],
    '進行中' => [
        'description' => '乗車・移動中',
        'color' => '#FF9800',
        'can_edit' => true,
        'can_cancel' => false,
        'can_convert_to_ride' => false,
        'next_status' => ['完了', 'キャンセル']
    ],
    '完了' => [
        'description' => '送迎完了',
        'color' => '#4CAF50',
        'can_edit' => false,
        'can_cancel' => false,
        'can_convert_to_ride' => true,
        'next_status' => []
    ],
    'キャンセル' => [
        'description' => 'キャンセル',
        'color' => '#757575',
        'can_edit' => false,
        'can_cancel' => false,
        'can_convert_to_ride' => false,
        'next_status' => []
    ]
];

// =================================================================
// アラート・通知設定
// =================================================================

// アラート設定
define('ALERT_UNASSIGNED_RESERVATIONS', true); // 未割り当て予約のアラート
define('ALERT_UPCOMING_RESERVATIONS', true); // 近日予約のアラート
define('ALERT_CONFLICTING_RESERVATIONS', true); // 重複予約のアラート
define('ALERT_MAINTENANCE_SCHEDULE', true); // 車両メンテナンスアラート

// 通知タイミング設定
define('NOTIFICATION_HOURS_BEFORE', [24, 2]); // 何時間前に通知するか
define('REMINDER_DAYS_ADVANCE', 7); // 何日前からリマインドするか

// =================================================================
// データ保持・アーカイブ設定
// =================================================================

define('DATA_RETENTION_MONTHS', 36); // 36ヶ月間データを保持
define('AUTO_ARCHIVE_ENABLED', true); // 自動アーカイブ有効
define('AUTO_DELETE_CANCELLED_DAYS', 30); // キャンセル予約を30日後に削除

// =================================================================
// API・外部連携設定
// =================================================================

// WTS連携設定
define('WTS_INTEGRATION_ENABLED', true);
define('AUTO_CREATE_RIDE_RECORDS', false); // 完了時に自動で乗車記録作成
define('SYNC_VEHICLE_STATUS', true); // 車両状況の同期
define('SYNC_DRIVER_STATUS', true); // 運転者状況の同期

// タイムツリー移行設定
define('TIMETREE_MIGRATION_ENABLED', true);
define('TIMETREE_BACKUP_ENABLED', true);
define('MIGRATION_BATCH_SIZE', 100); // 一度に処理する件数

// =================================================================
// UI・UX設定
// =================================================================

// カラーテーマ
$CALENDAR_COLOR_THEMES = [
    'default' => [
        'primary' => '#2196F3',
        'secondary' => '#607D8B',
        'success' => '#4CAF50',
        'warning' => '#FF9800',
        'danger' => '#F44336',
        'info' => '#00BCD4'
    ],
    'dark' => [
        'primary' => '#1976D2',
        'secondary' => '#455A64',
        'success' => '#388E3C',
        'warning' => '#F57C00',
        'danger' => '#D32F2F',
        'info' => '#0097A7'
    ]
];

// 表示設定
define('EVENTS_PER_PAGE', 50);
define('SEARCH_RESULTS_PER_PAGE', 20);
define('SHOW_WEEKEND_HIGHLIGHT', true);
define('SHOW_BUSINESS_HOURS_HIGHLIGHT', true);
define('ENABLE_DRAG_DROP', true);
define('ENABLE_RESIZE', false);

// =================================================================
// セキュリティ設定
// =================================================================

// アクセス制御
define('REQUIRE_LOGIN', true);
define('SESSION_TIMEOUT_MINUTES', 120);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION_MINUTES', 30);

// データ保護
define('LOG_ALL_ACTIONS', true);
define('ENCRYPT_PERSONAL_DATA', false); // 個人情報の暗号化（将来対応）
define('AUDIT_LOG_RETENTION_DAYS', 365);

// =================================================================
// パフォーマンス設定
// =================================================================

define('ENABLE_CACHING', false); // キャッシュ機能（将来対応）
define('CACHE_DURATION_MINUTES', 30);
define('OPTIMIZE_LARGE_DATASETS', true);
define('LAZY_LOAD_EVENTS', true);

// =================================================================
// デバッグ・ログ設定
// =================================================================

define('CALENDAR_DEBUG_MODE', false);
define('CALENDAR_LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR
define('CALENDAR_LOG_FILE', '../logs/calendar.log');

// =================================================================
// 設定取得関数
// =================================================================

/**
 * カレンダー設定取得
 */
function getCalendarConfig($key = null, $default = null) {
    global $CALENDAR_VEHICLE_CONSTRAINTS, $CALENDAR_SERVICE_TYPES, 
           $CALENDAR_RENTAL_SERVICES, $CALENDAR_REFERRER_TYPES,
           $CALENDAR_PAYMENT_METHODS, $CALENDAR_RESERVATION_STATUSES,
           $CALENDAR_COLOR_THEMES;
    
    $config = [
        'vehicle_constraints' => $CALENDAR_VEHICLE_CONSTRAINTS,
        'service_types' => $CALENDAR_SERVICE_TYPES,
        'rental_services' => $CALENDAR_RENTAL_SERVICES,
        'referrer_types' => $CALENDAR_REFERRER_TYPES,
        'payment_methods' => $CALENDAR_PAYMENT_METHODS,
        'reservation_statuses' => $CALENDAR_RESERVATION_STATUSES,
        'color_themes' => $CALENDAR_COLOR_THEMES,
        'business_hours' => [
            'start' => BUSINESS_START_HOUR,
            'end' => BUSINESS_END_HOUR,
            'days' => BUSINESS_DAYS
        ],
        'limits' => [
            'max_advance_days' => MAX_ADVANCE_BOOKING_DAYS,
            'min_advance_hours' => MIN_ADVANCE_BOOKING_HOURS,
            'max_daily_reservations' => MAX_RESERVATIONS_PER_DAY,
            'max_passengers' => MAX_PASSENGER_COUNT
        ]
    ];
    
    if ($key === null) {
        return $config;
    }
    
    return isset($config[$key]) ? $config[$key] : $default;
}

/**
 * 車両制約チェック
 */
function isVehicleCompatibleWithService($vehicle_model, $rental_service) {
    $constraints = getCalendarConfig('vehicle_constraints');
    $compatibility = $constraints['rental_service_compatibility'][$vehicle_model] ?? [];
    
    return in_array($rental_service, $compatibility);
}

/**
 * 営業時間チェック
 */
function isBusinessHour($hour, $day_of_week = null) {
    if ($day_of_week !== null && !in_array($day_of_week, BUSINESS_DAYS)) {
        return false;
    }
    
    return $hour >= BUSINESS_START_HOUR && $hour <= BUSINESS_END_HOUR;
}

/**
 * 予約可能期間チェック
 */
function isValidReservationDate($date) {
    $reservation_date = new DateTime($date);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    // 過去日チェック
    if ($reservation_date < $today) {
        return false;
    }
    
    // 最大事前予約日数チェック
    $max_date = clone $today;
    $max_date->add(new DateInterval('P' . MAX_ADVANCE_BOOKING_DAYS . 'D'));
    
    if ($reservation_date > $max_date) {
        return false;
    }
    
    return true;
}

/**
 * ログ出力
 */
function writeCalendarLog($level, $message, $context = []) {
    if (!LOG_ALL_ACTIONS && $level === 'DEBUG') {
        return;
    }
    
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'user_id' => $_SESSION['user_id'] ?? null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    
    // ログファイルに出力（実装簡略化）
    error_log(json_encode($log_entry, JSON_UNESCAPED_UNICODE));
}

// =================================================================
// 初期化処理
// =================================================================

// システム開始フラグ設定
if (!defined('WTS_SYSTEM')) {
    define('WTS_SYSTEM', true);
}

// ログレベル設定
if (CALENDAR_DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ERROR | E_WARNING);
    ini_set('display_errors', 0);
}

// 初期化ログ
if (CALENDAR_DEBUG_MODE) {
    writeCalendarLog('INFO', 'Calendar system configuration loaded', [
        'version' => CALENDAR_SYSTEM_VERSION,
        'default_view' => CALENDAR_DEFAULT_VIEW,
        'business_hours' => BUSINESS_START_HOUR . '-' . BUSINESS_END_HOUR
    ]);
}
?>
