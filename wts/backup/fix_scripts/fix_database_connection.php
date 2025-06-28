<?php
/**
 * データベース接続エラー修正スクリプト
 * 新システムファイルのデータベース接続を修正します
 */

echo "<h2>🔧 データベース接続エラー修正</h2>\n";
echo "<pre>\n";

try {
    echo "問題分析中...\n";
    echo "エラー: 新システムファイルで \$pdo 変数が未定義\n";
    echo "原因: config/database.php の読み込み方法が統一されていない\n\n";
    
    // 修正対象ファイル
    $files_to_fix = [
        'departure.php',
        'arrival.php', 
        'ride_records.php'
    ];
    
    echo "修正対象ファイル:\n";
    foreach ($files_to_fix as $file) {
        echo "- {$file}\n";
    }
    echo "\n";
    
    // 各ファイルの修正
    foreach ($files_to_fix as $filename) {
        if (!file_exists($filename)) {
            echo "⚠️ ファイルが見つかりません: {$filename}\n";
            continue;
        }
        
        echo "📝 {$filename} を修正中...\n";
        
        // ファイル内容を読み込み
        $content = file_get_contents($filename);
        
        if ($content === false) {
            echo "❌ ファイル読み込みエラー: {$filename}\n";
            continue;
        }
        
        // 既存のデータベース接続部分を統一された形式に置換
        $old_patterns = [
            // パターン1: 複雑な条件分岐
            '/\/\/ データベース接続.*?catch \(PDOException \$e\) \{.*?\}/s',
            // パターン2: 直接接続
            '/try \{.*?if \(file_exists\(\'config\/database\.php\'\)\).*?\}/s'
        ];
        
        // 統一されたデータベース接続コード
        $new_connection = '// データベース接続
require_once \'config/database.php\';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("データベース接続エラー: " . $e->getMessage());
}';
        
        // 置換実行
        $updated = false;
        foreach ($old_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $new_connection, $content);
                $updated = true;
                break;
            }
        }
        
        // パターンマッチしない場合は、先頭部分を直接置換
        if (!$updated) {
            // session_start() の後にデータベース接続を挿入
            if (strpos($content, 'session_start();') !== false) {
                $content = str_replace(
                    'session_start();',
                    "session_start();\n\n" . $new_connection,
                    $content
                );
                $updated = true;
            }
        }
        
        if ($updated) {
            // バックアップを作成
            $backup_name = "backup_{$filename}_" . date('Y-m-d_H-i-s');
            if (copy($filename, $backup_name)) {
                echo "✓ バックアップ作成: {$backup_name}\n";
            }
            
            // 修正されたファイルを保存
            if (file_put_contents($filename, $content)) {
                echo "✓ {$filename} 修正完了\n";
            } else {
                echo "❌ {$filename} 書き込みエラー\n";
            }
        } else {
            echo "⚠️ {$filename} パターンマッチしませんでした\n";
        }
        
        echo "\n";
    }
    
    echo str_repeat("=", 50) . "\n";
    echo "✅ データベース接続修正完了！\n";
    echo str_repeat("=", 50) . "\n\n";
    
    echo "📋 修正内容:\n";
    echo "・統一されたデータベース接続方式に変更\n";
    echo "・config/database.php の getDBConnection() 関数を使用\n";
    echo "・エラーハンドリングを簡素化\n\n";
    
    echo "🔍 動作確認手順:\n";
    echo "1. 出庫処理画面にアクセス\n";
    echo "2. 入庫処理画面にアクセス\n";
    echo "3. 乗車記録画面にアクセス\n";
    echo "4. エラーが解消されているか確認\n\n";
    
    echo "⚠️ 問題が継続する場合:\n";
    echo "・backup_*.php ファイルから復元可能\n";
    echo "・config/database.php の設定を確認\n";
    echo "・ファイルの権限を確認\n\n";
    
} catch (Exception $e) {
    echo "❌ 修正エラー: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #dc3545; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>