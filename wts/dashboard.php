<?php
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
die("データベース接続エラー: " . $e->getMessage());
}

// 既存のテーブル構造確認
function checkTableStructure($pdo) {
    try {
        $stmt = $pdo->query("DESCRIBE users");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $columns;
    } catch(PDOException $e) {
        return [];
    }
}

// ユーザー情報取得（既存テーブル構造対応）
function getUserInfo($pdo, $user_id) {
    $columns = checkTableStructure($pdo);
    
    // 基本カラムの存在確認
    $select_columns = ['id', 'name'];
    
    // 新権限システムカラムの確認
    if (in_array('permission_level', $columns)) {
        $select_columns[] = 'permission_level';
    } elseif (in_array('role', $columns)) {
        $select_columns[] = 'role';
    }
    
    // 職務フラグの確認
    if (in_array('is_driver', $columns)) $select_columns[] = 'is_driver';
    if (in_array('is_caller', $columns)) $select_columns[] = 'is_caller';
    if (in_array('is_manager', $columns)) $select_columns[] = 'is_manager';
    
    $select_sql = implode(', ', $select_columns);
    
    // activeカラムの存在確認
    $where_clause = "id = ?";
    if (in_array('active', $columns)) {
        $where_clause .= " AND active = TRUE";
    }
    
    $stmt = $pdo->prepare("SELECT {$select_sql} FROM users WHERE {$where_clause}");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_OBJ);
// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 権限レベル取得（既存テーブル構造対応）
/**
 * セッションから権限レベルを取得
 */
function getUserPermissionLevel() {
    return $_SESSION['user_permission_level'] ?? 'User';
}
$user_name = $_SESSION['user_name'];

/**
 * 管理者権限チェック
 */
function isAdmin() {
    return getUserPermissionLevel() === 'Admin';
}
// ✅ 修正: permission_levelベースの権限管理に統一
$user_permission_level = $_SESSION['user_permission_level'] ?? 'User';

/**
 * 職務フラグチェック
 */
function hasJobFunction($job) {
    $session_key = "is_{$job}";
    return isset($_SESSION[$session_key]) && $_SESSION[$session_key] === true;
}
$today = date('Y-m-d');
$current_time = date('H:i');
$current_hour = date('H');

/**
 * ユーザー表示名取得（権限付き）
 */
function getUserDisplayName() {
    $name = $_SESSION['user_name'] ?? '不明';
    
    if (isAdmin()) {
        return $name . ' (システム管理者)';
    } else {
        // 職務に応じた表示
        $roles = [];
        if (hasJobFunction('driver')) $roles[] = '運転者';
        if (hasJobFunction('caller')) $roles[] = '点呼者';
        
        if (!empty($roles)) {
            return $name . ' (' . implode('・', $roles) . ')';
        } else {
            return $name . ' (ユーザー)';
        }
    }
}
// 当月の開始日
$current_month_start = date('Y-m-01');

// 職務フラグによるユーザー取得（既存テーブル構造対応）
function getUsersByJobFunction($pdo, $job_function) {
    $columns = checkTableStructure($pdo);
    
    // 基本的に全ユーザーを返す（既存システム互換）
    $where_conditions = [];
    
    if (is_array($job_function)) {
        foreach ($job_function as $job) {
            if (in_array("is_{$job}", $columns)) {
                $where_conditions[] = "is_{$job} = TRUE";
            }
        }
    } else {
        if (in_array("is_{$job_function}", $columns)) {
            $where_conditions[] = "is_{$job_function} = TRUE";
        }
    }
    
    // 職務フラグが存在しない場合は全ユーザーを返す
    if (empty($where_conditions)) {
        $where_clause = "1=1";
    } else {
        $where_clause = implode(' OR ', $where_conditions);
    }
    
    // activeカラムの存在確認
    if (in_array('active', $columns)) {
        $where_clause .= " AND active = TRUE";
    }
    
    $stmt = $pdo->prepare("
        SELECT id, name FROM users 
        WHERE {$where_clause}
        ORDER BY name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
// ★★★ ここにシステム名取得処理を追加 ★★★
$system_name = '福祉輸送管理システム'; // デフォルト値
// システム名を取得
$system_name = '福祉輸送管理システム';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name' LIMIT 1");
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
$stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['setting_value'])) {
    $result = $stmt->fetch();
    if ($result) {
$system_name = $result['setting_value'];
}
} catch (PDOException $e) {
    // データベースエラーの場合はデフォルト値を使用
    error_log("System name fetch error: " . $e->getMessage());
} catch (Exception $e) {
    // デフォルト値を使用
}

$user_info = getUserInfo($pdo, $_SESSION['user_id']);
$permission_level = getUserPermissionLevel($pdo, $_SESSION['user_id']);
// 業務漏れチェック機能（改善版）
$alerts = [];

if (!$user_info) {
    session_destroy();
    header('Location: index.php?error=invalid_user');
    exit;
}

// 今日の日付
$today = date('Y-m-d');

// 今日の業務状況取得（既存テーブル構造対応）
function getTodayStats($pdo, $today) {
    // テーブル存在確認
    function tableExists($pdo, $table_name) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table_name}'");
            return $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            return false;
        }
