<?php
/**
 * 管理者権限緊急修正スクリプト
 * 現在ログインしているユーザーを管理者権限に設定
 */

session_start();
require_once 'config/database.php';

echo "<h2>🔧 管理者権限緊急修正</h2>";
echo "<div style='font-family: monospace; background: #f5f5f5; padding: 20px;'>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 現在のセッション情報確認
    echo "<h3>Step 1: 現在のセッション情報</h3>";
    if (isset($_SESSION['user_id'])) {
        echo "ログイン中のユーザーID: {$_SESSION['user_id']}<br>";
        echo "ログイン中のユーザー名: " . ($_SESSION['user_name'] ?? '未設定') . "<br>";
        echo "現在の権限: " . ($_SESSION['role'] ?? '未設定') . "<br>";
        
        // データベースから最新情報取得
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current_user) {
            echo "<br><strong>データベース上の情報:</strong><br>";
            echo "名前: {$current_user['name']}<br>";
            echo "ログインID: {$current_user['login_id']}<br>";
            echo "role: {$current_user['role']}<br>";
            echo "is_driver: " . (isset($current_user['is_driver']) ? ($current_user['is_driver'] ? 'TRUE' : 'FALSE') : '未設定') . "<br>";
            echo "is_caller: " . (isset($current_user['is_caller']) ? ($current_user['is_caller'] ? 'TRUE' : 'FALSE') : '未設定') . "<br>";
            echo "is_inspector: " . (isset($current_user['is_inspector']) ? ($current_user['is_inspector'] ? 'TRUE' : 'FALSE') : '未設定') . "<br>";
        }
        
    } else {
        echo "❌ ログインしていません<br>";
        echo "<a href='index.php'>ログイン画面へ</a>";
        exit;
    }
    
    echo "<h3>Step 2: 権限修正処理</h3>";
    
    if (isset($_POST['fix_permission'])) {
        echo "権限修正を実行中...<br>";
        
        // 現在のユーザーを管理者に設定
        $stmt = $pdo->prepare("
            UPDATE users SET 
                role = 'admin',
                is_driver = TRUE,
                is_caller = TRUE,
                is_inspector = TRUE,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$_SESSION['user_id']]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo "✅ 権限修正成功！<br>";
            
            // セッション情報も更新
            $_SESSION['role'] = 'admin';
            $_SESSION['is_driver'] = true;
            $_SESSION['is_caller'] = true;
            $_SESSION['is_inspector'] = true;
            
            echo "✅ セッション情報も更新完了<br>";
            
            // 修正後の確認
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "<br><strong>修正後の情報:</strong><br>";
            echo "role: {$updated_user['role']}<br>";
            echo "is_driver: " . ($updated_user['is_driver'] ? 'TRUE' : 'FALSE') . "<br>";
            echo "is_caller: " . ($updated_user['is_caller'] ? 'TRUE' : 'FALSE') . "<br>";
            echo "is_inspector: " . ($updated_user['is_inspector'] ? 'TRUE' : 'FALSE') . "<br>";
            
            echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 20px 0;'>";
            echo "<h4>🎉 修正完了！</h4>";
            echo "<p>管理者権限の設定が完了しました。</p>";
            echo "<a href='user_management.php' class='btn btn-primary' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>ユーザー管理画面へ</a>";
            echo "</div>";
            
        } else {
            echo "❌ 権限修正に失敗しました（影響行数: {$stmt->rowCount()}）<br>";
        }
        
    } else {
        // 修正確認フォーム
        echo "<div style='background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; margin: 20px 0;'>";
        echo "<h4>⚠️ 権限修正の確認</h4>";
        echo "<p>現在ログイン中のユーザー「{$current_user['name']}」を管理者権限に設定しますか？</p>";
        echo "<form method='POST'>";
        echo "<button type='submit' name='fix_permission' value='1' style='padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;'>権限を修正する</button>";
        echo " ";
        echo "<a href='dashboard.php' style='padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px;'>キャンセル</a>";
        echo "</form>";
        echo "</div>";
    }
    
    echo "<h3>Step 3: 全ユーザーの権限状況確認</h3>";
    
    $stmt = $pdo->query("SELECT id, name, login_id, role FROM users ORDER BY id");
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>名前</th><th>ログインID</th><th>権限</th><th>操作</th></tr>";
    
    foreach ($all_users as $user) {
        $current_user_mark = ($user['id'] == $_SESSION['user_id']) ? ' 👤' : '';
        $role_color = ($user['role'] === 'admin') ? 'color: red; font-weight: bold;' : '';
        
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['name']}{$current_user_mark}</td>";
        echo "<td>{$user['login_id']}</td>";
        echo "<td style='{$role_color}'>{$user['role']}</td>";
        echo "<td>";
        if ($user['role'] !== 'admin') {
            echo "<form method='POST' style='display: inline;'>";
            echo "<input type='hidden' name='target_user_id' value='{$user['id']}'>";
            echo "<button type='submit' name='make_admin' value='1' style='padding: 5px 10px; background: #28a745; color: white; border: none; border-radius: 3px; font-size: 12px;'>管理者にする</button>";
            echo "</form>";
        } else {
            echo "<span style='color: #28a745;'>管理者</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 他のユーザーを管理者にする処理
    if (isset($_POST['make_admin']) && isset($_POST['target_user_id'])) {
        $target_user_id = $_POST['target_user_id'];
        
        $stmt = $pdo->prepare("
            UPDATE users SET 
                role = 'admin',
                is_driver = TRUE,
                is_caller = TRUE,
                is_inspector = TRUE,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$target_user_id]);
        
        if ($result) {
            echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0;'>";
            echo "✅ ユーザーID {$target_user_id} を管理者に設定しました";
            echo "</div>";
            echo "<script>window.location.reload();</script>";
        }
    }
    
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='color: red; background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px;'>";
    echo "<strong>❌ データベースエラー:</strong><br>";
    echo htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .btn { display: inline-block; padding: 10px 15px; margin: 5px; text-decoration: none; border-radius: 5px; }
    .btn-primary { background: #007bff; color: white; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    table { margin: 20px 0; }
    th, td { padding: 8px 12px; border: 1px solid #ddd; }
    th { background: #f8f9fa; }
</style>
