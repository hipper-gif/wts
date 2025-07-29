<?php
echo "<h2>roleカラム使用箇所確認</h2>";

// チェック対象のファイル
$files_to_check = [
    'index.php',
    'dashboard.php',
    'pre_duty_call.php',
    'post_duty_call.php',
    'daily_inspection.php',
    'periodic_inspection.php',
    'departure.php',
    'arrival.php',
    'ride_records.php',
    'user_management.php',
    'vehicle_management.php'
];

$role_references = [];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // roleカラムへの参照をチェック
        $patterns = [
            '/user_role/',
            '/\[\'role\'\]/',
            '/\.role/',
            '/role\s*=/',
            '/role\s*!/',
            '/SELECT.*role/',
            '/UPDATE.*role/',
            '/INSERT.*role/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                if (!isset($role_references[$file])) {
                    $role_references[$file] = [];
                }
                $role_references[$file][] = $matches[0];
            }
        }
    }
}

if (empty($role_references)) {
    echo "<p style='color: green;'>✅ roleカラムへの参照は見つかりませんでした。安全に削除できます。</p>";
} else {
    echo "<p style='color: orange;'>⚠️ 以下のファイルでroleカラムへの参照が見つかりました:</p>";
    echo "<ul>";
    foreach ($role_references as $file => $references) {
        echo "<li><strong>{$file}</strong>";
        echo "<ul>";
        foreach (array_unique($references) as $ref) {
            echo "<li><code>" . htmlspecialchars($ref) . "</code></li>";
        }
        echo "</ul>";
        echo "</li>";
    }
    echo "</ul>";
    echo "<p><strong>対応が必要:</strong> これらのファイルでroleをpermission_levelに変更してください。</p>";
}

// セッション変数のチェック
echo "<h3>セッション変数の確認</h3>";
session_start();
if (isset($_SESSION['user_role'])) {
    echo "<p style='color: orange;'>⚠️ セッションに'user_role'が設定されています。</p>";
    echo "<p>値: " . htmlspecialchars($_SESSION['user_role']) . "</p>";
    echo "<p><strong>対応必要:</strong> ログイン処理で'user_role'セッションを'user_permission_level'に変更してください。</p>";
} else {
    echo "<p style='color: green;'>✅ セッションに'user_role'は設定されていません。</p>";
}

echo "<h3>推奨修正内容</h3>";
echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px;'>";
echo "<h4>1. ログイン処理（index.php）の修正例:</h4>";
echo "<pre>";
echo "// 修正前\n";
echo "\$_SESSION['user_role'] = \$user['role'];\n\n";
echo "// 修正後\n";
echo "\$_SESSION['user_permission_level'] = \$user['permission_level'];\n";
echo "</pre>";

echo "<h4>2. 権限チェックの修正例:</h4>";
echo "<pre>";
echo "// 修正前\n";
echo "if (\$_SESSION['user_role'] !== 'admin') {\n\n";
echo "// 修正後\n";
echo "if (\$_SESSION['user_permission_level'] !== 'Admin') {\n";
echo "</pre>";
echo "</div>";
?>

<script>
// 自動リロード（10秒後）
setTimeout(function() {
    location.reload();
}, 10000);
</script>

<p><small>このページは10秒ごとに自動更新されます。</small></p>
