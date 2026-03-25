<?php
/**
 * 福祉輸送管理システム v3.1 - 統一ヘッダーシステム（完全版）
 * 
 * ファイル名: includes/unified-header.php
 * バージョン: v3.1.1 ✅ 修正版
 * 作成日: 2025年9月10日
 * 修正日: 2025年9月18日
 * 修正内容: getPageConfiguration()関数を前方移動（Fatal Error解消）
 * 対応範囲: 19ページ全対応（日次7段階フロー + 定期2業務 + 基盤2 + 管理3 + 診断5）
 * PWA対応: 完全対応（Service Worker + Manifest + オフライン機能）
 */

/**
 * サブディレクトリ対応ベースパス取得
 * calendar/ 等のサブディレクトリからincludeされた場合に '../' を返す
 */
function getBasePath() {
    $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    // /wts/calendar/ や /wts/calendar/api/ からのアクセスを検出
    if (preg_match('#/wts/(calendar|api)(/|$)#', $script_dir)) {
        // calendar/api/ からは ../../
        if (preg_match('#/wts/calendar/api(/|$)#', $script_dir)) {
            return '../../';
        }
        return '../';
    }
    // /wts/api/ からのアクセス
    if (preg_match('#/wts/api(/|$)#', $script_dir)) {
        return '../';
    }
    return '';
}

/**
 * 📱 システム名動的取得（設定可能システム名対応）
 */
function getSystemName() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : '福祉輸送管理システム';
    } catch (Exception $e) {
        return '福祉輸送管理システム';
    }
}

/**
 * 📱 レスポンシブシステム名生成
 * カレンダー系ページは「スマルト カレンダー」、それ以外は「スマルト レコード」
 */
function getResponsiveSystemNames() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $is_calendar = (strpos($script, '/calendar/') !== false);

    if ($is_calendar) {
        return [
            'full' => 'スマルト カレンダー',
            'short' => 'スマルト カレンダー',
            'mobile' => 'スマルト',
            'version' => 'v4.0'
        ];
    }

    return [
        'full' => 'スマルト レコード',
        'short' => 'スマルト レコード',
        'mobile' => 'レコード',
        'version' => 'v4.0'
    ];
}

/**
 * 📱 頻度別ページ設定取得（19ページ対応）
 * ✅ CRITICAL: この関数を前方移動してFatal Error解消
 */
