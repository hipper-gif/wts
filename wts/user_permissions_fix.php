<?php
// ユーザー権限修正スクリプト
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    echo "<h2>ユーザー権限確認・修正スクリプト</h2>";
    
    // 1. 現在のユーザー状況を確認
    echo "<h3>1. 現在のユーザー状況</h3>";
    $stmt = $pdo->prepare("SELECT id, name, login_id, role, is_driver, is_caller, is_admin, is_active FROM users ORDER BY id");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>名前</th><th>ログインID</th><th>役割</th><th>運転者</th><th>点呼者</th><th>管理者</th><th>有効</th></tr>";
    
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['name'] ?? '未設定') . "</td>";
        echo "<td>" . htmlspecialchars($user['login_id'] ?? '未設定') . "</td>";
        echo "<td>" . htmlspecialchars($user['role'] ?? '未設定') . "</td>";
        echo "<td>" . ($user['is_driver'] ? '✓' : '✗') . "</td>";
        echo "<td>" . ($user['is_caller'] ? '✓' : '✗') . "</td>";
        echo "<td>" . ($user['is_admin'] ? '✓' : '✗') . "</td>";
        echo "<td>" . ($user['is_active'] ? '✓' : '✗') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. 運転者権限の自動修正
    echo "<h3>2. 運転者権限の自動修正</h3>";
    
    // roleが'driver'または'admin'のユーザーにis_driver=1を設定
    $stmt = $pdo->prepare("UPDATE users SET is_driver = 1 WHERE role IN ('driver', 'admin') AND is_active = 1");
    $stmt->execute();
    $updated_drivers = $stmt->rowCount();
    
    // roleが'manager'または'admin'のユーザーにis_caller=1を設定
    $stmt = $pdo->prepare("UPDATE users SET is_caller = 1 WHERE role IN ('manager', 'admin') AND is_active = 1");
    $stmt->execute();
    $updated_callers = $stmt->rowCount();
    
    // roleが'admin'のユーザーにis_admin=1を設定
    $stmt = $pdo->prepare("UPDATE users SET is_admin = 1 WHERE role = 'admin' AND is_active = 1");
    $stmt->execute();
    $updated_admins = $stmt->rowCount();
    
    echo "<p>✓ 運転者権限を修正: {$updated_drivers}件</p>";
    echo "<p>✓ 点呼者権限を修正: {$updated_callers}件</p>";
    echo "<p>✓ 管理者権限を修正: {$updated_admins}件</p>";
    
    // 3. 修正後の状況を確認
    echo "<h3>3. 修正後のユーザー状況</h3>";
    $stmt = $pdo->prepare("SELECT id, name, login_id, role, is_driver, is_caller, is_admin, is_active FROM users ORDER BY id");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>名前</th><th>ログインID</th><th>役割</th><th>運転者</th><th>点呼者</th><th>管理者</th><th>有効</th></tr>";
    
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['name'] ?? '未設定') . "</td>";
        echo "<td>" . htmlspecialchars($user['login_id'] ?? '未設定') . "</td>";
        echo "<td>" . htmlspecialchars($user['role'] ?? '未設定') . "</td>";
        echo "<td style='color: " . ($user['is_driver'] ? 'green' : 'red') . "'>" . ($user['is_driver'] ? '✓' : '✗') . "</td>";
        echo "<td style='color: " . ($user['is_caller'] ? 'green' : 'red') . "'>" . ($user['is_caller'] ? '✓' : '✗') . "</td>";
        echo "<td style='color: " . ($user['is_admin'] ? 'green' : 'red') . "'>" . ($user['is_admin'] ? '✓' : '✗') . "</td>";
        echo "<td style='color: " . ($user['is_active'] ? 'green' : 'red') . "'>" . ($user['is_active'] ? '✓' : '✗') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 4. 運転者として取得されるユーザーリストを確認
    echo "<h3>4. 運転者として取得されるユーザー（修正後）</h3>";
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE (role IN ('driver', 'admin') OR is_driver = 1) AND is_active = 1 ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<ul>";
    foreach ($drivers as $driver) {
        echo "<li>ID: " . $driver['id'] . " - " . htmlspecialchars($driver['name']) . "</li>";
    }
    echo "</ul>";
    
    // 5. データベーステーブル構造の確認
    echo "<h3>5. 必要テーブルの構造確認</h3>";
    
    // post_duty_callsテーブルの確認・作成
    $stmt = $pdo->query("SHOW TABLES LIKE 'post_duty_calls'");
    if ($stmt->rowCount() == 0) {
        echo "<p>⚠️ post_duty_callsテーブルが存在しません。作成します...</p>";
        
        $create_sql = "CREATE TABLE post_duty_calls (
            id INT PRIMARY KEY AUTO_INCREMENT,
            driver_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            call_date DATE NOT NULL,
            call_time TIME NOT NULL,
            caller_name VARCHAR(100),
            alcohol_check_value DECIMAL(5,3) DEFAULT 0.000,
            alcohol_check_time TIME,
            pre_duty_call_id INT,
            health_condition_check BOOLEAN DEFAULT FALSE,
            fatigue_check BOOLEAN DEFAULT FALSE,
            alcohol_drug_check BOOLEAN DEFAULT FALSE,
            vehicle_condition_check BOOLEAN DEFAULT FALSE,
            accident_violation_check BOOLEAN DEFAULT FALSE,
            equipment_return_check BOOLEAN DEFAULT FALSE,
            report_completion_check BOOLEAN DEFAULT FALSE,
            remarks TEXT,
            is_completed BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (driver_id) REFERENCES users(id),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
            FOREIGN KEY (pre_duty_call_id) REFERENCES pre_duty_calls(id)
        )";
        
        $pdo->exec($create_sql);
        echo "<p>✓ post_duty_callsテーブルを作成しました</p>";
    } else {
        echo "<p>✓ post_duty_callsテーブルは既に存在します</p>";
    }
    
    // periodic_inspectionsテーブルの確認・作成
    $stmt = $pdo->query("SHOW TABLES LIKE 'periodic_inspections'");
    if ($stmt->rowCount() == 0) {
        echo "<p>⚠️ periodic_inspectionsテーブルが存在しません。作成します...</p>";
        
        $create_sql = "CREATE TABLE periodic_inspections (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id INT NOT NULL,
            inspection_date DATE NOT NULL,
            inspector_id INT NOT NULL,
            mileage INT,
            inspection_type VARCHAR(50) DEFAULT '3months',
            service_provider_name VARCHAR(200),
            service_provider_address VARCHAR(300),
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
            FOREIGN KEY (inspector_id) REFERENCES users(id)
        )";
        
        $pdo->exec($create_sql);
        echo "<p>✓ periodic_inspectionsテーブルを作成しました</p>";
        
        // 関連する点検項目テーブルも作成
        $items_sql = "CREATE TABLE periodic_inspection_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            inspection_id INT NOT NULL,
            category VARCHAR(50) NOT NULL,
            item_name VARCHAR(100) NOT NULL,
            result VARCHAR(10),
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (inspection_id) REFERENCES periodic_inspections(id) ON DELETE CASCADE
        )";
        
        $pdo->exec($items_sql);
        echo "<p>✓ periodic_inspection_itemsテーブルを作成しました</p>";
    } else {
        echo "<p>✓ periodic_inspectionsテーブルは既に存在します</p>";
    }
    
    echo "<p><strong>修正完了！</strong> 運転者リストに " . count($drivers) . " 名が表示されるはずです。</p>";
    
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { margin: 10px 0; }
    th, td { padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    h2 { color: #333; }
    h3 { color: #666; margin-top: 30px; }
</style>