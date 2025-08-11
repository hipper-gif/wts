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

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'POST method required']));
}

try {
    // データベース接続
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // パラメータ取得・バリデーション
    $arrival_id = $_POST['arrival_id'] ?? null;
    $arrival_date = $_POST['arrival_date'] ?? null;
    $arrival_time = $_POST['arrival_time'] ?? null;
    $arrival_mileage = $_POST['arrival_mileage'] ?? null;
    $fuel_cost = (int)($_POST['fuel_cost'] ?? 0);
    $highway_cost = (int)($_POST['highway_cost'] ?? 0);
    $toll_cost = (int)($_POST['toll_cost'] ?? 0);
    $other_cost = (int)($_POST['other_cost'] ?? 0);
    $remarks = $_POST['remarks'] ?? null;
    $edit_reason = $_POST['edit_reason'] ?? null;
    $user_id = $_SESSION['user_id'];
    
    // 必須パラメータチェック
    if (!$arrival_id || !$arrival_date || !$arrival_time || !$arrival_mileage || !$edit_reason) {
        http_response_code(400);
        exit(json_encode(['success' => false, 'error' => '必須項目が入力されていません']));
    }
    
    $arrival_mileage = (int)$arrival_mileage;
    
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
    
    // トランザクション開始
    $pdo->beginTransaction();
    
    // 1. 出庫メーター取得（走行距離計算・バリデーション用）
    $stmt = $pdo->prepare("
        SELECT d.departure_mileage, a.vehicle_id, a.driver_id
        FROM arrival_records a
        JOIN departure_records d ON a.departure_record_id = d.id
        WHERE a.id = ?
    ");
    $stmt->execute([$arrival_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) {
        $pdo->rollback();
        http_response_code(404);
        exit(json_encode(['success' => false, 'error' => '入庫記録が見つかりません']));
    }
    
    // メーターバリデーション
    if ($arrival_mileage < $data['departure_mileage']) {
        $pdo->rollback();
        http_response_code(400);
        exit(json_encode([
            'success' => false, 
            'error' => "入庫メーターは出庫メーター({$data['departure_mileage']}km)以上である必要があります"
        ]));
    }
    
    $total_distance = $arrival_mileage - $data['departure_mileage'];
    
    // 2. 入庫記録更新
    $update_stmt = $pdo->prepare("
        UPDATE arrival_records SET
            arrival_date = ?,
            arrival_time = ?,
            arrival_mileage = ?,
            total_distance = ?,
            fuel_cost = ?,
            highway_cost = ?,
            toll_cost = ?,
            other_cost = ?,
            remarks = ?,
            is_edited = TRUE,
            edit_reason = ?,
            last_edited_by = ?,
            last_edited_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $update_result = $update_stmt->execute([
        $arrival_date, $arrival_time, $arrival_mileage, $total_distance,
        $fuel_cost, $highway_cost, $toll_cost, $other_cost, $remarks,
        $edit_reason, $user_id, $arrival_id
    ]);
    
    if (!$update_result) {
        $pdo->rollback();
        http_response_code(500);
        exit(json_encode(['success' => false, 'error' => '入庫記録の更新に失敗しました']));
    }
    
    // 3. 車両の現在メーター更新
    $vehicle_stmt = $pdo->prepare("
        UPDATE vehicles SET current_mileage = ? WHERE id = ?
    ");
    $vehicle_result = $vehicle_stmt->execute([$arrival_mileage, $data['vehicle_id']]);
    
    if (!$vehicle_result) {
        $pdo->rollback();
        http_response_code(500);
        exit(json_encode(['success' => false, 'error' => '車両メーターの更新に失敗しました']));
    }
    
    // コミット
    $pdo->commit();
    
    // 成功レスポンス
    echo json_encode([
        'success' => true,
        'message' => '入庫記録を修正しました',
        'data' => [
            'arrival_id' => $arrival_id,
            'total_distance' => $total_distance,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    error_log("Database error in edit_arrival_record.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'データベースエラーが発生しました'
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    error_log("General error in edit_arrival_record.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'エラーが発生しました: ' . $e->getMessage()
    ]);
}
?>
