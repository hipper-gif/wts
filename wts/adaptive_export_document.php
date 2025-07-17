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

// テーブル構造確認関数
function getTableColumns($pdo, $table_name) {
    try {
        $stmt = $pdo->query("DESCRIBE {$table_name}");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        return $columns;
    } catch (PDOException $e) {
        return [];
    }
}

// カラムの存在確認
function columnExists($pdo, $table, $column) {
    $columns = getTableColumns($pdo, $table);
    return in_array($column, $columns);
}

// 適応型HTML出力クラス
class AdaptiveAuditReport {
    private $pdo;
    private $content = '';
    private $title = '';
    private $company_name = 'スマイリーケアタクシー';
    
    public function __construct($pdo, $title) {
        $this->pdo = $pdo;
        $this->title = $title;
        $this->addHeader();
    }
    
    private function addHeader() {
        $this->content = "<!DOCTYPE html>
<html lang='ja'>
<head>
    <meta charset='UTF-8'>
    <title>{$this->title}</title>
    <style>
        @page { margin: 20mm; }
        body { 
            font-family: 'MS Gothic', 'Yu Gothic', sans-serif; 
            font-size: 12px; 
            line-height: 1.4; 
            margin: 0; 
            padding: 20px;
        }
        .header { 
            text-align: center; 
            border-bottom: 3px solid #000; 
            padding-bottom: 15px; 
            margin-bottom: 20px; 
        }
        .company-name { 
            font-size: 18px; 
            font-weight: bold; 
            margin-bottom: 10px;
        }
        .document-title { 
            font-size: 16px; 
            font-weight: bold; 
            color: #d32f2f; 
            margin-bottom: 10px;
        }
        .export-info { 
            font-size: 10px; 
            color: #666; 
        }
        .section-title { 
            background: #f0f0f0; 
            padding: 10px; 
            margin: 20px 0 10px 0; 
            font-weight: bold; 
            border: 1px solid #ccc;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 20px; 
            font-size: 11px;
        }
        th, td { 
            border: 1px solid #000; 
            padding: 5px; 
            text-align: center; 
        }
        th { 
            background: #e0e0e0; 
            font-weight: bold; 
        }
        .no-data { 
            text-align: center; 
            color: #666; 
            font-style: italic; 
            padding: 20px;
        }
        .summary-box { 
            border: 2px solid #0066cc; 
            padding: 15px; 
            margin: 20px 0; 
            background: #f8f9ff;
        }
        .signature-area { 
            margin-top: 30px; 
            border: 2px solid #000; 
            padding: 15px; 
        }
        .emergency-mark { 
            color: #d32f2f; 
            font-weight: bold; 
        }
        .adaptive-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 10px;
            margin: 10px 0;
            font-size: 10px;
        }
    </style>
</head>
<body>";
        
