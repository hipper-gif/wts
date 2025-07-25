<?php
/**
 * 福祉輸送管理システム - 共通関数ライブラリ
 * 運転者選択問題修正版
 */

/**
 * 運転者一覧を取得（権限フィルタリング付き）
 * @param PDO $pdo データベース接続
 * @param bool $active_only 有効ユーザーのみ取得するか
 * @return array 運転者一覧
 */
function getDriverList($pdo, $active_only = true) {
    try {
        $where_conditions = ["is_driver = 1"];
        
        if ($active_only) {
            $where_conditions[] = "is_active = 1";
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $stmt = $pdo->prepare("
            SELECT id, name, login_id, is_active
            FROM users 
            WHERE {$where_clause}
            ORDER BY name
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting driver list: " . $e->getMessage());
        return [];
    }
}

/**
 * 点呼者一覧を取得
 * @param PDO $pdo データベース接続
 * @param bool $active_only 有効ユーザーのみ取得するか
 * @return array 点呼者一覧
 */
function getCallerList($pdo, $active_only = true) {
    try {
        $where_conditions = ["is_caller = 1"];
        
        if ($active_only) {
            $where_conditions[] = "is_active = 1";
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $stmt = $pdo->prepare("
            SELECT id, name, login_id, is_active
            FROM users 
            WHERE {$where_clause}
            ORDER BY name
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting caller list: " . $e->getMessage());
        return [];
    }
}

/**
 * 管理者一覧を取得
 * @param PDO $pdo データベース接続
 * @param bool $active_only 有効ユーザーのみ取得するか
 * @return array 管理者一覧
 */
function getAdminList($pdo, $active_only = true) {
    try {
        $where_conditions = ["is_admin = 1"];
        
        if ($active_only) {
            $where_conditions[] = "is_active = 1";
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $stmt = $pdo->prepare("
            SELECT id, name, login_id, is_active
            FROM users 
            WHERE {$where_clause}
            ORDER BY name
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting admin list: " . $e->getMessage());
        return [];
    }
}

/**
 * 運転者選択のHTMLオプションを生成
 * @param PDO $pdo データベース接続
 * @param string|null $selected_id 選択されているID
 * @param bool $include_empty 空のオプションを含むか
 * @param string $empty_text 空のオプションのテキスト
 * @return string HTMLオプション文字列
 */
function generateDriverOptions($pdo, $selected_id = null, $include_empty = true, $empty_text = '運転者を選択してください') {
    $html = '';
    
    if ($include_empty) {
        $html .= '<option value="">' . htmlspecialchars($empty_text) . '</option>';
    }
    
    $drivers = getDriverList($pdo);
    
    foreach ($drivers as $driver) {
        $selected = ($selected_id == $driver['id']) ? 'selected' : '';
        $status_text = $driver['is_active'] ? '' : ' (無効)';
        
        $html .= sprintf(
            '<option value="%d" %s>%s%s</option>',
            $driver['id'],
            $selected,
            htmlspecialchars($driver['name']),
            $status_text
        );
    }
    
    return $html;
}

/**
 * 点呼者選択のHTMLオプションを生成
 * @param PDO $pdo データベース接続
 * @param string|null $selected_id 選択されているID
 * @param bool $include_empty 空のオプションを含むか
 * @param string $empty_text 空のオプションのテキスト
 * @return string HTMLオプション文字列
 */
function generateCallerOptions($pdo, $selected_id = null, $include_empty = true, $empty_text = '点呼者を選択してください') {
    $html = '';
    
    if ($include_empty) {
        $html .= '<option value="">' . htmlspecialchars($empty_text) . '</option>';
    }
    
    $callers = getCallerList($pdo);
    
    foreach ($callers as $caller) {
        $selected = ($selected_id == $caller['id']) ? 'selected' : '';
        $status_text = $caller['is_active'] ? '' : ' (無効)';
        
        $html .= sprintf(
            '<option value="%d" %s>%s%s</option>',
            $caller['id'],
            $selected,
            htmlspecialchars($caller['name']),
            $status_text
        );
    }
    
    return $html;
}

/**
 * 車両一覧を取得
 * @param PDO $pdo データベース接続
 * @param bool $active_only 有効車両のみ取得するか
 * @return array 車両一覧
 */
function getVehicleList($pdo, $active_only = true) {
    try {
        $where_clause = $active_only ? "WHERE is_active = 1" : "";
        
        $stmt = $pdo->prepare("
            SELECT id, vehicle_number, model, is_active
            FROM vehicles 
            {$where_clause}
            ORDER BY vehicle_number
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting vehicle list: " . $e->getMessage());
        return [];
    }
}

/**
 * 車両選択のHTMLオプションを生成
 * @param PDO $pdo データベース接続
 * @param string|null $selected_id 選択されているID
 * @param bool $include_empty 空のオプションを含むか
 * @param string $empty_text 空のオプションのテキスト
 * @return string HTMLオプション文字列
 */
function generateVehicleOptions($pdo, $selected_id = null, $include_empty = true, $empty_text = '車両を選択してください') {
    $html = '';
    
    if ($include_empty) {
        $html .= '<option value="">' . htmlspecialchars($empty_text) . '</option>';
    }
    
    $vehicles = getVehicleList($pdo);
    
    foreach ($vehicles as $vehicle) {
        $selected = ($selected_id == $vehicle['id']) ? 'selected' : '';
        $display_name = $vehicle['vehicle_number'];
        if (!empty($vehicle['model'])) {
            $display_name .= ' (' . $vehicle['model'] . ')';
        }
        if (!$vehicle['is_active']) {
            $display_name .= ' (無効)';
        }
        
        $html .= sprintf(
            '<option value="%d" %s>%s</option>',
            $vehicle['id'],
            $selected,
            htmlspecialchars($display_name)
        );
    }
    
    return $html;
}

/**
 * 現在のユーザーが指定した権限を持っているかチェック
 * @param string $permission 権限名 ('driver', 'caller', 'admin')
 * @return bool 権限を持っている場合true
 */
function hasPermission($permission) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    switch ($permission) {
        case 'driver':
            return isset($_SESSION['is_driver']) && $_SESSION['is_driver'];
        case 'caller':
            return isset($_SESSION['is_caller']) && $_SESSION['is_caller'];
        case 'admin':
            return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
        default:
            return false;
    }
}

/**
 * 権限チェック（リダイレクト付き）
 * @param array $required_permissions 必要な権限（OR条件）
 * @param string $redirect_url リダイレクト先URL
 */
function requirePermission($required_permissions, $redirect_url = 'dashboard.php') {
    $has_permission = false;
    
    foreach ($required_permissions as $permission) {
        if (hasPermission($permission)) {
            $has_permission = true;
            break;
        }
    }
    
    if (!$has_permission) {
        header("Location: {$redirect_url}");
        exit;
    }
}

/**
 * ユーザー名を取得
 * @param PDO $pdo データベース接続
 * @param int $user_id ユーザーID
 * @return string ユーザー名
 */
function getUserName($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetchColumn();
        return $result ?: '不明なユーザー';
    } catch (Exception $e) {
        error_log("Error getting user name: " . $e->getMessage());
        return '不明なユーザー';
    }
}

/**
 * 車両名を取得
 * @param PDO $pdo データベース接続
 * @param int $vehicle_id 車両ID
 * @return string 車両名
 */
function getVehicleName($pdo, $vehicle_id) {
    try {
        $stmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $result = $stmt->fetchColumn();
        return $result ?: '不明な車両';
    } catch (Exception $e) {
        error_log("Error getting vehicle name: " . $e->getMessage());
        return '不明な車両';
    }
}

/**
 * 日付を日本語形式にフォーマット
 * @param string $date 日付文字列
 * @param bool $include_weekday 曜日を含むか
 * @return string フォーマットされた日付
 */
function formatJapaneseDate($date, $include_weekday = true) {
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }
    
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $formatted = date('Y年n月j日', $timestamp);
    
    if ($include_weekday) {
        $weekday = $weekdays[date('w', $timestamp)];
        $formatted .= "({$weekday})";
    }
    
    return $formatted;
}

/**
 * 通貨形式にフォーマット
 * @param int|float $amount 金額
 * @return string フォーマットされた金額
 */
function formatCurrency($amount) {
    return '¥' . number_format($amount);
}

/**
 * テーブルが存在するかチェック
 * @param PDO $pdo データベース接続
 * @param string $table_name テーブル名
 * @return bool テーブルが存在する場合true
 */
function tableExists($pdo, $table_name) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table_name]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error checking table existence: " . $e->getMessage());
        return false;
    }
}

/**
 * カラムが存在するかチェック
 * @param PDO $pdo データベース接続
 * @param string $table_name テーブル名
 * @param string $column_name カラム名
 * @return bool カラムが存在する場合true
 */
function columnExists($pdo, $table_name, $column_name) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table_name}` LIKE ?");
        $stmt->execute([$column_name]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error checking column existence: " . $e->getMessage());
        return false;
    }
}

/**
 * 安全なHTMLエスケープ
 * @param string $str エスケープする文字列
 * @return string エスケープされた文字列
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * デバッグ用関数
 * @param mixed $data デバッグするデータ
 * @param string $label ラベル
 */
function debug($data, $label = 'DEBUG') {
    if (defined('DEBUG') && DEBUG) {
        echo "<pre><strong>{$label}:</strong>\n";
        print_r($data);
        echo "</pre>";
    }
    error_log("{$label}: " . print_r($data, true));
}

/**
 * CSVエクスポート用のヘッダー設定
 * @param string $filename ファイル名
 */
function setCsvHeaders($filename) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}

/**
 * PDFエクスポート用のヘッダー設定
 * @param string $filename ファイル名
 */
function setPdfHeaders($filename) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
}
?>
