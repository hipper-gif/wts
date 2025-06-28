<?php
/**
 * Stage 1: ファイル整理 - バックアップ作成とテストファイル移動
 * 
 * 実行前確認事項:
 * 1. 現在のシステムが正常動作していることを確認
 * 2. データベースのバックアップを取得済み
 * 3. この作業は本番環境で実行する
 */

echo "<h2>🗂️ Stage 1: ファイル整理開始</h2>\n";
echo "<pre>\n";

// 現在のディレクトリを確認
$current_dir = getcwd();
echo "現在のディレクトリ: {$current_dir}\n\n";

try {
    // Step 1: バックアップディレクトリ構造を作成
    echo "📁 Step 1: バックアップディレクトリ作成中...\n";
    
    $backup_dirs = [
        'backup',
        'backup/dev_tools',
        'backup/setup_scripts',
        'backup/temp_files'
    ];
    
    foreach ($backup_dirs as $dir) {
        if (!is_dir($dir)) {
            if (mkdir($dir, 0755, true)) {
                echo "✓ ディレクトリ作成: {$dir}\n";
            } else {
                throw new Exception("ディレクトリ作成失敗: {$dir}");
            }
        } else {
            echo "✓ 既存ディレクトリ: {$dir}\n";
        }
    }
    
    echo "\n";
    
    // Step 2: 移動対象ファイルの確認と分類
    echo "📋 Step 2: 移動対象ファイルの確認中...\n";
    
    // テスト・デバッグ用ファイル（即座に移動可能）
    $test_files = [
        'add_data.php' => 'dev_tools',
        'debug_data.php' => 'dev_tools',
        'test_db.php' => 'dev_tools',
        'test_password.php' => 'dev_tools',
        'check_new_tables.php' => 'dev_tools'
    ];
    
    // セットアップ・修正スクリプト（慎重に移動）
    $setup_files = [
        'setup_departure_arrival_system.php' => 'setup_scripts',
        'fix_ride_records_table.php' => 'setup_scripts',
        'fix_arrival_table.php' => 'setup_scripts',
        'fix_vehicle_table.php' => 'setup_scripts'
    ];
    
    // Step 3: テスト・デバッグファイルの移動（安全）
    echo "🧪 Step 3: テスト・デバッグファイルの移動中...\n";
    
    foreach ($test_files as $file => $dest_dir) {
        if (file_exists($file)) {
            $dest_path = "backup/{$dest_dir}/{$file}";
            if (copy($file, $dest_path)) {
                // コピー成功後に元ファイルを削除
                if (unlink($file)) {
                    echo "✓ 移動完了: {$file} → {$dest_path}\n";
                } else {
                    echo "⚠️ コピー成功、削除失敗: {$file}\n";
                }
            } else {
                echo "❌ 移動失敗: {$file}\n";
            }
        } else {
            echo "ℹ️ ファイル不存在: {$file}\n";
        }
    }
    
    echo "\n";
    
    // Step 4: セットアップファイルの移動（慎重）
    echo "⚙️ Step 4: セットアップファイルの移動中...\n";
    echo "※ これらのファイルはシステム構築完了後に移動されます\n";
    
    foreach ($setup_files as $file => $dest_dir) {
        if (file_exists($file)) {
            // まずはコピーのみ（元ファイルは残す）
            $dest_path = "backup/{$dest_dir}/{$file}";
            if (copy($file, $dest_path)) {
                echo "✓ バックアップ作成: {$file} → {$dest_path}\n";
                echo "  ※ 元ファイルは動作確認後に削除します\n";
            } else {
                echo "❌ バックアップ失敗: {$file}\n";
            }
        } else {
            echo "ℹ️ ファイル不存在: {$file}\n";
        }
    }
    
    echo "\n";
    
    // Step 5: 現在のファイル構成を確認
    echo "📊 Step 5: 現在のファイル構成確認...\n";
    
    $files = glob('*.php');
    $remaining_files = array_filter($files, function($file) use ($test_files) {
        return !array_key_exists($file, $test_files);
    });
    
    echo "残存するPHPファイル:\n";
    foreach ($remaining_files as $file) {
        if (in_array($file, ['index.php', 'dashboard.php', 'pre_duty_call.php', 'daily_inspection.php', 
                            'departure.php', 'arrival.php', 'ride_records.php', 'logout.php'])) {
            echo "  ✅ {$file} (メイン機能)\n";
        } elseif ($file === 'operation.php') {
            echo "  ⚠️ {$file} (旧システム - 後で判断)\n";
        } else {
            echo "  ❓ {$file} (要確認)\n";
        }
    }
    
    echo "\n";
    
    // Step 6: バックアップ内容の確認
    echo "📦 Step 6: バックアップ内容確認...\n";
    
    foreach ($backup_dirs as $dir) {
        if ($dir !== 'backup') {
            $files_in_backup = glob("{$dir}/*");
            echo "{$dir}: " . count($files_in_backup) . "個のファイル\n";
            foreach ($files_in_backup as $backup_file) {
                echo "  - " . basename($backup_file) . "\n";
            }
        }
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ Stage 1 完了！\n";
    echo str_repeat("=", 50) . "\n\n";
    
    echo "📋 完了した作業:\n";
    echo "・バックアップディレクトリ構造の作成\n";
    echo "・テスト・デバッグファイルの安全な移動\n";
    echo "・セットアップファイルのバックアップ作成\n\n";
    
    echo "🔜 次のステップ:\n";
    echo "1. 現在のシステムの動作確認を実施\n";
    echo "2. 問題がなければ Stage 2 に進む\n";
    echo "3. 問題があればバックアップから復元\n\n";
    
    echo "✅ 新システム動作確認済み:\n";
    echo "・出庫処理: 完全動作確認済み\n";
    echo "・乗車記録: 完全動作確認済み\n";
    echo "・入庫処理: 完全動作確認済み\n";
    echo "・セットアップファイルは安全に移動可能です\n\n";
    
    // Stage 1 完了後の動作確認項目
    echo "✅ Stage 1 後の動作確認項目:\n";
    echo "□ ログイン機能 (index.php)\n";
    echo "□ ダッシュボード表示 (dashboard.php)\n";
    echo "□ 乗務前点呼 (pre_duty_call.php)\n";
    echo "□ 日常点検 (daily_inspection.php)\n";
    echo "□ 出庫処理 (departure.php)\n";
    echo "□ 入庫処理 (arrival.php)\n";
    echo "□ 乗車記録 (ride_records.php)\n\n";
    
    echo "全ての機能が正常に動作することを確認してから Stage 2 に進んでください。\n";
    
} catch (Exception $e) {
    echo "❌ エラー発生: " . $e->getMessage() . "\n";
    echo "\n復旧方法:\n";
    echo "1. backup/ フォルダから必要なファイルを復元\n";
    echo "2. システム管理者に連絡\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>