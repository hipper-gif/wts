<?php
/**
 * 福祉輸送管理システム - 改良版統一ヘッダー関数 + PWA対応
 * 
 * 最終更新: 2025年9月9日
 * 改良点: ダッシュボード戻りナビゲーション・モダンなデザイン・UX向上・PWA対応
 * 
 * 使用方法:
 * require_once 'includes/header.php';
 * echo renderSystemHeader($user_name, $user_role, 'dashboard');
 * echo renderPageHeader('tools', '日常点検', '車両の安全確認');
 */

/**
 * システムヘッダーを生成（最上部の固定ヘッダー）
 * 
 * @param string $user_name ユーザー名
 * @param string $user_role ユーザー権限
 * @param string $current_page 現在のページ（'dashboard', 'inspection', etc.）
 * @param bool $show_dashboard_link ダッシュボード戻りリンクを表示するか
 * @return string HTML
 */
function renderSystemHeader($user_name, $user_role, $current_page = '', $show_dashboard_link = true) {
    // ダッシュボードページでは戻りリンクを表示しない
    $is_dashboard = $current_page === 'dashboard';
    $show_dashboard_link = $show_dashboard_link && !$is_dashboard;
    
    // ダッシュボード戻りリンク（右上のユーザー情報エリアに配置）
    $dashboard_link = '';
    if ($show_dashboard_link) {
        $dashboard_link = '<a href="dashboard.php" class="dashboard-link">
            <i class="fas fa-tachometer-alt"></i>ダッシュボード
        </a>';
    }
    
    $html = '
    <div class="header-container">
        <div class="system-header">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="system-title">
                            <i class="fas fa-taxi"></i>福祉輸送管理システム
                        </h1>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="user-info">
                            ' . $dashboard_link . '
                            <i class="fas fa-user-circle"></i>
                            <span>' . htmlspecialchars($user_name) . '</span>
                            <span class="text-warning">(' . htmlspecialchars($user_role) . ')</span>
                            <a href="logout.php" class="logout-link ms-3">
                                <i class="fas fa-sign-out-alt"></i>ログアウト
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>';
    
    return $html;
}

/**
 * ページヘッダーを生成（機能名・サブタイトル）
 * 
 * @param string $icon Font Awesomeアイコンクラス（faを除く）
 * @param string $title メインタイトル
 * @param string $subtitle サブタイトル（オプション）
 * @param array $breadcrumb パンくずリスト（オプション）
 * @return string HTML
 */
function renderPageHeader($icon, $title, $subtitle = '', $breadcrumb = []) {
    $subtitle_html = $subtitle ? '<small class="ms-2 opacity-75">' . htmlspecialchars($subtitle) . '</small>' : '';
    
    // パンくずリスト生成
    $breadcrumb_html = '';
    if (!empty($breadcrumb)) {
        $breadcrumb_html = '<nav aria-label="breadcrumb" class="mt-2">
            <ol class="breadcrumb mb-0">';
        
        foreach ($breadcrumb as $index => $item) {
            $is_last = $index === count($breadcrumb) - 1;
            if ($is_last) {
                $breadcrumb_html .= '<li class="breadcrumb-item active" aria-current="page">' . 
                    htmlspecialchars($item['text']) . '</li>';
            } else {
                $breadcrumb_html .= '<li class="breadcrumb-item">
                    <a href="' . htmlspecialchars($item['url']) . '" class="text-white">
                        ' . htmlspecialchars($item['text']) . '
                    </a>
                </li>';
            }
        }
        
        $breadcrumb_html .= '</ol></nav>';
    }
    
    $html = '
    <div class="page-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        <i class="fas fa-' . htmlspecialchars($icon) . '"></i>
                        ' . htmlspecialchars($title) . 
                        $subtitle_html . '
                    </h2>
                    ' . $breadcrumb_html . '
                </div>
            </div>
        </div>
    </div>';
    
    return $html;
}