function getPageConfiguration($page_type) {
    $configurations = [
        // 📅 日次業務（7ページ）
        'daily_inspection' => [
            'category' => 'daily',
            'icon' => 'tools',
            'title' => '日常点検',
            'subtitle' => '17項目の車両点検',
            'description' => '車両の日常点検を実施 - 法令遵守必須項目',
            'frequency' => '毎日',
            'priority' => 'critical'
        ],
        'pre_duty_call' => [
            'category' => 'daily',
            'icon' => 'clipboard-check',
            'title' => '乗務前点呼',
            'subtitle' => '16項目のドライバーチェック',
            'description' => '乗務前の健康・準備状況確認',
            'frequency' => '毎日',
            'priority' => 'critical'
        ],
        'departure' => [
            'category' => 'daily',
            'icon' => 'play-circle',
            'title' => '出庫処理',
            'subtitle' => '出庫時刻・天候・メーター記録',
            'description' => '運行開始時の記録管理',
            'frequency' => '毎日',
            'priority' => 'critical'
        ],
        'ride_records' => [
            'category' => 'daily',
            'icon' => 'route',
            'title' => '乗車記録',
            'subtitle' => '復路作成機能付き乗車管理',
            'description' => '乗客輸送記録の管理 - 復路作成機能搭載',
            'frequency' => '随時',
            'priority' => 'critical'
        ],
        'arrival' => [
            'category' => 'daily',
            'icon' => 'stop-circle',
            'title' => '入庫処理',
            'subtitle' => '入庫時刻・走行距離・費用記録',
            'description' => '運行終了時の記録管理',
            'frequency' => '毎日',
            'priority' => 'critical'
        ],
        'arrival_list' => [
            'category' => 'daily',
            'icon' => 'list-alt',
            'title' => '入庫記録一覧',
            'subtitle' => '過去の入庫記録確認・修正',
            'description' => '入庫記録の一覧表示と修正機能',
            'frequency' => '随時',
            'priority' => 'normal'
        ],
        'post_duty_call' => [
            'category' => 'daily',
            'icon' => 'check-circle',
            'title' => '乗務後点呼',
            'subtitle' => '12項目の業務終了チェック',
            'description' => '乗務終了時の確認業務',
            'frequency' => '毎日',
            'priority' => 'critical'
        ],
        'cash_management' => [
            'category' => 'daily',
            'icon' => 'money-check-alt',
            'title' => '売上金確認',
            'subtitle' => '現金内訳・差額確認（v3.1拡張）',
            'description' => '売上金の確認と現金内訳管理',
            'frequency' => '毎日',
            'priority' => 'critical'
        ],
        'driver_cash_count' => [
            'category' => 'daily',
            'icon' => 'calculator',
            'title' => '現金カウント',
            'subtitle' => '金種別枚数入力・差額確認',
            'description' => '運転者向け現金カウント入力',
            'frequency' => '毎日',
            'priority' => 'normal'
        ],
        
        // 🗓️ 定期業務（2ページ）
        'periodic_inspection' => [
            'category' => 'periodic',
            'icon' => 'wrench',
            'title' => '定期点検',
            'subtitle' => '3ヶ月毎の法定車両点検',
            'description' => '法定定期点検の実施記録',
            'frequency' => '3ヶ月毎',
            'priority' => 'high'
        ],
        'annual_report' => [
            'category' => 'periodic',
            'icon' => 'file-alt',
            'title' => '陸運局提出',
            'subtitle' => '年1回の法定報告書',
            'description' => '陸運局への年次報告書作成・提出',
            'frequency' => '年1回',
            'priority' => 'high'
        ],
        
        // 🏠 基盤ページ（2ページ）
        'dashboard' => [
            'category' => 'foundation',
            'icon' => 'tachometer-alt',
            'title' => 'ダッシュボード',
            'subtitle' => '業務状況の総合管理',
            'description' => '7段階業務フローの進捗管理',
            'frequency' => '常時',
            'priority' => 'critical'
        ],
        'master_menu' => [
            'category' => 'foundation',
            'icon' => 'th-large',
            'title' => 'マスターメニュー',
            'subtitle' => '機能一覧・設定管理',
            'description' => 'システム機能の総合メニュー',
            'frequency' => '随時',
            'priority' => 'normal'
        ],
        
        // 📊 管理ページ（3ページ）
        'user_management' => [
            'category' => 'management',
            'icon' => 'users',
            'title' => 'ユーザー管理',
            'subtitle' => '権限・職務フラグ管理',
            'description' => 'システム利用者の管理',
            'frequency' => '随時',
            'priority' => 'normal'
        ],
        'vehicle_management' => [
            'category' => 'management',
            'icon' => 'car',
            'title' => '車両管理',
            'subtitle' => '車両情報・点検履歴管理',
            'description' => '保有車両の総合管理',
            'frequency' => '随時',
            'priority' => 'normal'
        ],
        'location_management' => [
            'category' => 'management',
            'icon' => 'map-marker-alt',
            'title' => '場所マスタ管理',
            'subtitle' => '病院・施設・駅などの登録管理',
            'description' => 'よく使う場所の登録管理',
            'frequency' => '随時',
            'priority' => 'normal'
        ],
        'accident_management' => [
            'category' => 'management',
            'icon' => 'exclamation-triangle',
            'title' => '事故管理',
            'subtitle' => '事故記録・報告管理',
            'description' => '事故発生時の記録・報告管理',
            'frequency' => '随時',
            'priority' => 'high'
        ],
        
        // 🛠️ 診断・管理ツール（5ページ）
        'audit_data_manager' => [
            'category' => 'diagnostic',
            'icon' => 'clipboard-list',
            'title' => '監査データ管理',
            'subtitle' => '監査対応データの整理',
            'description' => '監査準備のためのデータ管理',
            'frequency' => '監査時',
            'priority' => 'high'
        ],
        'emergency_audit_export' => [
            'category' => 'diagnostic',
            'icon' => 'file-export',
            'title' => '緊急監査エクスポート',
            'subtitle' => '即座の監査対応',
            'description' => '緊急監査対応のためのデータエクスポート',
            'frequency' => '緊急時',
            'priority' => 'critical'
        ],
        'emergency_audit_kit' => [
            'category' => 'diagnostic',
            'icon' => 'first-aid',
            'title' => '緊急監査キット',
            'subtitle' => '監査対応支援ツール',
            'description' => '監査対応の総合支援ツール',
            'frequency' => '監査時',
            'priority' => 'high'
        ],
        'data_management' => [
            'category' => 'diagnostic',
            'icon' => 'database',
            'title' => 'データ管理',
            'subtitle' => 'システムデータの管理',
            'description' => 'データベースの管理・メンテナンス',
            'frequency' => '随時',
            'priority' => 'normal'
        ],
        'manual_data_manager' => [
            'category' => 'diagnostic',
            'icon' => 'edit',
            'title' => '手動データ管理',
            'subtitle' => 'データの手動入力・修正',
            'description' => '手動でのデータ入力・修正機能',
            'frequency' => '随時',
            'priority' => 'normal'
        ]
    ];
    
    return $configurations[$page_type] ?? [
        'category' => 'other',
        'icon' => 'file',
        'title' => 'ページ',
        'subtitle' => '',
        'description' => '',
        'frequency' => '随時',
        'priority' => 'normal'
    ];
}

