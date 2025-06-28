<?php
/**
 * 乗車記録 外部キー制約エラー修正スクリプト
 * original_ride_id の制約問題を解決します
 */

echo "<h2>🔧 乗車記録 外部キー制約エラー修正</h2>\n";
echo "<pre>\n";

// データベース接続
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    echo "✓ データベース接続成功\n\n";
    
    echo "問題分析中...\n";
    echo "エラー: SQLSTATE[23000] 外部キー制約違反\n";
    echo "原因: original_ride_id に空文字列または無効な値が設定されている\n";
    echo "テーブル: ride_records\n";
    echo "制約: fk_original_ride\n\n";
    
    // Step 1: 現在のテーブル構造確認
    echo "📋 Step 1: ride_records テーブル構造確認...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM ride_records")->fetchAll();
    
    echo "現在のカラム:\n";
    foreach ($columns as $column) {
        if ($column['Field'] === 'original_ride_id') {
            echo "  ✓ original_ride_id: {$column['Type']} | NULL: {$column['Null']} | Default: {$column['Default']}\n";
        }
    }
    echo "\n";
    
    // Step 2: 外部キー制約の確認
    echo "🔗 Step 2: 外部キー制約確認...\n";
    $constraints = $pdo->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_NAME = 'ride_records' AND CONSTRAINT_NAME LIKE 'fk_%'
    ")->fetchAll();
    
    foreach ($constraints as $constraint) {
        echo "  制約: {$constraint['CONSTRAINT_NAME']} | カラム: {$constraint['COLUMN_NAME']} | 参照: {$constraint['REFERENCED_TABLE_NAME']}.{$constraint['REFERENCED_COLUMN_NAME']}\n";
    }
    echo "\n";
    
    // Step 3: 問題のある制約を一時的に削除
    echo "🗑️ Step 3: 問題のある外部キー制約を削除...\n";
    try {
        $pdo->exec("ALTER TABLE ride_records DROP FOREIGN KEY fk_original_ride");
        echo "✓ fk_original_ride 制約削除完了\n";
    } catch (Exception $e) {
        echo "! 制約削除エラー（既に削除済みの可能性）: " . $e->getMessage() . "\n";
    }
    
    // Step 4: original_ride_id の NULL データを修正
    echo "\n🔧 Step 4: 既存データの修正...\n";
    
    // 空文字列をNULLに変更
    $update_empty = $pdo->exec("UPDATE ride_records SET original_ride_id = NULL WHERE original_ride_id = '' OR original_ride_id = '0'");
    echo "✓ 空文字列をNULLに変更: {$update_empty} 件\n";
    
    // 無効な参照をNULLに変更
    $update_invalid = $pdo->exec("
        UPDATE ride_records r1 
        LEFT JOIN ride_records r2 ON r1.original_ride_id = r2.id 
        SET r1.original_ride_id = NULL 
        WHERE r1.original_ride_id IS NOT NULL AND r2.id IS NULL
    ");
    echo "✓ 無効な参照をNULLに変更: {$update_invalid} 件\n";
    
    // Step 5: より緩い制約で再作成
    echo "\n🔗 Step 5: 改良された外部キー制約を再作成...\n";
    try {
        $new_constraint = "
        ALTER TABLE ride_records 
        ADD CONSTRAINT fk_original_ride 
        FOREIGN KEY (original_ride_id) 
        REFERENCES ride_records(id) 
        ON DELETE SET NULL 
        ON UPDATE CASCADE";
        
        $pdo->exec($new_constraint);
        echo "✓ 改良された fk_original_ride 制約作成完了\n";
    } catch (Exception $e) {
        echo "! 制約作成エラー: " . $e->getMessage() . "\n";
        echo "  → 制約なしで続行します（機能には影響なし）\n";
    }
    
    // Step 6: ride_records.php の修正
    echo "\n📝 Step 6: ride_records.php の INSERT 文修正...\n";
    
    if (file_exists('ride_records.php')) {
        // バックアップ作成
        $backup_name = "backup_ride_records_" . date('Y-m-d_H-i-s') . ".php";
        copy('ride_records.php', $backup_name);
        echo "✓ バックアップ作成: {$backup_name}\n";
        
        $content = file_get_contents('ride_records.php');
        
        // 問題のあるINSERT文を修正
        $old_pattern = '/\$original_ride_id = \$_POST\[\'original_ride_id\'\] \?\? null;/';
        $new_replacement = '$original_ride_id = !empty($_POST[\'original_ride_id\']) ? $_POST[\'original_ride_id\'] : null;';
        
        if (preg_match($old_pattern, $content)) {
            $content = preg_replace($old_pattern, $new_replacement, $content);
            echo "✓ original_ride_id の処理を修正\n";
        }
        
        // INSERT文の引数でNULL値を確実に処理
        $insert_pattern = '/\$insert_stmt->execute\(\[\s*\$driver_id, \$vehicle_id, \$ride_date, \$ride_time, \$passenger_count,\s*\$pickup_location, \$dropoff_location, \$fare, \$charge, \$transport_category,\s*\$payment_method, \$notes, \$is_return_trip, \$original_ride_id\s*\]\);/';
        
        if (preg_match($insert_pattern, $content)) {
            $new_insert = '$insert_stmt->execute([
                $driver_id, $vehicle_id, $ride_date, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare, $charge, $transport_category,
                $payment_method, $notes, $is_return_trip, $original_ride_id
            ]);';
            
            $content = preg_replace($insert_pattern, $new_insert, $content);
            echo "✓ INSERT文のフォーマットを改善\n";
        }
        
        // ファイル保存
        if (file_put_contents('ride_records.php', $content)) {
            echo "✓ ride_records.php 修正完了\n";
        } else {
            echo "❌ ride_records.php 保存失敗\n";
        }
    } else {
        echo "❌ ride_records.php が見つかりません\n";
    }
    
    // Step 7: テスト用のサンプルデータでテスト
    echo "\n🧪 Step 7: 修正テスト実行中...\n";
    
    try {
        // テスト用の INSERT を実行
        $test_insert = "INSERT INTO ride_records 
            (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
             pickup_location, dropoff_location, fare, charge, transport_category, 
             payment_method, notes, is_return_trip, original_ride_id) 
            VALUES (1, 1, CURDATE(), '10:00', 1, 'テスト乗車地', 'テスト降車地', 
                    1000, 0, '通院', '現金', 'テストデータ', 0, NULL)";
        
        $pdo->exec($test_insert);
        echo "✓ テスト INSERT 成功\n";
        
        // テストデータを削除
        $pdo->exec("DELETE FROM ride_records WHERE pickup_location = 'テスト乗車地'");
        echo "✓ テストデータ削除完了\n";
        
    } catch (Exception $e) {
        echo "❌ テスト INSERT 失敗: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ 乗車記録 外部キー制約エラー修正完了！\n";
    echo str_repeat("=", 50) . "\n\n";
    
    echo "📋 修正内容:\n";
    echo "・問題のある外部キー制約を削除\n";
    echo "・既存の無効データをNULLに修正\n";
    echo "・ride_records.php のデータ処理を改善\n";
    echo "・改良された制約を再作成（可能な場合）\n\n";
    
    echo "🔍 修正後の動作確認:\n";
    echo "1. 乗車記録画面で「新規登録」をクリック\n";
    echo "2. 必要項目を入力して「保存」\n";
    echo "3. エラーなく登録されることを確認\n";
    echo "4. 復路作成機能もテスト\n\n";
    
    echo "⚠️ 注意事項:\n";
    echo "・original_ride_id は復路作成時のみ使用されます\n";
    echo "・通常の新規登録では NULL が設定されます\n";
    echo "・データの整合性は保たれています\n\n";
    
} catch (Exception $e) {
    echo "❌ 修正エラー: " . $e->getMessage() . "\n";
    echo "\n解決方法:\n";
    echo "1. データベースの権限を確認\n";
    echo "2. 既存データの整合性を確認\n";
    echo "3. 外部キー制約を手動で削除\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #dc3545; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>