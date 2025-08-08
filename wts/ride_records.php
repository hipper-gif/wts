<?php
session_start();

// データベース接続
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// ユーザーの権限レベルを取得（新権限システム）
$user_permission_sql = "SELECT permission_level FROM users WHERE id = ?";
$user_permission_stmt = $pdo->prepare($user_permission_sql);
$user_permission_stmt->execute([$user_id]);
$user_permission = $user_permission_stmt->fetchColumn() ?: 'User';

// ログインユーザーが運転者かどうかを確認（職務フラグのみ使用）
$user_is_driver_sql = "SELECT is_driver FROM users WHERE id = ?";
$user_is_driver_stmt = $pdo->prepare($user_is_driver_sql);
$user_is_driver_stmt->execute([$user_id]);
$user_info = $user_is_driver_stmt->fetch(PDO::FETCH_ASSOC);
$user_is_driver = ($user_info['is_driver'] == 1);

// 今日の日付
$today = date('Y-m-d');
$current_time = date('H:i');

// ✅ デフォルト車両の決定ロジック
$default_vehicle_id = null;
$default_driver_id = null;
$default_vehicle_info = null;

// 1. ログインユーザーが運転者の場合、そのユーザーをデフォルト
if ($user_is_driver) {
    $default_driver_id = $user_id;
    
    // 2. 今日の出庫記録から車両を特定
    $today_departure_sql = "
        SELECT dr.vehicle_id, v.vehicle_number, v.vehicle_name 
        FROM departure_records dr
        JOIN vehicles v ON dr.vehicle_id = v.id
        WHERE dr.driver_id = ? AND dr.departure_date = ?
        ORDER BY dr.departure_time DESC 
        LIMIT 1
    ";
    $today_departure_stmt = $pdo->prepare($today_departure_sql);
    $today_departure_stmt->execute([$user_id, $today]);
    $today_departure = $today_departure_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($today_departure) {
        $default_vehicle_id = $today_departure['vehicle_id'];
        $default_vehicle_info = $today_departure;
    }
}

// 3. 出庫記録がない場合、最近使った車両を取得
if (!$default_vehicle_id && $user_is_driver) {
    $recent_vehicle_sql = "
        SELECT rr.vehicle_id, v.vehicle_number, v.vehicle_name
        FROM ride_records rr
        JOIN vehicles v ON rr.vehicle_id = v.id
        WHERE rr.driver_id = ? 
        ORDER BY rr.ride_date DESC, rr.ride_time DESC 
        LIMIT 1
    ";
    $recent_vehicle_stmt = $pdo->prepare($recent_vehicle_sql);
    $recent_vehicle_stmt->execute([$user_id]);
    $recent_vehicle = $recent_vehicle_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($recent_vehicle) {
        $default_vehicle_id = $recent_vehicle['vehicle_id'];
        $default_vehicle_info = $recent_vehicle;
    }
}

$success_message = '';
$error_message = '';

// POSTデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'add';
        
        if ($action === 'add') {
            // 新規追加
            $driver_id = $_POST['driver_id'];
            $vehicle_id = $_POST['vehicle_id'];
            $ride_date = $_POST['ride_date'];
            $ride_time = $_POST['ride_time'];
            $passenger_count = $_POST['passenger_count'];
            $pickup_location = $_POST['pickup_location'];
            $dropoff_location = $_POST['dropoff_location'];
            $fare = $_POST['fare'];
            $charge = $_POST['charge'] ?? 0;
            $transport_category = $_POST['transport_category'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            $is_return_trip = (isset($_POST['is_return_trip']) && $_POST['is_return_trip'] == '1') ? 1 : 0;
            $original_ride_id = !empty($_POST['original_ride_id']) ? $_POST['original_ride_id'] : null;
            
            // ✅ 料金仕様書準拠：合計料金と支払い方法別金額の計算
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;
            
            $insert_sql = "INSERT INTO ride_records 
                (driver_id, vehicle_id, ride_date, ride_time, passenger_count, 
                 pickup_location, dropoff_location, fare, charge, total_fare,
                 cash_amount, card_amount, transport_category, payment_method, 
                 notes, is_return_trip, original_ride_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $driver_id, $vehicle_id, $ride_date, $ride_time, $passenger_count,
                $pickup_location, $dropoff_location, $fare, $charge, $total_fare,
                $cash_amount, $card_amount, $transport_category, $payment_method, 
                $notes, $is_return_trip, $original_ride_id
            ]);
            
            if ($is_return_trip == 1) {
                $success_message = '復路の乗車記録を登録しました。';
            } else {
                $success_message = '乗車記録を登録しました。';
            }
            
        } elseif ($action === 'edit') {
            // 編集
            $record_id = $_POST['record_id'];
            $ride_time = $_POST['ride_time'];
            $passenger_count = $_POST['passenger_count'];
            $pickup_location = $_POST['pickup_location'];
            $dropoff_location = $_POST['dropoff_location'];
            $fare = $_POST['fare'];
            $charge = $_POST['charge'] ?? 0;
            $transport_category = $_POST['transport_category'];
            $payment_method = $_POST['payment_method'];
            $notes = $_POST['notes'] ?? '';
            
            // ✅ 料金仕様書準拠：編集時も合計料金を再計算
            $total_fare = $fare + $charge;
            $cash_amount = ($payment_method === '現金') ? $total_fare : 0;
            $card_amount = ($payment_method === 'カード') ? $total_fare : 0;
            
            $update_sql = "UPDATE ride_records SET 
                ride_time = ?, passenger_count = ?, pickup_location = ?, dropoff_location = ?, 
                fare = ?, charge = ?, total_fare = ?, cash_amount = ?, card_amount = ?,
                transport_category = ?, payment_method = ?, notes = ?, updated_at = NOW() 
                WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([
                $ride_time, $passenger_count, $pickup_location, $dropoff_location,
                $fare, $charge, $total_fare, $cash_amount, $card_amount,
                $transport_category, $payment_method, $notes, $record_id
            ]);
            
            $success_message = '乗車記録を更新しました。';
            
        } elseif ($action === 'delete') {
            // 削除
            $record_id = $_POST['record_id'];
            
            $delete_sql = "DELETE FROM ride_records WHERE id = ?";
            $delete_stmt = $pdo->prepare($delete_sql);
            $delete_stmt->execute([$record_id]);
            
            $success_message = '乗車記録を削除しました。';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// 運転者一覧取得（職務フラグのみ使用）
$drivers_sql = "SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name";
$drivers_stmt = $pdo->prepare($drivers_sql);
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// 車両一覧取得
$vehicles_sql = "SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE status = 'active' ORDER BY vehicle_number";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute();
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

// よく使う場所の取得（過去の記録から）
$common_locations_sql = "
    SELECT location, COUNT(*) as usage_count FROM (
        SELECT pickup_location as location FROM ride_records 
        WHERE pickup_location IS NOT NULL AND pickup_location != '' AND pickup_location NOT LIKE '%(%'
        UNION ALL
        SELECT dropoff_location as location FROM ride_records 
        WHERE dropoff_location IS NOT NULL AND dropoff_location != '' AND dropoff_location NOT LIKE '%(%'
    ) as all_locations 
    GROUP BY location 
    ORDER BY usage_count DESC, location ASC 
    LIMIT 20
";
$common_locations_stmt = $pdo->prepare($common_locations_sql);
$common_locations_stmt->execute();
$common_locations_data = $common_locations_stmt->fetchAll(PDO::FETCH_ASSOC);

// よく使う場所のリストを作成
$common_locations = array_column($common_locations_data, 'location');

// デフォルト場所も追加（よく使われる場所がない場合）
$default_locations = [
    '○○病院', '△△クリニック', '□□総合病院',
    'スーパー○○', 'イオンモール', '駅前ショッピングセンター',
    '○○介護施設', 'デイサービス△△',
    '市役所', '郵便局', '銀行○○支店'
];

if (empty($common_locations)) {
    $common_locations = $default_locations;
} else {
    // 既存の場所とデフォルト場所をマージ（重複除去）
    $common_locations = array_unique(array_merge($common_locations, $default_locations));
}

// JavaScript用にJSONエンコード
$locations_json = json_encode($common_locations, JSON_UNESCAPED_UNICODE);

// 検索条件
$search_date = $_GET['search_date'] ?? $today;
$search_driver = $_GET['search_driver'] ?? '';
$search_vehicle = $_GET['search_vehicle'] ?? '';

// 乗車記録一覧取得
$where_conditions = ["r.ride_date = ?"];
$params = [$search_date];

if ($search_driver) {
    $where_conditions[] = "r.driver_id = ?";
    $params[] = $search_driver;
}

if ($search_vehicle) {
    $where_conditions[] = "r.vehicle_id = ?";
    $params[] = $search_vehicle;
}

// ✅ 料金仕様書準拠：total_fareを使用
$rides_sql = "SELECT r.*, u.name as driver_name, v.vehicle_number, v.vehicle_name,
    COALESCE(r.total_fare, r.fare + r.charge) as total_amount,
    CASE WHEN r.is_return_trip = 1 THEN '復路' ELSE '往路' END as trip_type
    FROM ride_records r 
    JOIN users u ON r.driver_id = u.id 
    JOIN vehicles v ON r.vehicle_id = v.id 
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY r.ride_time DESC";
$rides_stmt = $pdo->prepare($rides_sql);
$rides_stmt->execute($params);
$rides = $rides_stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ 料金仕様書準拠：日次集計
$summary_sql = "SELECT 
    COUNT(*) as total_rides,
    SUM(r.passenger_count) as total_passengers,
    SUM(COALESCE(r.total_fare, r.fare + r.charge)) as total_revenue,
    AVG(COALESCE(r.total_fare, r.fare + r.charge)) as avg_fare,
    COUNT(CASE WHEN r.payment_method = '現金' THEN 1 END) as cash_count,
    COUNT(CASE WHEN r.payment_method = 'カード' THEN 1 END) as card_count,
    SUM(COALESCE(r.cash_amount, 
        CASE WHEN r.payment_method = '現金' THEN COALESCE(r.total_fare, r.fare + r.charge) ELSE 0 END)) as cash_total,
    SUM(COALESCE(r.card_amount, 
        CASE WHEN r.payment_method = 'カード' THEN COALESCE(r.total_fare, r.fare + r.charge) ELSE 0 END)) as card_total
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions);
$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// 輸送分類別集計
$category_sql = "SELECT 
    r.transport_category,
    COUNT(*) as count,
    SUM(r.passenger_count) as passengers,
    SUM(COALESCE(r.total_fare, r.fare + r.charge)) as revenue
    FROM ride_records r 
    WHERE " . implode(' AND ', $where_conditions) . "
    GROUP BY r.transport_category 
    ORDER BY count DESC";
$category_stmt = $pdo->prepare($category_sql);
$category_stmt->execute($params);
$categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

