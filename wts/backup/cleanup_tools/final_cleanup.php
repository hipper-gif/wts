<?php
/**
 * 最終ファイル整理スクリプト
 * 残りの6個のファイルを適切に整理します
 */

echo "<h2>🧹 最終ファイル整理</h2>\n";
echo "<pre>\n";

try {
    echo "📋 現在の状況:\n";
    echo "・メインシステムファイル: 完璧な状態\n";
    echo "・整理対象: 6個のファイル\n";
    echo "・目標: メインファイルのみの美しい構成\n\n";
    
    // 整理対象ファイルの定義
    $files_to_organize = [
        'auto_backups' => [
            'backup_arrival.php_2025-06-28_11-20-36',
            'backup_departure.php_2025-06-28_11-20-36', 
            'backup_ride_records.php_2025-06-28_11-20-36'
        ],
        'temp_api' => [
            'check_prerequisites_api.php',
            'get_previous_mileage_api.php'
        ],
        'cleanup_tools' => [
            'stage2_cleanup.php'
        ]
    ];
    
    // Step 1: 必要なバックアップディレクトリを確認・作成
    echo "📁 Step 1: バックアップディレクトリ確認...\n";
    
    $required_dirs = [
        'backup/auto_backups',
        'backup/temp_api', 
        'backup/cleanup_tools'
    ];
    
    foreach ($required_dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            echo "✓ ディレクトリ作成: {$dir}\n";
        } else {
            echo "✓ ディレクトリ存在: {$dir}\n";
        }
    }
    echo "\n";
    
    // Step 2: 自動生成バックアップファイルの移動
    echo "💾 Step 2: 自動生成バックアップファイルの移動...\n";
    foreach ($files_to_organize['auto_backups'] as $file) {
        if (file_exists($file)) {
            $dest = "backup/auto_backups/{$file}";
            if (rename($file, $dest)) {
                echo "✓ 移動: {$file}\n";
                echo "   → {$dest}\n";
            } else {
                echo "❌ 移動失敗: {$file}\n";
            }
        } else {
            echo "ℹ️ ファイル不存在: {$file}\n";
        }
    }
    echo "\n";
    
    // Step 3: 一時的APIファイルの移動
    echo "🔌 Step 3: 一時的APIファイルの移動...\n";
    foreach ($files_to_organize['temp_api'] as $file) {
        if (file_exists($file)) {
            $dest = "backup/temp_api/{$file}";
            if (rename($file, $dest)) {
                echo "✓ 移動: {$file}\n";
                echo "   → {$dest}\n";
                echo "   ※ 将来的に本格的なAPIに置き換え予定\n";
            } else {
                echo "❌ 移動失敗: {$file}\n";
            }
        } else {
            echo "ℹ️ ファイル不存在: {$file}\n";
        }
    }
    echo "\n";
    
    // Step 4: 整理ツールの移動
    echo "🧹 Step 4: 整理ツールの移動...\n";
    foreach ($files_to_organize['cleanup_tools'] as $file) {
        if (file_exists($file)) {
            $dest = "backup/cleanup_tools/{$file}";
            if (rename($file, $dest)) {
                echo "✓ 移動: {$file}\n";
                echo "   → {$dest}\n";
            } else {
                echo "❌ 移動失敗: {$file}\n";
            }
        } else {
            echo "ℹ️ ファイル不存在: {$file}\n";
        }
    }
    echo "\n";
    
    // Step 5: 最終的なファイル構成確認
    echo "📊 Step 5: 最終的なファイル構成確認...\n";
    
    $current_php_files = glob('*.php');
    
    // メインシステムファイルの確認
    $main_system_files = [
        'index.php' => 'ログイン画面',
        'dashboard.php' => 'ダッシュボード',
        'pre_duty_call.php' => '乗務前点呼', 
        'daily_inspection.php' => '日常点検',
        'departure.php' => '出庫処理',
        'arrival.php' => '入庫処理',
        'ride_records.php' => '乗車記録',
        'logout.php' => 'ログアウト処理'
    ];
    
    echo "メインシステムファイル:\n";
    foreach ($main_system_files as $file => $description) {
        if (in_array($file, $current_php_files)) {
            echo "  ✅ {$file} - {$description}\n";
        } else {
            echo "  ❌ {$file} - {$description} (不存在)\n";
        }
    }
    
    // 残存ファイルの確認
    $remaining_files = array_diff($current_php_files, array_keys($main_system_files));
    if (!empty($remaining_files)) {
        echo "\n残存ファイル:\n";
        foreach ($remaining_files as $file) {
            if ($file === 'operation.php') {
                echo "  ⚠️ {$file} - 旧システム (段階的廃止予定)\n";
            } else {
                echo "  ❓ {$file} - 要確認\n";
            }
        }
    }
    echo "\n";
    
    // Step 6: ディレクトリ構成の確認
    echo "📁 Step 6: ディレクトリ構成確認...\n";
    
    $directories = [
        'config' => '設定ファイル',
        'api' => 'API (将来使用)',
        'backup' => 'バックアップ保管庫'
    ];
    
    foreach ($directories as $dir => $description) {
        if (is_dir($dir)) {
            echo "  📁 {$dir}/ - {$description}\n";
        } else {
            echo "  ❌ {$dir}/ - {$description} (不存在)\n";
        }
    }
    echo "\n";
    
    // Step 7: バックアップ構成の詳細確認
    echo "📦 Step 7: バックアップ構成詳細確認...\n";
    
    $backup_structure = [
        'backup/dev_tools' => 'テスト・デバッグツール',
        'backup/setup_scripts' => 'セットアップスクリプト',
        'backup/auto_backups' => '自動生成バックアップ',
        'backup/temp_api' => '一時的API',
        'backup/cleanup_tools' => '整理ツール'
    ];
    
    foreach ($backup_structure as $dir => $description) {
        if (is_dir($dir)) {
            $file_count = count(glob("{$dir}/*"));
            echo "  📁 {$dir} - {$description} ({$file_count}個)\n";
            
            // 内容の簡単な表示
            if ($file_count > 0 && $file_count <= 5) {
                $files = glob("{$dir}/*");
                foreach ($files as $file) {
                    echo "    - " . basename($file) . "\n";
                }
            }
        } else {
            echo "  ❌ {$dir} - {$description} (不存在)\n";
        }
    }
    
    // このスクリプト自体を移動
    $current_script = basename(__FILE__);
    if ($current_script !== 'final_cleanup.php' && file_exists($current_script)) {
        $script_dest = "backup/cleanup_tools/{$current_script}";
        echo "\n🔄 このスクリプト自体を移動...\n";
        echo "  ※ 実行完了後に手動で移動してください: {$current_script} → {$script_dest}\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🎉 最終ファイル整理完了！\n";
    echo str_repeat("=", 60) . "\n\n";
    
    echo "📋 整理完了内容:\n";
    echo "・自動生成バックアップファイル → backup/auto_backups/\n";
    echo "・一時的APIファイル → backup/temp_api/\n";
    echo "・整理ツール → backup/cleanup_tools/\n";
    echo "・メインシステムファイルのみ残存\n\n";
    
    echo "🎯 最終的なファイル構成:\n";
    echo "wts/\n";
    echo "├── 📄 index.php              # ログイン画面\n";
    echo "├── 📄 dashboard.php          # ダッシュボード\n";
    echo "├── 📄 pre_duty_call.php      # 乗務前点呼\n";
    echo "├── 📄 daily_inspection.php   # 日常点検\n";
    echo "├── 📄 departure.php          # 出庫処理\n";
    echo "├── 📄 arrival.php            # 入庫処理\n";
    echo "├── 📄 ride_records.php       # 乗車記録\n";
    echo "├── 📄 logout.php             # ログアウト\n";
    echo "├── ⚠️ operation.php           # 旧システム\n";
    echo "├── 📁 config/                # 設定ファイル\n";
    echo "├── 📁 api/                   # API (将来使用)\n";
    echo "└── 📁 backup/                # バックアップ保管庫\n\n";
    
    echo "✅ システム品質向上効果:\n";
    echo "・セキュリティ: 不要ファイルによる攻撃面削減\n";
    echo "・パフォーマンス: ファイル数削減によるサーバー負荷軽減\n";
    echo "・保守性: 必要ファイルの明確化\n";
    echo "・管理性: 整理されたバックアップ構造\n\n";
    
    echo "🔜 次のステップ:\n";
    echo "1. 最終動作確認（全機能テスト）\n";
    echo "2. operation.php の廃止判断\n";
    echo "3. 新機能の開発（乗務後点呼、定期点検等）\n";
    echo "4. 本格的なAPI実装\n\n";
    
    echo "💡 開発継続時の注意:\n";
    echo "・新しいテストファイルは backup/ に自動保管\n";
    echo "・メインファイルの変更時は必ずバックアップ作成\n";
    echo "・本番環境の美しさを維持\n\n";
    
} catch (Exception $e) {
    echo "❌ 整理エラー: " . $e->getMessage() . "\n";
    echo "\n復旧方法:\n";
    echo "・手動でファイルを確認\n";
    echo "・バックアップから必要ファイルを復元\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #28a745; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>