<?php
// セッションセキュリティ設定
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
session_start();
require_once 'config/database.php';

// 既にログイン済みの場合はダッシュボードへリダイレクト
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';

if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $error_message = 'セッションがタイムアウトしました。再度ログインしてください。';
}

// CSRFトークンの初期化（ログイン画面表示時）
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF検証（セッション切れ後の再ログインではトークン不一致を許容し、トークンを再生成）
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        // トークンを再生成して再度ログインを促す
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $error_message = 'セッションの有効期限が切れました。もう一度ログインしてください。';
    }

    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($error_message) && (empty($login_id) || empty($password))) {
        $error_message = 'ログインIDとパスワードを入力してください。';
    } elseif (empty($error_message)) {
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
                
                // 権限レベル設定（user_roleが正規変数、is_adminはpermission_levelから派生）
                $_SESSION['user_role'] = $user['permission_level'] ?? 'User';
                $_SESSION['is_admin'] = ($user['permission_level'] === 'Admin') ? 1 : 0;

                // 職務フラグをセッションに保存（最適化後の構造に対応）
                $_SESSION['is_driver'] = (bool)($user['is_driver'] ?? false);
                $_SESSION['is_caller'] = (bool)($user['is_caller'] ?? false);
                $_SESSION['is_manager'] = (bool)($user['is_manager'] ?? false);
                $_SESSION['is_mechanic'] = (bool)($user['is_mechanic'] ?? false);
                $_SESSION['is_inspector'] = (bool)($user['is_inspector'] ?? false);
                
                // セキュリティ対策
                $_SESSION['login_time'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                session_regenerate_id(true);
                $_SESSION['last_activity'] = time();

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
                
                // セッションを確実に書き込んでからリダイレクト（Android PWA対応）
                session_write_close();
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

// システム名を動的取得（tenant.php経由）
$pdo = getDBConnection();
$settings = getTenantSettings();
$system_name = $settings['system_name'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- PWA対応メタタグ -->
    <link rel="manifest" href="manifest.json.php">
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

    <!-- ログインページ専用CSS -->
    <link rel="stylesheet" href="css/login.css">

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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
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
                    <?= htmlspecialchars($system_name) ?>
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
            navigator.serviceWorker.register('<?= APP_BASE_PATH ?>/sw.js.php')
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