/**
 * セクションヘッダーを生成（情報ブロック単位）
 * 
 * @param string $icon Font Awesomeアイコンクラス（faを除く）
 * @param string $title セクションタイトル
 * @param string $badge バッジテキスト（オプション）
 * @param array $actions アクションボタン配列（オプション）
 * @return string HTML
 */
function renderSectionHeader($icon, $title, $badge = '', $actions = []) {
    $badge_html = $badge ? '<span class="status-badge info ms-2">' . htmlspecialchars($badge) . '</span>' : '';
    
    // アクションボタン生成
    $actions_html = '';
    if (!empty($actions)) {
        $actions_html = '<div class="ms-auto">';
        foreach ($actions as $action) {
            $btn_class = $action['class'] ?? 'btn-primary btn-sm';
            $actions_html .= '<a href="' . htmlspecialchars($action['url']) . '" 
                class="btn ' . $btn_class . ' me-2">
                <i class="fas fa-' . htmlspecialchars($action['icon']) . '"></i>
                ' . htmlspecialchars($action['text']) . '
            </a>';
        }
        $actions_html .= '</div>';
    }
    
    $html = '
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex align-items-center">
                <h5>
                    <i class="fas fa-' . htmlspecialchars($icon) . '"></i>
                    ' . htmlspecialchars($title) . 
                    $badge_html . '
                </h5>
                ' . $actions_html . '
            </div>
        </div>
    </div>';
    
    return $html;
}

/**
 * タブナビゲーションを生成
 * 
 * @param array $tabs タブ配列 [['id' => 'tab1', 'title' => 'タイトル', 'icon' => 'アイコン']]
 * @param string $active_tab アクティブなタブID
 * @return string HTML
 */
function renderTabNavigation($tabs, $active_tab) {
    $html = '<ul class="nav nav-tabs" id="mainTabs" role="tablist">';
    
    foreach ($tabs as $tab) {
        $tab_id = $tab['id'];
        $is_active = $tab_id === $active_tab;
        $active_class = $is_active ? ' active' : '';
        $active_attr = $is_active ? ' aria-selected="true"' : ' aria-selected="false"';
        
        $html .= '<li class="nav-item" role="presentation">
            <button class="nav-link' . $active_class . '" id="' . $tab_id . '-tab" 
                data-bs-toggle="tab" data-bs-target="#' . $tab_id . '" 
                type="button" role="tab"' . $active_attr . '>
                <i class="fas fa-' . htmlspecialchars($tab['icon']) . '"></i>
                ' . htmlspecialchars($tab['title']) . '
            </button>
        </li>';
    }
    
    $html .= '</ul>';
    return $html;
}

/**
 * 統計カードを生成
 * 
 * @param array $stats 統計データ配列
 * @return string HTML
 */
