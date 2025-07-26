<?php
session_start();

// データベース接続（直接定義）
try {
    $host = 'localhost';
    $dbname = 'twinklemark_wts';
    $username = 'twinklemark_taxi';
    $password = 'Smiley2525';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>✅ データベース接続成功</h1>";
    
} catch (PDOException $e) {
    die("<h1>❌ データベース接続エラー</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>");
}

echo "<h2>🔍 現在のセッション情報</h2>";
echo "<div style='background: #f8f9fa; padding: 15px; border: 1px solid #ddd; margin: 10px 0;'>";
if (isset($_SESSION['user_id'])) {
    echo "<p><strong>ユーザーID:</strong> " . htmlspecialchars($_SESSION['user_id']) . "</p>";
    if (isset($_SESSION['role'])) {
        echo "<p><strong>セッション権限:</strong> " . htmlspecialchars($_SESSION['role']) . "</p>";
    }
    if (isset($_SESSION['username'])) {
        echo "<p><strong>ユーザー名:</strong> " . htmlspecialchars($_SESSION['username']) . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ ログインしていません</p>";
    echo "<a href='index.php'>ログイン画面へ</a>";
    exit;
}
echo "</div>";

// 権限修正処理
if (isset($_POST['fix_permissions']) && isset($_SESSION['user_id'])) {
    try {
        // 現在のユーザーをシステム管理者に設定
        $stmt = $pdo->prepare("UPDATE users SET role = 'システム管理者' WHERE id = ?");
        $result = $stmt->execute([$_SESSION['user_id']]);
        
        if ($result) {
            // セッション情報も更新
            $_SESSION['role'] = 'システム管理者';
            
            echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
            echo "<h3>✅ 権限修正完了</h3>";
            echo "<p>ユーザーID " . htmlspecialchars($_SESSION['user_id']) . " の権限を「システム管理者」に更新しました</p>";
            echo "<p>セッション情報も更新されました</p>";
            echo "</div>";
            
            echo "<script>setTimeout(function(){ location.reload(); }, 2000);</script>";
        } else {
            echo "<p style='color: red;'>❌ 権限更新に失敗しました</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>権限更新エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 現在のユーザー情報を取得
try {
    $stmt = $pdo->prepare("SELECT id, name, login_id, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo "<h2>📊 データベースのユーザー情報</h2>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'><th style='padding: 10px;'>項目</th><th style='padding: 10px;'>値</th></tr>";
        echo "<tr><td style='padding: 10px;'><strong>ID</strong></td><td style='padding: 10px;'>" . htmlspecialchars($user['id']) . "</td></tr>";
        echo "<tr><td style='padding: 10px;'><strong>名前</strong></td><td style='padding: 10px;'>" . htmlspecialchars($user['name']) . "</td></tr>";
        echo "<tr><td style='padding: 10px;'><strong>ログインID</strong></td><td style='padding: 10px;'>" . htmlspecialchars($user['login_id']) . "</td></tr>";
        echo "<tr><td style='padding: 10px;'><strong>現在の権限</strong></td><td style='padding: 10px; font-size: 18px;'><strong style='color: " . ($user['role'] === 'システム管理者' ? 'green' : 'red') . ";'>" . htmlspecialchars($user['role']) . "</strong></td></tr>";
        echo "</table>";
        
        // 権限チェック結果
        echo "<h2>🎯 権限チェック結果</h2>";
        echo "<div style='background: " . ($user['role'] === 'システム管理者' ? '#d4edda' : '#f8d7da') . "; padding: 15px; border-radius: 4px; margin: 10px 0;'>";
        echo "<ul style='margin: 0; padding-left: 20px;'>";
        
        $is_system_admin = ($user['role'] === 'システム管理者');
        $is_admin = in_array($user['role'], ['システム管理者', '管理者']);
        $is_driver = in_array($user['role'], ['システム管理者', '管理者', '運転者']);
        
        echo "<li>システム管理者権限: " . ($is_system_admin ? '✅ あり' : '❌ なし') . "</li>";
        echo "<li>管理者権限: " . ($is_admin ? '✅ あり' : '❌ なし') . "</li>";
        echo "<li>運転者権限: " . ($is_driver ? '✅ あり' : '❌ なし') . "</li>";
        echo "</ul>";
        echo "</div>";
        
        // アクセス可能な機能
        echo "<h2>🔑 アクセス可能な機能</h2>";
        echo "<div style='display: flex; flex-wrap: wrap; gap: 10px;'>";
        
        if ($is_system_admin) {
            echo "<span style='background: #28a745; color: white; padding: 5px 10px; border-radius: 3px;'>accident_management.php</span>";
            echo "<span style='background: #28a745; color: white; padding: 5px 10px; border-radius: 3px;'>annual_report.php</span>";
            echo "<span style='background: #28a745; color: white; padding: 5px 10px; border-radius: 3px;'>emergency_audit_kit.php</span>";
        } else {
            echo "<span style='background: #dc3545; color: white; padding: 5px 10px; border-radius: 3px;'>accident_management.php ❌</span>";
            echo "<span style='background: #dc3545; color: white; padding: 5px 10px; border-radius: 3px;'>annual_report.php ❌</span>";
            echo "<span style='background: #dc3545; color: white; padding: 5px 10px; border-radius: 3px;'>emergency_audit_kit.php ❌</span>";
        }
        
        if ($is_admin) {
            echo "<span style='background: #007bff; color: white; padding: 5px 10px; border-radius: 3px;'>cash_management.php</span>";
            echo "<span style='background: #007bff; color: white; padding: 5px 10px; border-radius: 3px;'>user_management.php</span>";
            echo "<span style='background: #007bff; color: white; padding: 5px 10px; border-radius: 3px;'>vehicle_management.php</span>";
        }
        
        echo "</div>";
        
    } else {
        echo "<p style='color: red;'>❌ ユーザーが見つかりません</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>ユーザー情報取得エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 全ユーザーの権限一覧
try {
    echo "<h2>👥 全ユーザーの権限一覧</h2>";
    $stmt = $pdo->query("SELECT id, name, login_id, role FROM users ORDER BY id");
    $all_users = $stmt->fetchAll();
    
    if ($all_users) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
        echo "<tr style='background: #f8f9fa;'><th style='padding: 10px;'>ID</th><th style='padding: 10px;'>名前</th><th style='padding: 10px;'>ログインID</th><th style='padding: 10px;'>権限</th></tr>";
        foreach ($all_users as $u) {
            $highlight = ($u['id'] == $_SESSION['user_id']) ? 'background-color: #fff3cd;' : '';
            $role_color = ($u['role'] === 'システム管理者') ? 'color: green; font-weight: bold;' : '';
            echo "<tr style='{$highlight}'>";
            echo "<td style='padding: 10px;'>" . htmlspecialchars($u['id']) . "</td>";
            echo "<td style='padding: 10px;'>" . htmlspecialchars($u['name']) . "</td>";
            echo "<td style='padding: 10px;'>" . htmlspecialchars($u['login_id']) . "</td>";
            echo "<td style='padding: 10px; {$role_color}'>" . htmlspecialchars($u['role']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>ユーザー一覧取得エラー: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 権限修正フォーム
if (isset($user) && $user['role'] !== 'システム管理者') {
    echo "<h2>⚡ 権限修正</h2>";
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
    echo "<p><strong>現在の権限では一部機能にアクセスできません。</strong></p>";
    echo "<p>「システム管理者」権限に変更して全機能を利用可能にしますか？</p>";
    echo "<form method='post' style='margin: 10px 0;'>";
    echo "<button type='submit' name='fix_permissions' style='background: #dc3545; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px;'>";
    echo "🔑 システム管理者権限に変更";
    echo "</button>";
    echo "</form>";
    echo "</div>";
}

// テストリンク
echo "<h2>🧪 機能テスト</h2>";
echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 20px 0;'>";

// 基本機能
echo "<div style='border: 1px solid #ddd; padding: 15px; border-radius: 4px;'>";
echo "<h4>基本機能</h4>";
echo "<a href='dashboard.php' style='display: block; margin: 5px 0; color: #007bff;'>📊 ダッシュボード</a>";
echo "<a href='departure.php' style='display: block; margin: 5px 0; color: #007bff;'>🚀 出庫処理</a>";
echo "<a href='arrival.php' style='display: block; margin: 5px 0; color: #007bff;'>🏁 入庫処理</a>";
echo "<a href='ride_records.php' style='display: block; margin: 5px 0; color: #007bff;'>🚗 乗車記録</a>";
echo "</div>";

// 管理機能
echo "<div style='border: 1px solid #ddd; padding: 15px; border-radius: 4px;'>";
echo "<h4>管理機能</h4>";
echo "<a href='cash_management.php' style='display: block; margin: 5px 0; color: #007bff;'>💰 集金管理</a>";
echo "<a href='user_management.php' style='display: block; margin: 5px 0; color: #007bff;'>👥 ユーザー管理</a>";
echo "<a href='vehicle_management.php' style='display: block; margin: 5px 0; color: #007bff;'>🚗 車両管理</a>";
echo "</div>";

// 高級機能
echo "<div style='border: 1px solid #ddd; padding: 15px; border-radius: 4px;'>";
echo "<h4>高級機能 (システム管理者のみ)</h4>";
echo "<a href='accident_management.php' style='display: block; margin: 5px 0; color: " . (isset($user) && $user['role'] === 'システム管理者' ? '#007bff' : '#dc3545') . ";'>🚨 事故管理</a>";
echo "<a href='annual_report.php' style='display: block; margin: 5px 0; color: " . (isset($user) && $user['role'] === 'システム管理者' ? '#007bff' : '#dc3545') . ";'>📄 陸運局提出</a>";
echo "<a href='emergency_audit_kit.php' style='display: block; margin: 5px 0; color: " . (isset($user) && $user['role'] === 'システム管理者' ? '#007bff' : '#dc3545') . ";'>⚡ 緊急監査対応</a>";
echo "</div>";

echo "</div>";

// SQL手動実行用
echo "<h2>🛠 手動修正用SQL</h2>";
echo "<div style='background: #f8f9fa; padding: 15px; border: 1px solid #ddd; margin: 10px 0; border-radius: 4px;'>";
echo "<p><strong>phpMyAdminで実行する場合：</strong></p>";
echo "<code style='background: #e9ecef; padding: 10px; display: block; margin: 5px 0; border-radius: 3px;'>";
if (isset($user)) {
    echo "UPDATE users SET role = 'システム管理者' WHERE id = " . intval($user['id']) . ";<br>";
    echo "-- または<br>";
    echo "UPDATE users SET role = 'システム管理者' WHERE login_id = '" . htmlspecialchars($user['login_id']) . "';";
} else {
    echo "UPDATE users SET role = 'システム管理者' WHERE login_id = 'あなたのログインID';";
}
echo "</code>";
echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h1, h2, h3, h4 { color: #333; }
table { width: 100%; }
th, td { text-align: left; }
</style>
