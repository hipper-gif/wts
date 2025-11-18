<?php
// =================================================================
// タイムツリー移行API
// 
// ファイル: /Smiley/taxi/wts/calendar/api/migrate_timetree.php
// 機能: タイムツリーデータ→予約システム移行・バッチ処理
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

// 基盤システム読み込み
require_once '../../config/database.php';
require_once '../includes/calendar_functions.php';

// 認証チェック（管理者のみ）
if (!isset($_SESSION['user_id']) || false) {
    sendErrorResponse('管理者権限が必要です', 403);
}

// データベース接続
$pdo = getDBConnection();

// アクション判定
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'upload':
        handleFileUpload();
        break;
    case 'preview':
        handleDataPreview();
        break;
    case 'execute':
        handleMigrationExecution();
        break;
    case 'status':
        handleMigrationStatus();
        break;
    default:
        sendErrorResponse('無効なアクションです');
}

// =================================================================
// ファイルアップロード処理
// =================================================================

function handleFileUpload() {
    if (!isset($_FILES['timetree_file'])) {
        sendErrorResponse('ファイルが選択されていません');
    }
    
    $file = $_FILES['timetree_file'];
    
    // ファイル検証
    if ($file['error'] !== UPLOAD_ERR_OK) {
        sendErrorResponse('ファイルアップロードに失敗しました');
    }
    
    if ($file['size'] > 10 * 1024 * 1024) { // 10MB制限
        sendErrorResponse('ファイルサイズが大きすぎます（10MB以下）');
    }
    
    $allowedExtensions = ['csv', 'json', 'xlsx'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowedExtensions)) {
        sendErrorResponse('対応していないファイル形式です（CSV, JSON, XLSX）');
    }
    
    // 一時保存
    $uploadDir = '../../temp/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $tempFilename = 'timetree_' . date('YmdHis') . '_' . uniqid() . '.' . $extension;
    $tempFilePath = $uploadDir . $tempFilename;
    
    if (!move_uploaded_file($file['tmp_name'], $tempFilePath)) {
        sendErrorResponse('ファイルの保存に失敗しました');
    }
    
    // セッションにファイル情報を保存
    $_SESSION['timetree_upload'] = [
        'filename' => $tempFilename,
        'filepath' => $tempFilePath,
        'original_name' => $file['name'],
        'size' => $file['size'],
        'uploaded_at' => time()
    ];
    
    sendSuccessResponse([
        'filename' => $tempFilename,
        'original_name' => $file['name'],
        'size' => formatFileSize($file['size'])
    ], 'ファイルアップロードが完了しました');
}

// =================================================================
// データプレビュー処理
// =================================================================

function handleDataPreview() {
    if (!isset($_SESSION['timetree_upload'])) {
        sendErrorResponse('アップロードファイルが見つかりません');
    }
    
    $fileInfo = $_SESSION['timetree_upload'];
    $filePath = $fileInfo['filepath'];
    
    if (!file_exists($filePath)) {
        sendErrorResponse('ファイルが存在しません');
    }
    
    try {
        // ファイル形式に応じて読み込み
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $rawData = readTimeTreeFile($filePath, $extension);
        
        // データ変換
        $convertedData = convertTimeTreeData($rawData);
        
        // 変換結果の検証
        $validationResults = validateConvertedData($convertedData);
        
        // プレビュー用サンプル（最初の10件）
        $sampleData = array_slice($convertedData, 0, 10);
        
        sendSuccessResponse([
            'total_records' => count($convertedData),
            'valid_records' => $validationResults['valid_count'],
            'invalid_records' => $validationResults['invalid_count'],
            'sample_data' => $sampleData,
            'validation_errors' => $validationResults['errors'],
            'statistics' => generateDataStatistics($convertedData)
        ]);
        
    } catch (Exception $e) {
        error_log("タイムツリーデータプレビューエラー: " . $e->getMessage());
        sendErrorResponse('データ解析中にエラーが発生しました: ' . $e->getMessage());
    }
}

// =================================================================
// 移行実行処理
// =================================================================

