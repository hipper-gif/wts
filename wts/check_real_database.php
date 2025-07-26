<?php
// 実際のデータベース構造とデータを確認するスクリプト
session_start();

try {
    $host = 'localhost';
    $dbname = 'twinklemark_wts';
    $username = 'twinklemark_taxi';
    $password = 'Smiley2525';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>🔍 実際のデータベース構造とデータ確認</h1>";
    
    // 全テーブル一覧
    echo "<h2>📊 データベース内の全テーブル</h2>";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<strong>テーブル数:</strong> " . count($tables) . "<br>";
    echo "<strong>テーブル一覧:</strong> " . implode(', ', $tables);
    echo "</div>";
    
    // 各テーブルのデータ件数と構造を確認
    foreach ($tables as $table) {
        echo "<h3>📋 テーブル: {$table}</h3>";
        
        try {
            // データ件数
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            
            // テーブル構造
            $stmt = $pdo->query("DESCRIBE {$table}");
            $columns = $stmt->fetchAll();
            
            echo "<div style='background: " . ($count > 0 ? '#d4edda' : '#fff3cd') . "; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
            echo "<strong>データ件数:</strong> {$count} 件<br>";
            echo "<strong>カラム:</strong> ";
            $column_names = array_column($columns, 'Field');
            echo implode(', ', $column_names);
            echo "</div>";
            
            // データが存在する場合は最新3件を表示
            if ($count > 0) {
                echo "<h4>📝 最新データ（最大3件）</h4>";
                $stmt = $pdo->query("SELECT * FROM {$table} ORDER BY id DESC LIMIT 3");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($data)) {
                    echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
                    echo "<tr style='background: #f8f9fa;'>";
                    foreach (array_keys($data[0]) as $col) {
                        echo "<th style='padding: 5px;'>{$col}</th>";
                    }
                    echo "</tr>";
                    
                    foreach ($data as $row) {
                        echo "<tr>";
                        foreach ($row as $value) {
                            $display_value = strlen($value) > 30 ? substr($value, 0, 30) . '...' : $value;
                            echo "<td style='padding: 5px;'>" . htmlspecialchars($display_value) . "</td>";
                        }
                        echo "</tr>";
                    }
                    echo "</table>";
                }
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "<hr>";
    }
    
    // 統計データの実際の取得テスト
    echo "<h2>📈 実データ統計テスト</h2>";
    
    $today = date('Y-m-d');
    $current_month = date('Y-m');
    
    $statistics = [];
    
    // 出庫記録の確認
    if (in_array('departure_records', $tables)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
            $stmt->execute([$today]);
            $statistics['今日の出庫'] = $stmt->fetchColumn();
            
            $stmt = $pdo->query("SELECT departure_date, COUNT(*) as count FROM departure_records GROUP BY departure_date ORDER BY departure_date DESC LIMIT 5");
            $departure_history = $stmt->fetchAll();
        } catch (Exception $e) {
            $statistics['今日の出庫'] = "エラー: " . $e->getMessage();
        }
    } else {
        $statistics['今日の出庫'] = "テーブル不存在";
    }
    
    // 入庫記録の確認
    if (in_array('arrival_records', $tables)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
            $stmt->execute([$today]);
            $statistics['今日の入庫'] = $stmt->fetchColumn();
        } catch (Exception $e) {
            $statistics['今日の入庫'] = "エラー: " . $e->getMessage();
        }
    } else {
        $statistics['今日の入庫'] = "テーブル不存在";
    }
    
    // 乗車記録の確認
    if (in_array('ride_records', $tables)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*), SUM(fare_amount) FROM ride_records WHERE ride_date = ?");
            $stmt->execute([$today]);
            $ride_data = $stmt->fetch();
            $statistics['今日の乗車回数'] = $ride_data[0] ?? 0;
            $statistics['今日の売上'] = $ride_data[1] ?? 0;
            
            // 料金カラム名の確認
            $stmt = $pdo->query("DESCRIBE ride_records");
            $ride_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<p><strong>ride_recordsのカラム:</strong> " . implode(', ', $ride_columns) . "</p>";
            
        } catch (Exception $e) {
            $statistics['今日の乗車回数'] = "エラー: " . $e->getMessage();
            $statistics['今日の売上'] = "エラー: " . $e->getMessage();
        }
    } else {
        $statistics['今日の乗車回数'] = "テーブル不存在";
        $statistics['今日の売上'] = "テーブル不存在";
    }
    
    // 運行記録（旧システム）の確認
    if (in_array('daily_operations', $tables)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_operations WHERE operation_date = ?");
            $stmt->execute([$today]);
            $statistics['今日の運行記録（旧）'] = $stmt->fetchColumn();
            
            // 運行記録のカラム確認
            $stmt = $pdo->query("DESCRIBE daily_operations");
            $operation_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<p><strong>daily_operationsのカラム:</strong> " . implode(', ', $operation_columns) . "</p>";
            
        } catch (Exception $e) {
            $statistics['今日の運行記録（旧）'] = "エラー: " . $e->getMessage();
        }
    } else {
        $statistics['今日の運行記録（旧）'] = "テーブル不存在";
    }
    
    echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h4>📊 統計データ（" . $today . "）</h4>";
    foreach ($statistics as $key => $value) {
        $color = is_numeric($value) && $value > 0 ? 'green' : (strpos($value, 'エラー') !== false ? 'red' : 'orange');
        echo "<p><strong>{$key}:</strong> <span style='color: {$color};'>{$value}</span></p>";
    }
    echo "</div>";
    
    // データベース使用状況の推奨
    echo "<h2>💡 実データ連動の推奨事項</h2>";
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h4>🎯 使用すべき実際のテーブル</h4>";
    
    $recommended_tables = [
        'departure_records' => '出庫記録（新システム）',
        'arrival_records' => '入庫記録（新システム）', 
        'ride_records' => '乗車記録（独立システム）',
        'daily_operations' => '運行記録（旧システム・参考用）',
        'pre_duty_calls' => '乗務前点呼',
        'post_duty_calls' => '乗務後点呼',
        'daily_inspections' => '日常点検',
        'periodic_inspections' => '定期点検'
    ];
    
    foreach ($recommended_tables as $table => $description) {
        $exists = in_array($table, $tables);
        $count = 0;
        if ($exists) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
                $count = $stmt->fetchColumn();
            } catch (Exception $e) {
                $count = "エラー";
            }
        }
        
        $status_color = $exists ? ($count > 0 ? 'green' : 'orange') : 'red';
        $status_text = $exists ? ($count > 0 ? "✅ 使用可能（{$count}件）" : "⚠ 存在するがデータなし") : "❌ テーブル不存在";
        
        echo "<p><strong>{$table}</strong> - {$description}: <span style='color: {$status_color};'>{$status_text}</span></p>";
    }
    echo "</div>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h4>🔧 次のステップ</h4>";
    echo "<ol>";
    echo "<li>上記の実データ構造を確認</li>";
    echo "<li>ダッシュボードを実データ専用に修正</li>";
    echo "<li>サンプルデータ・固定値を削除</li>";
    echo "<li>実際のカラム名と一致させる</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<h1>❌ データベース接続エラー</h1>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
h1, h2, h3, h4 { color: #333; }
table { background: white; }
</style>
