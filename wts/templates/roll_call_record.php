<?php
/**
 * 点呼記録簿 - 印刷用テンプレート
 *
 * パラメータ:
 *   ?date=2026-03-26&driver_id=1       日次モード（特定日・特定乗務員）
 *   ?date=2026-03-26&driver_id=all     日次モード（特定日・全乗務員）
 *   ?month=2026-03&driver_id=1         月次モード（1ヶ月分を1枚の表に）
 *   ?month=2026-03&driver_id=all       月次モード（全乗務員）
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/session_check.php';

// パラメータ取得
$mode = 'daily'; // daily or monthly
$target_date = null;
$target_month = null;
$driver_id_param = $_GET['driver_id'] ?? null;

if (!empty($_GET['month'])) {
    $mode = 'monthly';
    $target_month = $_GET['month']; // 例: 2026-03
    if (!preg_match('/^\d{4}-\d{2}$/', $target_month)) {
        die('不正な月パラメータです。');
    }
} elseif (!empty($_GET['date'])) {
    $mode = 'daily';
    $target_date = $_GET['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
        die('不正な日付パラメータです。');
    }
} else {
    // デフォルトは今日
    $target_date = date('Y-m-d');
}

if (empty($driver_id_param)) {
    die('driver_idパラメータが必要です。');
}

// 会社情報の取得
$company_name = '';
try {
    $stmt = $pdo->prepare("SELECT company_name FROM company_info ORDER BY id LIMIT 1");
    $stmt->execute();
    $company_row = $stmt->fetch();
    if ($company_row) {
        $company_name = $company_row['company_name'];
    }
} catch (Exception $e) {
    // company_infoテーブルが存在しない場合は空のまま
    $company_name = '';
}

// 対象乗務員の取得
$drivers = [];
if ($driver_id_param === 'all') {
    $drivers = getActiveDrivers($pdo);
} else {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([(int)$driver_id_param]);
    $driver = $stmt->fetch();
    if ($driver) {
        $drivers[] = $driver;
    } else {
        die('指定された乗務員が見つかりません。');
    }
}

if (empty($drivers)) {
    die('対象の乗務員がいません。');
}

/**
 * 日次データを取得
 */
