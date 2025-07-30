<?php
echo "<h2>🔧 transport_category エラー修正</h2>";

// ride_records.phpの内容を読み込み
$file_path = 'ride_records.php';
$content = file_get_contents($file_path);

if ($content === false) {
    echo "<p style='color:red;'>❌ ride_records.php が見つかりません</p>";
    exit;
}

// エラーの原因となっているコードを検索・修正
$search_patterns = [
    // パターン1: transport_categoryの直接参照
    '/\$category\[\'transport_category\'\](?!\s*\?\?)/' => '$category[\'transport_category\'] ?? \'その他\'',
    
    // パターン2: 配列キーの安全でない参照
    '/echo\s+htmlspecialchars\(\$category\[\'transport_category\'\]\);/' => 'echo htmlspecialchars($category[\'transport_category\'] ?? \'その他\');',
    
    // パターン3: count, passengers, revenueの安全でない参照
    '/\$category\[\'count\'\](?!\s*\?\?)/' => '$category[\'count\'] ?? 0',
    '/\$category\[\'passengers\'\](?!\s*\?\?)/' => '$category[\'passengers\'] ?? 0',
    '/\$category\[\'revenue\'\](?!\s*\?\?)/' => '$category[\'revenue\'] ?? 0',
];

$changes_made = 0;

foreach ($search_patterns as $pattern => $replacement) {
    $new_content = preg_replace($pattern, $replacement, $content);
    if ($new_content !== $content) {
        $content = $new_content;
        $changes_made++;
        echo "<p>✅ パターン修正: " . htmlspecialchars($pattern) . "</p>";
    }
}

// より安全な輸送分類表示コードに置換
$safe_display_code = '<?php 
                            $display_category = $category[\'transport_category\'] ?? $category[\'transport_type\'] ?? \'その他\';
                            echo htmlspecialchars($display_category, ENT_QUOTES, \'UTF-8\'); 
                        ?>';

// 問題のある表示コードを検索・置換
$problem_patterns = [
    '/\<\?php echo htmlspecialchars\(\$category\[\'transport_category\'\](?:\s*\?\?\s*\'[^\']*\')?\); \?\>/',
    '/\<\?php echo htmlspecialchars\(\$category\[\'transport_category\'\]\); \?\>/'
];

foreach ($problem_patterns as $pattern) {
    $new_content = preg_replace($pattern, $safe_display_code, $content);
    if ($new_content !== $content) {
        $content = $new_content;
        $changes_made++;
        echo "<p>✅ 表示コード修正完了</p>";
    }
}

// ファイルを更新
if ($changes_made > 0) {
    $backup_file = 'ride_records_backup_' . date('Y-m-d_H-i-s') . '.php';
    file_put_contents($backup_file, file_get_contents($file_path));
    echo "<p>📄 バックアップ作成: {$backup_file}</p>";
    
    file_put_contents($file_path, $content);
    echo "<p>✅ ride_records.php を修正しました（{$changes_made}箇所）</p>";
    echo "<p>🔗 <a href='ride_records.php'>ride_records.php で確認してください</a></p>";
} else {
    echo "<p>ℹ️ 修正が必要な箇所が見つかりませんでした</p>";
    echo "<p>手動で805行目付近を確認してください</p>";
}

// 805行目付近の内容を表示
$lines = explode("\n", $content);
if (count($lines) >= 805) {
    echo "<h3>📋 805行目付近の内容:</h3>";
    echo "<pre style='background:#f5f5f5; padding:10px; border:1px solid #ddd;'>";
    for ($i = 800; $i <= 810 && $i < count($lines); $i++) {
        $line_num = $i + 1;
        $highlight = ($line_num == 805) ? 'style="background:yellow;"' : '';
        echo "<span {$highlight}>{$line_num}: " . htmlspecialchars($lines[$i]) . "</span>\n";
    }
    echo "</pre>";
}
?>