        $this->content .= "<div class='header'>";
        $this->content .= "<div class='company-name'>{$this->company_name}</div>";
        $this->content .= "<div class='document-title emergency-mark'>🚨 {$this->title}</div>";
        $this->content .= "<div class='export-info'>出力日時: " . date('Y年m月d日 H時i分') . " | 適応型出力システム</div>";
        $this->content .= "</div>";
    }
    
    public function addAdaptiveSection($title, $data, $note = '') {
        $this->content .= "<div class='section-title'>{$title}</div>";
        
        if (!empty($note)) {
            $this->content .= "<div class='adaptive-note'>{$note}</div>";
        }
        
        if (empty($data)) {
            $this->content .= "<div class='no-data'>該当するデータがありません</div>";
            return;
        }
        
        $this->content .= "<table>";
        
        // ヘッダー行（実際のデータの最初の行から生成）
        $this->content .= "<tr>";
        foreach (array_keys($data[0]) as $key) {
            $this->content .= "<th>{$key}</th>";
        }
        $this->content .= "</tr>";
        
        // データ行
        foreach ($data as $row) {
            $this->content .= "<tr>";
            foreach ($row as $cell) {
                $value = $cell ?? '-';
                $this->content .= "<td>" . htmlspecialchars($value) . "</td>";
            }
            $this->content .= "</tr>";
        }
        
        $this->content .= "</table>";
    }
    
    public function addSummary($title, $data) {
        $this->content .= "<div class='summary-box'>";
        $this->content .= "<strong>{$title}</strong><br>";
        foreach ($data as $key => $value) {
            $this->content .= "{$key}: {$value}<br>";
        }
        $this->content .= "</div>";
    }
    
    public function addSignature() {
        $this->content .= "<div class='signature-area'>";
        $this->content .= "<div style='display: flex; justify-content: space-between;'>";
        $this->content .= "<div>監査対応者署名: ___________________</div>";
        $this->content .= "<div>確認日時: " . date('Y年m月d日') . "</div>";
        $this->content .= "</div>";
        $this->content .= "<div style='margin-top: 10px; font-size: 10px;'>この書類は適応型出力システムにより自動生成されました。</div>";
        $this->content .= "</div>";
    }
    
    public function output() {
        $this->content .= "</body></html>";
        
        $filename = str_replace(['🚨', ' '], ['', '_'], $this->title) . '_' . date('Ymd_His') . '.html';
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        
        echo $this->content;
    }
}

// データ取得と出力処理
try {
    switch ($type) {
        case 'call_records':
            generateAdaptiveCallRecords($pdo, $start_date, $end_date);
            break;
        case 'driving_reports':
            generateAdaptiveDrivingReports($pdo, $start_date, $end_date);
            break;
        case 'inspection_records':
            generateAdaptiveInspectionRecords($pdo, $start_date, $end_date);
            break;
        case 'emergency_kit':
            generateAdaptiveEmergencyKit($pdo, $start_date, $end_date);
            break;
        default:
            http_response_code(400);
            exit('不正な書類タイプです');
    }
} catch (Exception $e) {
    http_response_code(500);
    exit('出力エラー: ' . $e->getMessage());
}

// 適応型点呼記録生成
function generateAdaptiveCallRecords($pdo, $start_date, $end_date) {
    $report = new AdaptiveAuditReport($pdo, '点呼記録簿（適応型出力）');
    
    // 利用可能なカラムを確認
    $available_columns = getTableColumns($pdo, 'pre_duty_calls');
    
    // 基本的なSELECT文を構築
    $select_parts = ['pc.call_date', 'pc.call_time'];
    $joins = [];
    
    // usersテーブルとのJOINを試行
    if (in_array('driver_id', $available_columns)) {
        $joins[] = "LEFT JOIN users u ON pc.driver_id = u.id";
        $select_parts[] = "COALESCE(u.name, 'ID:' || pc.driver_id) as driver_name";
    }
    
    // vehiclesテーブルとのJOINを試行
    if (in_array('vehicle_id', $available_columns)) {
        $joins[] = "LEFT JOIN vehicles v ON pc.vehicle_id = v.id";
        $select_parts[] = "COALESCE(v.vehicle_number, 'ID:' || pc.vehicle_id) as vehicle_number";
    }
    
    // その他の利用可能なカラム
    $optional_columns = ['caller_name', 'alcohol_check_value', 'health_check'];
    foreach ($optional_columns as $col) {
        if (in_array($col, $available_columns)) {
            $select_parts[] = "pc.{$col}";
        }
    }
    
    $sql = "SELECT " . implode(', ', $select_parts) . " FROM pre_duty_calls pc ";
    $sql .= implode(' ', $joins);
    $sql .= " WHERE pc.call_date BETWEEN ? AND ? ORDER BY pc.call_date DESC, pc.call_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $pre_duty_calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $note = "利用可能カラム: " . implode(', ', $available_columns);
    $report->addAdaptiveSection('乗務前点呼記録', $pre_duty_calls, $note);
    
    // 乗務後点呼記録（テーブルが存在する場合）
    if (getTableColumns($pdo, 'post_duty_calls')) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM post_duty_calls WHERE call_date BETWEEN ? AND ? ORDER BY call_date DESC");
            $stmt->execute([$start_date, $end_date]);
            $post_duty_calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($post_duty_calls)) {
                $report->addAdaptiveSection('乗務後点呼記録', $post_duty_calls);
            }
        } catch (PDOException $e) {
            // エラーは無視
        }
    }
    
    // サマリー
    $summary = [
        '対象期間' => $start_date . ' ～ ' . $end_date,
        '乗務前点呼記録数' => count($pre_duty_calls) . '件',
        '使用テーブル' => 'pre_duty_calls',
        '検出カラム数' => count($available_columns) . '個'
    ];
    
    $report->addSummary('点呼記録サマリー', $summary);
    $report->addSignature();
    $report->output();
}

