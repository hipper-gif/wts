<?php
echo "<h2>roleカラム参照一括修正スクリプト</h2>";

// 修正対象ファイルと修正内容の定義
$file_fixes = [
    'index.php' => [
        "SELECT id, name, login_id, password, role" => "SELECT id, name, login_id, password, permission_level",
        "\$_SESSION['user_role']" => "\$_SESSION['user_permission_level']",
        "\$user['role']" => "\$user['permission_level']",
        "role =" => "permission_level =",
    ],
    'dashboard.php' => [
        "\$_SESSION['user_role']" => "\$_SESSION['user_permission_level']",
        "\$user['role']" => "\$user['permission_level']",
        "role =" => "permission_level =",
    ],
    'pre_duty_call.php' => [
        "SELECT id, name, role FROM users WHERE (role" => "SELECT id, name, permission_level FROM users WHERE (permission_level",
        "role =" => "permission_level =",
    ],
    'post_duty_call.php' => [
        "SELECT id, name, role FROM users WHERE (role" => "SELECT id, name, permission_level FROM users WHERE (permission_level",
        "role =" => "permission_level =",
    ],
    'daily_inspection.php' => [
        "SELECT id, name, role FROM users WHERE (role" => "SELECT id, name, permission_level FROM users WHERE (permission_level",
        "\$user['role']" => "\$user['permission_level']",
        "role =" => "permission_level =",
    ],
    'periodic_inspection.php' => [
        "SELECT id, name, role FROM users WHERE role" => "SELECT id, name, permission_level FROM users WHERE permission_level",
    ],
    'departure.php' => [
        "SELECT id, name FROM users WHERE (role" => "SELECT id, name FROM users WHERE (permission_level",
        "\$_SESSION['user_role']" => "\$_SESSION['user_permission_level']",
        "role =" => "permission_level =",
    ],
    'arrival.php' => [
        "SELECT id, name FROM users WHERE (role" => "SELECT id, name FROM users WHERE (permission_level",
        "\$_SESSION['user_role']" => "\$_SESSION['user_permission_level']",
        "role =" => "permission_level =",
    ],
    'ride_records.php' => [
        "SELECT id, name, role" => "SELECT id, name, permission_level",
        "\$user['role']" => "\$user['permission_level']",
        "role =" => "permission_level =",
    ],
    'vehicle_management.php' => [
        "\$_SESSION['user_role']" => "\$_SESSION['user_permission_level']",
        "role =" => "permission_level =",
    ]
];

// 権限値の修正マップ
$permission_value_fixes = [
    "'admin'" => "'Admin'",
    '"admin"' => '"Admin"',
    "'user'" => "'User'",
    '"user"' => '"User"',
    "= 'admin'" => "= 'Admin'",
    '= "admin"' => '= "Admin"',
    "= 'user'" => "= 'User'",
    '= "user"' => '= "User"',
    "!== 'admin'" => "!== 'Admin'",
    '!== "admin"' => '!== "Admin"',
    "== 'admin'" => "== 'Admin'",
    '== "admin"' => '== "Admin"',
];

$backup_dir = 'backup_before_role_fix_' . date('Y-m-d_H-i-s');

