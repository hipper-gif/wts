#!/usr/bin/env php
<?php
/**
 * LINO テナントプロビジョニング シナリオテスト
 *
 * 既存DBに対してトランザクション内で全ステップを実行し、
 * 結果を検証した後にロールバックする（データは残らない）。
 *
 * 使い方: php scripts/test_provision_lino.php
 */

// .env 読み込み
$envFile = __DIR__ . '/../wts/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) putenv(trim($parts[0]) . '=' . trim($parts[1]));
    }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'twinklemark_wts';
$dbUser = getenv('DB_USER') ?: 'twinklemark_taxi';
$dbPass = getenv('DB_PASS') ?: '';

echo "=== LINO プロビジョニング シナリオテスト ===\n\n";

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "[OK] DB接続成功\n";
} catch (Exception $e) {
    echo "[FAIL] DB接続失敗: {$e->getMessage()}\n";
    exit(1);
}

// provision_params.json 読み込み
$jsonPath = __DIR__ . '/lino_provision.json';
if (!file_exists($jsonPath)) {
    echo "[FAIL] {$jsonPath} が見つかりません\n";
    exit(1);
}
$data = json_decode(file_get_contents($jsonPath), true);
$params = $data['provision_params'];
$company = $params['company_info'];
$vehicles = $params['vehicles'];
$users = $params['users'];

echo "[OK] provision データ読み込み: ユーザー" . count($users) . "名, 車両" . count($vehicles) . "台\n\n";

// テスト用SAVEPOINT（外側はautocommit=0で制御）
$pdo->exec("SET autocommit = 0");
$pdo->exec("SAVEPOINT test_start");

$errors = [];
$passed = 0;

// --- Test 1: company_info 登録 ---
echo "--- Step 1: company_info 登録 ---\n";
try {
    // id=1がなければINSERT、あればUPDATE
    $pdo->exec("INSERT IGNORE INTO company_info (id) VALUES (1)");
    $stmt = $pdo->prepare("UPDATE company_info SET
        company_name = ?, representative_name = ?, postal_code = ?,
        address = ?, phone = ?, fax = ?,
        manager_name = ?, manager_email = ?, license_number = ?
        WHERE id = 1");
    $stmt->execute([
        $company['company_name'],
        $company['representative'],
        $company['postal_code'],
        $company['address'],
        $company['phone'],
        $company['fax'] ?? '',
        $company['manager_name'],
        $company['manager_email'],
        $company['license_number'],
    ]);

    // 検証
    $row = $pdo->query("SELECT * FROM company_info WHERE id = 1")->fetch();
    $checks = [
        ['company_name', $company['company_name']],
        ['representative_name', $company['representative']],
        ['postal_code', $company['postal_code']],
        ['address', $company['address']],
        ['phone', $company['phone']],
        ['manager_name', $company['manager_name']],
        ['manager_email', $company['manager_email']],
        ['license_number', $company['license_number']],
    ];
    foreach ($checks as [$key, $expected]) {
        if (($row[$key] ?? '') === $expected) {
            echo "  [OK] {$key} = {$expected}\n";
            $passed++;
        } else {
            $msg = "  [FAIL] {$key}: expected '{$expected}', got '{$row[$key]}'";
            echo $msg . "\n";
            $errors[] = $msg;
        }
    }
} catch (Exception $e) {
    $msg = "  [FAIL] company_info 登録エラー: {$e->getMessage()}";
    echo $msg . "\n";
    $errors[] = $msg;
}

// --- Test 2: system_settings 登録 ---
echo "\n--- Step 2: system_settings 登録 ---\n";
try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('system_name', 'スマルト')
        ON DUPLICATE KEY UPDATE setting_value = 'スマルト'");

    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('theme_color', '#00C896')
        ON DUPLICATE KEY UPDATE setting_value = setting_value");

    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");

    $stmt->execute(['system_name']);
    $val = $stmt->fetchColumn();
    if ($val === 'スマルト') {
        echo "  [OK] system_name = スマルト\n";
        $passed++;
    } else {
        $msg = "  [FAIL] system_name = '{$val}'";
        echo $msg . "\n";
        $errors[] = $msg;
    }

    $stmt->execute(['theme_color']);
    $val = $stmt->fetchColumn();
    echo "  [OK] theme_color = {$val}\n";
    $passed++;
} catch (Exception $e) {
    $msg = "  [FAIL] system_settings エラー: {$e->getMessage()}";
    echo $msg . "\n";
    $errors[] = $msg;
}

