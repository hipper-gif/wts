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
    
    echo "<h2>🔍 ユーザー管理画面機能確認ツール</h2>";
    echo "<p><strong>目的:</strong> ユーザー管理画面からrole（権限）の変更が可能かを確認する</p>";
    
    // 1. 現在のユーザー管理画面の存在確認
    echo "<h3>📋 Step 1: ユーザー管理画面の存在確認</h3>";
    
    $user_management_exists = file_exists('user_management.php');
    echo "<p>user_management.php の存在: " . ($user_management_exists ? '✅ 存在する' : '❌ 存在しない') . "</p>";
    
    if ($user_management_exists) {
        echo "<p>🔗 <a href='user_management.php' target='_blank'>ユーザー管理画面を開く</a></p>";
    }
    
    // 2. 現在の問題のあるユーザー一覧
    echo "<h3>👥 Step 2: 権限修正が必要なユーザー</h3>";
    
    // 実際の運転者（調査結果に基づく）
    $real_drivers = [1, 2, 3, 4];
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>名前</th><th>ログインID</th><th>現在の権限</th><th>正しい権限</th><th>状態</th><th>運行記録数</th><th>手動修正リンク</th></tr>";
    
    foreach ($real_drivers as $driver_id) {
        $stmt = $pdo->prepare("SELECT id, name, login_id, role, is_active FROM users WHERE id = ?");
        $stmt->execute([$driver_id]);
        $user = $stmt->fetch();
        
        if ($user) {
            // 運行記録数を取得
            $stmt = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM departure_records WHERE driver_id = ?) +
                    (SELECT COUNT(*) FROM arrival_records WHERE driver_id = ?) +
                    (SELECT COUNT(*) FROM ride_records WHERE driver_id = ?) as total_records
            ");
            $stmt->execute([$driver_id, $driver_id, $driver_id]);
            $record_count = $stmt->fetchColumn();
            
            $current_role = $user['role'] ?: '(空)';
            $needs_fix = ($user['role'] !== '運転者');
            $status_class = $user['is_active'] ? '#e8f5e8' : '#f5e8e8';
            $role_class = $needs_fix ? '#fff3cd' : '#e8f4fd';
            
            echo "<tr style='background-color: $status_class;'>";
            echo "<td><strong>" . $user['id'] . "</strong></td>";
            echo "<td><strong>" . htmlspecialchars($user['name']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($user['login_id']) . "</td>";
            echo "<td style='background-color: $role_class;'>" . htmlspecialchars($current_role) . "</td>";
            echo "<td><strong>運転者</strong></td>";
            echo "<td>" . ($user['is_active'] ? '✅ 有効' : '❌ 無効') . "</td>";
            echo "<td><strong>$record_count 件</strong></td>";
            
            if ($needs_fix) {
                echo "<td><button onclick='fixUserRole(" . $user['id'] . ", \"" . htmlspecialchars($user['name'], ENT_QUOTES) . "\")' style='padding: 5px 10px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer;'>権限を修正</button></td>";
            } else {
                echo "<td>✅ 修正不要</td>";
            }
            
            echo "</tr>";
        }
    }
    echo "</table>";
    
    // 3. 権限変更のためのJavaScript
    echo "<h3>🔧 Step 3: 権限変更機能</h3>";
    
    echo "<script>
    function fixUserRole(userId, userName) {
        if (confirm('「' + userName + '」の権限を「運転者」に変更しますか？')) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=fix_role&user_id=' + userId
            })
            .then(response => response.json())  
            .then(data => {
                if (data.success) {
                    alert('✅ ' + userName + 'の権限を「運転者」に変更しました');
                    location.reload();
                } else {
                    alert('❌ エラー: ' + data.message);
                }
            })
            .catch(error => {
                alert('❌ 通信エラーが発生しました');
                console.error('Error:', error);
            });
        }
    }
    
    function fixAllDrivers() {
        if (confirm('実際の運転者4人の権限をまとめて「運転者」に変更しますか？')) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=fix_all_drivers'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.fixed_count + '人の権限を修正しました');
                    location.reload();
                } else {
                    alert('❌ エラー: ' + data.message);
                }
            })
            .catch(error => {
                alert('❌ 通信エラーが発生しました');
                console.error('Error:', error);
            });
        }
    }
    </script>";
    
    // POSTリクエストの処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'fix_role') {
            $user_id = intval($_POST['user_id'] ?? 0);
            
            if (in_array($user_id, $real_drivers)) {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET role = '運転者' WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    // ユーザー名を取得
                    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user_name = $stmt->fetchColumn();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => $user_name . 'の権限を「運転者」に変更しました'
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'データベースエラー: ' . $e->getMessage()
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => '無効なユーザーIDです'
                ]);
            }
            exit;
            
        } elseif ($action === 'fix_all_drivers') {
            try {
                $fixed_count = 0;
                foreach ($real_drivers as $driver_id) {
                    $stmt = $pdo->prepare("UPDATE users SET role = '運転者' WHERE id = ? AND role != '運転者'");
                    $stmt->execute([$driver_id]);
                    
                    if ($stmt->rowCount() > 0) {
                        $fixed_count++;
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'fixed_count' => $fixed_count,
                    'message' => $fixed_count . '人の権限を修正しました'
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'データベースエラー: ' . $e->getMessage()
                ]);
            }
            exit;
        }
    }
    
    // 4. 一括修正オプション
    echo "<div style='background: #e8f4fd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>🚀 一括修正オプション</h4>";
    echo "<p>実際の運転者4人の権限をまとめて「運転者」に変更できます：</p>";
    echo "<button onclick='fixAllDrivers()' style='padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;'>";
    echo "🔧 実際の運転者4人をまとめて修正";
    echo "</button>";
    echo "</div>";
    
    // 5. 既存のユーザー管理画面の機能確認
    echo "<h3>🖥️ Step 4: 既存のユーザー管理画面の機能</h3>";
    
    if ($user_management_exists) {
        echo "<p>✅ ユーザー管理画面が存在します。以下の方法でも権限変更が可能かもしれません：</p>";
        echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 5px;'>";
        echo "<h4>📋 手動での権限変更手順（推奨）</h4>";
        echo "<ol>";
        echo "<li><a href='user_management.php' target='_blank'><strong>ユーザー管理画面を開く</strong></a></li>";
        echo "<li>対象ユーザー（杉原 星、杉原　充、保田　翔）を見つける</li>";
        echo "<li>各ユーザーの「編集」ボタンをクリック</li>";
        echo "<li>権限を「運転者」に変更</li>";
        echo "<li>保存する</li>";
        echo "</ol>";
        echo "<p><strong>この方法の方が安全で確実です。</strong></p>";
        echo "</div>";
    } else {
        echo "<p>⚠️ ユーザー管理画面が見つかりません。上記の一括修正機能をご利用ください。</p>";
    }
    
    // 6. 修正後の確認方法
    echo "<h3>✅ Step 5: 修正後の確認方法</h3>";
    
    echo "<p>権限修正後、以下の画面で運転者選択リストに<strong>実際の運転者</strong>が表示されることを確認してください：</p>";
    
    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 20px 0;'>";
    
    $test_pages = [
        '出庫処理' => 'departure.php',
        '入庫処理' => 'arrival.php',
        '乗車記録' => 'ride_records.php', 
        '乗務前点呼' => 'pre_duty_call.php',
        '日常点検' => 'daily_inspection.php'
    ];
    
    foreach ($test_pages as $page_name => $file_name) {
        echo "<a href='$file_name' target='_blank' style='display: block; padding: 8px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; text-decoration: none; color: #333; text-align: center;'>";
        echo "🔗 $page_name";
        echo "</a>";
    }
    echo "</div>";
    
    // 7. 現在の権限修正状況の確認
    echo "<h3>📊 Step 6: 現在の状況サマリー</h3>";
    
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN role = '運転者' THEN 1 ELSE 0 END) as drivers,
        SUM(CASE WHEN role = '' OR role IS NULL THEN 1 ELSE 0 END) as empty_roles,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users
    FROM users");
    $stats = $stmt->fetch();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>項目</th><th>数</th><th>状況</th></tr>";
    echo "<tr><td>総ユーザー数</td><td>" . $stats['total_users'] . "</td><td>-</td></tr>";
    echo "<tr><td>権限「運転者」のユーザー</td><td>" . $stats['drivers'] . "</td><td>" . ($stats['drivers'] >= 3 ? '✅ 適正' : '⚠️ 不足') . "</td></tr>";
    echo "<tr><td>権限が空のユーザー</td><td>" . $stats['empty_roles'] . "</td><td>" . ($stats['empty_roles'] > 0 ? '❌ 要修正' : '✅ 正常') . "</td></tr>";
    echo "<tr><td>有効なユーザー</td><td>" . $stats['active_users'] . "</td><td>-</td></tr>";
    echo "</table>";
    
    if ($stats['empty_roles'] > 0) {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>⚠️ 権限が空のユーザーが " . $stats['empty_roles'] . " 人います</h4>";
        echo "<p>これらのユーザーの権限を適切に設定する必要があります。</p>";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div style='background: #f5e8e8; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h4>❌ データベースエラー</h4>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
