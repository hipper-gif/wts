<?php
/**
 * ファイル自動置き換えスクリプト
 * dashboard_fixed.php → dashboard.php に安全に置き換え
 */

echo "<h1>🔄 ファイル自動置き換えスクリプト</h1>";
echo "<div style='font-family: Arial; background: #f8f9fa; padding: 20px;'>";

// 安全確認
$execute = isset($_GET['execute']) && $_GET['execute'] === 'true';

if (!$execute) {
    echo "<div style='background: #fff3cd; padding: 15px; border: 2px solid #ffc107; margin: 20px 0;'>";
    echo "<h3>⚠️ 実行前確認</h3>";
    echo "<p>以下の処理を実行します：</p>";
    echo "<ol>";
    echo "<li><strong>dashboard.php</strong> を <strong>dashboard_backup.php</strong> にバックアップ</li>";
    echo "<li><strong>dashboard_fixed.php</strong> を <strong>dashboard.php</strong> にコピー</li>";
    echo "<li>不要ファイル <strong>dashboard_fixed.php</strong> を削除</li>";
    echo "</ol>";
    echo "<p>実行する場合は、URL末尾に <strong>?execute=true</strong> を追加してください。</p>";
    echo "</div>";
    
    // 現在のファイル状況確認
    echo "<h2>📁 現在のファイル状況</h2>";
    
    $files_to_check = [
        'dashboard.php' => '元のダッシュボード',
        'dashboard_fixed.php' => '修正版ダッシュボード',
        'dashboard_backup.php' => 'バックアップ（存在すれば）'
    ];
    
    foreach ($files_to_check as $file => $description) {
        if (file_exists($file)) {
            $size = filesize($file);
            $modified = date('Y-m-d H:i:s', filemtime($file));
            echo "<div style='background: #d4edda; padding: 8px; margin: 5px 0; border-radius: 3px;'>";
            echo "✅ <strong>{$file}</strong> - {$description}<br>";
            echo "　　サイズ: " . number_format($size) . " bytes, 更新: {$modified}";
            echo "</div>";
        } else {
            echo "<div style='background: #f8d7da; padding: 8px; margin: 5px 0; border-radius: 3px;'>";
            echo "❌ <strong>{$file}</strong> - {$description} (存在しません)";
            echo "</div>";
        }
    }
    
    echo "</div>";
    return;
}

// 実際の処理実行
echo "<h2>🚀 置き換え処理実行</h2>";

try {
    // ステップ1: バックアップ作成
    echo "<h3>ステップ1: バックアップ作成</h3>";
    
    if (file_exists('dashboard.php')) {
        $backup_name = 'dashboard_backup_' . date('Y-m-d_H-i-s') . '.php';
        
        if (copy('dashboard.php', $backup_name)) {
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
            echo "✅ バックアップ作成完了: <strong>{$backup_name}</strong>";
            echo "</div>";
        } else {
            throw new Exception("バックアップ作成に失敗しました");
        }
    } else {
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
        echo "⚠️ 元のdashboard.phpが存在しません（新規作成）";
        echo "</div>";
    }
    
    // ステップ2: 修正版をコピー
    echo "<h3>ステップ2: 修正版をメインファイルにコピー</h3>";
    
    if (file_exists('dashboard_fixed.php')) {
        if (copy('dashboard_fixed.php', 'dashboard.php')) {
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
            echo "✅ コピー完了: dashboard_fixed.php → dashboard.php";
            echo "</div>";
        } else {
            throw new Exception("ファイルコピーに失敗しました");
        }
    } else {
        throw new Exception("dashboard_fixed.php が存在しません");
    }
    
    // ステップ3: 修正版ファイル削除
    echo "<h3>ステップ3: 不要ファイル削除</h3>";
    
    if (unlink('dashboard_fixed.php')) {
        echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
        echo "✅ 削除完了: dashboard_fixed.php";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
        echo "⚠️ dashboard_fixed.php削除に失敗（手動削除してください）";
        echo "</div>";
    }
    
    // 完了メッセージ
    echo "<div style='background: #d1ecf1; padding: 20px; border: 2px solid #0dcaf0; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🎉 置き換え完了！</h3>";
    echo "<p><strong>メインダッシュボードが修正版に更新されました。</strong></p>";
    echo "<p>以下のリンクで動作確認してください：</p>";
    echo "<p><a href='dashboard.php' target='_blank' style='background: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>📊 ダッシュボードを開く</a></p>";
    echo "</div>";
    
    // 処理結果サマリー
    echo "<h3>📋 処理結果サマリー</h3>";
    echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>";
    echo "<ul>";
    echo "<li>✅ バックアップ作成: {$backup_name}</li>";
    echo "<li>✅ メインファイル更新: dashboard.php</li>";
    echo "<li>✅ 不要ファイル削除: dashboard_fixed.php</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border: 2px solid #dc3545; border-radius: 5px;'>";
    echo "<h3>❌ エラー発生</h3>";
    echo "<p>エラー内容: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>手動でファイルを確認してください。</p>";
    echo "</div>";
}

echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
h1, h2, h3 { color: #333; }
a { font-weight: bold; }
</style>
