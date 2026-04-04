<?php
// api/backup.php - データベースバックアップ（管理者専用）
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/session_check.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

// 管理者チェック
if ($user_role !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '管理者権限が必要です']);
    exit;
}

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドが必要です']);
    exit;
}

// CSRF検証
require_once dirname(__DIR__) . '/includes/session_check.php';
validateCsrfToken();

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getDBConnection();

    // バックアップ対象テーブル一覧を取得
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $backup_data = [
        'metadata' => [
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $_SESSION['user_name'] ?? 'unknown',
            'php_version' => PHP_VERSION,
            'table_count' => count($tables)
        ],
        'tables' => []
    ];

    foreach ($tables as $table) {
        // テーブル名のバリデーション（英数字・アンダースコアのみ許可）
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            continue;
        }

        // テーブル構造
        $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $create_info = $stmt->fetch(PDO::FETCH_ASSOC);

        // テーブルデータ
        $stmt = $pdo->query("SELECT * FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $backup_data['tables'][$table] = [
            'create_sql' => $create_info['Create Table'] ?? '',
            'row_count' => count($rows),
            'data' => $rows
        ];
    }

    // JSONファイルとしてダウンロード
    $filename = 'wts_backup_' . date('Ymd_His') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo json_encode($backup_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Backup error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'バックアップに失敗しました']);
}