function getDailyData($pdo, $date, $driver_id) {
    // 乗務前点呼
    $stmt = $pdo->prepare("
        SELECT pdc.*, v.vehicle_number
        FROM pre_duty_calls pdc
        LEFT JOIN vehicles v ON pdc.vehicle_id = v.id
        WHERE pdc.driver_id = ? AND pdc.call_date = ?
        LIMIT 1
    ");
    $stmt->execute([$driver_id, $date]);
    $pre = $stmt->fetch();

    // 乗務後点呼
    $stmt = $pdo->prepare("
        SELECT pdc.*, v.vehicle_number
        FROM post_duty_calls pdc
        LEFT JOIN vehicles v ON pdc.vehicle_id = v.id
        WHERE pdc.driver_id = ? AND pdc.call_date = ?
        LIMIT 1
    ");
    $stmt->execute([$driver_id, $date]);
    $post = $stmt->fetch();

    return ['pre' => $pre, 'post' => $post];
}

/**
 * 月次データを取得
 */
function getMonthlyData($pdo, $year_month, $driver_id) {
    $start_date = $year_month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));

    // 乗務前点呼
    $stmt = $pdo->prepare("
        SELECT pdc.*, v.vehicle_number
        FROM pre_duty_calls pdc
        LEFT JOIN vehicles v ON pdc.vehicle_id = v.id
        WHERE pdc.driver_id = ? AND pdc.call_date BETWEEN ? AND ?
        ORDER BY pdc.call_date
    ");
    $stmt->execute([$driver_id, $start_date, $end_date]);
    $pre_records = $stmt->fetchAll();

    // 乗務後点呼
    $stmt = $pdo->prepare("
        SELECT pdc.*, v.vehicle_number
        FROM post_duty_calls pdc
        LEFT JOIN vehicles v ON pdc.vehicle_id = v.id
        WHERE pdc.driver_id = ? AND pdc.call_date BETWEEN ? AND ?
        ORDER BY pdc.call_date
    ");
    $stmt->execute([$driver_id, $start_date, $end_date]);
    $post_records = $stmt->fetchAll();

    // 日付でインデックス化
    $pre_by_date = [];
    foreach ($pre_records as $r) {
        $pre_by_date[$r['call_date']] = $r;
    }
    $post_by_date = [];
    foreach ($post_records as $r) {
        $post_by_date[$r['call_date']] = $r;
    }

    return [
        'pre_by_date' => $pre_by_date,
        'post_by_date' => $post_by_date,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'days_in_month' => (int)date('t', strtotime($start_date)),
        'pre_count' => count($pre_records),
        'post_count' => count($post_records),
    ];
}

/**
 * boolean値を表示用に変換
 */
function boolDisplay($val) {
    if ($val === null || $val === '') return '-';
    return $val ? '○' : '×';
}

/**
 * 時刻を短縮表示
 */
function shortTime($time) {
    if (empty($time)) return '-';
    return substr($time, 0, 5);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>点呼記録簿 - <?= $mode === 'monthly' ? htmlspecialchars($target_month) : htmlspecialchars($target_date) ?></title>
    <style>
        /* 基本スタイル */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "游ゴシック", "Yu Gothic", "メイリオ", Meiryo, sans-serif;
            font-size: 11px;
            color: #000;
            background: #f5f5f5;
        }

        /* 印刷設定 */
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        @media print {
            body {
                background: #fff;
            }

            .no-print {
                display: none !important;
            }

            .page {
                page-break-after: always;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border: none;
            }

            .page:last-child {
                page-break-after: auto;
            }
        }

        /* 画面表示用 */
        @media screen {
            .page {
                width: 297mm;
                min-height: 210mm;
                margin: 10mm auto;
                padding: 10mm;
                background: #fff;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                border: 1px solid #ccc;
            }
        }

        /* 印刷ボタン */
        .print-controls {
            text-align: center;
            padding: 15px;
            background: #fff;
            border-bottom: 1px solid #ddd;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .print-controls button {
            padding: 10px 30px;
            font-size: 16px;
            cursor: pointer;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 6px;
            margin: 0 5px;
        }

        .print-controls button:hover {
            background: #0b5ed7;
        }

        .print-controls button.btn-secondary {
            background: #6c757d;
        }

        .print-controls button.btn-secondary:hover {
            background: #5c636a;
        }

        /* ページヘッダー */
        .page-title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 8px;
            letter-spacing: 6px;
        }

        .page-subtitle {
            text-align: center;
            font-size: 12px;
            margin-bottom: 12px;
        }

        .page-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 12px;
        }

        /* テーブル共通 */
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th, td {
            border: 1px solid #000;
            padding: 3px 4px;
            text-align: center;
            vertical-align: middle;
            font-size: 10px;
            word-break: break-all;
        }

        th {
            background: #f0f0f0;
            font-weight: bold;
        }

        /* 月次テーブル */
        .monthly-table th.group-header {
            background: #e0e0e0;
            font-size: 11px;
        }

        .monthly-table td.no-data {
            color: #999;
        }

        /* 日次レイアウト */
        .daily-section {
            margin-bottom: 15px;
        }

        .daily-section h3 {
            font-size: 14px;
            padding: 6px 10px;
            background: #f0f0f0;
            border: 1px solid #000;
            border-bottom: none;
            margin: 0;
        }

        .daily-detail-table th {
            width: 25%;
            text-align: left;
            padding: 5px 10px;
            font-size: 11px;
        }

        .daily-detail-table td {
            text-align: left;
            padding: 5px 10px;
            font-size: 11px;
        }

        /* サマリー */
        .summary-row {
            font-size: 11px;
            text-align: left;
            padding: 6px 10px;
        }

        /* 印鑑欄 */
        .stamp-area {
            margin-top: 15px;
            display: flex;
            justify-content: flex-end;
        }

        .stamp-box {
            border: 1px solid #000;
            width: 60px;
            height: 60px;
            text-align: center;
            line-height: 20px;
            padding-top: 3px;
            font-size: 10px;
            margin-left: 5px;
        }

        .stamp-box .stamp-label {
            border-bottom: 1px solid #000;
            font-weight: bold;
        }
    </style>
</head>
<body>

<!-- 印刷ボタン -->
<div class="print-controls no-print">
    <button onclick="window.print()">&#128424; 印刷</button>
    <button class="btn-secondary" onclick="history.back()">戻る</button>
</div>

