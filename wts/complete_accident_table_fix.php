<?php
// 事故管理テーブル完全修正スクリプト
// accident_management.phpで使用される全カラムを追加

try {
    // データベース接続
    $host = 'localhost';
    $dbname = 'twinklemark_wts';
    $username = 'twinklemark_taxi';
    $password = 'Smiley2525';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>🔧 事故管理テーブル完全修正</h1>";
    echo "<p>accident_management.phpで使用される全カラムを追加します</p>";
    
    // 1. 現在のaccidentsテーブル構造を確認
    echo "<h2>📊 現在のテーブル構造確認</h2>";
    
    $existing_columns = [];
    try {
        $stmt = $pdo->query("DESCRIBE accidents");
        $columns = $stmt->fetchAll();
        
        foreach ($columns as $col) {
            $existing_columns[] = $col['Field'];
        }
        
        echo "<p>現在のカラム数: <strong>" . count($existing_columns) . "</strong></p>";
        echo "<p>既存カラム: <code>" . implode(', ', $existing_columns) . "</code></p>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<h4>⚠ テーブルが存在しません</h4>";
        echo "<p>accidentsテーブルを新規作成します</p>";
        echo "</div>";
        $existing_columns = [];
    }
    
    // 2. accident_management.phpで必要な全カラムを定義
    echo "<h2>🎯 必要カラムの定義</h2>";
    
    $required_columns = [
        // 基本カラム
        'id' => 'INT PRIMARY KEY AUTO_INCREMENT',
        'vehicle_id' => 'INT NOT NULL',
        'driver_id' => 'INT NOT NULL',
        'accident_date' => 'DATE NOT NULL',
        'accident_time' => 'TIME',
        
        // 詳細情報
        'location' => 'VARCHAR(255)',
        'weather' => 'VARCHAR(50)',
        'road_condition' => 'VARCHAR(100)',
        'accident_type' => 'ENUM("交通事故", "重大事故", "車両故障", "その他") DEFAULT "交通事故"',
        'severity' => 'ENUM("軽微", "軽傷", "重傷", "死亡") DEFAULT "軽微"',
        
        // 被害状況
        'deaths' => 'INT DEFAULT 0',
        'injuries' => 'INT DEFAULT 0',
        'damage_amount' => 'DECIMAL(10,2) DEFAULT 0.00',
        'vehicle_damage' => 'TEXT',
        'other_damage' => 'TEXT',
        
        // 詳細記録
        'description' => 'TEXT',
        'cause_analysis' => 'TEXT',
        'prevention_measures' => 'TEXT',
        'police_report' => 'VARCHAR(100)',
        'insurance_claim' => 'VARCHAR(100)',
        
        // 関係者情報
        'other_party_name' => 'VARCHAR(100)',
        'other_party_phone' => 'VARCHAR(20)',
        'other_party_insurance' => 'VARCHAR(100)',
        
        // システム管理
        'status' => 'ENUM("報告済み", "調査中", "処理完了", "保留") DEFAULT "報告済み"',
        'reported_to_police' => 'BOOLEAN DEFAULT FALSE',
        'reported_to_insurance' => 'BOOLEAN DEFAULT FALSE',
        'created_by' => 'INT',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];
    
    echo "<p>必要カラム数: <strong>" . count($required_columns) . "</strong></p>";
    
    // 3. テーブルが存在しない場合は作成
    if (empty($existing_columns)) {
        echo "<h3>🆕 accidentsテーブル新規作成</h3>";
        
        $create_sql = "CREATE TABLE accidents (\n";
        $column_definitions = [];
        foreach ($required_columns as $name => $definition) {
            $column_definitions[] = "    {$name} {$definition}";
        }
        $create_sql .= implode(",\n", $column_definitions);
        
        // 外部キー制約を追加
        $create_sql .= ",\n    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)";
        $create_sql .= ",\n    FOREIGN KEY (driver_id) REFERENCES users(id)";
        $create_sql .= ",\n    FOREIGN KEY (created_by) REFERENCES users(id)";
        $create_sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        try {
            $pdo->exec($create_sql);
            echo "<p style='color: green;'>✅ accidentsテーブルを作成しました</p>";
            
            // 作成後に再度カラムリストを取得
            $stmt = $pdo->query("DESCRIBE accidents");
            $columns = $stmt->fetchAll();
            $existing_columns = [];
            foreach ($columns as $col) {
                $existing_columns[] = $col['Field'];
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ テーブル作成エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    // 4. 不足カラムを追加
    echo "<h2>🔧 不足カラムの追加</h2>";
    
    $added_columns = [];
    $skipped_columns = [];
    $failed_columns = [];
    
    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            try {
                // PRIMARY KEY AUTO_INCREMENTの場合は特別処理
                if (strpos($column_definition, 'PRIMARY KEY AUTO_INCREMENT') !== false) {
                    continue; // IDカラムはスキップ
                }
                
                $sql = "ALTER TABLE accidents ADD COLUMN {$column_name} {$column_definition}";
                $pdo->exec($sql);
                $added_columns[] = $column_name;
                echo "<p style='color: green;'>✅ '{$column_name}' カラムを追加</p>";
                
            } catch (Exception $e) {
                $failed_columns[] = $column_name;
                echo "<p style='color: red;'>❌ '{$column_name}' 追加失敗: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            $skipped_columns[] = $column_name;
        }
    }
    
    // 5. 結果表示
    echo "<h2>📊 修正結果</h2>";
    
    if (!empty($added_columns)) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<h4>✅ 追加されたカラム (" . count($added_columns) . "個)</h4>";
        echo "<p>" . implode(', ', $added_columns) . "</p>";
        echo "</div>";
    }
    
    if (!empty($skipped_columns)) {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<h4>⏭ 既存カラム（スキップ） (" . count($skipped_columns) . "個)</h4>";
        echo "<p>" . implode(', ', $skipped_columns) . "</p>";
        echo "</div>";
    }
    
    if (!empty($failed_columns)) {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<h4>❌ 追加失敗 (" . count($failed_columns) . "個)</h4>";
        echo "<p>" . implode(', ', $failed_columns) . "</p>";
        echo "</div>";
    }
    
    // 6. 外部キー制約の追加
    echo "<h2>🔗 外部キー制約の設定</h2>";
    
    $foreign_keys = [
        'fk_accidents_vehicle' => 'FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)',
        'fk_accidents_driver' => 'FOREIGN KEY (driver_id) REFERENCES users(id)',
        'fk_accidents_created_by' => 'FOREIGN KEY (created_by) REFERENCES users(id)'
    ];
    
    foreach ($foreign_keys as $constraint_name => $constraint_sql) {
        try {
            $sql = "ALTER TABLE accidents ADD CONSTRAINT {$constraint_name} {$constraint_sql}";
            $pdo->exec($sql);
            echo "<p style='color: green;'>✅ 外部キー制約 '{$constraint_name}' を追加</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ 外部キー制約 '{$constraint_name}': " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    // 7. 最終的なテーブル構造を確認
    echo "<h2>📋 最終テーブル構造</h2>";
    
    try {
        $stmt = $pdo->query("DESCRIBE accidents");
        $final_columns = $stmt->fetchAll();
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th style='padding: 8px;'>カラム名</th>";
        echo "<th style='padding: 8px;'>データ型</th>";
        echo "<th style='padding: 8px;'>NULL</th>";
        echo "<th style='padding: 8px;'>キー</th>";
        echo "<th style='padding: 8px;'>デフォルト</th>";
        echo "<th style='padding: 8px;'>状態</th>";
        echo "</tr>";
        
        foreach ($final_columns as $col) {
            $is_new = in_array($col['Field'], $added_columns);
            $row_style = $is_new ? "background: #d4edda;" : "";
            
            echo "<tr style='{$row_style}'>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Field']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Type']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Null']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Key']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
            echo "<td style='padding: 8px;'>" . ($is_new ? "🆕 新規" : "既存") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p><strong>総カラム数: " . count($final_columns) . "</strong></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>最終確認エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 8. テストデータの追加
    echo "<h2>🧪 テストデータ追加</h2>";
    
    if (isset($_POST['add_test_data'])) {
        try {
            // 車両IDとユーザーIDを取得
            $stmt = $pdo->query("SELECT id FROM vehicles LIMIT 1");
            $vehicle = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
            $user = $stmt->fetch();
            
            if ($vehicle && $user) {
                $test_sql = "INSERT INTO accidents (
                    vehicle_id, driver_id, accident_date, accident_time,
                    location, weather, accident_type, severity,
                    deaths, injuries, damage_amount, description,
                    cause_analysis, prevention_measures, created_by
                ) VALUES (?, ?, CURDATE(), '14:30:00', 
                    'テスト交差点（国道1号線）', '雨', '交通事故', '軽微',
                    0, 1, 50000.00, 'テスト用事故記録 - 軽微な接触事故',
                    '雨天時の視界不良による判断ミス', 'ダッシュボードカメラの設置、雨天時運転講習の実施',
                    ?)";
                
                $stmt = $pdo->prepare($test_sql);
                $stmt->execute([$vehicle['id'], $user['id'], $user['id']]);
                
                echo "<p style='color: green;'>✅ テストデータを追加しました</p>";
                
                // 追加したデータを表示
                $stmt = $pdo->query("SELECT * FROM accidents ORDER BY id DESC LIMIT 1");
                $test_record = $stmt->fetch();
                
                if ($test_record) {
                    echo "<h4>追加されたテストデータ:</h4>";
                    echo "<ul>";
                    echo "<li>ID: " . $test_record['id'] . "</li>";
                    echo "<li>日付: " . $test_record['accident_date'] . "</li>";
                    echo "<li>場所: " . $test_record['location'] . "</li>";
                    echo "<li>損害額: ¥" . number_format($test_record['damage_amount']) . "</li>";
                    echo "</ul>";
                }
                
            } else {
                echo "<p style='color: red;'>❌ 車両またはユーザーデータが不足しています</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>テストデータ追加エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='add_test_data' style='background: #17a2b8; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer;'>";
    echo "🧪 テストデータを追加";
    echo "</button>";
    echo "</form>";
    
    // 9. 最終確認とテストリンク
    echo "<h2>🎉 修正完了</h2>";
    
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; margin: 15px 0; border-radius: 4px;'>";
    echo "<h3>✅ 事故管理テーブル修正完了</h3>";
    echo "<p><strong>追加されたカラム:</strong> " . count($added_columns) . "個</p>";
    echo "<p><strong>総カラム数:</strong> " . (count($existing_columns) + count($added_columns)) . "個</p>";
    echo "<p>accident_management.phpで必要な全カラムが追加されました。</p>";
    
    echo "<div style='margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap;'>";
    echo "<a href='accident_management.php' style='background: #dc3545; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;' target='_blank'>";
    echo "🚨 事故管理機能をテスト";
    echo "</a>";
    echo "<a href='annual_report.php' style='background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;' target='_blank'>";
    echo "📄 陸運局提出機能";
    echo "</a>";
    echo "<a href='emergency_audit_kit.php' style='background: #fd7e14; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;' target='_blank'>";
    echo "⚡ 緊急監査対応";
    echo "</a>";
    echo "<a href='dashboard.php' style='background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;'>";
    echo "📊 ダッシュボード";
    echo "</a>";
    echo "</div>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<h1>❌ データベース接続エラー</h1>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>エックスサーバーの管理画面でデータベース接続情報を確認してください。</p>";
}
?>

<style>
body { 
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
    margin: 20px; 
    background: #f8f9fa;
}
h1, h2, h3, h4 { color: #333; }
table { width: 100%; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
th, td { text-align: left; }
.container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
</style>
