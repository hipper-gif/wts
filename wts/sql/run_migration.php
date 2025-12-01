<?php
// マイグレーション実行スクリプト
require_once __DIR__ . '/../config/database.php';

// マイグレーションファイル
$migration_file = __DIR__ . '/remove_uk_vehicle_date_constraint.sql';

try {
    // データベース接続
    $pdo = getDBConnection();

    // マイグレーションファイル読み込み
    if (!file_exists($migration_file)) {
        throw new Exception("マイグレーションファイルが見つかりません: {$migration_file}");
    }

    $sql = file_get_contents($migration_file);

    // SQLステートメントを分割して実行
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            // コメント行と空行を除外
            return !empty($stmt) &&
                   !preg_match('/^\s*--/', $stmt) &&
                   !preg_match('/^\s*\/\*/', $stmt);
        }
    );

    echo "マイグレーションを実行します...\n";
    echo "ファイル: {$migration_file}\n\n";

    foreach ($statements as $statement) {
        // SHOWコマンドはスキップ（確認用のみ）
        if (stripos($statement, 'SHOW INDEX') !== false) {
            echo "インデックス確認をスキップします\n";
            continue;
        }

        echo "実行: " . substr($statement, 0, 100) . "...\n";
        $pdo->exec($statement);
        echo "✓ 完了\n\n";
    }

    // 制約削除の確認
    echo "\n=== 制約削除の確認 ===\n";

    // departure_recordsテーブルの確認
    echo "\n1. departure_records テーブル:\n";
    $stmt = $pdo->query("SHOW INDEX FROM departure_records");
    $departure_indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $has_departure_uk = false;
    foreach ($departure_indexes as $index) {
        if ($index['Key_name'] === 'uk_vehicle_date') {
            $has_departure_uk = true;
            break;
        }
    }

    if ($has_departure_uk) {
        echo "❌ エラー: departure_recordsのuk_vehicle_date制約がまだ存在します\n";
        exit(1);
    } else {
        echo "✓ departure_recordsのuk_vehicle_date制約が正常に削除されました\n";
    }

    // arrival_recordsテーブルの確認
    echo "\n2. arrival_records テーブル:\n";
    $stmt = $pdo->query("SHOW INDEX FROM arrival_records");
    $arrival_indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $has_arrival_uk = false;
    foreach ($arrival_indexes as $index) {
        if ($index['Key_name'] === 'uk_vehicle_date') {
            $has_arrival_uk = true;
            break;
        }
    }

    if ($has_arrival_uk) {
        echo "❌ エラー: arrival_recordsのuk_vehicle_date制約がまだ存在します\n";
        exit(1);
    } else {
        echo "✓ arrival_recordsのuk_vehicle_date制約が正常に削除されました\n";
    }

    echo "\n現在のインデックス一覧:\n";
    echo "\ndeparture_records:\n";
    foreach ($departure_indexes as $index) {
        echo "  - {$index['Key_name']} ({$index['Column_name']})\n";
    }

    echo "\narrival_records:\n";
    foreach ($arrival_indexes as $index) {
        echo "  - {$index['Key_name']} ({$index['Column_name']})\n";
    }

    echo "\n✓ マイグレーション完了!\n";

} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ エラー: " . $e->getMessage() . "\n";
    exit(1);
}
?>
