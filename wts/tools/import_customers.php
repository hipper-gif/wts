<?php
/**
 * TimeTree 顧客データ CSV インポートスクリプト
 *
 * customers_with_drivers.csv を読み込み、customers テーブルに
 * インポート（既存なら更新、なければ追加）する。
 *
 * 使い方:
 *   php import_customers.php [--dry-run] [--verbose]
 *
 * オプション:
 *   --dry-run   実際にはDBを変更せず、処理内容を表示する
 *   --verbose   詳細な処理ログを表示する
 */

// CLI実行チェック
if (php_sapi_name() !== 'cli') {
    die("このスクリプトはCLIからのみ実行できます。\n");
}

require_once __DIR__ . '/../config/database.php';

// --- オプション解析 ---
$options = getopt('', ['dry-run', 'verbose']);
$dryRun  = isset($options['dry-run']);
$verbose = isset($options['verbose']);

$csvFile = __DIR__ . '/customers_with_drivers.csv';

if (!file_exists($csvFile)) {
    die("CSVファイルが見つかりません: {$csvFile}\n");
}

// --- ヘルパー関数 ---

/**
 * 顧客名から敬称を除去する
 */
function cleanCustomerName(string $name): string
{
    // 末尾の「様」「さん」を除去
    $name = mb_ereg_replace('(様|さん)$', '', trim($name));
    return $name;
}

/**
 * 電話番号文字列をパースする（カンマ区切りで複数の場合あり）
 * @return array [primary, secondary]
 */
function parsePhones(string $raw): array
{
    if (trim($raw) === '') {
        return [null, null];
    }
    $parts = array_map('trim', explode(',', $raw));
    $primary   = $parts[0] ?: null;
    $secondary = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
    return [$primary, $secondary];
}

/**
 * 住所文字列をパースする（カンマ区切りで複数の場合あり）
 * @return string|null 最初の住所
 */
function parseAddress(string $raw): ?string
{
    if (trim($raw) === '') {
        return null;
    }
    $parts = array_map('trim', explode(',', $raw));
    return $parts[0] ?: null;
}

/**
 * 「よく行く場所」文字列をパースする
 * フォーマット: "場所名(回数) / 場所名(回数) / ..."
 * @return array [[name, count], ...]
 */
function parseFrequentLocations(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }
    $locations = [];
    $items = array_map('trim', explode('/', $raw));
    foreach ($items as $item) {
        if ($item === '') continue;
        // "場所名(回数)" のパターン
        if (preg_match('/^(.+?)[\(（](\d+)[\)）]$/', $item, $m)) {
            $locations[] = [trim($m[1]), (int)$m[2]];
        } else {
            $locations[] = [trim($item), 0];
        }
    }
    return $locations;
}

/**
 * mobility_type を判定する
 */
function determineMobilityType(string $wheelchair, string $ownWheelchair): string
{
    if (trim($wheelchair) === '○') {
        return 'wheelchair';
    }
    return 'independent';
}

// --- メイン処理 ---

echo "=== TimeTree 顧客データインポート ===\n";
if ($dryRun) {
    echo "[DRY-RUN モード] データベースは変更されません。\n";
}
echo "\n";

$pdo = getDBConnection();

// ドライバー名 → ID のマッピングをキャッシュ
$stmtDrivers = $pdo->query("SELECT id, name FROM users WHERE is_active = 1");
$driverMap = [];
foreach ($stmtDrivers->fetchAll() as $row) {
    $driverMap[$row['name']] = (int)$row['id'];
}
if ($verbose) {
    echo "[INFO] ドライバーマップ: " . count($driverMap) . " 件ロード\n";
    foreach ($driverMap as $name => $id) {
        echo "  - {$name} => {$id}\n";
    }
    echo "\n";
}

// location_master の name → id マッピングをキャッシュ
$stmtLocations = $pdo->query("SELECT id, name FROM location_master WHERE is_active = 1");
$locationMap = [];
foreach ($stmtLocations->fetchAll() as $row) {
    $locationMap[$row['name']] = (int)$row['id'];
}
if ($verbose) {
    echo "[INFO] 場所マスタ: " . count($locationMap) . " 件ロード\n\n";
}

// CSV読み込み
$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("CSVファイルを開けません: {$csvFile}\n");
}

