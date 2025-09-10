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
            
            // 最適化後のusersテーブル構造に対応（18カラム構造）
            $stmt = $pdo->prepare("
                SELECT id, name, password, permission_level, 
                       is_driver, is_caller, is_manager, is_admin, is_mechanic, is_inspector
                FROM users 
                WHERE login_id = ? AND is_active = 1
            ");
            $stmt->execute([$login_id]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // ログイン成功時のセッション設定（最適化後対応）
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['login_id'] = $login_id;
                
                // 権限レベル設定
                $_SESSION['user_permission_level'] = $user['permission_level'] ?? 'User';
                $_SESSION['is_admin_user'] = ($user['permission_level'] === 'Admin');
                
                // 職務フラグをセッションに保存（最適化後の構造に対応）
                $_SESSION['is_driver'] = (bool)($user['is_driver'] ?? false);
                $_SESSION['is_caller'] = (bool)($user['is_caller'] ?? false);
                $_SESSION['is_manager'] = (bool)($user['is_manager'] ?? false);
                $_SESSION['is_admin'] = (bool)($user['is_admin'] ?? false);
                $_SESSION['is_mechanic'] = (bool)($user['is_mechanic'] ?? false);
                $_SESSION['is_inspector'] = (bool)($user['is_inspector'] ?? false);
                
                // セキュリティ対策
                $_SESSION['login_time'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                // 最終ログイン時刻を更新（最適化後のカラム名：last_login_at）
                try {
                    $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                } catch (Exception $e) {
                    // 更新に失敗してもログインは継続
                    error_log("Last login update failed: " . $e->getMessage());
                }
                
                // PWA対応：ログイン成功時の追跡
                if (isset($_GET['utm_source']) && $_GET['utm_source'] === 'pwa') {
                    $_SESSION['pwa_login'] = true;
                }
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error_message = 'ログインIDまたはパスワードが正しくありません。';
                // セキュリティ：失敗ログ記録
                error_log("Login failed for ID: " . $login_id . " from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            }
        } catch (Exception $e) {
            $error_message = 'ログイン処理中にエラーが発生しました。しばらく待ってから再度お試しください。';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

// システム名を動的取得（設定可能システム名対応）
$system_name = 'WTS';
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    if ($result) {
        $system_name = $result;
    }
} catch (Exception $e) {
    // デフォルト名を使用
    error_log("System name fetch error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- PWA対応メタタグ -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#2196F3">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($system_name) ?> v3.1">
    <link rel="apple-touch-icon" href="icons/icon-192x192.png">
    
    <!-- モダンミニマル統一対応 -->
    <title><?= htmlspecialchars($system_name) ?> - ログイン</title>
    
    <!-- Bootstrap & Font Awesome（統一仕様） -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Google Fonts（統一フォント） -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- PWA専用CSS -->
    <link rel="stylesheet" href="css/pwa-styles.css">
    
    <style>
        /* モダンミニマル統一スタイル */
        :root {
            --primary: #2196F3;
            --primary-dark: #1976D2;
            --success: #4CAF50;
            --warning: #FFC107;
            --danger: #F44336;
            --info: #00BCD4;
            --white: #FFFFFF;
            --light-gray: #F8F9FA;
            --medium-gray: #6C757D;
            --dark-gray: #343A40;
            --black: #1A1A1A;
        }
        
        body {
            font-family: 'Noto Sans JP', 'Hiragino Kaku Gothic ProN', sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        
        .login-container {
            background: var(--white);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            padding: 0;
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            padding: 2rem;
            text-align: center;
            position: relative;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin: 0 0 0.5rem 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .login-header .subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin: 0;
            font-weight: 400;
        }
        
        .login-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--white);
            opacity: 0.9;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .form-floating {
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .form-control {
            border: 2px solid #E9ECEF;
            border-radius: 12px;
            padding: 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: var(--light-gray);
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(33, 150, 243, 0.25);
            background-color: var(--white);
        }
        
        .form-floating > label {
            color: var(--medium-gray);
            font-weight: 500;
            padding: 1rem;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            border-radius: 12px;
            color: var(--white);
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            width: 100%;
            margin-top: 1rem;
            transition: all 0.3s ease;
            text-transform: none;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.4);
            background: linear-gradient(135deg, var(--primary-dark) 0%, #1565C0 100%);
            color: var(--white);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            border-left: 4px solid var(--danger);
        }
        
        .alert-danger {
            background-color: #FFF5F5;
            color: #C53030;
        }
        
        .system-info {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #E9ECEF;
            font-size: 0.85rem;
            color: var(--medium-gray);
        }
        
        .version-badge {
            display: inline-block;
            background: var(--primary);
            color: var(--white);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }
        
        /* PWAインストール促進バナー */
        .pwa-banner {
            display: none;
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 1rem;
            text-align: center;
            margin: -2rem -2rem 2rem -2rem;
            font-size: 0.9rem;
            animation: slideDown 0.5s ease;
        }
        
        .pwa-banner.show {
            display: block;
        }
        
        .pwa-banner button {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            margin-left: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .pwa-banner button:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* レスポンシブ対応 */
        @media (max-width: 576px) {
            .login-container {
                margin: 10px;
                border-radius: 12px;
            }
            
            .login-header {
                padding: 1.5rem;
            }
            
            .login-header h1 {
                font-size: 1.5rem;
            }
            
            .login-body {
                padding: 1.5rem;
            }
            
            .login-icon {
                font-size: 2rem;
            }
        }
        
        /* ダークモード対応 */
        @media (prefers-color-scheme: dark) {
            .login-container {
                background: #2D2D2D;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            }
            
            .form-control {
                background-color: #404040;
                border-color: #555555;
                color: #FFFFFF;
            }
            
            .form-floating > label {
                color: #CCCCCC;
            }
            
            .system-info {
                color: #CCCCCC;
                border-color: #555555;
            }
        }
        
        /* アニメーション */
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .login-container {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- PWAインストール促進バナー -->
        <div id="pwa-banner" class="pwa-banner">
            <i class="fas fa-mobile-alt me-2"></i>
            このアプリをホーム画面に追加してより快適に利用できます
            <button onclick="installPWA()" id="install-button">
                <i class="fas fa-download me-1"></i>インストール
            </button>
        </div>
        
        <!-- ログインヘッダー -->
        <div class="login-header">
            <div class="login-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1><?= htmlspecialchars($system_name) ?></h1>
            <p class="subtitle">v3.1 - ログイン</p>
        </div>
        
        <!-- ログインフォーム -->
        <div class="login-body">
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" autocomplete="on">
                <div class="form-floating">
                    <input type="text" 
                           class="form-control" 
                           id="login_id" 
                           name="login_id" 
                           placeholder="ログインID"
                           autocomplete="username"
                           required>
                    <label for="login_id">
                        <i class="fas fa-user me-2"></i>ログインID
                    </label>
                </div>
                
                <div class="form-floating">
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           placeholder="パスワード"
                           autocomplete="current-password"
                           required>
                    <label for="password">
                        <i class="fas fa-lock me-2"></i>パスワード
                    </label>
                </div>
                
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>ログイン
                </button>
            </form>
            
            <!-- システム情報 -->
            <div class="system-info">
                <div>
                    <i class="fas fa-truck me-1"></i>
                    福祉輸送事業管理システム
                </div>
                <span class="version-badge">
                    <i class="fas fa-code-branch me-1"></i>
                    Phase 3 - PWA対応版
                </span>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <!-- PWA対応JavaScript -->
    <script>
        // PWAインストール管理
        let deferredPrompt;
        
        // PWAインストール可能性チェック
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            
            // PWAバナー表示
            const banner = document.getElementById('pwa-banner');
            if (banner && !isInstalled()) {
                banner.classList.add('show');
            }
        });
        
        // PWAインストール実行
        async function installPWA() {
            if (!deferredPrompt) return;
            
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            
            if (outcome === 'accepted') {
                console.log('PWA インストール開始');
                // バナー非表示
                document.getElementById('pwa-banner').style.display = 'none';
            }
            
            deferredPrompt = null;
        }
        
        // PWAインストール状態確認
        function isInstalled() {
            return (
                window.matchMedia('(display-mode: standalone)').matches ||
                window.navigator.standalone === true
            );
        }
        
        // PWAアプリインストール完了時
        window.addEventListener('appinstalled', () => {
            console.log('PWA インストール完了');
            document.getElementById('pwa-banner').style.display = 'none';
            
            // インストール成功通知
            if ('Notification' in window) {
                new Notification('アプリインストール完了', {
                    body: 'ホーム画面からアプリにアクセスできます',
                    icon: 'icons/icon-192x192.png'
                });
            }
        });
        
        // Service Worker 登録
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(registration => {
                    console.log('Service Worker 登録成功:', registration.scope);
                })
                .catch(error => {
                    console.log('Service Worker 登録失敗:', error);
                });
        }
        
        // フォームのリアルタイム検証
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.querySelector('form');
            const inputs = loginForm.querySelectorAll('input[required]');
            
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.value.trim() !== '') {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                });
            });
            
            // フォーカス時の視覚効果
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentNode.style.transform = 'scale(1.02)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentNode.style.transform = 'scale(1)';
                });
            });
        });
        
        // セキュリティ対策：ブルートフォース攻撃防止
        let loginAttempts = 0;
        const maxAttempts = 5;
        const lockoutTime = 15 * 60 * 1000; // 15分
        
        function handleLoginAttempt() {
            loginAttempts++;
            localStorage.setItem('loginAttempts', loginAttempts.toString());
            localStorage.setItem('lastAttempt', Date.now().toString());
            
            if (loginAttempts >= maxAttempts) {
                const loginButton = document.querySelector('.btn-login');
                loginButton.disabled = true;
                loginButton.innerHTML = '<i class="fas fa-clock me-2"></i>15分後に再試行してください';
                
                setTimeout(() => {
                    loginButton.disabled = false;
                    loginButton.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>ログイン';
                    loginAttempts = 0;
                    localStorage.removeItem('loginAttempts');
                    localStorage.removeItem('lastAttempt');
                }, lockoutTime);
            }
        }
        
        // ページ読み込み時のロックアウトチェック
        window.addEventListener('load', function() {
            const attempts = parseInt(localStorage.getItem('loginAttempts') || '0');
            const lastAttempt = parseInt(localStorage.getItem('lastAttempt') || '0');
            
            if (attempts >= maxAttempts && Date.now() - lastAttempt < lockoutTime) {
                const remaining = lockoutTime - (Date.now() - lastAttempt);
                const minutes = Math.ceil(remaining / 60000);
                
                const loginButton = document.querySelector('.btn-login');
                loginButton.disabled = true;
                loginButton.innerHTML = `<i class="fas fa-clock me-2"></i>${minutes}分後に再試行してください`;
                
                setTimeout(() => {
                    loginButton.disabled = false;
                    loginButton.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>ログイン';
                    localStorage.removeItem('loginAttempts');
                    localStorage.removeItem('lastAttempt');
                }, remaining);
            }
        });
        
        // エラー時のログイン試行回数カウント
        <?php if (!empty($error_message)): ?>
            handleLoginAttempt();
        <?php endif; ?>
    </script>
</body>
</html>
