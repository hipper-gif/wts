<?php
session_start();
require_once 'config/database.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// リクエストパラメータの取得
$type = $_GET['type'] ?? '';
$start_date = $_GET['start'] ?? '';
$end_date = $_GET['end'] ?? '';

if (empty($type) || empty($start_date) || empty($end_date)) {
    http_response_code(400);
    exit('必要なパラメータが不足しています');
}

// データベース接続
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    exit('データベース接続エラー: ' . $e->getMessage());
}

// 簡易PDF生成クラス（実際の実装ではTCPDFを使用）
class SimpleAuditPDF {
    private $content = '';
    private $title = '';
    private $company_name = 'スマイリーケアタクシー';
    
    public function __construct($title) {
        $this->title = $title;
        $this->addHeader();
    }
    
    private function addHeader() {
        $this->content .= "<!DOCTYPE html><html><head>";
        $this->content .= "<meta charset='UTF-8'>";
        $this->content .= "<title>{$this->title}</title>";
        $this->content .= "<style>
            body { font-family: 'MS Gothic', monospace; margin: 20px; }
            .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
            .company-name { font-size: 18px; font-weight: bold; }
            .document-title { font-size: 16px; margin: 10px 0; }
            .export-info { font-size: 12px; color: #666; }
            table { border-collapse: collapse; width: 100%; margin: 10px 0; }
            th, td { border: 1px solid #000; padding: 5px; text-align: center; font-size: 12px; }
            th { background-color: #f0f0f0; font-weight: bold; }
            .signature-area { margin-top: 30px; border: 1px solid #000; padding: 10px; }
            .page-break { page-break-after: always; }
            .emergency-mark { color: red; font-weight: bold; }
        </style>";
        $this->content .= "</head><body>";
        
        $this->content .= "<div class='header'>";
        $this->content .= "<div class='company-name'>{$this->company_name}</div>";
        $this->content .= "<div class='document-title emergency-mark'>🚨 {$this->title}</div>";
        $this->content .= "<div class='export-info'>出力日時: " . date('Y年m月d日 H:i') . " | 国土交通省・陸運局監査対応書類</div>";
        $this->content .= "</div>";
    }
    
    public function addSection($section_title, $data, $columns) {
        $this->content .= "<h3>{$section_title}</h3>";
        $this->content .= "<table>";
        
        // ヘッダー行
        $this->content .= "<tr>";
        foreach ($columns as $column) {
            $this->content .= "<th>{$column}</th>";
        }
        $this->content .= "</tr>";
        
        // データ行
        foreach ($data as $row) {
            $this->content .= "<tr>";
            foreach ($columns as $key => $label) {
                $value = $row[$key] ?? '-';
                $this->content .= "<td>{$value}</td>";
            }
            $this->content .= "</tr>";
        }
        
        $this->content .= "</table>";
    }
    
    public function addSignature() {
        $this->content .= "<div class='signature-area'>";
        $this->content .= "<div style='display: flex; justify-content: space-between;'>";
        $this->content .= "<div>監査対応者署名: ___________________</div>";
        $this->content .= "<div>確認日時: " . date('Y年m月d日') . "</div>";
        $this->content .= "</div>";
        $this->content .= "<div style='margin-top: 10px;'>この書類は福祉輸送管理システムから自動生成されました。</div>";
        $this->content .= "</div>";
    }
    
    public function output() {
        $this->content .= "</body></html>";
        
        // PDF出力のヘッダー設定
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $this->title . '_' . date('Ymd_His') . '.pdf"');
        
        // 実際のPDF変換（簡易版のため、HTMLをそのまま出力）
        // 本番環境ではwkhtmltopdfやTCPDFを使用
        echo $this->content;
    }
}

// データ取得と出力処理
switch ($type) {
    case 'call_records':
        generateCallRecords($pdo, $start_date, $end_date);
        break;
    case 'driving_reports':
        generateDrivingReports($pdo, $start_date, $end_date);
        break;
    case 'inspection_records':
        generateInspectionRecords($pdo, $start_date, $end_date);
        break;
    default:
        http_response_code(400);
        exit('不正な書類タイプです');
}

// 点呼記録簿生成
function generateCallRecords($pdo, $start_date, $end_date) {
    $pdf = new SimpleAuditPDF('点呼記録簿（監査提出用）');
    
    // 乗務前点呼記録の取得
    $stmt = $pdo->prepare("
        SELECT 
            pc.call_date,
            pc.call_time,
            u.name as driver_name,
            v.vehicle_number,
            pc.caller_name,
            pc.alcohol_check_value,
            CASE 
                WHEN pc.health_check = 1 THEN '良好'
                ELSE '要注意'
            END as health_status,
            pc.created_at
        FROM pre_duty_calls pc
        JOIN users u ON pc.driver_id = u.id
        JOIN vehicles v ON pc.vehicle_id = v.id
        WHERE pc.call_date BETWEEN ? AND ?
        ORDER BY pc.call_date DESC, pc.call_time DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $pre_duty_calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 乗務前点呼のデータ出力
    $columns = [
        'call_date' => '点呼日',
        'call_time' => '点呼時刻',
        'driver_name' => '運転者名',
        'vehicle_number' => '車両番号',
        'caller_name' => '点呼者',
        'alcohol_check_value' => 'アルコールチェック',
        'health_status' => '健康状態'
    ];
    
    $pdf->addSection('乗務前点呼記録', $pre_duty_calls, $columns);
    
    // 乗務後点呼記録の取得
    $stmt = $pdo->prepare("
        SELECT 
            pc.call_date,
            pc.call_time,
            u.name as driver_name,
            v.vehicle_number,
            pc.caller_name,
            pc.alcohol_check_value,
            pc.created_at
        FROM post_duty_calls pc
        JOIN users u ON pc.driver_id = u.id
        JOIN vehicles v ON pc.vehicle_id = v.id
        WHERE pc.call_date BETWEEN ? AND ?
        ORDER BY pc.call_date DESC, pc.call_time DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $post_duty_calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($post_duty_calls)) {
        $pdf->addSection('乗務後点呼記録', $post_duty_calls, $columns);
    }
    
    // 点呼実施状況サマリー
    $total_days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
    $pre_duty_count = count($pre_duty_calls);
    $post_duty_count = count($post_duty_calls);
    
    $summary_data = [
        [
            'item' => '対象期間',
            'value' => $start_date . ' ～ ' . $end_date . ' (' . $total_days . '日間)'
        ],
        [
            'item' => '乗務前点呼実施回数',
            'value' => $pre_duty_count . '回'
        ],
        [
            'item' => '乗務後点呼実施回数',
            'value' => $post_duty_count . '回'
        ],
        [
            'item' => '点呼実施率',
            'value' => round(($pre_duty_count / ($total_days * 2)) * 100, 1) . '%'
        ]
    ];
    
    $pdf->addSection('点呼実施状況サマリー', $summary_data, ['item' => '項目', 'value' => '値']);
    
    $pdf->addSignature();
    $pdf->output();
}

// 運転日報生成
function generateDrivingReports($pdo, $start_date, $end_date) {
    $pdf = new SimpleAuditPDF('運転日報（監査提出用）');
    
    // 出庫・入庫記録の取得
    $stmt = $pdo->prepare("
        SELECT 
            dr.departure_date,
            dr.departure_time,
            u.name as driver_name,
            v.vehicle_number,
            dr.weather,
            dr.departure_mileage,
            ar.arrival_time,
            ar.arrival_mileage,
            ar.total_distance,
            ar.fuel_cost
        FROM departure_records dr
        JOIN users u ON dr.driver_id = u.id
        JOIN vehicles v ON dr.vehicle_id = v.id
        LEFT JOIN arrival_records ar ON dr.id = ar.departure_record_id
        WHERE dr.departure_date BETWEEN ? AND ?
        ORDER BY dr.departure_date DESC, dr.departure_time DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $operations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $columns = [
        'departure_date' => '運行日',
        'departure_time' => '出庫時刻',
        'arrival_time' => '入庫時刻',
        'driver_name' => '運転者',
        'vehicle_number' => '車両番号',
        'weather' => '天候',
        'departure_mileage' => '出庫時メーター',
        'arrival_mileage' => '入庫時メーター',
        'total_distance' => '走行距離',
        'fuel_cost' => '燃料代'
    ];
    
    $pdf->addSection('運行記録', $operations, $columns);
    
    // 乗車記録の取得
    $stmt = $pdo->prepare("
        SELECT 
            rr.ride_date,
            rr.ride_time,
            u.name as driver_name,
            v.vehicle_number,
            rr.passenger_count,
            rr.pickup_location,
            rr.dropoff_location,
            rr.fare,
            rr.transportation_type,
            rr.payment_method
        FROM ride_records rr
        JOIN users u ON rr.driver_id = u.id
        JOIN vehicles v ON rr.vehicle_id = v.id
        WHERE rr.ride_date BETWEEN ? AND ?
        ORDER BY rr.ride_date DESC, rr.ride_time DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $rides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $ride_columns = [
        'ride_date' => '乗車日',
        'ride_time' => '乗車時刻',
        'driver_name' => '運転者',
        'vehicle_number' => '車両番号',
        'passenger_count' => '乗車人数',
        'pickup_location' => '乗車地',
        'dropoff_location' => '降車地',
        'fare' => '運賃',
        'transportation_type' => '輸送分類',
        'payment_method' => '支払方法'
    ];
    
    $pdf->addSection('乗車記録', $rides, $ride_columns);
    
    // 運行実績サマリー
    $total_distance = array_sum(array_column($operations, 'total_distance'));
    $total_fuel_cost = array_sum(array_column($operations, 'fuel_cost'));
    $total_fare = array_sum(array_column($rides, 'fare'));
    $total_passengers = array_sum(array_column($rides, 'passenger_count'));
    
    $summary_data = [
        [
            'item' => '対象期間',
            'value' => $start_date . ' ～ ' . $end_date
        ],
        [
            'item' => '総運行回数',
            'value' => count($operations) . '回'
        ],
        [
            'item' => '総走行距離',
            'value' => number_format($total_distance) . 'km'
        ],
        [
            'item' => '総乗車回数',
            'value' => count($rides) . '回'
        ],
        [
            'item' => '総乗車人数',
            'value' => $total_passengers . '名'
        ],
        [
            'item' => '総売上',
            'value' => number_format($total_fare) . '円'
        ],
        [
            'item' => '総燃料費',
            'value' => number_format($total_fuel_cost) . '円'
        ]
    ];
    
    $pdf->addSection('運行実績サマリー', $summary_data, ['item' => '項目', 'value' => '値']);
    
    $pdf->addSignature();
    $pdf->output();
}

// 点検記録生成
function generateInspectionRecords($pdo, $start_date, $end_date) {
    $pdf = new SimpleAuditPDF('点検記録簿（監査提出用）');
    
    // 日常点検記録の取得
    $stmt = $pdo->prepare("
        SELECT 
            di.inspection_date,
            u.name as driver_name,
            v.vehicle_number,
            di.mileage,
            CASE 
                WHEN di.cabin_brake_pedal = 1 THEN '可'
                ELSE '否'
            END as brake_check,
            CASE 
                WHEN di.cabin_parking_brake = 1 THEN '可'
                ELSE '否'
            END as parking_brake_check,
            CASE 
                WHEN di.lighting_headlights = 1 THEN '可'
                ELSE '否'
            END as lights_check,
            di.defect_details,
            di.inspector_name
        FROM daily_inspections di
        JOIN users u ON di.driver_id = u.id
        JOIN vehicles v ON di.vehicle_id = v.id
        WHERE di.inspection_date BETWEEN ? AND ?
        ORDER BY di.inspection_date DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $daily_inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $daily_columns = [
        'inspection_date' => '点検日',
        'driver_name' => '運転者',
        'vehicle_number' => '車両番号',
        'mileage' => '走行距離',
        'brake_check' => 'ブレーキ',
        'parking_brake_check' => 'パーキングブレーキ',
        'lights_check' => '灯火類',
        'defect_details' => '不良箇所',
        'inspector_name' => '点検者'
    ];
    
    $pdf->addSection('日常点検記録', $daily_inspections, $daily_columns);
    
    // 定期点検記録の取得
    $stmt = $pdo->prepare("
        SELECT 
            pi.inspection_date,
            v.vehicle_number,
            pi.inspector_name,
            pi.next_inspection_date,
            pi.steering_system_result,
            pi.brake_system_result,
            pi.running_system_result,
            pi.suspension_system_result,
            pi.powertrain_result,
            pi.electrical_system_result,
            pi.engine_result,
            pi.co_concentration,
            pi.hc_concentration
        FROM periodic_inspections pi
        JOIN vehicles v ON pi.vehicle_id = v.id
        WHERE pi.inspection_date BETWEEN ? AND ?
        ORDER BY pi.inspection_date DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $periodic_inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($periodic_inspections)) {
        $periodic_columns = [
            'inspection_date' => '点検日',
            'vehicle_number' => '車両番号',
            'inspector_name' => '点検者',
            'next_inspection_date' => '次回点検日',
            'steering_system_result' => 'かじ取り装置',
            'brake_system_result' => '制動装置',
            'running_system_result' => '走行装置',
            'co_concentration' => 'CO濃度',
            'hc_concentration' => 'HC濃度'
        ];
        
        $pdf->addSection('定期点検記録（3ヶ月点検）', $periodic_inspections, $periodic_columns);
    }
    
    // 点検実施状況サマリー
    $total_days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
    $daily_inspection_count = count($daily_inspections);
    $periodic_inspection_count = count($periodic_inspections);
    
    $summary_data = [
        [
            'item' => '対象期間',
            'value' => $start_date . ' ～ ' . $end_date . ' (' . $total_days . '日間)'
        ],
        [
            'item' => '日常点検実施回数',
            'value' => $daily_inspection_count . '回'
        ],
        [
            'item' => '定期点検実施回数',
            'value' => $periodic_inspection_count . '回'
        ],
        [
            'item' => '日常点検実施率',
            'value' => round(($daily_inspection_count / $total_days) * 100, 1) . '%'
        ]
    ];
    
    $pdf->addSection('点検実施状況サマリー', $summary_data, ['item' => '項目', 'value' => '値']);
    
    $pdf->addSignature();
    $pdf->output();
}
?>