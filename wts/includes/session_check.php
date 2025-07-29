<?php
// includes/session_check.php - 全画面で使用する共通セッション管理

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続（まだ存在しない場合）
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

// ユーザー情報を取得してグローバル変数に設定（permission_level使用）
function getCurrentUser() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, name, permission_level FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$user) {
            // ユーザーが見つからない場合はログアウト
            session_destroy();
            header('Location: index.php');
            exit;
        }
        
        return $user;
        
    } catch (PDOException $e) {
        // データベースエラーの場合
        error_log("Database error in session_check: " . $e->getMessage());
        return (object)[
            'id' => $_SESSION['user_id'],
            'name' => 'ゲスト',
            'permission_level' => 'User'
        ];
    }
}

// グローバル変数として設定（permission_level使用）
$current_user = getCurrentUser();
$user_id = $current_user->id;
$user_name = $current_user->name;
$user_role = $current_user->permission_level; // permission_levelをuser_roleとして使用

// 権限チェック関数（permission_levelベース）
function requireRole($required_role) {
    global $user_role;
    
    // permission_levelは Admin/User の2段階
    $role_hierarchy = [
        'User' => 1,
        'Admin' => 2
    ];
    
    $user_level = $role_hierarchy[$user_role] ?? 1;
    $required_level = $role_hierarchy[$required_role] ?? 2;
    
    if ($user_level < $required_level) {
        header('HTTP/1.1 403 Forbidden');
        echo '<h1>アクセス権限がありません</h1>';
        echo '<p><a href="dashboard.php">ダッシュボードに戻る</a></p>';
        exit;
    }
}

// 管理者チェック関数（Adminのみ）
function requireAdmin() {
    requireRole('Admin');
}

// ログ出力関数
function logUserAction($action, $details = '') {
    global $user_id, $user_name;
    
    $log_entry = date('Y-m-d H:i:s') . " - User: {$user_name} (ID: {$user_id}) - Action: {$action}";
    if ($details) {
        $log_entry .= " - Details: {$details}";
    }
    $log_entry .= "\n";
    
    // ログファイルに記録（本番環境では適切な場所に）
    file_put_contents('logs/user_actions.log', $log_entry, FILE_APPEND | LOCK_EX);
}
?>
