<?php
/**
 * 乗車記録UI修正スクリプト
 * 1. 新規登録時のメッセージ修正
 * 2. 復路作成ボタンの表示修正
 */

echo "<h2>🔧 乗車記録UI修正</h2>\n";
echo "<pre>\n";

try {
    echo "問題分析中...\n";
    echo "問題1: 新規登録時に「復路の乗車記録を登録しました」と表示\n";
    echo "問題2: 復路作成ボタンが表示されない\n\n";
    
    // Step 1: ride_records.php の修正
    echo "📝 Step 1: ride_records.php のメッセージ表示ロジック修正...\n";
    
    if (!file_exists('ride_records.php')) {
        echo "❌ ride_records.php が見つかりません\n";
        exit;
    }
    
    // バックアップ作成
    $backup_name = "backup_ride_records_ui_" . date('Y-m-d_H-i-s') . ".php";
    copy('ride_records.php', $backup_name);
    echo "✓ バックアップ作成: {$backup_name}\n";
    
    $content = file_get_contents('ride_records.php');
    
    // 修正1: 成功メッセージの条件分岐を修正
    $old_message_pattern = '/if \(\$is_return_trip\) \{.*?\$success_message = \'復路の乗車記録を登録しました。\';.*?\} else \{.*?\$success_message = \'乗車記録を登録しました。\';.*?\}/s';
    
    $new_message_code = 'if ($is_return_trip == 1) {
                $success_message = \'復路の乗車記録を登録しました。\';
            } else {
                $success_message = \'乗車記録を登録しました。\';
            }';
    
    if (preg_match($old_message_pattern, $content)) {
        $content = preg_replace($old_message_pattern, $new_message_code, $content);
        echo "✓ 成功メッセージの条件分岐を修正\n";
    } else {
        // パターンが見つからない場合は、別の方法で修正
        $content = str_replace(
            '$success_message = \'復路の乗車記録を登録しました。\';',
            $new_message_code,
            $content
        );
        echo "✓ 成功メッセージを代替方法で修正\n";
    }
    
    // 修正2: is_return_trip の処理を修正
    $old_return_trip_pattern = '/\$is_return_trip = isset\(\$_POST\[\'is_return_trip\'\]\) \? 1 : 0;/';
    $new_return_trip_code = '$is_return_trip = (isset($_POST[\'is_return_trip\']) && $_POST[\'is_return_trip\'] == \'1\') ? 1 : 0;';
    
    if (preg_match($old_return_trip_pattern, $content)) {
        $content = preg_replace($old_return_trip_pattern, $new_return_trip_code, $content);
        echo "✓ is_return_trip の処理を修正\n";
    }
    
    // 修正3: 復路作成ボタンの表示条件を修正
    $old_button_pattern = '/\<\?php if \(\!\$ride\[\'is_return_trip\'\]\): \?\>.*?<button type="button"[^>]*onclick="createReturnTrip\([^)]*\)"[^>]*>.*?<\/button>.*?\<\?php endif; \?\>/s';
    
    $new_button_code = '<?php if ($ride[\'is_return_trip\'] != 1): ?>
                                                    <button type="button" class="btn btn-success btn-sm" 
                                                            onclick="createReturnTrip(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                            title="復路作成">
                                                        <i class="fas fa-route"></i>
                                                    </button>
                                                <?php endif; ?>';
    
    if (preg_match($old_button_pattern, $content)) {
        $content = preg_replace($old_button_pattern, $new_button_code, $content);
        echo "✓ 復路作成ボタンの表示条件を修正\n";
    } else {
        echo "⚠️ 復路作成ボタンのパターンが見つかりません（手動確認が必要）\n";
    }
    
    // 修正4: JavaScriptの復路作成関数を改善
    $js_improvement = '
        // 復路作成モーダル表示（改良版）
        function createReturnTrip(record) {
            console.log("復路作成:", record); // デバッグ用
            
            document.getElementById(\'rideModalTitle\').innerHTML = \'<i class="fas fa-route me-2"></i>復路作成\';
            document.getElementById(\'modalAction\').value = \'add\';
            document.getElementById(\'modalRecordId\').value = \'\';
            document.getElementById(\'modalIsReturnTrip\').value = \'1\';
            document.getElementById(\'modalOriginalRideId\').value = record.id;
            document.getElementById(\'returnTripInfo\').style.display = \'block\';
            
            // 基本情報をコピー（乗降地は入れ替え）
            document.getElementById(\'modalDriverId\').value = record.driver_id;
            document.getElementById(\'modalVehicleId\').value = record.vehicle_id;
            document.getElementById(\'modalRideDate\').value = record.ride_date;
            document.getElementById(\'modalRideTime\').value = getCurrentTime();
            document.getElementById(\'modalPassengerCount\').value = record.passenger_count;
            
            // 乗降地を入れ替え
            document.getElementById(\'modalPickupLocation\').value = record.dropoff_location;
            document.getElementById(\'modalDropoffLocation\').value = record.pickup_location;
            
            document.getElementById(\'modalFare\').value = record.fare;
            document.getElementById(\'modalCharge\').value = record.charge || 0;
            document.getElementById(\'modalTransportCategory\').value = record.transport_category;
            document.getElementById(\'modalPaymentMethod\').value = record.payment_method;
            document.getElementById(\'modalNotes\').value = \'\';
            
            new bootstrap.Modal(document.getElementById(\'rideModal\')).show();
        }
        
        // 現在時刻を取得する関数
        function getCurrentTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, \'0\');
            const minutes = String(now.getMinutes()).padStart(2, \'0\');
            return hours + \':\' + minutes;
        }';
    
    // JavaScriptの改善を適用
    if (strpos($content, 'function createReturnTrip(record)') !== false) {
        $content = preg_replace('/function createReturnTrip\(record\).*?}\s*$/s', $js_improvement, $content);
        echo "✓ JavaScript復路作成関数を改善\n";
    }
    
    // Step 2: ファイル保存
    if (file_put_contents('ride_records.php', $content)) {
        echo "✓ ride_records.php 修正完了\n";
    } else {
        echo "❌ ride_records.php 保存失敗\n";
    }
    
    echo "\n";
    
    // Step 3: 具体的な修正箇所の表示
    echo "📋 Step 2: 修正された具体的な箇所...\n";
    echo "1. 成功メッセージの条件分岐:\n";
    echo "   ・新規登録: \"乗車記録を登録しました。\"\n";
    echo "   ・復路作成: \"復路の乗車記録を登録しました。\"\n\n";
    
    echo "2. 復路作成ボタンの表示条件:\n";
    echo "   ・往路記録: 復路作成ボタン表示\n";
    echo "   ・復路記録: 復路作成ボタン非表示\n\n";
    
    echo "3. 復路フラグの処理:\n";
    echo "   ・is_return_trip = \'1\' の場合のみ復路として判定\n";
    echo "   ・デフォルトは 0（往路）\n\n";
    
    // Step 4: 表示確認用のテストデータチェック
    echo "🔍 Step 3: 現在のデータ確認...\n";
    
    require_once 'config/database.php';
    $pdo = getDBConnection();
    
    $test_query = "SELECT id, pickup_location, dropoff_location, is_return_trip 
                   FROM ride_records 
                   ORDER BY id DESC LIMIT 5";
    $test_result = $pdo->query($test_query)->fetchAll();
    
    if (!empty($test_result)) {
        echo "最新の乗車記録（5件）:\n";
        foreach ($test_result as $record) {
            $trip_type = $record['is_return_trip'] == 1 ? '復路' : '往路';
            echo "  ID:{$record['id']} | {$record['pickup_location']} → {$record['dropoff_location']} | {$trip_type}\n";
        }
    } else {
        echo "乗車記録データがありません\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ 乗車記録UI修正完了！\n";
    echo str_repeat("=", 50) . "\n\n";
    
    echo "📋 修正内容:\n";
    echo "・新規登録時のメッセージ表示を正確に\n";
    echo "・復路作成ボタンの表示条件を修正\n";
    echo "・復路フラグの処理を改善\n";
    echo "・JavaScript関数の動作を安定化\n\n";
    
    echo "🔍 修正後の動作確認:\n";
    echo "1. 新規登録 → \"乗車記録を登録しました\" と表示\n";
    echo "2. 往路記録の右側に復路作成ボタン（🔄アイコン）が表示\n";
    echo "3. 復路作成ボタンクリック → モーダルが開き乗降地が入れ替わる\n";
    echo "4. 復路保存 → \"復路の乗車記録を登録しました\" と表示\n";
    echo "5. 復路記録には復路作成ボタンが表示されない\n\n";
    
    echo "⚠️ 確認すべきポイント:\n";
    echo "・乗車記録一覧の表示\n";
    echo "・復路作成ボタンの有無\n";
    echo "・復路作成時の乗降地入れ替わり\n";
    echo "・メッセージ表示の正確性\n\n";
    
} catch (Exception $e) {
    echo "❌ 修正エラー: " . $e->getMessage() . "\n";
    echo "\n復旧方法:\n";
    echo "・backup_ride_records_ui_*.php から復元\n";
    echo "・手動でHTMLとJavaScript部分を確認\n";
}

echo "</pre>\n";
?>

<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
h2 { color: #333; border-bottom: 2px solid #28a745; padding-bottom: 10px; }
pre { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
</style>