// BOM除去
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

// ヘッダー行をスキップ
$header = fgetcsv($handle);

$stats = [
    'inserted'  => 0,
    'updated'   => 0,
    'skipped'   => 0,
    'errors'    => 0,
    'locations_linked' => 0,
    'locations_not_found' => 0,
    'drivers_not_found' => [],
];

$lineNum = 1; // ヘッダーが1行目

// 既存顧客チェック用プリペアドステートメント
$stmtFindCustomer = $pdo->prepare(
    "SELECT id FROM customers WHERE name = :name AND phone = :phone LIMIT 1"
);
$stmtFindCustomerByName = $pdo->prepare(
    "SELECT id FROM customers WHERE name = :name AND phone IS NULL LIMIT 1"
);

// 顧客INSERT
$stmtInsertCustomer = $pdo->prepare(
    "INSERT INTO customers (name, name_kana, phone, phone_secondary, address, mobility_type, wheelchair_type, notes, assigned_driver_id)
     VALUES (:name, :name_kana, :phone, :phone_secondary, :address, :mobility_type, :wheelchair_type, :notes, :assigned_driver_id)"
);

// 顧客UPDATE
$stmtUpdateCustomer = $pdo->prepare(
    "UPDATE customers SET
        phone_secondary = COALESCE(:phone_secondary, phone_secondary),
        address = COALESCE(:address, address),
        mobility_type = :mobility_type,
        wheelchair_type = :wheelchair_type,
        notes = COALESCE(:notes, notes),
        assigned_driver_id = COALESCE(:assigned_driver_id, assigned_driver_id)
     WHERE id = :id"
);

// customer_locations INSERT (IGNORE で重複スキップ)
$stmtInsertCustLoc = $pdo->prepare(
    "INSERT IGNORE INTO customer_locations (customer_id, location_id, relationship_type, visit_count)
     VALUES (:customer_id, :location_id, 'frequent', :visit_count)"
);

// トランザクション開始
$pdo->beginTransaction();

