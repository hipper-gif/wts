<?php
// ride_records.php 運転者クエリ修正スクリプト
// URL: https://tw1nkle.com/Smiley/taxi/wts/fix_ride_records_query.php

echo "<h2>🔧 ride_records.php クエリ修正</h2>";
echo "<div style='background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px;'>";
echo "<strong>修正内容:</strong> ride_records.phpの97行目のクエリを、データベースの実際の状況に合わせて修正します。";
echo "</div>";

$ride_records_file = __DIR__ . '/ride_records.php';

if (!file_exists($ride_records_file)) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "❌ ride_records.phpファイルが見つかりません。";
    echo "</div>";
    exit();
}

try {
    // ファイルを読み込み
    $content = file_get_contents($ride_records_file);
    
    // 元のクエリ
    $old_query = 'SELECT id, name FROM users WHERE role IN (\'運転者\', \'システム管理者\') ORDER BY name';
    
    // 新しいクエリ（データベースの実状に合わせる）
    $new_query = 'SELECT id, name FROM users WHERE (role IN (\'driver\', \'admin\') OR is_driver = 1) AND is_active = 1 ORDER BY name';
    
    // クエリを置換
    $new_content = str_replace($old_query, $new_query, $content);
    
    if ($content === $new_content) {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
        echo "⚠️ 対象のクエリが見つかりませんでした。手動で確認が必要です。";
        echo "</div>";
        
        // 現在のクエリを表示
        if (preg_match('/\$stmt = \$pdo->query\("SELECT[^"]+users[^"]+"\);/', $content, $matches)) {
            echo "<h3>現在のクエリ:</h3>";
            echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>";
            echo htmlspecialchars($matches[0]);
            echo "</pre>";
        }
        
    } else {
        // ファイルを書き込み
        if (file_put_contents($ride_records_file, $new_content)) {
            echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px;'>";
            echo "✅ <strong>修正完了！</strong><br>";
            echo "修正前: <code>" . htmlspecialchars($old_query) . "</code><br>";
            echo "修正後: <code>" . htmlspecialchars($new_query) . "</code>";
            echo "</div>";
            
            echo "<div style='background: #cce7ff; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
            echo "<strong>期待される結果:</strong><br>";
            echo "- 杉原 星<br>";
            echo "- 杉原　充<br>";
            echo "- 保田　翔<br>";
            echo "- 工藤　洋子<br>";
            echo "- 眞壁　亜友美<br>";
            echo "- システム管理者<br>";
            echo "- 運転者B<br>";
            echo "<strong>合計7名が表示されます</strong>";
            echo "</div>";
            
        } else {
            echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
            echo "❌ ファイルの書き込みに失敗しました。権限を確認してください。";
            echo "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "❌ エラー: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "<div style='text-align: center; margin: 20px;'>";
echo "<a href='ride_records.php' style='padding: 15px 25px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; font-size: 16px;'>乗車記録で確認</a>";
echo "</div>";

echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<strong>注意:</strong> この修正により、次回のGitHub自動デプロイ時に変更が上書きされる可能性があります。<br>";
echo "恒久的な修正のためには、GitHubリポジトリのride_records.phpも同様に修正することを推奨します。";
echo "</div>";
?>
