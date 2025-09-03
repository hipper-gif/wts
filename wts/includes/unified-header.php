<?php
/**
 * 福祉輸送管理システム - 統一ヘッダー関数集
 * 
 * ファイル名: unified-header.php
 * 配置先: /includes/unified-header.php
 * 作成日: 2025年9月2日
 * バージョン: v3.0（モダンミニマル完全版）
 * 
 * 使用方法:
 * require_once 'includes/unified-header.php';
 * echo renderSystemHeader($user_name, $user_role);
 * echo renderPageHeader('calendar-check', '日常点検', '17項目の安全チェック');
 */

/**
 * システムヘッダーを生成
 * 
 * @param string $user_name ユーザー名
 * @param string $user_role ユーザー権限
 * @param string $dashboard_url ダッシュボードURL（オプション）
 * @return string システムヘッダーのHTML
 */
function renderSystemHeader($user_name = '未設定', $user_role = 'User', $dashboard_url = 'dashboard.php') {
    $user_name_safe = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
    $user_role_safe = htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8');
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
            $role_display = $user_role_safe;
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
 * 
 * @param string $icon Font Awesomeアイコン名（fa-プレフィックスなし）
 * @param string $title ページタイトル
 * @param string $subtitle サブタイトル（オプション）
 * @param array $breadcrumb パンくずリスト（オプション）
 * @return string ページヘッダーのHTML
 */
function renderPageHeader($icon, $title, $subtitle = '', $breadcrumb = []) {
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    
    $icon_html = !empty($icon_safe) ? '<i class="fas fa-' . $icon_safe . '"></i> ' : '';
    
    return '
    <span class="badge badge-' . $type_safe . '">
        ' . $icon_html . $text_safe . '
    </span>';
}

/**
 * 統計カードを生成（ダッシュボード用）
 * 
 * @param string $title カードタイトル
 * @param string $value 統計値
 * @param string $icon アイコン名
 * @param string $change 変化率（オプション）
 * @param string $change_type 変化タイプ（positive, negative, neutral）
 * @return string 統計カードのHTML
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
 * 点検項目チェックボックスを生成
 * 
 * @param string $name input name属性
 * @param string $label ラベルテキスト
 * @param array $options オプション配列 [['value' => '可', 'text' => '可', 'class' => 'success']]
 * @param string $selected 選択された値（オプション）
 * @param bool $required 必須項目かどうか
 * @return string チェック項目のHTML
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
 * 集金カウンター項目を生成
 * 
 * @param string $denomination 金種名（例：千円札）
 * @param int $unit_value 単位金額（例：1000）
 * @param int $count 枚数
 * @param int $base_count 基準枚数（オプション）
 * @return string 集金カウンター項目のHTML
 */
function renderCashCounter($denomination, $unit_value, $count = 0, $base_count = 0) {
    $denomination_safe = htmlspecialchars($denomination, ENT_QUOTES, 'UTF-8');
    $unit_value_safe = (int)$unit_value;
    $count_safe = (int)$count;
    $base_count_safe = (int)$base_count;
    
    $total_amount = $unit_value_safe * $count_safe;
    $difference = $count_safe - $base_count_safe;
    
    $base_html = '';
    if ($base_count_safe > 0) {
        $difference_text = '';
        $difference_class = '';
        if ($difference > 0) {
            $difference_text = '+' . $difference;
            $difference_class = 'text-success';
        } elseif ($difference < 0) {
            $difference_text = $difference;
            $difference_class = 'text-danger';
        } else {
            $difference_text = '±0';
            $difference_class = 'text-muted';
        }
        
        $base_html = '
        <div class="cash-base">
            基準: ' . $base_count_safe . '枚 
            <span class="' . $difference_class . '">(' . $difference_text . ')</span>
        </div>';
    }
    
    return '
    <div class="cash-item">
        <div class="cash-denomination">' . $denomination_safe . '</div>
        <div class="cash-input-group">
            <button type="button" class="cash-btn" data-action="decrease">−</button>
            <input type="number" class="cash-input" name="' . strtolower(str_replace(['円', '札', '玉'], '', $denomination_safe)) . '" value="' . $count_safe . '" min="0" />
            <button type="button" class="cash-btn" data-action="increase">＋</button>
        </div>
        <div class="cash-amount">¥' . number_format($total_amount) . '</div>
        ' . $base_html . '
    </div>';
}

/**
 * プログレスバーを生成
 * 
 * @param int $current 現在値
 * @param int $total 総数
 * @param string $type バータイプ（primary, success, warning, danger）
 * @param bool $show_text パーセンテージを表示するか
 * @return string プログレスバーのHTML
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
 * データテーブルのヘッダーを生成
 * 
 * @param array $columns カラム配列 [['key' => 'name', 'label' => '名前', 'sortable' => true]]
 * @param string $sort_key 現在のソートキー
 * @param string $sort_dir ソート方向（asc, desc）
 * @return string テーブルヘッダーのHTML
 */
function renderTableHeader($columns, $sort_key = '', $sort_dir = 'asc') {
    $header_cells = [];
    
    foreach ($columns as $column) {
        $key_safe = htmlspecialchars($column['key'] ?? '', ENT_QUOTES, 'UTF-8');
        $label_safe = htmlspecialchars($column['label'] ?? '', ENT_QUOTES, 'UTF-8');
        $sortable = $column['sortable'] ?? false;
        
        $cell_content = $label_safe;
        $cell_class = '';
        
        if ($sortable) {
            $sort_icon = '';
            if ($key_safe === $sort_key) {
                $sort_icon = $sort_dir === 'asc' ? 
                    '<i class="fas fa-sort-up ms-1"></i>' : 
                    '<i class="fas fa-sort-down ms-1"></i>';
                $cell_class = ' sortable active';
            } else {
                $sort_icon = '<i class="fas fa-sort ms-1 text-muted"></i>';
                $cell_class = ' sortable';
            }
            
            $cell_content = '
            <a href="#" data-sort="' . $key_safe . '" class="sort-link">
                ' . $label_safe . $sort_icon . '
            </a>';
        }
        
        $header_cells[] = '<th class="' . $cell_class . '">' . $cell_content . '</th>';
    }
    
    return '
    <thead>
        <tr>
            ' . implode('', $header_cells) . '
        </tr>
    </thead>';
}

/**
 * ページネーションを生成
 * 
 * @param int $current_page 現在のページ
 * @param int $total_pages 総ページ数
 * @param string $base_url ベースURL
 * @param array $params 追加パラメータ
 * @return string ページネーションのHTML
 */
function renderPagination($current_page, $total_pages, $base_url = '', $params = []) {
    if ($total_pages <= 1) {
        return '';
    }
    
    $current_page = max(1, min($current_page, $total_pages));
    $base_url_safe = htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8');
    
    // URLパラメータを構築
    $query_params = http_build_query($params);
    $separator = strpos($base_url_safe, '?') !== false ? '&' : '?';
    
    $pagination_items = [];
    
    // 前のページ
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $prev_url = $base_url_safe . $separator . 'page=' . $prev_page . ($query_params ? '&' . $query_params : '');
        $pagination_items[] = '
        <a href="' . $prev_url . '" class="pagination-item">
            <i class="fas fa-chevron-left"></i> 前へ
        </a>';
    }
    
    // ページ番号
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        $page_url = $base_url_safe . $separator . 'page=' . $i . ($query_params ? '&' . $query_params : '');
        $active_class = ($i === $current_page) ? ' active' : '';
        
        $pagination_items[] = '
        <a href="' . $page_url . '" class="pagination-item' . $active_class . '">
            ' . $i . '
        </a>';
    }
    
    // 次のページ
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $next_url = $base_url_safe . $separator . 'page=' . $next_page . ($query_params ? '&' . $query_params : '');
        $pagination_items[] = '
        <a href="' . $next_url . '" class="pagination-item">
            次へ <i class="fas fa-chevron-right"></i>
        </a>';
    }
    
    return '
    <div class="pagination">
        ' . implode('', $pagination_items) . '
    </div>';
}

