<?php
/**
 * 福祉輸送管理システム v3.1 - 統一ヘッダーシステム（完全版）
 * 
 * ファイル名: includes/unified-header.php
 * バージョン: v3.1.0
 * 作成日: 2025年9月10日
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
        'version' => 'v3.1'
    ];
}

function generateMobileAbbreviation($name) {
    if (strpos($name, '福祉輸送管理システム') !== false) {
        return 'WTS';
    }
    // 他のシステム名の場合の略称生成ロジック
    $words = explode(' ', str_replace(['システム', 'System'], '', $name));
    $abbr = '';
    foreach ($words as $word) {
        $abbr .= mb_substr($word, 0, 1);
    }
    return strtoupper($abbr);
}

/**
 * 🎯 完全HTMLヘッダー生成（PWA対応）
 */
function renderCompleteHTMLHead($page_title, $options = []) {
    $description = $options['description'] ?? '福祉輸送管理システム v3.1 - 7段階業務フロー対応PWAアプリ';
    $additional_css = $options['additional_css'] ?? [];
    $additional_js = $options['additional_js'] ?? [];
    $system_names = getResponsiveSystemNames();
    
    $html = '<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="description" content="' . htmlspecialchars($description) . '">
    <meta name="keywords" content="福祉輸送,タクシー,業務管理,PWA,オフライン,法令遵守,7段階フロー">
    
    <title>' . htmlspecialchars($page_title) . ' - ' . htmlspecialchars($system_names['full']) . ' ' . $system_names['version'] . '</title>
    
    <!-- ========== 基本ライブラリ ========== -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- ========== 統一CSS v3.1 ========== -->
    <link rel="stylesheet" href="css/ui-unified-v3.css">
    <link rel="stylesheet" href="css/header-unified.css">';
    
    // 追加CSS
    foreach ($additional_css as $css) {
        $html .= '
    <link rel="stylesheet" href="' . htmlspecialchars($css) . '">';
    }
    
    $html .= '
    
    <!-- ========== PWA設定 v3.1 ========== -->
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
    
    <!-- Open Graph（SNS対応） -->
    <meta property="og:title" content="' . htmlspecialchars($page_title . ' - ' . $system_names['full']) . '">
    <meta property="og:description" content="' . htmlspecialchars($description) . '">
    <meta property="og:image" content="https://tw1nkle.com/Smiley/taxi/wts/icons/icon-512x512.png">
    <meta property="og:type" content="website">
    
    <!-- ========== PWA JavaScript初期化 ========== -->
    <script>
    // システム名を JavaScript で利用可能にする
    window.SYSTEM_CONFIG = {
        names: ' . json_encode($system_names) . ',
        version: "' . $system_names['version'] . '",
        pwaDomain: "/Smiley/taxi/wts/"
    };
    
    // Service Worker 登録
    if ("serviceWorker" in navigator) {
        window.addEventListener("load", function() {
            navigator.serviceWorker.register("/Smiley/taxi/wts/sw.js")
                .then(function(registration) {
                    console.log("✅ Service Worker 登録成功:", registration.scope);
                    
                    // 更新チェック
                    registration.addEventListener("updatefound", () => {
                        showPWANotification("アプリの新しいバージョンが利用可能です", "info");
                    });
                })
                .catch(function(error) {
                    console.log("ℹ️ Service Worker 未実装（Phase 3で実装予定）:", error.message);
                });
        });
    }
    
    // PWA インストール管理
    let deferredPrompt = null;
    
    window.addEventListener("beforeinstallprompt", (e) => {
        e.preventDefault();
        deferredPrompt = e;
        
        const installBtn = document.getElementById("pwa-install-btn");
        if (installBtn) {
            installBtn.style.display = "flex";
            installBtn.addEventListener("click", installPWA);
        }
    });
    
    window.addEventListener("appinstalled", () => {
        deferredPrompt = null;
        const installBtn = document.getElementById("pwa-install-btn");
        if (installBtn) installBtn.style.display = "none";
        
        showPWANotification("📱 アプリがホーム画面に追加されました！", "success");
    });
    
    async function installPWA() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            const result = await deferredPrompt.userChoice;
            console.log(result.outcome === "accepted" ? "✅ PWAインストール成功" : "❌ PWAインストール拒否");
            deferredPrompt = null;
        }
    }
    
    // オフライン状態監視
    window.addEventListener("online", () => {
        document.body.classList.remove("offline-mode");
        showPWANotification("🌐 接続が復旧しました", "success");
    });
    
    window.addEventListener("offline", () => {
        document.body.classList.add("offline-mode");
        showPWANotification("📡 オフラインモードで動作中", "warning");
    });
    
    // PWA通知表示
    function showPWANotification(message, type = "info") {
        const notification = document.createElement("div");
        notification.className = `pwa-notification ${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentElement) notification.remove();
        }, 5000);
    }
    </script>
    
    <!-- ========== PWA用CSS ========== -->
    <style>
    /* PWA通知システム */
    .pwa-notification {
        position: fixed;
        top: 80px;
        right: 20px;
        max-width: 300px;
        padding: 12px 16px;
        border-radius: 8px;
        color: white;
        font-size: 0.9rem;
        z-index: 9999;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    
    .pwa-notification.success { background: linear-gradient(135deg, #10b981, #059669); }
    .pwa-notification.warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .pwa-notification.info { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    
    .notification-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    
    .notification-content button {
        background: none;
        border: none;
        color: white;
        font-size: 16px;
        cursor: pointer;
        opacity: 0.8;
    }
    
    /* PWAインストールボタン */
    #pwa-install-btn {
        display: none;
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: linear-gradient(135deg, #2196F3, #1976D2);
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 25px;
        font-weight: 600;
        box-shadow: 0 4px 20px rgba(33, 150, 243, 0.4);
        cursor: pointer;
        z-index: 9998;
        align-items: center;
        gap: 8px;
        transition: transform 0.2s ease;
    }
    
    #pwa-install-btn:hover {
        transform: translateY(-2px);
    }
    
    /* オフラインモード */
    .offline-mode::before {
        content: "📡 オフライン";
        position: fixed;
        top: 70px;
        right: 20px;
        background: #f44336;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        z-index: 9999;
    }
    
    /* PWAモード */
    .pwa-standalone::after {
        content: "📱 アプリ";
        position: fixed;
        top: 70px;
        left: 20px;
        background: #4CAF50;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        z-index: 9999;
    }
    
    /* レスポンシブ対応 */
    @media (max-width: 768px) {
        .pwa-notification {
            top: 70px;
            right: 10px;
            left: 10px;
            max-width: none;
        }
        
        #pwa-install-btn {
            bottom: 10px;
            right: 10px;
            left: 10px;
            justify-content: center;
        }
    }
    
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    </style>
</head>
<body>
    <!-- PWAインストールボタン -->
    <button id="pwa-install-btn">
        <i class="fas fa-download"></i>
        <span>アプリをインストール</span>
    </button>';
    
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
                            <span class="d-none d-lg-inline">' . htmlspecialchars($system_names['full']) . '</span>
                            <span class="d-none d-md-inline d-lg-none">' . htmlspecialchars($system_names['short']) . '</span>
                            <span class="d-md-none">' . htmlspecialchars($system_names['mobile']) . '</span>
                            <small class="version-badge">' . $system_names['version'] . '</small>
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
        'daily' => 'text-primary',      // 日次業務（青）
        'periodic' => 'text-warning',   // 定期業務（オレンジ）
        'foundation' => 'text-success', // 基盤（緑）
        'management' => 'text-info',    // 管理（水色）
        'diagnostic' => 'text-secondary', // 診断（グレー）
        'other' => 'text-dark'          // その他（黒）
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
 * 📊 統計カード生成
 */
function renderStatsCards($stats) {
    if (empty($stats)) return '';
    
    $html = '<div class="row g-3 mb-4">';
    
    foreach ($stats as $stat) {
        $value = htmlspecialchars($stat['value'] ?? '0', ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($stat['label'] ?? '', ENT_QUOTES, 'UTF-8');
        $icon = htmlspecialchars($stat['icon'] ?? 'chart-bar', ENT_QUOTES, 'UTF-8');
        $color = htmlspecialchars($stat['color'] ?? 'primary', ENT_QUOTES, 'UTF-8');
        $trend = $stat['trend'] ?? null;
        
        $trend_html = '';
        if ($trend) {
            $trend_class = $trend['type'] === 'up' ? 'text-success' : 'text-danger';
            $trend_icon = $trend['type'] === 'up' ? 'arrow-up' : 'arrow-down';
            $trend_value = htmlspecialchars($trend['value'] ?? '', ENT_QUOTES, 'UTF-8');
            $trend_html = '<small class="' . $trend_class . ' ms-2">
                <i class="fas fa-' . $trend_icon . '"></i> ' . $trend_value . '
            </small>';
        }
        
        $html .= '
        <div class="col-6 col-md-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <i class="fas fa-' . $icon . ' text-' . $color . ' fs-2 mb-2"></i>
                    <h3 class="stat-value text-' . $color . ' mb-1">' . $value . $trend_html . '</h3>
                    <p class="stat-label text-muted mb-0">' . $label . '</p>
                </div>
            </div>
        </div>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * 🚨 アラート生成
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
    <!-- 統一JavaScript + PWA機能 -->
    <script src="js/ui-interactions.js"></script>
    
    <script>
    // 初期化処理
    document.addEventListener("DOMContentLoaded", function() {
        // PWAスタンドアローンモード判定
        if (window.matchMedia("(display-mode: standalone)").matches || 
            window.navigator.standalone === true) {
            document.body.classList.add("pwa-standalone");
        }
        
        // ツールチップ初期化
        const tooltips = document.querySelectorAll("[data-bs-toggle=\\"tooltip\\"]");
        tooltips.forEach(el => new bootstrap.Tooltip(el));
        
        // アラート自動非表示（5秒後）
        setTimeout(() => {
            const alerts = document.querySelectorAll(".alert-dismissible");
            alerts.forEach(alert => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            });
        }, 5000);
        
        console.log("✅ 統一ヘッダーシステム v3.1 初期化完了");
        if (window.SYSTEM_CONFIG) {
            console.log("📱 システム:", window.SYSTEM_CONFIG.names.full, window.SYSTEM_CONFIG.version);
        }
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

/**
 * 📱 頻度別ページ設定取得（19ページ対応）
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
 * 📱 PWA機能状態表示
 */
function renderPWAStatus() {
    // PWAファイル存在チェック
    $manifest_exists = file_exists($_SERVER['DOCUMENT_ROOT'] . '/Smiley/taxi/wts/manifest.json');
    $sw_exists = file_exists($_SERVER['DOCUMENT_ROOT'] . '/Smiley/taxi/wts/sw.js');
    $icons_exist = file_exists($_SERVER['DOCUMENT_ROOT'] . '/Smiley/taxi/wts/icons/icon-192x192.png');
    
    $status_items = [
        'Web App Manifest' => $manifest_exists,
        'Service Worker' => $sw_exists,
        'PWA Icons' => $icons_exist,
        'PWA Ready' => $manifest_exists && $icons_exist
    ];
    
    $html = '
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-mobile-alt text-primary"></i>
                PWA機能状態 <small class="text-muted">(Phase 3実装中)</small>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">';
    
    foreach ($status_items as $item => $status) {
        $icon = $status ? 'check-circle text-success' : 'times-circle text-danger';
        $text = $status ? '利用可能' : '未実装';
        $badge_class = $status ? 'bg-success' : 'bg-warning';
        
        $html .= '
                <div class="col-md-6 mb-2">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-' . $icon . ' me-2"></i>
                        <span class="me-2">' . $item . '</span>
                        <span class="badge ' . $badge_class . '">' . $text . '</span>
                    </div>
                </div>';
    }
    
    $overall_status = $manifest_exists && $icons_exist;
    $status_message = $overall_status ? 
        '基本PWA機能が利用可能です' : 
        'PWA機能の実装中です（Phase 3で完成予定）';
    $alert_type = $overall_status ? 'success' : 'info';
    
    $html .= '
            </div>
            <div class="alert alert-' . $alert_type . ' mb-0 mt-3">
                <i class="fas fa-info-circle"></i>
                ' . $status_message . '
            </div>
        </div>
    </div>';
    
    return $html;
}

/**
 * 🎯 使用例・実装ガイド
 */
function renderUsageExample() {
    return '
    <!-- 使用例: 日常点検ページ -->
    <?php
    require_once "includes/unified-header.php";
    
    // ページ設定取得
    $page_config = getPageConfiguration("daily_inspection");
    
    // 完全ページ生成
    $page_data = renderCompletePage(
        $page_config["title"],           // ページタイトル
        $_SESSION["user_name"],          // ユーザー名
        $_SESSION["user_role"],          // ユーザー権限
        "daily_inspection",              // 現在のページ
        $page_config["icon"],            // アイコン
        $page_config["title"],           // タイトル
        $page_config["subtitle"],        // サブタイトル
        $page_config["category"],        // カテゴリ（daily）
        [
            "description" => $page_config["description"],
            "additional_css" => ["css/inspection.css"],
            "additional_js" => ["js/inspection.js"],
            "breadcrumb" => [
                ["text" => "ホーム", "url" => "dashboard.php"],
                ["text" => "日次業務", "url" => "#"],
                ["text" => "日常点検", "url" => "daily_inspection.php"]
            ]
        ]
    );
    
    // HTML出力
    echo $page_data["html_head"];
    echo $page_data["system_header"];
    echo $page_data["page_header"];
    ?>
    
    <main class="main-content">
        <div class="container-fluid">
            <?php
            // 7段階フロー進捗表示
            echo renderWorkflowProgress(1, [], date("Y-m-d"));
            
            // PWA状態表示
            echo renderPWAStatus();
            
            // セクションヘッダー
            echo renderSectionHeader("tools", "点検項目", "17項目", [
                ["icon" => "plus", "text" => "新規点検", "url" => "?action=new", "class" => "btn-primary btn-sm"],
                ["icon" => "history", "text" => "履歴", "url" => "?action=history", "class" => "btn-outline-secondary btn-sm"]
            ]);
            ?>
            
            <!-- メインコンテンツ -->
            <div class="row">
                <div class="col-md-8">
                    <!-- 点検フォーム -->
                </div>
                <div class="col-md-4">
                    <!-- サイドバー -->
                </div>
            </div>
        </div>
    </main>
    
    <?php echo $page_data["html_footer"]; ?>
    ';
}

/**
 * 🔧 システム診断情報
 */
function renderSystemDiagnostics() {
    $system_info = [
        'PHP Version' => PHP_VERSION,
        'Memory Limit' => ini_get('memory_limit'),
        'Upload Max Size' => ini_get('upload_max_filesize'),
        'Timezone' => date_default_timezone_get(),
        'Current Time' => date('Y-m-d H:i:s'),
        'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'Document Root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
    ];
    
    $html = '
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-server text-info"></i>
                システム診断情報
            </h5>
        </div>
        <div class="card-body">
            <div class="row">';
    
    foreach ($system_info as $key => $value) {
        $html .= '
                <div class="col-md-6 mb-2">
                    <strong>' . $key . ':</strong>
                    <code class="ms-2">' . htmlspecialchars($value) . '</code>
                </div>';
    }
    
    $html .= '
            </div>
        </div>
    </div>';
    
    return $html;
}

/**
 * 📊 統計情報表示
 */
function renderSystemStats($stats = []) {
    $default_stats = [
        ['label' => '総ページ数', 'value' => '19', 'icon' => 'file-alt', 'color' => 'primary'],
        ['label' => '日次業務', 'value' => '7', 'icon' => 'calendar-day', 'color' => 'success'],
        ['label' => '定期業務', 'value' => '2', 'icon' => 'calendar', 'color' => 'warning'],
        ['label' => '管理機能', 'value' => '8', 'icon' => 'cogs', 'color' => 'info']
    ];
    
    $stats = array_merge($default_stats, $stats);
    
    return renderStatsCards($stats);
}
?>
