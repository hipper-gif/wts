<?php
/**
 * 点呼者リスト表示修正
 * ファイル名: fix_caller_display.php
 * 
 * 問題解決: 過去の点呼記録から点呼者リストを取得
 */

// 認証なしでアクセス可能（緊急修正用）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// データベース接続
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4',
        'twinklemark_taxi',
        'Smiley2525',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

$action = $_GET['action'] ?? 'fix';

/**
 * 点呼者リスト取得（修正版）
 * 過去の点呼記録から重複なしで取得
 */
function getCallersFromHistory($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT caller_name as name
            FROM (
                SELECT caller_name FROM pre_duty_calls 
                WHERE caller_name IS NOT NULL 
                AND caller_name != '' 
                AND caller_name != '自動補完'
                UNION
                SELECT caller_name FROM post_duty_calls 
                WHERE caller_name IS NOT NULL 
                AND caller_name != ''
                AND caller_name != '自動補完'
            ) AS all_callers
            WHERE name IS NOT NULL 
            AND name != ''
            ORDER BY 
                CASE 
                    WHEN name LIKE '%システム管理者%' THEN 1
                    WHEN name LIKE '%管理者%' THEN 2
                    ELSE 3
                END,
                name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("点呼者取得エラー: " . $e->getMessage());
        return [];
    }
}

/**
 * 点呼者選択HTML生成（修正版）
 */
function generateCallerSelectHTML($pdo, $selected_name = null) {
    $callers = getCallersFromHistory($pdo);
    
    $html = '<select name="caller_name" id="caller_name" class="form-select" required>';
    $html .= '<option value="">点呼者を選択してください</option>';
    
    foreach ($callers as $caller) {
        $name = htmlspecialchars($caller['name']);
        $selected = ($selected_name === $caller['name']) ? 'selected' : '';
        $html .= "<option value=\"{$name}\" {$selected}>{$name}</option>";
    }
    
    $html .= '<option value="その他">その他</option>';
    $html .= '</select>';
    
    return $html;
}

/**
 * pre_duty_call.php と post_duty_call.php 用の修正コード生成
 */
function generateFixCode() {
    return '
/**
 * 点呼者リスト取得関数（pre_duty_call.php と post_duty_call.php に追加）
 */
function getCallersList($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT caller_name as name
            FROM (
                SELECT caller_name FROM pre_duty_calls 
                WHERE caller_name IS NOT NULL 
                AND caller_name != \'\' 
                AND caller_name != \'自動補完\'
                UNION
                SELECT caller_name FROM post_duty_calls 
                WHERE caller_name IS NOT NULL 
                AND caller_name != \'\'
                AND caller_name != \'自動補完\'
            ) AS all_callers
            WHERE name IS NOT NULL 
            AND name != \'\'
            ORDER BY 
                CASE 
                    WHEN name LIKE \'%システム管理者%\' THEN 1
                    WHEN name LIKE \'%管理者%\' THEN 2
                    ELSE 3
                END,
                name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("点呼者取得エラー: " . $e->getMessage());
        return [];
    }
}

// HTMLの点呼者選択部分を以下に置き換え：
// $callers = getCallersList($pdo);
// 
// echo \'<div class="mb-3">\';
// echo \'<label for="caller_name" class="form-label">点呼者 <span class="text-danger">*</span></label>\';
// echo \'<select name="caller_name" id="caller_name" class="form-select" required>\';
// echo \'<option value="">点呼者を選択してください</option>\';
// 
// foreach ($callers as $caller) {
//     $selected = (isset($selected_caller) && $selected_caller === $caller[\'name\']) ? \'selected\' : \'\';
//     echo "<option value=\"" . htmlspecialchars($caller[\'name\']) . "\" $selected>";
//     echo htmlspecialchars($caller[\'name\']) . "</option>";
// }
// 
// echo \'<option value="その他">その他</option>\';
// echo \'</select>\';
// echo \'</div>\';
';
}

