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
 */
function getResponsiveSystemNames() {
    $full_name = getSystemName();

    return [
        'full' => $full_name,
        'short' => str_replace(['システム', 'System'], '', $full_name),
        'mobile' => generateMobileAbbreviation($full_name),
        'version' => 'v3.1.1'  // ✅ バージョン更新
    ];
}

function generateMobileAbbreviation($name) {
    // 福祉輸送管理システムの場合
    if (strpos($name, '福祉輸送管理システム') !== false) {
        return 'WTS';
    }

    // システム、Systemを除去
    $cleaned = str_replace(['システム', 'System'], '', $name);

    // 短すぎる場合はそのまま返す（10文字以下）
    if (mb_strlen($cleaned) <= 10) {
        return $cleaned;
    }

    // スペース区切りで単語に分解
    $words = preg_split('/[\s　]+/u', $cleaned);

    // 単語が複数ある場合は各単語の頭文字を取る
    if (count($words) > 1) {
        $abbr = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $abbr .= mb_substr($word, 0, 1);
            }
        }
        return mb_strtoupper($abbr);
    }

    // 単語が1つの場合は最初の5文字を返す
    return mb_substr($cleaned, 0, 5);
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
    
    $html = '<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="' . htmlspecialchars($description) . '">
    <meta name="keywords" content="福祉輸送,タクシー,業務管理,PWA,オフライン,法令遵守,7段階フロー">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">

    <!-- Android最適化 -->
    <meta name="format-detection" content="telephone=yes">
    <meta name="mobile-web-app-capable" content="yes">
    
    <title>' . htmlspecialchars($page_title) . ' - ' . htmlspecialchars($system_names['full']) . ' ' . $system_names['version'] . '</title>
    
    <!-- ========== 基本ライブラリ ========== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- ========== 統一CSS v3.1.1 ========== -->
    <link rel="stylesheet" href="css/ui-unified-v3.css">
    <link rel="stylesheet" href="css/header-unified.css">';
    
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
</head>
<body>';
    
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
    if ($show_dashboard_link) {
        $dashboard_link = '<a href="dashboard.php" class="dashboard-link">
            <i class="fas fa-tachometer-alt"></i>
            <span class="d-none d-md-inline">ダッシュボード</span>
        </a>';
    }
    
    return '
    <div class="system-header-container">
        <header class="system-header">
            <div class="container-fluid">
                <div class="d-flex align-items-center justify-content-between h-100">
                    <!-- システムタイトル（レスポンシブ対応） -->
                    <div class="system-title-area">
                        <h1 class="system-title m-0">
                            <i class="fas fa-taxi text-primary"></i>
                            <span class="system-name-display d-none d-md-inline">' . htmlspecialchars($system_names['full']) . '</span>
                            <span class="system-name-mobile d-inline d-md-none">' . htmlspecialchars($system_names['mobile']) . '</span>
                        </h1>
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
                        
                        <a href="logout.php" class="logout-btn" title="ログアウト">
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
function renderPageHeader($icon, $title, $subtitle = '', $category = 'other', $breadcrumb = []) {
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
    </div>';
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
 * 📋 完全HTMLフッター生成（PWA対応）
 */
function renderCompleteHTMLFooter($additional_js = []) {
    $html = '
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';
    
    // 追加JavaScript
    foreach ($additional_js as $js) {
        $js_safe = htmlspecialchars($js, ENT_QUOTES, 'UTF-8');
        $html .= '
    <script src="' . $js_safe . '"></script>';
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
    $page_header = renderPageHeader($icon, $title, $subtitle, $category, $options['breadcrumb'] ?? []);
    
    return [
        'html_head' => $html_head,
        'system_header' => $system_header,
        'page_header' => $page_header,
        'html_footer' => renderCompleteHTMLFooter($options['additional_js'] ?? [])
    ];
}

?>
