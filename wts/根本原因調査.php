<?php
/**
 * 根本原因調査ツール
 * usersテーブルの実際の構造を確認し、正しい修正を実行
 */

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4",
        "twinklemark_taxi",
        "Smiley2525",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

echo "<h1>🔍 根本原因調査</h1>";

// 1. usersテーブル構造確認
echo "<h2>1. usersテーブル構造</h2>";
$stmt = $pdo->query("DESCRIBE users");
$columns = $stmt->fetchAll();

echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>カラム名</th><th>データ型</th></tr>";
foreach ($columns as $col) {
    echo "<tr><td><strong>{$col['Field']}</strong></td><td>{$col['Type']}</td></tr>";
}
echo "</table>";

// 2. 実際のユーザーデータサンプル
echo "<h2>2. 実際のユーザーデータ</h2>";
$stmt = $pdo->query("SELECT * FROM users LIMIT 1");
$user_sample = $stmt->fetch();

if ($user_sample) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>カラム</th><th>値</th></tr>";
    foreach ($user_sample as $field => $value) {
        echo "<tr><td><strong>{$field}</strong></td><td>" . ($value ?: 'NULL') . "</td></tr>";
    }
    echo "</table>";
}

// 3. 正しいカラム名特定
$name_candidates = ['name', 'username', 'user_name', 'login_id', 'login_name'];
$correct_name_field = null;

foreach ($name_candidates as $candidate) {
    if (isset($user_sample[$candidate])) {
        $correct_name_field = $candidate;
        break;
    }
}

echo "<h2>3. 修正内容</h2>";
if ($correct_name_field) {
    echo "<div style='background: green; color: white; padding: 10px;'>";
    echo "✅ 正しいカラム名: <strong>{$correct_name_field}</strong>";
    echo "</div>";
    
    // 4. dashboard_fixed.php修正
    if (file_exists('dashboard_fixed.php')) {
        $dashboard_content = file_get_contents('dashboard_fixed.php');
        
        // 42行目付近の name を正しいカラム名に修正
        $fixed_content = str_replace(
            "htmlspecialchars(\$user['name'])",
            "htmlspecialchars(\$user['{$correct_name_field}'])",
            $dashboard_content
        );
        
        file_put_contents('dashboard_fixed.php', $fixed_content);
        
        echo "<div style='background: blue; color: white; padding: 10px; margin-top: 10px;'>";
        echo "✅ dashboard_fixed.php修正完了";
        echo "</div>";
    }
    
} else {
    echo "<div style='background: red; color: white; padding: 10px;'>";
    echo "❌ 名前用のカラムが見つかりません";
    echo "</div>";
}

// 5. 動作確認
echo "<h2>4. 修正後テスト</h2>";
echo "<a href='dashboard_fixed.php' target='_blank' style='background: blue; color: white; padding: 10px; text-decoration: none;'>修正版ダッシュボードをテスト</a>";

?>