function renderStatsCards($stats) {
    $html = '<div class="row mb-4">';
    
    foreach ($stats as $stat) {
        $value = $stat['value'] ?? '0';
        $label = $stat['label'] ?? '';
        $icon = $stat['icon'] ?? 'chart-bar';
        $color = $stat['color'] ?? 'primary';
        $trend = $stat['trend'] ?? '';
        
        // トレンド表示
        $trend_html = '';
        if ($trend) {
            $trend_class = $trend['type'] === 'up' ? 'text-success' : 'text-danger';
            $trend_icon = $trend['type'] === 'up' ? 'arrow-up' : 'arrow-down';
            $trend_html = '<small class="' . $trend_class . ' ms-2">
                <i class="fas fa-' . $trend_icon . '"></i> ' . $trend['value'] . '
            </small>';
        }
        
        $html .= '<div class="col-6 col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-item">
                    <i class="fas fa-' . htmlspecialchars($icon) . '"></i>
                    <h3>' . htmlspecialchars($value) . $trend_html . '</h3>
                    <p>' . htmlspecialchars($label) . '</p>
                </div>
            </div>
        </div>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * アラートメッセージを生成
 * 
 * @param string $type アラートタイプ（success, info, warning, danger）
 * @param string $title タイトル
 * @param string $message メッセージ
 * @param bool $dismissible 閉じるボタンを表示するか
 * @return string HTML
 */
function renderAlert($type, $title, $message, $dismissible = true) {
    $icons = [
        'success' => 'check-circle',
        'info' => 'info-circle',
        'warning' => 'exclamation-triangle',
        'danger' => 'exclamation-circle'
    ];
    
    $icon = $icons[$type] ?? 'info-circle';
    $dismiss_html = '';
    $dismiss_class = '';
    
    if ($dismissible) {
        $dismiss_class = ' alert-dismissible fade show';
        $dismiss_html = '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    }
    
    $html = '<div class="alert alert-' . $type . $dismiss_class . '" role="alert">
        <div class="d-flex align-items-start">
            <i class="fas fa-' . $icon . ' me-3 mt-1"></i>
            <div class="flex-grow-1">
                <h6 class="alert-heading mb-1">' . htmlspecialchars($title) . '</h6>
                <p class="mb-0">' . htmlspecialchars($message) . '</p>
            </div>
            ' . $dismiss_html . '
        </div>
    </div>';
    
    return $html;
}

/**
 * データテーブルを生成
 * 
 * @param array $headers ヘッダー配列
 * @param array $data データ配列
 * @param array $options オプション設定
 * @return string HTML
 */
function renderDataTable($headers, $data, $options = []) {
    $striped = $options['striped'] ?? true;
    $hover = $options['hover'] ?? true;
    $responsive = $options['responsive'] ?? true;
    
    $table_classes = ['table'];
    if ($striped) $table_classes[] = 'table-striped';
    if ($hover) $table_classes[] = 'table-hover';
    
    $wrapper_start = $responsive ? '<div class="table-responsive">' : '';
    $wrapper_end = $responsive ? '</div>' : '';
    
    $html = $wrapper_start . '<table class="' . implode(' ', $table_classes) . '">';
    
    // ヘッダー生成
    $html .= '<thead><tr>';
    foreach ($headers as $header) {
        $width = isset($header['width']) ? ' style="width: ' . $header['width'] . '"' : '';
        $html .= '<th scope="col"' . $width . '>' . htmlspecialchars($header['text']) . '</th>';
    }
    $html .= '</tr></thead>';
    
    // データ生成
    $html .= '<tbody>';
    if (empty($data)) {
        $html .= '<tr><td colspan="' . count($headers) . '" class="text-center text-muted py-4">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
            データがありません
        </td></tr>';
    } else {
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>' . $wrapper_end;
    
    return $html;
}

/**
 * フォームヘルパー - 入力フィールド生成
 * 
 * @param string $type 入力タイプ
 * @param string $name フィールド名
 * @param string $label ラベル
 * @param string $value 値
 * @param array $options オプション
 * @return string HTML
 */
function renderFormField($type, $name, $label, $value = '', $options = []) {
    $required = $options['required'] ?? false;
    $placeholder = $options['placeholder'] ?? '';
    $help_text = $options['help'] ?? '';
    $class = $options['class'] ?? '';
    
    $required_attr = $required ? ' required' : '';
    $required_mark = $required ? ' <span class="text-danger">*</span>' : '';
    
    $field_id = 'field_' . $name;
    
    $html = '<div class="mb-3 ' . $class . '">
        <label for="' . $field_id . '" class="form-label">
            ' . htmlspecialchars($label) . $required_mark . '
        </label>';
    
    switch ($type) {
        case 'textarea':
            $rows = $options['rows'] ?? 3;
            $html .= '<textarea class="form-control" id="' . $field_id . '" name="' . $name . '" 
                rows="' . $rows . '" placeholder="' . htmlspecialchars($placeholder) . '"' . $required_attr . '>' . 
                htmlspecialchars($value) . '</textarea>';
            break;
            
        case 'select':
            $options_list = $options['options'] ?? [];
            $html .= '<select class="form-select" id="' . $field_id . '" name="' . $name . '"' . $required_attr . '>';
            
            if ($placeholder) {
                $html .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
            }
            
            foreach ($options_list as $option_value => $option_text) {
                $selected = $value === $option_value ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars($option_value) . '"' . $selected . '>' . 
                    htmlspecialchars($option_text) . '</option>';
            }
            $html .= '</select>';
            break;
            
        default:
            $html .= '<input type="' . $type . '" class="form-control" id="' . $field_id . '" 
                name="' . $name . '" value="' . htmlspecialchars($value) . '" 
                placeholder="' . htmlspecialchars($placeholder) . '"' . $required_attr . '>';
            break;
    }
    
    if ($help_text) {
        $html .= '<div class="form-text">' . htmlspecialchars($help_text) . '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * モーダルウィンドウを生成
 * 
 * @param string $id モーダルID
 * @param string $title タイトル
 * @param string $content 内容
 * @param array $buttons ボタン配列
 * @param string $size サイズ（sm, lg, xl）
 * @return string HTML
 */
function renderModal($id, $title, $content, $buttons = [], $size = '') {
    $size_class = $size ? ' modal-' . $size : '';
    
    $html = '<div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . 'Label" aria-hidden="true">
        <div class="modal-dialog' . $size_class . '">
            <div class="modal-content">
                <div class="modal-header bg-gradient-primary text-white">
                    <h1 class="modal-title fs-5" id="' . $id . 'Label">
                        ' . htmlspecialchars($title) . '
                    </h1>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    ' . $content . '
                </div>';
    
    if (!empty($buttons)) {
        $html .= '<div class="modal-footer">';
        foreach ($buttons as $button) {
            $class = $button['class'] ?? 'btn-secondary';
            $dismiss = isset($button['dismiss']) && $button['dismiss'] ? ' data-bs-dismiss="modal"' : '';
            $onclick = isset($button['onclick']) ? ' onclick="' . htmlspecialchars($button['onclick']) . '"' : '';
            
            $html .= '<button type="button" class="btn ' . $class . '"' . $dismiss . $onclick . '>
                ' . htmlspecialchars($button['text']) . '
            </button>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div></div></div>';
    return $html;
}

/**
 * 完全なHTMLページヘッダーを生成（head部分含む）+ PWA対応
 * 
 * @param string $page_title ページタイトル
 * @param array $options オプション設定
 * @return string HTML
 */
function renderHTMLHead($page_title, $options = []) {
    $description = $options['description'] ?? '福祉輸送管理システム - 7段階業務フロー対応PWAアプリ';
    $additional_css = $options['additional_css'] ?? [];
    $additional_js = $options['additional_js'] ?? [];
    
    $html = '<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="' . htmlspecialchars($description) . '">
    <title>' . htmlspecialchars($page_title) . ' - 福祉輸送管理システム</title>
    
    <!-- Bootstrap 5.3.0 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6.0.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- 統一ヘッダーCSS -->
    <link rel="stylesheet" href="css/header-unified.css">
    
    <!-- ========== PWA設定 v3.1 ========== -->
    
    <!-- Web App Manifest -->
    <link rel="manifest" href="/Smiley/taxi/wts/manifest.json">

    <!-- テーマカラー -->
    <meta name="theme-color" content="#2196F3">
    <meta name="msapplication-TileColor" content="#2196F3">

    <!-- iOS Safari対応 -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="福祉輸送管理システム">
    <link rel="apple-touch-icon" href="/Smiley/taxi/wts/icons/apple-touch-icon.png">

    <!-- Android Chrome対応 -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="WTS">

    <!-- 基本favicon設定 -->
    <link rel="icon" type="image/png" sizes="32x32" href="/Smiley/taxi/wts/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/Smiley/taxi/wts/icons/favicon-16x16.png">

    <!-- SEO・SNS対応 -->
    <meta name="keywords" content="福祉輸送,タクシー,業務管理,PWA,オフライン,法令遵守,7段階フロー">

    <!-- Open Graph（SNS共有用） -->
    <meta property="og:title" content="' . htmlspecialchars($page_title) . ' - 福祉輸送管理システム v3.1">
    <meta property="og:description" content="' . htmlspecialchars($description) . '">
    <meta property="og:image" content="https://twinklemark.xsrv.jp/Smiley/taxi/wts/icons/icon-512x512.png">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://twinklemark.xsrv.jp/Smiley/taxi/wts/">

    <!-- PWA初期化JavaScript -->
    <script>
    // PWA Service Worker 登録
    if ("serviceWorker" in navigator) {
        window.addEventListener("load", function() {
            navigator.serviceWorker.register("/Smiley/taxi/wts/sw.js")
                .then(function(registration) {
                    console.log("✅ Service Worker 登録成功:", registration.scope);
                })
                .catch(function(error) {
                    console.log("❌ Service Worker 登録失敗 (Day2で実装予定):", error);
                });
        });
    }

    // PWAインストールプロンプト管理
    let deferredPrompt;

    window.addEventListener("beforeinstallprompt", (e) => {
        e.preventDefault();
        deferredPrompt = e;
        console.log("📱 PWAインストール可能");
        
        // インストールボタンの表示制御
        const installButton = document.getElementById("pwa-install-btn");
        if (installButton) {
            installButton.style.display = "block";
            installButton.addEventListener("click", installPWA);
        }
    });

    window.addEventListener("appinstalled", () => {
        console.log("🎉 PWA インストール完了");
        deferredPrompt = null;
        
        // インストールボタンを隠す
        const installButton = document.getElementById("pwa-install-btn");
        if (installButton) {
            installButton.style.display = "none";
        }
        
        // 成功メッセージ表示
        showPWANotification("アプリがホーム画面に追加されました！", "success");
    });

    // PWAインストール実行
    function installPWA() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((choiceResult) => {
                if (choiceResult.outcome === "accepted") {
                    console.log("✅ PWAインストール: 承認");
                } else {
                    console.log("❌ PWAインストール: 拒否");
                }
                deferredPrompt = null;
            });
        }
    }

    // PWA通知表示
    function showPWANotification(message, type = "info") {
        const notification = document.createElement("div");
        notification.className = `pwa-notification ${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-message">${message}</span>
                <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // 5秒後に自動削除
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 5000);
    }

    // オフライン状態監視
    window.addEventListener("online", () => {
        document.body.classList.remove("offline-mode");
        console.log("🌐 オンライン復旧");
        showPWANotification("インターネット接続が復旧しました", "success");
    });

    window.addEventListener("offline", () => {
        document.body.classList.add("offline-mode");
        console.log("📡 オフライン状態");
        showPWANotification("オフラインモードで動作中", "warning");
    });

    // PWA表示モード判定
    window.addEventListener("load", () => {
        // スタンドアローンモード（PWAアプリとして起動）の場合
        if (window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true) {
            document.body.classList.add("pwa-mode");
            console.log("📱 PWAモードで起動");
        }
    });
    </script>

    <!-- PWA専用CSS -->
    <style>
    /* PWA通知システム */
    .pwa-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        max-width: 300px;
        padding: 12px 16px;
        border-radius: 8px;
        color: white;
        font-size: 0.9rem;
        z-index: 9998;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .pwa-notification.success {
        background: linear-gradient(135deg, #4CAF50, #45a049);
    }

    .pwa-notification.warning {
        background: linear-gradient(135deg, #FF9800, #F57C00);
    }

    .pwa-notification.info {
        background: linear-gradient(135deg, #2196F3, #1976D2);
    }

    .notification-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .notification-close {
        background: none;
        border: none;
        color: white;
        font-size: 16px;
        cursor: pointer;
        padding: 0;
        opacity: 0.8;
    }

    .notification-close:hover {
        opacity: 1;
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
        padding: 12px 24px;
        border-radius: 50px;
        font-weight: 600;
        box-shadow: 0 4px 20px rgba(33, 150, 243, 0.4);
        cursor: pointer;
        z-index: 9999;
        transition: all 0.3s ease;
    }

    #pwa-install-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(33, 150, 243, 0.5);
    }

    /* オフラインモード表示 */
    .offline-mode {
        filter: grayscale(0.3);
    }

    .offline-mode::before {
        content: "📡 オフライン";
        position: fixed;
        top: 10px;
        right: 10px;
        background: #f44336;
        color: white;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        z-index: 9999;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    /* PWAモード表示 */
    .pwa-mode::after {
        content: "📱 PWA";
        position: fixed;
        top: 10px;
        left: 10px;
        background: #4CAF50;
        color: white;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        z-index: 9999;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    /* アニメーション */
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* レスポンシブ対応 */
    @media (max-width: 768px) {
        .pwa-notification {
            top: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
        }
        
        #pwa-install-btn {
            bottom: 10px;
            right: 10px;
            left: 10px;
            border-radius: 12px;
        }
    }
    </style>
    
    <!-- ========== PWA設定終了 ========== -->';
    
    // 追加CSS
    foreach ($additional_css as $css) {
        $html .= '
    <link rel="stylesheet" href="' . htmlspecialchars($css) . '">';
    }
    
    $html .= '
</head>
<body class="fade-in-up">
    
    <!-- PWAインストールボタン -->
    <button id="pwa-install-btn">
        <i class="fas fa-download"></i> アプリをインストール
    </button>';
    
    return $html;
}

/**
 * HTMLフッターを生成（JavaScriptファイル含む）+ PWA対応
 * 
 * @param array $options オプション設定
 * @return string HTML
 */
function renderHTMLFooter($options = []) {
    $additional_js = $options['additional_js'] ?? [];
    
    $html = '
    <!-- Bootstrap 5.3.0 JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';
    
    // 追加JavaScript
    foreach ($additional_js as $js) {
        $html .= '
    <script src="' . htmlspecialchars($js) . '"></script>';
    }
    
    $html .= '
    <!-- 共通JavaScript + PWA機能 -->
    <script>
        // ツールチップ初期化
        var tooltipTriggerList = [].slice.call(document.querySelectorAll(\'[data-bs-toggle="tooltip"]\'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // アラート自動非表示
        setTimeout(function() {
            var alerts = document.querySelectorAll(\'.alert-dismissible\');
            alerts.forEach(function(alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // フェードインアニメーション
        document.addEventListener(\'DOMContentLoaded\', function() {
            document.body.classList.add(\'fade-in-up\');
        });
        
        // PWA関連の初期化処理
        document.addEventListener(\'DOMContentLoaded\', function() {
            // PWA状態をコンソールに表示
            if (window.matchMedia(\'(display-mode: standalone)\').matches) {
                console.log(\'🎉 PWAとして起動中\');
            }
            
            // Service Worker状態確認
            if (\'serviceWorker\' in navigator) {
                navigator.serviceWorker.ready.then(function(registration) {
                    console.log(\'✅ Service Worker 準備完了:\', registration.scope);
                });
            }
        });
    </script>
</body>
</html>';
    
    return $html;
}

/**
 * PWA専用ヘルパー関数 - インストールボタンを表示
 * 
 * @param string $text ボタンテキスト
 * @param string $position 位置（fixed, inline）
 * @return string HTML
 */
function renderPWAInstallButton($text = 'アプリをインストール', $position = 'fixed') {
    $position_class = $position === 'inline' ? 'btn btn-primary' : '';
    $position_id = $position === 'fixed' ? 'pwa-install-btn' : 'pwa-install-btn-inline';
    
    return '<button id="' . $position_id . '" class="' . $position_class . '" style="display: none;">
        <i class="fas fa-download"></i> ' . htmlspecialchars($text) . '
    </button>';
}

/**
 * PWA状態チェック関数
 * 
 * @return array PWA状態情報
 */
function getPWAStatus() {
    $manifest_exists = file_exists($_SERVER['DOCUMENT_ROOT'] . '/Smiley/taxi/wts/manifest.json');
    $sw_exists = file_exists($_SERVER['DOCUMENT_ROOT'] . '/Smiley/taxi/wts/sw.js');
    $icons_dir_exists = is_dir($_SERVER['DOCUMENT_ROOT'] . '/Smiley/taxi/wts/icons/');
    
    return [
        'manifest_exists' => $manifest_exists,
        'service_worker_exists' => $sw_exists,
        'icons_dir_exists' => $icons_dir_exists,
        'pwa_ready' => $manifest_exists && $icons_dir_exists,
        'fully_ready' => $manifest_exists && $sw_exists && $icons_dir_exists
    ];
}

/**
 * PWA機能付きページを生成するためのショートカット関数
 * 
 * @param string $page_title ページタイトル
 * @param string $user_name ユーザー名
 * @param string $user_role ユーザー権限
 * @param string $current_page 現在のページ
 * @param array $options オプション設定
 * @return array [html_head, system_header] のHTML文字列
 */
function renderPWAPage($page_title, $user_name, $user_role, $current_page = '', $options = []) {
    $html_head = renderHTMLHead($page_title, $options);
    $system_header = renderSystemHeader($user_name, $user_role, $current_page);
    
    return [
        'html_head' => $html_head,
        'system_header' => $system_header
    ];
}

/**
 * 使用例・サンプル実装（PWA対応版）
 */
function renderSampleUsage() {
    return '
    <!-- PWA対応版使用例 -->
    <?php
    require_once "includes/header.php";
    
    // PWA機能付きHTMLヘッダー出力
    echo renderHTMLHead("日常点検", [
        "description" => "車両の日常点検を行います - PWAアプリ対応"
    ]);
    
    // システムヘッダー
    echo renderSystemHeader($_SESSION["user_name"], $_SESSION["user_role"], "inspection");
    
    // ページヘッダー
    echo renderPageHeader("tools", "日常点検", "車両の安全確認");
    
    // PWA状態確認
    $pwa_status = getPWAStatus();
    if (!$pwa_status["pwa_ready"]) {
        echo renderAlert("warning", "PWA準備中", "アプリ機能の準備中です");
    }
    
    // インライン型インストールボタン（オプション）
    echo renderPWAInstallButton("ホーム画面に追加", "inline");
    
    // メインコンテンツ...
    
    // HTMLフッター（PWA対応）
    echo renderHTMLFooter();
    ?>
    ';
}

/**
 * PWA診断情報を表示する関数
 * 
 * @return string HTML
 */
function renderPWADiagnostics() {
    $status = getPWAStatus();
    
    $html = '<div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-mobile-alt"></i> PWA状態診断</h5>
        </div>
        <div class="card-body">
            <div class="row">';
    
    $checks = [
        'manifest_exists' => ['ファイル', 'manifest.json', $status['manifest_exists']],
        'service_worker_exists' => ['ファイル', 'sw.js（Day 2実装予定）', $status['service_worker_exists']],
        'icons_dir_exists' => ['ディレクトリ', 'icons/', $status['icons_dir_exists']],
        'pwa_ready' => ['PWA基本', '基本機能', $status['pwa_ready']],
        'fully_ready' => ['PWA完全', 'フル機能', $status['fully_ready']]
    ];
    
    foreach ($checks as $key => $check) {
        $icon = $check[2] ? 'check-circle text-success' : 'times-circle text-danger';
        $status_text = $check[2] ? '正常' : '未実装';
        
        $html .= '<div class="col-md-6 mb-3">
            <div class="d-flex align-items-center">
                <i class="fas fa-' . $icon . ' me-2"></i>
                <div>
                    <strong>' . $check[0] . '</strong><br>
                    <small class="text-muted">' . $check[1] . ': ' . $status_text . '</small>
                </div>
            </div>
        </div>';
    }
    
    $html .= '</div>';
    
    if ($status['pwa_ready']) {
        $html .= '<div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            PWA基本機能が利用可能です！
        </div>';
    } else {
        $html .= '<div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            PWA機能の準備中です
        </div>';
    }
    
    $html .= '</div></div>';
    
    return $html;
}
?>
