<?php
// includes/historical_common.php
// 過去データ入力用共通関数

/**
 * 営業日のみの日付配列を生成
 * @param string $start_date 開始日 (Y-m-d)
 * @param string $end_date 終了日 (Y-m-d)
 * @param array $exclude_weekdays 除外する曜日 [0=日, 1=月, ..., 6=土]
 * @return array 営業日の配列
 */
function generateBusinessDates($start_date, $end_date, $exclude_weekdays = [0, 6]) {
    $dates = [];
    $current = new DateTime($start_date);
    $end = new DateTime($end_date);
    
    while ($current <= $end) {
        $weekday = (int)$current->format('w'); // 0=日曜, 6=土曜
        
        // 除外曜日でなければ追加
        if (!in_array($weekday, $exclude_weekdays)) {
            $dates[] = $current->format('Y-m-d');
        }
        
        $current->add(new DateInterval('P1D'));
    }
    
    return $dates;
}

/**
 * 既存データの確認
 * @param PDO $pdo データベース接続
 * @param string $table テーブル名
 * @param string $date_field 日付フィールド名
 * @param array $dates 確認する日付配列
 * @param array $additional_conditions 追加の条件 ['field' => 'value']
 * @return array 既存データがある日付の配列
 */
function checkExistingData($pdo, $table, $date_field, $dates, $additional_conditions = []) {
    if (empty($dates)) {
        return [];
    }
    
    $existing = [];
    $placeholders = str_repeat('?,', count($dates) - 1) . '?';
    
    $where_clause = "$date_field IN ($placeholders)";
    $params = $dates;
    
    foreach ($additional_conditions as $field => $value) {
        $where_clause .= " AND $field = ?";
        $params[] = $value;
    }
    
    $sql = "SELECT $date_field FROM $table WHERE $where_clause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[] = $row[$date_field];
    }
    
    return $existing;
}

/**
 * 日付配列を既存/未入力に分類
 * @param array $all_dates 全日付配列
 * @param array $existing_dates 既存データがある日付配列
 * @return array ['existing' => [], 'missing' => []]
 */
function categorizeDates($all_dates, $existing_dates) {
    return [
        'existing' => array_intersect($all_dates, $existing_dates),
        'missing' => array_diff($all_dates, $existing_dates)
    ];
}

/**
 * ユーザー一覧を取得（特定の役割）
 * @param PDO $pdo データベース接続
 * @param string $role_filter 役割フィルター (driver, caller, etc.)
 * @return array ユーザー配列
 */
