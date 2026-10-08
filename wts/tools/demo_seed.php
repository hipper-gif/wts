<?php
/**
 * 公開デモ（テナント demo）に架空データを入れる。日付はすべて「今日」基準で作るので、毎朝流し直せば常に新しい。
 *
 * 使い方（サーバー上・CLIのみ）: php tools/demo_seed.php
 *   前提: テナントを開設した直後の状態（土台テーブル＋初期データ＋admin）。毎朝の作り直しは scripts/demo/reset_demo.sh が行う。
 *
 * 安全装置: DB名が twinklemark_wtsdemo で、.env に DEMO_LOGIN_ID があるときだけ動く（本番・他社テナントでは止まる）。
 * 人名・住所・電話・施設名はすべて架空。実在の利用者・事業所は1件も入れない。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Asia/Tokyo');
require_once __DIR__ . '/../config/database.php';

if (DB_NAME !== 'twinklemark_wtsdemo' || !getenv('DEMO_LOGIN_ID')) {
    fwrite(STDERR, "中止: デモ用テナント以外では実行しない（DB_NAME=" . DB_NAME . "）\n");
    exit(1);
}

$pdo = getDBConnection();
mt_srand((int)date('Ymd'));
$today = new DateTimeImmutable('today');
$fmt = fn(DateTimeImmutable $d) => $d->format('Y-m-d');

$pdo->beginTransaction();

// ---- 運転者・受付（デモの入口は DEMO_LOGIN_ID の一般ユーザー。Admin は誰にも渡さない）----
$unusable = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$insUser = $pdo->prepare("INSERT INTO users
    (login_id, name, password, permission_level, is_driver, is_caller, is_manager, is_active,
     phone, hire_date, driver_license_type, driver_license_expiry, care_qualification, health_check_next,
     dispatch_color, dispatch_priority)
    VALUES (?, ?, ?, 'User', ?, ?, 0, 1, '000-0000-0000', ?, ?, ?, ?, ?, ?, ?)");
$staff = [
    // login_id, 名前, 運転者, 受付, 免許, 資格, 色, 優先
    [getenv('DEMO_LOGIN_ID'), '山田 太郎', 1, 1, '普通第二種', '介護職員初任者研修', '#2563eb', 1],
    ['suzuki', '鈴木 一郎', 1, 0, '普通第二種', '介護福祉士', '#16a34a', 2],
    ['takahashi', '高橋 美咲', 0, 1, null, null, '#db2777', 3],
];
$userIds = [];
foreach ($staff as [$login, $name, $drv, $call, $lic, $care, $color, $prio]) {
    $insUser->execute([
        $login, $name, $unusable, $drv, $call,
        $fmt($today->modify('-' . mt_rand(400, 1500) . ' days')),
        $lic, $lic ? $fmt($today->modify('+' . mt_rand(200, 900) . ' days')) : null,
        $care, $fmt($today->modify('+' . mt_rand(60, 300) . ' days')),
        $color, $prio,
    ]);
    $userIds[$login] = (int)$pdo->lastInsertId();
}
$drivers = [$userIds[getenv('DEMO_LOGIN_ID')], $userIds['suzuki']];
$driverNames = [$drivers[0] => '山田 太郎', $drivers[1] => '鈴木 一郎'];

// ---- 車両 ----
$insVeh = $pdo->prepare("INSERT INTO vehicles
    (vehicle_number, vehicle_name, model, vehicle_type, capacity, is_active, accessibility_category,
     is_wheelchair_compatible, is_stretcher_compatible, next_inspection_date, shaken_expiry_date, current_mileage, status)
    VALUES (?, ?, ?, 'welfare', ?, 1, ?, ?, ?, ?, ?, ?, 'available')");
$vehicles = [];
foreach ([
    ['みほん 300 あ 00-01', '1号車', 'ハイエース 福祉車両', 4, 'wheelchair', 1, 0, 48210],
    ['みほん 300 あ 00-02', '2号車', 'セレナ 車いす仕様', 3, 'combo', 1, 1, 31560],
] as [$no, $vname, $model, $cap, $acc, $wc, $st, $km]) {
    $insVeh->execute([$no, $vname, $model, $cap, $acc, $wc, $st,
        $fmt($today->modify('+' . mt_rand(30, 120) . ' days')),
        $fmt($today->modify('+' . mt_rand(150, 600) . ' days')), $km]);
    $vehicles[] = ['id' => (int)$pdo->lastInsertId(), 'km' => $km];
}
$pdo->prepare("UPDATE users SET default_vehicle_id = ? WHERE id = ?")->execute([$vehicles[0]['id'], $drivers[0]]);
$pdo->prepare("UPDATE users SET default_vehicle_id = ? WHERE id = ?")->execute([$vehicles[1]['id'], $drivers[1]]);

// ---- 行き先（架空の施設）----
$places = [
    ['みほん総合病院', 'hospital', 'みほん市みほん町5-1'],
    ['さくら整形外科クリニック', 'hospital', 'みほん市さくら台2-8'],
    ['ひだまり透析センター', 'hospital', 'みほん市ひだまり1-3'],
    ['デイサービスあおぞら', 'facility', 'みほん市あおぞら町4-12'],
    ['みほん駅 東口', 'station', 'みほん市駅前1-1'],
    ['特別養護老人ホーム ことり', 'facility', 'みほん市ことり丘7-2'],
];
$insLoc = $pdo->prepare("INSERT INTO location_master (name, location_name, location_type, category, address, phone, is_active, usage_count)
    VALUES (?, ?, ?, ?, ?, '000-0000-0000', 1, ?)");
foreach ($places as [$pname, $type, $addr]) {
    $insLoc->execute([$pname, $pname, $type, $type, $addr, mt_rand(5, 40)]);
}
$placeNames = array_column($places, 0);

// ---- 利用者（架空）----
$people = [
    ['青木 ハナ', 'あおき はな', '要介護2', 'wheelchair', 'みほん市青葉町1-4'],
    ['石井 茂', 'いしい しげる', '要介護3', 'wheelchair', 'みほん市石田3-2'],
    ['上野 きよ', 'うえの きよ', '要支援2', 'walker', 'みほん市上野台5-9'],
    ['江藤 正夫', 'えとう まさお', '要介護4', 'stretcher', 'みほん市江川2-7'],
    ['小川 文子', 'おがわ ふみこ', '要介護1', 'independent', 'みほん市小川町6-1'],
    ['加藤 勇', 'かとう いさむ', '要介護2', 'wheelchair', 'みほん市加茂1-11'],
    ['木村 静江', 'きむら しずえ', '要介護3', 'wheelchair', 'みほん市木の実4-3'],
    ['工藤 健一', 'くどう けんいち', '要支援1', 'independent', 'みほん市工町8-5'],
];
$insCus = $pdo->prepare("INSERT INTO customers (name, name_kana, phone, address, care_level, mobility_type,
    default_pickup_location, default_dropoff_location, is_active, created_at, updated_at)
    VALUES (?, ?, '000-0000-0000', ?, ?, ?, ?, ?, 1, NOW(), NOW())");
$customers = [];
foreach ($people as $i => [$cname, $kana, $level, $mob, $addr]) {
    $dest = $placeNames[$i % 4];
    $insCus->execute([$cname, $kana, $addr, $level, $mob, $addr, $dest]);
    $customers[] = ['id' => (int)$pdo->lastInsertId(), 'name' => $cname, 'home' => $addr, 'dest' => $dest, 'mob' => $mob];
}

// ---- 1日分の乗務（点検→点呼→出庫→乗車→入庫→点呼）----
$insInsp = $pdo->prepare("INSERT INTO daily_inspections (vehicle_id, driver_id, inspection_date, inspection_time, inspector_name,
    foot_brake_result, parking_brake_result, brake_fluid_result, lights_result, lens_result, tire_pressure_result, tire_damage_result,
    engine_start_result, engine_performance_result, wiper_result, washer_spray_result, mileage, is_locked)
    VALUES (?, ?, ?, ?, ?, '可','可','可','可','可','可','可','可','可','可','可', ?, 0)");
$insPre = $pdo->prepare("INSERT INTO pre_duty_calls (driver_id, vehicle_id, call_date, call_time, caller_name,
    health_check, clothing_check, footwear_check, pre_inspection_check, license_check, vehicle_registration_check, insurance_check,
    emergency_tools_check, alcohol_check_value, alcohol_check_time, is_completed)
    VALUES (?, ?, ?, ?, '高橋 美咲', 1,1,1,1,1,1,1,1, 0.000, ?, 1)");
$insDep = $pdo->prepare("INSERT INTO departure_records (driver_id, vehicle_id, departure_date, departure_time, weather,
    departure_mileage, pre_duty_completed, daily_inspection_completed) VALUES (?, ?, ?, ?, ?, ?, 1, 1)");
$insRide = $pdo->prepare("INSERT INTO ride_records (ride_number, driver_id, vehicle_id, ride_date, ride_time, dropoff_time,
    passenger_count, pickup_location, dropoff_location, ride_distance, fare, charge, total_fare, cash_amount, card_amount,
    transport_type, transportation_type, payment_method, disability_discount, ticket_amount, is_return_trip, departure_record_id)
    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
$insArr = $pdo->prepare("INSERT INTO arrival_records (departure_record_id, driver_id, vehicle_id, arrival_date, arrival_time,
    arrival_mileage, total_distance, fuel_cost, post_duty_completed, total_trips, total_revenue) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
$insPost = $pdo->prepare("INSERT INTO post_duty_calls (driver_id, vehicle_id, call_date, call_time, caller_name,
    duty_record_check, vehicle_condition_check, health_condition_check, fatigue_check, alcohol_drug_check, accident_violation_check,
    equipment_return_check, report_completion_check, alcohol_check_value, alcohol_check_time, is_completed)
    VALUES (?, ?, ?, ?, '高橋 美咲', 1,1,1,1,1,1,1,1, 0.000, ?, 1)");
$insRes = $pdo->prepare("INSERT INTO reservations (reservation_date, reservation_time, client_name, customer_id,
    pickup_location, dropoff_location, passenger_count, driver_id, vehicle_id, service_type, rental_service,
    referrer_type, referrer_name, estimated_fare, actual_fare, payment_method, status, ride_record_id, special_notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$weathers = ['晴', '晴', '曇', '雨'];
$referrers = [['CM', 'ケアマネ みほん'], ['家族', 'ご家族'], ['SW', '相談員 さくら'], ['本人', 'ご本人']];
$rentals = ['なし', '車いす', 'なし', 'リクライニング'];

/** 1件の乗車（往路 or 復路）。完了済みなら ride_records と完了予約、未来なら予約だけ。 */
$addTrip = function (DateTimeImmutable $day, int $driver, array $veh, int $n, string $time, array $c, bool $return, bool $done, ?int $depId)
    use ($pdo, $insRide, $insRes, $referrers, $rentals, $fmt, $userIds) {
    $from = $return ? $c['dest'] : $c['home'];
    $to = $return ? $c['home'] : $c['dest'];
    $dist = mt_rand(28, 120) / 10;
    $fare = 730 + (int)round($dist * 300 / 10) * 10;
    $charge = $c['mob'] === 'stretcher' ? 2000 : ($c['mob'] === 'wheelchair' ? 500 : 0);
    $total = $fare + $charge;
    $card = mt_rand(0, 3) === 0;
    $type = $c['dest'] === 'デイサービスあおぞら' ? '外出等' : '通院';
    $drop = (new DateTimeImmutable($fmt($day) . ' ' . $time))->modify('+' . mt_rand(15, 35) . ' minutes')->format('H:i:s');
    $rideId = null;
    if ($done) {
        $insRide->execute([$n, $driver, $veh['id'], $fmt($day), $time, $drop, $from, $to, $dist, $fare, $charge, $total,
            $card ? 0 : $total, $card ? $total : 0, $type, $type, $card ? 'カード' : '現金', $c['mob'] !== 'independent' ? 1 : 0,
            $return ? 1 : 0, $depId]);
        $rideId = (int)$pdo->lastInsertId();
    }
    [$rt, $rn] = $referrers[$c['id'] % 4];
    $insRes->execute([$fmt($day), $time, $c['name'] . ' 様', $c['id'], $from, $to, $driver, $veh['id'],
        $return ? 'お送り' : 'お迎え', $rentals[$c['id'] % 4], $rt, $rn, $total, $done ? $total : null,
        $card ? 'カード' : '現金', $done ? '完了' : '予約', $rideId, $c['mob'] === 'stretcher' ? 'ストレッチャー対応' : null,
        $userIds['takahashi']]);
    return $done ? $total : 0;
};

