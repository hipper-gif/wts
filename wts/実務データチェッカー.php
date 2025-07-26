<?php
/**
 * 実務データ重要度チェッカー
 * ride_records最優先、10件以上のテーブルのみ分析
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🎯 実務データ重要度チェッカー</h1>";
echo "<div style='font-family: Arial; background: #f8f9fa; padding: 20px;'>";

// DB接続
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4",
        "twinklemark_taxi",
        "Smiley2525",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

// 1. 全テーブルのレコード数チェック
echo "<h2>1. 📊 レコード数チェック（10件以上のみ表示）</h2>";

$stmt = $pdo->query("SHOW TABLES");
$all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$important_tables = [];
$empty_tables = [];

foreach ($all_tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        
        if ($count >= 10) {
            $important_tables[$table] = $count;
        } elseif ($count == 0) {
            $empty_tables[] = $table;
        }
    } catch (Exception $e) {
        // スキップ
    }
}

// 重要度順にソート（ride_recordsを最優先）
uksort($important_tables, function($a, $b) {
    if ($a === 'ride_records') return -1;
    if ($b === 'ride_records') return 1;
    return $important_tables[$b] <=> $important_tables[$a]; // レコード数の多い順
});

echo "<div style='background: #e7f1ff; padding: 15px; border-radius: 5px; margin-bottom: 20px;'>";
echo "<h3>🔥 実務データ保護対象（10件以上）</h3>";
foreach ($important_tables as $table => $count) {
    $priority = $table === 'ride_records' ? '🚨 最重要' : '🔶 重要';
    $bg_color = $table === 'ride_records' ? '#ffebee' : '#f8f9fa';
    
    echo "<div style='background: {$bg_color}; padding: 8px; margin: 5px 0; border-radius: 3px; border-left: 4px solid " . ($table === 'ride_records' ? '#dc3545' : '#ffc107') . ";'>";
    echo "<strong>{$priority} {$table}:</strong> {$count}件";
    echo "</div>";
}
echo "</div>";

if (!empty($empty_tables)) {
    echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin-bottom: 20px;'>";
    echo "<h3>🗑️ 空テーブル（削除候補）</h3>";
    echo implode(', ', $empty_tables);
    echo "</div>";
}

// 2. ride_records詳細分析（最重要）
echo "<h2>2. 🚨 ride_records 詳細分析（最重要データ）</h2>";

if (isset($important_tables['ride_records'])) {
    try {
        // 構造確認
        $stmt = $pdo->query("DESCRIBE ride_records");
        $columns = $stmt->fetchAll();
        
        // 最新5件のデータ
        $stmt = $pdo->query("SELECT * FROM ride_records ORDER BY created_at DESC LIMIT 5");
        $recent_data = $stmt->fetchAll();
        
        echo "<div style='background: #ffebee; padding: 15px; border-radius: 5px; border: 2px solid #dc3545;'>";
        echo "<h3>🚨 ride_records - 絶対保護</h3>";
        echo "<div><strong>レコード数:</strong> {$important_tables['ride_records']}件</div>";
        echo "<div><strong>カラム数:</strong> " . count($columns) . "個</div>";
        
        echo "<h4>📑 カラム構造</h4>";
        echo "<table style='width: 100%; border-collapse: collapse; font-size: 14px;'>";
        echo "<tr style='background: #dc3545; color: white;'>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>カラム名</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>データ型</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>NULL</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>キー</th>";
        echo "</tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td style='padding: 6px; border: 1px solid #ddd; font-weight: bold;'>{$column['Field']}</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$column['Type']}</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$column['Null']}</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>";
            if ($column['Key'] === 'PRI') echo "🔑 PRIMARY";
            elseif ($column['Key'] === 'MUL') echo "🔗 INDEX";
            else echo "-";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h4>📄 最新データサンプル（5件）</h4>";
        if (!empty($recent_data)) {
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto;'>";
            echo "<table style='width: 100%; border-collapse: collapse; font-size: 12px;'>";
            echo "<tr style='background: #28a745; color: white;'>";
            foreach (array_keys($recent_data[0]) as $field) {
                echo "<th style='padding: 4px; border: 1px solid #ddd; white-space: nowrap;'>{$field}</th>";
            }
            echo "</tr>";
            
            foreach (array_slice($recent_data, 0, 3) as $row) { // 3件だけ表示
                echo "<tr>";
                foreach ($row as $value) {
                    $display_value = $value;
                    if (is_null($value)) $display_value = "<em style='color: #999;'>NULL</em>";
                    elseif (strlen($value) > 15) $display_value = substr($value, 0, 12) . "...";
                    echo "<td style='padding: 4px; border: 1px solid #ddd;'>{$display_value}</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 10px;'>ride_records分析エラー: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "❌ ride_recordsテーブルが見つかりません！";
    echo "</div>";
}

// 3. その他の重要テーブル（簡潔に）
echo "<h2>3. 🔶 その他の重要実務データ</h2>";

foreach ($important_tables as $table => $count) {
    if ($table === 'ride_records') continue; // すでに分析済み
    
    echo "<div style='background: #fff3cd; padding: 10px; margin: 5px 0; border-radius: 5px;'>";
    echo "<h4>{$table} - {$count}件</h4>";
    
    try {
        // 簡潔な構造確認
        $stmt = $pdo->query("DESCRIBE {$table}");
        $columns = $stmt->fetchAll();
        $column_names = array_column($columns, 'Field');
        
        // 最新データの確認
        $stmt = $pdo->query("SELECT * FROM {$table} ORDER BY " . 
            (in_array('created_at', $column_names) ? 'created_at' : 
            (in_array('id', $column_names) ? 'id' : $column_names[0])) . 
            " DESC LIMIT 1");
        $latest = $stmt->fetch();
        
        echo "<div><strong>カラム数:</strong> " . count($columns) . "個</div>";
        echo "<div><strong>主要カラム:</strong> " . implode(', ', array_slice($column_names, 0, 6));
        if (count($column_names) > 6) echo "...";
        echo "</div>";
        
        if ($latest) {
            $latest_date = $latest['created_at'] ?? $latest['updated_at'] ?? $latest['date'] ?? '不明';
            echo "<div><strong>最新データ:</strong> {$latest_date}</div>";
        }
        
    } catch (Exception $e) {
        echo "<div style='color: #dc3545;'>分析エラー: " . $e->getMessage() . "</div>";
    }
    
    echo "</div>";
}

// 4. 安全な整理方針
echo "<h2>4. 🛡️ 安全な整理方針</h2>";

echo "<div style='background: #d1ecf1; padding: 20px; border-radius: 5px;'>";
echo "<h3>🎯 実務データ保護戦略</h3>";

echo "<div style='background: #ffebee; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #dc3545;'>";
echo "<h4>🚨 絶対保護（触らない）</h4>";
echo "<ul>";
echo "<li><strong>ride_records</strong> - " . ($important_tables['ride_records'] ?? 0) . "件の乗車記録</li>";
foreach ($important_tables as $table => $count) {
    if ($table !== 'ride_records') {
        echo "<li><strong>{$table}</strong> - {$count}件</li>";
    }
}
echo "</ul>";
echo "</div>";

echo "<div style='background: #d4edda; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #28a745;'>";
echo "<h4>✅ 安全な作業</h4>";
echo "<ul>";
echo "<li>空テーブルの削除（レコード0件）</li>";
echo "<li>設定ファイルの統一</li>";
echo "<li>ダッシュボードの修正（データは変更しない）</li>";
echo "<li>cash_management画面の修正</li>";
echo "</ul>";
echo "</div>";

echo "<div style='background: #fff3cd; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #ffc107;'>";
echo "<h4>⚠️ 慎重な作業（要バックアップ）</h4>";
echo "<ul>";
echo "<li>重複テーブルの統合（データ移行後に統合）</li>";
echo "<li>不要カラムの削除</li>";
echo "<li>テーブル名の変更</li>";
echo "</ul>";
echo "</div>";

echo "</div>";

echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
h1, h2, h3, h4 { color: #333; }
table { font-size: 12px; }
th { text-align: left; }
</style>
