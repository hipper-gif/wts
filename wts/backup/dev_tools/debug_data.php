<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    echo "<h3>データベース内容確認</h3>";
    
    // usersテーブル確認
    echo "<h4>Users テーブル:</h4>";
    $stmt = $pdo->query("SELECT id, login_id, name, role, is_active FROM users");
    $users = $stmt->fetchAll();
    
    echo "<pre>";
    print_r($users);
    echo "</pre>";
    
    // vehiclesテーブル確認
    echo "<h4>Vehicles テーブル:</h4>";
    $stmt = $pdo->query("SELECT id, vehicle_number, model, is_active FROM vehicles");
    $vehicles = $stmt->fetchAll();
    
    echo "<pre>";
    print_r($vehicles);
    echo "</pre>";
    
    // 実際のSQL実行テスト
    echo "<h4>SQLテスト:</h4>";
    
    echo "<h5>運転者取得SQL:</h5>";
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE role IN ('driver', 'manager', 'admin') AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "結果件数: " . count($drivers) . "<br>";
    foreach ($drivers as $driver) {
        echo "- ID: {$driver['id']}, 名前: {$driver['name']}, 権限: {$driver['role']}<br>";
    }
    
    echo "<h5>点呼者取得SQL:</h5>";
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE role IN ('manager', 'admin') AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $callers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "結果件数: " . count($callers) . "<br>";
    foreach ($callers as $caller) {
        echo "- ID: {$caller['id']}, 名前: {$caller['name']}, 権限: {$caller['role']}<br>";
    }
    
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage();
}
?>