<?php if ($mode === 'monthly'): ?>
    <?php
    // === 月次モード ===
    $year = (int)substr($target_month, 0, 4);
    $month = (int)substr($target_month, 5, 2);

    foreach ($drivers as $driver):
        $data = getMonthlyData($pdo, $target_month, $driver['id']);
        $days_in_month = $data['days_in_month'];
    ?>
    <div class="page">
        <div class="page-title">点 呼 記 録 簿</div>
        <div class="page-info">
            <div>事業者名: <?= htmlspecialchars($company_name) ?></div>
            <div>対象月: <?= $year ?>年<?= $month ?>月</div>
            <div>乗務員: <?= htmlspecialchars($driver['name']) ?></div>
        </div>

        <table class="monthly-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width:28px;">日</th>
                    <th colspan="5" class="group-header">乗務前点呼</th>
                    <th colspan="5" class="group-header">乗務後点呼</th>
                </tr>
                <tr>
                    <!-- 乗務前 -->
                    <th style="width:42px;">時刻</th>
                    <th>点呼者</th>
                    <th style="width:32px;">健康</th>
                    <th style="width:40px;">酒気</th>
                    <th>指示事項</th>
                    <!-- 乗務後 -->
                    <th style="width:42px;">時刻</th>
                    <th>点呼者</th>
                    <th style="width:32px;">車両</th>
                    <th style="width:40px;">酒気</th>
                    <th style="width:32px;">事故</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($d = 1; $d <= $days_in_month; $d++):
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                    $pre = $data['pre_by_date'][$date_str] ?? null;
                    $post = $data['post_by_date'][$date_str] ?? null;
                ?>
                <tr>
                    <td><?= $d ?></td>
                    <?php if ($pre): ?>
                        <td><?= shortTime($pre['call_time']) ?></td>
                        <td><?= htmlspecialchars($pre['caller_name'] ?? '-') ?></td>
                        <td><?= boolDisplay($pre['health_check'] ?? null) ?></td>
                        <td><?= ($pre['alcohol_check_value'] !== null) ? number_format((float)$pre['alcohol_check_value'], 3) : '-' ?></td>
                        <td style="text-align:left;font-size:9px;"><?= htmlspecialchars(mb_strimwidth($pre['remarks'] ?? '', 0, 30, '...')) ?></td>
                    <?php else: ?>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                    <?php endif; ?>

                    <?php if ($post): ?>
                        <td><?= shortTime($post['call_time']) ?></td>
                        <td><?= htmlspecialchars($post['caller_name'] ?? '-') ?></td>
                        <td><?= boolDisplay($post['vehicle_condition_check'] ?? null) ?></td>
                        <td><?= ($post['alcohol_check_value'] !== null) ? number_format((float)$post['alcohol_check_value'], 3) : '-' ?></td>
                        <td><?= boolDisplay($post['accident_violation_check'] ?? null) ?></td>
                    <?php else: ?>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                        <td class="no-data">-</td>
                    <?php endif; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="11" class="summary-row">
                        乗務前点呼 実施日数: <?= $data['pre_count'] ?>日 / <?= $days_in_month ?>日
                        &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
                        乗務後点呼 実施日数: <?= $data['post_count'] ?>日 / <?= $days_in_month ?>日
                    </td>
                </tr>
            </tfoot>
        </table>

        <div class="stamp-area">
            <div class="stamp-box">
                <div class="stamp-label">管理者</div>
            </div>
            <div class="stamp-box">
                <div class="stamp-label">運行管理者</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

