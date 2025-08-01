<?php
/**
 * 福祉輸送管理システム - 柔軟なファイル分析ツール
 * 複数のGitHubアクセス方法を試して確実にファイル内容を取得
 */

// セキュリティ設定
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// GitHub設定（確認済みリポジトリ: https://github.com/hipper-gif/wts.git）
$github_token = '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';
$github_patterns = [
    // パターン1: wtsサブディレクトリ + mainブランチ
    [
        'owner' => 'hipper-gif',
        'repo' => 'wts',
        'path' => 'wts',
        'api_url' => 'https://api.github.com/repos/hipper-gif/wts/contents/wts',
        'raw_base' => 'https://raw.githubusercontent.com/hipper-gif/wts/main/wts/'
    ],
    // パターン2: ルートディレクトリ + mainブランチ
    [
        'owner' => 'hipper-gif',
        'repo' => 'wts',
        'path' => '',
        'api_url' => 'https://api.github.com/repos/hipper-gif/wts/contents',
        'raw_base' => 'https://raw.githubusercontent.com/hipper-gif/wts/main/'
    ],
    // パターン3: wtsサブディレクトリ + masterブランチ
    [
        'owner' => 'hipper-gif',
        'repo' => 'wts',
        'path' => 'wts',
        'api_url' => 'https://api.github.com/repos/hipper-gif/wts/contents/wts',
        'raw_base' => 'https://raw.githubusercontent.com/hipper-gif/wts/master/wts/'
    ],
    // パターン4: ルートディレクトリ + masterブランチ
    [
        'owner' => 'hipper-gif',
        'repo' => 'wts',
        'path' => '',
        'api_url' => 'https://api.github.com/repos/hipper-gif/wts/contents',
        'raw_base' => 'https://raw.githubusercontent.com/hipper-gif/wts/master/'
    ]
];

// 既知のファイルリスト（ドキュメントから抽出）
$known_files = [
    // 核心システム
    'index.php', 'dashboard.php', 'logout.php', 'functions.php',
    'pre_duty_call.php', 'post_duty_call.php', 
    'daily_inspection.php', 'periodic_inspection.php',
    'departure.php', 'arrival.php', 'ride_records.php',
    'user_management.php', 'vehicle_management.php',
    
    // 新機能
    'cash_management.php', 'annual_report.php', 'accident_management.php',
    
    // 重複候補
    'index_improved.php', 'dashboard_debug.php',
    
    // 緊急監査系
    'emergency_audit_kit.php', 'emergency_audit_export.php',
    'adaptive_export_document.php', 'audit_data_manager.php',
    'export_document.php', 'fixed_export_document.php',
    
    // 保守ツール
    'check_table_structure.php', 'check_db_structure.php',
    'fix_table_structure.php', 'fix_user_permissions.php',
    'setup_audit_kit.php', 'manual_data_manager.php',
    
    // その他
    'master_menu.php', 'operation.php', 'file_scanner.php'
];

// 分析結果
$analysis_results = [
    'core_system' => [],
    'testing' => [],
    'redundant' => [],
    'cleanup' => [],
    'maintenance' => [],
    'problematic' => [],
    'completed' => [],
    'not_found' => [],
    'errors' => []
];

/**
 * GitHub APIでファイル一覧を取得（複数パターン試行）
 */
function getGitHubFiles($patterns, $token) {
    foreach ($patterns as $pattern) {
        $context = stream_context_create([
            'http' => [
                'header' => [
                    "Authorization: token {$token}",
                    "User-Agent: WTS-FileAnalyzer/3.0",
                    "Accept: application/vnd.github.v3+json"
                ],
                'timeout' => 30
            ]
        ]);
        
        $response = @file_get_contents($pattern['api_url'], false, $context);
        if ($response !== false) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return ['success' => true, 'data' => $data, 'pattern' => $pattern];
            }
        }
    }
    
    return ['success' => false, 'error' => 'All GitHub API patterns failed'];
}

/**
 * 直接rawファイルアクセスを試行
 */
