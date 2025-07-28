<?php
/**
 * ride_recordsテーブル修正スクリプト
 * 要件定義準拠のためのデータベース構造修正
 */

session_start();
require_once 'config/database.php';

// 管理者権限チェック
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    die("管理者権限が必要です。");
}

try {
    $pdo = getDBConnection();
    
    echo "<h2>ride_recordsテーブル修正スクリプト</h2>";
    echo "<p>要件定義準拠のためのデータベース構造を修正します。</p>";
    
    // 現在のテーブル構造確認
    echo "<h3>1. 現在のテーブル構造確認</h3>";
    $describe_sql = "DESCRIBE ride_records";
    $describe_stmt = $pdo->query($describe_sql);
    $current_columns = $describe_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL許可</th><th>キー</th><th>デフォルト値</th></tr>";
    foreach ($current_columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 必要なカラムの確認
    $existing_columns = array_column($current_columns, 'Field');
    $required_columns = [
        'departure_record_id' => "INT NULL COMMENT '出庫記録ID（運行記録との関連付け）'",
        'ride_number' => "INT DEFAULT 1 COMMENT '連番（運転日報内での乗車記録の順番）'"
    ];
    
    echo "<h3>2. カラム追加処理</h3>";
    
    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            echo "<p>カラム追加: {$column_name}</p>";
            
            $alter_sql = "ALTER TABLE ride_records ADD COLUMN {$column_name} {$column_definition}";
            $pdo->exec($alter_sql);
            
            echo "<span style='color: green;'>✓ カラム '{$column_name}' を追加しました。</span><br>";
        } else {
            echo "<span style='color: orange;'>- カラム '{$column_name}' は既に存在します。</span><br>";
        }
    }
    
    // インデックス追加
    echo "<h3>3. インデックス追加</h3>";
    
    try {
        $index_sql = "CREATE INDEX idx_departure_record ON ride_records(departure_record_id)";
        $pdo->exec($index_sql);
        echo "<span style='color: green;'>✓ departure_record_idにインデックスを追加しました。</span><br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "<span style='color: orange;'>- インデックス idx_departure_record は既に存在します。</span><br>";
        } else {
            throw $e;
        }
    }
    
    try {
        $index_sql = "CREATE INDEX idx_ride_number ON ride_records(departure_record_id, ride_number)";
        $pdo->exec($index_sql);
        echo "<span style='color: green;'>✓ 複合インデックス(departure_record_id, ride_number)を追加しました。</span><br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "<span style='color: orange;'>- インデックス idx_ride_number は既に存在します。</span><br>";
        } else {
            throw $e;
        }
    }
    
    // 外部キー制約追加（departure_recordsテーブルが存在する場合）
    echo "<h3>4. 外部キー制約追加</h3>";
    
    $check_departure_table = $pdo->query("SHOW TABLES LIKE 'departure_records'");
    if ($check_departure_table->rowCount() > 0) {
        try {
            $fk_sql = "ALTER TABLE ride_records 
                       ADD CONSTRAINT fk_ride_departure 
                       FOREIGN KEY (departure_record_id) 
                       REFERENCES departure_records(id) 
                       ON DELETE SET NULL 
                       ON UPDATE CASCADE";
            $pdo->exec($fk_sql);
            echo "<span style='color: green;'>✓ departure_recordsテーブルとの外部キー制約を追加しました。</span><br>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate foreign key constraint name') !== false) {
                echo "<span style='color: orange;'>- 外部キー制約 fk_ride_departure は既に存在します。</span><br>";
            } else {
                echo "<span style='color: red;'>外部キー制約追加エラー: " . $e->getMessage() . "</span><br>";
            }
        }
    } else {
        echo "<span style='color: orange;'>- departure_recordsテーブルが存在しないため、外部キー制約をスキップします。</span><br>";
    }
    
    // 既存データの移行処理
    echo "<h3>5. 既存データ移行処理</h3>";
    
    // 既存のride_recordsで departure_record_id が NULL のものを確認
    $null_departure_sql = "SELECT COUNT(*) as count FROM ride_records WHERE departure_record_id IS NULL";
    $null_departure_stmt = $pdo->query($null_departure_sql);
    $null_count = $null_departure_stmt->fetchColumn();
    
    if ($null_count > 0) {
        echo "<p>{$null_count}件の乗車記録に運行記録IDが設定されていません。</p>";
        
        // 日付・運転者・車両が一致する出庫記録を検索して自動関連付け
        $migration_sql = "
            UPDATE ride_records r
            JOIN departure_records d ON (
                r.driver_id = d.driver_id AND 
                r.vehicle_id = d.vehicle_id AND 
                r.ride_date = d.departure_date
            )
            SET r.departure_record_id = d.id
            WHERE r.departure_record_id IS NULL
        ";
        
        $affected_rows = $pdo->exec($migration_sql);
        echo "<span style='color: green;'>✓ {$affected_rows}件の乗車記録を自動で運行記録に関連付けました。</span><br>";
        
        // 残った未関連付けデータの確認
        $remaining_null = $pdo->query($null_departure_sql)->fetchColumn();
        if ($remaining_null > 0) {
            echo "<span style='color: orange;'>⚠ {$remaining_null}件の乗車記録が運行記録に関連付けできませんでした。</span><br>";
            echo "<p>これらのデータは手動で修正するか、対応する出庫記録を作成してください。</p>";
        }
    } else {
        echo "<span style='color: green;'>✓ 全ての乗車記録に運行記録IDが設定されています。</span><br>";
    }
    
    // ride_number の自動設定
    echo "<h3>6. 連番（ride_number）の自動設定</h3>";
    
    $ride_number_sql = "SELECT COUNT(*) as count FROM ride_records WHERE ride_number IS NULL OR ride_number = 0";
    $ride_number_stmt = $pdo->query($ride_number_sql);
    $ride_number_null_count = $ride_number_stmt->fetchColumn();
    
    if ($ride_number_null_count > 0) {
        echo "<p>{$ride_number_null_count}件の乗車記録に連番が設定されていません。自動設定します。</p>";
        
        // 各departure_record_idごとに連番を設定
        $set_ride_numbers_sql = "
            SELECT DISTINCT departure_record_id 
            FROM ride_records 
            WHERE departure_record_id IS NOT NULL 
            ORDER BY departure_record_id
        ";
        $departure_ids = $pdo->query($set_ride_numbers_sql)->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($departure_ids as $departure_id) {
            $rides_sql = "
                SELECT id 
                FROM ride_records 
                WHERE departure_record_id = ? 
                ORDER BY ride_time ASC, created_at ASC
            ";
            $rides_stmt = $pdo->prepare($rides_sql);
            $rides_stmt->execute([$departure_id]);
            $ride_ids = $rides_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($ride_ids as $index => $ride_id) {
                $update_number_sql = "UPDATE ride_records SET ride_number = ? WHERE id = ?";
                $update_number_stmt = $pdo->prepare($update_number_sql);
                $update_number_stmt->execute([$index + 1, $ride_id]);
            }
        }
        
        echo "<span style='color: green;'>✓ 全ての乗車記録に連番を設定しました。</span><br>";
    } else {
        echo "<span style='color: green;'>✓ 全ての乗車記録に連番が設定されています。</span><br>";
    }
    
    // 修正後のテーブル構造確認
    echo "<h3>7. 修正後のテーブル構造確認</h3>";
    $final_describe_stmt = $pdo->query($describe_sql);
    $final_columns = $final_describe_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>NULL許可</th><th>キー</th><th>デフォルト値</th></tr>";
    foreach ($final_columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 統計情報
    echo "<h3>8. 統計情報</h3>";
    $stats_sql = "
        SELECT 
            COUNT(*) as total_rides,
            COUNT(DISTINCT departure_record_id) as linked_operations,
            COUNT(CASE WHEN departure_record_id IS NULL THEN 1 END) as unlinked_rides,
            MIN(ride_date) as oldest_record,
            MAX(ride_date) as newest_record
        FROM ride_records
    ";
    $stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC);
    
    echo "<ul>";
    echo "<li>総乗車記録数: " . $stats['total_rides'] . "件</li>";
    echo "<li>関連付けられた運行記録数: " . $stats['linked_operations'] . "件</li>";
    echo "<li>未関連付け乗車記録数: " . $stats['unlinked_rides'] . "件</li>";
    echo "<li>最古の記録: " . $stats['oldest_record'] . "</li>";
    echo "<li>最新の記録: " . $stats['newest_record'] . "</li>";
    echo "</ul>";
    
    echo "<h3 style='color: green;'>✅ テーブル修正が完了しました！</h3>";
    echo "<p><a href='ride_records.php'>修正された乗車記録管理画面を確認する</a></p>";
    
} catch (PDOException $e) {
    echo "<h3 style='color: red;'>❌ エラーが発生しました</h3>";
    echo "<p>エラーメッセージ: " . $e->getMessage() . "</p>";
    echo "<p>ファイル: " . $e->getFile() . "</p>";
    echo "<p>行番号: " . $e->getLine() . "</p>";
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ エラーが発生しました</h3>";
    echo "<p>エラーメッセージ: " . $e->getMessage() . "</p>";
}
?>

<style>
body {
    font-family: 'Hiragino Kaku Gothic Pro', sans-serif;
    margin: 20px;
    background-color: #f8f9fa;
}

h2, h3 {
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 5px;
}

table {
    background-color: white;
    border: 1px solid #dee2e6;
}

th {
    background-color: #e9ecef;
    padding: 8px;
    font-weight: bold;
}

td {
    padding: 6px 8px;
}

p {
    margin: 10px 0;
}

ul {
    background-color: white;
    padding: 15px 30px;
    border-radius: 5px;
    border: 1px solid #dee2e6;
}

li {
    margin: 5px 0;
}
</style>
