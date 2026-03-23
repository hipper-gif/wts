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
        
        // NOTE: $where_clause contains only hardcoded conditions (no user input) - safe from SQL injection
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
 * アクティブなドライバー一覧を取得
 */
function getActiveDrivers($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * アクティブな車両一覧を取得
 */
function getActiveVehicles($pdo, $columns = 'basic') {
    switch ($columns) {
        case 'with_model':
            $sql = "SELECT id, vehicle_number, model FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        case 'with_mileage':
            $sql = "SELECT id, vehicle_number, model, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        case 'with_name':
            $sql = "SELECT id, vehicle_number, vehicle_name, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        case 'full':
            $sql = "SELECT id, vehicle_number, vehicle_name, model, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
        default:
            $sql = "SELECT id, vehicle_number FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number";
            break;
    }
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
 * 統一監査ログ記録
 * @param PDO $pdo データベース接続
 * @param int $record_id 対象レコードID
 * @param string $action 操作種別 (create, edit, delete, admin_unlock)
 * @param int $user_id 操作ユーザーID
 * @param string $type レコード種別 (arrival, departure, ride_record, inspection, pre_duty_call, post_duty_call, location, vehicle)
 * @param array $changes 変更内容の配列 [['field'=>..., 'old'=>..., 'new'=>...], ...]
 * @param string|null $reason 編集理由
 */
function logAudit($pdo, $record_id, $action, $user_id, $type = 'record', $changes = [], $reason = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO inspection_audit_logs
            (inspection_id, action, edited_by, field_changed, old_value, new_value, reason, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (empty($changes)) {
            $stmt->execute([$record_id, $action, $user_id, $type, null, null, $reason, $ip, $ua]);
        } else {
            foreach ($changes as $change) {
                $stmt->execute([
                    $record_id, $action, $user_id,
                    $change['field'] ?? $type,
                    $change['old'] ?? null,
                    $change['new'] ?? null,
                    $reason, $ip, $ua
                ]);
            }
        }
    } catch (Exception $e) {
        error_log("監査ログ記録エラー: " . $e->getMessage());
    }
}

/**
 * 日付ベースの編集可否判定（共通）
 * @param array $record レコードデータ
 * @param string $date_column 日付カラム名
 * @param string $user_role ユーザー権限 (Admin/User)
 * @return array ['can_edit'=>bool, 'needs_reason'=>bool, 'lock_reason'=>string]
 */
function canEditByDate($record, $date_column, $user_role) {
    $today = date('Y-m-d');
    $record_date = $record[$date_column] ?? '';

    if ($record_date >= $today) {
        return ['can_edit' => true, 'needs_reason' => false, 'lock_reason' => ''];
    }

    if ($user_role === 'Admin') {
        return ['can_edit' => true, 'needs_reason' => true, 'lock_reason' => '過去日の記録です。管理者権限で修正できます。'];
    }

    return ['can_edit' => false, 'needs_reason' => false, 'lock_reason' => '過去日の記録はロックされています。管理者にお問い合わせください。'];
}

?>
