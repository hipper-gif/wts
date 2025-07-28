<?php
/**
 * 福祉輸送管理システム - 更新版ユーザー関数セット
 * 既存テーブル構造を活用した権限・属性管理
 */

/**
 * 運転者リスト取得（運行記録用）
 */
function getDriversForList($pdo) {
    $stmt = $pdo->query("
        SELECT id, name 
        FROM users 
        WHERE is_driver = TRUE
        ORDER BY name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 点呼者リスト取得（点呼記録用）
 */
function getCallersForList($pdo) {
    $stmt = $pdo->query("
        SELECT id, name 
        FROM users 
        WHERE is_caller = TRUE
        ORDER BY name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 点検者リスト取得（点検記録用）
 */
function getInspectorsForList($pdo) {
    $stmt = $pdo->query("
        SELECT id, name 
        FROM users 
        WHERE is_inspector = TRUE
        ORDER BY name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 全ユーザー一覧取得（管理画面用）
 */
function getAllUsers($pdo) {
    $stmt = $pdo->query("
        SELECT *, 
               CASE WHEN role = 'admin' THEN '管理者' ELSE 'ユーザー' END as role_display,
               CONCAT(
                   CASE WHEN is_driver THEN '運転者 ' ELSE '' END,
                   CASE WHEN is_caller THEN '点呼者 ' ELSE '' END,
                   CASE WHEN is_inspector THEN '点検者 ' ELSE '' END
               ) as attributes_display
        FROM users 
        ORDER BY role DESC, name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ユーザー権限チェック
 */
function checkUserPermission($required_role = 'user') {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        return false;
    }
    
    if ($required_role === 'admin') {
        return $_SESSION['role'] === 'admin';
    }
    
    return true; // user権限以上であれば OK
}

/**
 * レコード編集権限チェック
 */
function checkRecordEditPermission($pdo, $record_id, $table_name, $user_id, $user_role) {
    // 管理者は全レコード編集可能
    if ($user_role === 'admin') {
        return true;
    }
    
    // ユーザーは自分のレコードのみ編集可能
    $stmt = $pdo->prepare("SELECT driver_id FROM {$table_name} WHERE id = ?");
    $stmt->execute([$record_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return ($record && $record['driver_id'] == $user_id);
}

/**
 * ユーザー情報取得
 */
function getUserById($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * ユーザー登録・更新
 */
function saveUser($pdo, $userData) {
    if (isset($userData['id']) && $userData['id']) {
        // 更新
        $stmt = $pdo->prepare("
            UPDATE users SET 
                name = ?, 
                login_id = ?, 
                role = ?,
                is_driver = ?,
                is_caller = ?,
                is_inspector = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $userData['name'],
            $userData['login_id'],
            $userData['role'],
            isset($userData['is_driver']) ? 1 : 0,
            isset($userData['is_caller']) ? 1 : 0,
            isset($userData['is_inspector']) ? 1 : 0,
            $userData['id']
        ]);
        
    } else {
        // 新規登録
        $stmt = $pdo->prepare("
            INSERT INTO users (name, login_id, password, role, is_driver, is_caller, is_inspector) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $userData['name'],
            $userData['login_id'],
            password_hash($userData['password'], PASSWORD_DEFAULT),
            $userData['role'],
            isset($userData['is_driver']) ? 1 : 0,
            isset($userData['is_caller']) ? 1 : 0,
            isset($userData['is_inspector']) ? 1 : 0
        ]);
    }
}

/**
 * ログイン処理（既存コードとの互換性維持）
 */
function authenticateUser($pdo, $login_id, $password) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE login_id = ?");
    $stmt->execute([$login_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        // セッション設定（既存コードとの互換性）
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role'] = $user['role'];  // 'admin' または 'user'
        $_SESSION['is_driver'] = $user['is_driver'];
        $_SESSION['is_caller'] = $user['is_caller'];
        $_SESSION['is_inspector'] = $user['is_inspector'];
        
        return $user;
    }
    
    return false;
}

/**
 * 権限別アクセス制御（ページ先頭で使用）
 */
function requirePermission($required_role = 'user') {
    if (!checkUserPermission($required_role)) {
        if (!isset($_SESSION['user_id'])) {
            header('Location: index.php?error=login_required');
        } else {
            header('Location: dashboard.php?error=access_denied');
        }
        exit;
    }
}

/**
 * HTML出力用のヘルパー関数
 */
function renderDriverSelect($pdo, $selected_id = null, $name = 'driver_id', $required = true) {
    $drivers = getDriversForList($pdo);
    $required_attr = $required ? 'required' : '';
    
    echo "<select name='{$name}' class='form-control' {$required_attr}>";
    echo "<option value=''>選択してください</option>";
    foreach ($drivers as $driver) {
        $selected = ($driver['id'] == $selected_id) ? 'selected' : '';
        echo "<option value='{$driver['id']}' {$selected}>{$driver['name']}</option>";
    }
    echo "</select>";
}

function renderCallerSelect($pdo, $selected_id = null, $name = 'caller_id', $required = true) {
    $callers = getCallersForList($pdo);
    $required_attr = $required ? 'required' : '';
    
    echo "<select name='{$name}' class='form-control' {$required_attr}>";
    echo "<option value=''>選択してください</option>";
    foreach ($callers as $caller) {
        $selected = ($caller['id'] == $selected_id) ? 'selected' : '';
        echo "<option value='{$caller['id']}' {$selected}>{$caller['name']}</option>";
    }
    echo "</select>";
}

function renderInspectorSelect($pdo, $selected_id = null, $name = 'inspector_id', $required = true) {
    $inspectors = getInspectorsForList($pdo);
    $required_attr = $required ? 'required' : '';
    
    echo "<select name='{$name}' class='form-control' {$required_attr}>";
    echo "<option value=''>選択してください</option>";
    foreach ($inspectors as $inspector) {
        $selected = ($inspector['id'] == $selected_id) ? 'selected' : '';
        echo "<option value='{$inspector['id']}' {$selected}>{$inspector['name']}</option>";
    }
    echo "</select>";
}

/**
 * デバッグ用：現在のユーザー情報表示
 */
function debugCurrentUser() {
    if (isset($_SESSION['user_id'])) {
        echo "<div style='background: #f8f9fa; border: 1px solid #dee2e6; padding: 10px; margin: 10px 0;'>";
        echo "<strong>現在のユーザー情報:</strong><br>";
        echo "ID: " . $_SESSION['user_id'] . "<br>";
        echo "名前: " . $_SESSION['user_name'] . "<br>";
        echo "権限: " . $_SESSION['role'] . "<br>";
        echo "運転者: " . ($_SESSION['is_driver'] ? 'Yes' : 'No') . "<br>";
        echo "点呼者: " . ($_SESSION['is_caller'] ? 'Yes' : 'No') . "<br>";
        echo "点検者: " . ($_SESSION['is_inspector'] ? 'Yes' : 'No') . "<br>";
        echo "</div>";
    }
}

// 使用例コメント
/*
使用例:

// ページ先頭での権限チェック
requirePermission('admin'); // 管理者のみアクセス可能
requirePermission('user');  // ログインユーザーなら誰でも

// リスト表示
$drivers = getDriversForList($pdo);
$callers = getCallersForList($pdo);
$inspectors = getInspectorsForList($pdo);

// HTML出力
renderDriverSelect($pdo, $selected_driver_id);
renderCallerSelect($pdo, $selected_caller_id);

// レコード編集権限チェック
if (checkRecordEditPermission($pdo, $record_id, 'pre_duty_calls', $_SESSION['user_id'], $_SESSION['role'])) {
    // 編集処理実行
}

// 既存の権限チェック（そのまま使用可能）
if ($_SESSION['role'] === 'admin') {
    // 管理者専用処理
}
*/
?>
