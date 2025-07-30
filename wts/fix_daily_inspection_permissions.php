<?php
// 福祉輸送管理システム - 日常点検権限エラー修正スクリプト
// 新権限管理システム（permission_level + 職務フラグ）対応

require_once 'config/database.php';

echo "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>日常点検権限エラー修正</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>
</head>
<body>
<div class='container mt-4'>
    <h2><i class='fas fa-tools'></i> 日常点検権限エラー修正</h2>
    <div class='alert alert-info'>
        <strong>実行内容:</strong> daily_inspection.phpのroleカラムエラーを新権限管理システム（permission_level + 職務フラグ）に対応させます。
    </div>";

try {
    // データベース接続
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='alert alert-success'>✅ データベース接続成功</div>";
    
    // 1. 現在のusersテーブル構造確認
    echo "<h4><i class='fas fa-database'></i> usersテーブル構造確認</h4>";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='card mb-3'>";
    echo "<div class='card-body'>";
    echo "<div class='table-responsive'>";
    echo "<table class='table table-sm'>";
    echo "<thead><tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>デフォルト</th></tr></thead><tbody>";
    
    $hasPermissionLevel = false;
    $hasJobFlags = false;
    $jobFlags = ['is_driver', 'is_caller', 'is_mechanic', 'is_manager'];
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td><strong>{$column['Field']}</strong></td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
        
        if ($column['Field'] === 'permission_level') {
            $hasPermissionLevel = true;
        }
        if (in_array($column['Field'], $jobFlags)) {
            $hasJobFlags = true;
        }
    }
    
    echo "</tbody></table>";
    echo "</div></div></div>";
    
    // 2. 新権限システムカラムの確認・追加
    echo "<h4><i class='fas fa-plus-circle'></i> 新権限システムカラム確認・追加</h4>";
    
    // permission_levelカラムの追加
    if (!$hasPermissionLevel) {
        $pdo->exec("ALTER TABLE users ADD COLUMN permission_level ENUM('User', 'Admin') DEFAULT 'User' AFTER password_hash");
        echo "<div class='alert alert-success'>✅ permission_levelカラムを追加しました</div>";
    } else {
        echo "<div class='alert alert-info'>ℹ️ permission_levelカラムは既に存在します</div>";
    }
    
    // 職務フラグカラムの追加
    $jobFlagDefinitions = [
        'is_driver' => "BOOLEAN DEFAULT TRUE COMMENT '運転者として選択可能'",
        'is_caller' => "BOOLEAN DEFAULT FALSE COMMENT '点呼者として選択可能'",
        'is_mechanic' => "BOOLEAN DEFAULT FALSE COMMENT '点検者として選択可能'",
        'is_manager' => "BOOLEAN DEFAULT FALSE COMMENT '管理者として選択可能'"
    ];
    
    foreach ($jobFlagDefinitions as $flagName => $definition) {
        $exists = false;
        foreach ($columns as $column) {
            if ($column['Field'] === $flagName) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$flagName} {$definition}");
            echo "<div class='alert alert-success'>✅ {$flagName}カラムを追加しました</div>";
        } else {
            echo "<div class='alert alert-info'>ℹ️ {$flagName}カラムは既に存在します</div>";
        }
    }
    
    // 3. 既存ユーザーの権限設定
    echo "<h4><i class='fas fa-users'></i> 既存ユーザー権限設定</h4>";
    
    $stmt = $pdo->query("SELECT id, name, login_id FROM users WHERE active = TRUE OR active IS NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        // デフォルト設定
        $permissionLevel = 'User';
        $isDriver = true;   // 全員デフォルトで運転者
        $isCaller = false;
        $isMechanic = false;
        $isManager = false;
        
        // 名前・ログインIDベースでの権限設定
        if (strpos($user['name'], '杉原') !== false || 
            in_array($user['login_id'], ['admin', 'sugihara_hoshi', 'sugihara_mitsuru'])) {
            $permissionLevel = 'Admin';
            $isCaller = true;
            $isManager = true;
        }
        
        // 点呼者設定（杉原さん2名）
        if (strpos($user['name'], '杉原') !== false) {
            $isCaller = true;
        }
        
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET permission_level = ?, 
                is_driver = ?, 
                is_caller = ?, 
                is_mechanic = ?, 
                is_manager = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$permissionLevel, $isDriver, $isCaller, $isMechanic, $isManager, $user['id']]);
        
        $roleText = $permissionLevel === 'Admin' ? '管理者' : '一般ユーザー';
        $jobText = [];
        if ($isDriver) $jobText[] = '運転者';
        if ($isCaller) $jobText[] = '点呼者';
        if ($isMechanic) $jobText[] = '整備者';
        if ($isManager) $jobText[] = '管理者';
        
        echo "<div class='alert alert-success'>";
        echo "✅ <strong>{$user['name']}</strong>の権限を設定しました<br>";
        echo "権限レベル: <span class='badge bg-" . ($permissionLevel === 'Admin' ? 'danger' : 'primary') . "'>{$roleText}</span> ";
        echo "職務: " . implode(', ', $jobText);
        echo "</div>";
    }
    
    // 4. daily_inspection.php修正コード生成
    echo "<h4><i class='fas fa-code'></i> daily_inspection.php修正コード</h4>";
    echo "<div class='card'>";
    echo "<div class='card-body'>";
    echo "<h6>以下のコードをdaily_inspection.phpの権限チェック部分（52行目付近）に適用してください:</h6>";
    echo "<pre class='bg-light p-3' style='font-size: 0.9em;'><code>";
    
    $fixCode = <<<'PHP'