function getUsersByRole($pdo, $role_filter = null) {
    $sql = "SELECT id, name FROM users WHERE active = 1";
    $params = [];
    
    if ($role_filter) {
        // 新しい権限システム（is_driver, is_caller等）に対応
        $sql .= " AND is_{$role_filter} = 1";
    }
    
    $sql .= " ORDER BY name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 車両一覧を取得
 * @param PDO $pdo データベース接続
 * @return array 車両配列
 */
function getVehicles($pdo) {
    $sql = "SELECT id, vehicle_number, model FROM vehicles ORDER BY vehicle_number";
    $stmt = $pdo->query($sql);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 日常点検のデフォルトデータを生成
 * @param array $base_data 基本データ ['vehicle_id', 'inspector_id', 'inspection_date']
 * @return array 日常点検データ
 */
function generateDailyInspectionDefaults($base_data) {
    return array_merge($base_data, [
        // 運転室内点検
        'cabin_brake_pedal' => '可',
        'cabin_parking_brake' => '可',
        'cabin_engine_condition' => '可',
        'cabin_engine_performance' => '可',
        'cabin_wiper_performance' => '可',
        'cabin_washer_fluid_spray' => '可',
        
        // エンジンルーム内点検
        'engine_brake_fluid' => '可',
        'engine_coolant_level' => '可',
        'engine_oil_level' => '可',
        'engine_battery_fluid' => '可',
        'engine_washer_fluid_level' => '可',
        'engine_fan_belt' => '可',
        
        // 灯火類・タイヤ点検
        'light_headlights' => '可',
        'light_taillights' => '可',
        'light_brake_lights' => '可',
        'tire_air_pressure' => '可',
        'tire_condition' => '可',
        
        // その他
        'defect_details' => '',
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * 乗務前点呼のデフォルトデータを生成
 * @param array $base_data 基本データ
 * @return array 乗務前点呼データ
 */
function generatePreDutyCallDefaults($base_data) {
    return array_merge($base_data, [
        // 15項目の確認事項（全てチェック済み）
        'health_condition' => 1,
        'appearance_clothing' => 1,
        'pre_drive_inspection' => 1,
        'license_documents' => 1,
        'vehicle_registration' => 1,
        'insurance_certificate' => 1,
        'emergency_equipment' => 1,
        'maps_navigation' => 1,
        'taxi_card' => 1,
        'emergency_signals' => 1,
        'change_money' => 1,
        'driver_id_card' => 1,
        'operation_record_sheets' => 1,
        'receipts' => 1,
        'stop_sign' => 1,
        
        // アルコールチェック
        'alcohol_check_value' => '0.000',
        
        // その他
        'remarks' => '',
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * データの妥当性をチェック
 * @param array $data チェック対象データ
 * @param string $data_type データタイプ ('daily_inspection', 'pre_duty_call')
 * @return array ['errors' => [], 'warnings' => []]
 */
function validateHistoricalData($data, $data_type) {
    $errors = [];
    $warnings = [];
    
    switch ($data_type) {
        case 'daily_inspection':
            // 必須項目チェック
            if (empty($data['vehicle_id'])) {
                $errors[] = '車両が選択されていません';
            }
            if (empty($data['inspector_id'])) {
                $errors[] = '点検者が選択されていません';
            }
            if (empty($data['inspection_date'])) {
                $errors[] = '点検日が設定されていません';
            }
            
            // 日付妥当性チェック
            if (!empty($data['inspection_date'])) {
                $inspection_date = new DateTime($data['inspection_date']);
                $today = new DateTime();
                
                if ($inspection_date > $today) {
                    $warnings[] = '未来の日付が設定されています: ' . $data['inspection_date'];
                }
                
                $one_year_ago = $today->sub(new DateInterval('P1Y'));
                if ($inspection_date < $one_year_ago) {
                    $warnings[] = '1年以上前の日付が設定されています: ' . $data['inspection_date'];
                }
            }
            break;
            
        case 'pre_duty_call':
            // 必須項目チェック
            if (empty($data['driver_id'])) {
                $errors[] = '運転者が選択されていません';
            }
            if (empty($data['vehicle_id'])) {
                $errors[] = '車両が選択されていません';
            }
            
            // アルコール値チェック
            if (isset($data['alcohol_check_value'])) {
                $alcohol_value = (float)$data['alcohol_check_value'];
                if ($alcohol_value > 0.15) {
                    $warnings[] = 'アルコール値が高めです: ' . $data['alcohol_check_value'] . ' mg/L';
                }
            }
            break;
    }
    
    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * 一括挿入用SQLを生成
 * @param string $table テーブル名
 * @param array $columns カラム名配列
 * @param int $record_count レコード数
 * @return string SQL文
 */
function generateBulkInsertSQL($table, $columns, $record_count) {
    $column_list = implode(', ', $columns);
    $placeholders = '(' . str_repeat('?,', count($columns) - 1) . '?)';
    $all_placeholders = str_repeat($placeholders . ',', $record_count - 1) . $placeholders;
    
    return "INSERT INTO {$table} ({$column_list}) VALUES {$all_placeholders}";
}

/**
 * エラーメッセージを整形
 * @param array $errors エラー配列
 * @param array $warnings 警告配列
 * @return string 整形されたメッセージ
 */
function formatValidationMessages($errors, $warnings) {
    $html = '';
    
    if (!empty($errors)) {
        $html .= '<div class="alert alert-danger"><strong>エラー:</strong><ul>';
        foreach ($errors as $error) {
            $html .= '<li>' . htmlspecialchars($error) . '</li>';
        }
        $html .= '</ul></div>';
    }
    
    if (!empty($warnings)) {
        $html .= '<div class="alert alert-warning"><strong>警告:</strong><ul>';
        foreach ($warnings as $warning) {
            $html .= '<li>' . htmlspecialchars($warning) . '</li>';
        }
        $html .= '</ul></div>';
    }
    
    return $html;
}

/**
 * 日付を日本語形式で表示
 * @param string $date 日付 (Y-m-d)
 * @return string 日本語形式の日付
 */
function formatDateJapanese($date) {
    $dt = new DateTime($date);
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $weekday = $weekdays[(int)$dt->format('w')];
    
    return $dt->format('m/d') . '(' . $weekday . ')';
}
?>
