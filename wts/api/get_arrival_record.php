<?php
session_start();
require_once '../config/database.php';

// Content-Type設定
header('Content-Type: application/json');

// セッション確認
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'ログインが必要です']));
}

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'GET method required']));
}

// パラメータ確認
if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'ID parameter required']));
}

try {
    // データベース接続
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $arrival_id = (int)$_GET['id'];
    $user_id = $_SESSION['user_id'];
    
    // 権限チェック関数
    function canEditArrivalRecord($pdo, $arrival_id, $user_id) {
        // 管理者は全て編集可能
        $user_stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch();
        
        if ($user && $user['permission_level'] === 'Admin') {
            return true;
        }
        
        // 一般ユーザーは自分の記録のみ編集可能
        $record_stmt = $pdo->prepare("
            SELECT driver_id FROM arrival_records 
            WHERE id = ? AND driver_id = ?
        ");
        $record_stmt->execute([$arrival_id, $user_id]);
        
        return $record_stmt->rowCount() > 0;
    }
    
    // 権限チェック
    if (!canEditArrivalRecord($pdo, $arrival_id, $user_id)) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'error' => '編集権限がありません']));
    }
    
    // 入庫記録と関連データを取得
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            u.name as driver_name,
            v.vehicle_number,
            d.departure_mileage,
            d.departure_date,
            d.departure_time
        FROM arrival_records a
        JOIN users u ON a.driver_id = u.id
        JOIN vehicles v ON a.vehicle_id = v.id
        LEFT JOIN departure_records d ON a.departure_record_id = d.id
        WHERE a.id = ?
    ");
    
    $stmt->execute([$arrival_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        http_response_code(404);
        exit(json_encode(['success' => false, 'error' => '入庫記録が見つかりません']));
    }
    
    // 日付・時刻フォーマット調整
    if ($record['arrival_time']) {
        // HH:MM:SS から HH:MM に変換
        $time_parts = explode(':', $record['arrival_time']);
        $record['arrival_time'] = $time_parts[0] . ':' . $time_parts[1];
    }
    
    // レスポンス
    echo json_encode([
        'success' => true,
        'record' => $record
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in get_arrival_record.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'データベースエラーが発生しました'
    ]);
} catch (Exception $e) {
    error_log("General error in get_arrival_record.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'エラーが発生しました'
    ]);
}
?>
