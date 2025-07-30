<?php
// 福祉輸送管理システム - usersテーブル構造確認・修正
require_once 'config/database.php';

echo "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>usersテーブル構造確認</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>
</head>
<body>
<div class='container mt-4'>
    <h2><i class='fas fa-database'></i> usersテーブル構造確認・修正</h2>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='alert alert-success'>✅ データベース接続成功</div>";
    
    // 1. usersテーブル構造確認
    echo "<h4><i class='fas fa-table'></i> 現在のusersテーブル構造</h4>";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='table-responsive'>";
    echo "<table class='table table-striped'>";
    echo "<thead class='table-dark'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>Key</th><th>デフォルト</th><th>Extra</th></tr>";
    echo "</thead><tbody>";
    
    $hasActive = false;
    $hasIsActive = false;
    $hasPermissionLevel = false;
    $hasJobFlags = false;
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td><strong>{$column['Field']}</strong></td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>";
        
        // カラム存在チェック
        if ($column['Field'] === 'active') $hasActive = true;
        if ($column['Field'] === 'is_active') $hasIsActive = true;
        if ($column['Field'] === 'permission_level') $hasPermissionLevel = true;
        if (in_array($column['Field'], ['is_driver', 'is_caller', 'is_mechanic', 'is_manager'])) {
            $hasJobFlags = true;
        }
    }
    
    echo "</tbody></table>";
    echo "</div>";
    
    // 2. カラム存在状況の診断
    echo "<h4><i class='fas fa-search'></i> カラム存在状況</h4>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='card'>";
    echo "<div class='card-header'><strong>アクティブフラグ</strong></div>";
    echo "<div class='card-body'>";
    if ($hasActive) {
        echo "<div class='alert alert-success'><i class='fas fa-check'></i> activeカラム: 存在</div>";
    } else {
        echo "<div class='alert alert-warning'><i class='fas fa-times'></i> activeカラム: 未存在</div>";
    }
    if ($hasIsActive) {
        echo "<div class='alert alert-success'><i class='fas fa-check'></i> is_activeカラム: 存在</div>";
    } else {
        echo "<div class='alert alert-warning'><i class='fas fa-times'></i> is_activeカラム: 未存在</div>";
    }
    echo "</div></div></div>";
    
    echo "<div class='col-md-6'>";
    echo "<div class='card'>";
    echo "<div class='card-header'><strong>新権限システム</strong></div>";
    echo "<div class='card-body'>";
    if ($hasPermissionLevel) {
        echo "<div class='alert alert-success'><i class='fas fa-check'></i> permission_level: 存在</div>";
    } else {
        echo "<div class='alert alert-warning'><i class='fas fa-times'></i> permission_level: 未存在</div>";
    }
    if ($hasJobFlags) {
        echo "<div class='alert alert-success'><i class='fas fa-check'></i> 職務フラグ: 一部存在</div>";
    } else {
        echo "<div class='alert alert-warning'><i class='fas fa-times'></i> 職務フラグ: 未存在</div>";
    }
    echo "</div></div></div>";
    echo "</div>";
    
    // 3. 必要なカラムの追加
    echo "<h4><i class='fas fa-plus-circle'></i> 必要カラムの追加</h4>";
    
    // アクティブフラグの統一
    if (!$hasActive && !$hasIsActive) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active BOOLEAN DEFAULT TRUE COMMENT 'ユーザーアクティブ状態'");
        echo "<div class='alert alert-success'>✅ is_activeカラムを追加しました</div>";
        $hasIsActive = true;
    } elseif ($hasActive && !$hasIsActive) {
        // activeがある場合は、is_activeに統一
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active BOOLEAN DEFAULT TRUE COMMENT 'ユーザーアクティブ状態'");
        $pdo->exec("UPDATE users SET is_active = active");
        echo "<div class='alert alert-success'>✅ is_activeカラムを追加し、activeから値をコピーしました</div>";
        $hasIsActive = true;
    }
    
    // permission_levelの追加
    if (!$hasPermissionLevel) {
        $pdo->exec("ALTER TABLE users ADD COLUMN permission_level ENUM('User', 'Admin') DEFAULT 'User' COMMENT '権限レベル'");
        echo "<div class='alert alert-success'>✅ permission_levelカラムを追加しました</div>";
    }
    
    // 職務フラグの追加
    $jobFlags = [
        'is_driver' => "BOOLEAN DEFAULT TRUE COMMENT '運転者として選択可能'",
        'is_caller' => "BOOLEAN DEFAULT FALSE COMMENT '点呼者として選択可能'",
        'is_mechanic' => "BOOLEAN DEFAULT FALSE COMMENT '点検者として選択可能'",
        'is_manager' => "BOOLEAN DEFAULT FALSE COMMENT '管理者として選択可能'"
    ];
    
    foreach ($jobFlags as $flagName => $definition) {
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
        }
    }
    
    // 4. 既存ユーザーの権限設定
    echo "<h4><i class='fas fa-users-cog'></i> 既存ユーザー権限設定</h4>";
    
    // アクティブフラグの条件を正しく設定
    $activeCondition = $hasIsActive ? "is_active = TRUE OR is_active IS NULL" : 
                      ($hasActive ? "active = TRUE OR active IS NULL" : "1=1");
    
    $stmt = $pdo->query("SELECT id, name, login_id FROM users WHERE {$activeCondition}");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        // デフォルト設定
        $permissionLevel = 'User';
        $isDriver = true;   // 全員デフォルトで運転者
        $isCaller = false;
        $isMechanic = false;
        $isManager = false;
        
        // 管理者判定
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
        
        echo "<div class='alert alert-success'>";
        echo "✅ <strong>{$user['name']}</strong> - Level: <span class='badge bg-" . 
             ($permissionLevel === 'Admin' ? 'danger' : 'primary') . "'>{$permissionLevel}</span>";
        echo "</div>";
    }
    
    // 5. 修正版daily_inspection.php用コード
    echo "<h4><i class='fas fa-code'></i> 修正版daily_inspection.phpコード</h4>";
    echo "<div class='card'>";
    echo "<div class='card-body'>";
    echo "<h6>以下のコードをdaily_inspection.phpの権限チェック部分に適用:</h6>";
    
    // アクティブ条件を動的に設定
    $finalActiveCondition = $hasIsActive ? "(is_active = TRUE OR is_active IS NULL)" : 
                           ($hasActive ? "(active = TRUE OR active IS NULL)" : "1=1");
    
    echo "<pre class='bg-light p-3'><code>";
    $fixedCode = <<<PHP
