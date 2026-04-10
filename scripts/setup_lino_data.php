<?php
/**
 * LINO テナント初期データ投入スクリプト
 * リモートサーバー上で実行: php setup_lino_data.php <pass_suzuki> <pass_matsuo>
 */

$pass_suzuki = $argv[1] ?? 'changeme1';
$pass_matsuo = $argv[2] ?? 'changeme2';

$pdo = new PDO(
    'mysql:host=localhost;dbname=twinklemark_wtslino;charset=utf8mb4',
    'twinklemark_taxi',
    'Smiley2525',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ユーザー登録
$stmt = $pdo->prepare('INSERT INTO users (
    name, login_id, password, permission_level,
    is_driver, is_caller, is_inspector, is_admin, is_manager, is_mechanic,
    is_active, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())');

// 鈴木政典（運転者+管理者）
$hash1 = password_hash($pass_suzuki, PASSWORD_DEFAULT);
$stmt->execute(['鈴木　政典', 'lino2025', $hash1, 'Admin', 1, 0, 0, 1, 0, 0]);
echo "鈴木政典: OK (id=" . $pdo->lastInsertId() . ")\n";

// 松尾弥生（点呼者）
$hash2 = password_hash($pass_matsuo, PASSWORD_DEFAULT);
$stmt->execute(['松尾弥生', 'lino01', $hash2, 'User', 0, 1, 0, 0, 0, 0]);
echo "松尾弥生: OK (id=" . $pdo->lastInsertId() . ")\n";

// 車両登録
$stmt2 = $pdo->prepare('INSERT INTO vehicles (
    vehicle_number, vehicle_name, model,
    vehicle_type, capacity, current_mileage,
    status, is_active, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())');
$stmt2->execute(['4657', 'ノア', 'ノア', 'welfare', 4, 0, 'active']);
echo "車両4657 ノア: OK (id=" . $pdo->lastInsertId() . ")\n";

// 検証
echo "\n=== 検証 ===\n";
$users = $pdo->query("SELECT id, name, login_id, permission_level, is_driver, is_caller, is_admin FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    $roles = [];
    if ($u['is_driver']) $roles[] = '運転者';
    if ($u['is_caller']) $roles[] = '点呼者';
    if ($u['is_admin']) $roles[] = '管理者';
    echo "  User: {$u['name']} ({$u['login_id']}) - " . implode('+', $roles) . " [{$u['permission_level']}]\n";
}

$vehicles = $pdo->query("SELECT id, vehicle_number, vehicle_name, status FROM vehicles WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($vehicles as $v) {
    echo "  Vehicle: {$v['vehicle_number']} {$v['vehicle_name']} [{$v['status']}]\n";
}

echo "\n完了\n";
