<?php
/**
 * 福祉輸送管理システム - GitHubファイル分析ツール
 * 実際のファイル内容を分析して削除候補を特定
 */

// セキュリティ設定
ini_set('display_errors', 1);
error_reporting(E_ALL);

// GitHubの直接URLベース
$github_base = 'https://raw.githubusercontent.com/hipper-gif/wts/main/wts/';

// 分析対象ファイルリスト（実際のGitHub確認済み）
$files_to_analyze = [
    // 🔐 基盤システム
    'index.php',
    'index_improved.php', 
    'dashboard.php',
    'dashboard_debug.php',
    'logout.php',
    'functions.php',
    'master_menu.php',
    
    // 🎯 点呼・点検システム
    'pre_duty_call.php',
    'post_duty_call.php',
    'daily_inspection.php',
    'periodic_inspection.php',
    
    // 🚀 運行管理システム
    'departure.php',
    'arrival.php',
    'ride_records.php',
    'operation.php', // 旧システム
    
    // 💰 集金・報告機能
    'cash_management.php',
    'annual_report.php',
    'accident_management.php',
    
    // 🚨 緊急監査対応
    'emergency_audit_kit.php',
    'emergency_audit_export.php',
    'adaptive_export_document.php',
    'audit_data_manager.php',
    'export_document.php',
    'fixed_export_document.php',
    
    // 👥 マスタ管理
    'user_management.php',
    'vehicle_management.php',
    
    // 🔧 システム保守ツール
    'check_table_structure.php',
    'check_db_structure.php',
    'check_real_database.php',
    'safe_check.php',
    'debug_data.php',
    'fix_table_structure.php',
    'fix_user_permissions.php',
    'fix_permissions_complete.php',
    'fix_system_settings.php',
    'fix_accident_table.php',
    'fix_ride_records_table.php',
    'fix_caller_display.php',
    'fix_caller_list.php',
    'fix_database_error.php',
    'manual_data_manager.php',
    'data_management.php',
    'sync_existing_ride_data.php',
    'remove_permission_checks.php',
    'temp_fix_permissions.php',
    'user_permissions_fix.php',
    'setup_audit_kit.php',
    'setup_complete_system.php',
    'simple_audit_setup.php',
    'quick_edit.php',
    'file_scanner.php',
    'system_fix.php',
    'complete_accident_table_fix.php',
    
    // 🇯🇵 日本語ツール
    '診断ツール.php',
    '詳細分析ツール.php',
    '実務データチェッカー.php',
    '根本原因調査.php',
    '修正スクリプト.php',
    '安全修正スクリプト.php',
    '統合スクリプト.php',
    '置き換えスクリプト.php',
    
    // 📄 HTMLファイル
    'file_list.html',
    'github_file_checker.html'
];

// ファイル分析結果
$analysis_results = [
    'core_system' => [],      // 核心システム
    'completed' => [],        // 完成機能
    'testing' => [],         // テスト段階
    'problematic' => [],     // 問題あり
    'redundant' => [],       // 重複ファイル
    'cleanup' => [],         // 削除候補
    'errors' => []           // エラー
];

/**
 * ファイル内容を取得
 */
function fetchFileContent($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'WTS-FileAnalyzer/1.0'
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    return $content !== false ? $content : null;
}

/**
 * ファイル分析
 */
function analyzeFile($filename, $content) {
    if (!$content) {
        return ['status' => 'error', 'type' => 'fetch_failed'];
    }
    
    $size = strlen($content);
    $lines = substr_count($content, "\n");
    
    // PHPファイルの分析
    if (strpos($filename, '.php') !== false) {
        return analyzePhpFile($filename, $content, $size, $lines);
    }
    
    // HTMLファイルの分析
    if (strpos($filename, '.html') !== false) {
        return analyzeHtmlFile($filename, $content, $size, $lines);
    }
    
    return ['status' => 'unknown', 'type' => 'unknown_type'];
}

/**
 * PHPファイル分析
 */
