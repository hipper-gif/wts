<?php
session_start();

// データベース接続
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

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

$messages = [];
$errors = [];

try {
    // 1. usersテーブルの構造を確認・修正
    $messages[] = "1. usersテーブルの構造確認を開始...";
    
    // 必要なカラムの存在確認
    $stmt = $pdo->query("DESCRIBE users");
    $existing_columns = array_column($stmt->fetchAll(), 'Field');
    
    $required_columns = [
        ['name' => 'is_driver', 'type' => 'TINYINT(1) DEFAULT 0'],
        ['name' => 'is_caller', 'type' => 'TINYINT(1) DEFAULT 0'], 
        ['name' => 'is_admin', 'type' => 'TINYINT(1) DEFAULT 0'],
        ['name' => 'is_active', 'type' => 'TINYINT(1) DEFAULT 1'],
        ['name' => 'last_login_at', 'type' => 'TIMESTAMP NULL'],
        ['name' => 'created_at', 'type' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
        ['name' => 'updated_at', 'type' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP']
    ];
    
    foreach ($required_columns as $column) {
        if (!in_array($column['name'], $existing_columns)) {
            $sql = "ALTER TABLE users ADD COLUMN {$column['name']} {$column['type']}";
            $pdo->exec($sql);
            $messages[] = "✅ カラム {$column['name']} を追加しました";
        } else {
            $messages[] = "✓ カラム {$column['name']} は既に存在します";
        }
    }
    
    // 2. 既存ユーザーの権限を正規化
    $messages[] = "\n2. 既存ユーザーの権限正規化を開始...";
    
    // roleカラムに基づいて適切な権限フラグを設定
    $stmt = $pdo->query("SELECT id, name, role FROM users");
    $users = $stmt->fetchAll();
    
    foreach ($users as $user) {
        $is_driver = 0;
        $is_caller = 0; 
        $is_admin = 0;
        $new_role = $user['role'];
        
        // 既存のroleに基づいて権限を設定
        switch ($user['role']) {
            case 'admin':
            case 'システム管理者':
                $is_admin = 1;
                $is_caller = 1; // 通常、管理者は点呼も実施可能
                $new_role = 'admin';
                break;
                
            case 'manager':
            case '管理者':
            case '点呼者':
                $is_caller = 1;
                $new_role = 'manager';
                break;
                
            case 'driver':
            case '運転者':
                $is_driver = 1;
                $new_role = 'driver';
                break;
                
            default:
                // 不明なroleは運転者として設定
                $is_driver = 1;
                $new_role = 'driver';
                break;
        }
        
        // 更新実行
        $stmt = $pdo->prepare("
            UPDATE users 
            SET is_driver = ?, is_caller = ?, is_admin = ?, role = ?, is_active = 1
            WHERE id = ?
        ");
        $stmt->execute([$is_driver, $is_caller, $is_admin, $new_role, $user['id']]);
        
        $permissions = [];
        if ($is_driver) $permissions[] = '運転者';
        if ($is_caller) $permissions[] = '点呼者';
        if ($is_admin) $permissions[] = 'システム管理者';
        
        $messages[] = "✅ ユーザー「{$user['name']}」: " . implode(' + ', $permissions) . " (メイン権限: {$new_role})";
    }
    
    // 3. デフォルト管理者アカウントの確認・作成
    $messages[] = "\n3. デフォルト管理者アカウントの確認...";
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = 'admin'");
    $stmt->execute();
    
    if ($stmt->fetchColumn() == 0) {
        // デフォルト管理者アカウントを作成
        $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (name, login_id, password, role, is_driver, is_caller, is_admin, is_active, created_at) 
            VALUES ('システム管理者', 'admin', ?, 'admin', 0, 1, 1, 1, NOW())
        ");
        $stmt->execute([$hashed_password]);
        $messages[] = "✅ デフォルト管理者アカウント（admin/admin123）を作成しました";
    } else {
        // 既存の管理者アカウントを更新
        $stmt = $pdo->prepare("
            UPDATE users 
            SET is_admin = 1, is_caller = 1, role = 'admin', is_active = 1
            WHERE login_id = 'admin'
        ");
        $stmt->execute();
        $messages[] = "✅ 既存のadminアカウントの権限を確認・更新しました";
    }
    
    // 4. 改善されたログイン処理の適用
    $messages[] = "\n4. ログイン処理の改善を確認...";
    
    // セッション更新（現在ログイン中の場合）
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("
            SELECT id, name, role, is_driver, is_caller, is_admin 
            FROM users 
            WHERE id = ? AND is_active = TRUE
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $current_user = $stmt->fetch();
        
        if ($current_user) {
            $_SESSION['user_name'] = $current_user['name'];
            $_SESSION['user_role'] = $current_user['role'];
            $_SESSION['is_driver'] = $current_user['is_driver'];
            $_SESSION['is_caller'] = $current_user['is_caller'];
            $_SESSION['is_admin'] = $current_user['is_admin'];
            
            $messages[] = "✅ 現在のセッションを更新しました: {$current_user['name']}";
        }
    }
    
    // 5. 運転者のみ表示用のビューを作成（オプション）
    $messages[] = "\n5. 運転者選択用の機能を確認...";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(is_driver) as drivers FROM users WHERE is_active = 1");
    $stats = $stmt->fetch();
    
    $messages[] = "✅ 有効ユーザー: {$stats['total']}名（うち運転者: {$stats['drivers']}名）";
    
    // 6. テーブル構造の最終確認
    $messages[] = "\n6. 最終確認...";
    
    $stmt = $pdo->query("
        SELECT 
            name, login_id, role,
            is_driver, is_caller, is_admin, is_active
        FROM users 
        ORDER BY is_admin DESC, is_caller DESC, is_driver DESC, name
    ");
    $final_users = $stmt->fetchAll();
    
    $messages[] = "=== 修正完了後のユーザー一覧 ===";
    foreach ($final_users as $user) {
        $permissions = [];
        if ($user['is_driver']) $permissions[] = '運転者';
        if ($user['is_caller']) $permissions[] = '点呼者';
        if ($user['is_admin']) $permissions[] = 'システム管理者';
        
        $status = $user['is_active'] ? '有効' : '無効';
        $messages[] = "・{$user['name']} ({$user['login_id']}) - " . implode('+', $permissions) . " [{$status}]";
    }
    
    $messages[] = "\n🎉 権限修正が完了しました！";
    $messages[] = "📝 今後は運転者選択で適切にフィルタリングされます";
    $messages[] = "🔐 セッション管理も改善されました";
    
} catch (Exception $e) {
    $errors[] = "エラーが発生しました: " . $e->getMessage();
    error_log("User permissions fix error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>権限修正スクリプト - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background-color: #f8f9fa; 
            font-family: 'Courier New', monospace;
        }
        .container {
            max-width: 800px;
            margin-top: 2rem;
        }
        .result-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .message {
            padding: 0.5rem 0;
            border-left: 3px solid #28a745;
            padding-left: 1rem;
            margin: 0.5rem 0;
            background-color: #f8fff9;
        }
        .error {
            padding: 0.5rem 0;
            border-left: 3px solid #dc3545;
            padding-left: 1rem;
            margin: 0.5rem 0;
            background-color: #fdf2f2;
            color: #721c24;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 2rem;
            border-radius: 10px 10px 0 0;
        }
        pre {
            background: #2d3748;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 5px;
            font-size: 0.9rem;
            max-height: 600px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="result-card">
            <div class="header">
                <h1><i class="fas fa-users-cog me-2"></i>ユーザー権限修正スクリプト</h1>
                <p class="mb-0">福祉輸送管理システムの権限問題を修正します</p>
            </div>
            
            <div class="card-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>エラーが発生しました</h5>
                        <?php foreach ($errors as $error): ?>
                            <div class="error"><?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($messages)): ?>
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle me-2"></i>修正完了</h5>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($messages as $message): ?>
                                <div class="message"><?= htmlspecialchars($message) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="text-center mt-4">
                    <a href="dashboard.php" class="btn btn-primary me-2">
                        <i class="fas fa-home me-1"></i>ダッシュボードに戻る
                    </a>
                    <a href="user_management.php" class="btn btn-outline-primary">
                        <i class="fas fa-users me-1"></i>ユーザー管理を確認
                    </a>
                </div>
                
                <div class="alert alert-info mt-4">
                    <h6><i class="fas fa-info-circle me-2"></i>修正内容</h6>
                    <ul class="mb-0">
                        <li>usersテーブルに権限フラグ（is_driver, is_caller, is_admin）を追加</li>
                        <li>既存ユーザーの権限を正規化</li>
                        <li>運転者選択で適切なフィルタリングが可能に</li>
                        <li>セッション管理の改善</li>
                        <li>デフォルト管理者アカウントの確認・作成</li>
                    </ul>
                </div>
                
                <div class="alert alert-warning mt-3">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>注意事項</h6>
                    <ul class="mb-0">
                        <li>このスクリプトは一度だけ実行してください</li>
                        <li>修正後は必ず各機能の動作を確認してください</li>
                        <li>問題がある場合は、バックアップから復元してください</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