// 適応型運転日報生成
function generateAdaptiveDrivingReports($pdo, $start_date, $end_date) {
    $report = new AdaptiveAuditReport($pdo, '運転日報（適応型出力）');
    
    // 出庫記録の取得
    $departure_columns = getTableColumns($pdo, 'departure_records');
    if (!empty($departure_columns)) {
        $stmt = $pdo->prepare("
            SELECT dr.*, u.name as driver_name, v.vehicle_number 
            FROM departure_records dr 
            LEFT JOIN users u ON dr.driver_id = u.id 
            LEFT JOIN vehicles v ON dr.vehicle_id = v.id 
            WHERE dr.departure_date BETWEEN ? AND ? 
            ORDER BY dr.departure_date DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $departures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $note = "出庫記録 - 利用可能カラム: " . implode(', ', $departure_columns);
        $report->addAdaptiveSection('出庫記録', $departures, $note);
    }
    
    // 入庫記録の取得
    $arrival_columns = getTableColumns($pdo, 'arrival_records');
    if (!empty($arrival_columns)) {
        $stmt = $pdo->prepare("
            SELECT ar.*, u.name as driver_name, v.vehicle_number 
            FROM arrival_records ar 
            LEFT JOIN users u ON ar.driver_id = u.id 
            LEFT JOIN vehicles v ON ar.vehicle_id = v.id 
            WHERE ar.arrival_date BETWEEN ? AND ? 
            ORDER BY ar.arrival_date DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $arrivals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $note = "入庫記録 - 利用可能カラム: " . implode(', ', $arrival_columns);
        $report->addAdaptiveSection('入庫記録', $arrivals, $note);
    }
    
    // 乗車記録の取得（適応型）
    $ride_columns = getTableColumns($pdo, 'ride_records');
    if (!empty($ride_columns)) {
        // 利用可能なカラムに基づいてSQLを構築
        $select_parts = [];
        $joins = [];
        
        if (in_array('ride_date', $ride_columns)) {
            $select_parts[] = 'rr.ride_date';
            $date_condition = "rr.ride_date BETWEEN ? AND ?";
        } else {
            $select_parts[] = 'DATE(rr.created_at) as ride_date';
            $date_condition = "DATE(rr.created_at) BETWEEN ? AND ?";
        }
        
        $basic_columns = ['ride_time', 'passenger_count', 'pickup_location', 'dropoff_location', 'fare'];
        foreach ($basic_columns as $col) {
            if (in_array($col, $ride_columns)) {
                $select_parts[] = "rr.{$col}";
            }
        }
        
        // transportation_typeが存在するかチェック
        if (in_array('transportation_type', $ride_columns)) {
            $select_parts[] = 'rr.transportation_type';
        } else {
            $select_parts[] = "'未設定' as transportation_type";
        }
        
        // payment_methodが存在するかチェック
        if (in_array('payment_method', $ride_columns)) {
            $select_parts[] = 'rr.payment_method';
        } else {
            $select_parts[] = "'未設定' as payment_method";
        }
        
        // JOINの設定
        if (in_array('driver_id', $ride_columns)) {
            $joins[] = "LEFT JOIN users u ON rr.driver_id = u.id";
            $select_parts[] = "COALESCE(u.name, 'ID:' || rr.driver_id) as driver_name";
        }
        
        if (in_array('vehicle_id', $ride_columns)) {
            $joins[] = "LEFT JOIN vehicles v ON rr.vehicle_id = v.id";
            $select_parts[] = "COALESCE(v.vehicle_number, 'ID:' || rr.vehicle_id) as vehicle_number";
        }
        
        $sql = "SELECT " . implode(', ', $select_parts) . " FROM ride_records rr ";
        $sql .= implode(' ', $joins);
        $sql .= " WHERE {$date_condition} ORDER BY rr.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start_date, $end_date]);
        $rides = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $note = "乗車記録 - 利用可能カラム: " . implode(', ', $ride_columns);
        $report->addAdaptiveSection('乗車記録', $rides, $note);
    }
    
    // サマリー
    $summary = [
        '対象期間' => $start_date . ' ～ ' . $end_date,
        '出庫記録数' => isset($departures) ? count($departures) . '件' : '0件',
        '入庫記録数' => isset($arrivals) ? count($arrivals) . '件' : '0件',
        '乗車記録数' => isset($rides) ? count($rides) . '件' : '0件'
    ];
    
    $report->addSummary('運転日報サマリー', $summary);
    $report->addSignature();
    $report->output();
}

// 適応型点検記録生成
function generateAdaptiveInspectionRecords($pdo, $start_date, $end_date) {
    $report = new AdaptiveAuditReport($pdo, '点検記録簿（適応型出力）');
    
    // 日常点検記録の取得
    $inspection_columns = getTableColumns($pdo, 'daily_inspections');
    if (!empty($inspection_columns)) {
        // 利用可能なカラムに基づいてSELECTを構築
        $select_parts = ['di.inspection_date'];
        $joins = [];
        
        if (in_array('driver_id', $inspection_columns)) {
            $joins[] = "LEFT JOIN users u ON di.driver_id = u.id";
            $select_parts[] = "COALESCE(u.name, 'ID:' || di.driver_id) as driver_name";
        }
        
        if (in_array('vehicle_id', $inspection_columns)) {
            $joins[] = "LEFT JOIN vehicles v ON di.vehicle_id = v.id";
            $select_parts[] = "COALESCE(v.vehicle_number, 'ID:' || di.vehicle_id) as vehicle_number";
        }
        
        // その他の利用可能なカラム
        $optional_columns = ['mileage', 'defect_details', 'inspector_name'];
        foreach ($optional_columns as $col) {
            if (in_array($col, $inspection_columns)) {
                $select_parts[] = "di.{$col}";
            }
        }
        
        // 点検項目のカラム（存在する場合のみ追加）
        $inspection_items = [
            'cabin_brake_pedal' => 'ブレーキペダル',
            'cabin_parking_brake' => 'パーキングブレーキ', 
            'lighting_headlights' => '前照灯',
            'lighting_taillights' => '尾灯',
            'engine_oil' => 'エンジンオイル',
            'brake_fluid' => 'ブレーキ液',
            'tire_condition' => 'タイヤ状態'
        ];
        
        foreach ($inspection_items as $col => $name) {
            if (in_array($col, $inspection_columns)) {
                $select_parts[] = "CASE WHEN di.{$col} = 1 THEN '可' ELSE '否' END as {$name}";
            }
        }
        
        $sql = "SELECT " . implode(', ', $select_parts) . " FROM daily_inspections di ";
        $sql .= implode(' ', $joins);
        $sql .= " WHERE di.inspection_date BETWEEN ? AND ? ORDER BY di.inspection_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$start_date, $end_date]);
        $daily_inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $note = "日常点検 - 利用可能カラム: " . implode(', ', $inspection_columns);
        $report->addAdaptiveSection('日常点検記録', $daily_inspections, $note);
    }
    
    // 定期点検記録の取得
    $periodic_columns = getTableColumns($pdo, 'periodic_inspections');
    if (!empty($periodic_columns)) {
        try {
            $stmt = $pdo->prepare("
                SELECT pi.*, v.vehicle_number 
                FROM periodic_inspections pi 
                LEFT JOIN vehicles v ON pi.vehicle_id = v.id 
                WHERE pi.inspection_date BETWEEN ? AND ? 
                ORDER BY pi.inspection_date DESC
            ");
            $stmt->execute([$start_date, $end_date]);
            $periodic_inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($periodic_inspections)) {
                $note = "定期点検 - 利用可能カラム: " . implode(', ', $periodic_columns);
                $report->addAdaptiveSection('定期点検記録', $periodic_inspections, $note);
            }
        } catch (PDOException $e) {
            // エラーは無視
        }
    }
    
    // サマリー
    $summary = [
        '対象期間' => $start_date . ' ～ ' . $end_date,
        '日常点検記録数' => isset($daily_inspections) ? count($daily_inspections) . '件' : '0件',
        '定期点検記録数' => isset($periodic_inspections) ? count($periodic_inspections) . '件' : '0件',
        '検出カラム数' => count($inspection_columns) . '個'
    ];
    
    $report->addSummary('点検記録サマリー', $summary);
    $report->addSignature();
    $report->output();
}

// 適応型緊急監査キット生成
function generateAdaptiveEmergencyKit($pdo, $start_date, $end_date) {
    $report = new AdaptiveAuditReport($pdo, '緊急監査対応セット（適応型）');
    
    // システム情報の表示
    $system_info = [
        ['項目' => 'システム名', '内容' => 'スマイリーケアタクシー 福祉輸送管理システム'],
        ['項目' => '出力方式', '内容' => '適応型出力システム'],
        ['項目' => '対象期間', '内容' => $start_date . ' ～ ' . $end_date],
        ['項目' => '出力日時', '内容' => date('Y-m-d H:i:s')],
        ['項目' => '監査対応', '内容' => '国土交通省・陸運局監査対応']
    ];
    
    $report->addAdaptiveSection('システム情報', $system_info);
    
    // 各テーブルの状況確認
    $tables_status = [];
    $required_tables = ['pre_duty_calls', 'daily_inspections', 'departure_records', 'arrival_records', 'ride_records'];
    
    foreach ($required_tables as $table) {
        $columns = getTableColumns($pdo, $table);
        if (!empty($columns)) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
                $count = $stmt->fetchColumn();
                $tables_status[] = [
                    'テーブル名' => $table,
                    '状態' => '✅ 存在',
                    'カラム数' => count($columns) . '個',
                    'レコード数' => $count . '件'
                ];
            } catch (PDOException $e) {
                $tables_status[] = [
                    'テーブル名' => $table,
                    '状態' => '❌ アクセスエラー',
                    'カラム数' => '-',
                    'レコード数' => '-'
                ];
            }
        } else {
            $tables_status[] = [
                'テーブル名' => $table,
                '状態' => '❌ 未存在',
                'カラム数' => '-',
                'レコード数' => '-'
            ];
        }
    }
    
    $report->addAdaptiveSection('データベース状況', $tables_status);
    
    // 監査準備度の計算
    $readiness_score = 0;
    $readiness_items = [];
    
    // 点呼記録の確認
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date >= ?");
        $stmt->execute([date('Y-m-d', strtotime('-3 months'))]);
        $call_count = $stmt->fetchColumn();
        
        if ($call_count >= 60) {
            $readiness_score += 25;
            $readiness_items[] = ['項目' => '点呼記録', '状態' => '✅ 良好', '詳細' => "{$call_count}件"];
        } else {
            $readiness_items[] = ['項目' => '点呼記録', '状態' => '⚠️ 不足', '詳細' => "{$call_count}件"];
        }
    } catch (PDOException $e) {
        $readiness_items[] = ['項目' => '点呼記録', '状態' => '❌ エラー', '詳細' => $e->getMessage()];
    }
    
    // 日常点検の確認
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date >= ?");
        $stmt->execute([date('Y-m-d', strtotime('-1 month'))]);
        $inspection_count = $stmt->fetchColumn();
        
        if ($inspection_count >= 20) {
            $readiness_score += 25;
            $readiness_items[] = ['項目' => '日常点検', '状態' => '✅ 良好', '詳細' => "{$inspection_count}件"];
        } else {
            $readiness_items[] = ['項目' => '日常点検', '状態' => '⚠️ 不足', '詳細' => "{$inspection_count}件"];
        }
    } catch (PDOException $e) {
        $readiness_items[] = ['項目' => '日常点検', '状態' => '❌ エラー', '詳細' => $e->getMessage()];
    }
    
    // 運行記録の確認
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date >= ?");
        $stmt->execute([date('Y-m-d', strtotime('-3 months'))]);
        $departure_count = $stmt->fetchColumn();
        
        if ($departure_count >= 50) {
            $readiness_score += 25;
            $readiness_items[] = ['項目' => '運行記録', '状態' => '✅ 良好', '詳細' => "{$departure_count}件"];
        } else {
            $readiness_items[] = ['項目' => '運行記録', '状態' => '⚠️ 不足', '詳細' => "{$departure_count}件"];
        }
    } catch (PDOException $e) {
        $readiness_items[] = ['項目' => '運行記録', '状態' => '❌ エラー', '詳細' => $e->getMessage()];
    }
    
    // 乗車記録の確認
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_records WHERE created_at >= ?");
        $stmt->execute([date('Y-m-d', strtotime('-3 months'))]);
        $ride_count = $stmt->fetchColumn();
        
        if ($ride_count >= 100) {
            $readiness_score += 25;
            $readiness_items[] = ['項目' => '乗車記録', '状態' => '✅ 良好', '詳細' => "{$ride_count}件"];
        } else {
            $readiness_items[] = ['項目' => '乗車記録', '状態' => '⚠️ 不足', '詳細' => "{$ride_count}件"];
        }
    } catch (PDOException $e) {
        $readiness_items[] = ['項目' => '乗車記録', '状態' => '❌ エラー', '詳細' => $e->getMessage()];
    }
    
    $report->addAdaptiveSection('監査準備度チェック', $readiness_items);
    
    // 推奨アクション
    $recommendations = [];
    if ($readiness_score < 80) {
        $recommendations[] = ['アクション' => 'データ生成', '内容' => 'audit_data_manager.php でデータを生成'];
        $recommendations[] = ['アクション' => 'テーブル修正', '内容' => 'fix_table_structure.php でテーブル構造を修正'];
    }
    
    if ($readiness_score >= 80) {
        $recommendations[] = ['アクション' => '監査対応OK', '内容' => '現在のデータで監査対応可能'];
    }
    
    $recommendations[] = ['アクション' => '定期確認', '内容' => '週次でデータ状況を確認'];
    
    $report->addAdaptiveSection('推奨アクション', $recommendations);
    
    // サマリー
    $summary = [
        '監査準備度' => $readiness_score . '%',
        '対象期間' => $start_date . ' ～ ' . $end_date,
        '確認テーブル数' => count($required_tables) . '個',
        '出力方式' => '適応型（テーブル構造自動判定）',
        '監査対応' => $readiness_score >= 80 ? '✅ 準備完了' : '⚠️ 要改善'
    ];
    
    $report->addSummary('緊急監査対応サマリー', $summary);
    $report->addSignature();
    $report->output();
}
?>