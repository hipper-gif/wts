<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>🔧 運転者リスト最終修正（is_active対応）</h2>";
    
    // Step 1: 現在のユーザー設定確認
    echo "<h3>Step 1: 現在のユーザー設定確認</h3>";
    
    $users_sql = "SELECT id, name, role, permission_level, is_driver, is_caller, is_active 
                  FROM users ORDER BY name";
    $stmt = $pdo->prepare($users_sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>ID</th><th>名前</th><th>is_driver</th><th>is_caller</th><th>is_active</th><th>操作</th>";
    echo "</tr>";
    
    foreach ($users as $user) {
        $driver_status = $user['is_driver'] ? '✅' : '❌';
        $caller_status = $user['is_caller'] ? '✅' : '❌';
        $active_status = $user['is_active'] ? '✅' : '❌';
        $should_be_driver = $user['is_active'] ? '→運転者に設定推奨' : '';
        
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td>{$driver_status}</td>";
        echo "<td>{$caller_status}</td>";
        echo "<td>{$active_status}</td>";
        echo "<td>{$should_be_driver}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Step 2: 有効なユーザーを運転者として設定
    echo "<h3>Step 2: 有効ユーザーの運転者権限設定</h3>";
    
    $update_sql = "UPDATE users SET is_driver = 1 WHERE is_active = 1";
    $result = $pdo->exec($update_sql);
    echo "✅ {$result}名のユーザーを運転者として設定しました<br>";
    
    // Step 3: ride_records.phpの修正
    echo "<h3>Step 3: ride_records.php修正</h3>";
    
    $file_path = 'ride_records.php';
    $content = file_get_contents($file_path);
    
    if ($content === false) {
        echo "❌ ride_records.php が見つかりません<br>";
        exit;
    }
    
    // 問題のあるSQL文パターンを検索・修正
    $old_patterns = [
        // パターン1: active を使用
        '/\$drivers_sql\s*=\s*"[^"]*WHERE[^"]*active\s*=\s*1[^"]*ORDER BY[^"]*";/',
        // パターン2: 複雑な条件
        '/\$drivers_sql\s*=\s*"SELECT[^"]*WHERE[^"]*\(role[^"]*OR[^"]*is_driver[^"]*\)[^"]*ORDER BY[^"]*";/',
        // パターン3: is_active以外を使用
        '/\$drivers_sql\s*=\s*"SELECT[^"]*WHERE[^"]*is_driver[^"]*AND[^"]*(?!is_active)[^"]*ORDER BY[^"]*";/'
    ];
    
    $new_sql = '$drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name";';
    
    $changes_made = 0;
    $original_content = $content;
    
    foreach ($old_patterns as $pattern) {
        $new_content = preg_replace($pattern, $new_sql, $content);
        if ($new_content !== $content) {
            $content = $new_content;
            $changes_made++;
            echo "✅ パターン{$changes_made}を修正しました<br>";
        }
    }
    
    // 手動での文字列置換も試行
    $manual_replacements = [
        'WHERE (role IN (\'driver\', \'admin\') OR is_driver = 1) AND is_active = 1' => 'WHERE is_driver = 1 AND is_active = 1',
        'WHERE is_driver = 1 AND active = 1' => 'WHERE is_driver = 1 AND is_active = 1',
        'WHERE active = 1' => 'WHERE is_active = 1'
    ];
    
    foreach ($manual_replacements as $old => $new) {
        if (strpos($content, $old) !== false) {
            $content = str_replace($old, $new, $content);
            $changes_made++;
            echo "✅ 手動置換を実行しました: " . htmlspecialchars($old) . "<br>";
        }
    }
    
    if ($content !== $original_content) {
        // バックアップ作成
        $backup_file = 'ride_records_backup_is_active_' . date('Y-m-d_H-i-s') . '.php';
        file_put_contents($backup_file, $original_content);
        echo "📄 バックアップ作成: {$backup_file}<br>";
        
        // ファイル更新
        file_put_contents($file_path, $content);
        echo "✅ ride_records.php を修正しました（{$changes_made}箇所）<br>";
    } else {
        echo "⚠️ 自動修正箇所が見つかりませんでした<br>";
        echo "<p>手動で以下のように修正してください:</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px;'>";
        echo htmlspecialchars('$drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name";');
        echo "</pre>";
    }
    
    // Step 4: 修正後の運転者リスト確認
    echo "<h3>Step 4: 修正後の運転者リスト確認</h3>";
    
    $final_drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name";
    $stmt = $pdo->prepare($final_drivers_sql);
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>✅ 運転者として表示されるユーザー(" . count($drivers) . "名):</p>";
    echo "<ul>";
    foreach ($drivers as $driver) {
        echo "<li>" . htmlspecialchars($driver['name']) . " (ID: {$driver['id']})</li>";
    }
    echo "</ul>";
    
    if (empty($drivers)) {
        echo "<p style='color: red;'>⚠️ 運転者が0名です。is_active = 1 のユーザーがいない可能性があります。</p>";
        
        // 全ユーザーを有効化
        echo "<h4>🔄 全ユーザー有効化</h4>";
        $activate_sql = "UPDATE users SET is_active = 1";
        $pdo->exec($activate_sql);
        echo "✅ 全ユーザーを有効化しました<br>";
        
        // 再確認
        $stmt = $pdo->prepare($final_drivers_sql);
        $stmt->execute();
        $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p>🔄 再確認結果(" . count($drivers) . "名):</p>";
        echo "<ul>";
        foreach ($drivers as $driver) {
            echo "<li>" . htmlspecialchars($driver['name']) . " (ID: {$driver['id']})</li>";
        }
        echo "</ul>";
    }
    
    echo "<h3>🎉 修正完了</h3>";
    echo "<p>🔗 <a href='ride_records.php'>ride_records.php で運転者リストを確認してください</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