// 輸送分類・支払方法の選択肢
$transport_categories = ['通院', '外出等', '退院', '転院', '施設入所', 'その他'];
$payment_methods = ['現金', 'カード', 'その他'];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗車記録管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Hiragino Kaku Gothic Pro', 'ヒラギノ角ゴ Pro', 'Yu Gothic Medium', '游ゴシック Medium', YuGothic, '游ゴシック体', 'Meiryo', sans-serif;
        }
        
        /* メインアクションエリア */
        .main-actions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .action-btn {
            border: 2px solid white;
            color: white;
            background: transparent;
            padding: 12px 25px;
            font-size: 1.1em;
            font-weight: bold;
            border-radius: 50px;
            margin: 0 10px 10px 0;
            transition: all 0.3s;
            min-width: 180px;
        }
        
        .action-btn:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .action-btn.primary {
            background: white;
            color: #667eea;
            border-color: white;
        }
        
        .action-btn.primary:hover {
            background: #f8f9fa;
            transform: scale(1.05);
        }
        
        /* ✅ デフォルト車両情報表示 */
        .default-info-banner {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid #bbdefb;
        }

        .default-info-banner .default-info-item {
            text-align: center;
        }

        .default-info-banner small {
            color: #666;
            font-size: 0.75rem;
        }

        .default-info-banner strong {
            color: #1976d2;
            font-size: 0.9rem;
        }
        
        /* ✅ コンパクトフォームスタイル */
        .compact-form-section {
            margin-bottom: 16px;
        }

        .form-label-sm {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 4px;
            display: block;
            color: #333;
        }

        .form-control-sm {
            font-size: 0.9rem;
            padding: 6px 8px;
        }

        /* ✅ 乗車人数ボタン */
        .passenger-control {
            text-align: center;
        }

        .passenger-buttons {
            display: flex;
            gap: 4px;
            justify-content: center;
        }

        .passenger-btn {
            flex: 1;
            max-width: 70px;
            height: 50px;
            border: 2px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: bold;
            transition: all 0.2s ease;
            color: #333;
        }

        .passenger-btn.active,
        .passenger-btn:focus {
            border-color: #0d6efd;
            background: #0d6efd;
            color: white;
            box-shadow: 0 2px 8px rgba(13, 110, 253, 0.25);
        }

        .passenger-input {
            margin-top: 8px;
            text-align: center;
            font-weight: bold;
        }

        /* ✅ 場所入力の改善 */
        .location-inputs {
            position: relative;
        }

        .location-input-group {
            margin-bottom: 8px;
        }

        .location-input {
            font-size: 1rem;
            padding: 12px 16px;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            transition: border-color 0.2s ease;
        }

        .location-input:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            outline: none;
        }

        .location-arrow {
            text-align: center;
            margin: 4px 0;
        }

        /* ✅ 料金入力の改善 */
        .fare-input {
            font-size: 1.2rem;
            font-weight: bold;
            text-align: center;
        }
        
        /* 検索フォーム - コンパクト化 */
        .search-form {
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .search-form .form-label {
            font-size: 0.9em;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .search-form .form-control,
        .search-form .form-select {
            font-size: 0.9em;
            padding: 6px 10px;
        }
        
        /* 乗車記録カード */
        .ride-record {
            background: white;
            padding: 18px;
            margin: 10px 0;
            border-radius: 12px;
            border-left: 4px solid #007bff;
            transition: all 0.3s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        
        .ride-record:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .return-trip {
            border-left-color: #28a745;
            background: linear-gradient(90deg, #f8fff9 0%, white 20%);
        }
        
        /* 復路作成ボタン強調 */
        .return-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        
        .return-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        /* よく使う場所のドロップダウン */
        .location-dropdown {
            position: relative;
        }
        
        .location-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .location-suggestion {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
        }
        
        .location-suggestion:hover {
            background-color: #f8f9fa;
        }
        
        .location-suggestion:last-child {
            border-bottom: none;
        }
        
        .location-suggestion mark {
            background-color: #fff3cd;
            padding: 0 2px;
            border-radius: 2px;
        }
        
        .location-suggestion-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        
        /* その他のスタイル */
        .amount-display {
            font-size: 1.2em;
            font-weight: bold;
            color: #28a745;
        }
        
        .trip-type-badge {
            font-size: 0.8em;
            padding: 3px 10px;
            border-radius: 15px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        
        .summary-value {
            font-size: 1.8em;
            font-weight: bold;
            display: block;
        }
        
        .return-trip-info {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
        }
        
        /* ✅ スマートフォン対応 */
        @media (max-width: 768px) {
            .main-actions {
                text-align: center;
                padding: 15px;
            }
            
            .action-btn {
                display: block;
                width: 100%;
                margin: 0;
                min-width: auto;
            }
            
            .main-actions h3 {
                font-size: 1.3em;
                margin-bottom: 8px;
            }
            
            .main-actions p {
                font-size: 0.9em;
                margin-bottom: 15px;
            }
            
            /* 検索フォーム - スマホ最適化 */
            .search-form {
                padding: 12px;
                margin-bottom: 15px;
            }
            
            .search-form .row {
                --bs-gutter-x: 0.75rem;
            }
            
            .search-form .form-label {
                font-size: 0.8em;
                margin-bottom: 3px;
            }
            
            .search-form .form-control,
            .search-form .form-select {
                font-size: 0.85em;
                padding: 5px 8px;
                height: auto;
            }
            
            .search-form .btn {
                font-size: 0.85em;
                padding: 6px 12px;
                margin-top: 10px;
                width: 100%;
            }
            
            /* 検索フォームの列幅調整 */
            .search-form .col-md-3 {
                margin-bottom: 8px;
            }
            
            .ride-record {
                padding: 15px;
            }
            
            .btn-group {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .location-suggestions {
                max-height: 250px;
            }
            
            .location-suggestion {
                padding: 15px;
                font-size: 1.1em;
            }
        }

        /* ✅ スマートフォン最適化：モーダル表示問題を修正 */
        @media (max-width: 576px) {
            .modal-dialog {
                margin: 0;
                max-width: 100%;
                height: 100vh;
                display: flex;
                flex-direction: column;
            }
            
            .modal-content {
                height: 100vh;
                border-radius: 0;
                border: none;
                display: flex;
                flex-direction: column;
            }
            
            .modal-header {
                flex-shrink: 0;
                padding: 16px;
                border-bottom: 1px solid #dee2e6;
                /* ノッチ対応 */
                padding-top: calc(16px + env(safe-area-inset-top));
            }
            
            .modal-body {
                flex: 1;
                overflow-y: auto;
                padding: 16px;
                /* 下部余白を確実に確保 */
                padding-bottom: 20px;
            }
            
            .modal-footer {
                flex-shrink: 0; /* フッターを固定サイズに */
                padding: 16px;
                border-top: 1px solid #dee2e6;
                background-color: #fff; /* 背景色を明示的に指定 */
                /* セーフエリア対応 */
                padding-bottom: calc(16px + env(safe-area-inset-bottom));
            }
            
            /* よく使う場所のドロップダウンを大きく */
            .location-suggestions {
                max-height: 30vh;
            }
            
            .location-suggestion {
                padding: 16px;
                font-size: 1rem;
            }

            .passenger-btn {
                height: 45px;
                font-size: 0.75rem;
            }

            .search-form {
                padding: 10px;
            }
            
            .search-form .form-label {
                font-size: 0.75em;
            }
            
            .search-form .form-control,
            .search-form .form-select {
                font-size: 0.8em;
                padding: 4px 6px;
            }
            
            .ride-record {
                padding: 12px;
                margin: 6px 0;
            }
            
            .ride-record .row {
                --bs-gutter-x: 0.5rem;
            }
            
            .amount-display {
                font-size: 1.1em;
            }

            /* ✅ 運転者・車両選択エリアの調整 */
            .default-info-banner {
                padding: 12px;
                margin-bottom: 12px;
            }
            
            .default-info-banner .default-info-item small {
                font-size: 0.7rem;
            }
            
            .default-info-banner .default-info-item strong {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <!-- ナビゲーションバー -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-taxi me-2"></i>福祉輸送管理システム
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt me-1"></i>ダッシュボード</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="departure.php"><i class="fas fa-sign-out-alt me-1"></i>出庫処理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="ride_records.php"><i class="fas fa-users me-1"></i>乗車記録</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="arrival.php"><i class="fas fa-sign-in-alt me-1"></i>入庫処理</a>
                    </li>
                </ul>
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($user_name); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- メインアクションエリア -->
        <div class="main-actions">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-2"><i class="fas fa-clipboard-list me-2"></i>乗車記録管理</h3>
                    <p class="mb-0">乗車記録の新規登録・編集・復路作成ができます</p>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" class="action-btn primary" onclick="showAddModal()">
                        <i class="fas fa-plus me-2"></i>新規登録
                    </button>
                </div>
            </div>
        </div>

        <!-- 検索フォーム - コンパクト版 -->
        <div class="search-form">
            <form method="GET" class="row g-2">
                <div class="col-6 col-md-3">
                    <label for="search_date" class="form-label">日付</label>
                    <input type="date" class="form-control" id="search_date" name="search_date" 
                           value="<?php echo htmlspecialchars($search_date); ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label for="search_driver" class="form-label">運転者</label>
                    <select class="form-select" id="search_driver" name="search_driver">
                        <option value="">全て</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?php echo $driver['id']; ?>" 
                                <?php echo ($search_driver == $driver['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($driver['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="search_vehicle" class="form-label">車両</label>
                    <select class="form-select" id="search_vehicle" name="search_vehicle">
                        <option value="">全て</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo $vehicle['id']; ?>" 
                                <?php echo ($search_vehicle == $vehicle['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>検索
                    </button>
                </div>
            </form>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- 乗車記録一覧 -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; border-radius: 10px 10px 0 0 !important;">
                        <h4 class="mb-0">
                            <i class="fas fa-list me-2"></i>乗車記録一覧
                            <small class="ms-2">(<?php echo htmlspecialchars($search_date); ?>)</small>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (empty($rides)): ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-info-circle me-2"></i>
                                該当する乗車記録がありません。
                            </p>
                        <?php else: ?>
                            <?php foreach ($rides as $ride): ?>
                                <div class="ride-record <?php echo $ride['is_return_trip'] ? 'return-trip' : ''; ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <strong class="me-2"><?php echo substr($ride['ride_time'], 0, 5); ?></strong>
                                                <span class="badge trip-type-badge <?php echo $ride['is_return_trip'] ? 'bg-success' : 'bg-primary'; ?>">
                                                    <?php echo $ride['trip_type']; ?>
                                                </span>
                                                <small class="text-muted ms-2">
                                                    <?php echo htmlspecialchars($ride['driver_name']); ?> / <?php echo htmlspecialchars($ride['vehicle_number']); ?>
                                                </small>
                                            </div>
                                            <div class="mb-1">
                                                <i class="fas fa-map-marker-alt text-success me-1"></i>
                                                <strong><?php echo htmlspecialchars($ride['pickup_location']); ?></strong>
                                                <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                                <strong><?php echo htmlspecialchars($ride['dropoff_location']); ?></strong>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $ride['passenger_count']; ?>名 / <?php echo htmlspecialchars($ride['transport_category']); ?> / <?php echo htmlspecialchars($ride['payment_method']); ?>
                                                <?php if ($ride['notes']): ?>
                                                    <br><i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($ride['notes']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="amount-display mb-2">
                                                ¥<?php echo number_format($ride['total_amount']); ?>
                                            </div>
                                            <div class="btn-group" role="group">
                                                <?php if (!$ride['is_return_trip']): ?>
                                                    <button type="button" class="btn return-btn btn-sm" 
                                                            onclick="createReturnTrip(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                            title="復路作成">
                                                        <i class="fas fa-route me-1"></i>復路作成
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-warning btn-sm" 
                                                        onclick="editRecord(<?php echo htmlspecialchars(json_encode($ride)); ?>)"
                                                        title="編集">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" 
                                                        onclick="deleteRecord(<?php echo $ride['id']; ?>)"
                                                        title="削除">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- サイドバー（集計情報） -->
            <div class="col-lg-4">
                <!-- 日次集計 -->
                <div class="card mb-3">
                    <div class="card-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white;">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>日次集計</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6">
                                <div class="summary-card">
                                    <span class="summary-value"><?php echo $summary['total_rides'] ?? 0; ?></span>
                                    <span class="summary-label">総回数</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <span class="summary-value"><?php echo $summary['total_passengers'] ?? 0; ?></span>
                                    <span class="summary-label">総人数</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="summary-card">
                                    <span class="summary-value">¥<?php echo number_format($summary['total_revenue'] ?? 0); ?></span>
                                    <span class="summary-label">売上合計</span>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <strong>現金</strong><br>
                                <span><?php echo $summary['cash_count'] ?? 0; ?>回</span><br>
                                <span class="text-success">¥<?php echo number_format($summary['cash_total'] ?? 0); ?></span>
                            </div>
                            <div class="col-6">
                                <strong>カード</strong><br>
                                <span><?php echo $summary['card_count'] ?? 0; ?>回</span><br>
                                <span class="text-info">¥<?php echo number_format($summary['card_total'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 輸送分類別集計 -->
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white;">
                        <h6 class="mb-0"><i class="fas fa-pie-chart me-2"></i>輸送分類別</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categories)): ?>
                            <p class="text-muted">データがありません</p>
                        <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($category['transport_category']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo $category['count']; ?>回 / <?php echo $category['passengers']; ?>名</small>
                                    </div>
                                    <div class="text-end">
                                        <strong>¥<?php echo number_format($category['revenue']); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 乗車記録入力・編集モーダル -->
    <div class="modal fade" id="rideModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rideModalTitle">
                        <i class="fas fa-plus me-2"></i>乗車記録登録
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="rideForm" method="POST">
                    <input type="hidden" name="action" id="modalAction" value="add">
                    <input type="hidden" name="record_id" id="modalRecordId">
                    <input type="hidden" name="is_return_trip" id="modalIsReturnTrip" value="0">
                    <input type="hidden" name="original_ride_id" id="modalOriginalRideId">
                    
                    <div class="modal-body">
                        <!-- ✅ 運転者・車両選択：最小化表示 + 変更ボタン -->
                        <div class="default-info-banner">
                            <div class="row align-items-center">
                                <div class="col-4 default-info-item">
                                    <small class="text-muted">運転者</small><br>
                                    <strong id="selectedDriverName">
                                        <?php echo htmlspecialchars($user_name); ?>
                                    </strong>
                                </div>
                                <div class="col-4 default-info-item">
                                    <small class="text-muted">車両</small><br>
                                    <strong id="selectedVehicleName">
                                        <?php if ($default_vehicle_info): ?>
                                            <?php echo htmlspecialchars($default_vehicle_info['vehicle_number']); ?>
                                        <?php else: ?>
                                            未設定
                                        <?php endif; ?>
                                    </strong>
                                </div>
                                <div class="col-4 text-end">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="toggleDriverVehicleSelection()" id="editDriverVehicleBtn">
                                        <i class="fas fa-edit me-1"></i>変更
                                    </button>
                                </div>
                            </div>
                            
                            <!-- ✅ 変更時に表示される選択エリア -->
                            <div id="driverVehicleSelection" style="display: none;" class="mt-3">
                                <div class="row g-2">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label-sm">運転者選択</label>
                                        <select class="form-select form-select-sm" id="modalDriverId" name="driver_id" required>
                                            <option value="">運転者を選択</option>
                                            <?php foreach ($drivers as $driver): ?>
                                                <option value="<?php echo $driver['id']; ?>" 
                                                    <?php echo ($driver['id'] == $default_driver_id) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($driver['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label-sm">車両選択</label>
                                        <select class="form-select form-select-sm" id="modalVehicleId" name="vehicle_id" required>
                                            <option value="">車両を選択</option>
                                            <?php foreach ($vehicles as $vehicle): ?>
                                                <option value="<?php echo $vehicle['id']; ?>" 
                                                    <?php echo ($vehicle['id'] == $default_vehicle_id) ? 'selected' : ''; ?>
                                                    data-vehicle-number="<?php echo htmlspecialchars($vehicle['vehicle_number']); ?>">
                                                    <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' - ' . $vehicle['vehicle_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <button type="button" class="btn btn-success btn-sm me-2" onclick="applyDriverVehicleSelection()">
                                        <i class="fas fa-check me-1"></i>適用
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="cancelDriverVehicleSelection()">
                                        <i class="fas fa-times me-1"></i>キャンセル
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 復路情報表示 -->
                        <div id="returnTripInfo" class="return-trip-info" style="display: none;"></div>

                        <!-- ✅ 基本情報：コンパクト化 -->
                        <div class="compact-form-section">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label-sm">日付</label>
                                    <input type="date" class="form-control form-control-sm" id="modalRideDate" name="ride_date" 
                                           value="<?php echo $today; ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label-sm">時刻</label>
                                    <input type="time" class="form-control form-control-sm" id="modalRideTime" name="ride_time" 
                                           value="<?php echo $current_time; ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- ✅ 乗車人数：改善版 -->
                        <div class="compact-form-section">
                            <label class="form-label-sm">乗車人数</label>
                            <div class="passenger-control">
                                <div class="passenger-buttons">
                                    <button type="button" class="passenger-btn active" data-count="1">1人</button>
                                    <button type="button" class="passenger-btn" data-count="2">2人</button>
                                    <button type="button" class="passenger-btn" data-count="3">3人</button>
                                    <button type="button" class="passenger-btn" data-count="4">4人+</button>
                                </div>
                                <input type="number" id="modalPassengerCount" name="passenger_count" 
                                       class="form-control passenger-input" value="1" min="1" max="10" style="display:none;">
                            </div>
                        </div>

                        <!-- ✅ 場所：大きめの入力エリア -->
                        <div class="compact-form-section">
                            <div class="location-inputs">
                                <div class="location-input-group">
                                    <label class="form-label-sm">
                                        <i class="fas fa-circle text-success me-1"></i>乗車地
                                    </label>
                                    <div class="location-dropdown">
                                        <input type="text" class="form-control location-input" id="modalPickupLocation" 
                                               name="pickup_location" placeholder="乗車地を入力" required>
                                        <div id="pickupSuggestions" class="location-suggestions" style="display: none;"></div>
                                    </div>
                                </div>
                                
                                <div class="location-arrow">
                                    <i class="fas fa-arrow-down text-muted"></i>
                                </div>
                                
                                <div class="location-input-group">
                                    <label class="form-label-sm">
                                        <i class="fas fa-circle text-danger me-1"></i>降車地
                                    </label>
                                    <div class="location-dropdown">
                                        <input type="text" class="form-control location-input" id="modalDropoffLocation" 
                                               name="dropoff_location" placeholder="降車地を入力" required>
                                        <div id="dropoffSuggestions" class="location-suggestions" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ✅ 料金：シンプル化 -->
                        <div class="compact-form-section">
                            <div class="row g-2">
                                <div class="col-8">
                                    <label class="form-label-sm">運賃</label>
                                    <input type="number" class="form-control fare-input" id="modalFare" name="fare" 
                                           placeholder="0" min="0" step="100" required>
                                </div>
                                <div class="col-4">
                                    <label class="form-label-sm">追加</label>
                                    <input type="number" class="form-control" id="modalCharge" name="charge" 
                                           value="0" min="0" step="100">
                                </div>
                            </div>
                        </div>

                        <!-- ✅ その他：コンパクト化 -->
                        <div class="compact-form-section">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label-sm">分類</label>
                                    <select class="form-select form-select-sm" id="modalTransportCategory" name="transport_category" required>
                                        <option value="通院" selected>通院</option>
                                        <option value="外出等">外出等</option>
                                        <option value="退院">退院</option>
                                        <option value="転院">転院</option>
                                        <option value="施設入所">施設入所</option>
                                        <option value="その他">その他</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label-sm">支払</label>
                                    <select class="form-select form-select-sm" id="modalPaymentMethod" name="payment_method" required>
                                        <option value="現金" selected>現金</option>
                                        <option value="カード">カード</option>
                                        <option value="その他">その他</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ✅ 備考欄：他のフィールドと統一 -->
                        <div class="compact-form-section">
                            <label class="form-label-sm">備考（任意）</label>
                            <textarea class="form-control form-control-sm" id="modalNotes" name="notes" rows="2" 
                                      style="resize: none;" placeholder="特記事項があれば入力してください"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>キャンセル
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // PHPから取得したよく使う場所データとユーザー情報
        const commonLocations = <?php echo $locations_json; ?>;
        const currentUserId = <?php echo $user_id; ?>;
        const userIsDriver = <?php echo $user_is_driver ? 'true' : 'false'; ?>;
        
        // ✅ デフォルト値の設定（初期値として保持）
        const defaultDriverId = <?php echo $default_driver_id ? $default_driver_id : 'null'; ?>;
        const defaultVehicleId = <?php echo $default_vehicle_id ? $default_vehicle_id : 'null'; ?>;
        
        // 現在選択されている値を保持
        let currentDriverId = defaultDriverId;
        let currentVehicleId = defaultVehicleId;

        // ✅ 運転者・車両選択の制御
        function toggleDriverVehicleSelection() {
            const selectionArea = document.getElementById('driverVehicleSelection');
            const editBtn = document.getElementById('editDriverVehicleBtn');
            
            if (selectionArea.style.display === 'none') {
                selectionArea.style.display = 'block';
                editBtn.innerHTML = '<i class="fas fa-times me-1"></i>閉じる';
                editBtn.className = 'btn btn-outline-secondary btn-sm';
            } else {
                selectionArea.style.display = 'none';
                editBtn.innerHTML = '<i class="fas fa-edit me-1"></i>変更';
                editBtn.className = 'btn btn-outline-primary btn-sm';
            }
        }

        function applyDriverVehicleSelection() {
            const driverSelect = document.getElementById('modalDriverId');
            const vehicleSelect = document.getElementById('modalVehicleId');
            const driverNameDisplay = document.getElementById('selectedDriverName');
            const vehicleNameDisplay = document.getElementById('selectedVehicleName');
            
            // 選択された値を取得
            const selectedDriverId = driverSelect.value;
            const selectedVehicleId = vehicleSelect.value;
            
            if (!selectedDriverId || !selectedVehicleId) {
                alert('運転者と車両の両方を選択してください。');
                return;
            }
            
            // 現在の選択を更新
            currentDriverId = selectedDriverId;
            currentVehicleId = selectedVehicleId;
            
            // 表示名を更新
            const selectedDriverText = driverSelect.options[driverSelect.selectedIndex].text;
            const selectedVehicleNumber = vehicleSelect.options[vehicleSelect.selectedIndex].getAttribute('data-vehicle-number');
            
            driverNameDisplay.textContent = selectedDriverText;
            vehicleNameDisplay.textContent = selectedVehicleNumber;
            
            // 選択エリアを閉じる
            toggleDriverVehicleSelection();
            
            // バイブレーション
            if (navigator.vibrate) navigator.vibrate(100);
        }

        function cancelDriverVehicleSelection() {
            const driverSelect = document.getElementById('modalDriverId');
            const vehicleSelect = document.getElementById('modalVehicleId');
            
            // 現在の値に戻す
            driverSelect.value = currentDriverId;
            vehicleSelect.value = currentVehicleId;
            
            // 選択エリアを閉じる
            toggleDriverVehicleSelection();
        }

        // 新規登録モーダル表示
        function showAddModal() {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>乗車記録登録';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('modalRecordId').value = '';
            document.getElementById('modalIsReturnTrip').value = '0';
            document.getElementById('modalOriginalRideId').value = '';
            document.getElementById('returnTripInfo').style.display = 'none';
            
            // フォームをリセット
            document.getElementById('rideForm').reset();
            document.getElementById('modalRideDate').value = '<?php echo $today; ?>';
            document.getElementById('modalRideTime').value = getCurrentTime();
            document.getElementById('modalPassengerCount').value = '1';
            document.getElementById('modalCharge').value = '0';
            document.getElementById('modalPaymentMethod').value = '現金';
            document.getElementById('modalTransportCategory').value = '通院';
            
            // ✅ デフォルト値の設定
            currentDriverId = defaultDriverId;
            currentVehicleId = defaultVehicleId;
            
            if (defaultDriverId) {
                document.getElementById('modalDriverId').value = defaultDriverId;
            }
            if (defaultVehicleId) {
                document.getElementById('modalVehicleId').value = defaultVehicleId;
            }
            
            // 表示名をリセット
            document.getElementById('selectedDriverName').textContent = '<?php echo htmlspecialchars($user_name); ?>';
            document.getElementById('selectedVehicleName').textContent = '<?php echo $default_vehicle_info ? htmlspecialchars($default_vehicle_info['vehicle_number']) : '未設定'; ?>';
            
            // 選択エリアを閉じる
            const selectionArea = document.getElementById('driverVehicleSelection');
            const editBtn = document.getElementById('editDriverVehicleBtn');
            selectionArea.style.display = 'none';
            editBtn.innerHTML = '<i class="fas fa-edit me-1"></i>変更';
            editBtn.className = 'btn btn-outline-primary btn-sm';
            
            // 乗車人数ボタンのリセット
            resetPassengerButtons();
            
            new bootstrap.Modal(document.getElementById('rideModal')).show();
        }

        // 編集モーダル表示
        function editRecord(record) {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>乗車記録編集';
            document.getElementById('modalAction').value = 'edit';
            document.getElementById('modalRecordId').value = record.id;
            document.getElementById('returnTripInfo').style.display = 'none';
            
            // フォームに値を設定
            document.getElementById('modalDriverId').value = record.driver_id;
            document.getElementById('modalVehicleId').value = record.vehicle_id;
            document.getElementById('modalRideDate').value = record.ride_date;
            document.getElementById('modalRideTime').value = record.ride_time;
            document.getElementById('modalPassengerCount').value = record.passenger_count;
            document.getElementById('modalPickupLocation').value = record.pickup_location;
            document.getElementById('modalDropoffLocation').value = record.dropoff_location;
            document.getElementById('modalFare').value = record.fare;
            document.getElementById('modalCharge').value = record.charge;
            document.getElementById('modalTransportCategory').value = record.transport_category;
            document.getElementById('modalPaymentMethod').value = record.payment_method;
            document.getElementById('modalNotes').value = record.notes || '';
            
            // ✅ 現在の選択値を更新
            currentDriverId = record.driver_id;
            currentVehicleId = record.vehicle_id;
            
            // ✅ 表示名を更新
            const driverSelect = document.getElementById('modalDriverId');
            const vehicleSelect = document.getElementById('modalVehicleId');
            const selectedDriverText = driverSelect.options[driverSelect.selectedIndex]?.text || '不明';
            const selectedVehicleNumber = vehicleSelect.options[vehicleSelect.selectedIndex]?.getAttribute('data-vehicle-number') || '不明';
            
            document.getElementById('selectedDriverName').textContent = selectedDriverText;
            document.getElementById('selectedVehicleName').textContent = selectedVehicleNumber;
            
            // 乗車人数ボタンの設定
            setPassengerCount(record.passenger_count);
            
            new bootstrap.Modal(document.getElementById('rideModal')).show();
        }

        // 復路作成モーダル表示
        function createReturnTrip(record) {
            document.getElementById('rideModalTitle').innerHTML = '<i class="fas fa-route me-2"></i>復路作成';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('modalRecordId').value = '';
            document.getElementById('modalIsReturnTrip').value = '1';
            document.getElementById('modalOriginalRideId').value = record.id;
            
            // 復路情報表示
            const returnTripInfo = document.getElementById('returnTripInfo');
            returnTripInfo.style.display = 'block';
            returnTripInfo.innerHTML = `
                <h6><i class="fas fa-route me-2"></i>復路作成</h6>
                <p class="mb-0">「${record.pickup_location} → ${record.dropoff_location}」の復路を作成します。</p>
                <p class="mb-0 text-muted">乗車地と降車地が自動で入れ替わります。</p>
            `;
            
            // 基本情報をコピー（乗降地は入れ替え）
            document.getElementById('modalDriverId').value = record.driver_id;
            document.getElementById('modalVehicleId').value = record.vehicle_id;
            document.getElementById('modalRideDate').value = record.ride_date;
            document.getElementById('modalRideTime').value = getCurrentTime();
            document.getElementById('modalPassengerCount').value = record.passenger_count;
            
            // 乗降地を入れ替え
            document.getElementById('modalPickupLocation').value = record.dropoff_location;
            document.getElementById('modalDropoffLocation').value = record.pickup_location;
            
            document.getElementById('modalFare').value = record.fare;
            document.getElementById('modalCharge').value = record.charge;
            document.getElementById('modalTransportCategory').value = record.transport_category;
            document.getElementById('modalPaymentMethod').value = record.payment_method;
            document.getElementById('modalNotes').value = '';
            
            // ✅ 現在の選択値を更新
            currentDriverId = record.driver_id;
            currentVehicleId = record.vehicle_id;
            
            // ✅ 表示名を更新
            const driverSelect = document.getElementById('modalDriverId');
            const vehicleSelect = document.getElementById('modalVehicleId');
            const selectedDriverText = driverSelect.options[driverSelect.selectedIndex]?.text || '不明';
            const selectedVehicleNumber = vehicleSelect.options[vehicleSelect.selectedIndex]?.getAttribute('data-vehicle-number') || '不明';
            
            document.getElementById('selectedDriverName').textContent = selectedDriverText;
            document.getElementById('selectedVehicleName').textContent = selectedVehicleNumber;
            
            // 乗車人数ボタンの設定
            setPassengerCount(record.passenger_count);
            
            new bootstrap.Modal(document.getElementById('rideModal')).show();
        }

        // 削除確認
        function deleteRecord(recordId) {
            if (confirm('この乗車記録を削除しますか？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="record_id" value="${recordId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 現在時刻を取得
        function getCurrentTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            return `${hours}:${minutes}`;
        }

        // ✅ 乗車人数制御（4人以上対応）
        function resetPassengerButtons() {
            const passengerBtns = document.querySelectorAll('.passenger-btn');
            const passengerInput = document.getElementById('modalPassengerCount');
            
            passengerBtns.forEach(b => b.classList.remove('active'));
            document.querySelector('[data-count="1"]').classList.add('active');
            passengerInput.style.display = 'none';
            passengerInput.value = '1';
        }

        function setPassengerCount(count) {
            const passengerBtns = document.querySelectorAll('.passenger-btn');
            const passengerInput = document.getElementById('modalPassengerCount');
            
            passengerBtns.forEach(b => b.classList.remove('active'));
            
            if (count <= 3) {
                const targetBtn = document.querySelector(`[data-count="${count}"]`);
                if (targetBtn) {
                    targetBtn.classList.add('active');
                    passengerInput.style.display = 'none';
                }
            } else {
                document.querySelector('[data-count="4"]').classList.add('active');
                passengerInput.style.display = 'block';
            }
            
            passengerInput.value = count;
        }

        // よく使う場所の候補表示
        function showLocationSuggestions(input, type) {
            const query = input.value.toLowerCase().trim();
            const suggestionId = type === 'pickup' ? 'pickupSuggestions' : 'dropoffSuggestions';
            const suggestionsDiv = document.getElementById(suggestionId);
            
            // 空文字またはフォーカス時はよく使う場所を表示
            if (query.length === 0) {
                const topLocations = commonLocations.slice(0, 8);
                suggestionsDiv.innerHTML = '';
                
                if (topLocations.length > 0) {
                    const header = document.createElement('div');
                    header.className = 'location-suggestion-header';
                    header.innerHTML = '<small class="text-muted px-3 py-2 d-block"><i class="fas fa-star me-1"></i>よく使う場所</small>';
                    suggestionsDiv.appendChild(header);
                    
                    topLocations.forEach(location => {
                        const div = document.createElement('div');
                        div.className = 'location-suggestion';
                        div.innerHTML = `<i class="fas fa-map-marker-alt me-2 text-muted"></i>${location}`;
                        div.onclick = () => selectLocation(input, location, suggestionsDiv);
                        suggestionsDiv.appendChild(div);
                    });
                    suggestionsDiv.style.display = 'block';
                }
                return;
            }
            
            // 検索結果
            const filteredLocations = commonLocations.filter(location =>
                location.toLowerCase().includes(query)
            );
            
            if (filteredLocations.length === 0) {
                suggestionsDiv.style.display = 'none';
                return;
            }
            
            suggestionsDiv.innerHTML = '';
            filteredLocations.slice(0, 10).forEach(location => {
                const div = document.createElement('div');
                div.className = 'location-suggestion';
                
                // 検索語をハイライト
                const highlightedText = location.replace(
                    new RegExp(query, 'gi'), 
                    `<mark>            passengerInput.style.display</mark>`
                );
                div.innerHTML = `<i class="fas fa-search me-2 text-muted"></i>${highlightedText}`;
                div.onclick = () => selectLocation(input, location, suggestionsDiv);
                suggestionsDiv.appendChild(div);
            });
            
            suggestionsDiv.style.display = 'block';
        }

        // 場所選択処理
        function selectLocation(input, location, suggestionsDiv) {
            input.value = location;
            suggestionsDiv.style.display = 'none';
            input.classList.remove('is-invalid');
        }

        // ✅ フォーカス管理（スムーズな入力フロー）
        function optimizeInputFlow() {
            // 時刻 → 乗車地 → 降車地 → 料金の順番
            const inputs = [
                'modalRideTime',
                'modalPickupLocation', 
                'modalDropoffLocation',
                'modalFare'
            ];
            
            inputs.forEach((inputId, index) => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && index < inputs.length - 1) {
                            e.preventDefault();
                            const nextInput = document.getElementById(inputs[index + 1]);
                            if (nextInput) nextInput.focus();
                        }
                    });
                }
            });
        }

        // イベントリスナー設定
        document.addEventListener('DOMContentLoaded', function() {
            // ✅ 乗車人数クイック選択
            const passengerBtns = document.querySelectorAll('.passenger-btn');
            const passengerInput = document.getElementById('modalPassengerCount');

            passengerBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // 全ボタンのactiveクラス削除
                    passengerBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const count = this.dataset.count;
                    if (count === '4') {
                        // 4人+の場合、数値入力を表示
                        passengerInput.style.display = 'block';
                        passengerInput.value = '4';
                        passengerInput.focus();
                    } else {
                        // 1-3人の場合、隠したまま値設定
                        passengerInput.style.display = 'none';
                        passengerInput.value = count;
                    }
                    
                    // バイブレーション
                    if (navigator.vibrate) navigator.vibrate(50);
                });
            });
            
            // 場所入力フィールドのイベント設定
            ['modalPickupLocation', 'modalDropoffLocation'].forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    const type = id.includes('Pickup') ? 'pickup' : 'dropoff';
                    
                    input.addEventListener('keyup', function() {
                        showLocationSuggestions(this, type);
                    });
                    
                    input.addEventListener('focus', function() {
                        showLocationSuggestions(this, type);
                    });
                }
            });
            
            // 外部クリックで候補を閉じる
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.location-dropdown')) {
                    document.getElementById('pickupSuggestions').style.display = 'none';
                    document.getElementById('dropoffSuggestions').style.display = 'none';
                }
            });

            // ✅ 入力フロー最適化の実行
            optimizeInputFlow();
        });

        // フォーム送信前の確認
        document.getElementById('rideForm').addEventListener('submit', function(e) {
            const action = document.getElementById('modalAction').value;
            const isReturnTrip = document.getElementById('modalIsReturnTrip').value === '1';
            
            // ✅ バリデーション：運転者・車両の確認（現在の選択値を使用）
            if (!currentDriverId) {
                alert('運転者が設定されていません。運転者を選択してください。');
                e.preventDefault();
                return;
            }
            
            if (!currentVehicleId) {
                alert('車両が設定されていません。車両を選択してください。');
                e.preventDefault();
                return;
            }
            
            // 隠しフィールドに現在の選択値を設定（確実な送信のため）
            document.getElementById('modalDriverId').value = currentDriverId;
            document.getElementById('modalVehicleId').value = currentVehicleId;
            
            let message = '';
            if (action === 'add' && isReturnTrip) {
                message = '復路の乗車記録を登録しますか？';
            } else if (action === 'add') {
                message = '乗車記録を登録しますか？';
            } else {
                message = '乗車記録を更新しますか？';
            }
            
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>';
            }
            
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
