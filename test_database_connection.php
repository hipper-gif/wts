<?php
// test_database_connection.php
// データベース接続テスト用スクリプト
// 実行日: 2025年8月29日

echo "<h1>📊 データベース接続テスト</h1>\n";
echo "<hr>\n";

// Step 1: config/database.php の存在確認
echo "<h3>Step 1: 設定ファイル確認</h3>\n";
if (file_exists('config/database.php')) {
    echo "✅ config/database.php が存在します<br>\n";
} else {
    echo "❌ config/database.php が見つかりません<br>\n";
    echo "<strong>解決方法:</strong> config/database.php を作成してください<br>\n";
    echo "<pre>\n";
    echo "<?php\n";
    echo "// config/database.php の例\n";
    echo "try {\n";
    echo "    \$pdo = new PDO(\n";
    echo "        'mysql:host=mysql###.xserver.jp;dbname=twinklemark_wts;charset=utf8',\n";
    echo "        'twinklemark_taxi',\n";
    echo "        'your_password',\n";
    echo "        [\n";
    echo "            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n";
    echo "            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n";
    echo "            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'\n";
    echo "        ]\n";
    echo "    );\n";
    echo "} catch (PDOException \$e) {\n";
    echo "    die('データベース接続エラー: ' . \$e->getMessage());\n";
    echo "}\n";
    echo "?>\n";
    echo "</pre>\n";
    exit;
}

// Step 2: データベース接続テスト
echo "<h3>Step 2: データベース接続テスト</h3>\n";
try {
    require_once 'config/database.php';
    
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "✅ \$pdo 変数が正しく設定されています<br>\n";
        
        // 接続テスト
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        
        if ($result['test'] == 1) {
            echo "✅ データベース接続成功<br>\n";
            
            // サーバー情報取得
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            echo "📋 MySQL バージョン: {$version}<br>\n";
            
        } else {
            echo "❌ データベースクエリ実行エラー<br>\n";
        }
    } else {
        echo "❌ \$pdo 変数が正しく設定されていません<br>\n";
        echo "config/database.php の内容を確認してください<br>\n";
    }
    
} catch (PDOException $e) {
    echo "❌ データベース接続エラー: " . $e->getMessage() . "<br>\n";
    echo "<strong>よくある原因:</strong><br>\n";
    echo "• データベースホスト名が間違っている<br>\n";
    echo "• ユーザー名・パスワードが間違っている<br>\n";
    echo "• データベース名が間違っている<br>\n";
    echo "• サーバーのファイアウォール設定<br>\n";
} catch (Exception $e) {
    echo "❌ 予期しないエラー: " . $e->getMessage() . "<br>\n";
}

// Step 3: テーブル存在確認
echo "<h3>Step 3: テーブル存在確認</h3>\n";
if (isset($pdo)) {
    try {
        $tables = ['ride_records', 'cash_count_details', 'users', 'vehicles'];
        
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            
            if ($stmt->fetch()) {
                echo "✅ {$table} テーブル存在<br>\n";
                
                // レコード数確認
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM {$table}");
                $count = $stmt->fetch()['count'];
                echo "&nbsp;&nbsp;📊 レコード数: {$count}<br>\n";
                
            } else {
                echo "❌ {$table} テーブルが見つかりません<br>\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ テーブル確認エラー: " . $e->getMessage() . "<br>\n";
    }
}

// Step 4: 料金カラム存在確認
echo "<h3>Step 4: ride_records 料金カラム確認</h3>\n";
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("DESCRIBE ride_records");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $required_columns = ['fare', 'payment_method'];
        $optional_columns = ['total_fare', 'cash_amount', 'card_amount', 'charge'];
        
        echo "<strong>必須カラム:</strong><br>\n";
        foreach ($required_columns as $col) {
            if (in_array($col, $columns)) {
                echo "✅ {$col}<br>\n";
            } else {
                echo "❌ {$col}<br>\n";
            }
        }
        
        echo "<strong>オプションカラム:</strong><br>\n";
        foreach ($optional_columns as $col) {
            if (in_array($col, $columns)) {
                echo "✅ {$col}<br>\n";
            } else {
                echo "⚠️ {$col} (フォールバック処理で対応)<br>\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ カラム確認エラー: " . $e->getMessage() . "<br>\n";
    }
}

// Step 5: 集金関数テスト
echo "<h3>Step 5: 集金管理関数テスト</h3>\n";
if (isset($pdo)) {
    try {
        if (file_exists('includes/cash_functions.php')) {
            echo "✅ includes/cash_functions.php 存在<br>\n";
            
            require_once 'includes/cash_functions.php';
            
            // 関数存在確認
            $functions = ['getTodayCashRevenue', 'getBaseChangeBreakdown', 'saveCashCount'];
            foreach ($functions as $func) {
                if (function_exists($func)) {
                    echo "✅ {$func}() 関数定義済み<br>\n";
                } else {
                    echo "❌ {$func}() 関数が見つかりません<br>\n";
                }
            }
            
            // 実際の関数テスト
            echo "<strong>関数実行テスト:</strong><br>\n";
            $today_data = getTodayCashRevenue($pdo);
            echo "📊 今日の売上取得: " . json_encode($today_data) . "<br>\n";
            
            $base_change = getBaseChangeBreakdown();
            echo "🧮 基準おつり設定: 総額¥" . number_format($base_change['total']['amount']) . "<br>\n";
            
        } else {
            echo "❌ includes/cash_functions.php が見つかりません<br>\n";
        }
        
    } catch (Exception $e) {
        echo "❌ 関数テストエラー: " . $e->getMessage() . "<br>\n";
    }
}

// Step 6: セッション確認
echo "<h3>Step 6: セッション設定確認</h3>\n";
session_start();

if (isset($_SESSION['user_id'])) {
    echo "✅ ユーザーセッション存在<br>\n";
    echo "&nbsp;&nbsp;👤 ユーザーID: " . $_SESSION['user_id'] . "<br>\n";
    echo "&nbsp;&nbsp;📝 ユーザー名: " . ($_SESSION['user_name'] ?? '未設定') . "<br>\n";
    echo "&nbsp;&nbsp;🔐 権限レベル: " . ($_SESSION['permission_level'] ?? '未設定') . "<br>\n";
} else {
    echo "⚠️ ユーザーセッションがありません<br>\n";
    echo "ログインページから再度ログインしてください<br>\n";
}

echo "<hr>\n";
echo "<h3>📋 総合判定</h3>\n";

$issues = [];
if (!file_exists('config/database.php')) {
    $issues[] = 'config/database.php が存在しない';
}
if (!isset($pdo)) {
    $issues[] = 'データベース接続に失敗';
}
if (!file_exists('includes/cash_functions.php')) {
    $issues[] = 'includes/cash_functions.php が存在しない';
}

if (empty($issues)) {
    echo "🎉 <strong>すべて正常です！集金管理システムを使用できます。</strong><br>\n";
    echo '<a href="cash_management.php" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">集金管理ページを開く</a><br>\n';
} else {
    echo "❌ <strong>以下の問題を修正してください:</strong><br>\n";
    foreach ($issues as $issue) {
        echo "• {$issue}<br>\n";
    }
}

echo "<br>\n";
echo "<small>テスト実行日時: " . date('Y-m-d H:i:s') . "</small>\n";
?>