function handleMigrationExecution() {
    global $pdo;
    
    if (!isset($_SESSION['timetree_upload'])) {
        sendErrorResponse('アップロードファイルが見つかりません');
    }
    
    $fileInfo = $_SESSION['timetree_upload'];
    $filePath = $fileInfo['filepath'];
    
    if (!file_exists($filePath)) {
        sendErrorResponse('ファイルが存在しません');
    }
    
    try {
        // データ読み込み・変換
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $rawData = readTimeTreeFile($filePath, $extension);
        $convertedData = convertTimeTreeData($rawData);
        
        // 移行実行
        $migrationResult = executeMigration($convertedData, $pdo);
        
        // 移行ログ記録
        logMigrationResult($migrationResult);
        
        // 一時ファイル削除
        unlink($filePath);
        unset($_SESSION['timetree_upload']);
        
        sendSuccessResponse($migrationResult, '移行が完了しました');
        
    } catch (Exception $e) {
        error_log("タイムツリー移行実行エラー: " . $e->getMessage());
        sendErrorResponse('移行中にエラーが発生しました: ' . $e->getMessage());
    }
}

// =================================================================
// 移行状況確認処理
// =================================================================

function handleMigrationStatus() {
    global $pdo;
    
    try {
        // 最新の移行状況を取得
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_reservations,
                COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_imported,
                MAX(created_at) as last_import_time
            FROM reservations 
            WHERE created_by = {$_SESSION['user_id']}
        ");
        $stats = $stmt->fetch();
        
        // 移行履歴取得
        $stmt = $pdo->prepare("
            SELECT * FROM calendar_audit_logs 
            WHERE user_id = ? AND action = 'migrate' 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $history = $stmt->fetchAll();
        
        sendSuccessResponse([
            'statistics' => $stats,
            'migration_history' => $history,
            'system_status' => 'operational'
        ]);
        
    } catch (Exception $e) {
        error_log("移行状況確認エラー: " . $e->getMessage());
        sendErrorResponse('状況確認中にエラーが発生しました');
    }
}

// =================================================================
// データ読み込み関数
// =================================================================

function readTimeTreeFile($filePath, $extension) {
    switch ($extension) {
        case 'csv':
            return readCSVFile($filePath);
        case 'json':
            return readJSONFile($filePath);
        case 'xlsx':
            return readExcelFile($filePath);
        default:
            throw new Exception('未対応のファイル形式です');
    }
}

function readCSVFile($filePath) {
    $data = [];
    $handle = fopen($filePath, 'r');
    
    if (!$handle) {
        throw new Exception('CSVファイルを開けません');
    }
    
    // ヘッダー行読み込み
    $headers = fgetcsv($handle);
    if (!$headers) {
        throw new Exception('CSVヘッダーが読み込めません');
    }
    
    // データ行読み込み
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($headers)) {
            $data[] = array_combine($headers, $row);
        }
    }
    
    fclose($handle);
    return $data;
}

function readJSONFile($filePath) {
    $content = file_get_contents($filePath);
    if (!$content) {
        throw new Exception('JSONファイルを読み込めません');
    }
    
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON形式が無効です: ' . json_last_error_msg());
    }
    
    return $data;
}

function readExcelFile($filePath) {
    // PHPSpreadsheet使用（実装簡略化）
    // 実際の実装では適切なライブラリを使用
    throw new Exception('Excel形式は現在サポートしていません。CSVで出力してください。');
}

// =================================================================
// データ変換関数
// =================================================================

function convertTimeTreeData($rawData) {
    $convertedData = [];
    
    foreach ($rawData as $item) {
        try {
            $converted = convertSingleTimeTreeItem($item);
            if ($converted) {
                $convertedData[] = $converted;
            }
        } catch (Exception $e) {
            error_log("データ変換エラー: " . $e->getMessage() . " データ: " . json_encode($item));
        }
    }
    
    return $convertedData;
}