/**
 * 🎯 完全HTMLヘッダー生成（PWA対応）
 */
function renderCompleteHTMLHead($page_title, $options = []) {
    $description = $options['description'] ?? '福祉輸送管理システム v3.1.1 - 7段階業務フロー対応PWAアプリ';
    $additional_css = $options['additional_css'] ?? [];
    $additional_js = $options['additional_js'] ?? [];
    $system_names = getResponsiveSystemNames();

    // サブディレクトリからの呼び出し時にベースパスを自動検出
    $base_path = getBasePath();
    
    $html = '<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="' . htmlspecialchars($description) . '">
    <meta name="keywords" content="福祉輸送,タクシー,業務管理,PWA,オフライン,法令遵守,7段階フロー">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">

    <!-- Android最適化 -->
    <meta name="format-detection" content="telephone=yes">
    <meta name="mobile-web-app-capable" content="yes">
    
    <title>' . htmlspecialchars($page_title) . ' - ' . htmlspecialchars($system_names['full']) . ' ' . $system_names['version'] . '</title>
    
    <!-- ========== 基本ライブラリ ========== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- ========== 統一CSS v3.2.0 ========== -->
    <link rel="stylesheet" href="' . $base_path . 'css/ui-unified-v3.css?v=320">
    <link rel="stylesheet" href="' . $base_path . 'css/header-unified.css?v=320">';
    
    // 追加CSS
    foreach ($additional_css as $css) {
        $html .= '
    <link rel="stylesheet" href="' . htmlspecialchars($css) . '">';
    }
    
    $html .= '
    
    <!-- ========== PWA設定 v3.1.1 ========== -->
    <link rel="manifest" href="/Smiley/taxi/wts/manifest.json">
    <meta name="theme-color" content="#2196F3">
    <meta name="msapplication-TileColor" content="#2196F3">
    
    <!-- iOS Safari対応 -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="' . htmlspecialchars($system_names['mobile']) . ' ' . $system_names['version'] . '">
    <link rel="apple-touch-icon" href="/Smiley/taxi/wts/icons/icon-192x192.png">
    
    <!-- Android Chrome対応 -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="' . htmlspecialchars($system_names['mobile']) . '">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="192x192" href="/Smiley/taxi/wts/icons/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/Smiley/taxi/wts/icons/icon-32x32.png">

    <!-- Skip Link CSS -->
    <style>
    .skip-link { position: absolute; top: -40px; left: 0; background: #2196F3; color: white; padding: 8px 16px; z-index: 10000; transition: top 0.3s; }
    .skip-link:focus { top: 0; }
    </style>
</head>
<body>
<a href="#main-content" class="visually-hidden-focusable skip-link">メインコンテンツへスキップ</a>';
    
    return $html;
}

/**
 * 🏠 統一システムヘッダー生成（3層構造）
 */
function renderSystemHeader($user_name = '未設定', $user_role = 'User', $current_page = '', $show_dashboard_link = true) {
    $system_names = getResponsiveSystemNames();
    $user_name_safe = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
    $user_role_safe = htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8');
    
    // 権限表示名変換
    $role_display = match($user_role_safe) {
        'Admin' => '管理者',
        'User' => '一般',
        default => $user_role_safe
    };
    
    // ダッシュボードリンクの表示判定
    $is_dashboard = $current_page === 'dashboard';
    $show_dashboard_link = $show_dashboard_link && !$is_dashboard;
    
    $dashboard_link = '';
    $header_base_path = getBasePath();
    if ($show_dashboard_link) {
        $dashboard_link = '<a href="' . $header_base_path . 'dashboard.php" class="dashboard-link" aria-label="ダッシュボードへ戻る">
            <i class="fas fa-home"></i>
            <span class="d-none d-md-inline">ダッシュボード</span>
        </a>';
    }
    
    return '
    <div class="system-header-container">
        <header class="system-header" role="banner">
            <div class="container-fluid">
                <div class="d-flex align-items-center justify-content-between h-100">
                    <!-- システムタイトル（タップでダッシュボードへ） -->
                    <div class="system-title-area">
                        <a href="' . $header_base_path . 'dashboard.php" class="system-title-link">
                            <h1 class="system-title m-0">
                                <img src="' . $header_base_path . 'icons/smaruto-header@2x.png" alt="スマルト" class="system-logo" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline\'">
                                <i class="fas fa-taxi text-primary" style="display:none"></i>
                                <span class="system-name-display d-none d-md-inline">' . htmlspecialchars($system_names['full']) . '</span>
                                <span class="system-name-mobile d-inline d-md-none">' . htmlspecialchars($system_names['mobile']) . '</span>
                            </h1>
                        </a>
                    </div>
                    
                    <!-- ユーザー情報エリア -->
                    <div class="user-area d-flex align-items-center gap-3">
                        ' . $dashboard_link . '
                        
                        <div class="user-info d-flex align-items-center gap-2">
                            <i class="fas fa-user-circle text-muted"></i>
                            <div class="user-details">
                                <div class="user-name">' . $user_name_safe . '</div>
                                <div class="user-role">' . $role_display . '</div>
                            </div>
                        </div>
                        
                        <a href="' . $header_base_path . 'logout.php" class="logout-btn" title="ログアウト" aria-label="ログアウト">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="d-none d-sm-inline">ログアウト</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>
    </div>';
}

/**
 * 📄 統一ページヘッダー生成（頻度別対応）
 */
function renderPageHeader($icon, $title, $subtitle = '', $category = 'other', $breadcrumb = [], $workflow_stepper = '') {
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitle_safe = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    
    // カテゴリ別カラー設定
    $category_colors = [
        'daily' => 'text-primary',
        'periodic' => 'text-warning',
        'foundation' => 'text-success',
        'management' => 'text-info',
        'diagnostic' => 'text-secondary',
        'other' => 'text-dark'
    ];
    
    $icon_color = $category_colors[$category] ?? $category_colors['other'];
    
    $subtitle_html = '';
    if (!empty($subtitle_safe)) {
        $subtitle_html = '<span class="page-subtitle">' . $subtitle_safe . '</span>';
    }
    
    // パンくずリスト
    $breadcrumb_html = '';
    if (!empty($breadcrumb)) {
        $breadcrumb_html = '<nav aria-label="パンくず" class="breadcrumb-nav">
            <ol class="breadcrumb mb-0">';
        
        foreach ($breadcrumb as $index => $item) {
            $is_last = $index === count($breadcrumb) - 1;
            if ($is_last) {
                $breadcrumb_html .= '<li class="breadcrumb-item active">' . 
                    htmlspecialchars($item['text']) . '</li>';
            } else {
                $breadcrumb_html .= '<li class="breadcrumb-item">
                    <a href="' . htmlspecialchars($item['url']) . '">
                        ' . htmlspecialchars($item['text']) . '
                    </a>
                </li>';
            }
        }
        
        $breadcrumb_html .= '</ol></nav>';
    }
    
    // ステッパーは固定ヘッダーの外（メインコンテンツ先頭）に配置
    $stepper_section = '';
    if ($workflow_stepper) {
        $stepper_section = '
    <div class="workflow-stepper-wrap">
        <div class="container-fluid">' . $workflow_stepper . '</div>
    </div>';
    }

    return '
    <div class="page-header">
        <div class="container-fluid">
            <div class="d-flex align-items-center justify-content-between h-100">
                <div class="page-title-area">
                    <h2 class="page-title m-0">
                        <i class="fas fa-' . $icon_safe . ' ' . $icon_color . '"></i>
                        ' . $title_safe . '
                        ' . $subtitle_html . '
                    </h2>
                    ' . $breadcrumb_html . '
                </div>
            </div>
        </div>
    </div>' . $stepper_section;
}

/**
 * 🗂️ セクションヘッダー生成
 */
function renderSectionHeader($icon, $title, $badge = '', $actions = []) {
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $badge_safe = htmlspecialchars($badge, ENT_QUOTES, 'UTF-8');
    
    $badge_html = '';
    if (!empty($badge_safe)) {
        $badge_html = '<span class="badge bg-primary ms-2">' . $badge_safe . '</span>';
    }
    
    $actions_html = '';
    if (!empty($actions)) {
        $actions_html = '<div class="section-actions ms-auto">';
        foreach ($actions as $action) {
            $btn_class = htmlspecialchars($action['class'] ?? 'btn-outline-primary btn-sm', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars($action['url'] ?? '#', ENT_QUOTES, 'UTF-8');
            $icon = htmlspecialchars($action['icon'] ?? '', ENT_QUOTES, 'UTF-8');
            $text = htmlspecialchars($action['text'] ?? '', ENT_QUOTES, 'UTF-8');
            
            $actions_html .= '<a href="' . $url . '" class="btn ' . $btn_class . ' me-2">
                ' . ($icon ? '<i class="fas fa-' . $icon . '"></i> ' : '') . $text . '
            </a>';
        }
        $actions_html .= '</div>';
    }
    
    return '
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex align-items-center">
                <h5 class="section-title mb-0">
                    <i class="fas fa-' . $icon_safe . ' text-primary"></i>
                    ' . $title_safe . $badge_html . '
                </h5>
                ' . $actions_html . '
            </div>
        </div>
    </div>';
}

/**
 * 🚨 アラート生成
 * ✅ CRITICAL: 3つの引数を取る正しい実装
 */
function renderAlert($type, $title, $message, $dismissible = true) {
    $type_safe = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $message_safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    
    $icons = [
        'success' => 'check-circle',
        'info' => 'info-circle',
        'warning' => 'exclamation-triangle',
        'danger' => 'exclamation-circle'
    ];
    
    $icon = $icons[$type_safe] ?? 'info-circle';
    
    $dismiss_html = '';
    $dismiss_class = '';
    if ($dismissible) {
        $dismiss_class = ' alert-dismissible';
        $dismiss_html = '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    }
    
    return '
    <div class="alert alert-' . $type_safe . $dismiss_class . '" role="alert">
        <div class="d-flex align-items-start">
            <i class="fas fa-' . $icon . ' me-3 mt-1"></i>
            <div class="flex-grow-1">
                <h6 class="alert-heading mb-1">' . $title_safe . '</h6>
                <p class="mb-0">' . $message_safe . '</p>
            </div>
            ' . $dismiss_html . '
        </div>
    </div>';
}

/**
 * 📋 業務フロー進捗表示（7段階フロー用）
 */
function renderWorkflowProgress($current_step = 1, $completed_steps = [], $date = null) {
    $date = $date ?: date('Y-m-d');
    $workflow_steps = [
        1 => ['icon' => 'tools', 'title' => '日常点検', 'color' => 'primary'],
        2 => ['icon' => 'clipboard-check', 'title' => '乗務前点呼', 'color' => 'info'],
        3 => ['icon' => 'play-circle', 'title' => '出庫処理', 'color' => 'success'],
        4 => ['icon' => 'route', 'title' => '乗車記録', 'color' => 'warning'],
        5 => ['icon' => 'stop-circle', 'title' => '入庫処理', 'color' => 'danger'],
        6 => ['icon' => 'check-circle', 'title' => '乗務後点呼', 'color' => 'dark'],
        7 => ['icon' => 'money-check-alt', 'title' => '売上金確認', 'color' => 'secondary']
    ];
    
    $html = '
    <div class="workflow-progress mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-tasks text-primary"></i>
                    7段階業務フロー進捗 <small class="text-muted">(' . $date . ')</small>
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-2">';
    
    foreach ($workflow_steps as $step => $config) {
        $is_completed = in_array($step, $completed_steps);
        $is_current = $step == $current_step;
        $status_class = $is_completed ? 'completed' : ($is_current ? 'current' : 'pending');
        $icon_color = $is_completed ? 'text-success' : ($is_current ? 'text-' . $config['color'] : 'text-muted');
        
        $html .= '
                    <div class="col-6 col-md-3 col-lg-auto">
                        <div class="workflow-step ' . $status_class . ' text-center p-2">
                            <div class="step-icon mb-2">
                                <i class="fas fa-' . $config['icon'] . ' fs-4 ' . $icon_color . '"></i>
                                ' . ($is_completed ? '<i class="fas fa-check-circle text-success position-absolute"></i>' : '') . '
                            </div>
                            <div class="step-title small fw-bold">' . $config['title'] . '</div>
                            <div class="step-number badge bg-' . $config['color'] . '">' . $step . '</div>
                        </div>
                    </div>';
    }
    
    $html .= '
                </div>
                <div class="progress mt-3" style="height: 8px;">
                    <div class="progress-bar bg-success" style="width: ' . (count($completed_steps) / 7 * 100) . '%"></div>
                </div>
                <div class="text-center mt-2">
                    <small class="text-muted">
                        完了: ' . count($completed_steps) . '/7段階 
                        (' . round(count($completed_steps) / 7 * 100, 1) . '%)
                    </small>
                </div>
            </div>
        </div>
    </div>';
    
    return $html;
}

/**
 * 当日の業務フロー完了状況をDBから取得
 */
function getWorkflowCompletionStatus($pdo, $user_id, $date = null) {
    $date = $date ?: date('Y-m-d');
    $completed = [];
    try {
        // 1.日常点検
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE driver_id = ? AND inspection_date = ?");
        $stmt->execute([$user_id, $date]);
        if ($stmt->fetchColumn() > 0) $completed[] = 'inspection';

        // 2.乗務前点呼
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? AND is_completed = 1");
        $stmt->execute([$user_id, $date]);
        if ($stmt->fetchColumn() > 0) $completed[] = 'pre_duty';

        // 3.出庫
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE driver_id = ? AND departure_date = ?");
        $stmt->execute([$user_id, $date]);
        if ($stmt->fetchColumn() > 0) $completed[] = 'departure';

        // 4.乗車記録
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records WHERE driver_id = ? AND ride_date = ?");
        $stmt->execute([$user_id, $date]);
        if ($stmt->fetchColumn() > 0) $completed[] = 'ride';

        // 5.入庫
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE driver_id = ? AND arrival_date = ?");
        $stmt->execute([$user_id, $date]);
        if ($stmt->fetchColumn() > 0) $completed[] = 'arrival';

        // 6.乗務後点呼
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE driver_id = ? AND call_date = ? AND is_completed = 1");
        $stmt->execute([$user_id, $date]);
        if ($stmt->fetchColumn() > 0) $completed[] = 'post_duty';

        // 7.売上金確認
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_count_details WHERE driver_id = ? AND confirmation_date = ?");
        $stmt->execute([$user_id, $date]);
        if ($stmt->fetchColumn() > 0) $completed[] = 'cash';
    } catch (Exception $e) {
        // エラー時は空配列を返す
    }
    return $completed;
}

/**
 * ワークフローステッパーHTML生成
 *
 * @param string $current_step 現在のステップキー
 * @param array $completed getWorkflowCompletionStatus()の戻り値
 * @param array|null $prev 前ステップ ['url'=>'xxx.php','label'=>'yyy'] or null
 * @param array|null $next 次ステップ ['url'=>'xxx.php','label'=>'yyy'] or null
 * @return string HTML
 */
function renderWorkflowStepper($current_step, $completed, $prev = null, $next = null) {
    $steps = [
        'inspection' => ['label' => '点検', 'url' => 'daily_inspection.php', 'icon' => 'tools', 'title' => '日常点検'],
        'pre_duty'   => ['label' => '点呼', 'url' => 'pre_duty_call.php', 'icon' => 'clipboard-check', 'title' => '乗務前点呼'],
        'departure'  => ['label' => '出庫', 'url' => 'departure.php', 'icon' => 'car', 'title' => '出庫処理'],
        'ride'       => ['label' => '乗車', 'url' => 'ride_records.php', 'icon' => 'route', 'title' => '乗車記録'],
        'arrival'    => ['label' => '入庫', 'url' => 'arrival.php', 'icon' => 'warehouse', 'title' => '入庫処理'],
        'post_duty'  => ['label' => '点呼', 'url' => 'post_duty_call.php', 'icon' => 'phone-alt', 'title' => '乗務後点呼'],
        'cash'       => ['label' => '売上', 'url' => 'cash_management.php', 'icon' => 'yen-sign', 'title' => '売上金確認'],
    ];

    $base_path = getBasePath();
    $step_keys = array_keys($steps);

    $html = '<div class="workflow-stepper">';
    $html .= '<div class="stepper-steps">';
    $html .= '<a href="' . $base_path . 'dashboard.php" class="stepper-home" title="ダッシュボード"><i class="fas fa-home"></i></a>';

    $step_num = 0;
    $prev_key = null;
    foreach ($steps as $key => $step) {
        $step_num++;
        $is_completed = in_array($key, $completed);
        $is_current = ($key === $current_step);

        // コネクタ（最初のステップ以外）
        if ($prev_key !== null) {
            $prev_completed = in_array($prev_key, $completed);
            $connector_class = $prev_completed ? ' completed' : '';
            $html .= '<span class="stepper-connector' . $connector_class . '"></span>';
        }

        // ステップ本体
        if ($is_current) {
            $html .= '<span class="stepper-step current" title="' . htmlspecialchars($step['title']) . '">';
            $html .= '<span class="step-indicator">' . $step_num . '</span>';
            $html .= '<span class="step-label">' . htmlspecialchars($step['label']) . '</span>';
            $html .= '</span>';
        } elseif ($is_completed) {
            $html .= '<a href="' . $base_path . htmlspecialchars($step['url']) . '" class="stepper-step completed" title="' . htmlspecialchars($step['title']) . '">';
            $html .= '<span class="step-indicator"><i class="fas fa-check"></i></span>';
            $html .= '<span class="step-label">' . htmlspecialchars($step['label']) . '</span>';
            $html .= '</a>';
        } else {
            $html .= '<a href="' . $base_path . htmlspecialchars($step['url']) . '" class="stepper-step pending" title="' . htmlspecialchars($step['title']) . '">';
            $html .= '<span class="step-indicator">' . $step_num . '</span>';
            $html .= '<span class="step-label">' . htmlspecialchars($step['label']) . '</span>';
            $html .= '</a>';
        }

        $prev_key = $key;
    }

    $html .= '</div>'; // .stepper-steps

    // 前後ナビボタン（常に左=前、右=次で固定配置）
    $html .= '<div class="stepper-nav">';
    if ($prev) {
        $html .= '<a href="' . $base_path . htmlspecialchars($prev['url']) . '" class="stepper-nav-btn prev"><i class="fas fa-chevron-left"></i> ' . htmlspecialchars($prev['label']) . '</a>';
    } else {
        $html .= '<span></span>';
    }
    if ($next) {
        $html .= '<a href="' . $base_path . htmlspecialchars($next['url']) . '" class="stepper-nav-btn next">' . htmlspecialchars($next['label']) . ' <i class="fas fa-chevron-right"></i></a>';
    } else {
        $html .= '<span></span>';
    }
    $html .= '</div>';

    $html .= '</div>'; // .workflow-stepper

    return $html;
}

/**
 * 📋 完全HTMLフッター生成（PWA対応）
 */
function renderCompleteHTMLFooter($additional_js = []) {
    $html = '
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>';

    // 追加JavaScript
    foreach ($additional_js as $js) {
        $js_safe = htmlspecialchars($js, ENT_QUOTES, 'UTF-8');
        $html .= '
    <script src="' . $js_safe . '" defer></script>';
    }
    
    $html .= '
    <script>
    // v3.1.1初期化処理
    document.addEventListener("DOMContentLoaded", function() {
        console.log("✅ 統一ヘッダーシステム v3.1.1 初期化完了");
    });
    </script>
</body>
</html>';
    
    return $html;
}

/**
 * 🎯 完全ページ生成ショートカット（PWA対応）
 */
function renderCompletePage($page_title, $user_name, $user_role, $current_page, $icon, $title, $subtitle = '', $category = 'other', $options = []) {
    $html_head = renderCompleteHTMLHead($page_title, $options);
    $system_header = renderSystemHeader($user_name, $user_role, $current_page);
    $page_header = renderPageHeader($icon, $title, $subtitle, $category, $options['breadcrumb'] ?? [], $options['workflow_stepper'] ?? '');
    
    return [
        'html_head' => $html_head,
        'system_header' => $system_header,
        'page_header' => $page_header,
        'html_footer' => renderCompleteHTMLFooter($options['additional_js'] ?? [])
    ];
}

?>
