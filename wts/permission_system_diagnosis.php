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
    
    echo "<h2>🔍 権限管理システム診断ツール</h2>";
    echo "<p><strong>問題:</strong> user_management.phpでの権限変更が反映されない</p>";
    echo "<p><strong>目標:</strong> 柔軟なチェックボックス方式の権限管理システムを実装</p>";
    
    // 1. 現在の権限管理構造の分析
    echo "<h3>📊 Step 1: 現在の権限管理構造の分析</h3>";
    
    $stmt = $pdo->query("DESCRIBE users");
    $user_columns = $stmt->fetchAll();
    
    $permission_columns = [];
    foreach ($user_columns as $column) {
        if (in_array($column['Field'], ['role', 'is_driver', 'is_caller', 'is_admin', 'is_manager', 'permissions'])) {
            $permission_columns[] = $column;
        }
    }
    
    echo "<h4>🗂️ 権限関連カラムの確認</h4>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>カラム名</th><th>データ型</th><th>説明</th><th>問題点</th></tr>";
    
    foreach ($permission_columns as $column) {
        $field = $column['Field'];
        $type = $column['Type'];
        
        $description = '';
        $issue = '';
        
        switch ($field) {
            case 'role':
                $description = 'ENUM型の主権限（システム管理者、管理者、運転者、点呼者）';
                $issue = 'ENUM型で固定的、柔軟性がない';
                break;
            case 'is_driver':
                $description = '運転者フラグ（0/1）';
                $issue = 'roleとの整合性が取れていない';
                break;
            case 'is_caller':
                $description = '点呼者フラグ（0/1）';
                $issue = 'roleとの整合性が取れていない';
                break;
            case 'is_admin':
                $description = '管理者フラグ（0/1）';
                $issue = 'roleとの整合性が取れていない';
                break;
            case 'is_manager':
                $description = 'マネージャーフラグ（0/1）';
                $issue = 'roleとの整合性が取れていない';
                break;
            case 'permissions':
                $description = 'JSON形式の詳細権限';
                $issue = '他の権限カラムとの整合性が不明';
                break;
        }
        
        echo "<tr>";
        echo "<td><strong>$field</strong></td>";
        echo "<td>$type</td>";
        echo "<td>$description</td>";
        echo "<td style='color: red;'>$issue</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. 実際のユーザーデータでの権限の矛盾確認
    echo "<h3>⚠️ Step 2: 権限データの矛盾確認</h3>";
    
    $stmt = $pdo->query("SELECT id, name, role, is_driver, is_caller, is_admin, is_manager, permissions FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
    echo "<tr><th>ID</th><th>名前</th><th>role</th><th>is_driver</th><th>is_caller</th><th>is_admin</th><th>is_manager</th><th>permissions</th><th>矛盾チェック</th></tr>";
    
    foreach ($users as $user) {
        $inconsistencies = [];
        
        // roleとフラグの矛盾をチェック
        if ($user['role'] === '運転者' && !$user['is_driver']) {
            $inconsistencies[] = 'roleは運転者だがis_driver=0';
        }
        if ($user['role'] !== '運転者' && $user['is_driver']) {
            $inconsistencies[] = 'roleは運転者でないがis_driver=1';
        }
        if ($user['role'] === 'システム管理者' && !$user['is_admin']) {
            $inconsistencies[] = 'roleはシステム管理者だがis_admin=0';
        }
        if ($user['role'] === '' && ($user['is_driver'] || $user['is_caller'] || $user['is_admin'])) {
            $inconsistencies[] = 'roleは空だがフラグが設定されている';
        }
        
        $inconsistency_display = empty($inconsistencies) ? '✅ 整合性OK' : '❌ ' . implode('<br>', $inconsistencies);
        $row_color = empty($inconsistencies) ? '#e8f5e8' : '#f5e8e8';
        
        echo "<tr style='background-color: $row_color;'>";
        echo "<td><strong>" . $user['id'] . "</strong></td>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td>" . htmlspecialchars($user['role'] ?: '(空)') . "</td>";
        echo "<td>" . ($user['is_driver'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($user['is_caller'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($user['is_admin'] ? '✅' : '❌') . "</td>";
        echo "<td>" . ($user['is_manager'] ? '✅' : '❌') . "</td>";
        echo "<td style='max-width: 150px; overflow: hidden; text-overflow: ellipsis;'>" . 
             (empty($user['permissions']) ? '(空)' : '設定あり') . "</td>";
        echo "<td>$inconsistency_display</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 3. 運転者選択リストの現在のロジック確認
    echo "<h3>🚗 Step 3: 運転者選択リストの問題</h3>";
    
    echo "<h4>現在のクエリでの結果:</h4>";
    
    $queries = [
        "role基準" => "SELECT id, name FROM users WHERE role = '運転者' AND is_active = 1",
        "is_driver基準" => "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1",
        "両方の条件" => "SELECT id, name FROM users WHERE (role = '運転者' OR is_driver = 1) AND is_active = 1"
    ];
    
    foreach ($queries as $query_name => $query) {
        echo "<h5>🔍 $query_name</h5>";
        echo "<p><code>$query</code></p>";
        
        $stmt = $pdo->query($query);
        $results = $stmt->fetchAll();
        
        echo "<p><strong>結果: " . count($results) . "人</strong></p>";
        if (count($results) > 0) {
            echo "<ul>";
            foreach ($results as $result) {
                echo "<li>" . htmlspecialchars($result['name']) . " (ID: " . $result['id'] . ")</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>❌ 該当者なし</p>";
        }
    }
    
    // 4. 改善提案
    echo "<h3>💡 Step 4: 改善提案</h3>";
    
    echo "<div style='background: #e8f4fd; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
    echo "<h4>🎯 推奨される改善策</h4>";
    
    echo "<h5>1. 柔軟なチェックボックス権限システム</h5>";
    echo "<ul>";
    echo "<li><strong>is_driver:</strong> 運転者権限（運転業務、出庫・入庫・乗車記録）</li>";
    echo "<li><strong>is_caller:</strong> 点呼者権限（点呼記録の作成・確認）</li>";
    echo "<li><strong>is_admin:</strong> システム管理者権限（全機能アクセス）</li>";
    echo "<li><strong>is_manager:</strong> 管理者権限（ユーザー・車両管理）</li>";
    echo "</ul>";
    
    echo "<h5>2. 運転者選択リストの改善</h5>";
    echo "<p><code>SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1</code></p>";
    echo "<p>→ roleではなく<strong>is_driver</strong>フラグを基準にする</p>";
    
    echo "<h5>3. 既存データの修正</h5>";
    echo "<p>実際の運転者（杉原さん、保田さんなど）の<strong>is_driver = 1</strong>に設定</p>";
    
    echo "</div>";
    
    // 5. 即座実行可能な修正
    echo "<h3>🔧 Step 5: 即座実行可能な修正</h3>";
    
    echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 5px;'>";
    echo "<h4>実際の運転者のis_driverフラグを修正:</h4>";
    echo "<button onclick='fixDriverFlags()' style='padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 10px;'>";
    echo "🚗 実際の運転者のis_driverを1に設定";
    echo "</button>";
    echo "</div>";
    
    echo "<script>
    function fixDriverFlags() {
        if (confirm('実際の運転者4人（杉原 星、杉原　充、服部　優佑、保田　翔）のis_driverフラグを1に設定しますか？')) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=fix_driver_flags'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ エラー: ' + data.message);
                }
            });
        }
    }
    </script>";
    
    // POSTリクエスト処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        
        if ($_POST['action'] === 'fix_driver_flags') {
            try {
                $real_drivers = [1, 2, 3, 4]; // 杉原 星、杉原　充、服部　優佑、保田　翔
                $fixed_count = 0;
                
                foreach ($real_drivers as $driver_id) {
                    $stmt = $pdo->prepare("UPDATE users SET is_driver = 1 WHERE id = ?");
                    $stmt->execute([$driver_id]);
                    $fixed_count++;
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => $fixed_count . '人の運転者のis_driverフラグを1に設定しました'
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'エラー: ' . $e->getMessage()
                ]);
            }
            exit;
        }
    }
    
    // 6. 次のステップ
    echo "<h3>🚀 次のステップ</h3>";
    
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
    echo "<h4>📋 実行順序</h4>";
    echo "<ol>";
    echo "<li><strong>is_driverフラグ修正</strong> - 上記ボタンで実行</li>";
    echo "<li><strong>改善されたユーザー管理画面作成</strong> - チェックボックス方式</li>";
    echo "<li><strong>運転者選択リスト修正</strong> - is_driver基準に変更</li>";
    echo "<li><strong>各画面での動作確認</strong></li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<p><strong>まずは上記の「is_driverフラグ修正」を実行してください。</strong></p>";
    echo "<p>その後、改善されたユーザー管理システムを作成します。</p>";
    
} catch (PDOException $e) {
    echo "<div style='background: #f5e8e8; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h4>❌ データベースエラー</h4>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
