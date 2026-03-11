<?php
// api/health.php - ヘルスチェックエンドポイント
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$health = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => []
];

$overall_ok = true;

// 1. PHPバージョンチェック
$health['checks']['php'] = [
    'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'warning',
    'version' => PHP_VERSION
];

// 2. データベース接続チェック
try {
    require_once dirname(__DIR__) . '/config/database.php';
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT 1");
    $health['checks']['database'] = ['status' => 'ok'];
} catch (Exception $e) {
    $health['checks']['database'] = ['status' => 'error', 'message' => 'DB接続失敗'];
    $overall_ok = false;
    error_log("Health check DB error: " . $e->getMessage());
}

// 3. セッションディレクトリチェック
$session_path = session_save_path();
if (empty($session_path)) $session_path = sys_get_temp_dir();
$health['checks']['session'] = [
    'status' => is_writable($session_path) ? 'ok' : 'error'
];
if ($health['checks']['session']['status'] === 'error') $overall_ok = false;

// 4. 必須ファイル存在チェック
$required_files = [
    '../config/database.php',
    '../includes/session_check.php',
    '../includes/unified-header.php',
    '../functions.php'
];
$missing_files = [];
foreach ($required_files as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $missing_files[] = basename($file);
    }
}
$health['checks']['files'] = [
    'status' => empty($missing_files) ? 'ok' : 'error',
    'missing' => $missing_files
];
if (!empty($missing_files)) $overall_ok = false;

// 5. ディスク容量チェック
$free_space = disk_free_space('/');
$total_space = disk_total_space('/');
if ($free_space !== false && $total_space !== false) {
    $usage_percent = round((1 - $free_space / $total_space) * 100, 1);
    $health['checks']['disk'] = [
        'status' => $usage_percent < 90 ? 'ok' : 'warning',
        'usage_percent' => $usage_percent
    ];
}

// 全体ステータス
$health['status'] = $overall_ok ? 'ok' : 'error';

http_response_code($overall_ok ? 200 : 503);
echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
