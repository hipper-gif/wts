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
    $stmt = $pdo->query("SHOW INDEX FROM departure_records");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $has_uk_vehicle_date = false;
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'uk_vehicle_date') {
            $has_uk_vehicle_date = true;
            break;
        }
    }

    if ($has_uk_vehicle_date) {
        echo "❌ エラー: uk_vehicle_date制約がまだ存在します\n";
        exit(1);
    } else {
        echo "✓ uk_vehicle_date制約が正常に削除されました\n\n";
    }

    echo "現在のインデックス一覧:\n";
    foreach ($indexes as $index) {
        echo "  - {$index['Key_name']} ({$index['Column_name']})\n";
    }

    echo "\nマイグレーション完了!\n";

} catch (PDOException $e) {
    echo "❌ データベースエラー: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ エラー: " . $e->getMessage() . "\n";
    exit(1);
}
?>
