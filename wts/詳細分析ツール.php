<?php
/**
 * 詳細テーブル構造分析ツール
 * 23個のテーブルの完全な構造とデータ状況を分析
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 詳細テーブル構造分析ツール</h1>";
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

// 1. 全テーブル一覧取得
echo "<h2>1. 📊 全テーブル一覧（23個）</h2>";

$stmt = $pdo->query("SHOW TABLES");
$all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "<div style='background: #e7f1ff; padding: 15px; border-radius: 5px;'>";
echo "<strong>総テーブル数: " . count($all_tables) . "個</strong><br>";
echo "<strong>テーブル名: </strong>" . implode(', ', $all_tables);
echo "</div>";

// 2. 各テーブルの詳細分析
echo "<h2>2. 🔍 各テーブル詳細分析</h2>";

foreach ($all_tables as $table) {
    echo "<div style='border: 1px solid #ddd; margin: 15px 0; padding: 15px; border-radius: 8px; background: white;'>";
    
    // テーブル名
    echo "<h3 style='color: #2c3e50; margin-top: 0;'>📋 {$table}</h3>";
    
    try {
        // テーブル情報取得
        $stmt = $pdo->query("SHOW TABLE STATUS LIKE '{$table}'");
        $table_status = $stmt->fetch();
        
        // カラム情報取得
        $stmt = $pdo->query("DESCRIBE {$table}");
        $columns = $stmt->fetchAll();
        
        // インデックス情報取得
        $stmt = $pdo->query("SHOW INDEX FROM {$table}");
        $indexes = $stmt->fetchAll();
        
        // レコード数取得
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $record_count = $stmt->fetchColumn();
        
        // サンプルデータ取得（最初の1件）
        $stmt = $pdo->query("SELECT * FROM {$table} LIMIT 1");
        $sample_data = $stmt->fetch();
        
        // 基本情報表示
        echo "<div style='display: flex; gap: 20px; margin-bottom: 15px;'>";
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; flex: 1;'>";
        echo "<h4>📈 基本情報</h4>";
        echo "<div><strong>レコード数:</strong> {$record_count}件</div>";
        echo "<div><strong>カラム数:</strong> " . count($columns) . "個</div>";
        echo "<div><strong>データサイズ:</strong> " . ($table_status['Data_length'] ? number_format($table_status['Data_length']) . " bytes" : "不明") . "</div>";
        echo "<div><strong>作成日時:</strong> " . ($table_status['Create_time'] ?: "不明") . "</div>";
        echo "<div><strong>更新日時:</strong> " . ($table_status['Update_time'] ?: "不明") . "</div>";
        echo "</div>";
        
        // エンジン情報
        echo "<div style='background: #e8f5e8; padding: 10px; border-radius: 5px; flex: 1;'>";
        echo "<h4>⚙️ エンジン情報</h4>";
        echo "<div><strong>エンジン:</strong> " . ($table_status['Engine'] ?: "不明") . "</div>";
        echo "<div><strong>文字コード:</strong> " . ($table_status['Collation'] ?: "不明") . "</div>";
        echo "<div><strong>自動増分:</strong> " . ($table_status['Auto_increment'] ?: "なし") . "</div>";
        echo "</div>";
        echo "</div>";
        
        // カラム詳細
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 10px;'>";
        echo "<h4>📑 カラム構造</h4>";
        echo "<table style='width: 100%; border-collapse: collapse; font-size: 14px;'>";
        echo "<tr style='background: #343a40; color: white;'>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>カラム名</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>データ型</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>NULL</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>キー</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>デフォルト</th>";
        echo "<th style='padding: 8px; border: 1px solid #ddd;'>備考</th>";
        echo "</tr>";
        
        foreach ($columns as $column) {
            $key_color = '';
            if ($column['Key'] === 'PRI') $key_color = 'background: #ffebee;';
            elseif ($column['Key'] === 'MUL') $key_color = 'background: #e8f5e8;';
            elseif ($column['Key'] === 'UNI') $key_color = 'background: #fff3e0;';
            
            echo "<tr style='{$key_color}'>";
            echo "<td style='padding: 6px; border: 1px solid #ddd; font-weight: bold;'>{$column['Field']}</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$column['Type']}</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$column['Null']}</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>";
            if ($column['Key'] === 'PRI') echo "🔑 PRIMARY";
            elseif ($column['Key'] === 'MUL') echo "🔗 INDEX";
            elseif ($column['Key'] === 'UNI') echo "⭐ UNIQUE";
            else echo "-";
            echo "</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>" . ($column['Default'] ?: '-') . "</td>";
            echo "<td style='padding: 6px; border: 1px solid #ddd;'>" . ($column['Extra'] ?: '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        // インデックス情報
        if (!empty($indexes)) {
            echo "<div style='background: #e8f4fd; padding: 15px; border-radius: 5px; margin-bottom: 10px;'>";
            echo "<h4>🗂️ インデックス情報</h4>";
            echo "<table style='width: 100%; border-collapse: collapse; font-size: 14px;'>";
            echo "<tr style='background: #007bff; color: white;'>";
            echo "<th style='padding: 8px; border: 1px solid #ddd;'>インデックス名</th>";
            echo "<th style='padding: 8px; border: 1px solid #ddd;'>カラム名</th>";
            echo "<th style='padding: 8px; border: 1px solid #ddd;'>ユニーク</th>";
            echo "<th style='padding: 8px; border: 1px solid #ddd;'>順序</th>";
            echo "</tr>";
            
            foreach ($indexes as $index) {
                $unique_color = $index['Non_unique'] == 0 ? 'background: #fff3e0;' : '';
                echo "<tr style='{$unique_color}'>";
                echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$index['Key_name']}</td>";
                echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$index['Column_name']}</td>";
                echo "<td style='padding: 6px; border: 1px solid #ddd;'>" . ($index['Non_unique'] == 0 ? "Yes" : "No") . "</td>";
                echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$index['Seq_in_index']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        // サンプルデータ
        if ($sample_data && $record_count > 0) {
            echo "<div style='background: #f0f8f0; padding: 15px; border-radius: 5px; margin-bottom: 10px;'>";
            echo "<h4>📄 サンプルデータ（1件目）</h4>";
            echo "<table style='width: 100%; border-collapse: collapse; font-size: 12px;'>";
            echo "<tr style='background: #28a745; color: white;'>";
            echo "<th style='padding: 6px; border: 1px solid #ddd;'>カラム</th>";
            echo "<th style='padding: 6px; border: 1px solid #ddd;'>値</th>";
            echo "<th style='padding: 6px; border: 1px solid #ddd;'>データ型</th>";
            echo "</tr>";
            
            foreach ($sample_data as $field => $value) {
                $display_value = $value;
                if (is_null($value)) $display_value = "<em style='color: #999;'>NULL</em>";
                elseif (strlen($value) > 50) $display_value = substr($value, 0, 47) . "...";
                
                echo "<tr>";
                echo "<td style='padding: 6px; border: 1px solid #ddd; font-weight: bold;'>{$field}</td>";
                echo "<td style='padding: 6px; border: 1px solid #ddd;'>{$display_value}</td>";
                echo "<td style='padding: 6px; border: 1px solid #ddd;'>" . gettype($value) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        } elseif ($record_count == 0) {
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
            echo "⚠️ このテーブルにはデータがありません";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
        echo "❌ テーブル分析エラー: " . $e->getMessage();
        echo "</div>";
    }
    
    echo "</div>";
}

// 3. テーブル関連性分析
echo "<h2>3. 🔗 テーブル関連性分析</h2>";

$related_tables = [
    'ユーザー関連' => ['users'],
    '車両関連' => ['vehicles'],
    '点呼関連' => ['pre_duty_calls', 'post_duty_calls'],
    '点検関連' => ['daily_inspections', 'periodic_inspections'],
    '運行関連（旧）' => ['daily_operations'],
    '運行関連（新）' => ['departure_records', 'arrival_records', 'ride_records'],
    '集金関連' => ['cash_confirmations', 'detailed_cash_confirmations', 'driver_change_stocks', 'cash_management'],
    '報告関連' => ['annual_reports', 'accidents'],
    'システム関連' => ['system_settings', 'company_info', 'fiscal_years']
];

foreach ($related_tables as $category => $tables) {
    echo "<div style='background: #e8f4fd; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<h4>{$category}</h4>";
    
    foreach ($tables as $table) {
        if (in_array($table, $all_tables)) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
                $count = $stmt->fetchColumn();
                echo "<div style='background: #d4edda; padding: 5px; margin: 2px; border-radius: 3px;'>";
                echo "✅ {$table}: {$count}件";
                echo "</div>";
            } catch (Exception $e) {
                echo "<div style='background: #f8d7da; padding: 5px; margin: 2px; border-radius: 3px;'>";
                echo "❌ {$table}: エラー";
                echo "</div>";
            }
        } else {
            echo "<div style='background: #f8d7da; padding: 5px; margin: 2px; border-radius: 3px;'>";
            echo "❌ {$table}: 存在しない";
            echo "</div>";
        }
    }
    echo "</div>";
}

// 4. 重複・類似テーブル候補
echo "<h2>4. ⚠️ 重複・類似テーブル候補</h2>";

$potential_duplicates = [
    '集金管理系' => [
        'tables' => ['cash_confirmations', 'detailed_cash_confirmations', 'driver_change_stocks'],
        'reason' => '同じ集金業務を異なる角度で管理'
    ],
    '運行記録系' => [
        'tables' => ['daily_operations', 'departure_records', 'arrival_records'],
        'reason' => '旧システムと新システムの並存'
    ],
    'システム設定系' => [
        'tables' => ['system_settings', 'company_info'],
        'reason' => 'システム設定と会社情報の重複'
    ]
];

foreach ($potential_duplicates as $category => $info) {
    echo "<div style='background: #fff3cd; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #ffc107;'>";
    echo "<h4>⚠️ {$category}</h4>";
    echo "<div><strong>対象テーブル:</strong> " . implode(', ', $info['tables']) . "</div>";
    echo "<div><strong>重複理由:</strong> {$info['reason']}</div>";
    
    $existing_tables = array_intersect($info['tables'], $all_tables);
    if (!empty($existing_tables)) {
        echo "<div style='margin-top: 10px;'><strong>実際に存在:</strong> " . implode(', ', $existing_tables) . "</div>";
    }
    echo "</div>";
}

echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
h1, h2, h3, h4 { color: #333; }
table { font-size: 13px; }
th { text-align: left; }
</style>
