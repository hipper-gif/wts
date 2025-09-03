<?php
/**
 * 福祉輸送管理システム - 統一ヘッダー関数集（修正版）
 * 
 * ファイル名: unified-header.php
 * 配置先: /includes/unified-header.php
 * 作成日: 2025年9月3日
 * バージョン: v3.0（完全修正版）
 */

/**
 * システムヘッダーを生成
 */
function renderSystemHeader($user_name = '未設定', $user_role = 'User', $dashboard_url = 'dashboard.php') {
    $user_name_safe = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
    $dashboard_url_safe = htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8');
    
    // 権限レベルに応じた表示名
    $role_display = '';
    switch ($user_role) {
        case 'Admin':
            $role_display = '管理者';
            break;
        case 'User':
            $role_display = '一般ユーザー';
            break;
        default:
            $role_display = htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8');
    }
    
    return '
    <header class="system-header">
        <div class="header-content">
            <a href="' . $dashboard_url_safe . '" class="system-title">
                <i class="fas fa-taxi icon-primary"></i>
                福祉輸送管理システム
            </a>
            <div class="user-info">
                <span class="user-name">' . $user_name_safe . '</span>
                <span class="user-role">' . $role_display . '</span>
                <a href="logout.php" class="btn-icon" title="ログアウト">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </header>';
}

/**
 * ページヘッダーを生成
 */
function renderPageHeader($icon, $title, $subtitle = '') {
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitle_safe = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    
    $subtitle_html = '';
    if (!empty($subtitle_safe)) {
        $subtitle_html = '<span class="page-subtitle">' . $subtitle_safe . '</span>';
    }
    
    return '
    <nav class="page-header">
        <div class="page-title">
            <i class="fas fa-' . $icon_safe . ' icon-primary"></i>
            ' . $title_safe . '
        </div>
        ' . $subtitle_html . '
    </nav>';
}

/**
 * セクションヘッダーを生成
 */
function renderSectionHeader($icon, $title, $description = '') {
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $description_safe = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    
    $description_html = '';
    if (!empty($description_safe)) {
        $description_html = '<p class="section-description">' . $description_safe . '</p>';
    }
    
    return '
    <div class="section-header">
        <h3 class="section-title">
            <i class="fas fa-' . $icon_safe . ' icon-secondary"></i>
            ' . $title_safe . '
        </h3>
        ' . $description_html . '
    </div>';
}

/**
 * カードヘッダーを生成
 */
function renderCardHeader($icon, $title, $badge = '', $badge_class = 'badge-primary') {
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $badge_safe = htmlspecialchars($badge, ENT_QUOTES, 'UTF-8');
    $badge_class_safe = htmlspecialchars($badge_class, ENT_QUOTES, 'UTF-8');
    
    $badge_html = '';
    if (!empty($badge_safe)) {
        $badge_html = '<span class="badge ' . $badge_class_safe . ' ms-2">' . $badge_safe . '</span>';
    }
    
    return '
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-' . $icon_safe . ' icon-primary"></i>
            ' . $title_safe . $badge_html . '
        </h5>
    </div>';
}

/**
 * アラートメッセージを生成
 */
function renderAlert($message, $type = 'info', $icon = '', $dismissible = false) {
    $message_safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $type_safe = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    
    // デフォルトアイコン設定
    if (empty($icon_safe)) {
        switch ($type_safe) {
            case 'success':
                $icon_safe = 'check-circle';
                break;
            case 'warning':
                $icon_safe = 'exclamation-triangle';
                break;
            case 'danger':
                $icon_safe = 'times-circle';
                break;
            case 'info':
            default:
                $icon_safe = 'info-circle';
                break;
        }
    }
    
    $dismissible_html = '';
    if ($dismissible) {
        $dismissible_html = '
        <button type="button" class="modal-close" data-dismiss="alert" aria-label="閉じる">
            <i class="fas fa-times"></i>
        </button>';
    }
    
    return '
    <div class="alert alert-' . $type_safe . '" role="alert">
        <i class="fas fa-' . $icon_safe . ' icon-' . $type_safe . '"></i>
        <div class="alert-content">
            ' . $message_safe . '
        </div>
        ' . $dismissible_html . '
    </div>';
}

/**
 * ステータスバッジを生成
 */
function renderBadge($text, $type = 'primary', $icon = '') {
    $text_safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $type_safe = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    
    $icon_html = !empty($icon_safe) ? '<i class="fas fa-' . $icon_safe . '"></i> ' : '';
    
    return '
    <span class="badge badge-' . $type_safe . '">
        ' . $icon_html . $text_safe . '
    </span>';
}

/**
 * 統計カードを生成（ダッシュボード用）
 */
