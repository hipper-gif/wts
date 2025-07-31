<?php
/**
 * 福祉輸送管理システム - システム状態チェッカー
 * URL: https://tw1nkle.com/Smiley/taxi/wts/system_status_check.php
 * 目的: Claude用のリアルタイムシステム状況確認
 */

header('Content-Type: application/json; charset=utf-8');

try {
    // データベース接続確認
    require_once 'config/database.php';
    
    $status = [
        'timestamp' => date('Y-m-d H:i:s'),
        'system_info' => [
            'php_version' => phpversion(),
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown'
        ],
        'database' => checkDatabase($pdo),
        'files' => checkFiles(),
        'functions' => checkFunctions(),
        'tables' => checkTables($pdo),
        'summary' => []
    ];
    
    // サマリー生成
    $status['summary'] = generateSummary($status);
    
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

/**
 * データベース接続と基本テーブル確認
 */
function checkDatabase($pdo) {
    try {
        $result = [
            'connection' => 'OK',
            'database_name' => 'twinklemark_wts',
            'tables_count' => 0,
            'tables_status' => []
        ];
        
        // テーブル一覧取得
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $result['tables_count'] = count($tables);
        
        // 各テーブルの状況確認
        $important_tables = [
            'users', 'vehicles', 'pre_duty_calls', 'post_duty_calls',
            'daily_inspections', 'periodic_inspections',
            'departure_records', 'arrival_records', 'ride_records',
            'accidents', 'system_settings'
        ];
        
        foreach ($important_tables as $table) {
            if (in_array($table, $tables)) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                $count = $stmt->fetchColumn();
                $result['tables_status'][$table] = [
                    'exists' => true,
                    'record_count' => $count
                ];
            } else {
                $result['tables_status'][$table] = [
                    'exists' => false,
                    'record_count' => 0
                ];
            }
        }
        
        return $result;
        
    } catch (Exception $e) {
        return [
            'connection' => 'ERROR',
            'error_message' => $e->getMessage()
        ];
    }
}

/**
 * 重要ファイルの存在確認
 */
function checkFiles() {
    $files = [
        // 基盤システム
        'index.php' => '認証・ログイン',
        'dashboard.php' => 'ダッシュボード',
        'logout.php' => 'ログアウト',
        
        // 点呼・点検
        'pre_duty_call.php' => '乗務前点呼',
        'post_duty_call.php' => '乗務後点呼',
        'daily_inspection.php' => '日常点検',
        'periodic_inspection.php' => '定期点検',
        
        // 運行管理
        'departure.php' => '出庫処理',
        'arrival.php' => '入庫処理',
        'ride_records.php' => '乗車記録',
        
        // 追加機能
        'cash_management.php' => '集金管理',
        'annual_report.php' => '陸運局提出',
        'accident_management.php' => '事故管理',
        
        // マスタ管理
        'user_management.php' => 'ユーザー管理',
        'vehicle_management.php' => '車両管理',
        
        // 設定
        'config/database.php' => 'DB設定'
    ];
    
    $result = [
        'total_files' => count($files),
        'existing_files' => 0,
        'file_status' => []
    ];
    
    foreach ($files as $file => $description) {
        $exists = file_exists($file);
        $size = $exists ? filesize($file) : 0;
        $modified = $exists ? date('Y-m-d H:i:s', filemtime($file)) : null;
        
        $result['file_status'][$file] = [
            'exists' => $exists,
            'description' => $description,
            'size_bytes' => $size,
            'size_kb' => round($size / 1024, 1),
            'last_modified' => $modified
        ];
        
        if ($exists) {
            $result['existing_files']++;
        }
    }
    
    $result['completion_rate'] = round(($result['existing_files'] / $result['total_files']) * 100, 1);
    
    return $result;
}

/**
 * 重要関数の存在確認
 */
function checkFunctions() {
    $functions = [];
    
    // 各ファイルから重要関数を確認
    $php_files = [
        'dashboard.php', 'user_management.php', 'vehicle_management.php'
    ];
    
    foreach ($php_files as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            preg_match_all('/function\s+(\w+)\s*\(/', $content, $matches);
            if (!empty($matches[1])) {
                $functions[$file] = $matches[1];
            }
        }
    }
    
    return [
        'php_files_checked' => count($php_files),
        'functions_found' => $functions
    ];
}

/**
 * テーブル構造の詳細確認
 */
function checkTables($pdo) {
    try {
        $result = [];
        
        // 重要テーブルの構造確認
        $tables = ['users', 'vehicles', 'ride_records'];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("DESCRIBE $table");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result[$table] = [
                    'exists' => true,
                    'columns' => array_column($columns, 'Field'),
                    'column_count' => count($columns)
                ];
            } catch (Exception $e) {
                $result[$table] = [
                    'exists' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $result;
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * サマリー生成
 */
function generateSummary($status) {
    $summary = [
        'overall_status' => 'OK',
        'completion_estimate' => '0%',
        'critical_issues' => [],
        'recommendations' => []
    ];
    
    // データベース状況
    if ($status['database']['connection'] !== 'OK') {
        $summary['critical_issues'][] = 'データベース接続エラー';
        $summary['overall_status'] = 'ERROR';
    }
    
    // ファイル完成度
    $file_completion = $status['files']['completion_rate'];
    $summary['completion_estimate'] = $file_completion . '%';
    
    if ($file_completion >= 95) {
        $summary['overall_status'] = 'EXCELLENT';
    } elseif ($file_completion >= 80) {
        $summary['overall_status'] = 'GOOD';
    } elseif ($file_completion >= 60) {
        $summary['overall_status'] = 'FAIR';
    } else {
        $summary['overall_status'] = 'POOR';
    }
    
    // 推奨事項
    if (!$status['files']['file_status']['cash_management.php']['exists']) {
        $summary['recommendations'][] = '集金管理機能の実装';
    }
    if (!$status['files']['file_status']['annual_report.php']['exists']) {
        $summary['recommendations'][] = '陸運局提出機能の実装';
    }
    
    return $summary;
}
?>