try {
    while (($row = fgetcsv($handle)) !== false) {
        $lineNum++;

        // 空行スキップ
        if (count($row) < 2 || (trim($row[0]) === '' && trim($row[1] ?? '') === '')) {
            continue;
        }

        // CSVカラム: 顧客名,電話番号,住所,車椅子,自身車椅子,介助内容,主担当ドライバー,全ドライバー,利用回数,よく行く場所,初回,最終
        $rawName       = trim($row[0] ?? '');
        $rawPhone      = trim($row[1] ?? '');
        $rawAddress    = trim($row[2] ?? '');
        $rawWheelchair = trim($row[3] ?? '');
        $rawOwnWc      = trim($row[4] ?? '');
        $rawAssist     = trim($row[5] ?? '');
        $rawDriver     = trim($row[6] ?? '');
        // $rawAllDrivers = trim($row[7] ?? '');  // 参考情報のみ
        // $rawCount      = trim($row[8] ?? '');  // 参考情報のみ
        $rawLocations  = trim($row[9] ?? '');
        // $rawFirstDate  = trim($row[10] ?? '');
        // $rawLastDate   = trim($row[11] ?? '');

        if ($rawName === '') {
            if ($verbose) echo "[SKIP] 行{$lineNum}: 顧客名が空\n";
            $stats['skipped']++;
            continue;
        }

        // --- データ加工 ---
        $name = cleanCustomerName($rawName);
        list($phone, $phoneSecondary) = parsePhones($rawPhone);
        $address = parseAddress($rawAddress);
        $mobilityType = determineMobilityType($rawWheelchair, $rawOwnWc);

        // 車椅子タイプ: 自身の車椅子がある場合
        $wheelchairType = null;
        if (trim($rawOwnWc) === '○') {
            $wheelchairType = '自己所有';
        }

        // 介助内容 → notes
        $notes = $rawAssist !== '' ? $rawAssist : null;

        // 主担当ドライバー → assigned_driver_id
        $assignedDriverId = null;
        if ($rawDriver !== '' && $rawDriver !== '他社' && $rawDriver !== '他社未振分') {
            if (isset($driverMap[$rawDriver])) {
                $assignedDriverId = $driverMap[$rawDriver];
            } else {
                if (!in_array($rawDriver, $stats['drivers_not_found'])) {
                    $stats['drivers_not_found'][] = $rawDriver;
                }
                if ($verbose) {
                    echo "[WARN] 行{$lineNum}: ドライバー '{$rawDriver}' がusersテーブルに見つかりません\n";
                }
            }
        }

        // --- 重複チェック ---
        $existingId = null;
        if ($phone !== null) {
            $stmtFindCustomer->execute([':name' => $name, ':phone' => $phone]);
            $found = $stmtFindCustomer->fetch();
            if ($found) {
                $existingId = (int)$found['id'];
            }
        } else {
            $stmtFindCustomerByName->execute([':name' => $name]);
            $found = $stmtFindCustomerByName->fetch();
            if ($found) {
                $existingId = (int)$found['id'];
            }
        }

        if ($existingId !== null) {
            // --- 更新 ---
            if ($verbose) {
                echo "[UPDATE] 行{$lineNum}: {$name} (ID={$existingId})\n";
            }
            if (!$dryRun) {
                $stmtUpdateCustomer->execute([
                    ':phone_secondary'   => $phoneSecondary,
                    ':address'           => $address,
                    ':mobility_type'     => $mobilityType,
                    ':wheelchair_type'   => $wheelchairType,
                    ':notes'             => $notes,
                    ':assigned_driver_id' => $assignedDriverId,
                    ':id'                => $existingId,
                ]);
            }
            $customerId = $existingId;
            $stats['updated']++;
        } else {
            // --- 新規追加 ---
            if ($verbose) {
                echo "[INSERT] 行{$lineNum}: {$name} (phone={$phone})\n";
            }
            if (!$dryRun) {
                $stmtInsertCustomer->execute([
                    ':name'              => $name,
                    ':name_kana'         => null,
                    ':phone'             => $phone,
                    ':phone_secondary'   => $phoneSecondary,
                    ':address'           => $address,
                    ':mobility_type'     => $mobilityType,
                    ':wheelchair_type'   => $wheelchairType,
                    ':notes'             => $notes,
                    ':assigned_driver_id' => $assignedDriverId,
                ]);
                $customerId = (int)$pdo->lastInsertId();
            } else {
                $customerId = null; // dry-run時はIDなし
            }
            $stats['inserted']++;
        }

        // --- よく行く場所の紐付け ---
        $frequentLocations = parseFrequentLocations($rawLocations);
        foreach ($frequentLocations as [$locName, $visitCount]) {
            if (isset($locationMap[$locName])) {
                $locationId = $locationMap[$locName];
                if (!$dryRun && $customerId !== null) {
                    $stmtInsertCustLoc->execute([
                        ':customer_id' => $customerId,
                        ':location_id' => $locationId,
                        ':visit_count' => $visitCount,
                    ]);
                }
                $stats['locations_linked']++;
                if ($verbose) {
                    echo "  [LINK] {$locName} (visit_count={$visitCount})\n";
                }
            } else {
                $stats['locations_not_found']++;
                if ($verbose) {
                    echo "  [WARN] 場所 '{$locName}' がlocation_masterに見つかりません\n";
                }
            }
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "\n[DRY-RUN] ロールバックしました（変更なし）。\n";
    } else {
        $pdo->commit();
        echo "\n[OK] コミット完了。\n";
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n[ERROR] エラーが発生しました。ロールバックしました。\n";
    echo "  " . $e->getMessage() . "\n";
    $stats['errors']++;
}

fclose($handle);

// --- 結果表示 ---
echo "\n=== インポート結果 ===\n";
echo "  新規追加:     {$stats['inserted']} 件\n";
echo "  更新:         {$stats['updated']} 件\n";
echo "  スキップ:     {$stats['skipped']} 件\n";
echo "  エラー:       {$stats['errors']} 件\n";
echo "  場所紐付け:   {$stats['locations_linked']} 件\n";
echo "  場所未発見:   {$stats['locations_not_found']} 件\n";

if (!empty($stats['drivers_not_found'])) {
    echo "\n  [WARN] 未発見ドライバー:\n";
    foreach ($stats['drivers_not_found'] as $d) {
        echo "    - {$d}\n";
    }
}

echo "\n完了。\n";
