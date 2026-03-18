<?php
/**
 * 手書き運行日報 CSV → DB一括インポートスクリプト
 *
 * 使い方:
 *   php import_daily_reports.php daily_reports.csv [--dry-run] [--verbose]
 *
 * CSVフォーマット (UTF-8 BOM付き):
 *   date, day_of_week, weather, driver_name, vehicle_number,
 *   departure_time, driving_duration,
 *   departure_mileage, arrival_mileage, total_distance,
 *   trip_no, trip_time, passengers, pickup_location, dropoff_location, fare, transportation_type,
 *   fuel_cost, highway_cost, other_cost, notes
 *
 * インポート先テーブル:
 *   - ride_records (乗務記録): 各乗務明細行
 *   - departure_records (出庫記録): 日ごと1件
 *   - arrival_records (入庫記録): 日ごと1件
 *   - vehicles.current_mileage の更新
 */

require_once __DIR__ . '/../config/database.php';

// === コマンドライン引数 ===
$args = $argv ?? [];
$csvFile = $args[1] ?? null;
$dryRun = in_array('--dry-run', $args);
$verbose = in_array('--verbose', $args);

if (!$csvFile || !file_exists($csvFile)) {
    echo "使い方: php import_daily_reports.php <CSVファイル> [--dry-run] [--verbose]\n";
    echo "\nオプション:\n";
    echo "  --dry-run   実際にはDBに書き込まず、処理内容を表示\n";
    echo "  --verbose   詳細ログを表示\n";
    exit(1);
}

// === CSV読み込み ===
$csv = file_get_contents($csvFile);
$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // BOM除去
$lines = array_filter(explode("\n", $csv), fn($l) => trim($l) !== '');
$headers = str_getcsv(array_shift($lines));

echo "=== 手書き運行日報 インポートツール ===\n";
echo "CSVファイル: {$csvFile}\n";
echo "データ行数: " . count($lines) . "\n";
echo "モード: " . ($dryRun ? "ドライラン（DB書込なし）" : "本番インポート") . "\n\n";

// === CSVをグループ化 (date + vehicle_number でグループ) ===
$dailyGroups = [];
foreach ($lines as $lineNum => $line) {
    $vals = str_getcsv($line);
    if (count($vals) < 17) {
        echo "WARNING: 行" . ($lineNum + 2) . " カラム数不足、スキップ\n";
        continue;
    }

    $row = array_combine(array_slice($headers, 0, count($vals)), $vals);
    $key = ($row['date'] ?? '') . '_' . ($row['vehicle_number'] ?? '');

    if (!isset($dailyGroups[$key])) {
        $dailyGroups[$key] = [
            'date' => $row['date'] ?? '',
            'driver_name' => $row['driver_name'] ?? '',
            'vehicle_number' => $row['vehicle_number'] ?? '',
            'weather' => $row['weather'] ?? '',
            'departure_time' => $row['departure_time'] ?? '',
            'departure_mileage' => intval($row['departure_mileage'] ?? 0),
            'arrival_mileage' => intval($row['arrival_mileage'] ?? 0),
            'total_distance' => intval($row['total_distance'] ?? 0),
            'fuel_cost' => intval($row['fuel_cost'] ?? 0),
            'highway_cost' => intval($row['highway_cost'] ?? 0),
            'other_cost' => intval($row['other_cost'] ?? 0),
            'notes' => $row['notes'] ?? '',
            'trips' => []
        ];
    }

    // 乗務明細行
    if (!empty($row['trip_no'])) {
        $dailyGroups[$key]['trips'][] = [
            'no' => intval($row['trip_no']),
            'time' => $row['trip_time'] ?? '',
            'passengers' => intval($row['passengers'] ?? 1),
            'pickup' => $row['pickup_location'] ?? '',
            'dropoff' => $row['dropoff_location'] ?? '',
            'fare' => intval($row['fare'] ?? 0),
            'type' => $row['transportation_type'] ?? '通院'
        ];
    }
}

echo "日別グループ数: " . count($dailyGroups) . "\n\n";

// === DB接続 ===
$pdo = getDBConnection();

