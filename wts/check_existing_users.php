<?php
// 既存ユーザー確認・権限修正スクリプト
// URL: https://tw1nkle.com/Smiley/taxi/wts/check_existing_users.php

session_start();
require_once 'config/database.php';

echo "<h2>🔍 既存ユーザー確認・権限修正</h2>";
echo "<div style='font-family: monospace; background: #f5f5f5; padding: 20px;'>";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4", 
                   "twinklemark_taxi", "Smiley2525");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ データベース接続成功<br><br>";
    
    // 権限変更処理
    if (isset($_GET['action']) && isset($_GET['id'])) {
        $user_id = $_GET['id'];
        $action = $_GET['action'];
        
        if ($action === 'make_driver') {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute(['運転者', $user_id]);
            echo "✅ ユーザーID {$user_id} を「運転者」に変更しました<br><br>";
        } elseif ($action === 'make_admin') {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute(['システム管理者', $user_id]);
            echo "✅ ユーザーID {$user_id} を「システム管理者」に変更しました<br><br>";
        }
    }
    
    // 全ユーザー取得
    echo "<strong>📋 現在登録されている全ユーザー:</strong><br>";
    $stmt = $pdo->query("SELECT id, name, login_id, role FROM users ORDER BY id");
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_users)) {
        echo "❌ ユーザーテーブルが空です！システム管理者に連絡してください。<br>";
        exit();
    }
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
    echo "<tr style='background: #e9ecef;'><th>ID</th><th>名前</th><th>ログインID</th><th>現在の権限</th><th>アクション</th></tr>";
    
    foreach ($all_users as $user) {
        $bg_color = '';
        if ($user['role'] === '運転者') $bg_color = 'background: #d4edda;';
        elseif ($user['role'] === 'システム管理者') $bg_color = 'background: #cce7ff;';
        
        echo "<tr style='{$bg_color}'>";
        echo "<td>{$user['id']}</td>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td>" . htmlspecialchars($user['login_id']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($user['role']) . "</strong></td>";
        echo "<td>";
        
        if ($user['role'] !== '運転者') {
            echo "<a href='?action=make_driver&id={$user['id']}' style='background: #28a745; color: white; padding: 5px 10px; text-decoration: none; margin: 2px; border-radius: 3px;'>運転者にする</a> ";
        }
        if ($user['role'] !== 'システム管理者') {
            echo "<a href='?action=make_admin&id={$user['id']}' style='background: #007bff; color: white; padding: 5px 10px; text-decoration: none; margin: 2px; border-radius: 3px;'>管理者にする</a>";
        }
        
        echo "</td></tr>";
    }
    echo "</table><br>";
    
    // 乗車記録で表示される運転者
    echo "<strong>🚗 乗車記録で選択可能な運転者（現在）:</strong><br>";
    $stmt = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('運転者', 'システム管理者') ORDER BY name");
    $available_drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($available_drivers)) {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "❌ <strong>問題発見！</strong> 乗車記録で選択可能な運転者が0名です！<br>";
        echo "→ 上記テーブルで、最低1名を「運転者」または「システム管理者」に変更してください。";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "✅ <strong>以下のユーザーが乗車記録で選択可能です:</strong><br>";
        foreach ($available_drivers as $driver) {
            echo "- " . htmlspecialchars($driver['name']) . " ({$driver['role']})<br>";
        }
        echo "</div>";
    }
    
    // ride_records.phpのSQLクエリをテスト
    echo "<strong>🧪 乗車記録クエリのテスト:</strong><br>";
    $test_sql = "SELECT id, name FROM users WHERE role IN ('運転者', 'システム管理者') ORDER BY name";
    echo "実行SQL: <code>{$test_sql}</code><br>";
    
    $stmt = $pdo->query($test_sql);
    $test_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "結果: " . count($test_results) . "件<br>";
    if (!empty($test_results)) {
        echo "<ul>";
        foreach ($test_results as $result) {
            echo "<li>ID: {$result['id']}, 名前: " . htmlspecialchars($result['name']) . "</li>";
        }
        echo "</ul>";
    }
    
    echo "<hr>";
    echo "<strong>🎯 推奨アクション:</strong><br>";
    
    if (empty($available_drivers)) {
        echo "<div style='background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px;'>";
        echo "1. 上記テーブルで実際の運転者を「運転者」権限に変更<br>";
        echo "2. システム管理者も運転する場合は「システム管理者」権限を維持<br>";
        echo "3. 変更後、<a href='ride_records.php'>乗車記録画面</a>で確認<br>";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px;'>";
        echo "✅ 設定完了！<a href='ride_records.php'>乗車記録画面</a>で動作確認してください。<br>";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "<br>";
} catch (Exception $e) {
    echo "❌ システムエラー: " . $e->getMessage() . "<br>";
}

echo "</div>";
echo "<br><div style='text-align: center;'>";
echo "<a href='ride_records.php' style='padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>乗車記録で確認</a>";
echo "<a href='dashboard.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>ダッシュボード</a>";
echo "</div>";
?>