<?php else: ?>
    <?php
    // === 日次モード ===
    foreach ($drivers as $driver):
        $data = getDailyData($pdo, $target_date, $driver['id']);
        $pre = $data['pre'];
        $post = $data['post'];

        $display_date = date('Y年m月d日', strtotime($target_date));

        // 乗務前チェック項目定義
        $pre_check_items = [
            'health_check' => '健康状態',
            'clothing_check' => '服装',
            'footwear_check' => '履物',
            'pre_inspection_check' => '運行前点検',
            'license_check' => '免許証',
            'vehicle_registration_check' => '車検証',
            'insurance_check' => '保険証',
            'emergency_tools_check' => '応急工具',
            'map_check' => '地図',
            'taxi_card_check' => 'タクシーカード',
            'emergency_signal_check' => '非常信号用具',
            'change_money_check' => '釣銭',
            'crew_id_check' => '乗務員証',
            'operation_record_check' => '運行記録用紙',
            'receipt_check' => '領収書',
            'stop_sign_check' => '停止表示機',
        ];

        // 乗務後チェック項目定義
        $post_check_items = [
            'duty_record_check' => '乗務記録確認',
            'vehicle_condition_check' => '車両状態確認',
            'health_condition_check' => '健康状態確認',
            'fatigue_check' => '疲労度確認',
            'alcohol_drug_check' => '酒気・薬物確認',
            'accident_violation_check' => '事故・違反確認',
            'equipment_return_check' => '用具返却確認',
            'report_completion_check' => '報告完了確認',
            'lost_items_check' => '忘れ物確認',
            'violation_accident_check' => '事故・違反最終確認',
            'route_operation_check' => '路線運行確認',
            'passenger_condition_check' => '乗客状態確認',
        ];
    ?>
    <div class="page">
        <div class="page-title">点 呼 記 録 簿（日次）</div>
        <div class="page-info">
            <div>事業者名: <?= htmlspecialchars($company_name) ?></div>
            <div>日付: <?= htmlspecialchars($display_date) ?></div>
            <div>乗務員: <?= htmlspecialchars($driver['name']) ?></div>
        </div>

        <!-- 乗務前点呼 -->
        <div class="daily-section">
            <h3>【乗務前点呼】</h3>
            <?php if ($pre): ?>
            <table class="daily-detail-table">
                <tr>
                    <th>車両</th>
                    <td><?= htmlspecialchars($pre['vehicle_number'] ?? '-') ?></td>
                    <th>実施時刻</th>
                    <td><?= shortTime($pre['call_time']) ?></td>
                </tr>
                <tr>
                    <th>点呼者</th>
                    <td><?= htmlspecialchars($pre['caller_name'] ?? '-') ?></td>
                    <th>酒気帯び（検知値）</th>
                    <td><?= ($pre['alcohol_check_value'] !== null) ? number_format((float)$pre['alcohol_check_value'], 3) . ' mg/L' : '-' ?></td>
                </tr>
                <tr>
                    <th colspan="4" style="background:#f8f8f8; text-align:center;">確認事項（16項目）</th>
                </tr>
                <?php
                $items = array_chunk(array_keys($pre_check_items), 2, true);
                // pair them up for display
                $keys = array_keys($pre_check_items);
                for ($i = 0; $i < count($keys); $i += 2):
                    $k1 = $keys[$i];
                    $k2 = isset($keys[$i + 1]) ? $keys[$i + 1] : null;
                ?>
                <tr>
                    <th><?= htmlspecialchars($pre_check_items[$k1]) ?></th>
                    <td><?= boolDisplay($pre[$k1] ?? null) ?></td>
                    <?php if ($k2): ?>
                    <th><?= htmlspecialchars($pre_check_items[$k2]) ?></th>
                    <td><?= boolDisplay($pre[$k2] ?? null) ?></td>
                    <?php else: ?>
                    <th></th><td></td>
                    <?php endif; ?>
                </tr>
                <?php endfor; ?>
                <?php if (!empty($pre['remarks'])): ?>
                <tr>
                    <th>指示事項・備考</th>
                    <td colspan="3"><?= htmlspecialchars($pre['remarks']) ?></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php else: ?>
            <table class="daily-detail-table">
                <tr><td colspan="4" style="text-align:center; padding:15px; color:#999;">乗務前点呼データなし</td></tr>
            </table>
            <?php endif; ?>
        </div>

        <!-- 乗務後点呼 -->
        <div class="daily-section">
            <h3>【乗務後点呼】</h3>
            <?php if ($post): ?>
            <table class="daily-detail-table">
                <tr>
                    <th>車両</th>
                    <td><?= htmlspecialchars($post['vehicle_number'] ?? '-') ?></td>
                    <th>実施時刻</th>
                    <td><?= shortTime($post['call_time']) ?></td>
                </tr>
                <tr>
                    <th>点呼者</th>
                    <td><?= htmlspecialchars($post['caller_name'] ?? '-') ?></td>
                    <th>酒気帯び（検知値）</th>
                    <td><?= ($post['alcohol_check_value'] !== null) ? number_format((float)$post['alcohol_check_value'], 3) . ' mg/L' : '-' ?></td>
                </tr>
                <tr>
                    <th colspan="4" style="background:#f8f8f8; text-align:center;">確認事項（12項目）</th>
                </tr>
                <?php
                $keys = array_keys($post_check_items);
                for ($i = 0; $i < count($keys); $i += 2):
                    $k1 = $keys[$i];
                    $k2 = isset($keys[$i + 1]) ? $keys[$i + 1] : null;
                ?>
                <tr>
                    <th><?= htmlspecialchars($post_check_items[$k1]) ?></th>
                    <td><?= boolDisplay($post[$k1] ?? null) ?></td>
                    <?php if ($k2): ?>
                    <th><?= htmlspecialchars($post_check_items[$k2]) ?></th>
                    <td><?= boolDisplay($post[$k2] ?? null) ?></td>
                    <?php else: ?>
                    <th></th><td></td>
                    <?php endif; ?>
                </tr>
                <?php endfor; ?>
                <?php if (!empty($post['remarks'])): ?>
                <tr>
                    <th>備考</th>
                    <td colspan="3"><?= htmlspecialchars($post['remarks']) ?></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php else: ?>
            <table class="daily-detail-table">
                <tr><td colspan="4" style="text-align:center; padding:15px; color:#999;">乗務後点呼データなし</td></tr>
            </table>
            <?php endif; ?>
        </div>

        <div class="stamp-area">
            <div class="stamp-box">
                <div class="stamp-label">管理者</div>
            </div>
            <div class="stamp-box">
                <div class="stamp-label">運行管理者</div>
            </div>
            <div class="stamp-box">
                <div class="stamp-label">乗務員</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
