<?php
// 現在のride_records.phpの運転者取得クエリ確認
// URL: https://tw1nkle.com/Smiley/taxi/wts/debug_current_ride_records.php

require_once 'config/database.php';

echo "<h2>🔍 現在のride_records.php 運転者取得デバッグ</h2>";
echo "<pre style='background: #f8f9fa; padding: 20px; border-radius: 5px;'>";

try {
    $pdo = new PDO("mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4", 
                   "twinklemark_taxi", "Smiley2525");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ データベース接続: 成功\n\n";
    
    // 1. 現在のusersテーブルの状況
    echo "=== usersテーブルの現在の状況 ===\n";
    $stmt = $pdo->query("SELECT id, name, login_id, role, is_driver, is_active FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "総ユーザー数: " . count($users) . "名\n\n";
    foreach ($users as $user) {
        $driver_flag = isset($user['is_driver']) ? ($user['is_driver'] ? '○' : '×') : 'N/A';
        $active_flag = isset($user['is_active']) ? ($user['is_active'] ? '○' : '×') : 'N/A';
        echo "ID: {$user['id']}\n";
        echo "名前: '{$user['name']}'\n";
        echo "ログインID: '{$user['login_id']}'\n";
        echo "権限: '{$user['role']}'\n";
        echo "is_driver: {$driver_flag}\n";
        echo "is_active: {$active_flag}\n";
        echo "---\n";
    }
    
    // 2. 複数のクエリパターンをテスト
    echo "\n=== 複数のクエリパターンをテスト ===\n";
    
    $test_queries = [
        '日本語権限パターン' => "SELECT id, name FROM users WHERE role IN ('運転者', 'システム管理者') ORDER BY name",
        '英語権限パターン' => "SELECT id, name FROM users WHERE role IN ('driver', 'admin') ORDER BY name",
        'GitHub完全パターン' => "SELECT id, name FROM users WHERE (role IN ('driver', 'admin') OR is_driver = 1) AND is_active = 1 ORDER BY name",
        'is_driverのみ' => "SELECT id, name FROM users WHERE is_driver = 1 ORDER BY name",
        'アクティブユーザーのみ' => "SELECT id, name FROM users WHERE is_active = 1 ORDER BY name",
        '名前が空でないユーザー' => "SELECT id, name FROM users WHERE name IS NOT NULL AND name != '' ORDER BY name",
        '全ユーザー' => "SELECT id, name FROM users ORDER BY name"
    ];
    
    foreach ($test_queries as $label => $sql) {
        echo "\n【{$label}】\n";
        echo "SQL: {$sql}\n";
        
        try {
            $stmt = $pdo->query($sql);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "結果: " . count($results) . "件\n";
            if (count($results) > 0) {
                foreach ($results as $result) {
                    echo "- ID: {$result['id']}, 名前: '{$result['name']}'\n";
                }
            } else {
                echo "❌ 0件\n";
            }
        } catch (PDOException $e) {
            echo "❌ エラー: " . $e->getMessage() . "\n";
        }
    }
    
    // 3. 実際のride_records.phpファイルの内容を確認
    echo "\n\n=== 実際のride_records.phpの運転者取得部分を確認 ===\n";
    
    $ride_records_file = __DIR__ . '/ride_records.php';
    if (file_exists($ride_records_file)) {
        $content = file_get_contents($ride_records_file);
        
        // 運転者取得のクエリ部分を抽出
        if (preg_match('/drivers_sql\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
            echo "ファイル内の運転者取得SQL:\n";
            echo $matches[1] . "\n";
        } else {
            echo "❌ ride_records.php内で運転者取得SQLが見つかりません\n";
        }
        
        // SELECT文を含む行を検索
        $lines = explode("\n", $content);
        $found_sql_lines = [];
        foreach ($lines as $line_num => $line) {
            if (stripos($line, 'SELECT') !== false && stripos($line, 'users') !== false) {
                $found_sql_lines[] = "行" . ($line_num + 1) . ": " . trim($line);
            }
        }
        
        if (!empty($found_sql_lines)) {
            echo "\nファイル内のSELECT文（users関連）:\n";
            foreach ($found_sql_lines as $sql_line) {
                echo $sql_line . "\n";
            }
        }
        
    } else {
        echo "❌ ride_records.phpファイルが見つかりません\n";
    }
    
    // 4. 推奨解決策
    echo "\n\n=== 推奨解決策 ===\n";
    
    // 最も成功率の高いクエリを特定
    $best_query = '';
    $best_count = 0;
    
    foreach ($test_queries as $label => $sql) {
        try {
            $stmt = $pdo->query($sql);
            $count = $stmt->rowCount();
            if ($count > $best_count) {
                $best_count = $count;
                $best_query = $sql;
            }
        } catch (PDOException $e) {
            // エラーの場合はスキップ
        }
    }
    
    if ($best_count > 0) {
        echo "✅ 最適なクエリ（{$best_count}件取得）:\n";
        echo "{$best_query}\n\n";
        echo "このクエリをride_records.phpで使用することを推奨します。\n";
    } else {
        echo "❌ すべてのクエリで0件でした。usersテーブルの基本的な修正が必要です。\n";
    }
    
} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "\n";
}

echo "</pre>";

echo "<div style='margin: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;'>";
echo "<strong>次のステップ:</strong><br>";
echo "1. 上記の結果で「最適なクエリ」が表示されている場合、そのクエリを使用<br>";
echo "2. すべて0件の場合、usersテーブルの基本データに問題がある<br>";
echo "3. この結果を確認して、具体的な修正方針を決定";
echo "</div>";

echo "<div style='text-align: center;'>";
echo "<a href='ride_records.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>乗車記録画面</a>";
echo "</div>";
?>
