<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>🔧 運転者リスト修正スクリプト</h2>";
    
    // Step 1: 現在のユーザー権限を確認・修正
    echo "<h3>Step 1: ユーザー権限設定</h3>";
    
    // 有効なユーザーを運転者として設定
    $update_sql = "UPDATE users SET is_driver = 1 WHERE active = 1";
    $pdo->exec($update_sql);
    echo "✅ 全ての有効ユーザーを運転者として設定しました<br>";
    
    // Step 2: ride_records.phpの修正
    echo "<h3>Step 2: ride_records.php修正</h3>";
    
    $file_path = 'ride_records.php';
    $content = file_get_contents($file_path);
    
    if ($content === false) {
        echo "❌ ride_records.php が見つかりません<br>";
        exit;
    }
    
    // 問題のあるSQL文を検索・修正
    $old_pattern = '/\$drivers_sql\s*=\s*["\']SELECT[^"\']*WHERE[^"\']*ORDER BY[^"\']*["\'];/';
    $new_sql = '$drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND active = 1 ORDER BY name";';
    
    $new_content = preg_replace($old_pattern, $new_sql, $content);
    
    if ($new_content !== $content) {
        // バックアップ作成
        $backup_file = 'ride_records_backup_drivers_' . date('Y-m-d_H-i-s') . '.php';
        file_put_contents($backup_file, $content);
        echo "📄 バックアップ作成: {$backup_file}<br>";
        
        // ファイル更新
        file_put_contents($file_path, $new_content);
        echo "✅ ride_records.php の運転者取得SQLを修正しました<br>";
    } else {
        echo "ℹ️ 自動修正できませんでした。手動で以下のように修正してください:<br>";
        echo "<pre style='background: #f5f5f5; padding: 10px;'>";
        echo htmlspecialchars('$drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND active = 1 ORDER BY name";');
        echo "</pre>";
    }
    
    // Step 3: 修正後のリスト確認
    echo "<h3>Step 3: 修正後の運転者リスト</h3>";
    
    $drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND active = 1 ORDER BY name";
    $stmt = $pdo->prepare($drivers_sql);
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>✅ 運転者として表示されるユーザー:</p>";
    echo "<ul>";
    foreach ($drivers as $driver) {
        echo "<li>" . htmlspecialchars($driver['name']) . " (ID: {$driver['id']})</li>";
    }
    echo "</ul>";
    
    echo "<h3>🎉 修正完了</h3>";
    echo "<p>🔗 <a href='ride_records.php'>ride_records.php で確認してください</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
