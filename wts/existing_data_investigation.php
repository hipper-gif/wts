<?php
session_start();

// データベース接続
$host = 'localhost';
$dbname = 'twinklemark_wts';
$username = 'twinklemark_taxi';
$password = 'Smiley2525';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "<h2>🔍 既存データ調査ツール</h2>";
    echo "<p><strong>目的:</strong> 既存の運転者データがなぜ見えなくなったかを特定する</p>";
    
    // 1. データベーステーブル構造の確認
    echo "<h3>📊 Step 1: データベーステーブル構造確認</h3>";
    
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>存在するテーブル数:</strong> " . count($tables) . "</p>";
    echo "<details><summary>📋 全テーブル一覧（クリックで展開）</summary><ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul></details>";
    
    // usersテーブルの構造確認
    if (in_array('users', $tables)) {
        echo "<h4>👥 usersテーブル構造</h4>";
        $stmt = $pdo->query("DESCRIBE users");
        $columns = $stmt->fetchAll();
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>カラム名</th><th>データ型</th><th>NULL可</th><th>キー</th><th>デフォルト値</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td><strong>" . $column['Field'] . "</strong></td>";
            echo "<td>" . $column['Type'] . "</td>";
            echo "<td>" . $column['Null'] . "</td>";
            echo "<td>" . $column['Key'] . "</td>";
            echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ <strong>usersテーブルが存在しません！</strong></p>";
    }
    
    // 2. 全ユーザーデータの生データ確認
    echo "<h3>📋 Step 2: 全ユーザーデータ（生データ）</h3>";
    
    $stmt = $pdo->query("SELECT * FROM users ORDER BY id");
    $all_users_raw = $stmt->fetchAll();
    
    echo "<p><strong>総ユーザー数:</strong> " . count($all_users_raw) . "</p>";
    
    if (count($all_users_raw) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
        echo "<tr>";
        
        // ヘッダー行
        foreach (array_keys($all_users_raw[0]) as $column) {
            echo "<th>$column</th>";
        }
        echo "</tr>";
        
        // データ行
        foreach ($all_users_raw as $user) {
            echo "<tr>";
            foreach ($user as $key => $value) {
                $display_value = $value;
                
                // 特殊な値の処理
                if ($key === 'password') {
                    $display_value = '***（パスワードハッシュ）';
                } elseif ($key === 'role') {
                    $display_value = "<strong style='color: blue;'>" . htmlspecialchars($value) . "</strong>";
                } elseif ($key === 'is_active') {
                    $display_value = $value ? '✅ 1 (有効)' : '❌ 0 (無効)';
                } elseif (is_null($value)) {
                    $display_value = '<em style="color: gray;">NULL</em>';
                } else {
                    $display_value = htmlspecialchars($value);
                }
                
                echo "<td>$display_value</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ <strong>ユーザーデータが存在しません！</strong></p>";
    }
    
    // 3. 権限値の詳細分析
    echo "<h3>🔍 Step 3: 権限値の詳細分析</h3>";
    
    $stmt = $pdo->query("SELECT role, COUNT(*) as count, GROUP_CONCAT(name SEPARATOR ', ') as users FROM users GROUP BY role");
    $role_analysis = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>権限値</th><th>ユーザー数</th><th>該当ユーザー</th><th>バイト表現</th></tr>";
    
    foreach ($role_analysis as $role_data) {
        $role = $role_data['role'];
        $byte_representation = '';
        
        // バイト表現を生成
        for ($i = 0; $i < strlen($role); $i++) {
            $byte_representation .= ord($role[$i]) . ' ';
        }
        
        $is_driver = ($role === '運転者');
        $row_style = $is_driver ? 'background-color: #e8f4fd; font-weight: bold;' : '';
        
        echo "<tr style='$row_style'>";
        echo "<td>" . htmlspecialchars($role) . "</td>";
        echo "<td>" . $role_data['count'] . "</td>";
        echo "<td>" . htmlspecialchars($role_data['users']) . "</td>";
        echo "<td style='font-family: monospace; font-size: 11px;'>$byte_representation</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 4. 運転者クエリの段階的テスト
    echo "<h3>🧪 Step 4: 運転者検索クエリの段階的テスト</h3>";
    
    $test_queries = [
        "完全一致" => "SELECT id, name, role, is_active FROM users WHERE role = '運転者'",
        "トリム付き完全一致" => "SELECT id, name, role, is_active FROM users WHERE TRIM(role) = '運転者'",
        "LIKE検索" => "SELECT id, name, role, is_active FROM users WHERE role LIKE '%運転者%'",
        "有効フラグ無視" => "SELECT id, name, role, is_active FROM users WHERE role = '運転者' ORDER BY is_active DESC",
        "文字コード指定" => "SELECT id, name, role, is_active FROM users WHERE role = _utf8mb4'運転者' COLLATE utf8mb4_unicode_ci",
        "バイナリ比較" => "SELECT id, name, role, is_active FROM users WHERE BINARY role = '運転者'"
    ];
    
    foreach ($test_queries as $test_name => $query) {
        echo "<h4>🔍 テスト: $test_name</h4>";
        echo "<p><code style='background: #f5f5f5; padding: 2px 5px;'>$query</code></p>";
        
        try {
            $stmt = $pdo->query($query);
            $results = $stmt->fetchAll();
            
            if (count($results) > 0) {
                echo "<p>✅ <strong>" . count($results) . "件</strong> 該当</p>";
                echo "<ul>";
                foreach ($results as $result) {
                    $active_status = $result['is_active'] ? '有効' : '無効';
                    echo "<li>" . htmlspecialchars($result['name']) . " (ID: " . $result['id'] . ", 状態: $active_status)</li>";
                }
                echo "</ul>";
            } else {
                echo "<p>❌ 該当なし</p>";
            }
        } catch (Exception $e) {
            echo "<p>❌ クエリエラー: " . $e->getMessage() . "</p>";
        }
    }
    
    // 5. 過去の運行記録からの運転者特定
    echo "<h3>📊 Step 5: 過去の運行記録から運転者を特定</h3>";
    
    $operational_tables = ['departure_records', 'arrival_records', 'ride_records', 'daily_operations', 'pre_duty_calls'];
    $found_drivers = [];
    
    foreach ($operational_tables as $table) {
        if (in_array($table, $tables)) {
            echo "<h4>📋 {$table}テーブルから運転者ID抽出</h4>";
            
            try {
                // driver_idカラムが存在するかチェック
                $stmt = $pdo->query("DESCRIBE $table");
                $table_columns = $stmt->fetchAll();
                $has_driver_id = false;
                
                foreach ($table_columns as $column) {
                    if ($column['Field'] === 'driver_id') {
                        $has_driver_id = true;
                        break;
                    }
                }
                
                if ($has_driver_id) {
                    $stmt = $pdo->query("SELECT DISTINCT driver_id, COUNT(*) as record_count FROM $table WHERE driver_id IS NOT NULL GROUP BY driver_id ORDER BY record_count DESC");
                    $driver_usage = $stmt->fetchAll();
                    
                    if (count($driver_usage) > 0) {
                        echo "<ul>";
                        foreach ($driver_usage as $usage) {
                            $driver_id = $usage['driver_id'];
                            $record_count = $usage['record_count'];
                            
                            // このdriver_idに対応するユーザー情報を取得
                            $stmt = $pdo->prepare("SELECT name, role, is_active FROM users WHERE id = ?");
                            $stmt->execute([$driver_id]);
                            $user_info = $stmt->fetch();
                            
                            if ($user_info) {
                                $status = $user_info['is_active'] ? '有効' : '無効';
                                echo "<li>運転者ID: $driver_id → <strong>" . htmlspecialchars($user_info['name']) . "</strong> (権限: " . htmlspecialchars($user_info['role']) . ", 状態: $status, 記録数: $record_count)</li>";
                                
                                if (!in_array($driver_id, $found_drivers)) {
                                    $found_drivers[] = $driver_id;
                                }
                            } else {
                                echo "<li>運転者ID: $driver_id → ❌ <strong>ユーザー情報なし</strong> (記録数: $record_count)</li>";
                            }
                        }
                        echo "</ul>";
                    } else {
                        echo "<p>📝 運転者IDの記録なし</p>";
                    }
                } else {
                    echo "<p>⚠️ driver_idカラムが存在しません</p>";
                }
            } catch (Exception $e) {
                echo "<p>❌ テーブルアクセスエラー: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<h4>❌ {$table}テーブルが存在しません</h4>";
        }
    }
    
    // 6. 実際に使用されている運転者の復旧案
    echo "<h3>🔧 Step 6: 実際の運転者データ復旧案</h3>";
    
    if (!empty($found_drivers)) {
        echo "<p>📊 過去の記録から <strong>" . count($found_drivers) . "人</strong> の実際の運転者を特定しました。</p>";
        
        echo "<h4>復旧対象の運転者:</h4>";
        echo "<ul>";
        foreach ($found_drivers as $driver_id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$driver_id]);
            $driver = $stmt->fetch();
            
            if ($driver) {
                $issues = [];
                $fixes = [];
                
                if ($driver['role'] !== '運転者') {
                    $issues[] = "権限が '{$driver['role']}' になっている";
                    $fixes[] = "権限を '運転者' に変更";
                }
                
                if (!$driver['is_active']) {
                    $issues[] = "無効になっている";
                    $fixes[] = "有効に変更";
                }
                
                echo "<li><strong>" . htmlspecialchars($driver['name']) . "</strong> (ID: $driver_id)";
                
                if (!empty($issues)) {
                    echo "<br>　問題: " . implode(', ', $issues);
                    echo "<br>　修正: " . implode(', ', $fixes);
                } else {
                    echo "<br>　✅ 正常";
                }
                echo "</li>";
            }
        }
        echo "</ul>";
        
        echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>💡 推奨アクション</h4>";
        echo "<p>自動作成した運転者を削除し、実際に使用されている運転者を復旧させることをお勧めします。</p>";
        echo "<ol>";
        echo "<li>自動作成された運転者（運転者A、運転者B）を削除</li>";
        echo "<li>実際の運転者の権限と有効フラグを修正</li>";
        echo "<li>各画面で運転者選択リストを確認</li>";
        echo "</ol>";
        echo "</div>";
        
    } else {
        echo "<p>⚠️ 過去の運行記録から運転者を特定できませんでした。</p>";
        echo "<p>この場合、以下の可能性があります：</p>";
        echo "<ul>";
        echo "<li>システムが新規導入で、まだ運行記録がない</li>";
        echo "<li>データベースの整合性に問題がある</li>";
        echo "<li>テーブル構造が変更されている</li>";
        echo "</ul>";
    }
    
    // 7. 次のアクション提案
    echo "<h3>🚀 次のアクション</h3>";
    
    if (!empty($found_drivers)) {
        echo "<p><strong>実際の運転者データが見つかりました！</strong></p>";
        echo "<ol>";
        echo "<li><a href='restore_real_drivers.php' target='_blank'>🔧 実際の運転者を復旧する</a></li>";
        echo "<li><a href='cleanup_auto_created.php' target='_blank'>🗑️ 自動作成された運転者を削除する</a></li>";
        echo "<li>各画面で運転者選択リストを確認する</li>";
        echo "</ol>";
    } else {
        echo "<p>実際の運転者データが見つからなかった場合：</p>";
        echo "<ol>";
        echo "<li>システム管理者に確認：本当に既存の運転者データがあったか？</li>";
        echo "<li>バックアップから復旧を検討</li>";
        echo "<li>最終手段として手動で運転者を作成</li>";
        echo "</ol>";
    }
    
} catch (PDOException $e) {
    echo "<div style='background: #f5e8e8; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h4>❌ データベース接続エラー</h4>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