function analyzePhpFile($filename, $content, $size, $lines) {
    $analysis = [
        'filename' => $filename,
        'size' => $size,
        'lines' => $lines,
        'type' => 'php',
        'features' => [],
        'issues' => [],
        'status' => 'unknown'
    ];
    
    // 核心システムファイルの判定
    $core_files = [
        'index.php', 'dashboard.php', 'logout.php',
        'pre_duty_call.php', 'post_duty_call.php', 
        'daily_inspection.php', 'periodic_inspection.php',
        'departure.php', 'arrival.php', 'ride_records.php',
        'user_management.php', 'vehicle_management.php'
    ];
    
    if (in_array($filename, $core_files)) {
        $analysis['category'] = 'core_system';
        $analysis['status'] = 'critical';
    }
    
    // 新機能の判定
    $new_features = ['cash_management.php', 'annual_report.php', 'accident_management.php'];
    if (in_array($filename, $new_features)) {
        $analysis['category'] = 'new_feature';
        $analysis['status'] = 'testing';
    }
    
    // 重複ファイルの判定
    $duplicates = [
        'index_improved.php' => 'index.php',
        'dashboard_debug.php' => 'dashboard.php',
        'fixed_export_document.php' => 'export_document.php'
    ];
    
    if (isset($duplicates[$filename])) {
        $analysis['category'] = 'duplicate';
        $analysis['original'] = $duplicates[$filename];
        $analysis['status'] = 'redundant';
    }
    
    // 機能分析
    if (strpos($content, 'CREATE TABLE') !== false) {
        $analysis['features'][] = 'database_setup';
    }
    if (strpos($content, 'ALTER TABLE') !== false) {
        $analysis['features'][] = 'database_migration';
    }
    if (strpos($content, 'session_start') !== false) {
        $analysis['features'][] = 'session_management';
    }
    if (strpos($content, 'PDO') !== false) {
        $analysis['features'][] = 'database_access';
    }
    if (strpos($content, 'bootstrap') !== false || strpos($content, 'Bootstrap') !== false) {
        $analysis['features'][] = 'ui_framework';
    }
    
    // 問題の検出
    if (strpos($content, 'mysql_') !== false) {
        $analysis['issues'][] = 'deprecated_mysql';
    }
    if (strpos($content, 'echo $_') !== false) {
        $analysis['issues'][] = 'potential_xss';
    }
    if (strpos($content, 'eval(') !== false) {
        $analysis['issues'][] = 'security_risk';
    }
    if (strpos($content, 'TODO') !== false || strpos($content, 'FIXME') !== false) {
        $analysis['issues'][] = 'incomplete_code';
    }
    
    // デバッグファイルの判定
    if (strpos($filename, 'debug') !== false || 
        strpos($filename, 'test') !== false ||
        strpos($filename, 'temp') !== false ||
        strpos($content, 'var_dump') !== false ||
        strpos($content, 'print_r') !== false) {
        $analysis['category'] = 'debug';
        $analysis['status'] = 'cleanup_candidate';
    }
    
    // 修正スクリプトの判定
    if (strpos($filename, 'fix_') !== false || 
        strpos($filename, 'setup_') !== false ||
        strpos($filename, 'check_') !== false) {
        $analysis['category'] = 'maintenance';
        $analysis['status'] = 'utility';
    }
    
    // 日本語ファイルの判定
    if (preg_match('/[\x{4e00}-\x{9faf}]/u', $filename)) {
        $analysis['category'] = 'japanese_tool';
        $analysis['status'] = 'specialized';
    }
    
    return $analysis;
}

/**
 * HTMLファイル分析
 */