function convertSingleTimeTreeItem($item) {
    // タイムツリーデータ形式の解析
    $title = $item['title'] ?? $item['タイトル'] ?? '';
    $datetime = $item['datetime'] ?? $item['日時'] ?? $item['start'] ?? '';
    $location = $item['location'] ?? $item['場所'] ?? '';
    $memo = $item['memo'] ?? $item['メモ'] ?? $item['note'] ?? '';
    $color = $item['color'] ?? $item['色'] ?? '';
    
    if (!$title || !$datetime) {
        return null; // 必須項目不足
    }
    
    // タイトル解析 "利用者名 出発地→目的地"
    $titlePattern = '/^(.+?)\s+(.+?)→(.+?)$/u';
    preg_match($titlePattern, $title, $matches);
    
    if (count($matches) < 4) {
        // パターンが合わない場合は基本情報のみ設定
        $client_name = $title;
        $pickup_location = $location ?: '不明';
        $dropoff_location = '不明';
    } else {
        $client_name = trim($matches[1]);
        $pickup_location = trim($matches[2]);
        $dropoff_location = trim($matches[3]);
    }
    
    // 日時変換
    $datetime_obj = new DateTime($datetime);
    $reservation_date = $datetime_obj->format('Y-m-d');
    $reservation_time = $datetime_obj->format('H:i:s');
    
    // メモ解析
    $parsed_memo = parseMemoField($memo);
    
    // 運転者判定（色ラベルから）
    $driver_id = getDriverByColor($color);
    
    // 変換されたデータ
    return [
        'reservation_date' => $reservation_date,
        'reservation_time' => $reservation_time,
        'client_name' => $client_name,
        'pickup_location' => $pickup_location,
        'dropoff_location' => $dropoff_location,
        'passenger_count' => 1, // デフォルト
        'driver_id' => $driver_id,
        'vehicle_id' => null, // 後で設定
        'service_type' => determineServiceType($pickup_location, $dropoff_location),
        'is_time_critical' => 1, // デフォルト
        'rental_service' => $parsed_memo['rental_service'],
        'entrance_assistance' => $parsed_memo['entrance_assistance'],
        'disability_card' => 0, // デフォルト
        'care_service_user' => 0, // デフォルト
        'hospital_escort_staff' => '',
        'dual_assistance_staff' => '',
        'referrer_type' => '未確認', // 移行データは未確認
        'referrer_name' => '移行データ',
        'referrer_contact' => $parsed_memo['contact'],
        'is_return_trip' => 0, // 移行時は往路として扱う
        'parent_reservation_id' => null,
        'return_hours_later' => null,
        'estimated_fare' => 0, // 移行時は未設定
        'actual_fare' => null,
        'payment_method' => '現金', // デフォルト
        'status' => '予約', // デフォルト
        'ride_record_id' => null,
        'special_notes' => $memo,
        'original_data' => json_encode($item), // 元データ保持
        'migration_source' => 'timetree'
    ];
}

function parseMemoField($memo) {
    $result = [
        'rental_service' => 'なし',
        'entrance_assistance' => 0,
        'contact' => ''
    ];
    
    // 連絡先抽出
    if (preg_match('/(?:連絡先[：:]|TEL[：:]?|電話[：:]?)([0-9\-\(\)\s]+)/u', $memo, $matches)) {
        $result['contact'] = trim($matches[1]);
    }
    
    // レンタルサービス判定
    if (preg_match('/(車いす|車椅子|wheelchair)/ui', $memo)) {
        $result['rental_service'] = '車いす';
    } elseif (preg_match('/(リクライニング|reclining)/ui', $memo)) {
        $result['rental_service'] = 'リクライニング';
    } elseif (preg_match('/(ストレッチャー|stretcher|担架)/ui', $memo)) {
        $result['rental_service'] = 'ストレッチャー';
    }
    
    // 玄関まで送迎判定
    if (preg_match('/(玄関まで|玄関送迎|door.?to.?door)/ui', $memo)) {
        $result['entrance_assistance'] = 1;
    }
    
    return $result;
}

function determineServiceType($pickup, $dropoff) {
    // 簡易的なサービス種別判定
    $hospital_keywords = ['病院', '医院', 'クリニック', '診療所', '医療センター'];
    $facility_keywords = ['施設', 'ホーム', 'ケア', 'デイサービス'];
    
    $pickup_is_hospital = containsKeywords($pickup, $hospital_keywords);
    $dropoff_is_hospital = containsKeywords($dropoff, $hospital_keywords);
    $pickup_is_facility = containsKeywords($pickup, $facility_keywords);
    $dropoff_is_facility = containsKeywords($dropoff, $facility_keywords);
    
    if (!$pickup_is_hospital && $dropoff_is_hospital) {
        return 'お迎え'; // 自宅等→病院
    } elseif ($pickup_is_hospital && !$dropoff_is_hospital) {
        return 'お送り'; // 病院→自宅等
    } elseif ($pickup_is_facility && !$dropoff_is_facility) {
        return '退院'; // 施設→自宅等
    } elseif (!$pickup_is_facility && $dropoff_is_facility) {
        return '入院'; // 自宅等→施設
    } else {
        return 'その他';
    }
}