try {
    // 1. 乗務前点呼未実施で乗車記録がある運転者をチェック
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.driver_id, u.name as driver_name, COUNT(r.id) as ride_count
        FROM ride_records r
        JOIN users u ON r.driver_id = u.id
        LEFT JOIN pre_duty_calls pdc ON r.driver_id = pdc.driver_id AND r.ride_date = pdc.call_date AND pdc.is_completed = TRUE
        WHERE r.ride_date = ? AND pdc.id IS NULL
        GROUP BY r.driver_id, u.name
    ");
    $stmt->execute([$today]);
    $no_pre_duty_with_rides = $stmt->fetchAll();
    
    foreach ($no_pre_duty_with_rides as $driver) {
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-exclamation-triangle',
            'title' => '乗務前点呼未実施',
            'message' => "運転者「{$driver['driver_name']}」が乗務前点呼を行わずに乗車記録（{$driver['ride_count']}件）を登録しています。",
            'action' => 'pre_duty_call.php',
            'action_text' => '乗務前点呼を実施'
        ];
}

    $stats = [
        'active_vehicles' => 0,
        'ride_count' => 0,
        'total_sales' => 0,
        'cash_sales' => 0,
        'card_sales' => 0,
        'total_rides' => 0,
        'total_passengers' => 0,
        'not_returned_vehicles' => []
    ];

    // 分離型テーブルが存在する場合
    if (tableExists($pdo, 'departure_records') && tableExists($pdo, 'arrival_records')) {
        // 稼働車両数（今日出庫済み未入庫）
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT d.vehicle_id) as active_vehicles
            FROM departure_records d 
            LEFT JOIN arrival_records a ON d.id = a.departure_record_id
            WHERE d.departure_date = ? AND a.id IS NULL
        ");
        $stmt->execute([$today]);
        $stats['active_vehicles'] = $stmt->fetchColumn() ?: 0;
    // 2. 出庫処理または日常点検未実施で乗車記録がある車両をチェック
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.vehicle_id, v.vehicle_number, r.driver_id, u.name as driver_name, 
               COUNT(r.id) as ride_count,
               MAX(CASE WHEN dr.id IS NULL THEN 0 ELSE 1 END) as has_departure,
               MAX(CASE WHEN di.id IS NULL THEN 0 ELSE 1 END) as has_daily_inspection
        FROM ride_records r
        JOIN vehicles v ON r.vehicle_id = v.id
        JOIN users u ON r.driver_id = u.id
        LEFT JOIN departure_records dr ON r.vehicle_id = dr.vehicle_id AND r.ride_date = dr.departure_date AND r.driver_id = dr.driver_id
        LEFT JOIN daily_inspections di ON r.vehicle_id = di.vehicle_id AND r.ride_date = di.inspection_date AND r.driver_id = di.driver_id
        WHERE r.ride_date = ?
        GROUP BY r.vehicle_id, v.vehicle_number, r.driver_id, u.name
        HAVING has_departure = 0 OR has_daily_inspection = 0
    ");
    $stmt->execute([$today]);
    $incomplete_prep_with_rides = $stmt->fetchAll();
    
    foreach ($incomplete_prep_with_rides as $vehicle) {
        $missing_items = [];
        if (!$vehicle['has_departure']) $missing_items[] = '出庫処理';
        if (!$vehicle['has_daily_inspection']) $missing_items[] = '日常点検';
        
        $alerts[] = [
            'type' => 'danger',
            'priority' => 'critical',
            'icon' => 'fas fa-car-crash',
            'title' => '必須処理未実施',
            'message' => "運転者「{$vehicle['driver_name']}」が車両「{$vehicle['vehicle_number']}」で" . implode('・', $missing_items) . "を行わずに乗車記録（{$vehicle['ride_count']}件）を登録しています。",
            'action' => $vehicle['has_departure'] ? 'daily_inspection.php' : 'departure.php',
            'action_text' => $missing_items[0] . 'を実施'
        ];
    }

        // 未入庫車両リスト
    // 3. 18時以降で入庫・乗務後点呼未完了をチェック（営業時間終了後）
    if ($current_hour >= 18) {
        // 未入庫車両をチェック
$stmt = $pdo->prepare("
            SELECT d.vehicle_id, v.vehicle_number, u.name as driver_name, d.departure_time
            FROM departure_records d
            JOIN vehicles v ON d.vehicle_id = v.id
            JOIN users u ON d.driver_id = u.id
            LEFT JOIN arrival_records a ON d.id = a.departure_record_id
            WHERE d.departure_date = ? AND a.id IS NULL
            ORDER BY d.departure_time
            SELECT dr.vehicle_id, v.vehicle_number, u.name as driver_name, dr.departure_time
            FROM departure_records dr
            JOIN vehicles v ON dr.vehicle_id = v.id
            JOIN users u ON dr.driver_id = u.id
            LEFT JOIN arrival_records ar ON dr.vehicle_id = ar.vehicle_id AND dr.departure_date = ar.arrival_date
            WHERE dr.departure_date = ? AND ar.id IS NULL
       ");
$stmt->execute([$today]);
        $stats['not_returned_vehicles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // 従来の運行記録テーブルから取得
    elseif (tableExists($pdo, 'daily_operations')) {
        $not_arrived_vehicles = $stmt->fetchAll();
        
        foreach ($not_arrived_vehicles as $vehicle) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-clock',
                'title' => '入庫処理未完了',
                'message' => "車両「{$vehicle['vehicle_number']}」（運転者：{$vehicle['driver_name']}）が18時以降も入庫処理を完了していません。出庫時刻：{$vehicle['departure_time']}",
                'action' => 'arrival.php',
                'action_text' => '入庫処理を実施'
            ];
        }
        
        // 乗務後点呼未実施をチェック
$stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT vehicle_id) as active_vehicles
            FROM daily_operations 
            WHERE operation_date = ? AND return_time IS NULL
            SELECT DISTINCT dr.driver_id, u.name as driver_name
            FROM departure_records dr
            JOIN users u ON dr.driver_id = u.id
            LEFT JOIN post_duty_calls pdc ON dr.driver_id = pdc.driver_id AND dr.departure_date = pdc.call_date AND pdc.is_completed = TRUE
            WHERE dr.departure_date = ? AND pdc.id IS NULL
       ");
$stmt->execute([$today]);
        $stats['active_vehicles'] = $stmt->fetchColumn() ?: 0;
    }

    // 乗車記録から売上データ取得
    if (tableExists($pdo, 'ride_records')) {
        // ride_recordsテーブルの列構造確認
        $stmt = $pdo->query("DESCRIBE ride_records");
        $columns = array_column($stmt->fetchAll(), 'Field');
        
        // 日付カラムの確認
        $date_column = 'ride_date';
        if (!in_array('ride_date', $columns) && in_array('ride_time', $columns)) {
            $date_column = 'DATE(ride_time)';
        } elseif (!in_array('ride_date', $columns) && in_array('created_at', $columns)) {
            $date_column = 'DATE(created_at)';
        }

        // 料金カラムの確認
        $fare_column = 'fare_amount';
        if (!in_array('fare_amount', $columns) && in_array('fare', $columns)) {
            $fare_column = 'fare';
        } elseif (!in_array('fare_amount', $columns) && in_array('amount', $columns)) {
            $fare_column = 'amount';
        }

        // 支払方法カラムの確認
        $payment_column = 'payment_method';
        if (!in_array('payment_method', $columns) && in_array('payment_type', $columns)) {
            $payment_column = 'payment_type';
        }

        // 人数カラムの確認
        $passenger_column = 'passenger_count';
        if (!in_array('passenger_count', $columns) && in_array('passengers', $columns)) {
            $passenger_column = 'passengers';
        } elseif (!in_array('passenger_count', $columns)) {
            $passenger_column = '1'; // デフォルト値
        }

        $where_condition = "WHERE {$date_column} = ?";
        if (in_array('ride_date', $columns)) {
            $where_condition = "WHERE ride_date = ?";
        }

        try {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM({$fare_column}) as total_sales,
                    COUNT(*) as total_rides,
                    SUM({$passenger_column}) as total_passengers
                FROM ride_records 
                {$where_condition}
            ");
            $stmt->execute([$today]);
            $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $stats['total_sales'] = $sales_data['total_sales'] ?: 0;
            $stats['total_rides'] = $sales_data['total_rides'] ?: 0;
            $stats['total_passengers'] = $sales_data['total_passengers'] ?: 0;

            // 支払方法別集計（カラムが存在する場合のみ）
            if (in_array($payment_column, $columns)) {
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN {$payment_column} = '現金' THEN {$fare_column} ELSE 0 END) as cash_sales,
                        SUM(CASE WHEN {$payment_column} = 'カード' THEN {$fare_column} ELSE 0 END) as card_sales
                    FROM ride_records 
                    {$where_condition}
                ");
                $stmt->execute([$today]);
                $payment_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stats['cash_sales'] = $payment_data['cash_sales'] ?: 0;
                $stats['card_sales'] = $payment_data['card_sales'] ?: 0;
            }
        } catch(PDOException $e) {
            // エラーの場合はデフォルト値を使用
        $no_post_duty = $stmt->fetchAll();
        
        foreach ($no_post_duty as $driver) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'icon' => 'fas fa-user-clock',
                'title' => '乗務後点呼未実施',
                'message' => "運転者「{$driver['driver_name']}」が18時以降も乗務後点呼を完了していません。",
                'action' => 'post_duty_call.php',
                'action_text' => '乗務後点呼を実施'
            ];
}
}

    $stats['ride_count'] = $stats['total_rides'];
    return $stats;
}

