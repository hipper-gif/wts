<?php
session_start();
require_once 'config/database.php';

// 既にログイン済みの場合はダッシュボードへリダイレクト
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login_id) || empty($password)) {
        $error_message = 'ログインIDとパスワードを入力してください。';
    } else {
        try {
            $pdo = getDBConnection();
            
            // 【修正】最適化後のusersテーブル構造に対応
            $stmt = $pdo->prepare("
                SELECT id, name, password, permission_level, 
                       is_driver, is_caller, is_manager, is_admin 
                FROM users 
                WHERE login_id = ? AND is_active = 1
            ");
            $stmt->execute([$login_id]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // 【修正】ログイン成功時のセッション設定（最適化後対応）
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['login_id'] = $login_id;
                
                // 権限レベル設定
                $_SESSION['user_permission_level'] = $user['permission_level'] ?? 'User';
                
                // 職務フラグをセッションに保存（最適化後の構造に対応）
                $_SESSION['is_driver'] = (bool)($user['is_driver'] ?? false);
                $_SESSION['is_caller'] = (bool)($user['is_caller'] ?? false);
                $_SESSION['is_manager'] = (bool)($user['is_manager'] ?? false);
                $_SESSION['is_admin'] = (bool)($user['is_admin'] ?? false);
                
                // ログイン時刻記録
                $_SESSION['login_time'] = time();
                
                // 最終ログイン時刻を更新（最適化後のカラム名に対応）
                try {
                    $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                } catch (Exception $e) {
                    // 更新に失敗してもログインは継続
                    error_log("Last login update failed: " . $e->getMessage());
                }
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error_message = 'ログインIDまたはパスワードが正しくありません。';
            }
        } catch (Exception $e) {
            $error_message = 'ログイン処理中にエラーが発生しました。しばらく待ってから再度お試しください。';
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>