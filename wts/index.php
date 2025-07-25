<?php
// index.php のログイン処理部分（該当箇所のみ）
// 既存のログイン成功処理の直後に以下のコードで置き換えてください

// ログイン認証が成功した場合の処理
if ($user && password_verify($password, $user['password'])) {
    // 基本セッション情報
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['login_id'] = $user['login_id'];
    
    // 【修正】権限の統合判定
    // データベースから最新の権限情報を確実に取得
    $is_driver = isset($user['is_driver']) ? (bool)$user['is_driver'] : false;
    $is_caller = isset($user['is_caller']) ? (bool)$user['is_caller'] : false;
    $is_admin = isset($user['is_admin']) ? (bool)$user['is_admin'] : false;
    $db_role = $user['role'] ?? 'driver';
    
    // メイン権限の決定（優先度：admin > manager > driver）
    if ($is_admin || $db_role === 'admin') {
        $_SESSION['user_role'] = 'admin';
    } elseif ($db_role === 'manager' || $is_caller) {
        $_SESSION['user_role'] = 'manager';
    } else {
        $_SESSION['user_role'] = 'driver';
    }
    
    // 個別権限も保存
    $_SESSION['is_driver'] = $is_driver;
    $_SESSION['is_caller'] = $is_caller;
    $_SESSION['is_admin'] = $is_admin;
    
    // 【追加】デバッグ情報をログに記録（開発時のみ）
    error_log("Login success: User={$user['name']}, Role={$_SESSION['user_role']}, Admin={$is_admin}, Caller={$is_caller}, Driver={$is_driver}");
    
    // ダッシュボードにリダイレクト
    header('Location: dashboard.php');
    exit;
}
?>