// === マスタ検索関数 ===
function findDriverId($pdo, $name) {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];

    // 完全一致
    $stmt = $pdo->prepare("SELECT id FROM users WHERE name = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        // 部分一致
        $stmt = $pdo->prepare("SELECT id FROM users WHERE name LIKE ? AND is_active = 1 LIMIT 1");
        $stmt->execute(['%' . $name . '%']);
        $id = $stmt->fetchColumn();
    }

    $cache[$name] = $id ?: null;
    return $cache[$name];
}

function findVehicleId($pdo, $number) {
    static $cache = [];
    if (isset($cache[$number])) return $cache[$number];

    $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$number]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        // 部分一致
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number LIKE ? AND is_active = 1 LIMIT 1");
        $stmt->execute(['%' . $number . '%']);
        $id = $stmt->fetchColumn();
    }

    $cache[$number] = $id ?: null;
    return $cache[$number];
}

// === 重複チェック ===
function isDuplicate($pdo, $table, $conditions) {
    $where = implode(' AND ', array_map(fn($k) => "$k = ?", array_keys($conditions)));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute(array_values($conditions));
    return $stmt->fetchColumn() > 0;
}

// === インポート実行 ===
$stats = [
    'departure_inserted' => 0,
    'arrival_inserted' => 0,
    'ride_inserted' => 0,
    'skipped_duplicate' => 0,
    'skipped_no_driver' => 0,
    'skipped_no_vehicle' => 0,
    'errors' => 0
];

