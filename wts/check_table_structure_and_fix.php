<?php
// 福祉輸送管理システム - テーブル構造確認と日常点検改善
require_once 'config/database.php';

echo "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>テーブル構造確認と修正</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>
</head>
<body>
<div class='container mt-4'>
    <h2><i class='fas fa-database'></i> テーブル構造確認と日常点検改善</h2>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='alert alert-success'>✅ データベース接続成功</div>";
    
    // 1. 全テーブル確認
    echo "<h4><i class='fas fa-list'></i> 現在のテーブル一覧</h4>";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<div class='row'>";
    foreach ($tables as $table) {
        echo "<div class='col-md-3 mb-2'>";
        echo "<span class='badge bg-primary'>{$table}</span>";
        echo "</div>";
    }
    echo "</div>";
    
    // 2. usersテーブル詳細確認
    echo "<h4><i class='fas fa-users'></i> usersテーブル構造</h4>";
    if (in_array('users', $tables)) {
        $stmt = $pdo->query("DESCRIBE users");
        $userColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<div class='table-responsive'>";
        echo "<table class='table table-striped table-sm'>";
        echo "<thead class='table-dark'>";
        echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>Key</th><th>デフォルト</th></tr>";
        echo "</thead><tbody>";
        
        $hasPermissionLevel = false;
        $hasJobFlags = false;
        $activeColumn = null;
        
        foreach ($userColumns as $column) {
            echo "<tr>";
            echo "<td><strong>{$column['Field']}</strong></td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "</tr>";
            
            if ($column['Field'] === 'permission_level') $hasPermissionLevel = true;
            if (in_array($column['Field'], ['is_driver', 'is_caller', 'is_mechanic', 'is_manager'])) $hasJobFlags = true;
            if (in_array($column['Field'], ['active', 'is_active'])) $activeColumn = $column['Field'];
        }
        
        echo "</tbody></table>";
        echo "</div>";
        
        // 現在のユーザーデータ確認
        echo "<h5><i class='fas fa-eye'></i> 現在のユーザーデータ</h5>";
        $userQuery = "SELECT id, name, login_id";
        if ($hasPermissionLevel) $userQuery .= ", permission_level";
        if ($hasJobFlags) $userQuery .= ", is_driver, is_caller, is_mechanic, is_manager";
        if ($activeColumn) $userQuery .= ", {$activeColumn}";
        $userQuery .= " FROM users ORDER BY id";
        
        $stmt = $pdo->query($userQuery);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<div class='table-responsive'>";
        echo "<table class='table table-striped'>";
        echo "<thead><tr>";
        echo "<th>ID</th><th>名前</th><th>ログインID</th>";
        if ($hasPermissionLevel) echo "<th>権限レベル</th>";
        if ($hasJobFlags) echo "<th>運転者</th><th>点呼者</th><th>整備者</th><th>管理者</th>";
        if ($activeColumn) echo "<th>アクティブ</th>";
        echo "</tr></thead><tbody>";
        
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td><strong>{$user['name']}</strong></td>";
            echo "<td><code>{$user['login_id']}</code></td>";
            if ($hasPermissionLevel) {
                $level = $user['permission_level'] ?? 'NULL';
                echo "<td><span class='badge bg-" . ($level === 'Admin' ? 'danger' : 'primary') . "'>{$level}</span></td>";
            }
            if ($hasJobFlags) {
                echo "<td>" . ($user['is_driver'] ? '✅' : '❌') . "</td>";
                echo "<td>" . ($user['is_caller'] ? '✅' : '❌') . "</td>";
                echo "<td>" . ($user['is_mechanic'] ? '✅' : '❌') . "</td>";
                echo "<td>" . ($user['is_manager'] ? '✅' : '❌') . "</td>";
            }
            if ($activeColumn) {
                echo "<td>" . ($user[$activeColumn] ? '✅' : '❌') . "</td>";
            }
            echo "</tr>";
        }
        
        echo "</tbody></table>";
        echo "</div>";
    }
    
    // 3. daily_inspectionsテーブル確認
    echo "<h4><i class='fas fa-clipboard-check'></i> daily_inspectionsテーブル構造</h4>";
    if (in_array('daily_inspections', $tables)) {
        $stmt = $pdo->query("DESCRIBE daily_inspections");
        $inspectionColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<div class='table-responsive'>";
        echo "<table class='table table-striped table-sm'>";
        echo "<thead class='table-dark'>";
        echo "<tr><th>カラム名</th><th>データ型</th><th>NULL</th><th>Key</th><th>デフォルト</th></tr>";
        echo "</thead><tbody>";
        
        $hasInspectionTime = false;
        foreach ($inspectionColumns as $column) {
            echo "<tr>";
            echo "<td><strong>{$column['Field']}</strong></td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "</tr>";
            
            if ($column['Field'] === 'inspection_time') $hasInspectionTime = true;
        }
        
        echo "</tbody></table>";
        echo "</div>";
        
        // inspection_timeカラムの追加
        if (!$hasInspectionTime) {
            $pdo->exec("ALTER TABLE daily_inspections ADD COLUMN inspection_time TIME COMMENT '点検実施時間'");
            echo "<div class='alert alert-success'>✅ daily_inspectionsテーブルにinspection_timeカラムを追加しました</div>";
        }
    }
    
    // 4. 必要なカラムの追加・修正
    echo "<h4><i class='fas fa-wrench'></i> 必要なカラムの追加・修正</h4>";
    
    if (!$hasPermissionLevel) {
        $pdo->exec("ALTER TABLE users ADD COLUMN permission_level ENUM('User', 'Admin') DEFAULT 'User' COMMENT '権限レベル'");
        echo "<div class='alert alert-success'>✅ permission_levelカラムを追加しました</div>";
    }
    
    $jobFlags = [
        'is_driver' => "BOOLEAN DEFAULT TRUE COMMENT '運転者として選択可能'",
        'is_caller' => "BOOLEAN DEFAULT FALSE COMMENT '点呼者として選択可能'",
        'is_mechanic' => "BOOLEAN DEFAULT FALSE COMMENT '点検者として選択可能'",
        'is_manager' => "BOOLEAN DEFAULT FALSE COMMENT '管理者として選択可能'"
    ];
    
    foreach ($jobFlags as $flagName => $definition) {
        $exists = false;
        foreach ($userColumns as $column) {
            if ($column['Field'] === $flagName) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$flagName} {$definition}");
            echo "<div class='alert alert-success'>✅ {$flagName}カラムを追加しました</div>";
        }
    }
    
    // activeカラムがない場合は追加
    if (!$activeColumn) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active BOOLEAN DEFAULT TRUE COMMENT 'アクティブフラグ'");
        echo "<div class='alert alert-success'>✅ is_activeカラムを追加しました</div>";
        $activeColumn = 'is_active';
    }
    
    // 5. ユーザーデータの初期設定
    echo "<h4><i class='fas fa-user-cog'></i> ユーザーデータの初期設定</h4>";
    
    $updateUsers = [
        ['name' => '杉原 星', 'permission_level' => 'Admin', 'is_driver' => 1, 'is_caller' => 1, 'is_manager' => 1],
        ['name' => '杉原 充', 'permission_level' => 'User', 'is_driver' => 1, 'is_caller' => 1, 'is_manager' => 1],
        ['name' => '保田 翔', 'permission_level' => 'User', 'is_driver' => 1, 'is_caller' => 0, 'is_manager' => 0],
        ['name' => '服部 優佑', 'permission_level' => 'User', 'is_driver' => 1, 'is_caller' => 0, 'is_manager' => 0]
    ];
    
    foreach ($updateUsers as $userData) {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET permission_level = ?, is_driver = ?, is_caller = ?, is_manager = ?, is_mechanic = 0, {$activeColumn} = 1
            WHERE name LIKE ?
        ");
        $stmt->execute([
            $userData['permission_level'],
            $userData['is_driver'],
            $userData['is_caller'],
            $userData['is_manager'],
            '%' . $userData['name'] . '%'
        ]);
        
        echo "<div class='alert alert-success'>✅ {$userData['name']}の権限を設定しました</div>";
    }
    
    // 6. daily_inspection.php修正コード生成
    echo "<h4><i class='fas fa-code'></i> daily_inspection.php修正コード</h4>";
    
    $activeCondition = $activeColumn === 'is_active' ? 'is_active = 1' : 'active = 1';
    
    echo "<div class='card'>";
    echo "<div class='card-body'>";
    echo "<h6>以下のコードをdaily_inspection.phpに適用してください:</h6>";
    echo "<pre class='bg-light p-3'><code>";
    
    $fixedCode = <<<PHP