/**
 * 必須CSS・JavaScriptリンクを生成
 * 
 * @param bool $include_interactions インタラクション用JSを含めるか
 * @return string CSS・JSリンクのHTML
 */
function renderRequiredAssets($include_interactions = true) {
    $css_links = '
    <!-- Bootstrap 5.3.0 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6.0.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- 統一UI CSS -->
    <link rel="stylesheet" href="css/ui-unified-v3.css">';
    
    $js_links = '
    <!-- Bootstrap 5.3.0 JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';
    
    if ($include_interactions) {
        $js_links .= '
    
    <!-- UI インタラクション JavaScript -->
    <script src="js/ui-interactions.js"></script>';
    }
    
    return $css_links . "\n" . $js_links;
}

/**
 * 完全なHTMLページテンプレートを生成
 * 
 * @param string $title ページタイトル
 * @param string $content メインコンテンツHTML
 * @param string $user_name ユーザー名
 * @param string $user_role ユーザー権限
 * @param string $page_icon ページアイコン
 * @param string $page_title ページタイトル
 * @param string $page_subtitle ページサブタイトル
 * @param array $additional_css 追加CSSファイル
 * @param array $additional_js 追加JSファイル
 * @return string 完全なHTMLページ
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
    
    ' . renderRequiredAssets(false) . '
    
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
    
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    ' . $additional_js_scripts . '
    
    <script src="js/ui-interactions.js"></script>
</body>
</html>';
}
?>
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitle_safe = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    
    // サブタイトルHTML
    $subtitle_html = '';
    if (!empty($subtitle_safe)) {
        $subtitle_html = '<span class="page-subtitle">' . $subtitle_safe . '</span>';
    }
    
    // パンくずリストHTML
    $breadcrumb_html = '';
    if (!empty($breadcrumb)) {
        $breadcrumb_items = [];
        foreach ($breadcrumb as $item) {
            if (isset($item['url']) && !empty($item['url'])) {
                $url_safe = htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8');
                $name_safe = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
                $breadcrumb_items[] = '<a href="' . $url_safe . '" class="breadcrumb-link">' . $name_safe . '</a>';
            } else {
                $name_safe = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
                $breadcrumb_items[] = '<span class="breadcrumb-current">' . $name_safe . '</span>';
            }
        }
        $breadcrumb_html = '<nav class="breadcrumb">' . implode(' <i class="fas fa-chevron-right breadcrumb-separator"></i> ', $breadcrumb_items) . '</nav>';
    }
    
    return '
    <nav class="page-header">
        <div class="page-title">
            <i class="fas fa-' . $icon_safe . ' icon-primary"></i>
            ' . $title_safe . '
        </div>
        ' . $subtitle_html . '
        ' . $breadcrumb_html . '
    </nav>';
}

/**
 * セクションヘッダーを生成
 * 
 * @param string $icon Font Awesomeアイコン名（fa-プレフィックスなし）
 * @param string $title セクションタイトル
 * @param string $description 説明文（オプション）
 * @param array $actions アクションボタン配列（オプション）
 * @return string セクションヘッダーのHTML
 */
