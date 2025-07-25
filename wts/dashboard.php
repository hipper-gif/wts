<?php
// dashboard.php の冒頭部分（セッション・権限チェック部分のみ）
// 既存のコードと置き換えてください

session_start();

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 【修正】ユーザー情報と権限を最新状態で取得
$user_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT name, login_id, role, is_driver, is_caller, is_admin FROM users WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch();
    
    if (!$current_user) {
        // ユーザーが見つからない場合はログアウト
        session_destroy();
        header('Location: index.php');
        exit;
    }
    
    // セッション情報を最新状態で更新
    $user_name = $current_user['name'];
    $_SESSION['user_name'] = $user_name;
    
    // 【重要】権限の統合判定（修正版）
    if ($current_user['is_admin'] || $current_user['role'] === 'admin') {
        $user_role = 'admin';
        $_SESSION['user_role'] = 'admin';
    } elseif ($current_user['role'] === 'manager' || $current_user['is_caller']) {
        $user_role = 'manager';
        $_SESSION['user_role'] = 'manager';
    } else {
        $user_role = 'driver';
        $_SESSION['user_role'] = 'driver';
    }
    
    // 個別権限も保存
    $_SESSION['is_driver'] = (bool)$current_user['is_driver'];
    $_SESSION['is_caller'] = (bool)$current_user['is_caller'];
    $_SESSION['is_admin'] = (bool)$current_user['is_admin'];
    
} catch (Exception $e) {
    error_log("User data fetch error: " . $e->getMessage());
    // セッションからの情報をフォールバック
    $user_name = $_SESSION['user_name'] ?? 'Unknown User';
    $user_role = $_SESSION['user_role'] ?? 'driver';
}

$today = date('Y-m-d');
$current_time = date('H:i');
$current_hour = date('H');

// 当月の開始日
$current_month_start = date('Y-m-01');

// システム名を取得
$system_name = '福祉輸送管理システム';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) {
        $system_name = $result['setting_value'];
    }
} catch (Exception $e) {
    // デフォルト値を使用
}

// 権限確認用のデバッグ情報（開発時のみ表示、本番では削除）
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    echo "<div style='background: #f0f0f0; padding: 10px; margin: 10px; border: 1px solid #ccc;'>";
    echo "<h4>デバッグ情報（開発用）</h4>";
    echo "<p><strong>ユーザーID:</strong> {$user_id}</p>";
    echo "<p><strong>ユーザー名:</strong> {$user_name}</p>";
    echo "<p><strong>権限:</strong> {$user_role}</p>";
    echo "<p><strong>is_admin:</strong> " . (($_SESSION['is_admin'] ?? false) ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p><strong>is_caller:</strong> " . (($_SESSION['is_caller'] ?? false) ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p><strong>is_driver:</strong> " . (($_SESSION['is_driver'] ?? false) ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p><strong>マスタ管理表示:</strong> " . (in_array($user_role, ['admin', 'manager']) ? 'YES' : 'NO') . "</p>";
    echo "</div>";
}

// この下に既存のアラート機能やその他のコードが続きます...
?>
