<?php
// 事故管理テーブル構造修正スクリプト
require_once 'config/database.php';

try {
    // データベース接続（直接定義）
    $host = 'localhost';
    $dbname = 'twinklemark_wts';
    $username = 'twinklemark_taxi';
    $password = 'Smiley2525';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>🔧 事故管理テーブル構造修正</h1>";
    
    // 1. 現在のaccidentsテーブル構造を確認
    echo "<h2>📊 現在のaccidentsテーブル構造</h2>";
    try {
        $stmt = $pdo->query("DESCRIBE accidents");
        $columns = $stmt->fetchAll();
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'><th style='padding: 8px;'>カラム名</th><th style='padding: 8px;'>データ型</th><th style='padding: 8px;'>NULL</th><th style='padding: 8px;'>キー</th><th style='padding: 8px;'>デフォルト</th></tr>";
        
        $existing_columns = [];
        foreach ($columns as $col) {
            $existing_columns[] = $col['Field'];
            echo "<tr>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Field']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Type']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Null']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Key']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p>現在のカラム: <code>" . implode(', ', $existing_columns) . "</code></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>テーブル構造取得エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        
        // テーブルが存在しない場合は作成
        echo "<h3>🆕 accidentsテーブルを新規作成</h3>";
        $create_table_sql = "
        CREATE TABLE accidents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id INT NOT NULL,
            driver_id INT NOT NULL,
            accident_date DATE NOT NULL,
            accident_time TIME,
            location VARCHAR(255),
            weather VARCHAR(50),
            accident_type ENUM('交通事故', '重大事故', 'その他') DEFAULT '交通事故',
            severity ENUM('軽微', '軽傷', '重傷', '死亡') DEFAULT '軽微',
            deaths INT DEFAULT 0,
            injuries INT DEFAULT 0,
            description TEXT,
            cause_analysis TEXT,
            prevention_measures TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
            FOREIGN KEY (driver_id) REFERENCES users(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        try {
            $pdo->exec($create_table_sql);
            echo "<p style='color: green;'>✅ accidentsテーブルを作成しました</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>テーブル作成エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        $existing_columns = [];
    }
    
    // 2. 必要なカラムの確認と追加
    echo "<h2>🔧 必要なカラムの確認と追加</h2>";
    
    $required_columns = [
        'created_by' => 'INT',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'accident_time' => 'TIME',
        'location' => 'VARCHAR(255)',
        'weather' => 'VARCHAR(50)',
        'severity' => 'ENUM("軽微", "軽傷", "重傷", "死亡") DEFAULT "軽微"',
        'cause_analysis' => 'TEXT',
        'prevention_measures' => 'TEXT'
    ];
    
    $added_columns = [];
    $skipped_columns = [];
    
    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            try {
                $sql = "ALTER TABLE accidents ADD COLUMN {$column_name} {$column_definition}";
                $pdo->exec($sql);
                $added_columns[] = $column_name;
                echo "<p style='color: green;'>✅ カラム '{$column_name}' を追加しました</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ カラム '{$column_name}' の追加に失敗: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            $skipped_columns[] = $column_name;
        }
    }
    
    if (!empty($added_columns)) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<h4>✅ 追加されたカラム</h4>";
        echo "<p>" . implode(', ', $added_columns) . "</p>";
        echo "</div>";
    }
    
    if (!empty($skipped_columns)) {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<h4>⏭ 既存のカラム（スキップ）</h4>";
        echo "<p>" . implode(', ', $skipped_columns) . "</p>";
        echo "</div>";
    }
    
    // 3. 外部キー制約の追加（必要に応じて）
    echo "<h2>🔗 外部キー制約の確認</h2>";
    try {
        // created_byの外部キー制約を追加
        if (in_array('created_by', $added_columns)) {
            $sql = "ALTER TABLE accidents ADD CONSTRAINT fk_accidents_created_by FOREIGN KEY (created_by) REFERENCES users(id)";
            $pdo->exec($sql);
            echo "<p style='color: green;'>✅ created_by外部キー制約を追加しました</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠ 外部キー制約追加: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 4. 修正後のテーブル構造を再確認
    echo "<h2>📊 修正後のaccidentsテーブル構造</h2>";
    try {
        $stmt = $pdo->query("DESCRIBE accidents");
        $columns = $stmt->fetchAll();
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'><th style='padding: 8px;'>カラム名</th><th style='padding: 8px;'>データ型</th><th style='padding: 8px;'>NULL</th><th style='padding: 8px;'>キー</th><th style='padding: 8px;'>デフォルト</th></tr>";
        
        foreach ($columns as $col) {
            $is_new = in_array($col['Field'], $added_columns);
            $row_style = $is_new ? "background: #d4edda;" : "";
            echo "<tr style='{$row_style}'>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Field']) . ($is_new ? " 🆕" : "") . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Type']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Null']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Key']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>修正後テーブル構造取得エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 5. テストデータの追加（オプション）
    echo "<h2>🧪 テストデータ追加（オプション）</h2>";
    if (isset($_POST['add_test_data'])) {
        try {
            // 車両IDとユーザーIDを取得
            $stmt = $pdo->query("SELECT id FROM vehicles LIMIT 1");
            $vehicle = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
            $user = $stmt->fetch();
            
            if ($vehicle && $user) {
                $test_data_sql = "
                INSERT INTO accidents (
                    vehicle_id, driver_id, accident_date, accident_time, 
                    location, weather, accident_type, severity, 
                    deaths, injuries, description, cause_analysis, 
                    prevention_measures, created_by
                ) VALUES (
                    ?, ?, CURDATE(), '10:30:00',
                    'テスト交差点', '晴れ', '交通事故', '軽微',
                    0, 0, 'テスト用事故記録', 'テスト原因分析', 
                    'テスト再発防止策', ?
                )";
                
                $stmt = $pdo->prepare($test_data_sql);
                $stmt->execute([$vehicle['id'], $user['id'], $user['id']]);
                
                echo "<p style='color: green;'>✅ テストデータを追加しました</p>";
            } else {
                echo "<p style='color: red;'>❌ 車両またはユーザーデータが不足しています</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>テストデータ追加エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    echo "<form method='post' style='margin: 10px 0;'>";
    echo "<button type='submit' name='add_test_data' style='background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer;'>";
    echo "🧪 テストデータを追加";
    echo "</button>";
    echo "</form>";
    
    // 6. 最終確認とテストリンク
    echo "<h2>🎯 修正完了 - 機能テスト</h2>";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
    echo "<h4>✅ 修正作業完了</h4>";
    echo "<p>accidentsテーブルの構造を修正しました。</p>";
    echo "<p>以下のリンクで事故管理機能をテストしてください：</p>";
    echo "<div style='margin: 15px 0;'>";
    echo "<a href='accident_management.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px;' target='_blank'>🚨 事故管理機能をテスト</a>";
    echo "<a href='dashboard.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>📊 ダッシュボードに戻る</a>";
    echo "</div>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<h1>❌ データベース接続エラー</h1>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h1, h2, h3, h4 { color: #333; }
table { width: 100%; }
th, td { text-align: left; }
</style>