<?php
// === 修正版: daily_inspection.php ===
session_start();
require_once 'config/database.php';

if (!isset(\$_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

\$pdo = getDBConnection();
\$user_id = \$_SESSION['user_id'];
\$user_name = \$_SESSION['user_name'];
\$today = date('Y-m-d');
\$current_time = date('H:i');

\$success_message = '';
\$error_message = '';

// 車両とドライバーの取得
try {
    \$stmt = \$pdo->prepare("SELECT id, vehicle_number, model, current_mileage FROM vehicles WHERE {$activeCondition} ORDER BY vehicle_number");
    \$stmt->execute();
    \$vehicles = \$stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ✅ 新権限システム: 運転者のみを取得
    \$stmt = \$pdo->prepare("SELECT id, name, permission_level FROM users WHERE is_driver = 1 AND {$activeCondition} ORDER BY name");
    \$stmt->execute();
    \$drivers = \$stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception \$e) {
    error_log("Data fetch error: " . \$e->getMessage());
    \$vehicles = [];
    \$drivers = [];
}

// フォーム送信処理
if (\$_SERVER['REQUEST_METHOD'] === 'POST') {
    \$inspector_id = \$_POST['inspector_id'];
    \$vehicle_id = \$_POST['vehicle_id'];
    \$inspection_date = \$_POST['inspection_date'] ?? \$today;
    \$inspection_time = \$_POST['inspection_time'] ?? \$current_time;
    \$mileage = \$_POST['mileage'];
    
    // ✅ 新権限システム: 運転者かどうかの確認
    \$stmt = \$pdo->prepare("SELECT permission_level, is_driver, is_mechanic FROM users WHERE id = ? AND {$activeCondition}");
    \$stmt->execute([\$inspector_id]);
    \$inspector = \$stmt->fetch();
    
    if (!\$inspector || (!\$inspector['is_driver'] && !\$inspector['is_mechanic'])) {
        \$error_message = 'エラー: 点検者は運転者または整備者のみ選択できます。';
    } else {
        // 点検項目の処理...（既存コード）
        
        try {
            // 既存レコードの確認
            \$stmt = \$pdo->prepare("SELECT id FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
            \$stmt->execute([\$inspector_id, \$vehicle_id, \$inspection_date]);
            \$existing = \$stmt->fetch();
            
            if (\$existing) {
                // 更新処理（inspection_time追加）
                \$sql = "UPDATE daily_inspections SET 
                    mileage = ?, inspection_time = ?, /* 他のカラム */ updated_at = NOW() 
                    WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?";
            } else {
                // 新規挿入（inspection_time追加）
                \$sql = "INSERT INTO daily_inspections (
                    vehicle_id, driver_id, inspection_date, inspection_time, mileage, /* 他のカラム */
                ) VALUES (?, ?, ?, ?, ?, /* 他の値 */)";
            }
            
            // 成功時のリダイレクト（連続業務フロー）
            \$success_message = '日常点検記録を登録しました。';
            if (isset(\$_POST['continue_to_call'])) {
                header("Location: pre_duty_call.php?auto_flow=1&vehicle_id={\$vehicle_id}&driver_id={\$inspector_id}");
                exit;
            }
            
        } catch (Exception \$e) {
            \$error_message = '記録の保存中にエラーが発生しました: ' . \$e->getMessage();
        }
    }
}
?>

<!-- HTML部分に以下を追加 -->
<div class="col-md-6 mb-3">
    <label class="form-label">点検日</label>
    <input type="date" class="form-control" name="inspection_date" 
           value="<?= \$existing_inspection ? \$existing_inspection['inspection_date'] : \$today ?>" required>
</div>
<div class="col-md-6 mb-3">
    <label class="form-label">点検時刻</label>
    <input type="time" class="form-control" name="inspection_time" 
           value="<?= \$existing_inspection ? \$existing_inspection['inspection_time'] : \$current_time ?>" required>
</div>

<!-- 保存ボタン部分を以下に変更 -->
<div class="text-center mb-4">
    <button type="submit" class="btn btn-success btn-lg me-2">
        <i class="fas fa-save me-2"></i>登録する
    </button>
    <button type="submit" name="continue_to_call" value="1" class="btn btn-primary btn-lg">
        <i class="fas fa-arrow-right me-2"></i>登録して乗務前点呼へ
    </button>
</div>
PHP;
    
    echo htmlspecialchars($fixedCode);
    echo "</code></pre>";
    echo "</div></div>";
    
    // 7. 最終確認
    echo "<h4><i class='fas fa-check-circle'></i> 修正完了確認</h4>";
    echo "<div class='alert alert-success'>";
    echo "<h5>✅ 修正が完了しました！</h5>";
    echo "<p><strong>実装された機能:</strong></p>";
    echo "<ul>";
    echo "<li>✅ 新権限管理システム対応（permission_level + 職務フラグ）</li>";
    echo "<li>✅ 日時入力欄の追加（inspection_time カラム）</li>";
    echo "<li>✅ 連続業務フロー（日常点検 → 乗務前点呼）</li>";
    echo "<li>✅ テーブル情報の正確な反映</li>";
    echo "</ul>";
    echo "<p><strong>次のステップ:</strong></p>";
    echo "<ol>";
    echo "<li>上記コードをdaily_inspection.phpに適用</li>";
    echo "<li>動作テストを実行</li>";
    echo "<li>連続業務フローの確認</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h5><i class='fas fa-exclamation-circle'></i> データベースエラー</h5>";
    echo "<p>エラー詳細: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class='mt-4 d-flex gap-2'>
        <a href='dashboard.php' class='btn btn-primary'><i class='fas fa-home'></i> ダッシュボード</a>
        <a href='daily_inspection.php' class='btn btn-success'><i class='fas fa-clipboard-check'></i> 日常点検テスト</a>
    </div>
</div>
</body>
</html>";
?>
