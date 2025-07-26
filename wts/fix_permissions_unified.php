<?php
/**
 * 福祉輸送管理システム - 統一権限管理修正スクリプト
 * 権限管理の混乱を解決し、統一されたシステムに修正
 */

// セキュリティ: 直接アクセス制限
if (!defined('WTS_SYSTEM')) {
    define('WTS_SYSTEM', true);
}

// データベース接続
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4',
        'twinklemark_taxi',
        'Smiley2525',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// 実行履歴記録
$log = [];

echo "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>福祉輸送管理システム - 統一権限管理修正</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>
    <style>
        .log-success { color: #28a745; }
        .log-warning { color: #ffc107; }
        .log-error { color: #dc3545; }
        .log-info { color: #17a2b8; }
    </style>
</head>
<body class='bg-light'>
<div class='container mt-4'>
    <div class='row justify-content-center'>
        <div class='col-md-10'>
            <div class='card'>
                <div class='card-header bg-primary text-white'>
                    <h4><i class='fas fa-shield-alt'></i> 統一権限管理修正スクリプト</h4>
                </div>
                <div class='card-body'>";

/**
 * ログ出力関数
 */
function addLog($message, $type = 'info') {
    global $log;
    $log[] = ['message' => $message, 'type' => $type, 'time' => date('H:i:s')];
    echo "<div class='log-{$type} mb-2'>[" . date('H:i:s') . "] " . htmlspecialchars($message) . "</div>";
    flush();
}

/**
 * Step 1: usersテーブル構造確認・修正
 */
addLog("Step 1: usersテーブル構造確認・修正開始", 'info');

try {
    // テーブル構造確認
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    $columnNames = array_column($columns, 'Field');
    
    addLog("usersテーブル構造確認完了", 'success');
    
    // 必要カラムの確認・追加
    $requiredColumns = [
        'role' => "VARCHAR(50) DEFAULT 'driver'",
        'permissions' => "JSON NULL",
        'is_active' => "BOOLEAN DEFAULT TRUE",
        'last_login' => "TIMESTAMP NULL",
        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];
    
    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $columnNames)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
            addLog("カラム追加: {$column}", 'success');
        } else {
            addLog("カラム確認済み: {$column}", 'info');
        }
    }
    
} catch (PDOException $e) {
    addLog("usersテーブル修正エラー: " . $e->getMessage(), 'error');
}

/**
 * Step 2: 権限マスタの定義・作成
 */
addLog("Step 2: 権限マスタの定義・作成", 'info');

$roles = [
    'system_admin' => [
        'name' => 'システム管理者',
        'permissions' => [
            'user_management', 'vehicle_management', 'system_settings',
            'pre_duty_call', 'post_duty_call', 'daily_inspection', 'periodic_inspection',
            'departure', 'arrival', 'ride_records',
            'cash_management', 'annual_report', 'accident_management',
            'export_documents', 'view_all_data'
        ]
    ],
    'admin' => [
        'name' => '管理者',
        'permissions' => [
            'vehicle_management',
            'pre_duty_call', 'post_duty_call', 'daily_inspection', 'periodic_inspection',
            'departure', 'arrival', 'ride_records',
            'cash_management', 'annual_report', 'accident_management',
            'export_documents', 'view_all_data'
        ]
    ],
    'driver' => [
        'name' => '運転者',
        'permissions' => [
            'pre_duty_call', 'post_duty_call', 'daily_inspection',
            'departure', 'arrival', 'ride_records'
        ]
    ],
    'caller' => [
        'name' => '点呼者',
        'permissions' => [
            'pre_duty_call', 'post_duty_call'
        ]
    ]
];

// 権限マスタテーブルの作成
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_roles (
            id INT PRIMARY KEY AUTO_INCREMENT,
            role_key VARCHAR(50) UNIQUE NOT NULL,
            role_name VARCHAR(100) NOT NULL,
            permissions JSON NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    addLog("user_rolesテーブル作成完了", 'success');
    
    // 権限データの挿入・更新
    foreach ($roles as $roleKey => $roleData) {
        $stmt = $pdo->prepare("
            INSERT INTO user_roles (role_key, role_name, permissions) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            role_name = VALUES(role_name),
            permissions = VALUES(permissions),
            updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$roleKey, $roleData['name'], json_encode($roleData['permissions'])]);
        addLog("権限データ更新: {$roleData['name']}", 'success');
    }
    
} catch (PDOException $e) {
    addLog("権限マスタ作成エラー: " . $e->getMessage(), 'error');
}

/**
 * Step 3: 既存ユーザーの権限正規化
 */
addLog("Step 3: 既存ユーザーの権限正規化", 'info');

