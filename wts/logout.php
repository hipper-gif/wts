<?php
session_start();

// ログアウト処理
if (isset($_SESSION['user_id'])) {
    $user_name = $_SESSION['user_name'] ?? 'Unknown User';
    error_log("Logout: User {$user_name} logged out at " . date('Y-m-d H:i:s'));
}

// セッションの完全な破棄
$_SESSION = array();

// セッションクッキーがある場合は削除
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// セッション破棄
session_destroy();

// ログアウト完了メッセージ付きでログイン画面にリダイレクト
header('Location: index.php?logout=1');
exit;
?>
