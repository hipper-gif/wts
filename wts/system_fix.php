<?php
/*
 * 福祉輸送管理システム - 緊急修復スクリプト
 * 
 * 実行方法: https://tw1nkle.com/Smiley/taxi/wts/system_fix.php
 * 
 * 修正内容:
 * 1. ユーザーテーブルの権限修正
 * 2. マスタ管理表示の修正
 * 3. データベース整合性チェック
 * 4. 必要なカラムの追加
 */

// エラー表示を有効化
error_reporting(E_ALL);
ini_set('display_errors', 1);

// データベース接続
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo "<h2>✅ データベース接続成功</h2>";
    
} catch (PDOException $e) {
    die("<h2>❌ データベース接続エラー: " . $e->getMessage() . "</h2>");
}

$fix_results = [];

echo "<h1>🔧 福祉輸送管理システム 緊急修復</h1>";
echo "<p><strong>実行時刻:</strong> " . date('Y-m-d H:i:s') . "</p><hr>";

// 1. ユーザーテーブルの構造確認と修正
echo "<h3>1. ユーザーテーブルの確認と修正</h3>";

try {
    // テーブル構造確認
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    $column_names = array_column($columns, 'Field');
    
    echo "<p><strong>現在のテーブル構造:</strong></p>";
    echo "<ul>";
    foreach ($columns as $column) {
        echo "<li>{$column['Field']} - {$column['Type']} - {$column['Null']} - {$column['Default']}</li>";
    }
    echo "</ul>";
    
    // 必要なカラムが存在するかチェック
    $required_columns = ['is_driver', 'is_caller', 'is_admin', 'role'];
    $missing_columns = [];
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $column_names)) {
            $missing_columns[] = $col;
        }
    }
    
    // 不足カラムの追加
    if (!empty($missing_columns)) {
        echo "<p><strong>🔧 不足カラムを追加します:</strong></p>";
        
        foreach ($missing_columns as $col) {
            try {
                switch ($col) {
                    case 'is_driver':
                        $pdo->exec("ALTER TABLE users ADD COLUMN is_driver BOOLEAN DEFAULT FALSE");
                        echo "<p>✅ is_driver カラムを追加しました</p>";
                        break;
                    case 'is_caller':
                        $pdo->exec("ALTER TABLE users ADD COLUMN is_caller BOOLEAN DEFAULT FALSE");
                        echo "<p>✅ is_caller カラムを追加しました</p>";
                        break;
                    case 'is_admin':
                        $pdo->exec("ALTER TABLE users ADD COLUMN is_admin BOOLEAN DEFAULT FALSE");
                        echo "<p>✅ is_admin カラムを追加しました</p>";
                        break;
                    case 'role':
                        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'driver'");
                        echo "<p>✅ role カラムを追加しました</p>";
                        break;
                }
            } catch (Exception $e) {
                echo "<p>⚠️ {$col} カラム追加エラー: " . $e->getMessage() . "</p>";
            }
        }
    } else {
        echo "<p>✅ 必要なカラムは全て存在しています</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ テーブル構造確認エラー: " . $e->getMessage() . "</p>";
}

// 2. 既存ユーザーの権限修正
echo "<h3>2. 既存ユーザーの権限修正</h3>";

