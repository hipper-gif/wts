<?php
/**
 * Stage 2: 高度なファイル整理
 * 残りの不要ファイルを分類・整理します
 */

echo "<h2>🗂️ Stage 2: 高度なファイル整理</h2>\n";
echo "<pre>\n";

try {
    echo "📋 現在の状況分析中...\n";
    echo "Stage 1完了: テスト・デバッグファイル移動済み\n";
    echo "課題: 多数の修正スクリプト・バックアップファイルが残存\n\n";
    
    // 整理対象ファイルの分類
    $cleanup_categories = [
        'backup_files' => [
            'backup_departure_js_2025-06-28_11-33-48.php',
            'backup_ride_records_2025-06-28_11-38-36.php', 
            'backup_ride_records_ui_2025-06-28_11-44-17.php'
        ],
        'fix_scripts' => [
            'fix_api_errors.php',
            'fix_database_connection.php',
            'fix_ride_records_constraints.php',
            'fix_ride_records_ui.php',
            'fix_arrival_table.php',
            'fix_ride_records_table.php',
            'fix_vehicle_table.php'
        ],
        'setup_scripts' => [
            'setup_departure_arrival_system.php'
        ],
        'temp_api_files' => [
            'check_prerequisites_api.php',
            'get_previous_mileage_api.php'
        ],
        'cleanup_scripts' => [
            'stage1_cleanup.php'
        ]
    ];
    
    echo "📁 Step 1: カテゴリ別ファイル整理中...\n";
    
    // 各カテゴリのバックアップディレクトリを作成
    $backup_dirs = [
        'backup/auto_backups',    # 自動生成されたバックアップ
        'backup/fix_scripts',     # 修正スクリプト
        'backup/temp_api',        # 一時的なAPI
        'backup/cleanup_tools'    # 整理ツール
    ];
    
    foreach ($backup_dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            echo "✓ ディレクトリ作成: {$dir}\n";
        }
    }
    echo "\n";
    
    // Step 2: 自動生成バックアップファイルの移動
    echo "💾 Step 2: 自動生成バックアップファイルの移動...\n";
    foreach ($cleanup_categories['backup_files'] as $file) {
        if (file_exists($file)) {
            $dest = "backup/auto_backups/{$file}";
            if (rename($file, $dest)) {
                echo "✓ 移動: {$file} → {$dest}\n";
            }
        } else {
            echo "ℹ️ 不存在: {$file}\n";
        }
    }
    echo "\n";
    
    // Step 3: 修正スクリプトの移動
    echo "🔧 Step 3: 修正スクリプトの移動...\n";
    foreach ($cleanup_categories['fix_scripts'] as $file) {
        if (file_exists($file)) {
            $dest = "backup/fix_scripts/{$file}";
            if (rename($file, $dest)) {
                echo "✓ 移動: {$file} → {$dest}\n";
            }
        } else {
            echo "ℹ️ 不存在: {$file}\n";
        }
    }
    echo "\n";
    
    // Step 4: セットアップスクリプトの移動
    echo "⚙️ Step 4: セットアップスクリプトの移動...\n";
    foreach ($cleanup_categories['setup_scripts'] as $file) {
        if (file_exists($file)) {
            $dest = "backup/setup_scripts/{$file}";
            if (file_exists($dest)) {
                // 既にバックアップ済みなので元ファイルを削除
                if (unlink($file)) {
                    echo "✓ 削除: {$file} (バックアップ済み)\n";
                }
            } else {
                if (rename($file, $dest)) {
                    echo "✓ 移動: {$file} → {$dest}\n";
                }
            }
        } else {
            echo "ℹ️ 不存在: {$file}\n";
        }
    }
    echo "\n";
    
    // Step 5: 一時的APIファイルの判定
    echo "🔌 Step 5: 一時的APIファイルの判定...\n";
    foreach ($cleanup_categories['temp_api_files'] as $file) {
        if (file_exists($file)) {
            echo "⚠️ 判定必要: {$file}\n";
            echo "   → 現在使用中の場合は保持\n";
            echo "   → 使用していない場合は backup/temp_api/ へ移動\n";
            
            // ファイルサイズと作成日時を確認
            $size = filesize($file);
            $date = date('Y-m-d H:i:s', filemtime($file));
            echo "   ファイル情報: {$size} bytes, 更新日時: {$date}\n";
        }
    }
    echo "\n";
    
    // Step 6: 整理ツールの移動
    echo "🧹 Step 6: 整理ツールの移動...\n";
    foreach ($cleanup_categories['cleanup_scripts'] as $file) {
        if (file_exists($file)) {
            $dest = "backup/cleanup_tools/{$file}";
            if (rename($file, $dest)) {
                echo "✓ 移動: {$file} → {$dest}\n";
            }
        }
    }
    
    // このスクリプト自体も移動対象に追加
    $current_script = basename(__FILE__);
    if ($current_script !== 'stage2_cleanup.php') {
        echo "ℹ️ 現在実行中のスクリプト: {$current_script}\n";
        echo "   実行完了後に backup/cleanup_tools/ へ移動してください\n";
    }
    echo "\n";
    
    // Step 7: 現在のファイル構成確認
    echo "📊 Step 7: 整理後のファイル構成確認...\n";
    
    $current_files = glob('*.php');
    
    echo "メインシステムファイル:\n";
    $main_files = [
        'index.php' => 'ログイン画面',
        'dashboard.php' => 'ダッシュボード', 
        'pre_duty_call.php' => '乗務前点呼',
        'daily_inspection.php' => '日常点検',
        'departure.php' => '出庫処理',
        'arrival.php' => '入庫処理',
        'ride_records.php' => '乗車記録',
        'logout.php' => 'ログアウト'
    ];
    
    foreach ($main_files as $file => $description) {
        if (in_array($file, $current_files)) {
            echo "  ✅ {$file} - {$description}\n";
        } else {
            echo "  ❌ {$file} - {$description} (ファイル不存在)\n";
        }
    }
    
    echo "\n保留・要判断ファイル:\n";
    $remaining_files = array_diff($current_files, array_keys($main_files));
    foreach ($remaining_files as $file) {
        if ($file === 'operation.php') {
            echo "  ⚠️ {$file} - 旧システム (新システム安定後に削除)\n";
        } elseif (strpos($file, 'api.php') !== false) {
            echo "  🔌 {$file} - API (使用状況を確認)\n";
        } else {
            echo "  ❓ {$file} - 用途不明 (手動確認が必要)\n";
        }
    }
    
    echo "\n";
    
    // Step 8: バックアップ構成の確認
    echo "📦 Step 8: バックアップ構成確認...\n";
    
    $backup_structure = [
        'backup/dev_tools' => 'テスト・デバッグツール',
        'backup/setup_scripts' => 'セットアップスクリプト',
        'backup/fix_scripts' => '修正スクリプト',
        'backup/auto_backups' => '自動生成バックアップ',
        'backup/temp_api' => '一時的API',
        'backup/cleanup_tools' => '整理ツール'
    ];
    
    foreach ($backup_structure as $dir => $description) {
        if (is_dir($dir)) {
            $file_count = count(glob("{$dir}/*"));
            echo "  📁 {$dir} - {$description} ({$file_count}個)\n";
        }
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ Stage 2 完了！\n";
    echo str_repeat("=", 50) . "\n\n";
    
    echo "📋 整理完了内容:\n";
    echo "・自動生成バックアップファイルの整理\n";
    echo "・修正スクリプトの分類・保管\n";
    echo "・セットアップファイルの最終処理\n";
    echo "・整理ツールの保管\n\n";
    
    echo "🎯 最終的なファイル構成:\n";
    echo "【メインシステム】\n";
    echo "・index.php, dashboard.php, logout.php\n";
    echo "・pre_duty_call.php, daily_inspection.php\n";
    echo "・departure.php, arrival.php, ride_records.php\n\n";
    
    echo "【要判断ファイル】\n";
    echo "・operation.php (旧システム - 段階的廃止)\n";
    echo "・*_api.php (一時的API - 使用状況確認)\n\n";
    
    echo "【バックアップ】\n";
    echo "・backup/ 以下に全ての開発ファイルを分類保管\n";
    echo "・必要時に復元可能\n\n";
    
    echo "🔜 次のアクション:\n";
    echo "1. メインシステムの最終動作確認\n";
    echo "2. operation.php の廃止判断\n";
    echo "3. APIファイルの本格実装\n";
    echo "4. 残りの機能開発（乗務後点呼等）\n\n";
    
} catch (Exception $e) {
    echo "❌ 整理エラー: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #17a2b8; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>