try {
    // 既存ユーザーのrole正規化
    $users = $pdo->query("SELECT id, name, role FROM users")->fetchAll();
    
    foreach ($users as $user) {
        $normalizedRole = 'driver'; // デフォルト
        
        // 既存のrole値を正規化
        $currentRole = strtolower($user['role'] ?? '');
        
        if (in_array($currentRole, ['システム管理者', 'system_admin', 'admin_system'])) {
            $normalizedRole = 'system_admin';
        } elseif (in_array($currentRole, ['管理者', 'admin', 'manager'])) {
            $normalizedRole = 'admin';
        } elseif (in_array($currentRole, ['点呼者', 'caller'])) {
            $normalizedRole = 'caller';
        }
        
        // 権限情報の更新
        $permissions = $roles[$normalizedRole]['permissions'] ?? [];
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET role = ?, permissions = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$normalizedRole, json_encode($permissions), $user['id']]);
        
        addLog("ユーザー権限更新: {$user['name']} → {$roles[$normalizedRole]['name']}", 'success');
    }
    
} catch (PDOException $e) {
    addLog("ユーザー権限正規化エラー: " . $e->getMessage(), 'error');
}

/**
 * Step 4: 権限チェック関数の統一
 */
addLog("Step 4: 統一権限チェック関数の作成", 'info');

$authFunctionCode = "<?php
/**
 * 統一権限管理システム
 */

class PermissionManager {
    private static \$pdo;
    
    public static function init(\$pdo) {
        self::\$pdo = \$pdo;
    }
    
    /**
     * ユーザー権限チェック
     */
    public static function hasPermission(\$userId, \$permission) {
        try {
            \$stmt = self::\$pdo->prepare(\"
                SELECT permissions FROM users WHERE id = ? AND is_active = TRUE
            \");
            \$stmt->execute([\$userId]);
            \$user = \$stmt->fetch();
            
            if (!\$user) return false;
            
            \$permissions = json_decode(\$user['permissions'] ?? '[]', true);
            return in_array(\$permission, \$permissions);
            
        } catch (Exception \$e) {
            return false;
        }
    }
    
    /**
     * 役割チェック
     */
    public static function hasRole(\$userId, \$role) {
        try {
            \$stmt = self::\$pdo->prepare(\"
                SELECT role FROM users WHERE id = ? AND is_active = TRUE
            \");
            \$stmt->execute([\$userId]);
            \$user = \$stmt->fetch();
            
            return \$user && \$user['role'] === \$role;
            
        } catch (Exception \$e) {
            return false;
        }
    }
    
    /**
     * ログイン確認
     */
    public static function requireLogin() {
        session_start();
        if (!isset(\$_SESSION['user_id'])) {
            header('Location: index.php');
            exit;
        }
        return \$_SESSION['user_id'];
    }
    
    /**
     * 権限要求
     */
    public static function requirePermission(\$permission) {
        \$userId = self::requireLogin();
        if (!self::hasPermission(\$userId, \$permission)) {
            http_response_code(403);
            die('アクセス権限がありません');
        }
        return \$userId;
    }
}
?>";

// 権限管理ファイルの作成
file_put_contents(__DIR__ . '/includes/auth.php', $authFunctionCode);
addLog("統一権限チェック関数作成完了: includes/auth.php", 'success');

/**
 * Step 5: 動作確認
 */
addLog("Step 5: 権限システム動作確認", 'info');

try {
    // ユーザー一覧の表示
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.role, ur.role_name, u.is_active
        FROM users u
        LEFT JOIN user_roles ur ON u.role = ur.role_key
        ORDER BY u.id
    ");
    $users = $stmt->fetchAll();
    
    addLog("権限システム修正完了！現在のユーザー一覧:", 'success');
    
    echo "<div class='mt-4'>
            <h5>現在のユーザー権限一覧</h5>
            <table class='table table-striped table-sm'>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ユーザー名</th>
                        <th>権限コード</th>
                        <th>権限名</th>
                        <th>状態</th>
                    </tr>
                </thead>
                <tbody>";
    
    foreach ($users as $user) {
        $status = $user['is_active'] ? 
            '<span class="badge bg-success">有効</span>' : 
            '<span class="badge bg-secondary">無効</span>';
        
        echo "<tr>
                <td>{$user['id']}</td>
                <td>{$user['name']}</td>
                <td>{$user['role']}</td>
                <td>{$user['role_name']}</td>
                <td>{$status}</td>
              </tr>";
    }
    
    echo "</tbody></table></div>";
    
} catch (PDOException $e) {
    addLog("動作確認エラー: " . $e->getMessage(), 'error');
}

/**
 * 完了メッセージ
 */
addLog("権限管理システムの統一修正が完了しました！", 'success');

echo "              <div class='mt-4 alert alert-success'>
                        <h5><i class='fas fa-check-circle'></i> 修正完了</h5>
                        <p>権限管理システムが統一され、以下が修正されました：</p>
                        <ul>
                            <li>usersテーブル構造の最適化</li>
                            <li>権限マスタテーブルの作成</li>
                            <li>既存ユーザーの権限正規化</li>
                            <li>統一権限チェック関数の作成</li>
                        </ul>
                        
                        <hr>
                        
                        <h6>次のステップ:</h6>
                        <ol>
                            <li><a href='user_management.php' class='btn btn-primary btn-sm'>ユーザー管理画面</a> で権限を変更可能</li>
                            <li><a href='dashboard.php' class='btn btn-success btn-sm'>ダッシュボード</a> で動作確認</li>
                            <li>各機能画面で権限が正しく動作するかテスト</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>";
?>
