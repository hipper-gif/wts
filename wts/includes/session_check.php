<?php
// includes/session_check.php - 全画面で使用する共通セッション管理

// セッションセキュリティ設定（session_start前に設定）
// 主設定は.user.ini、ここはフォールバック
if (session_status() == PHP_SESSION_NONE) {
    // WTS専用セッションディレクトリ（他アプリのGCから隔離）
    $wts_session_dir = '/home/twinklemark/twinklemark.xsrv.jp/xserver_php/session_wts';
    if (is_dir($wts_session_dir)) {
        ini_set('session.save_path', $wts_session_dir);
    }
    ini_set('session.gc_maxlifetime', 28800);
    ini_set('session.cookie_lifetime', 0);
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

// セッション完全破棄ヘルパー（Cookie含む）
function destroySessionFully() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// ログインチェック（診断ログ付き）
if (!isset($_SESSION['user_id'])) {
    $diag = [
        'reason' => 'no_user_id',
        'session_id' => session_id(),
        'session_data_keys' => implode(',', array_keys($_SESSION)),
        'save_path' => session_save_path(),
        'page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ];
    error_log("[WTS-SESSION-DIAG] Logout: " . json_encode($diag, JSON_UNESCAPED_UNICODE));
    header('Location: ' . $_sc_base . 'index.php');
    exit;
}

// セッションタイムアウトチェック（DB設定値をセッションにキャッシュ）
$session_timeout = $_SESSION['session_timeout_seconds'] ?? 28800; // デフォルト8時間
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    $diag = [
        'reason' => 'timeout',
        'last_activity' => date('Y-m-d H:i:s', $_SESSION['last_activity']),
        'now' => date('Y-m-d H:i:s'),
        'elapsed_sec' => time() - $_SESSION['last_activity'],
        'timeout_sec' => $session_timeout,
        'user' => $_SESSION['user_name'] ?? 'unknown',
        'page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    ];
    error_log("[WTS-SESSION-DIAG] Logout: " . json_encode($diag, JSON_UNESCAPED_UNICODE));
    destroySessionFully();
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
            // gc_maxlifetimeは.user.iniとsession_start前のini_setで設定済み
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
            destroySessionFully();
            global $_sc_base;
            header('Location: ' . ($_sc_base ?? '') . 'index.php');
            exit;
        }
        
        return $user;
        
    } catch (PDOException $e) {
        // データベースエラーの場合 — 安全のためアクセス拒否
        error_log("Database error in session_check: " . $e->getMessage());
        destroySessionFully();
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
$is_admin = ($user_role === 'Admin'); // 各ページでの判定を省略するためグローバルに設定

/**
 * CSRFトークンを検証
 * Ajax（X-CSRF-TOKEN）の場合はJSON応答、通常フォームの場合はログイン画面へリダイレクト
 */
function validateCsrfToken() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $is_ajax = !empty($_SERVER['HTTP_X_CSRF_TOKEN']) ||
                   (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($is_ajax) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'セッションが切れました。ページを再読み込みしてください。']);
            exit;
        }
        // 通常フォーム送信の場合はログイン画面へリダイレクト
        global $_sc_base;
        header('Location: ' . ($_sc_base ?? '') . 'index.php?timeout=1');
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