<?php
// === 新権限管理システム対応版 ===
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ✅ 新権限システム: permission_level + 職務フラグ使用
    $stmt = $pdo->prepare("
        SELECT permission_level, is_driver, is_caller, is_mechanic, is_manager, name 
        FROM users 
        WHERE id = ? AND (active = TRUE OR active IS NULL)
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$current_user) {
        header('Location: index.php?error=invalid_session');
        exit;
    }
    
    // 点検可能ユーザーの判定（運転者または整備者）
    if (!$current_user->is_driver && !$current_user->is_mechanic) {
        header('Location: dashboard.php?error=access_denied');
        exit;
    }

} catch (PDOException $e) {
    error_log("Database error in daily_inspection.php: " . $e->getMessage());
    header('Location: dashboard.php?error=database_error');
    exit;
}

// 以下、既存の日常点検機能...
?>
PHP;
    
    echo htmlspecialchars($fixCode);
    echo "</code></pre>";
    echo "</div></div>";
    
    // 5. 権限チェック関数の提供
    echo "<h4><i class='fas fa-function'></i> 推奨権限チェック関数</h4>";
    echo "<div class='card'>";
    echo "<div class='card-body'>";
    echo "<h6>includes/auth_functions.php として保存し、各ファイルで共通利用してください:</h6>";
    echo "<pre class='bg-light p-3' style='font-size: 0.9em;'><code>";
    
    $authFunctions = <<<'PHP'
<?php
// 福祉輸送管理システム - 権限チェック関数集

/**
 * 管理者権限チェック
 */
function requireAdmin($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ? AND (active = TRUE OR active IS NULL)");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$user || $user->permission_level !== 'Admin') {
        header('Location: dashboard.php?error=admin_required');
        exit;
    }
}

/**
 * ユーザー権限レベル取得
 */
function getUserPermissionLevel($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    return $user ? $user->permission_level : 'User';
}

/**
 * 職務フラグによるユーザーリスト取得
 */
