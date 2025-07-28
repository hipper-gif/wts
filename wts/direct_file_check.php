<?php
// 本番ファイル直接確認・修正
// URL: https://tw1nkle.com/Smiley/taxi/wts/direct_file_check.php

echo "<h2>🔧 本番ファイル直接確認・修正</h2>";

$file_path = __DIR__ . '/ride_records.php';

if (!file_exists($file_path)) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "❌ ride_records.phpが存在しません。";
    echo "</div>";
    exit();
}

$content = file_get_contents($file_path);
$lines = explode("\n", $content);

echo "<div style='background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px;'>";
echo "<strong>ファイル情報:</strong><br>";
echo "- サイズ: " . number_format(strlen($content)) . " 文字<br>";
echo "- 行数: " . count($lines) . " 行<br>";
echo "- 最終更新: " . date('Y-m-d H:i:s', filemtime($file_path));
echo "</div>";

// 運転者取得関連のコードを探す
echo "<strong>🔍 運転者取得関連のコード:</strong><br>";
echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto;'>";

$driver_related_lines = [];
foreach ($lines as $line_num => $line) {
    $line_trim = trim($line);
    if (empty($line_trim)) continue;
    
    if (stripos($line, 'driver') !== false || 
        (stripos($line, 'SELECT') !== false && stripos($line, 'users') !== false) ||
        (stripos($line, '$stmt') !== false && stripos($line, 'users') !== false)) {
        $driver_related_lines[] = [
            'line_num' => $line_num + 1,
            'content' => $line
        ];
    }
}

if (!empty($driver_related_lines)) {
    foreach ($driver_related_lines as $info) {
        echo "行{$info['line_num']}: " . htmlspecialchars($info['content']) . "\n";
    }
} else {
    echo "運転者関連のコードが見つかりません。";
}

echo "</pre>";

// 古いパターンを直接修正
echo "<br><strong>🔧 直接修正実行:</strong><br>";

$old_patterns = [
    "role IN ('運転者', 'システム管理者')",
    'role IN ("運転者", "システム管理者")',
    "WHERE role = '運転者'",
    'WHERE role = "運転者"'
];

$new_pattern = "(role IN ('driver', 'admin') OR is_driver = 1) AND is_active = 1";

$modified = false;
$original_content = $content;

foreach ($old_patterns as $old_pattern) {
    if (strpos($content, $old_pattern) !== false) {
        $content = str_replace($old_pattern, $new_pattern, $content);
        $modified = true;
        echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
        echo "✅ 修正: <code>" . htmlspecialchars($old_pattern) . "</code><br>";
        echo "→ <code>" . htmlspecialchars($new_pattern) . "</code>";
        echo "</div>";
    }
}

if ($modified) {
    // ファイルに書き込み
    if (file_put_contents($file_path, $content)) {
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<strong>✅ 修正完了！</strong><br>";
        echo "ride_records.phpを修正しました。";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
        echo "❌ ファイルの書き込みに失敗しました。";
        echo "</div>";
    }
} else {
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
    echo "⚠️ 修正対象のパターンが見つかりませんでした。<br>";
    echo "手動での確認が必要です。";
    echo "</div>";
    
    // ファイルの中身をもう少し詳しく表示
    echo "<br><strong>📋 ファイル内容詳細（最初の100行）:</strong><br>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 500px; overflow-y: auto; font-size: 12px;'>";
    for ($i = 0; $i < min(100, count($lines)); $i++) {
        echo sprintf("%3d: %s\n", $i + 1, htmlspecialchars($lines[$i]));
    }
    echo "</pre>";
}

echo "<div style='text-align: center; margin: 20px;'>";
echo "<a href='ride_records.php' style='padding: 15px 25px; background: #28a745; color: white; text-decoration: none; border-radius: 5px;'>乗車記録で確認</a>";
echo "</div>";
?>
