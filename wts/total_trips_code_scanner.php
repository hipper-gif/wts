<?php
session_start();

// システムファイルの一覧
$system_files = [
    'dashboard.php',
    'ride_records.php', 
    'user_management.php',
    'vehicle_management.php',
    'arrival.php',
    'departure.php',
    'pre_duty_call.php',
    'post_duty_call.php',
    'daily_inspection.php',
    'periodic_inspection.php'
];

$messages = [];
$errors = [];
$modified_files = [];

echo "<h1>🔧 total_trips カラム参照削除修正</h1>";
echo "<p><strong>実行日時:</strong> " . date('Y-m-d H:i:s') . "</p>";

// Step 1: ファイル存在確認と total_trips 参照検索
echo "<h3>📁 Step 1: ファイル確認と参照検索</h3>";

foreach ($system_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        if (strpos($content, 'total_trips') !== false) {
            $messages[] = "⚠️ $file で total_trips の参照を発見";
            
            // Step 2: ファイルを修正
            $modified_content = modifyFileContent($content, $file);
            
            if ($modified_content !== $content) {
                // バックアップ作成
                $backup_file = $file . '.backup_' . date('Ymd_His');
                file_put_contents($backup_file, $content);
                $messages[] = "📄 $file のバックアップを作成: $backup_file";
                
                // 修正版を保存
                file_put_contents($file, $modified_content);
                $messages[] = "✅ $file を修正しました";
                $modified_files[] = $file;
            }
        } else {
            $messages[] = "✅ $file - total_trips 参照なし";
        }
    } else {
        $messages[] = "📄 $file - ファイル未存在";
    }
}

// ファイル修正関数
function modifyFileContent($content, $filename) {
    $original_content = $content;
    
    switch ($filename) {
        case 'dashboard.php':
            $content = fixDashboard($content);
            break;
            
        case 'ride_records.php':
            $content = fixRideRecords($content);
            break;
            
        case 'user_management.php':
            $content = fixUserManagement($content);
            break;
            
        case 'vehicle_management.php':
            $content = fixVehicleManagement($content);
            break;
            
        default:
            // 一般的な修正: total_trips を参照するクエリを修正
            $content = fixGenericTotalTrips($content);
            break;
    }
    
    return $content;
}