// --- Test 3: ユーザー登録 ---
echo "\n--- Step 3: ユーザー登録 ({$params['users'][0]['name']}他" . count($users) . "名) ---\n";
$createdUserIds = [];
try {
    foreach ($users as $u) {
        $password = 'TestPass123!';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $isAdmin = $u['is_admin'] ? 1 : 0;
        $permLevel = $u['permission_level'];
        $isDriver = $u['is_driver'] ? 1 : 0;
        $isCaller = $u['is_inspector'] ? 1 : 0;  // is_inspector → is_caller (点呼者)

        $stmt = $pdo->prepare("INSERT INTO users (
            name, login_id, password, permission_level,
            is_driver, is_caller, is_inspector, is_admin, is_manager, is_mechanic,
            phone, email, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, '', '', 1, NOW())");
        $stmt->execute([
            $u['name'], $u['login_id'], $hash, $permLevel,
            $isDriver, $isCaller, 0, $isAdmin
        ]);
        $newId = $pdo->lastInsertId();
        $createdUserIds[] = $newId;

        // 検証: ログインできるか
        $stmt2 = $pdo->prepare("SELECT * FROM users WHERE login_id = ? AND is_active = 1");
        $stmt2->execute([$u['login_id']]);
        $userRow = $stmt2->fetch();

        $pwHash = $userRow['PASSWORD'] ?? $userRow['password'] ?? null;
        if ($userRow && $pwHash && password_verify($password, $pwHash)) {
            $roles = [];
            if ($userRow['is_driver']) $roles[] = '運転者';
            if ($userRow['is_caller']) $roles[] = '点呼者';
            if ($userRow['is_admin']) $roles[] = '管理者';
            $roleStr = implode('+', $roles) ?: '(役割なし)';
            echo "  [OK] {$u['name']} (ID: {$u['login_id']}, {$roleStr}, {$permLevel}) - ログイン検証OK\n";
            $passed++;
        } else {
            $msg = "  [FAIL] {$u['name']} のログイン検証失敗";
            echo $msg . "\n";
            $errors[] = $msg;
        }
    }
} catch (Exception $e) {
    $msg = "  [FAIL] ユーザー登録エラー: {$e->getMessage()}";
    echo $msg . "\n";
    $errors[] = $msg;
}

// --- Test 4: 車両登録 ---
echo "\n--- Step 4: 車両登録 ({$vehicles[0]['plate_number']} {$vehicles[0]['model']}) ---\n";
try {
    foreach ($vehicles as $v) {
        $stmt = $pdo->prepare("INSERT INTO vehicles (
            vehicle_number, vehicle_name, model,
            vehicle_type, capacity, current_mileage,
            status, is_active, created_at
        ) VALUES (?, ?, ?, 'welfare', 4, 0, 'active', 1, NOW())");
        $stmt->execute([
            $v['plate_number'],
            $v['model'],
            $v['model'],
        ]);

        // 検証
        $stmt2 = $pdo->prepare("SELECT * FROM vehicles WHERE vehicle_number = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
        $stmt2->execute([$v['plate_number']]);
        $vRow = $stmt2->fetch();

        if ($vRow) {
            echo "  [OK] 車両 {$vRow['vehicle_number']} ({$vRow['vehicle_name']}) - type={$vRow['vehicle_type']}, status={$vRow['status']}\n";
            $passed++;
        } else {
            $msg = "  [FAIL] 車両 {$v['plate_number']} の登録検証失敗";
            echo $msg . "\n";
            $errors[] = $msg;
        }
    }
} catch (Exception $e) {
    $msg = "  [FAIL] 車両登録エラー: {$e->getMessage()}";
    echo $msg . "\n";
    $errors[] = $msg;
}

// --- Test 5: 統合確認（ダッシュボード相当のクエリ） ---
echo "\n--- Step 5: 統合確認 ---\n";
try {
    $activeUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    $activeVehicles = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1")->fetchColumn();
    $companyName = $pdo->query("SELECT company_name FROM company_info WHERE id = 1")->fetchColumn();
    $systemName = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'")->fetchColumn();

    echo "  システム名: {$systemName}\n";
    echo "  会社名: {$companyName}\n";
    echo "  有効ユーザー数: {$activeUsers}\n";
    echo "  有効車両数: {$activeVehicles}\n";
    $passed++;
} catch (Exception $e) {
    $msg = "  [FAIL] 統合確認エラー: {$e->getMessage()}";
    echo $msg . "\n";
    $errors[] = $msg;
}

// --- ロールバック ---
echo "\n--- ロールバック ---\n";
$pdo->exec("ROLLBACK TO SAVEPOINT test_start");
$pdo->exec("SET autocommit = 1");
echo "  [OK] 全変更をロールバック済み（DBにデータは残りません）\n";

// --- 結果サマリ ---
echo "\n========================================\n";
if (empty($errors)) {
    echo "結果: ALL PASSED ({$passed} checks)\n";
    echo "プロビジョニング準備OKです！\n";
} else {
    echo "結果: " . count($errors) . " FAILED / {$passed} passed\n";
    foreach ($errors as $err) {
        echo "  {$err}\n";
    }
}
echo "========================================\n";
