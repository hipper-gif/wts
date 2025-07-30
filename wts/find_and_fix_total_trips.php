<?php
echo "<h2>total_trips 使用箇所検索・修正ツール</h2>";

// 検索対象ファイル
$files = [
    'dashboard.php',
    'ride_records.php', 
    'departure.php',
    'arrival.php',
    'pre_duty_call.php',
    'post_duty_call.php',
    'daily_inspection.php',
    'periodic_inspection.php',
    'user_management.php',
    'vehicle_management.php'
];

$found_files = [];

foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        if (strpos($content, 'total_trips') !== false) {
            $found_files[] = $file;
            echo "<h3>🔍 {$file}でtotal_trips発見！</h3>";
            
            // 該当行を表示
            $lines = explode("\n", $content);
            foreach ($lines as $line_num => $line) {
                if (strpos($line, 'total_trips') !== false) {
                    echo "<p><strong>行" . ($line_num + 1) . ":</strong> " . htmlspecialchars(trim($line)) . "</p>";
                }
            }
            
            // 自動修正
            $fixed_content = str_replace('total_trips', 'total_rides', $content);
            file_put_contents($file, $fixed_content);
            echo "<p>✅ {$file} を修正しました</p>";
            echo "<hr>";
        }
    }
}

if (empty($found_files)) {
    echo "<p>❌ total_tripsが見つかりませんでした</p>";
    echo "<p>🔍 手動でファイル内容を確認してください</p>";
} else {
    echo "<h3>📋 修正したファイル一覧:</h3>";
    foreach ($found_files as $file) {
        echo "<li>{$file}</li>";
    }
}

echo "<br><h3>🚀 次のアクション:</h3>";
echo "<p>1. 新規登録をテストしてください</p>";
echo "<p>2. まだエラーが出る場合は、JavaScriptファイルも確認してください</p>";
?>
