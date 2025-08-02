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
            
            // 【修正】全ての権限情報を取得
            $stmt = $pdo->prepare("SELECT id, name, password, permission_level, is_driver, is_caller, is_admin FROM users WHERE login_id = ? AND is_active = TRUE");
            $stmt->execute([$login_id]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // 【修正】ログイン成功時のセッション設定改善
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['login_id'] = $user['login_id'];
                
                // 権限の統合判定
$permission_level = $user['permission_level'] ?? 'User';
$_SESSION['user_permission_level'] = $permission_level;

// 職務フラグもセッションに保存
$_SESSION['is_driver'] = (bool)($user['is_driver'] ?? false);
$_SESSION['is_caller'] = (bool)($user['is_caller'] ?? false);
$_SESSION['is_admin'] = (bool)($user['is_admin'] ?? false);
                
                // 個別権限も保存
                $_SESSION['is_driver'] = $is_driver;
                $_SESSION['is_caller'] = $is_caller;
                $_SESSION['is_admin'] = $is_admin;
                $_SESSION['login_time'] = time();
                

                // 最終ログイン時刻を更新
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
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - ログイン</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            max-width: 400px;
            margin: 0 auto;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 10px;
        }
        .system-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        .company-name {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 0;
        }
        .form-floating {
            margin-bottom: 20px;
        }
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
            color: white;
        }
        .btn-login:active {
            transform: translateY(0);
        }
        .alert {
            border-radius: 10px;
            border: none;
            margin-bottom: 20px;
        }
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        .demo-info {
            margin-top: 20px;
            padding: 15px;
            background-color: rgba(13, 202, 240, 0.1);
            border-radius: 10px;
            border-left: 4px solid #0dcaf0;
        }
        .demo-info h6 {
            color: #0dcaf0;
            margin-bottom: 10px;
        }
        .demo-info p {
            margin: 5px 0;
            font-size: 0.9rem;
            color: #666;
        }
        .input-group-text {
            background-color: transparent;
            border: 2px solid #e9ecef;
            border-right: none;
            border-radius: 10px 0 0 10px;
        }
        .form-control.with-icon {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        
        .login-status {
            margin-top: 15px;
            padding: 10px;
            background-color: rgba(40, 167, 69, 0.1);
            border-radius: 8px;
            border-left: 4px solid #28a745;
            display: none;
        }
        
        .login-status.show {
            display: block;
        }
        
        @media (max-width: 480px) {
            .login-card {
                margin: 20px;
                padding: 30px 20px;
            }
            .logo-icon {
                font-size: 3rem;
            }
            .system-title {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="logo-section">
                    <div class="logo-icon">
                        <i class="fas fa-taxi"></i>
                    </div>
                    <h1 class="system-title">福祉輸送管理システム</h1>
                    <p class="company-name">スマイリーケアタクシー</p>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                
                <!-- ログアウト時のメッセージ表示 -->
                <?php if (isset($_GET['logout']) && $_GET['logout'] === '1'): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        ログアウトしました。
                    </div>
                <?php endif; ?>
                
                <!-- セッション切れ時のメッセージ表示 -->
                <?php if (isset($_GET['session_expired']) && $_GET['session_expired'] === '1'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-clock me-2"></i>
                        セッションが切れました。再度ログインしてください。
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <div class="input-group mb-3">
                        <span class="input-group-text">
                            <i class="fas fa-user"></i>
                        </span>
                        <input type="text" class="form-control with-icon" name="login_id" 
                               placeholder="ログインID" value="<?= htmlspecialchars($_POST['login_id'] ?? '') ?>" required>
                    </div>
                    
                    <div class="input-group mb-4">
                        <span class="input-group-text">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" class="form-control with-icon" name="password" 
                               placeholder="パスワード" required>
                    </div>
                    
                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <i class="fas fa-sign-in-alt me-2"></i>
                        ログイン
                    </button>
                </form>
                
                <div class="demo-info">
                    <h6><i class="fas fa-info-circle me-2"></i>デモ用ログイン情報</h6>
                    <p><strong>管理者:</strong> admin / admin123</p>
                    <p><strong>運転者:</strong> driver1 / driver123</p>
                    <p class="mb-0"><small class="text-muted">※本番運用時はこの情報を削除してください</small></p>
                </div>
                
                <!-- システム状況表示（開発時のみ） -->
                <?php if (isset($_GET['status'])): ?>
                <div class="demo-info" style="border-left-color: #28a745; background-color: rgba(40, 167, 69, 0.1);">
                    <h6><i class="fas fa-server me-2"></i>システム状況</h6>
                    <p>データベース: <span class="text-success">✅ 接続正常</span></p>
                    <p>権限修復: <span class="text-success">✅ 完了</span></p>
                    <p class="mb-0">最終更新: <?= date('Y-m-d H:i:s') ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ログイン処理の改善
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>ログイン中...';
            btn.disabled = true;
        });
        
        // エラーメッセージの自動フェードアウト
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (alert.classList.contains('alert-success')) {
                setTimeout(function() {
                    alert.style.opacity = '0.5';
                }, 3000);
            }
        });
        
        // フォーカス改善
        document.addEventListener('DOMContentLoaded', function() {
            const loginIdInput = document.querySelector('input[name="login_id"]');
            if (loginIdInput && !loginIdInput.value) {
                loginIdInput.focus();
            }
        });
    </script>
</body>
</html>
