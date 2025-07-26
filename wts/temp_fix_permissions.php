<?php
// 一時的権限修正スクリプト
session_start();
require_once 'config/database.php';

echo "<h1>一時的権限修正スクリプト</h1>";

if (isset($_SESSION['user_id'])) {
    try {
        // 1. 現在のユーザー情報確認
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        echo "<h2>修正前の情報</h2>";
        echo "<p>ユーザー名: " . htmlspecialchars($user['name']) . "</p>";
        echo "<p>現在の権限: <strong>" . htmlspecialchars($user['role']) . "</strong></p>";
        
        // 2. システム管理者権限に更新
        $stmt = $pdo->prepare("UPDATE users SET role = 'システム管理者' WHERE id = ?");
        $result = $stmt->execute([$_SESSION['user_id']]);
        
        if ($result) {
            // 3. セッション情報も更新
            $_SESSION['role'] = 'システム管理者';
            
            echo "<h2>✅ 修正完了</h2>";
            echo "<p style='color: green;'>権限を「システム管理者」に更新しました</p>";
            echo "<p style='color: green;'>セッション情報も更新しました</p>";
            
            // 4. 修正後の確認
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $updated_user = $stmt->fetch();
            
            echo "<h2>修正後の情報</h2>";
            echo "<p>ユーザー名: " . htmlspecialchars($updated_user['name']) . "</p>";
            echo "<p>新しい権限: <strong style='color: green;'>" . htmlspecialchars($updated_user['role']) . "</strong></p>";
            
            echo "<h2>テストアクセス</h2>";
            echo "<p>以下のリンクをテストしてください：</p>";
            echo "<ul>";
            echo "<li><a href='accident_management.php' target='_blank'>事故管理機能</a></li>";
            echo "<li><a href='annual_report.php' target='_blank'>陸運局提出機能</a></li>";
            echo "<li><a href='emergency_audit_kit.php' target='_blank'>緊急監査対応キット</a></li>";
            echo "</ul>";
            
        } else {
            echo "<p style='color: red;'>❌ 権限更新に失敗しました</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ ログインしていません</p>";
    echo "<a href='index.php'>ログイン画面へ</a>";
}

echo "<div style='margin: 20px 0;'>";
echo "<a href='dashboard.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px;'>ダッシュボードへ</a>";
echo "<a href='check_user_permissions.php' style='background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>権限確認</a>";
echo "</div>";
?>