function renderSectionHeader($icon, $title, $description = '', $actions = []) {
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $description_safe = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    
    // 説明文HTML
    $description_html = '';
    if (!empty($description_safe)) {
        $description_html = '<p class="section-description">' . $description_safe . '</p>';
    }
    
    // アクションボタンHTML
    $actions_html = '';
    if (!empty($actions)) {
        $action_buttons = [];
        foreach ($actions as $action) {
            $url_safe = htmlspecialchars($action['url'] ?? '#', ENT_QUOTES, 'UTF-8');
            $text_safe = htmlspecialchars($action['text'] ?? 'ボタン', ENT_QUOTES, 'UTF-8');
            $class_safe = htmlspecialchars($action['class'] ?? 'btn btn-primary', ENT_QUOTES, 'UTF-8');
            $icon_action = isset($action['icon']) ? '<i class="fas fa-' . htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') . '"></i> ' : '';
            
            $action_buttons[] = '<a href="' . $url_safe . '" class="' . $class_safe . '">' . $icon_action . $text_safe . '</a>';
        }
        $actions_html = '<div class="section-actions">' . implode('', $action_buttons) . '</div>';
    }
    
    return '
    <div class="section-header">
        <div class="section-title-row">
            <h3 class="section-title">
                <i class="fas fa-' . $icon_safe . ' icon-secondary"></i>
                ' . $title_safe . '
            </h3>
            ' . $actions_html . '
        </div>
        ' . $description_html . '
    </div>';
}

/**
 * カードヘッダーを生成
 * 
 * @param string $icon Font Awesomeアイコン名
 * @param string $title カードタイトル
 * @param string $badge ステータスバッジテキスト（オプション）
 * @param string $badge_class バッジのクラス（オプション）
 * @return string カードヘッダーのHTML
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
 * ナビゲーションタブを生成
 * 
 * @param array $tabs タブ配列 [['id' => 'tab1', 'title' => 'タブ1', 'icon' => 'icon-name', 'active' => true]]
 * @return string タブナビゲーションのHTML
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
 * アラートメッセージを生成
 * 
 * @param string $message メッセージ内容
 * @param string $type アラートタイプ（success, warning, danger, info）
 * @param string $icon アイコン名（オプション）
 * @param bool $dismissible 閉じるボタンを表示するか
 * @return string アラートのHTML
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
 * 
 * @param string $text バッジテキスト
 * @param string $type バッジタイプ（primary, success, warning, danger, info, secondary）
 * @param string $icon アイコン名（オプション）
 * @return string バッジのHTML
 */
function renderBadge($text, $type = 'primary', $icon = '') {
    $text_safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $type_safe = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    $icon_safe = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
