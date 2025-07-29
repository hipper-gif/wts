<?php
// permission_level_migration.php - roleをpermission_levelに完全移行

require_once 'config/database.php';

echo "<h2>福祉輸送管理システム - permission_level移行スクリプト</h2>";

try {
    // 1. permission_levelカラムが存在するかチェック
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'permission_level'");
    $permission_level_exists = $stmt->rowCount() > 0;
    
    if (!$permission_level_exists) {
        echo "<p>✅ Step 1: permission_levelカラムを追加中...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN permission_level ENUM('User', 'Admin') DEFAULT 'User' AFTER name");
        echo "<p>✅ permission_levelカラムを追加しました</p>";
    } else {
        echo "<p>✅ Step 1: permission_levelカラムは既に存在します</p>";
    }
    
    // 2. 既存のroleデータをpermission_levelに移行
    echo "<p>✅ Step 2: roleデータをpermission_levelに移行中...</p>";
    
    // roleカラムが存在する場合の移行処理
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $role_exists = $stmt->rowCount() > 0;
    
    if ($role_exists) {
        // roleの値に応じてpermission_levelを設定
        $pdo->exec("
            UPDATE users 
            SET permission_level = CASE 
                WHEN role IN ('システム管理者', 'Admin', '管理者') THEN 'Admin'
                ELSE 'User'
            END
        ");
        echo "<p>✅ roleからpermission_levelへのデータ移行完了</p>";
        
        // 移行結果を表示
        $stmt = $pdo->query("SELECT name, role, permission_level FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>移行結果:</h3>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ユーザー名</th><th>旧role</th><th>新permission_level</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($user['name']) . "</td>";
            echo "<td>" . htmlspecialchars($user['role']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($user['permission_level']) . "</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        // roleカラムが存在しない場合は、デフォルトユーザーを作成
        echo "<p>⚠️ roleカラムが存在しません。デフォルトユーザーを確認します...</p>";
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE permission_level = 'Admin'");
        $admin_count = $stmt->fetchColumn();
        
        if ($admin_count == 0) {
            echo "<p>⚠️ Adminユーザーが存在しません。既存ユーザーの一部をAdminに設定します...</p>";
            $pdo->exec("UPDATE users SET permission_level = 'Admin' WHERE id = 1 LIMIT 1");
            echo "<p>✅ ID=1のユーザーをAdminに設定しました</p>";
        }
    }
    
    // 3. 職務フラグをチェック・追加
    echo "<p>✅ Step 3: 職務フラグをチェック中...</p>";
    
    $job_flags = ['is_driver', 'is_caller', 'is_mechanic', 'is_manager'];
    
    foreach ($job_flags as $flag) {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE '$flag'");
        $flag_exists = $stmt->rowCount() > 0;
        
        if (!$flag_exists) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $flag BOOLEAN DEFAULT FALSE");
            echo "<p>✅ {$flag}カラムを追加しました</p>";
        }
    }
    
    // 4. 職務フラグのデフォルト設定
    echo "<p>✅ Step 4: 職務フラグのデフォルト設定...</p>";
    
    // 全ユーザーを運転者として設定
    $pdo->exec("UPDATE users SET is_driver = TRUE WHERE is_driver IS NULL OR is_driver = FALSE");
    
    // Adminユーザーは点呼者・管理者も可能
    $pdo->exec("UPDATE users SET is_caller = TRUE, is_manager = TRUE WHERE permission_level = 'Admin'");
    
    echo "<p>✅ 職務フラグのデフォルト設定完了</p>";
    
    // 5. 最終結果表示
    echo "<h3>最終設定結果:</h3>";
    $stmt = $pdo->query("
        SELECT name, permission_level, is_driver, is_caller, is_mechanic, is_manager 
        FROM users 
        ORDER BY permission_level DESC, name
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr>";
    echo "<th>ユーザー名</th>";
    echo "<th>権限レベル</th>";
    echo "<th>運転者</th>";
    echo "<th>点呼者</th>";
    echo "<th>整備者</th>";
    echo "<th>管理者</th>";
    echo "</tr>";
    
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($user['permission_level']) . "</strong></td>";
        echo "<td>" . ($user['is_driver'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($user['is_caller'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($user['is_mechanic'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($user['is_manager'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 6. roleカラムの削除（オプション）
    if ($role_exists) {
        echo "<h3>⚠️ roleカラムの削除</h3>";
        echo "<p>移行が完了しました。roleカラムを削除する場合は、以下のSQLを実行してください：</p>";
        echo "<code>ALTER TABLE users DROP COLUMN role;</code>";
        echo "<p><small>※安全のため、手動実行をお勧めします</small></p>";
    }
    
    echo "<h2>🎉 permission_level移行完了！</h2>";
    echo "<p>✅ すべてのユーザーがpermission_level（Admin/User）で管理されるようになりました</p>";
    echo "<p>✅ 職務フラグ（is_driver, is_caller, is_mechanic, is_manager）も設定されました</p>";
    echo "<p><a href='dashboard.php'>ダッシュボードに戻る</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>データベース接続またはテーブル構造に問題があります</p>";
}
?>