function tryDirectRawAccess($filename, $patterns) {
    foreach ($patterns as $pattern) {
        $url = $pattern['raw_base'] . $filename;
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'WTS-FileAnalyzer/3.0'
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        if ($content !== false && strlen($content) > 10) {
            return ['success' => true, 'content' => $content, 'url' => $url];
        }
    }
    
    return ['success' => false];
}

/**
 * ファイル分析
 */
function analyzeFileContent($filename, $content, $size = null) {
    if (!$size) {
        $size = strlen($content);
    }
    
    $lines = substr_count($content, "\n") + 1;
    
    $analysis = [
        'filename' => $filename,
        'size' => $size,
        'lines' => $lines,
        'features' => [],
        'issues' => [],
        'category' => 'unknown',
        'status' => 'unknown',
        'priority' => 0,
        'description' => ''
    ];
    
    return classifyFile($analysis, $content);
}

/**
 * ファイル分類
 */
function classifyFile($analysis, $content) {
    $filename = $analysis['filename'];
    
    // 核心システム判定
    $core_files = [
        'index.php' => ['desc' => 'ログイン画面', 'priority' => 10],
        'dashboard.php' => ['desc' => 'メインダッシュボード', 'priority' => 10],
        'pre_duty_call.php' => ['desc' => '乗務前点呼', 'priority' => 9],
        'post_duty_call.php' => ['desc' => '乗務後点呼', 'priority' => 9],
        'daily_inspection.php' => ['desc' => '日常点検', 'priority' => 9],
        'departure.php' => ['desc' => '出庫処理', 'priority' => 9],
        'arrival.php' => ['desc' => '入庫処理', 'priority' => 9],
        'ride_records.php' => ['desc' => '乗車記録管理', 'priority' => 9],
    ];
    
    if (isset($core_files[$filename])) {
        $analysis['category'] = 'core_system';
        $analysis['status'] = 'critical';
        $analysis['priority'] = $core_files[$filename]['priority'];
        $analysis['description'] = $core_files[$filename]['desc'];
    }
    
    // 新機能判定
    $new_features = [
        'cash_management.php' => '集金管理機能',
        'annual_report.php' => '陸運局提出機能', 
        'accident_management.php' => '事故管理機能',
        'periodic_inspection.php' => '定期点検機能'
    ];
    
    if (isset($new_features[$filename])) {
        $analysis['category'] = 'testing';
        $analysis['status'] = 'testing';
        $analysis['priority'] = 6;
        $analysis['description'] = $new_features[$filename];
    }
    
    // 重複ファイル判定
    $duplicates = [
        'index_improved.php' => 'index.php',
        'dashboard_debug.php' => 'dashboard.php',
        'fixed_export_document.php' => 'export_document.php'
    ];
    
    if (isset($duplicates[$filename])) {
        $analysis['category'] = 'redundant';
        $analysis['status'] = 'redundant';
        $analysis['priority'] = 2;
        $analysis['original'] = $duplicates[$filename];
        $analysis['description'] = '重複ファイル - 削除候補';
    }
    
    // 保守ツール判定
    if (preg_match('/^(fix_|setup_|check_|debug_)/', $filename)) {
        $analysis['category'] = 'maintenance';
        $analysis['status'] = 'utility';
        $analysis['priority'] = 3;
        $analysis['description'] = '保守・修正ツール';
    }
    
    // 問題ファイル判定
    if (strpos($filename, 'audit') !== false && strpos($filename, 'emergency') !== false) {
        $analysis['category'] = 'problematic';
        $analysis['status'] = 'problematic';
        $analysis['priority'] = 4;
        $analysis['description'] = '緊急監査システム（課題あり）';
    }
    
    // 機能分析
    if (strpos($content, 'CREATE TABLE') !== false) {
        $analysis['features'][] = 'database_setup';
    }
    if (strpos($content, 'bootstrap') !== false) {
        $analysis['features'][] = 'bootstrap_ui';
    }
    if (strpos($content, 'session_start') !== false) {
        $analysis['features'][] = 'session_mgmt';
    }
    
    // 問題検出
    if (strpos($content, 'var_dump') !== false || strpos($content, 'print_r') !== false) {
        $analysis['issues'][] = 'debug_code';
        if ($analysis['category'] === 'unknown') {
            $analysis['category'] = 'cleanup';
            $analysis['status'] = 'cleanup_candidate';
            $analysis['priority'] = 1;
        }
    }
    
    // デフォルト分類
    if ($analysis['category'] === 'unknown') {
        $analysis['category'] = 'completed';
        $analysis['status'] = 'completed';
        $analysis['priority'] = 5;
        $analysis['description'] = '完成機能';
    }
    
    return $analysis;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - ファイル分析ツール v3.0</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-critical { border-left: 5px solid #dc3545; background: #fff5f5; }
        .status-testing { border-left: 5px solid #ffc107; background: #fffdf0; }
        .status-redundant { border-left: 5px solid #fd7e14; background: #fff8f0; }
        .status-cleanup { border-left: 5px solid #6c757d; background: #f8f9fa; }
        .status-utility { border-left: 5px solid #0dcaf0; background: #f0fdff; }
        .status-problematic { border-left: 5px solid #e83e8c; background: #fdf2f8; }
        .analysis-card { margin-bottom: 1rem; transition: all 0.3s; }
        .analysis-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .summary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .loading { text-align: center; padding: 2rem; }
        .progress-step { margin: 0.5rem 0; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1><i class="fas fa-search"></i> 福祉輸送管理システム - ファイル分析 v3.0</h1>
                <p class="text-muted">複数のGitHubアクセス方法で確実にファイル内容を取得・分析します</p>
                
                <div id="analysis-progress" class="loading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">分析中...</span>
                    </div>
                    <div id="progress-messages" class="mt-3">
                        <p>分析開始...</p>
                    </div>
                </div>
                
                <div id="analysis-results" style="display: none;">
                    <!-- 分析結果 -->
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateProgress(message) {
            const messagesDiv = document.getElementById('progress-messages');
            const time = new Date().toLocaleTimeString();
            messagesDiv.innerHTML += `<div class="progress-step"><small class="text-muted">[${time}]</small> ${message}</div>`;
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
        
        async function runAnalysis() {
            const progressDiv = document.getElementById('analysis-progress');
            const resultsDiv = document.getElementById('analysis-results');
            
            try {
                updateProgress('GitHub APIアクセスを開始...');
                
                const response = await fetch(window.location.href + '?action=analyze');
                
                if (response.ok) {
                    updateProgress('分析結果を取得中...');
                    const results = await response.json();
                    
                    if (results.error) {
                        throw new Error(results.message || '分析エラー');
                    }
                    
                    updateProgress('結果表示を準備中...');
                    displayResults(results);
                    progressDiv.style.display = 'none';
                    resultsDiv.style.display = 'block';
                } else {
                    const errorText = await response.text();
                    throw new Error(`HTTP ${response.status}: ${errorText}`);
                }
            } catch (error) {
                progressDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <h5><i class="fas fa-exclamation-triangle"></i> 分析エラー</h5>
                        <p><strong>エラー内容:</strong> ${error.message}</p>
                        <p><strong>考えられる原因:</strong></p>
                        <ul>
                            <li>GitHubリポジトリのURL構造が異なる</li>
                            <li>APIキーの権限不足</li>
                            <li>ネットワーク接続の問題</li>
                        </ul>
                        <button class="btn btn-warning" onclick="location.reload()">再試行</button>
                        <button class="btn btn-info" onclick="showFallbackMode()">既知ファイルで分析</button>
                    </div>
                `;
            }
        }
        
        function showFallbackMode() {
            const progressDiv = document.getElementById('analysis-progress');
            progressDiv.innerHTML = `
                <div class="alert alert-info">
                    <h5><i class="fas fa-list"></i> フォールバックモード：既知ファイル分析</h5>
                    <p>GitHub APIが使用できない場合、ドキュメントから特定された既知ファイルを直接取得して分析します。</p>
                    <button class="btn btn-primary" onclick="runFallbackAnalysis()">既知ファイルで分析開始</button>
                </div>
            `;
        }
        
        async function runFallbackAnalysis() {
            const progressDiv = document.getElementById('analysis-progress');
            const resultsDiv = document.getElementById('analysis-results');
            
            updateProgress('フォールバックモード: 既知ファイルの直接取得を開始...');
            
            try {
                const response = await fetch(window.location.href + '?action=fallback');
                
                if (response.ok) {
                    const results = await response.json();
                    displayResults(results);
                    progressDiv.style.display = 'none';
                    resultsDiv.style.display = 'block';
                } else {
                    throw new Error('フォールバック分析も失敗しました');
                }
            } catch (error) {
                progressDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <h5>全ての分析方法が失敗しました</h5>
                        <p>GitHub接続に問題がある可能性があります。ネットワーク環境を確認してください。</p>
                    </div>
                `;
            }
        }
        
        function displayResults(results) {
            const resultsDiv = document.getElementById('analysis-results');
            
            let html = `
                <!-- サマリー -->
                <div class="row mb-4">
                    <div class="col-12">
                        ${generateSummary(results)}
                    </div>
                </div>
                
                <!-- 削除推奨ファイル（最重要） -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h3><i class="fas fa-trash text-danger"></i> 🗑️ 削除推奨ファイル</h3>
                        ${generateDeletionCandidates(results)}
                    </div>
                    <div class="col-md-6">
                        <h3><i class="fas fa-star text-warning"></i> ⭐ 核心システム（削除禁止）</h3>
                        ${generateFileList(results.core_system, 'critical')}
                    </div>
                </div>
                
                <!-- その他のカテゴリ -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <h4><i class="fas fa-flask text-warning"></i> テスト段階</h4>
                        ${generateFileList(results.testing, 'testing')}
                    </div>
                    <div class="col-md-4">
                        <h4><i class="fas fa-tools text-info"></i> 保守ツール</h4>
                        ${generateFileList(results.maintenance, 'utility')}
                    </div>
                    <div class="col-md-4">
                        <h4><i class="fas fa-exclamation-triangle text-danger"></i> 問題あり</h4>
                        ${generateFileList(results.problematic, 'problematic')}
                    </div>
                </div>
                
                ${results.not_found && results.not_found.length > 0 ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <h4><i class="fas fa-question-circle text-muted"></i> 未確認ファイル</h4>
                        <div class="alert alert-warning">
                            <p>以下のファイルは存在確認できませんでした：</p>
                            <div class="d-flex flex-wrap">
                                ${results.not_found.map(f => `<span class="badge bg-warning text-dark me-2 mb-1">${f}</span>`).join('')}
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}
            `;
            
            resultsDiv.innerHTML = html;
        }
        
        function generateDeletionCandidates(results) {
            const candidates = [
                ...(results.redundant || []),
                ...(results.cleanup || [])
            ];
            
            if (candidates.length === 0) {
                return '<p class="text-success">削除候補なし - システムは整理されています</p>';
            }
            
            return `
                <div class="alert alert-warning">
                    <h6>🚨 即座に削除可能なファイル: ${candidates.length}個</h6>
                </div>
                <div class="list-group">
                    ${candidates.map(file => `
                        <div class="list-group-item status-${file.status} d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">
                                    <i class="fas fa-file"></i> ${file.filename}
                                    <span class="badge bg-danger ms-2">削除推奨</span>
                                </h6>
                                <p class="mb-1">${file.description || '削除候補'}</p>
                                ${file.original ? `<small class="text-info">元ファイル: ${file.original}</small>` : ''}
                            </div>
                            <div class="text-end">
                                <small class="text-muted">${formatFileSize(file.size)}</small>
                                <div><span class="badge bg-secondary">P${file.priority}</span></div>
                            </div>
                        </div>
                    `).join('')}
                </div>
                <div class="mt-3">
                    <button class="btn btn-outline-danger" onclick="generateDeletionScript()">
                        <i class="fas fa-code"></i> 削除スクリプト生成
                    </button>
                </div>
            `;
        }
        
        function generateFileList(files, type) {
            if (!files || files.length === 0) {
                return '<p class="text-muted">該当ファイルなし</p>';
            }
            
            return `<div class="list-group">` + files.map(file => `
                <div class="list-group-item status-${file.status || type}">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">
                            <i class="fas fa-file-code"></i> ${file.filename}
                            <span class="badge bg-secondary ms-2">P${file.priority || 0}</span>
                        </h6>
                        <small>${formatFileSize(file.size)}</small>
                    </div>
                    ${file.description ? `<p class="mb-1">${file.description}</p>` : ''}
                    ${file.features && file.features.length > 0 ? '<div>' + file.features.map(f => `<span class="badge bg-primary me-1">${f}</span>`).join('') + '</div>' : ''}
                    ${file.issues && file.issues.length > 0 ? '<div>' + file.issues.map(i => `<span class="badge bg-danger me-1">${i}</span>`).join('') + '</div>' : ''}
                </div>
            `).join('') + `</div>`;
        }
        
        function generateSummary(results) {
            const total = Object.values(results).flat().filter(item => item && item.filename).length;
            const redundant = (results.redundant || []).length;
            const cleanup = (results.cleanup || []).length;
            const deletable = redundant + cleanup;
            
            return `
                <div class="card summary-card">
                    <div class="card-body">
                        <h4><i class="fas fa-chart-pie"></i> 分析結果サマリー</h4>
                        <div class="row">
                            <div class="col-md-8">
                                <ul class="list-unstyled mb-0">
                                    <li><i class="fas fa-folder"></i> <strong>分析ファイル数:</strong> ${total}ファイル</li>
                                    <li><i class="fas fa-star text-warning"></i> <strong>核心システム:</strong> ${(results.core_system || []).length}ファイル（削除禁止）</li>
                                    <li><i class="fas fa-trash text-danger"></i> <strong>削除推奨:</strong> ${deletable}ファイル</li>
                                    <li><i class="fas fa-tools text-info"></i> <strong>保守ツール:</strong> ${(results.maintenance || []).length}ファイル</li>
                                </ul>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="bg-white p-3 rounded">
                                    <h2 class="text-success mb-0">${Math.round((deletable / total) * 100)}%</h2>
                                    <small class="text-muted">削除可能率</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function generateDeletionScript() {
            alert('削除スクリプト機能は今後実装予定です。現在は手動でファイルを削除してください。');
        }
        
        function formatFileSize(bytes) {
            if (!bytes) return '不明';
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
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_GET['action'] === 'analyze') {
        // GitHub API分析
        try {
            $files_result = getGitHubFiles($github_patterns, $github_token);
            
            if (!$files_result['success']) {
                echo json_encode([
                    'error' => true,
                    'message' => $files_result['error'],
                    'suggestion' => 'フォールバックモードを試してください'
                ]);
                exit;
            }
            
            $files_data = $files_result['data'];
            $pattern = $files_result['pattern'];
            
            $all_analyses = [];
            
            foreach ($files_data as $file_info) {
                if ($file_info['type'] !== 'file' || !isset($file_info['download_url'])) {
                    continue;
                }
                
                $content = @file_get_contents($file_info['download_url']);
                if ($content !== false) {
                    $analysis = analyzeFileContent($file_info['name'], $content, $file_info['size']);
                    $all_analyses[] = $analysis;
                }
                
                usleep(100000); // レート制限対策
            }
            
            // 結果分類
            foreach ($all_analyses as $analysis) {
                $analysis_results[$analysis['category']][] = $analysis;
            }
            
            echo json_encode($analysis_results, JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
        
    } elseif ($_GET['action'] === 'fallback') {
        // フォールバック分析（既知ファイルの直接取得）
        try {
            $all_analyses = [];
            $not_found = [];
            
            foreach ($known_files as $filename) {
                $result = tryDirectRawAccess($filename, $github_patterns);
                
                if ($result['success']) {
                    $analysis = analyzeFileContent($filename, $result['content']);
                    $all_analyses[] = $analysis;
                } else {
                    $not_found[] = $filename;
                }
                
                usleep(200000); // より長い待機時間
            }
            
            // 結果分類
            foreach ($all_analyses as $analysis) {
                $analysis_results[$analysis['category']][] = $analysis;
            }
            
            $analysis_results['not_found'] = $not_found;
            
            echo json_encode($analysis_results, JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    exit;
}
?>
