<?php
/**
 * 福祉輸送管理システム - 改良版統一ヘッダー関数
 * 
 * 最終更新: 2025年9月1日
 * 改良点: ダッシュボード戻りナビゲーション・モダンなデザイン・UX向上
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
 * 完全なHTMLページヘッダーを生成（head部分含む）
 * 
 * @param string $page_title ページタイトル
 * @param array $options オプション設定
 * @return string HTML
 */
function renderHTMLHead($page_title, $options = []) {
    $description = $options['description'] ?? '福祉輸送管理システム';
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
    <link rel="stylesheet" href="css/header-unified.css">';
    
    // 追加CSS
    foreach ($additional_css as $css) {
        $html .= '<link rel="stylesheet" href="' . htmlspecialchars($css) . '">';
    }
    
    $html .= '</head>
<body class="fade-in-up">';
    
    return $html;
}

/**
 * HTMLフッターを生成（JavaScriptファイル含む）
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
        $html .= '<script src="' . htmlspecialchars($js) . '"></script>';
    }
    
    $html .= '
    <!-- 共通JavaScript -->
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
    </script>
</body>
</html>';
    
    return $html;
}

/**
 * 使用例・サンプル実装
 */
function renderSampleUsage() {
    return '
    <!-- 使用例 -->
    <?php
    require_once "includes/header.php";
    
    // HTMLヘッダー出力
    echo renderHTMLHead("日常点検", [
        "description" => "車両の日常点検を行います"
    ]);
    
    // システムヘッダー（ダッシュボード戻りリンク付き）
    echo renderSystemHeader($_SESSION["user_name"], $_SESSION["user_role"], "inspection");
    
    // ページヘッダー
    echo renderPageHeader("tools", "日常点検", "車両の安全確認");
    
    // 統計カード表示
    $stats = [
        ["value" => "5", "label" => "今日の点検", "icon" => "check-circle", "color" => "success"],
        ["value" => "2", "label" => "要注意", "icon" => "exclamation-triangle", "color" => "warning"]
    ];
    echo renderStatsCards($stats);
    
    // アラート表示
    echo renderAlert("info", "重要なお知らせ", "定期点検の実施をお忘れなく");
    
    // HTMLフッター
    echo renderHTMLFooter();
    ?>
    ';
}
?>
