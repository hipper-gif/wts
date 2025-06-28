<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h3>データベース確認とデバッグ</h3>";
    
    // 既存データの確認
    echo "<h4>既存ユーザー確認</h4>";
    $stmt = $pdo->query("SELECT id, login_id, name, role, is_active FROM users ORDER BY role, name");
    $existing_users = $stmt->fetchAll();
    
    if (empty($existing_users)) {
        echo "<p style='color: orange;'>⚠️ ユーザーデータがありません</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>ログインID</th><th>名前</th><th>権限</th><th>有効</th></tr>";
        foreach ($existing_users as $user) {
            $active = $user['is_active'] ? '有効' : '無効';
            echo "<tr><td>{$user['id']}</td><td>{$user['login_id']}</td><td>{$user['name']}</td><td>{$user['role']}</td><td>{$active}</td></tr>";
        }
        echo "</table>";
    }
    
    echo "<h4>既存車両確認</h4>";
    $stmt = $pdo->query("SELECT id, vehicle_number, model, is_active FROM vehicles ORDER BY vehicle_number");
    $existing_vehicles = $stmt->fetchAll();
    
    if (empty($existing_vehicles)) {
        echo "<p style='color: orange;'>⚠️ 車両データがありません</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>車両番号</th><th>車種</th><th>有効</th></tr>";
        foreach ($existing_vehicles as $vehicle) {
            $active = $vehicle['is_active'] ? '有効' : '無効';
            echo "<tr><td>{$vehicle['id']}</td><td>{$vehicle['vehicle_number']}</td><td>{$vehicle['model']}</td><td>{$active}</td></tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h4>初期データ追加</h4>";
    
    // 車両データの追加（重複チェック）
    $vehicles = [
        ['大阪801 あ 16-72', 'スマイリーケアタクシー1号車'],
        ['大阪801 あ 16-73', 'スマイリーケアタクシー2号車']
    ];
    
    foreach ($vehicles as $vehicle) {
        // 重複チェック
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE vehicle_number = ?");
        $stmt->execute([$vehicle[0]]);
        $exists = $stmt->fetchColumn();
        
        if ($exists == 0) {
            $stmt = $pdo->prepare("INSERT INTO vehicles (vehicle_number, model, registration_date, next_inspection_date, is_active) VALUES (?, ?, ?, ?, TRUE)");
            $result = $stmt->execute([
                $vehicle[0], 
                $vehicle[1], 
                '2024-01-01',
                date('Y-m-d', strtotime('+3 months'))
            ]);
            
            if ($result) {
                echo "<p style='color: green;'>✅ 車両追加: {$vehicle[0]}</p>";
            }
        } else {
            echo "<p style='color: blue;'>ℹ️ 車両既存: {$vehicle[0]}</p>";
        }
    }
    
    // ユーザーデータの追加（重複チェック）
    $users = [
        ['driver1', '田中太郎', 'driver'],
        ['driver2', '佐藤花子', 'driver'],
        ['manager1', '山田管理者', 'manager']
    ];
    
    foreach ($users as $user) {
        // 重複チェック
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ?");
        $stmt->execute([$user[0]]);
        $exists = $stmt->fetchColumn();
        
        if ($exists == 0) {
            $password = password_hash('password123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (login_id, name, password, role, is_active) VALUES (?, ?, ?, ?, TRUE)");
            $result = $stmt->execute([$user[0], $user[1], $password, $user[2]]);
            
            if ($result) {
                echo "<p style='color: green;'>✅ ユーザー追加: {$user[1]} (ID: {$user[0]}, パスワード: password123)</p>";
            }
        } else {
            echo "<p style='color: blue;'>ℹ️ ユーザー既存: {$user[0]}</p>";
        }
    }
    
    echo "<hr>";
    echo "<h4>更新後のデータ確認</h4>";
    
    // 更新後の車両一覧
    $stmt = $pdo->query("SELECT * FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $vehicles = $stmt->fetchAll();
    echo "<h5>有効な車両一覧 (" . count($vehicles) . "台):</h5>";
    foreach ($vehicles as $vehicle) {
        echo "<p>・{$vehicle['vehicle_number']} ({$vehicle['model']})</p>";
    }
    
    // 更新後のユーザー一覧
    $stmt = $pdo->query("SELECT login_id, name, role FROM users WHERE is_active = TRUE ORDER BY role, name");
    $users = $stmt->fetchAll();
    echo "<h5>有効なユーザー一覧 (" . count($users) . "名):</h5>";
    foreach ($users as $user) {
        $role_name = ['driver' => '運転者', 'manager' => '管理者', 'admin' => 'システム管理者'][$user['role']] ?? $user['role'];
        echo "<p>・{$user['name']} (ID: {$user['login_id']}, 権限: {$role_name})</p>";
    }
    
    // SQLテスト
    echo "<hr>";
    echo "<h4>SQLテスト結果</h4>";
    $stmt = $pdo->query("SELECT * FROM users WHERE role IN ('driver', 'manager', 'admin') AND is_active = TRUE ORDER BY name");
    $drivers = $stmt->fetchAll();
    echo "<p>ドライバー取得SQL結果: " . count($drivers) . "件</p>";
    
    $stmt = $pdo->query("SELECT * FROM users WHERE role IN ('manager', 'admin') AND is_active = TRUE ORDER BY name");
    $callers = $stmt->fetchAll();
    echo "<p>点呼者取得SQL結果: " . count($callers) . "件</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>エラー: " . $e->getMessage() . "</p>";
    echo "<p>詳細: " . $e->getTraceAsString() . "</p>";
}

echo "<hr>";
echo "<p><a href='pre_duty_call.php'>乗務前点呼画面へ</a></p>";
echo "<p><a href='dashboard.php'>ダッシュボードへ</a></p>";
?>