<?php
// === 修正版: 新権限管理システム対応 ===
session_start();
require_once 'config/database.php';

if (!isset(\$_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

try {
    \$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ✅ 正しいアクティブ条件で権限チェック
    \$stmt = \$pdo->prepare("
        SELECT permission_level, is_driver, is_caller, is_mechanic, is_manager, name 
        FROM users 
        WHERE id = ? AND {$finalActiveCondition}
    ");
    \$stmt->execute([\$_SESSION['user_id']]);
    \$current_user = \$stmt->fetch(PDO::FETCH_OBJ);
    
    if (!\$current_user) {
        header('Location: index.php?error=invalid_session');
        exit;
    }
    
    // 日常点検権限チェック（運転者または整備者）
    if (!\$current_user->is_driver && !\$current_user->is_mechanic) {
        header('Location: dashboard.php?error=access_denied&message=日常点検の権限がありません');
        exit;
    }

} catch (PDOException \$e) {
    error_log("Database error in daily_inspection.php: " . \$e->getMessage());
    header('Location: dashboard.php?error=database_error');
    exit;
}

// 以下、既存の日常点検処理...
?>
PHP;
    
    echo htmlspecialchars($fixedCode);
    echo "</code></pre>";
    echo "</div></div>";
    
    // 6. 最終確認テーブル
    echo "<h4><i class='fas fa-check-circle'></i> 修正後テーブル構造確認</h4>";
    $stmt = $pdo->query("DESCRIBE users");
    $finalColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='table-responsive'>";
    echo "<table class='table table-sm table-striped'>";
    echo "<thead class='table-success'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>デフォルト</th><th>状態</th></tr>";
    echo "</thead><tbody>";
    
    $requiredColumns = ['permission_level', 'is_driver', 'is_caller', 'is_mechanic', 'is_manager'];
    $activeColumns = $hasIsActive ? ['is_active'] : ($hasActive ? ['active'] : []);
    
    foreach ($finalColumns as $column) {
        $isRequired = in_array($column['Field'], $requiredColumns) || in_array($column['Field'], $activeColumns);
        $badgeClass = $isRequired ? 'bg-success' : 'bg-secondary';
        $status = $isRequired ? '重要' : '通常';
        
        echo "<tr>";
        echo "<td><strong>{$column['Field']}</strong></td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td><span class='badge {$badgeClass}'>{$status}</span></td>";
        echo "</tr>";
    }
    
    echo "</tbody></table>";
    echo "</div>";
    
    echo "<div class='alert alert-success mt-4'>";
    echo "<h5><i class='fas fa-thumbs-up'></i> 修正完了！</h5>";
    echo "<p>usersテーブルの構造修正が完了しました。</p>";
    echo "<p><strong>アクティブ条件:</strong> <code>{$finalActiveCondition}</code></p>";
    echo "<p><strong>次のステップ:</strong> daily_inspection.phpに上記のコードを適用してテストしてください。</p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h5><i class='fas fa-exclamation-circle'></i> データベースエラー</h5>";
    echo "<p>エラー詳細: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class='mt-4 d-flex gap-2'>
        <a href='dashboard.php' class='btn btn-primary'><i class='fas fa-home'></i> ダッシュボード</a>
        <a href='daily_inspection.php' class='btn btn-success'><i class='fas fa-clipboard-check'></i> 日常点検テスト</a>
    </div>
</div>
</body>
</html>";
?>