function getUsersByJobFunction($pdo, $job_function) {
    if (is_array($job_function)) {
        $conditions = [];
        foreach ($job_function as $job) {
            $conditions[] = "is_{$job} = TRUE";
        }
        $where = implode(' OR ', $conditions);
    } else {
        $where = "is_{$job_function} = TRUE";
    }
    
    $stmt = $pdo->prepare("
        SELECT id, name, permission_level 
        FROM users 
        WHERE (active = TRUE OR active IS NULL) AND ({$where})
        ORDER BY name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

/**
 * 現在ユーザーの詳細情報取得
 */
function getCurrentUser($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT id, name, login_id, permission_level, is_driver, is_caller, is_mechanic, is_manager 
        FROM users 
        WHERE id = ? AND (active = TRUE OR active IS NULL)
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_OBJ);
}

/**
 * 特定職務権限チェック
 */
function hasJobPermission($pdo, $user_id, $job_function) {
    $user = getCurrentUser($pdo, $user_id);
    if (!$user) return false;
    
    switch ($job_function) {
        case 'driver': return $user->is_driver;
        case 'caller': return $user->is_caller;
        case 'mechanic': return $user->is_mechanic;
        case 'manager': return $user->is_manager;
        default: return false;
    }
}
?>
PHP;
    
    echo htmlspecialchars($authFunctions);
    echo "</code></pre>";
    echo "</div></div>";
    
    // 6. 修正後のユーザー一覧表示
    echo "<h4><i class='fas fa-list'></i> 修正後ユーザー一覧</h4>";
    $stmt = $pdo->query("
        SELECT id, name, login_id, permission_level, is_driver, is_caller, is_mechanic, is_manager 
        FROM users 
        WHERE active = TRUE OR active IS NULL 
        ORDER BY permission_level DESC, name
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='table-responsive'>";
    echo "<table class='table table-striped table-hover'>";
    echo "<thead class='table-dark'>";
    echo "<tr>";
    echo "<th>ID</th><th>名前</th><th>ログインID</th><th>権限レベル</th>";
    echo "<th><i class='fas fa-car'></i> 運転者</th>";
    echo "<th><i class='fas fa-clipboard-check'></i> 点呼者</th>";
    echo "<th><i class='fas fa-tools'></i> 整備者</th>";
    echo "<th><i class='fas fa-user-tie'></i> 管理者</th>";
    echo "</tr>";
    echo "</thead><tbody>";
    
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td><strong>{$user['name']}</strong></td>";
        echo "<td><code>{$user['login_id']}</code></td>";
        echo "<td><span class='badge bg-" . ($user['permission_level'] === 'Admin' ? 'danger' : 'primary') . "'>{$user['permission_level']}</span></td>";
        echo "<td><i class='fas fa-" . ($user['is_driver'] ? 'check text-success' : 'times text-muted') . "'></i></td>";
        echo "<td><i class='fas fa-" . ($user['is_caller'] ? 'check text-success' : 'times text-muted') . "'></i></td>";
        echo "<td><i class='fas fa-" . ($user['is_mechanic'] ? 'check text-success' : 'times text-muted') . "'></i></td>";
        echo "<td><i class='fas fa-" . ($user['is_manager'] ? 'check text-success' : 'times text-muted') . "'></i></td>";
        echo "</tr>";
    }
    
    echo "</tbody></table>";
    echo "</div>";
    
    // 7. 修正手順サマリー
    echo "<div class='alert alert-warning mt-4'>";
    echo "<h5><i class='fas fa-exclamation-triangle'></i> 次に実行すべき手順</h5>";
    echo "<ol>";
    echo "<li><strong>daily_inspection.php修正</strong>: 上記の修正コードを52行目付近に適用</li>";
    echo "<li><strong>権限関数導入</strong>: includes/auth_functions.phpを作成し、各ファイルで利用</li>";
    echo "<li><strong>他ファイル修正</strong>: 同様のroleエラーが発生する他のファイルも修正</li>";
    echo "<li><strong>動作テスト</strong>: 日常点検機能の動作確認</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='alert alert-success'>";
    echo "<h5><i class='fas fa-check-circle'></i> 修正完了</h5>";
    echo "<p>新権限管理システム（permission_level + 職務フラグ）への移行が完了しました。</p>";
    echo "<p><strong>重要:</strong> 今後はroleカラムを使用せず、permission_levelと職務フラグを使用してください。</p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h5><i class='fas fa-exclamation-circle'></i> データベースエラー</h5>";
    echo "<p>エラー詳細: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>データベース接続設定を確認してください。</p>";
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h5><i class='fas fa-exclamation-circle'></i> 一般エラー</h5>";
    echo "<p>エラー詳細: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class='mt-4 d-flex gap-2'>
        <a href='dashboard.php' class='btn btn-primary'><i class='fas fa-home'></i> ダッシュボード</a>
        <a href='daily_inspection.php' class='btn btn-success'><i class='fas fa-clipboard-check'></i> 日常点検テスト</a>
        <a href='user_management.php' class='btn btn-info'><i class='fas fa-users'></i> ユーザー管理</a>
    </div>
</div>

<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>
</body>
</html>";
?>