function renderStatCard($title, $value, $icon, $change = '', $change_type = 'neutral') {
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $value_safe = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $change_safe = htmlspecialchars($change, ENT_QUOTES, 'UTF-8');
    $change_type_safe = htmlspecialchars($change_type, ENT_QUOTES, 'UTF-8');
    
    $change_html = '';
    if (!empty($change_safe)) {
        $change_html = '<div class="stat-change ' . $change_type_safe . '">' . $change_safe . '</div>';
    }
    
    return '
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-' . $icon_safe . ' icon-primary icon-xl"></i>
        </div>
        <div class="stat-value">' . $value_safe . '</div>
        <div class="stat-label">' . $title_safe . '</div>
        ' . $change_html . '
    </div>';
}

/**
 * プログレスバーを生成
 */
function renderProgressBar($current, $total, $type = 'primary', $show_text = true) {
    $current_safe = (int)$current;
    $total_safe = (int)$total;
    $percentage = $total_safe > 0 ? round(($current_safe / $total_safe) * 100, 1) : 0;
    
    $type_safe = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    
    $text_html = '';
    if ($show_text) {
        $text_html = '
        <div class="progress-text">
            ' . $current_safe . ' / ' . $total_safe . ' (' . $percentage . '%)
        </div>';
    }
    
    return '
    <div class="progress-container">
        ' . $text_html . '
        <div class="progress">
            <div class="progress-bar progress-bar-' . $type_safe . '" style="width: ' . $percentage . '%"></div>
        </div>
    </div>';
}

/**
 * ナビゲーションタブを生成
 */
function renderNavigationTabs($tabs) {
    if (empty($tabs)) {
        return '';
    }
    
    $tab_items = [];
    foreach ($tabs as $tab) {
        $id_safe = htmlspecialchars($tab['id'] ?? '', ENT_QUOTES, 'UTF-8');
        $title_safe = htmlspecialchars($tab['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $icon_safe = htmlspecialchars($tab['icon'] ?? '', ENT_QUOTES, 'UTF-8');
        $active_class = ($tab['active'] ?? false) ? ' active' : '';
        
        $icon_html = !empty($icon_safe) ? '<i class="fas fa-' . $icon_safe . '"></i> ' : '';
        
        $tab_items[] = '
        <button class="nav-tab' . $active_class . '" data-tab="' . $id_safe . '" type="button">
            ' . $icon_html . $title_safe . '
        </button>';
    }
    
    return '
    <div class="nav-tabs">
        ' . implode('', $tab_items) . '
    </div>';
}

/**
 * 点検項目チェックボックスを生成
 */
function renderCheckItem($name, $label, $options, $selected = '', $required = false) {
    $name_safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $label_safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $selected_safe = htmlspecialchars($selected, ENT_QUOTES, 'UTF-8');
    
    $required_html = $required ? ' <span class="text-danger">*</span>' : '';
    
    $option_buttons = [];
    foreach ($options as $option) {
        $value_safe = htmlspecialchars($option['value'] ?? '', ENT_QUOTES, 'UTF-8');
        $text_safe = htmlspecialchars($option['text'] ?? $value_safe, ENT_QUOTES, 'UTF-8');
        $class_safe = htmlspecialchars($option['class'] ?? '', ENT_QUOTES, 'UTF-8');
        $selected_class = ($value_safe === $selected_safe) ? ' selected ' . $class_safe : '';
        
        $option_buttons[] = '
        <button type="button" class="check-option' . $selected_class . '" data-name="' . $name_safe . '" data-value="' . $value_safe . '">
            ' . $text_safe . '
        </button>';
    }
    
    return '
    <div class="check-item">
        <div class="check-label">
            ' . $label_safe . $required_html . '
        </div>
        <div class="check-options">
            ' . implode('', $option_buttons) . '
            <input type="hidden" name="' . $name_safe . '" value="' . $selected_safe . '" />
        </div>
    </div>';
}

/**
 * 完全なHTMLページテンプレートを生成
 */
function renderCompletePage($title, $content, $user_name, $user_role, $page_icon, $page_title, $page_subtitle = '', $additional_css = [], $additional_js = []) {
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    
    // 追加アセット
    $additional_css_links = '';
    foreach ($additional_css as $css_file) {
        $css_file_safe = htmlspecialchars($css_file, ENT_QUOTES, 'UTF-8');
        $additional_css_links .= '<link rel="stylesheet" href="' . $css_file_safe . '">' . "\n    ";
    }
    
    $additional_js_scripts = '';
    foreach ($additional_js as $js_file) {
        $js_file_safe = htmlspecialchars($js_file, ENT_QUOTES, 'UTF-8');
        $additional_js_scripts .= '<script src="' . $js_file_safe . '"></script>' . "\n    ";
    }
    
    return '<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title_safe . ' - 福祉輸送管理システム</title>
    
    <!-- 必須CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/ui-unified-v3.css">
    
    ' . $additional_css_links . '
</head>
<body>
    ' . renderSystemHeader($user_name, $user_role) . '
    
    ' . renderPageHeader($page_icon, $page_title, $page_subtitle) . '
    
    <div class="main-content">
        <div class="content-container">
            ' . $content . '
        </div>
    </div>
    
    <!-- 必須JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    ' . $additional_js_scripts . '
    
    <script src="js/ui-interactions.js"></script>
</body>
</html>';
}
?>
