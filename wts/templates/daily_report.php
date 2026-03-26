<?php
/**
 * 運転日報（乗務記録）印刷用テンプレート
 * パラメータ: ?date=2026-03-26&driver_id=1 (driver_id=all で全員分)
 * A4横向き印刷用レイアウト
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';

// セッション開始・認証チェック
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$pdo = getDBConnection();

// パラメータ取得
$date = $_GET['date'] ?? date('Y-m-d');
$driver_id_param = $_GET['driver_id'] ?? 'all';

// 日付バリデーション
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// 会社情報取得
$company = [
    'company_name' => '',
    'representative_name' => '',
    'address' => '',
    'phone' => '',
    'license_number' => '',
    'business_type' => ''
];
try {
    $stmt = $pdo->prepare("SELECT * FROM company_info WHERE id = 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $company = array_merge($company, $row);
    }
} catch (PDOException $e) {
    // テーブルが存在しない場合は空欄のまま
}

// 運行管理者を取得（is_manager=1のユーザー名）
$manager_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM users WHERE is_manager = 1 AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $manager_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($manager_row) {
        $manager_name = $manager_row['name'];
    }
} catch (PDOException $e) {
    // 無視
}

// 対象ドライバー一覧を取得
$target_drivers = [];
if ($driver_id_param === 'all') {
    // 当日に出庫記録があるドライバーを取得
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.driver_license_number, u.driver_license_type
        FROM users u
        INNER JOIN departure_records dr ON u.id = dr.driver_id AND dr.departure_date = ?
        WHERE u.is_driver = 1 AND u.is_active = 1
        ORDER BY u.name
    ");
    $stmt->execute([$date]);
    $target_drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 出庫記録がない場合は乗車記録があるドライバーも含める
    if (empty($target_drivers)) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.name, u.driver_license_number, u.driver_license_type
            FROM users u
            INNER JOIN ride_records rr ON u.id = rr.driver_id AND rr.ride_date = ?
            WHERE u.is_driver = 1 AND u.is_active = 1
            ORDER BY u.name
        ");
        $stmt->execute([$date]);
        $target_drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $driver_id_int = intval($driver_id_param);
    $stmt = $pdo->prepare("SELECT id, name, driver_license_number, driver_license_type FROM users WHERE id = ?");
    $stmt->execute([$driver_id_int]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $target_drivers = [$row];
    }
}

// 各ドライバーのデータを収集
$reports = [];

foreach ($target_drivers as $driver) {
    $did = $driver['id'];
    $report = [
        'driver' => $driver,
        'departures' => [],
        'arrivals' => [],
        'rides' => [],
        'vehicle' => null,
        'summary' => []
    ];

    // 出庫記録
    $stmt = $pdo->prepare("
        SELECT dr.*, v.vehicle_number, v.vehicle_name
        FROM departure_records dr
        JOIN vehicles v ON dr.vehicle_id = v.id
        WHERE dr.departure_date = ? AND dr.driver_id = ?
        ORDER BY dr.departure_time ASC
    ");
    $stmt->execute([$date, $did]);
    $report['departures'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 入庫記録
    $stmt = $pdo->prepare("
        SELECT ar.*, dr.departure_time, dr.departure_mileage, dr.weather,
               v.vehicle_number, v.vehicle_name
        FROM arrival_records ar
        LEFT JOIN departure_records dr ON ar.departure_record_id = dr.id
        LEFT JOIN vehicles v ON ar.vehicle_id = v.id
        WHERE ar.arrival_date = ? AND ar.driver_id = ?
        ORDER BY ar.arrival_time ASC
    ");
    $stmt->execute([$date, $did]);
    $report['arrivals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 乗車記録
    $stmt = $pdo->prepare("
        SELECT rr.*
        FROM ride_records rr
        WHERE rr.ride_date = ? AND rr.driver_id = ?
        ORDER BY rr.ride_time ASC
    ");
    $stmt->execute([$date, $did]);
    $report['rides'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 車両情報（最初の出庫記録から取得）
    if (!empty($report['departures'])) {
        $report['vehicle'] = [
            'vehicle_number' => $report['departures'][0]['vehicle_number'],
            'vehicle_name' => $report['departures'][0]['vehicle_name']
        ];
    } elseif (!empty($report['arrivals'])) {
        $report['vehicle'] = [
            'vehicle_number' => $report['arrivals'][0]['vehicle_number'],
            'vehicle_name' => $report['arrivals'][0]['vehicle_name']
        ];
    }

    // 集計計算
    $total_rides = count($report['rides']);
    $total_fare = 0;
    $total_passengers = 0;
    foreach ($report['rides'] as $ride) {
        $total_fare += intval($ride['total_fare'] ?? ($ride['fare'] + ($ride['charge'] ?? 0)));
        $total_passengers += intval($ride['passenger_count'] ?? 0);
    }

    // 出庫・入庫メーター
    $departure_mileage = null;
    $arrival_mileage = null;
    $departure_time_first = null;
    $arrival_time_last = null;
    $weather = '';

    if (!empty($report['departures'])) {
        $departure_mileage = $report['departures'][0]['departure_mileage'];
        $departure_time_first = $report['departures'][0]['departure_time'];
        $weather = $report['departures'][0]['weather'] ?? '';
    }
    if (!empty($report['arrivals'])) {
        $last_arrival = end($report['arrivals']);
        $arrival_mileage = $last_arrival['arrival_mileage'];
        $arrival_time_last = $last_arrival['arrival_time'];
    }

    $total_distance = null;
    if ($departure_mileage !== null && $arrival_mileage !== null) {
        $total_distance = $arrival_mileage - $departure_mileage;
    }

    // 経費合計
    $total_fuel = 0;
    $total_highway = 0;
    $total_other_cost = 0;
    $break_info = [];
    $remarks_list = [];
    foreach ($report['arrivals'] as $ar) {
        $total_fuel += intval($ar['fuel_cost'] ?? 0);
        $total_highway += intval($ar['highway_cost'] ?? 0);
        $total_other_cost += intval($ar['other_cost'] ?? 0);
        if (!empty($ar['break_location'])) {
            $break_entry = $ar['break_location'];
            if (!empty($ar['break_start_time']) && !empty($ar['break_end_time'])) {
                $break_entry .= ' ' . substr($ar['break_start_time'], 0, 5) . '-' . substr($ar['break_end_time'], 0, 5);
            }
            $break_info[] = $break_entry;
        }
        if (!empty($ar['remarks'])) {
            $remarks_list[] = $ar['remarks'];
        }
    }

    $report['summary'] = [
        'total_rides' => $total_rides,
        'total_passengers' => $total_passengers,
        'total_fare' => $total_fare,
        'departure_mileage' => $departure_mileage,
        'arrival_mileage' => $arrival_mileage,
        'total_distance' => $total_distance,
        'departure_time' => $departure_time_first,
        'arrival_time' => $arrival_time_last,
        'weather' => $weather,
        'total_fuel' => $total_fuel,
        'total_highway' => $total_highway,
        'total_other_cost' => $total_other_cost,
        'break_info' => implode(' / ', $break_info),
        'remarks' => implode(' / ', $remarks_list)
    ];

    $reports[] = $report;
}

// 日付フォーマット
$date_display = date('Y年m月d日', strtotime($date));
$day_of_week = ['日', '月', '火', '水', '木', '金', '土'][date('w', strtotime($date))];
$date_display .= '（' . $day_of_week . '）';

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>運転日報 - <?= htmlspecialchars($date_display) ?></title>
    <style>
        /* 基本スタイル */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Yu Gothic', 'YuGothic', 'Meiryo', 'Hiragino Kaku Gothic Pro', sans-serif;
            font-size: 11px;
            color: #000;
            background: #f5f5f5;
        }

        /* 印刷用ボタン */
        .print-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #343a40;
            color: white;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .print-controls .btn-print {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        .print-controls .btn-print:hover {
            background: #0056b3;
        }
        .print-controls .btn-close-report {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
        }
        .print-controls .btn-close-report:hover {
            background: #5a6268;
        }
        .print-controls .info {
            font-size: 13px;
            opacity: 0.9;
        }

        /* ページコンテナ */
        .page {
            width: 297mm;
            min-height: 210mm;
            margin: 60px auto 20px;
            background: white;
            padding: 10mm;
            box-shadow: 0 2px 12px rgba(0,0,0,0.15);
        }

        /* ヘッダー */
        .report-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 8px;
            margin-bottom: 8px;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
        }

        /* 情報テーブル */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .info-table td {
            padding: 3px 6px;
            font-size: 10.5px;
            vertical-align: middle;
        }
        .info-table .label {
            font-weight: bold;
            white-space: nowrap;
            width: 1%;
        }
        .info-table .value {
            border-bottom: 1px solid #999;
            min-width: 80px;
        }

        /* メインテーブル */
        .records-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .records-table th,
        .records-table td {
            border: 1px solid #333;
            padding: 3px 5px;
            text-align: center;
            font-size: 10px;
            vertical-align: middle;
        }
        .records-table th {
            background: #e9e9e9;
            font-weight: bold;
            font-size: 9.5px;
        }
        .records-table td.text-left {
            text-align: left;
        }
        .records-table td.text-right {
            text-align: right;
        }

        /* フッターセクション */
        .footer-section {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .footer-section td {
            border: 1px solid #333;
            padding: 3px 6px;
            font-size: 10px;
            vertical-align: top;
        }
        .footer-section .footer-label {
            background: #e9e9e9;
            font-weight: bold;
            white-space: nowrap;
            width: 1%;
        }

        /* 合計行 */
        .summary-row td {
            font-weight: bold;
            background: #f0f0f0;
        }

        /* 印鑑欄 */
        .stamp-area {
            float: right;
            margin-top: 4px;
        }
        .stamp-area table {
            border-collapse: collapse;
        }
        .stamp-area th,
        .stamp-area td {
            border: 1px solid #333;
            padding: 2px 10px;
            font-size: 9px;
            text-align: center;
        }
        .stamp-area th {
            background: #e9e9e9;
            font-weight: bold;
        }
        .stamp-area td {
            height: 30px;
            width: 50px;
        }

        /* 空行ガイド */
        .empty-row td {
            height: 22px;
        }

        /* 印刷設定 */
        @page {
            size: A4 landscape;
            margin: 8mm;
        }

        @media print {
            .print-controls {
                display: none !important;
            }
            body {
                background: white;
                font-size: 10px;
            }
            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
                page-break-after: always;
            }
            .page:last-child {
                page-break-after: auto;
            }
            .records-table th,
            .records-table td {
                font-size: 9px;
                padding: 2px 4px;
            }
            .info-table td {
                font-size: 9.5px;
            }
        }

        /* データなし */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #666;
            font-size: 16px;
        }
        .no-data i {
            display: block;
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
    </style>
</head>
<body>

<!-- 印刷コントロール -->
<div class="print-controls">
    <div class="info">
        運転日報 - <?= htmlspecialchars($date_display) ?>
        <?php if ($driver_id_param !== 'all'): ?>
            / <?= htmlspecialchars($target_drivers[0]['name'] ?? '') ?>
        <?php else: ?>
            / 全乗務員（<?= count($reports) ?>名）
        <?php endif; ?>
    </div>
    <div>
        <button class="btn-print" onclick="window.print()">
            PDF保存 / 印刷
        </button>
        <a href="javascript:window.close()" class="btn-close-report" style="margin-left:8px;">閉じる</a>
    </div>
</div>

<?php if (empty($reports)): ?>
<div class="page">
    <div class="no-data">
        <i class="fas fa-inbox"></i>
        <?= htmlspecialchars($date_display) ?> の運行記録がありません。
    </div>
</div>

<?php else: ?>

<?php foreach ($reports as $ri => $report):
    $d = $report['driver'];
    $s = $report['summary'];
    $v = $report['vehicle'];
?>
<div class="page">
    <!-- タイトル -->
    <div class="report-title">運 転 日 報（乗務記録）</div>

    <!-- 上段情報 -->
    <table class="info-table">
        <tr>
            <td class="label">事業者名:</td>
            <td class="value"><?= htmlspecialchars($company['company_name']) ?></td>
            <td class="label" style="padding-left:16px;">運行管理者:</td>
            <td class="value"><?= htmlspecialchars($manager_name) ?></td>
            <td class="label" style="padding-left:16px;">日付:</td>
            <td class="value"><?= htmlspecialchars($date_display) ?></td>
        </tr>
    </table>

    <!-- 乗務員・車両情報 -->
    <table class="info-table" style="margin-bottom:8px;">
        <tr>
            <td class="label">乗務員:</td>
            <td class="value"><?= htmlspecialchars($d['name']) ?></td>
            <td class="label" style="padding-left:16px;">免許番号:</td>
            <td class="value"><?= htmlspecialchars($d['driver_license_number'] ?? '') ?></td>
            <td class="label" style="padding-left:16px;">免許種別:</td>
            <td class="value"><?= htmlspecialchars($d['driver_license_type'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="label">車両番号:</td>
            <td class="value"><?= htmlspecialchars($v['vehicle_number'] ?? '') ?></td>
            <td class="label" style="padding-left:16px;">車両名:</td>
            <td class="value"><?= htmlspecialchars($v['vehicle_name'] ?? '') ?></td>
            <td class="label" style="padding-left:16px;">天候:</td>
            <td class="value"><?= htmlspecialchars($s['weather']) ?></td>
        </tr>
        <tr>
            <td class="label">出庫時刻:</td>
            <td class="value"><?= $s['departure_time'] ? substr($s['departure_time'], 0, 5) : '' ?></td>
            <td class="label" style="padding-left:16px;">出庫メーター:</td>
            <td class="value"><?= $s['departure_mileage'] !== null ? number_format($s['departure_mileage']) . ' km' : '' ?></td>
            <td class="label" style="padding-left:16px;">入庫時刻:</td>
            <td class="value"><?= $s['arrival_time'] ? substr($s['arrival_time'], 0, 5) : '' ?></td>
        </tr>
        <tr>
            <td class="label">入庫メーター:</td>
            <td class="value"><?= $s['arrival_mileage'] !== null ? number_format($s['arrival_mileage']) . ' km' : '' ?></td>
            <td class="label" style="padding-left:16px;">走行距離:</td>
            <td class="value"><?= $s['total_distance'] !== null ? number_format($s['total_distance']) . ' km' : '' ?></td>
            <td colspan="2"></td>
        </tr>
    </table>

    <!-- 運行記録テーブル -->
    <table class="records-table">
        <thead>
            <tr>
                <th style="width:30px;">No.</th>
                <th style="width:50px;">時刻</th>
                <th>乗車地</th>
                <th>降車地</th>
                <th style="width:40px;">人数</th>
                <th style="width:55px;">輸送分類</th>
                <th style="width:60px;">運賃</th>
                <th style="width:60px;">迎車料</th>
                <th style="width:65px;">合計金額</th>
                <th style="width:50px;">支払</th>
                <th style="width:35px;">往復</th>
                <th>備考</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($report['rides'])): ?>
            <tr>
                <td colspan="12" style="padding:12px; color:#999;">運行記録なし</td>
            </tr>
            <?php else: ?>
            <?php foreach ($report['rides'] as $i => $ride):
                $ride_total = intval($ride['total_fare'] ?? ($ride['fare'] + ($ride['charge'] ?? 0)));
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $ride['ride_time'] ? substr($ride['ride_time'], 0, 5) : '' ?></td>
                <td class="text-left"><?= htmlspecialchars($ride['pickup_location'] ?? '') ?></td>
                <td class="text-left"><?= htmlspecialchars($ride['dropoff_location'] ?? '') ?></td>
                <td><?= intval($ride['passenger_count']) ?></td>
                <td><?= htmlspecialchars($ride['transportation_type'] ?? '') ?></td>
                <td class="text-right">&yen;<?= number_format(intval($ride['fare'])) ?></td>
                <td class="text-right">&yen;<?= number_format(intval($ride['charge'] ?? 0)) ?></td>
                <td class="text-right">&yen;<?= number_format($ride_total) ?></td>
                <td><?= htmlspecialchars($ride['payment_method'] ?? '') ?></td>
                <td><?= ($ride['is_return_trip'] ?? 0) ? '復' : '往' ?></td>
                <td class="text-left"><?= htmlspecialchars($ride['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php
            // 空行を追加して最低行数を確保（印刷用）
            $min_rows = 15;
            $current_rows = count($report['rides']);
            for ($j = $current_rows; $j < $min_rows; $j++):
            ?>
            <tr class="empty-row">
                <td><?= $j + 1 ?></td>
                <td></td><td></td><td></td><td></td><td></td>
                <td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <?php endfor; ?>

            <!-- 合計行 -->
            <tr class="summary-row">
                <td colspan="4" style="text-align:right;">合計</td>
                <td><?= $s['total_passengers'] ?></td>
                <td><?= $s['total_rides'] ?>件</td>
                <td colspan="2"></td>
                <td class="text-right">&yen;<?= number_format($s['total_fare']) ?></td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>

    <!-- フッターセクション -->
    <table class="footer-section">
        <tr>
            <td class="footer-label">経費</td>
            <td>
                燃料: &yen;<?= number_format($s['total_fuel']) ?>
                &nbsp;&nbsp;高速: &yen;<?= number_format($s['total_highway']) ?>
                &nbsp;&nbsp;その他: &yen;<?= number_format($s['total_other_cost']) ?>
            </td>
            <td class="footer-label">休憩</td>
            <td><?= htmlspecialchars($s['break_info'] ?: '-') ?></td>
        </tr>
        <tr>
            <td class="footer-label">備考</td>
            <td colspan="3"><?= htmlspecialchars($s['remarks'] ?: '') ?>&nbsp;</td>
        </tr>
    </table>

    <!-- 印鑑欄 -->
    <div class="stamp-area">
        <table>
            <tr>
                <th>運行管理者</th>
                <th>乗務員</th>
                <th>確認者</th>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td></td>
            </tr>
        </table>
    </div>
    <div style="clear:both;"></div>
</div>
<?php endforeach; ?>

<?php endif; ?>

</body>
</html>