if ($action === 'fix') {
    echo '<h2>✅ 点呼者リスト修正結果</h2>';
    
    // 現在の点呼者リスト確認
    $callers = getCallersFromHistory($pdo);
    
    echo '<h3>📋 取得できた点呼者リスト (' . count($callers) . '名):</h3>';
    if (!empty($callers)) {
        echo '<ul>';
        foreach ($callers as $caller) {
            echo '<li><strong>' . htmlspecialchars($caller['name']) . '</strong></li>';
        }
        echo '</ul>';
        
        echo '<h3>🔧 修正用HTMLサンプル:</h3>';
        echo '<div style="border: 1px solid #ccc; padding: 15px; background: #f9f9f9;">';
        echo generateCallerSelectHTML($pdo);
        echo '</div>';
        
        echo '<h3>📝 ファイル修正方法:</h3>';
        echo '<div style="background: #f8f9fa; padding: 20px; border-radius: 5px;">';
        echo '<h4>1. pre_duty_call.php の修正:</h4>';
        echo '<p>点呼者選択の部分を以下で置き換えてください：</p>';
        echo '<pre style="background: white; padding: 10px; border: 1px solid #ddd; font-size: 12px; overflow-x: auto;">';
        echo htmlspecialchars('
// 点呼者リスト取得
$callers_stmt = $pdo->query("
    SELECT DISTINCT caller_name as name
    FROM (
        SELECT caller_name FROM pre_duty_calls 
        WHERE caller_name IS NOT NULL AND caller_name != \'\' AND caller_name != \'自動補完\'
        UNION
        SELECT caller_name FROM post_duty_calls 
        WHERE caller_name IS NOT NULL AND caller_name != \'\' AND caller_name != \'自動補完\'
    ) AS all_callers
    WHERE name IS NOT NULL AND name != \'\'
    ORDER BY 
        CASE 
            WHEN name LIKE \'%システム管理者%\' THEN 1
            WHEN name LIKE \'%管理者%\' THEN 2
            ELSE 3
        END,
        name
");
$callers = $callers_stmt->fetchAll(PDO::FETCH_ASSOC);

// HTML出力部分
echo \'<div class="mb-3">\';
echo \'<label for="caller_name" class="form-label">点呼者 <span class="text-danger">*</span></label>\';
echo \'<select name="caller_name" id="caller_name" class="form-select" required>\';
echo \'<option value="">点呼者を選択してください</option>\';

foreach ($callers as $caller) {
    $selected = "";
    echo "<option value=\"" . htmlspecialchars($caller[\'name\']) . "\" $selected>";
    echo htmlspecialchars($caller[\'name\']) . "</option>";
}

echo \'<option value="その他">その他</option>\';
echo \'</select>\';
echo \'</div>\';
');
        echo '</pre>';
        
        echo '<h4>2. post_duty_call.php も同様に修正</h4>';
        echo '<p>同じコードを post_duty_call.php の点呼者選択部分にも適用してください。</p>';
        
        echo '</div>';
        
    } else {
        echo '<p style="color: red;">❌ 点呼者データが取得できませんでした。</p>';
    }
}

if ($action === 'test') {
    echo '<h2>🧪 点呼者リスト動作テスト</h2>';
    
    echo '<form method="POST" style="border: 1px solid #ccc; padding: 20px; margin: 20px 0;">';
    echo '<h3>点呼者選択テスト</h3>';
    echo generateCallerSelectHTML($pdo, $_POST['caller_name'] ?? null);
    echo '<br><br>';
    echo '<button type="submit" style="background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px;">選択テスト</button>';
    echo '</form>';
    
    if ($_POST) {
        echo '<div style="background: #d4edda; padding: 15px; border-radius: 5px;">';
        echo '<h4>✅ 選択結果:</h4>';
        echo '<p>選択された点呼者: <strong>' . htmlspecialchars($_POST['caller_name'] ?? 'なし') . '</strong></p>';
        echo '</div>';
    }
}

if ($action === 'download') {
    // 修正ファイルのダウンロード用コード生成
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="caller_fix_code.txt"');
    
    echo "# 点呼者リスト修正コード\n";
    echo "# pre_duty_call.php と post_duty_call.php の点呼者選択部分を以下で置き換え\n\n";
    echo generateFixCode();
    exit;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>点呼者リスト修正ツール</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 1200px; }
        .btn { padding: 10px 15px; margin: 5px; text-decoration: none; border-radius: 4px; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: black; }
        .section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
    </style>
</head>
<body>
    <h1>🔧 点呼者リスト修正ツール</h1>
    
    <div>
        <a href="?action=fix" class="btn btn-primary">🔧 修正実行</a>
        <a href="?action=test" class="btn btn-success">🧪 動作テスト</a>
        <a href="?action=download" class="btn btn-warning">📥 修正コードダウンロード</a>
        <a href="dashboard.php" class="btn" style="background: #6c757d; color: white;">🏠 ダッシュボード</a>
    </div>
    
    <div class="section">
        <h2>📋 問題の原因と解決</h2>
        <p><strong>原因:</strong> 点呼者データは過去の点呼記録に履歴として保存されているが、現在のコードで正しく取得できていない</p>
        <p><strong>解決:</strong> <code>pre_duty_calls.caller_name</code> と <code>post_duty_calls.caller_name</code> から重複なしで点呼者リストを取得</p>
        
        <h3>✅ 取得できる点呼者:</h3>
        <ul>
            <li>システム管理者</li>
            <li>管理者1</li>
            <li>保田　翔</li>
            <li>眞壁　亜友美</li>
            <li>保田</li>
            <li>杉原　星</li>
            <li>杉原　充</li>
            <li>杉原星</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>🚀 即座修正手順</h2>
        <ol>
            <li><strong>修正実行</strong>をクリックして、コードを確認</li>
            <li><strong>動作テスト</strong>で点呼者選択が動作するか確認</li>
            <li>提供されたコードを <code>pre_duty_call.php</code> と <code>post_duty_call.php</code> にコピー</li>
            <li>実際の点呼画面で点呼者リストが表示されるか確認</li>
        </ol>
    </div>
</body>
</html>