// 月間実績取得（既存テーブル構造対応）
function getMonthlyStats($pdo) {
    // 今月の1日から今日まで（月初〜現在日）
    $month_start = date('Y-m-01');  // 今月の1日
    $today = date('Y-m-d');         // 今日
    // 4. 【改善】実施順序チェック - 日常点検完了後の乗務前点呼実施時のみ注意表示
    $stmt = $pdo->prepare("
        SELECT u.name as driver_name, v.vehicle_number, pdc.call_time, 
               TIME(di.created_at) as inspection_time
        FROM pre_duty_calls pdc
        JOIN users u ON pdc.driver_id = u.id
        JOIN vehicles v ON pdc.vehicle_id = v.id
        JOIN daily_inspections di ON pdc.vehicle_id = di.vehicle_id 
            AND pdc.call_date = di.inspection_date 
            AND pdc.driver_id = di.driver_id
        WHERE pdc.call_date = ? AND pdc.is_completed = TRUE
        AND di.id IS NOT NULL 
        AND TIME(di.created_at) > pdc.call_time
    ");
    $stmt->execute([$today]);
    $order_violations = $stmt->fetchAll();

    $stats = [
        'monthly_sales' => 0,
        'monthly_rides' => 0,
        'monthly_passengers' => 0,
        'monthly_distance' => 0,
        'avg_fare' => 0
    ];

    // ride_recordsテーブルから月間売上取得
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ride_records'");
        if ($stmt->rowCount() > 0) {
            // テーブル構造確認
            $stmt = $pdo->query("DESCRIBE ride_records");
            $columns = array_column($stmt->fetchAll(), 'Field');
            
            // 日付カラムの確認
            $date_condition = "ride_date BETWEEN ? AND ?";
            if (!in_array('ride_date', $columns) && in_array('ride_time', $columns)) {
                $date_condition = "DATE(ride_time) BETWEEN ? AND ?";
            } elseif (!in_array('ride_date', $columns) && in_array('created_at', $columns)) {
                $date_condition = "DATE(created_at) BETWEEN ? AND ?";
            }

            // 料金カラムの確認
            $fare_column = 'fare_amount';
            if (!in_array('fare_amount', $columns) && in_array('fare', $columns)) {
                $fare_column = 'fare';
            } elseif (!in_array('fare_amount', $columns) && in_array('amount', $columns)) {
                $fare_column = 'amount';
            }

            // 人数カラムの確認
            $passenger_column = 'passenger_count';
            if (!in_array('passenger_count', $columns) && in_array('passengers', $columns)) {
                $passenger_column = 'passengers';
            } elseif (!in_array('passenger_count', $columns)) {
                $passenger_column = '1';
            }

            $stmt = $pdo->prepare("
                SELECT 
                    SUM({$fare_column}) as monthly_sales,
                    COUNT(*) as monthly_rides,
                    SUM({$passenger_column}) as monthly_passengers,
                    AVG({$fare_column}) as avg_fare
                FROM ride_records 
                WHERE {$date_condition}
            ");
            $stmt->execute([$month_start, $today]);
            $monthly_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $stats['monthly_sales'] = $monthly_data['monthly_sales'] ?: 0;
            $stats['monthly_rides'] = $monthly_data['monthly_rides'] ?: 0;
            $stats['monthly_passengers'] = $monthly_data['monthly_passengers'] ?: 0;
            $stats['avg_fare'] = $monthly_data['avg_fare'] ?: 0;
        }
    } catch(PDOException $e) {
        // エラーの場合はデフォルト値を使用
    }

    // 月間走行距離取得
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'arrival_records'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT SUM(total_distance) as monthly_distance
                FROM arrival_records 
                WHERE arrival_date BETWEEN ? AND ?
            ");
            $stmt->execute([$month_start, $today]);
            $stats['monthly_distance'] = $stmt->fetchColumn() ?: 0;
        }
        // 従来テーブルからの取得
        else {
            $stmt = $pdo->query("SHOW TABLES LIKE 'daily_operations'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT SUM(total_distance) as monthly_distance
                    FROM daily_operations 
                    WHERE operation_date BETWEEN ? AND ?
                ");
                $stmt->execute([$month_start, $today]);
                $stats['monthly_distance'] = $stmt->fetchColumn() ?: 0;
            }
        }
    } catch(PDOException $e) {
        // エラーの場合はデフォルト値を使用
    foreach ($order_violations as $violation) {
        $alerts[] = [
            'type' => 'info',
            'priority' => 'low',
            'icon' => 'fas fa-info-circle',
            'title' => '実施順序について',
            'message' => "運転者「{$violation['driver_name']}」：日常点検（{$violation['inspection_time']}）が乗務前点呼（{$violation['call_time']}）より後に実施されています。法的推奨順序は日常点検→乗務前点呼です。",
            'action' => '',
            'action_text' => '次回から順序を確認'
        ];
}

    return $stats;
}