// バックアップディレクトリ作成
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
    echo "<p style='color: green;'>✅ バックアップディレクトリを作成: {$backup_dir}</p>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_fix'])) {
    echo "<h3>🔧 修正実行中...</h3>";
    
    $fixed_files = [];
    $errors = [];
    
    foreach ($file_fixes as $filename => $fixes) {
        if (!file_exists($filename)) {
            $errors[] = "ファイルが見つかりません: {$filename}";
            continue;
        }
        
        // バックアップ作成
        $backup_file = $backup_dir . '/' . $filename;
        if (!copy($filename, $backup_file)) {
            $errors[] = "バックアップ作成に失敗: {$filename}";
            continue;
        }
        
        // ファイル内容読み込み
        $content = file_get_contents($filename);
        $original_content = $content;
        
        // roleカラム参照を修正
        foreach ($fixes as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
        
        // 権限値を修正（admin/user → Admin/User）
        foreach ($permission_value_fixes as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
        
        // 変更があった場合のみファイルを更新
        if ($content !== $original_content) {
            if (file_put_contents($filename, $content)) {
                $fixed_files[] = $filename;
                echo "<p style='color: green;'>✅ 修正完了: {$filename}</p>";
            } else {
                $errors[] = "ファイル更新に失敗: {$filename}";
            }
        } else {
            echo "<p style='color: gray;'>ℹ️ 変更なし: {$filename}</p>";
        }
    }
    
    echo "<h3>📋 修正結果</h3>";
    echo "<p><strong>修正されたファイル数:</strong> " . count($fixed_files) . "</p>";
    
    if (!empty($fixed_files)) {
        echo "<h4>✅ 修正されたファイル:</h4>";
        echo "<ul>";
        foreach ($fixed_files as $file) {
            echo "<li>{$file}</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($errors)) {
        echo "<h4>❌ エラー:</h4>";
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li style='color: red;'>{$error}</li>";
        }
        echo "</ul>";
    }
    
    echo "<h3>🎯 次のステップ</h3>";
    echo "<ol>";
    echo "<li>各ファイルの動作確認を行ってください</li>";
    echo "<li>問題がなければ、<a href='remove_role_column.php'>roleカラム削除スクリプト</a>を実行してください</li>";
    echo "<li>問題があった場合は、バックアップから復元してください</li>";
    echo "</ol>";
    
    echo "<h4>🔄 バックアップからの復元方法</h4>";
    echo "<div style='background-color: #f8f9fa; padding: 10px; border-radius: 5px;'>";
    echo "<pre>";
    foreach ($fixed_files as $file) {
        echo "cp {$backup_dir}/{$file} {$file}\n";
    }
    echo "</pre>";
    echo "</div>";
    
} else {
    // 修正内容のプレビュー
    echo "<h3>📋 修正予定の内容</h3>";
    
    foreach ($file_fixes as $filename => $fixes) {
        if (file_exists($filename)) {
            echo "<h4>📄 {$filename}</h4>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th style='width: 50%;'>修正前</th><th style='width: 50%;'>修正後</th></tr>";
            
            foreach ($fixes as $search => $replace) {
                echo "<tr>";
                echo "<td><code>" . htmlspecialchars($search) . "</code></td>";
                echo "<td><code>" . htmlspecialchars($replace) . "</code></td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: orange;'>⚠️ ファイルが見つかりません: {$filename}</p>";
        }
    }
    
    echo "<h3>🔧 権限値の修正</h3>";
    echo "<p>さらに、以下の権限値も修正されます:</p>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>修正前</th><th>修正後</th></tr>";
    foreach ($permission_value_fixes as $search => $replace) {
        echo "<tr>";
        echo "<td><code>" . htmlspecialchars($search) . "</code></td>";
        echo "<td><code>" . htmlspecialchars($replace) . "</code></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>⚠️ 重要な注意事項</h3>";
    echo "<div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; border: 1px solid #ffeaa7;'>";
    echo "<ul>";
    echo "<li><strong>バックアップ:</strong> 自動的にバックアップが作成されます</li>";
    echo "<li><strong>権限値:</strong> 'admin'/'user' → 'Admin'/'User' に統一されます</li>";
    echo "<li><strong>セッション:</strong> 'user_role' → 'user_permission_level' に変更されます</li>";
    echo "<li><strong>復元方法:</strong> 問題があった場合は上記のコマンドで復元できます</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<form method='POST'>";
    echo "<button type='submit' name='execute_fix' value='1' style='background-color: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;' onclick=\"return confirm('実行前にバックアップが作成されます。続行しますか？')\">一括修正を実行する</button>";
    echo "</form>";
}
?>

<style>
table {
    margin: 10px 0;
}
th, td {
    padding: 8px;
    text-align: left;
    border: 1px solid #ddd;
}
th {
    background-color: #f2f2f2;
}
code {
    background-color: #f8f9fa;
    padding: 2px 4px;
    border-radius: 3px;
    font-family: monospace;
}
</style>
