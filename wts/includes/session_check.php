<?php
// includes/session_check.php - 全画面で使用する共通セッション管理

// セッションセキュリティ設定（session_start前に設定）
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// サブディレクトリ対応ベースパス検出
$_sc_base = '';
$_sc_script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (preg_match('#/wts/calendar/api(/|$)#', $_sc_script)) {
    $_sc_base = '../../';
} elseif (preg_match('#/wts/(calendar|api)(/|$)#', $_sc_script)) {
    $_sc_base = '../';
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $_sc_base . 'index.php');
    exit;
}

// セッションタイムアウトチェック（DB設定値をセッションにキャッシュ）
$session_timeout = $_SESSION['session_timeout_seconds'] ?? 28800; // デフォルト8時間
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_unset();
    session_destroy();
    header('Location: ' . $_sc_base . 'index.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

// CSRFトークン生成（未設定の場合）
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// データベース接続（まだ存在しない場合）
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
    $pdo = getDBConnection();
}

// セッションタイムアウト設定をDBからキャッシュ（5分ごとに更新）
if (!isset($_SESSION['session_timeout_cached_at']) || (time() - $_SESSION['session_timeout_cached_at'] > 300)) {
    try {
        $stmt_timeout = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout'");
        $stmt_timeout->execute();
        $timeout_val = $stmt_timeout->fetchColumn();
        if ($timeout_val !== false) {
            $_SESSION['session_timeout_seconds'] = (int)$timeout_val;
        }
    } catch (PDOException $e) {
        // テーブルがない場合等は無視（デフォルト値を使用）
    }
    $_SESSION['session_timeout_cached_at'] = time();
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
            global $_sc_base;
            header('Location: ' . ($_sc_base ?? '') . 'index.php');
            exit;
        }
        
        return $user;
        
    } catch (PDOException $e) {
        // データベースエラーの場合 — 安全のためアクセス拒否
        error_log("Database error in session_check: " . $e->getMessage());
        session_destroy();
        global $_sc_base;
        header('Location: ' . ($_sc_base ?? '') . 'index.php?error=db');
        exit;
    }
}

// グローバル変数として設定（permission_level使用）
$current_user = getCurrentUser();
$user_id = $current_user->id;
$user_name = $current_user->name;
$user_role = $current_user->permission_level; // permission_levelをuser_roleとして使用

/**
 * CSRFトークンを検証
 */
function validateCsrfToken() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '不正なリクエストです']);
        exit;
    }
}

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
        global $_sc_base;
        echo '<p><a href="' . ($_sc_base ?? '') . 'dashboard.php">ダッシュボードに戻る</a></p>';
        exit;
    }
}
?>