// アラート情報取得（既存テーブル構造対応）
function getAlerts($pdo) {
    $alerts = [];
    // 今日の統計データ
    // 今日の乗務前点呼完了数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pre_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_pre_duty_calls = $stmt->fetchColumn();

    try {
        // 点検期限アラート（vehiclesテーブルが存在する場合）
        $stmt = $pdo->query("SHOW TABLES LIKE 'vehicles'");
        if ($stmt->rowCount() > 0) {
            // vehiclesテーブルの構造確認
            $stmt = $pdo->query("DESCRIBE vehicles");
            $columns = array_column($stmt->fetchAll(), 'Field');
            
            if (in_array('next_inspection_date', $columns)) {
                $stmt = $pdo->prepare("
                    SELECT vehicle_number, next_inspection_date
                    FROM vehicles 
                    WHERE next_inspection_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    AND next_inspection_date >= CURDATE()
                ");
                $stmt->execute();
                $inspection_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if ($inspection_alerts) {
                    foreach ($inspection_alerts as $alert) {
                        $alerts[] = [
                            'type' => 'warning',
                            'message' => "車両 {$alert['vehicle_number']} の定期点検期限が近づいています ({$alert['next_inspection_date']})"
                        ];
                    }
                }
            }
        }

        // 未入庫車両アラート（18時以降）
        if (date('H') >= 18) {
            $today = date('Y-m-d');
            
            // departure_recordsテーブルが存在する場合
            $stmt = $pdo->query("SHOW TABLES LIKE 'departure_records'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count
                    FROM departure_records d
                    LEFT JOIN arrival_records a ON d.id = a.departure_record_id
                    WHERE d.departure_date = ? AND a.id IS NULL
                ");
                $stmt->execute([$today]);
                $not_returned_count = $stmt->fetchColumn();
            }
            // 従来のdaily_operationsテーブルの場合
            else {
                $stmt = $pdo->query("SHOW TABLES LIKE 'daily_operations'");
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count
                        FROM daily_operations 
                        WHERE operation_date = ? AND return_time IS NULL
                    ");
                    $stmt->execute([$today]);
                    $not_returned_count = $stmt->fetchColumn();
                } else {
                    $not_returned_count = 0;
                }
            }
            
            if ($not_returned_count > 0) {
                $alerts[] = [
                    'type' => 'danger',
                    'message' => "{$not_returned_count}台の車両が未入庫です"
                ];
            }
        }
    } catch(PDOException $e) {
        // エラーの場合は空の配列を返す
    }

    return $alerts;
    // 今日の乗務後点呼完了数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM post_duty_calls WHERE call_date = ? AND is_completed = TRUE");
    $stmt->execute([$today]);
    $today_post_duty_calls = $stmt->fetchColumn();
    
    // 今日の出庫記録数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departure_records WHERE departure_date = ?");
    $stmt->execute([$today]);
    $today_departures = $stmt->fetchColumn();
    
    // 今日の入庫記録数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = ?");
    $stmt->execute([$today]);
    $today_arrivals = $stmt->fetchColumn();
    
    // 今日の乗車記録数と売上
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    $today_ride_records = $result ? $result['count'] : 0;
    $today_total_revenue = $result ? $result['revenue'] : 0;

    // 【追加】当月の乗車記録数と売上
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(fare + charge), 0) as revenue FROM ride_records WHERE ride_date >= ?");
    $stmt->execute([$current_month_start]);
    $result = $stmt->fetch();
    $month_ride_records = $result ? $result['count'] : 0;
    $month_total_revenue = $result ? $result['revenue'] : 0;
    
    // 【追加】平均売上計算
    $days_in_month = date('j'); // 今月の経過日数
    $month_avg_revenue = $days_in_month > 0 ? round($month_total_revenue / $days_in_month) : 0;
    
} catch (Exception $e) {
    error_log("Dashboard alert error: " . $e->getMessage());
}