// ダッシュボード修正
function fixDashboard($content) {
    // total_trips を動的カウントに変更
    $patterns = [
        // SELECT total_trips FROM users を動的カウントに変更
        '/SELECT[^;]*total_trips[^;]*FROM\s+users[^;]*/i' => '(SELECT COUNT(*) FROM ride_records WHERE driver_id = users.id) as total_trips',
        
        // SELECT total_trips FROM vehicles を動的カウントに変更  
        '/SELECT[^;]*total_trips[^;]*FROM\s+vehicles[^;]*/i' => '(SELECT COUNT(*) FROM ride_records WHERE vehicle_id = vehicles.id) as total_trips',
        
        // u.total_trips を動的カウントに変更
        '/u\.total_trips/i' => '(SELECT COUNT(*) FROM ride_records WHERE driver_id = u.id)',
        
        // v.total_trips を動的カウントに変更
        '/v\.total_trips/i' => '(SELECT COUNT(*) FROM ride_records WHERE vehicle_id = v.id)',
        
        // users.total_trips を動的カウントに変更
        '/users\.total_trips/i' => '(SELECT COUNT(*) FROM ride_records WHERE driver_id = users.id)',
        
        // vehicles.total_trips を動的カウントに変更
        '/vehicles\.total_trips/i' => '(SELECT COUNT(*) FROM ride_records WHERE vehicle_id = vehicles.id)'
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    return $content;
}

// 乗車記録管理修正
function fixRideRecords($content) {
    // total_trips カラムを参照するUPDATE文を削除
    $patterns = [
        '/UPDATE\s+users\s+SET\s+total_trips[^;]*;/i' => '// total_trips カラム更新をスキップ',
        '/UPDATE\s+vehicles\s+SET\s+total_trips[^;]*;/i' => '// total_trips カラム更新をスキップ',
        '/total_trips\s*=\s*[^,)]+/i' => '/* total_trips removed */'
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    return $content;
}

// ユーザー管理修正
function fixUserManagement($content) {
    // total_trips カラム表示を削除または動的計算に変更
    $patterns = [
        '/total_trips/i' => '(SELECT COUNT(*) FROM ride_records WHERE driver_id = users.id) as total_trips',
        '/<th[^>]*>.*?総乗車.*?<\/th>/i' => '<!-- <th>総乗車回数</th> -->',
        '/<td[^>]*>\s*\$[^<]*total_trips[^<]*<\/td>/i' => '<!-- total_trips column removed -->'
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    return $content;
}

// 車両管理修正
function fixVehicleManagement($content) {
    // total_trips カラム表示を削除または動的計算に変更
    $patterns = [
        '/total_trips/i' => '(SELECT COUNT(*) FROM ride_records WHERE vehicle_id = vehicles.id) as total_trips',
        '/<th[^>]*>.*?総運行.*?<\/th>/i' => '<!-- <th>総運行回数</th> -->',
        '/<td[^>]*>\s*\$[^<]*total_trips[^<]*<\/td>/i' => '<!-- total_trips column removed -->'
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    return $content;
}

// 一般的な total_trips 参照修正
function fixGenericTotalTrips($content) {
    $patterns = [
        // INSERT 文から total_trips を削除
        '/,\s*total_trips/i' => '',
        '/total_trips\s*,/i' => '',
        
        // SELECT 文の total_trips を動的計算に変更（コンテキストに応じて）
        '/SELECT[^;]*total_trips[^;]*/i' => function($matches) {
            $match = $matches[0];
            if (strpos($match, 'users') !== false) {
                return str_replace('total_trips', '(SELECT COUNT(*) FROM ride_records WHERE driver_id = users.id) as total_trips', $match);
            } elseif (strpos($match, 'vehicles') !== false) {
                return str_replace('total_trips', '(SELECT COUNT(*) FROM ride_records WHERE vehicle_id = vehicles.id) as total_trips', $match);
            }
            return $match;
        }
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        if (is_callable($replacement)) {
            $content = preg_replace_callback($pattern, $replacement, $content);
        } else {
            $content = preg_replace($pattern, $replacement, $content);
        }
    }
    
    return $content;
}

// Step 3: 修正結果の表示
echo "<h3>📊 Step 3: 修正結果</h3>";

if (!empty($modified_files)) {
    echo "<div style='background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>✅ 修正完了したファイル</h4>";
    echo "<ul>";
    foreach ($modified_files as $file) {
        echo "<li>$file</li>";
    }
    echo "</ul>";
    echo "</div>";
} else {
    echo "<div style='background-color: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>ℹ️ 修正が必要なファイルはありませんでした</h4>";
    echo "</div>";
}

// Step 4: 手動修正用のコード例を提供
echo "<h3>🛠 Step 4: 手動修正用コード例</h3>";
echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #007cba;'>";
echo "<h4>乗車記録数の動的取得例</h4>";
echo "<pre><code>";
echo htmlspecialchars('// ユーザーの総乗車回数取得
$stmt = $pdo->prepare("
    SELECT 
        u.id, 
        u.name,
        (SELECT COUNT(*) FROM ride_records WHERE driver_id = u.id) as total_trips
    FROM users u 
    WHERE u.role LIKE \'%運転者%\'
");

// 車両の総運行回数取得  
$stmt = $pdo->prepare("
    SELECT 
        v.id,
        v.vehicle_number,
        (SELECT COUNT(*) FROM ride_records WHERE vehicle_id = v.id) as total_trips
    FROM vehicles v
");');
echo "</code></pre>";
echo "</div>";

// Step 5: 次のアクション
echo "<h3>🚀 Step 5: 次のアクション</h3>";
echo "<div style='background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 5px;'>";
echo "<ol>";
echo "<li>修正されたファイルの動作確認</li>";
echo "<li>乗車記録の新規登録テスト</li>";
echo "<li>ダッシュボードでのエラー確認</li>";
echo "<li>必要に応じて個別ファイルの手動修正</li>";
echo "</ol>";
echo "</div>";

// ログ出力
foreach ($messages as $message) {
    echo "<div style='padding: 5px; margin: 5px 0; background-color: #e7f3ff; border-left: 3px solid #007cba;'>";
    echo $message;
    echo "</div>";
}

echo "<hr>";
echo "<p><strong>修正完了時刻:</strong> " . date('Y-m-d H:i:s') . "</p>";
?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background-color: #f5f5f5;
}

h1, h3 {
    color: #333;
}

pre {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}

code {
    font-family: monospace;
    font-size: 14px;
}
</style>
