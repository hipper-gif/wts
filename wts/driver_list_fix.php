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
    
    echo "<h2>🔧 運転者データ修正スクリプト</h2>";
    
    $actions_performed = [];
    
    // 1. 現在の状況確認
    echo "<h3>📋 修正前の状況確認</h3>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = '運転者'");
    $driver_count_before = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = '運転者' AND is_active = 1");
    $active_driver_count_before = $stmt->fetchColumn();
    
    echo "<p>運転者権限ユーザー: <strong>$driver_count_before</strong> 人</p>";
    echo "<p>有効な運転者: <strong>$active_driver_count_before</strong> 人</p>";
    
    // 2. 無効になっている運転者を有効化
    echo "<h3>✅ Step 1: 無効な運転者の有効化</h3>";
    
    $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE role = '運転者' AND (is_active = 0 OR is_active IS NULL)");
    $stmt->execute();
    $activated_count = $stmt->rowCount();
    
    if ($activated_count > 0) {
        $actions_performed[] = "無効な運転者を有効化: $activated_count 人";
        echo "<p>✅ $activated_count 人の運転者を有効化しました</p>";
    } else {
        echo "<p>📝 有効化が必要な運転者はいませんでした</p>";
    }
    
    // 3. 権限値の正規化
    echo "<h3>🔄 Step 2: 権限値の正規化</h3>";
    
    // 運転者に類似する権限を正規化
    $similar_roles = [
        '運転者 ', ' 運転者', ' 運転者 ', '運転手', 'ドライバー', 'driver', 'Driver'
    ];
    
    $normalized_count = 0;
    foreach ($similar_roles as $role) {
        $stmt = $pdo->prepare("UPDATE users SET role = '運転者' WHERE role = ?");
        $stmt->execute([$role]);
        $normalized_count += $stmt->rowCount();
    }
    
    if ($normalized_count > 0) {
        $actions_performed[] = "権限値を正規化: $normalized_count 件";
        echo "<p>✅ $normalized_count 件の権限を '運転者' に正規化しました</p>";
    } else {
        echo "<p>📝 正規化が必要な権限はありませんでした</p>";
    }
    
    // 4. 現在の運転者数確認
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = '運転者' AND is_active = 1");
    $current_active_drivers = $stmt->fetchColumn();
    
    echo "<h3>👥 Step 3: 運転者数の確認・補充</h3>";
    echo "<p>現在の有効な運転者数: <strong>$current_active_drivers</strong> 人</p>";
    
    // 5. 運転者が不足している場合の補充
    if ($current_active_drivers < 2) {
        echo "<p>⚠️ 運転者が不足しています。デフォルト運転者を作成します。</p>";
        
        $default_drivers = [
            ['name' => '運転者A', 'login_id' => 'driver1', 'password' => 'driver123'],
            ['name' => '運転者B', 'login_id' => 'driver2', 'password' => 'driver123']
        ];
        
        $created_count = 0;
        foreach ($default_drivers as $driver_data) {
            // 既存チェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ?");
            $stmt->execute([$driver_data['login_id']]);
            $exists = $stmt->fetchColumn();
            
            if ($exists == 0) {
                $hashed_password = password_hash($driver_data['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, login_id, password, role, is_active, created_at) VALUES (?, ?, ?, '運転者', 1, NOW())");
                $stmt->execute([$driver_data['name'], $driver_data['login_id'], $hashed_password]);
                $created_count++;
                echo "<p>✅ 運転者を作成: " . $driver_data['name'] . " (ログインID: " . $driver_data['login_id'] . ")</p>";
            }
        }
        
        if ($created_count > 0) {
            $actions_performed[] = "デフォルト運転者を作成: $created_count 人";
        }
    }
    
    // 6. 既存ユーザーの権限確認・修正
    echo "<h3>🔍 Step 4: 既存ユーザーの権限確認</h3>";
    
    $stmt = $pdo->query("SELECT id, name, login_id, role FROM users WHERE is_active = 1 ORDER BY created_at");
    $all_users = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>名前</th><th>ログインID</th><th>現在の権限</th><th>アクション</th></tr>";
    
    $role_changes = 0;
    foreach ($all_users as $user) {
        echo "<tr>";
        echo "<td>" . $user['id'] . "</td>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td>" . htmlspecialchars($user['login_id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['role']) . "</td>";
        
        // 名前に基づく権限の推測と修正
        $suggested_role = $user['role'];
        $action = "変更なし";
        
        if (strpos($user['name'], '運転') !== false || strpos($user['login_id'], 'driver') !== false) {
            if ($user['role'] !== '運転者') {
                $suggested_role = '運転者';
                $action = "運転者に変更";
                
                $stmt = $pdo->prepare("UPDATE users SET role = '運転者' WHERE id = ?");
                $stmt->execute([$user['id']]);
                $role_changes++;
            }
        } elseif (strpos($user['name'], 'admin') !== false || strpos($user['login_id'], 'admin') !== false) {
            if ($user['role'] !== 'システム管理者') {
                $suggested_role = 'システム管理者';
                $action = "システム管理者に変更";
                
                $stmt = $pdo->prepare("UPDATE users SET role = 'システム管理者' WHERE id = ?");
                $stmt->execute([$user['id']]);
                $role_changes++;
            }
        }
        
        echo "<td><strong>$action</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if ($role_changes > 0) {
        $actions_performed[] = "名前に基づく権限修正: $role_changes 件";
    }
    
    // 7. 最終確認
    echo "<h3>📊 修正後の状況確認</h3>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = '運転者' AND is_active = 1");
    $final_driver_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT id, name, login_id FROM users WHERE role = '運転者' AND is_active = 1 ORDER BY name");
    $final_drivers = $stmt->fetchAll();
    
    echo "<p><strong>最終的な有効運転者数: $final_driver_count 人</strong></p>";
    
    if (count($final_drivers) > 0) {
        echo "<h4>📋 有効な運転者一覧:</h4>";
        echo "<ul>";
        foreach ($final_drivers as $driver) {
            echo "<li>✅ <strong>" . htmlspecialchars($driver['name']) . "</strong> (ID: " . $driver['id'] . ", ログインID: " . htmlspecialchars($driver['login_id']) . ")</li>";
        }
        echo "</ul>";
        
        // 8. 実際のセレクトボックステスト
        echo "<h3>🧪 実際のセレクトボックステスト</h3>";
        echo "<h4>修正後の運転者選択リスト:</h4>";
        echo "<select style='padding: 8px; margin: 10px; font-size: 14px; border: 2px solid #007cba; border-radius: 5px;'>";
        echo "<option value=''>運転者を選択してください</option>";
        
        foreach ($final_drivers as $driver) {
            echo "<option value='" . $driver['id'] . "'>" . htmlspecialchars($driver['name']) . "</option>";
        }
        echo "</select>";
        
        echo "<p>✅ <strong>" . count($final_drivers) . " 人の運転者</strong>がセレクトボックスに表示されます。</p>";
        
    } else {
        echo "<div style='background: #f5e8e8; padding: 15px; border-radius: 5px;'>";
        echo "<h4>❌ まだ運転者が見つかりません</h4>";
        echo "<p>手動で運転者を作成する必要があります。</p>";
        echo "</div>";
    }
    
    // 9. JavaScriptテスト用のコード生成
    echo "<h3>💻 JavaScriptテスト用コード</h3>";
    echo "<div style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
    echo "<h4>運転者選択リスト生成用JavaScript:</h4>";
    echo "<pre style='background: white; padding: 10px; border-radius: 3px; overflow-x: auto;'>";
    echo htmlspecialchars('
// 運転者データ取得用AJAX
function loadDrivers(selectElementId) {
    fetch("api/get_drivers.php")
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById(selectElementId);
            select.innerHTML = "<option value=\"\">運転者を選択してください</option>";
            
            data.drivers.forEach(driver => {
                const option = document.createElement("option");
                option.value = driver.id;
                option.textContent = driver.name;
                select.appendChild(option);
            });
        })
        .catch(error => {
            console.error("運転者データの取得に失敗:", error);
        });
}

// PHP側のAPI (api/get_drivers.php)
<?php
$stmt = $pdo->prepare("SELECT id, name FROM users WHERE role = \'運転者\' AND is_active = 1 ORDER BY name");
$stmt->execute();
$drivers = $stmt->fetchAll();

header("Content-Type: application/json; charset=utf-8");
echo json_encode(["drivers" => $drivers]);
?>
    ');
    echo "</pre>";
    echo "</div>";
    
    // 10. 実行サマリー
    echo "<h3>📋 実行サマリー</h3>";
    
    if (!empty($actions_performed)) {
        echo "<ul>";
        foreach ($actions_performed as $action) {
            echo "<li>✅ $action</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>📝 特に修正が必要な項目はありませんでした。</p>";
    }
    
    // 11. 各画面での確認リンク
    echo "<h3>🚀 各画面での動作確認</h3>";
    echo "<p>以下のリンクで運転者選択リストが正常に表示されるか確認してください：</p>";
    echo "<ul>";
    echo "<li><a href='departure.php' target='_blank'>🚗 出庫処理</a></li>";
    echo "<li><a href='arrival.php' target='_blank'>🏠 入庫処理</a></li>";
    echo "<li><a href='ride_records.php' target='_blank'>📋 乗車記録</a></li>";
    echo "<li><a href='pre_duty_call.php' target='_blank'>📞 乗務前点呼</a></li>";
    echo "<li><a href='post_duty_call.php' target='_blank'>📞 乗務後点呼</a></li>";
    echo "<li><a href='daily_inspection.php' target='_blank'>🔧 日常点検</a></li>";
    echo "</ul>";
    
    // 最終結果
    if ($final_driver_count > 0) {
        echo "<div style='background: #e8f5e8; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h4>✅ 修正完了</h4>";
        echo "<p><strong>$final_driver_count 人の運転者</strong>が利用可能になりました。</p>";
        echo "<p>各画面の運転者選択リストに運転者が表示されるはずです。</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #f5e8e8; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
        echo "<h4>⚠️ 追加作業が必要</h4>";
        echo "<p>運転者データの修正が完了しませんでした。</p>";
        echo "<p>手動でユーザー管理画面から運転者を作成してください。</p>";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div style='background: #f5e8e8; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h4>❌ データベースエラー</h4>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