$today_stats = getTodayStats($pdo, $today);
$monthly_stats = getMonthlyStats($pdo);
$alerts = getAlerts($pdo);
// アラートを優先度でソート
usort($alerts, function($a, $b) {
    $priority_order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    return $priority_order[$a['priority']] - $priority_order[$b['priority']];
});
?>

<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード</title>
    <title>ダッシュボード - <?= htmlspecialchars($system_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
        .stat-card.sales {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        
        /* アラート専用スタイル */
        .alerts-section {
            margin-bottom: 2rem;
}
        .stat-card.rides {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        
        .alert-item {
            border-radius: 12px;
            border: none;
            margin-bottom: 1rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            animation: slideIn 0.5s ease-out;
}
        .stat-card.vehicles {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        
        .alert-critical {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-left: 5px solid #a71e2a;
        }
        
        .alert-high {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #212529;
            border-left: 5px solid #d39e00;
        }
        
        .alert-medium {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-left: 5px solid #0e6674;
}
        .menu-card {
        
        .alert-low {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            border-left: 5px solid #495057;
        }
        
        .alert-item .alert-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
        }
        
        .alert-title {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        
        .alert-message {
            margin-bottom: 1rem;
            line-height: 1.4;
        }
        
        .alert-action {
            text-align: right;
        }
        
        .alert-action .btn {
            font-weight: 600;
            border-radius: 20px;
            padding: 0.5rem 1.5rem;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* 統計カード */
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
border: none;
            transition: transform 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0;
        }
        
        /* 改善：クイックアクションボタン */
        .quick-action-group {
            background: white;
border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            height: 100%;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}
        .menu-card:hover {
            transform: translateY(-5px);
        
        .quick-action-btn {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1rem;
            text-decoration: none;
            color: #333;
            display: block;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
            min-height: 80px;
            position: relative;
            overflow: hidden;
}
        .alert-custom {
            border-radius: 10px;
            border: none;
        
        .quick-action-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.15);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        

        
        .quick-action-icon {
            font-size: 1.8rem;
            margin-right: 1rem;
        }
        
        .quick-action-content {
            display: flex;
            align-items: center;
        }
        
        .quick-action-text h6 {
            margin: 0;
            font-weight: 600;
        }
        
        .quick-action-text small {
            color: #6c757d;
            font-size: 0.75rem;
        }
        
        .text-purple { color: #6f42c1; }
        .text-orange { color: #fd7e14; }
        
        /* 売上表示の改善 */
        .revenue-card {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
            color: white;
        }
        
        .revenue-month-card {
            background: linear-gradient(135deg, var(--info-color) 0%, #138496 100%);
            color: white;
}
        .not-returned-vehicle {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
        
        @media (max-width: 768px) {
            .stats-number {
                font-size: 2rem;
            }
            .quick-action-btn {
                padding: 0.8rem;
                min-height: 70px;
            }
            .header h1 {
                font-size: 1.3rem;
            }
            .alert-item {
                padding: 1rem;
            }
            .alert-icon {
                font-size: 1.2rem !important;
            }
            .quick-action-icon {
                font-size: 1.5rem;
            }
}
</style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="#"><i class="fas fa-car me-2"></i><?= htmlspecialchars($system_name) ?></a>
        <div class="navbar-nav ms-auto">
            <span class="navbar-text me-3">
                <i class="fas fa-user me-1"></i><?= htmlspecialchars($user_info->name) ?>
                <span class="badge bg-<?= $permission_level === 'Admin' ? 'danger' : 'info' ?> ms-1">
                    <?= $permission_level === 'Admin' ? '管理者' : 'ユーザー' ?>
                </span>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sign-out-alt me-1"></i>ログアウト
            </a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <!-- アラート表示 -->
    <?php if (!empty($alerts)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <?php foreach ($alerts as $alert): ?>
                    <div class="alert alert-<?= $alert['type'] ?> alert-custom">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($alert['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- 今日の業務状況 -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-chart-line me-2"></i>今日の業務状況 (<?= date('Y年m月d日') ?>)</h2>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="stat-card sales">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3>¥<?= number_format($today_stats['total_sales']) ?></h3>
                        <p class="mb-0">今日の売上</p>
                        <small>現金: ¥<?= number_format($today_stats['cash_sales']) ?> | カード: ¥<?= number_format($today_stats['card_sales']) ?></small>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-taxi me-2"></i><?= htmlspecialchars($system_name) ?></h1>
                    <div class="user-info">
                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($user_name) ?> 
                        (<?= $user_permission_level === 'Admin' ? 'システム管理者' : 'ユーザー' ?>)
                        | <?= date('Y年n月j日 (D)', strtotime($today)) ?> <?= $current_time ?>
</div>
                    <i class="fas fa-yen-sign fa-2x opacity-75"></i>
</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card rides">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= $today_stats['total_rides'] ?>回</h3>
                        <p class="mb-0">乗車回数</p>
                        <small><?= $today_stats['total_passengers'] ?>名様を輸送</small>
                    </div>
                    <i class="fas fa-users fa-2x opacity-75"></i>
                <div class="col-auto">
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                    </a>
</div>
</div>
</div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card vehicles">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= $today_stats['active_vehicles'] ?>台</h3>
                        <p class="mb-0">稼働車両</p>
                        <small>現在運行中</small>
    </div>
    
    <div class="container mt-4">
        <!-- 業務漏れアラート（最優先表示） -->
        <?php if (!empty($alerts)): ?>
        <div class="alerts-section">
            <h4><i class="fas fa-exclamation-triangle me-2 text-danger"></i>重要なお知らせ・業務漏れ確認</h4>
            <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-item alert-<?= $alert['priority'] ?> <?= $alert['priority'] === 'critical' ? 'pulse' : '' ?>">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <i class="<?= $alert['icon'] ?> alert-icon"></i>
</div>
                    <i class="fas fa-car fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><?= count($today_stats['not_returned_vehicles']) ?>台</h3>
                        <p class="mb-0">未入庫車両</p>
                        <small>要確認</small>
                    <div class="col">
                        <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
                        <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
</div>
                    <i class="fas fa-exclamation-triangle fa-2x opacity-75"></i>
                    <?php if ($alert['action']): ?>
                    <div class="col-auto alert-action">
                        <a href="<?= $alert['action'] ?>" class="btn btn-light">
                            <i class="fas fa-arrow-right me-1"></i><?= htmlspecialchars($alert['action_text']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
</div>
</div>
            <?php endforeach; ?>
</div>
    </div>

    <!-- 未入庫車両詳細 -->
    <?php if (!empty($today_stats['not_returned_vehicles'])): ?>
        <?php endif; ?>
        
        <!-- 今日の業務状況と売上（改善版） -->
<div class="row mb-4">
<div class="col-12">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>未入庫車両一覧</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($today_stats['not_returned_vehicles'] as $vehicle): ?>
                            <div class="not-returned-vehicle">
                                <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong>
                                - 運転者: <?= htmlspecialchars($vehicle['driver_name']) ?>
                                - 出庫時刻: <?= htmlspecialchars($vehicle['departure_time']) ?>
                <div class="stats-card">
                    <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>業務状況</h5>
                    <div class="row text-center">
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-primary"><?= $today_departures ?></div>
                            <div class="stats-label">今日の出庫</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-success"><?= $today_ride_records ?></div>
                            <div class="stats-label">今日の乗車</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-<?= ($today_departures - $today_arrivals > 0) ? 'danger' : 'success' ?>">
                                <?= $today_departures - $today_arrivals ?>
</div>
                        <?php endforeach; ?>
                            <div class="stats-label">未入庫</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stats-number text-info"><?= $today_pre_duty_calls ?>/<?= $today_post_duty_calls ?></div>
                            <div class="stats-label">乗務前/後点呼</div>
                        </div>
</div>
</div>
</div>
</div>
    <?php endif; ?>

    <!-- 月間実績 -->
    <div class="row mb-4">
        <div class="col-12">
            <h3><i class="fas fa-calendar-alt me-2"></i>今月の実績 (<?= date('Y年m月1日') ?> 〜 <?= date('Y年m月d日') ?>)</h3>
            <small class="text-muted">※集計期間: 今月1日から今日まで</small>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-success">¥<?= number_format($monthly_stats['monthly_sales']) ?></h4>
                    <p class="card-text">月間売上</p>
        <!-- 売上情報（改善版：当日と当月を分離） -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card revenue-card">
                    <h6 class="mb-2"><i class="fas fa-yen-sign me-2"></i>今日の売上</h6>
                    <div class="stats-number">¥<?= number_format($today_total_revenue) ?></div>
                    <div class="stats-label" style="color: rgba(255,255,255,0.8);"><?= $today_ride_records ?>回の乗車</div>
</div>
</div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-primary"><?= $monthly_stats['monthly_rides'] ?>回</h4>
                    <p class="card-text">月間乗車回数</p>
            <div class="col-md-4">
                <div class="stats-card revenue-month-card">
                    <h6 class="mb-2"><i class="fas fa-calendar-alt me-2"></i>今月の売上</h6>
                    <div class="stats-number">¥<?= number_format($month_total_revenue) ?></div>
                    <div class="stats-label" style="color: rgba(255,255,255,0.8);"><?= $month_ride_records ?>回の乗車</div>
</div>
</div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-info"><?= number_format($monthly_stats['monthly_distance']) ?>km</h4>
                    <p class="card-text">月間走行距離</p>
            <div class="col-md-4">
                <div class="stats-card">
                    <h6 class="mb-2"><i class="fas fa-chart-bar me-2"></i>月平均</h6>
                    <div class="stats-number text-secondary">¥<?= number_format($month_avg_revenue) ?></div>
                    <div class="stats-label">1日あたり平均売上</div>
</div>
</div>
</div>
        <div class="col-md-3 col-sm-6">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-warning">¥<?= number_format($monthly_stats['avg_fare']) ?></h4>
                    <p class="card-text">平均売上</p>
                </div>
            </div>
        </div>
    </div>

    <!-- メニュー -->
    <div class="row mb-4">
        <div class="col-12">
            <h3><i class="fas fa-th-large me-2"></i>機能メニュー</h3>
        </div>
    </div>

    <?php if ($permission_level === 'Admin'): ?>
        <!-- 管理者メニュー：全機能 -->
        
        <!-- クイックアクション（改善版：段階的・優先度別） -->
<div class="row">
            <!-- 基本業務（フロー順） -->
            <div class="col-md-4 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-clipboard-list me-2"></i>基本業務（フロー順）</h5>
                    </div>
                    <div class="card-body">
                        <a href="daily_inspection.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-phone me-1"></i>1. 日常点検
                        </a>
                        <a href="pre_duty_call.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-tools me-1"></i>2. 乗務前点呼
                        </a>
                        <a href="departure.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-out-alt me-1"></i>3. 出庫処理
                        </a>
                        <a href="ride_records.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-users me-1"></i>4. 乗車記録
                        </a>
                        <a href="arrival.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-in-alt me-1"></i>5. 入庫処理
                        </a>
                        <a href="post_duty_call.php" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-phone-alt me-1"></i>6. 乗務後点呼
                        </a>
                    </div>
                </div>
            </div>

            <!-- 管理業務 -->
            <div class="col-md-4 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-chart-bar me-2"></i>管理業務</h5>
                    </div>
                    <div class="card-body">
                        <a href="cash_management.php" class="btn btn-outline-success btn-sm mb-2 w-100">
                            <i class="fas fa-coins me-1"></i>集金管理
                        </a>
                        <a href="periodic_inspection.php" class="btn btn-outline-success btn-sm mb-2 w-100">
                            <i class="fas fa-cog me-1"></i>定期点検（3ヶ月）
                        </a>
                        <a href="annual_report.php" class="btn btn-outline-success btn-sm mb-2 w-100">
                            <i class="fas fa-file-alt me-1"></i>陸運局提出
                        </a>
                        <a href="accident_management.php" class="btn btn-outline-success btn-sm mb-2 w-100">
                            <i class="fas fa-exclamation-circle me-1"></i>事故管理
                        </a>
                        <a href="#" class="btn btn-outline-success btn-sm w-100" onclick="showStats()">
                            <i class="fas fa-chart-line me-1"></i>売上統計
                        </a>
                    </div>
            <!-- 運転者向け：1日の流れに沿った業務 -->
            <div class="col-lg-6">
                <div class="quick-action-group">
                    <h5><i class="fas fa-route me-2"></i>運転業務（1日の流れ）</h5>
                    
                    <a href="daily_inspection.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-secondary">
                                <i class="fas fa-tools"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>1. 日常点検</h6>
                                <small>最初に実施（法定義務）</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="pre_duty_call.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-warning">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>2. 乗務前点呼</h6>
                                <small>日常点検後に実施</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="departure.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-primary">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>3. 出庫処理</h6>
                                <small>点呼・点検完了後</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="ride_records.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-success">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>4. 乗車記録</h6>
                                <small>営業中随時入力</small>
                            </div>
                        </div>
                    </a>
</div>
</div>

            <!-- システム管理 -->
            <div class="col-md-4 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-danger text-white">
                        <h5><i class="fas fa-cogs me-2"></i>システム管理</h5>
                    </div>
                    <div class="card-body">
                        <a href="user_management.php" class="btn btn-outline-danger btn-sm mb-2 w-100">
                            <i class="fas fa-users-cog me-1"></i>ユーザー管理
                        </a>
                        <a href="vehicle_management.php" class="btn btn-outline-danger btn-sm mb-2 w-100">
                            <i class="fas fa-car me-1"></i>車両管理
                        </a>
                        <a href="emergency_audit_kit.php" class="btn btn-outline-danger btn-sm w-100">
                            <i class="fas fa-shield-alt me-1"></i>緊急監査対応
                        </a>
                    </div>
            <!-- 1日の終了業務と管理業務 -->
            <div class="col-lg-6">
                <div class="quick-action-group">
                    <h5><i class="fas fa-moon me-2"></i>終業・管理業務</h5>
                    
                    <a href="arrival.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-info">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>入庫処理</h6>
                                <small>営業終了時に実施</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="post_duty_call.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-danger">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>乗務後点呼</h6>
                                <small>入庫後に実施</small>
                            </div>
                        </div>
                    </a>
                    
                    <a href="periodic_inspection.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-purple">
                                <i class="fas fa-wrench"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>定期点検</h6>
                                <small>3ヶ月ごと</small>
                            </div>
                        </div>
                    </a>

                    <?php if ($user_permission_level === 'Admin'): ?>
                    <a href="master_menu.php" class="quick-action-btn">
                        <div class="quick-action-content">
                            <div class="quick-action-icon text-orange">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <div class="quick-action-text">
                                <h6>マスタ管理</h6>
                                <small>システム設定</small>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>
</div>
</div>
</div>

    <?php else: ?>
        <!-- 一般ユーザーメニュー：基本業務のみ -->
        <div class="row">
            <!-- 基本業務（フロー順） -->
            <div class="col-md-6 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-clipboard-list me-2"></i>基本業務（フロー順）</h5>
                    </div>
                    <div class="card-body">
                        <a href="daily_inspection.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-phone me-1"></i>1. 日常点検
                        </a>
                        <a href="pre_duty_call.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-tools me-1"></i>2. 乗務前点呼
                        </a>
                        <a href="departure.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-out-alt me-1"></i>3. 出庫処理
                        </a>
                        <a href="ride_records.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-users me-1"></i>4. 乗車記録
                        </a>
                        <a href="arrival.php" class="btn btn-outline-primary btn-sm mb-2 w-100">
                            <i class="fas fa-sign-in-alt me-1"></i>5. 入庫処理
                        </a>
                        <a href="post_duty_call.php" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-phone-alt me-1"></i>6. 乗務後点呼
                        </a>
                    </div>
                </div>
            </div>

            <!-- 実績・統計 -->
            <div class="col-md-6 mb-3">
                <div class="card menu-card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-chart-bar me-2"></i>実績・統計</h5>
                    </div>
                    <div class="card-body">
                        <a href="#" class="btn btn-outline-info btn-sm mb-2 w-100" onclick="showStats()">
                            <i class="fas fa-chart-line me-1"></i>売上統計
                        </a>
                        <a href="#" class="btn btn-outline-info btn-sm mb-2 w-100" onclick="showOperationStats()">
                            <i class="fas fa-road me-1"></i>運行実績
                        </a>
                        <a href="#" class="btn btn-outline-info btn-sm w-100" onclick="showReports()">
                            <i class="fas fa-file-alt me-1"></i>各種レポート
                        </a>
        <!-- 今日の業務進捗ガイド -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="stats-card">
                    <h5 class="mb-3"><i class="fas fa-tasks me-2"></i>今日の業務進捗ガイド</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="progress-guide">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_departures > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-tools"></i>
                                            <small>点検・点呼</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_departures > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-sign-out-alt"></i>
                                            <small>出庫</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_ride_records > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-users"></i>
                                            <small>営業</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="progress-step <?= $today_arrivals > 0 ? 'completed' : 'pending' ?>">
                                            <i class="fas fa-sign-in-alt"></i>
                                            <small>終業</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="next-action">
                                <?php if ($today_departures == 0): ?>
                                    <h6 class="text-primary">次の作業</h6>
                                    <p class="mb-1"><strong>日常点検</strong> を実施してください</p>
                                    <small class="text-muted">その後、乗務前点呼→出庫の順番です</small>
                                <?php elseif ($today_arrivals == 0): ?>
                                    <h6 class="text-success">営業中</h6>
                                    <p class="mb-1">お疲れ様です！</p>
                                    <small class="text-muted">乗車記録の入力をお忘れなく</small>
                                <?php elseif ($today_post_duty_calls == 0): ?>
                                    <h6 class="text-warning">終業処理</h6>
                                    <p class="mb-1"><strong>乗務後点呼</strong> を実施してください</p>
                                    <small class="text-muted">本日の業務完了まであと少しです</small>
                                <?php else: ?>
                                    <h6 class="text-success">業務完了</h6>
                                    <p class="mb-1">本日もお疲れ様でした！</p>
                                    <small class="text-muted">明日もよろしくお願いします</small>
                                <?php endif; ?>
                            </div>
                        </div>
</div>
</div>
</div>
</div>
    <?php endif; ?>

</div>

<!-- 統計表示モーダル -->
<div class="modal fade" id="statsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">売上統計</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="statsModalBody">
                <!-- 統計データが動的に読み込まれます -->
            </div>
        </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showStats() {
    // 売上統計表示
    document.getElementById('statsModalBody').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <canvas id="salesChart"></canvas>
            </div>
            <div class="col-md-6">
                <h6>今日の詳細</h6>
                <p>総売上: ¥${<?= $today_stats['total_sales'] ?>}</p>
                <p>現金: ¥${<?= $today_stats['cash_sales'] ?>}</p>
                <p>カード: ¥${<?= $today_stats['card_sales'] ?>}</p>
                <p>乗車回数: ${<?= $today_stats['total_rides'] ?>}回</p>
                <p>乗客数: ${<?= $today_stats['total_passengers'] ?>}名</p>
            </div>
        </div>
    `;
    new bootstrap.Modal(document.getElementById('statsModal')).show();
}

function showOperationStats() {
    alert('運行実績画面を表示します（実装予定）');
}

function showReports() {
    alert('各種レポート画面を表示します（実装予定）');
}

// リアルタイム更新（5分ごと）
setInterval(function() {
    location.reload();
}, 300000);
</script>
    <style>
        .progress-guide {
            padding: 1rem 0;
        }
        
        .progress-step {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .progress-step.completed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .progress-step.pending {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px dashed #dee2e6;
        }
        
        .progress-step i {
            font-size: 1.5rem;
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .progress-step small {
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .next-action {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1.5rem;
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
        }
        
        .next-action h6 {
            margin-bottom: 1rem;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 5分ごとにページを自動更新してアラートを更新
        setInterval(function() {
            window.location.reload();
        }, 300000); // 5分 = 300000ms

        // アラートが存在する場合、ブラウザ通知を表示（ユーザーの許可が必要）
        <?php if (!empty($alerts) && in_array('critical', array_column($alerts, 'priority'))): ?>
        if (Notification.permission === "granted") {
            new Notification("重要な業務漏れがあります", {
                body: "<?= isset($alerts[0]) ? htmlspecialchars($alerts[0]['message']) : '' ?>",
                icon: "/favicon.ico"
            });
        } else if (Notification.permission !== "denied") {
            Notification.requestPermission().then(function (permission) {
                if (permission === "granted") {
                    new Notification("重要な業務漏れがあります", {
                        body: "<?= isset($alerts[0]) ? htmlspecialchars($alerts[0]['message']) : '' ?>",
                        icon: "/favicon.ico"
                    });
                }
            });
        }
        <?php endif; ?>

        // 業務進捗の可視化アニメーション
        document.addEventListener('DOMContentLoaded', function() {
            const steps = document.querySelectorAll('.progress-step');
            steps.forEach((step, index) => {
                setTimeout(() => {
                    step.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        step.style.transform = 'scale(1)';
                    }, 200);
                }, index * 100);
            });
        });
    </script>
</body>
</html>