$days = [];
for ($i = 21; $i >= -7; $i--) {
    $d = $today->modify(($i >= 0 ? '-' : '+') . abs($i) . ' days');
    if ((int)$d->format('w') === 0) {
        continue; // 日曜休み
    }
    $days[] = $d;
}
$rideTimes = ['08:30:00', '09:40:00', '11:00:00', '13:30:00', '14:40:00', '16:00:00'];

foreach ($days as $day) {
    $isToday = $fmt($day) === $fmt($today);
    $isPast = $day < $today;
    foreach ($drivers as $k => $driver) {
        $veh = &$vehicles[$k];
        $pool = $customers;
        shuffle($pool);
        $pairs = array_slice($pool, 0, mt_rand(1, 3));   // 1人＝往復2件
        if (!$isPast && !$isToday) {
            // 未来は予約だけ
            foreach ($pairs as $j => $c) {
                $addTrip($day, $driver, $veh, 0, $rideTimes[$j * 2], $c, false, false, null);
                $addTrip($day, $driver, $veh, 0, $rideTimes[$j * 2 + 1], $c, true, false, null);
            }
            continue;
        }
        $insp = $insInsp->execute([$veh['id'], $driver, $fmt($day), '07:50:00', $driverNames[$driver], $veh['km']]);
        $insPre->execute([$driver, $veh['id'], $fmt($day), '08:00:00', '08:00:00']);
        $insDep->execute([$driver, $veh['id'], $fmt($day), '08:10:00', $weathers[mt_rand(0, 3)], $veh['km']]);
        $depId = (int)$pdo->lastInsertId();
        $n = 0;
        $revenue = 0;
        $nowHm = date('H:i:s');
        foreach ($pairs as $j => $c) {
            foreach ([false, true] as $ret) {
                $t = $rideTimes[$j * 2 + ($ret ? 1 : 0)];
                // 今日は「今の時刻より前」の乗車だけ完了、それ以降は予約のまま（＝乗務中に見える）
                $done = $isPast || $t < $nowHm;
                $revenue += $addTrip($day, $driver, $veh, $done ? ++$n : 0, $t, $c, $ret, $done, $depId);
            }
        }
        $dist = $n * mt_rand(6, 11) + mt_rand(3, 8);
        if ($isPast) {
            $insArr->execute([$depId, $driver, $veh['id'], $fmt($day), '17:30:00', $veh['km'] + $dist, $dist,
                mt_rand(0, 4) === 0 ? mt_rand(30, 60) * 100 : 0, $n, $revenue]);
            $insPost->execute([$driver, $veh['id'], $fmt($day), '17:40:00', '17:40:00']);
            $veh['km'] += $dist;
        }
    }
    unset($veh);
}
foreach ($vehicles as $v) {
    $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?")->execute([$v['km'], $v['id']]);
}

$pdo->commit();
echo "デモデータ投入完了: 日数 " . count($days) . " / 利用者 " . count($customers) . "\n";