foreach ($dailyGroups as $key => $day) {
    $date = $day['date'];
    $driverName = $day['driver_name'];
    $vehicleNumber = $day['vehicle_number'];

    if ($verbose) {
        echo "--- {$date} | {$vehicleNumber} | {$driverName} ---\n";
    }

    // ドライバーID検索
    $driverId = findDriverId($pdo, $driverName);
    if (!$driverId) {
        echo "SKIP: ドライバー '{$driverName}' がusersテーブルに見つかりません ({$date})\n";
        $stats['skipped_no_driver']++;
        continue;
    }

    // 車両ID検索
    $vehicleId = findVehicleId($pdo, $vehicleNumber);
    if (!$vehicleId) {
        echo "SKIP: 車両 '{$vehicleNumber}' がvehiclesテーブルに見つかりません ({$date})\n";
        $stats['skipped_no_vehicle']++;
        continue;
    }

    if ($verbose) {
        echo "  driver_id={$driverId}, vehicle_id={$vehicleId}\n";
    }

    try {
        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        // === 1. 出庫記録 (departure_records) ===
        $departureRecordId = null;
        if ($day['departure_mileage'] > 0 || !empty($day['departure_time'])) {
            $dupCheck = isDuplicate($pdo, 'departure_records', [
                'driver_id' => $driverId,
                'vehicle_id' => $vehicleId,
                'departure_date' => $date
            ]);

            if ($dupCheck) {
                if ($verbose) echo "  出庫記録: 重複のためスキップ\n";
                // 既存のIDを取得
                $stmt = $pdo->prepare("SELECT id FROM departure_records WHERE driver_id = ? AND vehicle_id = ? AND departure_date = ? LIMIT 1");
                $stmt->execute([$driverId, $vehicleId, $date]);
                $departureRecordId = $stmt->fetchColumn();
            } else {
                if ($dryRun) {
                    echo "  [DRY] INSERT departure_records: date={$date}, time={$day['departure_time']}, mileage={$day['departure_mileage']}\n";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO departure_records
                        (driver_id, vehicle_id, departure_date, departure_time, departure_mileage, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $driverId, $vehicleId, $date,
                        $day['departure_time'] ?: null,
                        $day['departure_mileage'] ?: null
                    ]);
                    $departureRecordId = $pdo->lastInsertId();
                }
                $stats['departure_inserted']++;
                if ($verbose) echo "  出庫記録: 登録完了\n";
            }
        }

        // === 2. 入庫記録 (arrival_records) ===
        if ($day['arrival_mileage'] > 0) {
            $dupCheck = isDuplicate($pdo, 'arrival_records', [
                'driver_id' => $driverId,
                'vehicle_id' => $vehicleId,
                'arrival_date' => $date
            ]);

            if ($dupCheck) {
                if ($verbose) echo "  入庫記録: 重複のためスキップ\n";
                $stats['skipped_duplicate']++;
            } else {
                if ($dryRun) {
                    echo "  [DRY] INSERT arrival_records: date={$date}, mileage={$day['arrival_mileage']}, distance={$day['total_distance']}\n";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO arrival_records
                        (driver_id, vehicle_id, departure_record_id, arrival_date, arrival_mileage,
                         total_distance, fuel_cost, highway_cost, other_cost, remarks, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $driverId, $vehicleId, $departureRecordId, $date,
                        $day['arrival_mileage'],
                        $day['total_distance'] ?: ($day['arrival_mileage'] - $day['departure_mileage']),
                        $day['fuel_cost'] ?: null,
                        $day['highway_cost'] ?: null,
                        $day['other_cost'] ?: null,
                        $day['notes'] ?: null
                    ]);
                }
                $stats['arrival_inserted']++;
                if ($verbose) echo "  入庫記録: 登録完了\n";
            }
        }

        // === 3. 乗務記録 (ride_records) ===
        foreach ($day['trips'] as $trip) {
            if ($trip['fare'] <= 0 && empty($trip['pickup']) && empty($trip['dropoff'])) {
                continue; // 空行スキップ
            }

            // 簡易重複チェック（同日・同車両・同時刻・同運賃）
            $rideTime = $trip['time'] ?: '00:00';
            $dupCheck = false;
            if (!empty($trip['time'])) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records
                    WHERE driver_id = ? AND ride_date = ? AND ride_time = ? AND fare = ?");
                $stmt->execute([$driverId, $date, $rideTime, $trip['fare']]);
                $dupCheck = $stmt->fetchColumn() > 0;
            }

            if ($dupCheck) {
                if ($verbose) echo "  乗務#{$trip['no']}: 重複のためスキップ\n";
                $stats['skipped_duplicate']++;
                continue;
            }

            // 運賃・支払い（手書き日報には支払方法の記載なし → デフォルト現金）
            $fare = $trip['fare'];
            $charge = 0;
            $totalFare = $fare;
            $paymentMethod = '現金';
            $cashAmount = $totalFare;
            $cardAmount = 0;

            if ($dryRun) {
                echo "  [DRY] INSERT ride_records: #{$trip['no']} time={$rideTime}, pax={$trip['passengers']}, " .
                     "pickup={$trip['pickup']}, dropoff={$trip['dropoff']}, fare={$fare}, type={$trip['type']}\n";
            } else {
                $stmt = $pdo->prepare("INSERT INTO ride_records
                    (driver_id, vehicle_id, ride_date, ride_time, passenger_count,
                     pickup_location, dropoff_location, fare, charge, total_fare,
                     cash_amount, card_amount, transportation_type, payment_method,
                     notes, departure_record_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $driverId, $vehicleId, $date, $rideTime, $trip['passengers'],
                    $trip['pickup'], $trip['dropoff'],
                    $fare, $charge, $totalFare,
                    $cashAmount, $cardAmount,
                    $trip['type'], $paymentMethod,
                    '手書き日報からのインポート', $departureRecordId
                ]);
            }
            $stats['ride_inserted']++;
            if ($verbose) echo "  乗務#{$trip['no']}: 登録完了 (¥{$fare})\n";
        }

        // === 4. 車両メーター更新 ===
        if ($day['arrival_mileage'] > 0 && !$dryRun) {
            $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(COALESCE(current_mileage, 0), ?) WHERE id = ?");
            $stmt->execute([$day['arrival_mileage'], $vehicleId]);
        }

        if (!$dryRun) {
            $pdo->commit();
        }

    } catch (Exception $e) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "ERROR ({$date}): " . $e->getMessage() . "\n";
        $stats['errors']++;
    }
}

// === 結果サマリー ===
echo "\n=== インポート結果 ===\n";
echo "出庫記録: {$stats['departure_inserted']} 件登録\n";
echo "入庫記録: {$stats['arrival_inserted']} 件登録\n";
echo "乗務記録: {$stats['ride_inserted']} 件登録\n";
echo "重複スキップ: {$stats['skipped_duplicate']} 件\n";
echo "ドライバー不明スキップ: {$stats['skipped_no_driver']} 件\n";
echo "車両不明スキップ: {$stats['skipped_no_vehicle']} 件\n";
echo "エラー: {$stats['errors']} 件\n";

if ($dryRun) {
    echo "\n※ ドライランモードのため、DBへの書込みは行われていません。\n";
    echo "  本番実行: php import_daily_reports.php {$csvFile}\n";
}
