<?php
/**
 * データベース構造確認ツール
 * ファイル名: check_db_structure.php
 * 点呼者データがどのテーブルにあるか確認
 */

// エラー表示
error_reporting(E_ALL);
ini_set('display_errors', 1);

// データベース接続
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4',
        'twinklemark_taxi',
        'Smiley2525',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

$action = $_GET['action'] ?? 'all';

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データベース構造確認</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .section { margin: 30px 0; padding: 20px; border: 1px solid #ccc; }
        .btn { padding: 10px 15px; margin: 5px; text-decoration: none; background: #007bff; color: white; border-radius: 4px; }
        .highlight { background-color: #fff3cd; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h1>🔍 データベース構造確認</h1>
    
    <div>
        <a href="?action=all" class="btn">全テーブル確認</a>
        <a href="?action=callers" class="btn">点呼者関連検索</a>
        <a href="?action=sample_data" class="btn">サンプルデータ確認</a>
        <a href="dashboard.php" class="btn" style="background: #6c757d;">ダッシュボード</a>
    </div>

<?php

if ($action === 'all' || $action === '') {
    echo '<div class="section">';
    echo '<h2>📋 全テーブル一覧</h2>';
    
    try {
        // 全テーブル取得
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo '<p>データベース内のテーブル数: <strong>' . count($tables) . '</strong></p>';
        echo '<table>';
        echo '<tr><th>テーブル名</th><th>レコード数</th><th>構造確認</th></tr>';
        
        foreach ($tables as $table) {
            // レコード数取得
            $count_stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
            $count = $count_stmt->fetchColumn();
            
            // 点呼者関連かチェック
            $highlight = (strpos($table, 'call') !== false || 
                         strpos($table, 'caller') !== false || 
                         strpos($table, 'duty') !== false) ? 'class="highlight"' : '';
            
            echo "<tr $highlight>";
            echo "<td><strong>$table</strong></td>";
            echo "<td>$count 件</td>";
            echo "<td><a href='?action=table&name=$table' class='btn' style='padding: 5px 10px; font-size: 12px;'>構造確認</a></td>";
            echo "</tr>";
        }
        echo '</table>';
        
    } catch (Exception $e) {
        echo '<p class="error">エラー: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
}

if ($action === 'callers') {
    echo '<div class="section">';
    echo '<h2>👥 点呼者関連データ検索</h2>';
    
    try {
        // 点呼者関連テーブルをチェック
        $stmt = $pdo->query("SHOW TABLES LIKE '%call%'");
        $call_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($call_tables)) {
            $stmt = $pdo->query("SHOW TABLES");
            $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $call_tables = array_filter($all_tables, function($table) {
                return strpos($table, 'duty') !== false || 
                       strpos($table, 'caller') !== false ||
                       strpos($table, 'staff') !== false ||
                       strpos($table, 'employee') !== false;
            });
        }
        
        echo '<h3>点呼関連テーブル:</h3>';
        foreach ($call_tables as $table) {
            echo "<h4>📊 テーブル: $table</h4>";
            
            // テーブル構造表示
            $struct_stmt = $pdo->query("DESCRIBE `$table`");
            $structure = $struct_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<table>';
            echo '<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>デフォルト</th></tr>';
            foreach ($structure as $col) {
                $highlight = (strpos($col['Field'], 'call') !== false || 
                             strpos($col['Field'], 'name') !== false ||
                             strpos($col['Field'], 'staff') !== false) ? 'class="highlight"' : '';
                echo "<tr $highlight>";
                echo "<td><strong>{$col['Field']}</strong></td>";
                echo "<td>{$col['Type']}</td>";
                echo "<td>{$col['Null']}</td>";
                echo "<td>{$col['Default']}</td>";
                echo "</tr>";
            }
            echo '</table>';
            
            // サンプルデータ表示
            $sample_stmt = $pdo->query("SELECT * FROM `$table` LIMIT 3");
            $samples = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($samples)) {
                echo '<h5>サンプルデータ:</h5>';
                echo '<table>';
                echo '<tr>';
                foreach (array_keys($samples[0]) as $key) {
                    echo "<th>$key</th>";
                }
                echo '</tr>';
                foreach ($samples as $row) {
                    echo '<tr>';
                    foreach ($row as $value) {
                        echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';
            }
        }
        
        // usersテーブルも確認
        echo '<h3>👤 usersテーブル確認:</h3>';
        $users_stmt = $pdo->query("SELECT * FROM users LIMIT 5");
        $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($users)) {
            echo '<table>';
            echo '<tr>';
            foreach (array_keys($users[0]) as $key) {
                echo "<th>$key</th>";
            }
            echo '</tr>';
            foreach ($users as $row) {
                echo '<tr>';
                foreach ($row as $value) {
                    echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        }
        
        // 点呼者名の検索
        echo '<h3>🔍 点呼者名検索（全テーブル）:</h3>';
        $caller_names = [];
        
        foreach ($call_tables as $table) {
            try {
                // caller_name, call_name, staff_name などのカラムを探す
                $struct_stmt = $pdo->query("DESCRIBE `$table`");
                $columns = $struct_stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $name_columns = array_filter($columns, function($col) {
                    return strpos($col, 'call') !== false && strpos($col, 'name') !== false ||
                           strpos($col, 'staff') !== false ||
                           strpos($col, 'inspector') !== false ||
                           strpos($col, 'checker') !== false;
                });
                
                foreach ($name_columns as $col) {
                    $name_stmt = $pdo->query("SELECT DISTINCT `$col` FROM `$table` WHERE `$col` IS NOT NULL AND `$col` != ''");
                    $names = $name_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($names)) {
                        $caller_names[$table . '.' . $col] = $names;
                    }
                }
            } catch (Exception $e) {
                echo "<p class='error'>テーブル $table でエラー: " . $e->getMessage() . "</p>";
            }
        }
        
        if (!empty($caller_names)) {
            echo '<table>';
            echo '<tr><th>テーブル.カラム</th><th>点呼者名</th></tr>';
            foreach ($caller_names as $source => $names) {
                echo '<tr>';
                echo "<td><strong>$source</strong></td>";
                echo '<td>' . implode(', ', $names) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="error">点呼者名が見つかりませんでした。</p>';
        }
        
    } catch (Exception $e) {
        echo '<p class="error">エラー: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
}

if ($action === 'table' && isset($_GET['name'])) {
    $table_name = $_GET['name'];
    echo '<div class="section">';
    echo "<h2>🔍 テーブル詳細: $table_name</h2>";
    
    try {
        // テーブル構造
        $struct_stmt = $pdo->query("DESCRIBE `$table_name`");
        $structure = $struct_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<h3>テーブル構造:</h3>';
        echo '<table>';
        echo '<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>キー</th><th>デフォルト</th><th>その他</th></tr>';
        foreach ($structure as $col) {
            echo '<tr>';
            echo "<td><strong>{$col['Field']}</strong></td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>{$col['Default']}</td>";
            echo "<td>{$col['Extra']}</td>";
            echo '</tr>';
        }
        echo '</table>';
        
        // サンプルデータ
        $count_stmt = $pdo->query("SELECT COUNT(*) FROM `$table_name`");
        $count = $count_stmt->fetchColumn();
        
        echo "<h3>データ件数: $count 件</h3>";
        
        if ($count > 0) {
            $sample_stmt = $pdo->query("SELECT * FROM `$table_name` LIMIT 10");
            $samples = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<h3>サンプルデータ（最大10件）:</h3>';
            echo '<table>';
            echo '<tr>';
            foreach (array_keys($samples[0]) as $key) {
                echo "<th>$key</th>";
            }
            echo '</tr>';
            foreach ($samples as $row) {
                echo '<tr>';
                foreach ($row as $value) {
                    echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        }
        
    } catch (Exception $e) {
        echo '<p class="error">エラー: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
}

if ($action === 'sample_data') {
    echo '<div class="section">';
    echo '<h2>📝 点呼記録のサンプルデータ</h2>';
    
    try {
        // 最新の点呼記録を確認
        $tables_to_check = ['pre_duty_calls', 'post_duty_calls', 'daily_inspections'];
        
        foreach ($tables_to_check as $table) {
            try {
                $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY id DESC LIMIT 3");
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($records)) {
                    echo "<h3>📋 $table の最新データ:</h3>";
                    echo '<table>';
                    echo '<tr>';
                    foreach (array_keys($records[0]) as $key) {
                        echo "<th>$key</th>";
                    }
                    echo '</tr>';
                    foreach ($records as $row) {
                        echo '<tr>';
                        foreach ($row as $key => $value) {
                            // 点呼者関連のカラムをハイライト
                            $highlight = (strpos($key, 'call') !== false || 
                                         strpos($key, 'name') !== false ||
                                         strpos($key, 'staff') !== false) ? 'class="highlight"' : '';
                            echo "<td $highlight>" . htmlspecialchars($value ?? '') . '</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</table>';
                }
            } catch (Exception $e) {
                echo "<p>テーブル $table: 存在しないか、アクセスできません</p>";
            }
        }
        
    } catch (Exception $e) {
        echo '<p class="error">エラー: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
}

?>

<div class="section">
    <h2>💡 次のステップ</h2>
    <ol>
        <li><strong>点呼者関連検索</strong>をクリックして、点呼者データがどこにあるか確認</li>
        <li>点呼者名が格納されているテーブル・カラムを特定</li>
        <li>そのテーブルから点呼者リストを取得するコードに修正</li>
        <li><code>pre_duty_call.php</code>と<code>post_duty_call.php</code>のコードを適切に修正</li>
    </ol>
    
    <h3>よくある点呼者データの場所:</h3>
    <ul>
        <li><code>callers</code>テーブル</li>
        <li><code>staff</code>テーブル</li>
        <li><code>employees</code>テーブル</li>
        <li><code>pre_duty_calls.caller_name</code>カラムの履歴データ</li>
        <li><code>system_settings</code>テーブルの設定値</li>
    </ul>
</div>

</body>
</html>
