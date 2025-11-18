<?php
// =================================================================
// 予約データ取得API
// 
// ファイル: /Smiley/taxi/wts/calendar/api/get_reservations.php
// 機能: FullCalendar用予約データ取得・フィルタリング
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

// 基盤システム読み込み
require_once '../../config/database.php';
require_once '../includes/calendar_functions.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    sendErrorResponse('認証が必要です', 401);
}

// データベース接続
$pdo = getDBConnection();

try {
    // パラメータ取得・検証
    $start_date = $_GET['start'] ?? '';
    $end_date = $_GET['end'] ?? '';
    $driver_id = $_GET['driver_id'] ?? 'all';
    $view_type = $_GET['view_type'] ?? 'month';
    
    // 日付形式検証
    if (!$start_date || !$end_date) {
        sendErrorResponse('開始日と終了日が必要です');
    }
    
    // 日付形式変換（FullCalendarのISO形式対応）
    $start_date = date('Y-m-d', strtotime($start_date));
    $end_date = date('Y-m-d', strtotime($end_date));
    
    if (!$start_date || !$end_date) {
        sendErrorResponse('無効な日付形式です');
    }
    
    // ユーザー情報
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'] ?? 'user';
    
    // 予約データ取得
    $reservations = getReservationsForCalendar($start_date, $end_date, $driver_id, $user_role);
    
    // レスポンス送信
    sendSuccessResponse($reservations);
    
} catch (Exception $e) {
    error_log("予約データ取得エラー: " . $e->getMessage());
    sendErrorResponse('データ取得中にエラーが発生しました');
}
?>
