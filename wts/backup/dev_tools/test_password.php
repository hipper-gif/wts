<?php
require_once 'config/database.php';

// 管理者パスワードを正しく設定
$new_password = 'admin123';
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

try {
    $pdo = getDBConnection();
    
    // 現在のユーザー情報を確認
    $stmt = $pdo->query("SELECT id, login_id, name, role FROM users");
    $users = $stmt->fetchAll();
    
    echo "<h3>現在のユーザー一覧:</h3>";
    foreach ($users as $user) {
        echo "<p>ID: {$user['id']}, ログインID: {$user['login_id']}, 名前: {$user['name']}, 権限: {$user['role']}</p>";
    }
    
    // 管理者パスワードを更新
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE login_id = 'admin'");
    $result = $stmt->execute([$hashed_password]);
    
    if ($result) {
        echo "<p style='color: green;'>✅ 管理者パスワードを正常に更新しました</p>";
        echo "<p><strong>ログイン情報:</strong></p>";
        echo "<p>ログインID: admin</p>";
        echo "<p>パスワード: admin123</p>";
    } else {
        echo "<p style='color: red;'>❌ パスワード更新に失敗</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>エラー: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='index.php'>ログイン画面へ</a></p>";
?>