function containsKeywords($text, $keywords) {
    foreach ($keywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function getDriverByColor($color) {
    // 色ラベルから運転者ID判定（簡易版）
    $color_mapping = [
        '#FF5722' => 1, // 赤 -> 田中
        '#2196F3' => 2, // 青 -> 鈴木
        '#4CAF50' => 3, // 緑 -> 山田
        '#FF9800' => 1, // オレンジ -> 田中
        '#9C27B0' => 2  // 紫 -> 鈴木
    ];
    
    return $color_mapping[$color] ?? null;
}

// =================================================================
// データ検証関数
// =================================================================

function validateConvertedData($data) {
    $valid_count = 0;
    $invalid_count = 0;
    $errors = [];
    
    foreach ($data as $index => $item) {
        $item_errors = validateSingleItem($item, $index);
        
        if (empty($item_errors)) {
            $valid_count++;
        } else {
            $invalid_count++;
            $errors = array_merge($errors, $item_errors);
        }
    }
    
    return [
        'valid_count' => $valid_count,
        'invalid_count' => $invalid_count,
        'errors' => $errors
    ];
}

function validateSingleItem($item, $index) {
    $errors = [];
    
    // 必須項目チェック
    if (empty($item['client_name'])) {
        $errors[] = "行{$index}: 利用者名が不足";
    }
    
    if (empty($item['reservation_date'])) {
        $errors[] = "行{$index}: 予約日が不足";
    }
    
    if (empty($item['reservation_time'])) {
        $errors[] = "行{$index}: 予約時刻が不足";
    }
    
    // 日付形式チェック
    if (!empty($item['reservation_date']) && !strtotime($item['reservation_date'])) {
        $errors[] = "行{$index}: 無効な日付形式";
    }
    
    // 時刻形式チェック
    if (!empty($item['reservation_time']) && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $item['reservation_time'])) {
        $errors[] = "行{$index}: 無効な時刻形式";
    }
    
    return $errors;
}

// =================================================================
// 移行実行関数
// =================================================================

function executeMigration($data, $pdo) {
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    $pdo->beginTransaction();
    
    try {
        foreach ($data as $index => $item) {
            try {
                // 重複チェック
                if (!isDuplicateReservation($item, $pdo)) {
                    insertReservation($item, $pdo);
                    $success_count++;
                } else {
                    $errors[] = "行{$index}: 重複する予約（{$item['client_name']}様 {$item['reservation_date']} {$item['reservation_time']}）";
                    $error_count++;
                }
            } catch (Exception $e) {
                $errors[] = "行{$index}: " . $e->getMessage();
                $error_count++;
            }
        }
        
        $pdo->commit();
        
        return [
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors,
            'total_processed' => count($data)
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function isDuplicateReservation($item, $pdo) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations 
        WHERE client_name = ? 
            AND reservation_date = ? 
            AND reservation_time = ?
    ");
    $stmt->execute([
        $item['client_name'],
        $item['reservation_date'],
        $item['reservation_time']
    ]);
    
    return $stmt->fetchColumn() > 0;
}

function insertReservation($item, $pdo) {
    $item['created_by'] = $_SESSION['user_id'];
    
    $fields = array_keys($item);
    $placeholders = str_repeat('?,', count($fields) - 1) . '?';
    
    $sql = "INSERT INTO reservations (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($item));
}

// =================================================================
// 統計・ログ関数
// =================================================================

function generateDataStatistics($data) {
    $stats = [
        'total_count' => count($data),
        'service_types' => [],
        'rental_services' => [],
        'date_range' => ['start' => null, 'end' => null]
    ];
    
    foreach ($data as $item) {
        // サービス種別統計
        $service = $item['service_type'];
        $stats['service_types'][$service] = ($stats['service_types'][$service] ?? 0) + 1;
        
        // レンタルサービス統計
        $rental = $item['rental_service'];
        $stats['rental_services'][$rental] = ($stats['rental_services'][$rental] ?? 0) + 1;
        
        // 日付範囲
        $date = $item['reservation_date'];
        if (!$stats['date_range']['start'] || $date < $stats['date_range']['start']) {
            $stats['date_range']['start'] = $date;
        }
        if (!$stats['date_range']['end'] || $date > $stats['date_range']['end']) {
            $stats['date_range']['end'] = $date;
        }
    }
    
    return $stats;
}

function logMigrationResult($result) {
    global $pdo;
    
    try {
        $log_data = [
            'migration_type' => 'timetree_import',
            'success_count' => $result['success_count'],
            'error_count' => $result['error_count'],
            'total_processed' => $result['total_processed'],
            'errors' => $result['errors']
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO calendar_audit_logs 
            (user_id, user_type, action, target_type, target_id, new_data, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_SESSION['user_id'],
            'admin',
            'migrate',
            'reservation',
            null,
            json_encode($log_data, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
    } catch (Exception $e) {
        error_log("移行ログ記録エラー: " . $e->getMessage());
    }
}

// =================================================================
// ユーティリティ関数
// =================================================================

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}
?>
