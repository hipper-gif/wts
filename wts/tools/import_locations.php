<?php
/**
 * TimeTree場所データCSV → location_master インポートスクリプト
 *
 * 使い方:
 *   php import_locations.php [--dry-run] [--verbose]
 *
 * オプション:
 *   --dry-run   実際のDB操作を行わず、処理内容を表示のみ
 *   --verbose   詳細ログを表示
 */

require_once __DIR__ . '/../config/database.php';

// --- オプション解析 ---
$options = getopt('', ['dry-run', 'verbose']);
$dryRun  = isset($options['dry-run']);
$verbose = isset($options['verbose']);

$csvFile = __DIR__ . '/locations_with_stats.csv';

if (!file_exists($csvFile)) {
    fwrite(STDERR, "エラー: CSVファイルが見つかりません: {$csvFile}\n");
    exit(1);
}

// --- 種別マッピング ---
$typeMap = [
    '病院'     => 'hospital',
    'クリニック' => 'clinic',
    '介護施設'  => 'care_facility',
    '薬局'     => 'pharmacy',
    '自宅'     => 'home',
    '交通機関'  => 'station',
];

/**
 * 場所名から種別を推定する（CSV種別が「その他」の場合）
 */
function guessTypeFromName(string $name): string
{
    // 順序が重要: より具体的なものを先にマッチさせる
    if (preg_match('/病院|医大|医科|医療|医院/', $name)) {
        return 'hospital';
    }
    if (preg_match('/クリニック|診療所/', $name)) {
        return 'clinic';
    }
    if (preg_match('/デイ|施設|特養|老健|グループホーム|ケアホーム|ホーム|園/', $name)) {
        return 'care_facility';
    }
    if (preg_match('/薬局|調剤/', $name)) {
        return 'pharmacy';
    }
    if (preg_match('/駅/', $name)) {
        return 'station';
    }
    return 'other';
}

/**
 * CSV種別から location_type を決定する
 * 「自宅」の場合は null を返す（スキップ対象）
 */
function resolveLocationType(string $csvType, string $name): ?string
{
    global $typeMap;

    if (isset($typeMap[$csvType])) {
        $mapped = $typeMap[$csvType];
        return $mapped === 'home' ? null : $mapped;
    }

    // 「その他」またはマッピングにないもの → 名前から推定
    return guessTypeFromName($name);
}

// --- メイン処理 ---
echo "=== 場所マスタ インポート ===\n";
if ($dryRun) {
    echo "[DRY-RUN モード] 実際のDB操作は行いません\n";
}
echo "CSV: {$csvFile}\n\n";

// DB接続
$pdo = getDBConnection();

// 既存レコードを取得（name → id のマップ）
$existingStmt = $pdo->query("SELECT id, name FROM location_master");
$existingMap = [];
while ($row = $existingStmt->fetch()) {
    $existingMap[$row['name']] = (int)$row['id'];
}

// CSV読み込み
$handle = fopen($csvFile, 'r');
if ($handle === false) {
    fwrite(STDERR, "エラー: CSVファイルを開けません\n");
    exit(1);
}

// ヘッダー行をスキップ
$header = fgetcsv($handle);
if ($header === false) {
    fwrite(STDERR, "エラー: CSVヘッダーを読み込めません\n");
    exit(1);
}

// 準備済みステートメント
$insertStmt = $pdo->prepare(
    "INSERT INTO location_master (name, location_type, usage_count) VALUES (:name, :type, :usage_count)"
);
$updateStmt = $pdo->prepare(
    "UPDATE location_master SET usage_count = :usage_count, updated_at = NOW() WHERE id = :id"
);

// カウンタ
$stats = [
    'total'    => 0,
    'inserted' => 0,
    'updated'  => 0,
    'skipped_home' => 0,
    'skipped_dup'  => 0,
    'errors'   => 0,
];
$typeStats = [];

$lineNum = 1; // ヘッダー除いた行番号

while (($row = fgetcsv($handle)) !== false) {
    $lineNum++;
    $stats['total']++;

    // CSVカラム: 場所名, 種別, 利用回数, 利用顧客数, 主な利用者
    if (count($row) < 3) {
        if ($verbose) {
            echo "  [WARN] 行{$lineNum}: カラム数不足、スキップ\n";
        }
        $stats['errors']++;
        continue;
    }

    $name       = trim($row[0]);
    $csvType    = trim($row[1]);
    $usageCount = (int)$row[2];

    if ($name === '') {
        if ($verbose) {
            echo "  [WARN] 行{$lineNum}: 場所名が空、スキップ\n";
        }
        $stats['errors']++;
        continue;
    }

    // 種別判定
    $locationType = resolveLocationType($csvType, $name);

    // 自宅はスキップ
    if ($locationType === null) {
        if ($verbose) {
            echo "  [SKIP] 行{$lineNum}: 「{$name}」→ 自宅のためスキップ\n";
        }
        $stats['skipped_home']++;
        continue;
    }

    // 種別統計
    if (!isset($typeStats[$locationType])) {
        $typeStats[$locationType] = 0;
    }
    $typeStats[$locationType]++;

    // 重複チェック
    if (isset($existingMap[$name])) {
        // 既存: usage_count を更新
        $existingId = $existingMap[$name];
        if ($verbose) {
            echo "  [UPD] 行{$lineNum}: 「{$name}」→ 既存(id={$existingId}) usage_count={$usageCount}\n";
        }
        if (!$dryRun) {
            $updateStmt->execute([
                ':usage_count' => $usageCount,
                ':id'          => $existingId,
            ]);
        }
        $stats['updated']++;
        continue;
    }

    // 新規挿入
    if ($verbose) {
        echo "  [ADD] 行{$lineNum}: 「{$name}」→ {$locationType} (usage={$usageCount})\n";
    }

    if (!$dryRun) {
        try {
            $insertStmt->execute([
                ':name'        => $name,
                ':type'        => $locationType,
                ':usage_count' => $usageCount,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $existingMap[$name] = $newId;
        } catch (PDOException $e) {
            fwrite(STDERR, "  [ERR] 行{$lineNum}: 「{$name}」挿入失敗: {$e->getMessage()}\n");
            $stats['errors']++;
            continue;
        }
    } else {
        // dry-runでも重複チェック用にマップに追加
        $existingMap[$name] = -1;
    }

    $stats['inserted']++;
}

fclose($handle);

// --- サマリー表示 ---
echo "\n=== 実行結果サマリー ===\n";
if ($dryRun) {
    echo "[DRY-RUN] 実際のDB操作は行っていません\n";
}
echo "CSV総行数:       {$stats['total']}\n";
echo "新規追加:         {$stats['inserted']}\n";
echo "既存更新:         {$stats['updated']}\n";
echo "スキップ(自宅):   {$stats['skipped_home']}\n";
echo "エラー:           {$stats['errors']}\n";

if (!empty($typeStats)) {
    echo "\n--- 種別内訳 ---\n";
    $typeLabels = [
        'hospital'      => '病院',
        'clinic'        => 'クリニック',
        'care_facility' => '介護施設',
        'pharmacy'      => '薬局',
        'station'       => '駅・交通',
        'other'         => 'その他',
    ];
    foreach ($typeStats as $type => $count) {
        $label = $typeLabels[$type] ?? $type;
        echo "  {$label}: {$count}件\n";
    }
}

echo "\n完了\n";
exit($stats['errors'] > 0 ? 1 : 0);