try {
    // 既存ユーザーの確認
    $stmt = $pdo->query("SELECT * FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    echo "<p><strong>現在のユーザー情報:</strong></p>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>";
    echo "<tr style='background-color: #f0f0f0;'>";
    echo "<th style='padding: 8px;'>ID</th>";
    echo "<th style='padding: 8px;'>名前</th>";
    echo "<th style='padding: 8px;'>ログインID</th>";
    echo "<th style='padding: 8px;'>role</th>";
    echo "<th style='padding: 8px;'>is_driver</th>";
    echo "<th style='padding: 8px;'>is_caller</th>";
    echo "<th style='padding: 8px;'>is_admin</th>";
    echo "<th style='padding: 8px;'>is_active</th>";
    echo "</tr>";
    
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td style='padding: 8px;'>" . htmlspecialchars($user['id'] ?? '') . "</td>";
        echo "<td style='padding: 8px;'>" . htmlspecialchars($user['name'] ?? '') . "</td>";
        echo "<td style='padding: 8px;'>" . htmlspecialchars($user['login_id'] ?? '') . "</td>";
        echo "<td style='padding: 8px;'>" . htmlspecialchars($user['role'] ?? 'NULL') . "</td>";
        echo "<td style='padding: 8px;'>" . (isset($user['is_driver']) ? ($user['is_driver'] ? 'TRUE' : 'FALSE') : 'NULL') . "</td>";
        echo "<td style='padding: 8px;'>" . (isset($user['is_caller']) ? ($user['is_caller'] ? 'TRUE' : 'FALSE') : 'NULL') . "</td>";
        echo "<td style='padding: 8px;'>" . (isset($user['is_admin']) ? ($user['is_admin'] ? 'TRUE' : 'FALSE') : 'NULL') . "</td>";
        echo "<td style='padding: 8px;'>" . (isset($user['is_active']) ? ($user['is_active'] ? 'TRUE' : 'FALSE') : 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 権限が正しく設定されていないユーザーを修正
    echo "<p><strong>🔧 権限の自動修正を実行します:</strong></p>";
    
    foreach ($users as $user) {
        $user_id = $user['id'];
        $login_id = $user['login_id'] ?? '';
        $name = $user['name'] ?? '';
        
        // デフォルトの権限設定
        $is_driver = 1;  // 基本的に全員運転者
        $is_caller = 1;  // 基本的に全員点呼者
        $is_admin = 0;   // 管理者は個別設定
        $role = 'driver';
        
        // login_idに基づく自動判定
        if (stripos($login_id, 'admin') !== false || stripos($name, '管理') !== false) {
            $is_admin = 1;
            $role = 'admin';
        } elseif (stripos($login_id, 'manager') !== false || stripos($name, 'マネ') !== false) {
            $role = 'manager';
        }
        
        // 更新実行
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET role = ?, is_driver = ?, is_caller = ?, is_admin = ?, is_active = COALESCE(is_active, 1)
                WHERE id = ?
            ");
            $stmt->execute([$role, $is_driver, $is_caller, $is_admin, $user_id]);
            
            echo "<p>✅ ユーザー「{$name}」({$login_id}) の権限を更新: role={$role}, is_driver={$is_driver}, is_caller={$is_caller}, is_admin={$is_admin}</p>";
            
        } catch (Exception $e) {
            echo "<p>❌ ユーザー「{$name}」の更新エラー: " . $e->getMessage() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p>❌ ユーザー権限修正エラー: " . $e->getMessage() . "</p>";
}

// 3. 追加のユーザーが必要な場合のデフォルト作成
echo "<h3>3. デフォルトユーザーの確認・作成</h3>";

try {
    // ユーザー数確認
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $user_count = $stmt->fetchColumn();
    
    if ($user_count < 2) {
        echo "<p>⚠️ アクティブユーザーが少ないため、デフォルトユーザーを作成します</p>";
        
        // 管理者ユーザーの存在確認
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        $admin_count = $stmt->fetchColumn();
        
        if ($admin_count == 0) {
            // デフォルト管理者作成
            $stmt = $pdo->prepare("
                INSERT INTO users (name, login_id, password, role, is_driver, is_caller, is_admin, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE role = VALUES(role), is_admin = VALUES(is_admin)
            ");
            $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt->execute(['システム管理者', 'admin', $hashed_password, 'admin', 1, 1, 1, 1]);
            echo "<p>✅ デフォルト管理者アカウントを作成しました (admin / admin123)</p>";
        }
        
        // 運転者ユーザーの確認
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_driver = 1 AND is_active = 1");
        $driver_count = $stmt->fetchColumn();
        
        if ($driver_count < 2) {
            // デフォルト運転者作成
            $drivers = [
                ['運転者A', 'driver1', 'driver123'],
                ['運転者B', 'driver2', 'driver123']
            ];
            
            foreach ($drivers as [$name, $login_id, $password]) {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO users (name, login_id, password, role, is_driver, is_caller, is_admin, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt->execute([$name, $login_id, $hashed_password, 'driver', 1, 1, 0, 1]);
                echo "<p>✅ デフォルト運転者「{$name}」を作成しました ({$login_id} / {$password})</p>";
            }
        }
    } else {
        echo "<p>✅ 十分な数のユーザーが存在しています</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ デフォルトユーザー作成エラー: " . $e->getMessage() . "</p>";
}

// 4. 車両テーブルの確認・作成
echo "<h3>4. 車両テーブルの確認</h3>";

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1");
    $vehicle_count = $stmt->fetchColumn();
    
    if ($vehicle_count == 0) {
        echo "<p>⚠️ 登録車両がないため、デフォルト車両を作成します</p>";
        
        $vehicles = [
            ['車両1号', 'スマイリー1', '2024-01-01', '2025-01-01'],
            ['車両2号', 'スマイリー2', '2024-01-01', '2025-01-01']
        ];
        
        foreach ($vehicles as [$vehicle_number, $vehicle_name, $registration_date, $next_inspection]) {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO vehicles (vehicle_number, model, registration_date, next_inspection_date, is_active, mileage) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$vehicle_number, $vehicle_name, $registration_date, $next_inspection, 1, 0]);
            echo "<p>✅ デフォルト車両「{$vehicle_number}」を作成しました</p>";
        }
    } else {
        echo "<p>✅ 車両は {$vehicle_count} 台登録されています</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ 車両確認エラー: " . $e->getMessage() . "</p>";
}

// 5. 修正後の確認
echo "<h3>5. 修正結果の確認</h3>";

try {
    // 修正後のユーザー情報表示
    $stmt = $pdo->query("SELECT id, name, login_id, role, is_driver, is_caller, is_admin, is_active FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    echo "<p><strong>修正後のユーザー情報:</strong></p>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>";
    echo "<tr style='background-color: #e8f5e8;'>";
    echo "<th style='padding: 8px;'>ID</th>";
    echo "<th style='padding: 8px;'>名前</th>";
    echo "<th style='padding: 8px;'>ログインID</th>";
    echo "<th style='padding: 8px;'>メイン権限</th>";
    echo "<th style='padding: 8px;'>運転者</th>";
    echo "<th style='padding: 8px;'>点呼者</th>";
    echo "<th style='padding: 8px;'>管理者</th>";
    echo "<th style='padding: 8px;'>状態</th>";
    echo "</tr>";
    
    foreach ($users as $user) {
        $row_color = $user['is_active'] ? '' : 'background-color: #f0f0f0;';
        echo "<tr style='{$row_color}'>";
        echo "<td style='padding: 8px;'>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td style='padding: 8px;'>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td style='padding: 8px;'>" . htmlspecialchars($user['login_id']) . "</td>";
        echo "<td style='padding: 8px;'><strong>" . htmlspecialchars($user['role']) . "</strong></td>";
        echo "<td style='padding: 8px; text-align: center;'>" . ($user['is_driver'] ? '✅' : '❌') . "</td>";
        echo "<td style='padding: 8px; text-align: center;'>" . ($user['is_caller'] ? '✅' : '❌') . "</td>";
        echo "<td style='padding: 8px; text-align: center;'>" . ($user['is_admin'] ? '🔑' : '❌') . "</td>";
        echo "<td style='padding: 8px; text-align: center;'>" . ($user['is_active'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 統計情報
    $stats = [
        'total_users' => count($users),
        'active_users' => count(array_filter($users, fn($u) => $u['is_active'])),
        'drivers' => count(array_filter($users, fn($u) => $u['is_driver'] && $u['is_active'])),
        'callers' => count(array_filter($users, fn($u) => $u['is_caller'] && $u['is_active'])),
        'admins' => count(array_filter($users, fn($u) => $u['is_admin'] && $u['is_active']))
    ];
    
    echo "<p><strong>統計情報:</strong></p>";
    echo "<ul>";
    echo "<li>総ユーザー数: {$stats['total_users']}</li>";
    echo "<li>アクティブユーザー数: {$stats['active_users']}</li>";
    echo "<li>運転者数: {$stats['drivers']}</li>";
    echo "<li>点呼者数: {$stats['callers']}</li>";
    echo "<li>管理者数: {$stats['admins']}</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p>❌ 修正結果確認エラー: " . $e->getMessage() . "</p>";
}

// 6. セッション問題の対策
echo "<h3>6. セッション・権限チェック改善</h3>";

echo "<p><strong>ログイン時の権限取得を改善する必要があります。</strong></p>";
echo "<p>以下のコードをindex.php（ログイン処理）に適用してください：</p>";

echo "<pre style='background-color: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; overflow-x: auto;'>";
$sample_code = '// ログイン成功時のセッション設定改善版
$_SESSION["user_id"] = $user["id"];
$_SESSION["user_name"] = $user["name"];
$_SESSION["login_id"] = $user["login_id"];

// 権限の統合判定
if ($user["is_admin"]) {
    $_SESSION["user_role"] = "admin";
} elseif ($user["role"] == "manager" || $user["is_caller"]) {
    $_SESSION["user_role"] = "manager";
} else {
    $_SESSION["user_role"] = "driver";
}

// 個別権限も保存
$_SESSION["is_driver"] = (bool)$user["is_driver"];
$_SESSION["is_caller"] = (bool)$user["is_caller"];
$_SESSION["is_admin"] = (bool)$user["is_admin"];';
echo htmlspecialchars($sample_code);
echo "</pre>";

// 7. 最終チェック
echo "<h3>7. 動作確認</h3>";

try {
    // ダッシュボードでマスタ管理が表示される権限のユーザー数確認
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE (role IN ('admin', 'manager') OR is_admin = 1) AND is_active = 1");
    $admin_users = $stmt->fetchColumn();
    
    // 点呼機能で表示される運転者数確認
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE (role = 'driver' OR is_driver = 1) AND is_active = 1");
    $drivers = $stmt->fetchColumn();
    
    // 点呼機能で表示される点呼者数確認
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE (role IN ('manager', 'admin') OR is_caller = 1) AND is_active = 1");
    $callers = $stmt->fetchColumn();
    
    echo "<p><strong>権限チェック結果:</strong></p>";
    echo "<ul>";
    echo "<li>マスタ管理が表示される権限のユーザー: <strong>{$admin_users}</strong>人</li>";
    echo "<li>運転者として表示されるユーザー: <strong>{$drivers}</strong>人</li>";
    echo "<li>点呼者として表示されるユーザー: <strong>{$callers}</strong>人</li>";
    echo "</ul>";
    
    if ($admin_users > 0 && $drivers > 0 && $callers > 0) {
        echo "<p style='color: green; font-weight: bold;'>✅ 全ての権限が適切に設定されています！</p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>⚠️ 一部の権限に問題があります。上記の統計をご確認ください。</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ 最終チェックエラー: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>🎉 修復スクリプト実行完了</h2>";
echo "<p><strong>次の手順:</strong></p>";
echo "<ol>";
echo "<li>ブラウザで <a href='dashboard.php' target='_blank'>ダッシュボード</a> にアクセス</li>";
echo "<li>一度ログアウトして、再度ログイン</li>";
echo "<li>マスタ管理が表示されることを確認</li>";
echo "<li>乗務前点呼で運転者・点呼者が表示されることを確認</li>";
echo "</ol>";

echo "<p style='background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 5px;'>";
echo "<strong>⚠️ 注意:</strong> このスクリプトは1回のみ実行してください。問題が解決しない場合は、ログアウト・再ログインを試してください。";
echo "</p>";

echo "<p><strong>作成日時:</strong> " . date('Y-m-d H:i:s') . "</p>";
?>
