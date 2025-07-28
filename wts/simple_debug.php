<?php
// シンプルデバッグ - 現状確認のみ（何も変更しない）
// URL: https://tw1nkle.com/Smiley/taxi/wts/simple_debug.php

require_once 'config/database.php';

echo "<h2>🔍 現状確認（変更なし）</h2>";
echo "<pre style='background: #f8f9fa; padding: 20px; border-radius: 5px;'>";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4", 
                   "twinklemark_taxi", "Smiley2525");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ データベース接続: 成功\n\n";
    
    // 1. usersテーブルの全内容を表示
    echo "=== usersテーブルの全内容 ===\n";
    $stmt = $pdo->query("SELECT * FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        echo "ID: {$user['id']}\n";
        echo "名前: {$user['name']}\n";
        echo "ログインID: {$user['login_id']}\n";
        echo "権限: {$user['role']}\n";
        echo "---\n";
    }
    
    echo "\n=== ride_records.phpで実行されるクエリ ===\n";
    $sql = "SELECT id, name FROM users WHERE role IN ('運転者', 'システム管理者') ORDER BY name";
    echo "SQL: {$sql}\n\n";
    
    $stmt = $pdo->query($sql);
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "結果件数: " . count($drivers) . "件\n";
    if (count($drivers) > 0) {
        echo "取得されたユーザー:\n";
        foreach ($drivers as $driver) {
            echo "- ID: {$driver['id']}, 名前: {$driver['name']}\n";
        }
    } else {
        echo "❌ 0件のため、運転者リストが空になります\n";
    }
    
    echo "\n=== 問題の原因 ===\n";
    if (count($drivers) == 0) {
        echo "権限が「運転者」または「システム管理者」のユーザーが存在しません。\n";
        echo "\n現在の権限一覧:\n";
        $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roles as $role) {
            echo "- {$role['role']}: {$role['count']}名\n";
        }
    } else {
        echo "✅ ユーザーは正常に取得されています。\n";
        echo "ride_records.phpの別の箇所に問題がある可能性があります。\n";
    }
    
} catch (PDOException $e) {
    echo "❌ エラー: " . $e->getMessage() . "\n";
}

echo "</pre>";

echo "<p><strong>この結果をコピーして教えてください。</strong></p>";
echo "<p>何も変更していないので、安全です。</p>";
?>