function analyzeHtmlFile($filename, $content, $size, $lines) {
    return [
        'filename' => $filename,
        'size' => $size,
        'lines' => $lines,
        'type' => 'html',
        'category' => 'documentation',
        'status' => 'utility'
    ];
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - ファイル分析結果</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .analysis-card { margin-bottom: 1rem; }
        .file-size { font-size: 0.9em; color: #666; }
        .feature-badge { margin: 2px; }
        .issue-badge { margin: 2px; }
        .status-critical { border-left: 4px solid #dc3545; }
        .status-testing { border-left: 4px solid #ffc107; }
        .status-redundant { border-left: 4px solid #fd7e14; }
        .status-cleanup { border-left: 4px solid #6c757d; }
        .loading { text-align: center; padding: 2rem; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1><i class="fas fa-search"></i> 福祉輸送管理システム - ファイル分析結果</h1>
                <p class="text-muted">GitHubから実際のファイル内容を取得して分析しています...</p>
                
                <div id="analysis-progress" class="loading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">分析中...</span>
                    </div>
                    <p>ファイル分析実行中...</p>
                </div>
                
                <div id="analysis-results" style="display: none;">
                    <!-- 分析結果がここに表示される -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // ファイル分析実行
        async function runAnalysis() {
            const progressDiv = document.getElementById('analysis-progress');
            const resultsDiv = document.getElementById('analysis-results');
            
            try {
                progressDiv.innerHTML = '<div class="spinner-border text-primary" role="status"></div><p>分析実行中...</p>';
                
                // PHP分析を実行（Ajax）
                const response = await fetch(window.location.href + '?action=analyze', {
                    method: 'POST'
                });
                
                if (response.ok) {
                    const results = await response.json();
                    displayResults(results);
                    progressDiv.style.display = 'none';
                    resultsDiv.style.display = 'block';
                } else {
                    throw new Error('分析エラー');
                }
            } catch (error) {
                progressDiv.innerHTML = '<div class="alert alert-danger">分析エラーが発生しました: ' + error.message + '</div>';
            }
        }
        
        function displayResults(results) {
            const resultsDiv = document.getElementById('analysis-results');
            
            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <h3><i class="fas fa-star text-warning"></i> 核心システムファイル</h3>
                        ${generateFileList(results.core_system, 'critical')}
                    </div>
                    <div class="col-md-6">
                        <h3><i class="fas fa-check-circle text-success"></i> 完成機能</h3>
                        ${generateFileList(results.completed, 'success')}
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h3><i class="fas fa-exclamation-triangle text-warning"></i> テスト段階</h3>
                        ${generateFileList(results.testing, 'warning')}
                    </div>
                    <div class="col-md-6">
                        <h3><i class="fas fa-times-circle text-danger"></i> 問題ありファイル</h3>
                        ${generateFileList(results.problematic, 'danger')}
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h3><i class="fas fa-copy text-info"></i> 重複ファイル</h3>
                        ${generateFileList(results.redundant, 'info')}
                    </div>
                    <div class="col-md-6">
                        <h3><i class="fas fa-trash text-secondary"></i> 削除候補</h3>
                        ${generateFileList(results.cleanup, 'secondary')}
                    </div>
                </div>
                
                <div class="mt-4">
                    <h3><i class="fas fa-chart-bar"></i> 分析サマリー</h3>
                    ${generateSummary(results)}
                </div>
            `;
            
            resultsDiv.innerHTML = html;
        }
        
        function generateFileList(files, type) {
            if (!files || files.length === 0) {
                return '<p class="text-muted">該当ファイルなし</p>';
            }
            
            return files.map(file => `
                <div class="card analysis-card status-${file.status || type}">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="fas fa-file-code"></i> ${file.filename}
                            <span class="file-size">(${formatFileSize(file.size)})</span>
                        </h6>
                        <p class="card-text">
                            <small class="text-muted">行数: ${file.lines} | タイプ: ${file.type}</small>
                        </p>
                        ${file.features ? '<div>' + file.features.map(f => `<span class="badge bg-primary feature-badge">${f}</span>`).join('') + '</div>' : ''}
                        ${file.issues ? '<div>' + file.issues.map(i => `<span class="badge bg-danger issue-badge">${i}</span>`).join('') + '</div>' : ''}
                        ${file.original ? `<small class="text-info">元ファイル: ${file.original}</small>` : ''}
                    </div>
                </div>
            `).join('');
        }
        
        function generateSummary(results) {
            const total = Object.values(results).flat().length;
            return `
                <div class="alert alert-info">
                    <h5>📊 分析結果サマリー</h5>
                    <ul>
                        <li>📁 総ファイル数: ${total}</li>
                        <li>⭐ 核心システム: ${results.core_system?.length || 0}ファイル</li>
                        <li>✅ 完成機能: ${results.completed?.length || 0}ファイル</li>
                        <li>🔄 テスト段階: ${results.testing?.length || 0}ファイル</li>
                        <li>⚠️ 問題あり: ${results.problematic?.length || 0}ファイル</li>
                        <li>📋 重複ファイル: ${results.redundant?.length || 0}ファイル</li>
                        <li>🗑️ 削除候補: ${results.cleanup?.length || 0}ファイル</li>
                    </ul>
                </div>
            `;
        }
        
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        
        // ページ読み込み時に分析開始
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(runAnalysis, 1000);
        });
    </script>
</body>
</html>

<?php
// Ajax分析処理
if (isset($_GET['action']) && $_GET['action'] === 'analyze') {
    header('Content-Type: application/json');
    
    $results = [
        'core_system' => [],
        'completed' => [],
        'testing' => [],
        'problematic' => [],
        'redundant' => [],
        'cleanup' => [],
        'errors' => []
    ];
    
    foreach ($files_to_analyze as $filename) {
        try {
            $url = $github_base . urlencode($filename);
            $content = fetchFileContent($url);
            
            if ($content === null) {
                $results['errors'][] = [
                    'filename' => $filename,
                    'error' => 'Failed to fetch file'
                ];
                continue;
            }
            
            $analysis = analyzeFile($filename, $content);
            
            // カテゴリ別に分類
            switch ($analysis['status']) {
                case 'critical':
                    $results['core_system'][] = $analysis;
                    break;
                case 'testing':
                    $results['testing'][] = $analysis;
                    break;
                case 'redundant':
                    $results['redundant'][] = $analysis;
                    break;
                case 'cleanup_candidate':
                    $results['cleanup'][] = $analysis;
                    break;
                default:
                    if (!empty($analysis['issues'])) {
                        $results['problematic'][] = $analysis;
                    } else {
                        $results['completed'][] = $analysis;
                    }
            }
            
            // 少し待機（GitHubのレート制限対策）
            usleep(100000); // 0.1秒
            
        } catch (Exception $e) {
            $results['errors'][] = [
                'filename' => $filename,
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
