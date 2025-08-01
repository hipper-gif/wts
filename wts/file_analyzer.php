<?php
/**
 * 福祉輸送管理システム - GitHub ファイル名リスト取得＋Raw分析ツール
 * 1. GitHub APIでファイル名一覧を取得
 * 2. 各ファイルのraw URLを生成してアクセス・分析
 */

// セキュリティ設定
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// GitHub設定
$github_token = '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';
$github_owner = 'hipper-gif';
$github_repo = 'wts';

// 試行パターン（ディレクトリ構造 × ブランチ）
$access_patterns = [
    ['branch' => 'main', 'path' => 'wts'],
    ['branch' => 'main', 'path' => ''],
    ['branch' => 'master', 'path' => 'wts'],
    ['branch' => 'master', 'path' => ''],
];

// 分析結果格納
$analysis_results = [
    'core_system' => [],
    'new_features' => [],
    'redundant' => [],
    'cleanup' => [],
    'maintenance' => [],
    'problematic' => [],
    'completed' => [],
    'file_list' => [],
    'access_info' => []
];

/**
 * GitHub APIでファイル名一覧を取得
 */
function getFileList($owner, $repo, $path, $token) {
    $api_url = "https://api.github.com/repos/{$owner}/{$repo}/contents";
    if (!empty($path)) {
        $api_url .= "/{$path}";
    }
    
    $context = stream_context_create([
        'http' => [
            'header' => [
                "Authorization: token {$token}",
                "User-Agent: WTS-FileAnalyzer/4.0",
                "Accept: application/vnd.github.v3+json"
            ],
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($api_url, false, $context);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'HTTP request failed'];
    }
    
    // HTTPステータスコードをチェック
    $headers = $http_response_header ?? [];
    $status_line = $headers[0] ?? '';
    if (strpos($status_line, '200') === false) {
        return ['success' => false, 'error' => "HTTP error: {$status_line}"];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()];
    }
    
    // ファイル名のみ抽出
    $files = [];
    if (is_array($data)) {
        foreach ($data as $item) {
            if (isset($item['type']) && $item['type'] === 'file') {
                $files[] = [
                    'name' => $item['name'],
                    'size' => $item['size'] ?? 0,
                    'path' => $item['path'] ?? $item['name']
                ];
            }
        }
    }
    
    return ['success' => true, 'files' => $files, 'api_url' => $api_url];
}

/**
 * raw URLを生成
 */
function generateRawUrl($owner, $repo, $branch, $path, $filename) {
    $base_url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}";
    if (!empty($path)) {
        return "{$base_url}/{$path}/{$filename}";
    }
    return "{$base_url}/{$filename}";
}

/**
 * raw URLからファイル内容を取得
 */
function fetchRawContent($raw_url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'WTS-FileAnalyzer/4.0',
            'ignore_errors' => true
        ]
    ]);
    
    $content = @file_get_contents($raw_url, false, $context);
    
    if ($content === false) {
        return ['success' => false, 'error' => 'Failed to fetch raw content'];
    }
    
    // HTMLが返ってきた場合（404等）
    if (strpos($content, '<!DOCTYPE html>') === 0 || strpos($content, '<html') !== false) {
        return ['success' => false, 'error' => 'HTML response (likely 404)'];
    }
    
    return ['success' => true, 'content' => $content, 'size' => strlen($content)];
}

/**
 * ファイル分析
 */
function analyzeFile($filename, $content, $size, $raw_url) {
    $lines = substr_count($content, "\n") + 1;
    
    $analysis = [
        'filename' => $filename,
        'size' => $size,
        'lines' => $lines,
        'raw_url' => $raw_url,
        'features' => [],
        'issues' => [],
        'category' => 'unknown',
        'status' => 'unknown',
        'priority' => 0,
        'description' => '',
        'recommendation' => ''
    ];
    
    // ファイル分類
    $analysis = classifyFileByName($analysis, $content);
    
    return $analysis;
}

/**
 * ファイル名とコンテンツによる分類
 */
function classifyFileByName($analysis, $content) {
    $filename = $analysis['filename'];
    
    // 1. 核心システムファイル（絶対削除禁止）
    $core_files = [
        'index.php' => ['desc' => 'ログイン画面', 'priority' => 10],
        'dashboard.php' => ['desc' => 'メインダッシュボード', 'priority' => 10],
        'logout.php' => ['desc' => 'ログアウト処理', 'priority' => 8],
        'pre_duty_call.php' => ['desc' => '乗務前点呼', 'priority' => 9],
        'post_duty_call.php' => ['desc' => '乗務後点呼', 'priority' => 9],
        'daily_inspection.php' => ['desc' => '日常点検', 'priority' => 9],
        'periodic_inspection.php' => ['desc' => '定期点検（3ヶ月）', 'priority' => 8],
        'departure.php' => ['desc' => '出庫処理', 'priority' => 9],
        'arrival.php' => ['desc' => '入庫処理', 'priority' => 9],
        'ride_records.php' => ['desc' => '乗車記録管理', 'priority' => 9],
        'user_management.php' => ['desc' => 'ユーザー管理', 'priority' => 7],
        'vehicle_management.php' => ['desc' => '車両管理', 'priority' => 7]
    ];
    
    if (isset($core_files[$filename])) {
        $analysis['category'] = 'core_system';
        $analysis['status'] = 'critical';
        $analysis['priority'] = $core_files[$filename]['priority'];
        $analysis['description'] = $core_files[$filename]['desc'];
        $analysis['recommendation'] = '🔒 絶対削除禁止 - システムの核心機能';
        return $analysis;
    }
    
    // 2. 新機能（テスト段階）
    $new_features = [
        'cash_management.php' => '集金管理機能',
        'annual_report.php' => '陸運局提出機能',
        'accident_management.php' => '事故管理機能'
    ];
    
    if (isset($new_features[$filename])) {
        $analysis['category'] = 'new_features';
        $analysis['status'] = 'testing';
        $analysis['priority'] = 6;
        $analysis['description'] = $new_features[$filename];
        $analysis['recommendation'] = '🧪 テスト完了後に判断';
        return $analysis;
    }
    
    // 3. 重複ファイル（削除推奨）
    $duplicates = [
        'index_improved.php' => ['original' => 'index.php', 'reason' => '改良版だが本体と機能重複'],
        'dashboard_debug.php' => ['original' => 'dashboard.php', 'reason' => 'デバッグ版'],
        'fixed_export_document.php' => ['original' => 'export_document.php', 'reason' => '修正版だが重複']
    ];
    
    if (isset($duplicates[$filename])) {
        $analysis['category'] = 'redundant';
        $analysis['status'] = 'redundant';
        $analysis['priority'] = 2;
        $analysis['original'] = $duplicates[$filename]['original'];
        $analysis['description'] = $duplicates[$filename]['reason'];
        $analysis['recommendation'] = '🗑️ 削除推奨 - 重複ファイル';
        return $analysis;
    }
    
    // 4. 保守・修正ツール
    if (preg_match('/^(fix_|setup_|check_|debug_|temp_|test_)/', $filename)) {
        $analysis['category'] = 'maintenance';
        $analysis['status'] = 'utility';
        $analysis['priority'] = 3;
        $analysis['description'] = '保守・修正・テスト用ツール';
        
        if (strpos($filename, 'temp_') === 0 || strpos($filename, 'test_') === 0) {
            $analysis['recommendation'] = '🧹 削除候補 - 一時ファイル';
        } else {
            $analysis['recommendation'] = '🔧 必要時のみ保持';
        }
        return $analysis;
    }
    
    // 5. 日本語ツール
    if (preg_match('/[\x{4e00}-\x{9faf}]/u', $filename)) {
        $analysis['category'] = 'maintenance';
        $analysis['status'] = 'specialized';
        $analysis['priority'] = 2;
        $analysis['description'] = '日本語専用診断ツール';
        $analysis['recommendation'] = '🔧 特殊用途 - 必要性要確認';
        return $analysis;
    }
    
    // 6. 緊急監査システム（問題あり）
    if (strpos($filename, 'audit') !== false || strpos($filename, 'emergency') !== false) {
        $analysis['category'] = 'problematic';
        $analysis['status'] = 'problematic';
        $analysis['priority'] = 4;
        $analysis['description'] = '緊急監査対応システム';
        $analysis['recommendation'] = '⚠️ 課題あり - 修正または削除検討';
        return $analysis;
    }
    
    // 7. コンテンツ分析による追加判定
    if ($content) {
        // デバッグコードを含むファイル
        if (strpos($content, 'var_dump') !== false || 
            strpos($content, 'print_r') !== false ||
            strpos($content, 'echo "DEBUG"') !== false) {
            $analysis['category'] = 'cleanup';
            $analysis['status'] = 'cleanup_candidate';
            $analysis['priority'] = 1;
            $analysis['description'] = 'デバッグコードを含むファイル';
            $analysis['recommendation'] = '🧹 削除推奨 - デバッグファイル';
            $analysis['issues'][] = 'debug_code';
            return $analysis;
        }
        
        // 機能検出
        if (strpos($content, 'CREATE TABLE') !== false) {
            $analysis['features'][] = 'database_setup';
        }
        if (strpos($content, 'bootstrap') !== false) {
            $analysis['features'][] = 'bootstrap_ui';
        }
        if (strpos($content, 'session_start') !== false) {
            $analysis['features'][] = 'session_mgmt';
        }
    }
    
    // 8. デフォルト（完成機能）
    $analysis['category'] = 'completed';
    $analysis['status'] = 'completed';
    $analysis['priority'] = 5;
    $analysis['description'] = '完成機能';
    $analysis['recommendation'] = '✅ 保持 - 完成機能';
    
    return $analysis;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - GitHub Raw分析ツール v4.0</title>
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
        .progress-step { margin: 0.5rem 0; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem; }
        .file-url { font-size: 0.8em; color: #6c757d; word-break: break-all; }
        .recommendation { font-weight: bold; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1><i class="fas fa-search"></i> GitHub Raw分析ツール v4.0</h1>
                <p class="text-muted">
                    <strong>手順:</strong> 
                    1️⃣ GitHub APIでファイル名一覧取得 → 
                    2️⃣ 各ファイルのraw URL生成 → 
                    3️⃣ 内容取得・分析
                </p>
                
                <div id="analysis-progress" class="loading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">分析中...</span>
                    </div>
                    <div id="progress-messages" class="mt-3" style="max-height: 300px; overflow-y: auto;">
                        <p>分析を開始します...</p>
                    </div>
                </div>
                
                <div id="analysis-results" style="display: none;">
                    <!-- 分析結果 -->
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateProgress(message, type = 'info') {
            const messagesDiv = document.getElementById('progress-messages');
            const time = new Date().toLocaleTimeString();
            const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
            messagesDiv.innerHTML += `<div class="progress-step">${icon} <small>[${time}]</small> ${message}</div>`;
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
        
        async function runAnalysis() {
            const progressDiv = document.getElementById('analysis-progress');
            const resultsDiv = document.getElementById('analysis-results');
            
            try {
                updateProgress('GitHub APIでファイル一覧を取得中...');
                
                const response = await fetch(window.location.href + '?action=analyze');
                
                if (response.ok) {
                    const results = await response.json();
                    
                    if (results.error) {
                        throw new Error(results.message || '分析エラー');
                    }
                    
                    updateProgress(`分析完了! ${results.total_files || 0}ファイルを分析しました`, 'success');
                    displayResults(results);
                    progressDiv.style.display = 'none';
                    resultsDiv.style.display = 'block';
                } else {
                    const errorText = await response.text();
                    throw new Error(`HTTP ${response.status}: ${errorText}`);
                }
            } catch (error) {
                updateProgress(`エラー: ${error.message}`, 'error');
                progressDiv.innerHTML += `
                    <div class="alert alert-danger mt-3">
                        <h5><i class="fas fa-exclamation-triangle"></i> 分析エラー</h5>
                        <p><strong>エラー内容:</strong> ${error.message}</p>
                        <button class="btn btn-warning" onclick="location.reload()">再試行</button>
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
                
                <!-- 削除推奨（最重要） -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h2><i class="fas fa-trash text-danger"></i> 🗑️ 削除推奨ファイル</h2>
                        ${generateDeletionSection(results)}
                    </div>
                </div>
                
                <!-- 核心システム -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h3><i class="fas fa-shield-alt text-success"></i> 🛡️ 核心システム（削除絶対禁止）</h3>
                        ${generateDetailedFileList(results.core_system)}
                    </div>
                </div>
                
                <!-- その他カテゴリ -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <h4><i class="fas fa-flask text-warning"></i> 🧪 新機能（テスト段階）</h4>
                        ${generateDetailedFileList(results.new_features)}
                    </div>
                    <div class="col-md-6 mb-4">
                        <h4><i class="fas fa-tools text-info"></i> 🔧 保守ツール</h4>
                        ${generateDetailedFileList(results.maintenance)}
                    </div>
                </div>
                
                ${results.access_info ? `
                <div class="row">
                    <div class="col-12">
                        <h4><i class="fas fa-info-circle"></i> アクセス情報</h4>
                        <div class="alert alert-info">
                            <p><strong>成功パターン:</strong> ${results.access_info.pattern || '不明'}</p>
                            <p><strong>API URL:</strong> <code>${results.access_info.api_url || '不明'}</code></p>
                            <p><strong>Raw ベースURL:</strong> <code>${results.access_info.raw_base || '不明'}</code></p>
                        </div>
                    </div>
                </div>
                ` : ''}
            `;
            
            resultsDiv.innerHTML = html;
        }
        
        function generateSummary(results) {
            const total = results.total_files || 0;
            const core = (results.core_system || []).length;
            const redundant = (results.redundant || []).length;
            const cleanup = (results.cleanup || []).length;
            const deletable = redundant + cleanup;
            const deletablePercent = total > 0 ? Math.round((deletable / total) * 100) : 0;
            
            return `
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4><i class="fas fa-chart-pie"></i> 分析結果サマリー</h4>
                                <div class="row">
                                    <div class="col-sm-6">
                                        <ul class="list-unstyled mb-0">
                                            <li><i class="fas fa-folder"></i> <strong>総ファイル数:</strong> ${total}</li>
                                            <li><i class="fas fa-shield-alt"></i> <strong>核心システム:</strong> ${core}ファイル</li>
                                        </ul>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="list-unstyled mb-0">
                                            <li><i class="fas fa-trash"></i> <strong>削除推奨:</strong> ${deletable}ファイル</li>
                                            <li><i class="fas fa-tools"></i> <strong>保守ツール:</strong> ${(results.maintenance || []).length}ファイル</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="bg-white p-3 rounded text-dark">
                                    <h1 class="display-4 mb-0 text-success">${deletablePercent}%</h1>
                                    <small>削除可能率</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function generateDeletionSection(results) {
            const redundant = results.redundant || [];
            const cleanup = results.cleanup || [];
            const allDeletable = [...redundant, ...cleanup];
            
            if (allDeletable.length === 0) {
                return `
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle"></i> 削除候補なし</h5>
                        <p>システムは既に整理されており、明確な削除候補ファイルは見つかりませんでした。</p>
                    </div>
                `;
            }
            
            return `
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle"></i> ${allDeletable.length}個のファイルが削除候補です</h5>
                    <p>以下のファイルは安全に削除できると判断されます。</p>
                </div>
                ${generateDetailedFileList(allDeletable)}
                <div class="mt-3">
                    <button class="btn btn-outline-danger" onclick="showDeletionCommands(${JSON.stringify(allDeletable.map(f => f.filename))})">
                        <i class="fas fa-terminal"></i> 削除コマンド表示
                    </button>
                </div>
            `;
        }
        
        function generateDetailedFileList(files) {
            if (!files || files.length === 0) {
                return '<p class="text-muted">該当ファイルなし</p>';
            }
            
            return `
                <div class="row">
                    ${files.map(file => `
                        <div class="col-lg-6 mb-3">
                            <div class="card analysis-card status-${file.status} h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-file-code"></i> ${file.filename}
                                        </h6>
                                        <span class="badge bg-secondary">P${file.priority}</span>
                                    </div>
                                    
                                    <p class="card-text mb-2">
                                        <small class="text-muted">${formatFileSize(file.size)} | ${file.lines} 行</small>
                                    </p>
                                    
                                    ${file.description ? `
                                        <p class="card-text mb-2">${file.description}</p>
                                    ` : ''}
                                    
                                    <div class="recommendation mb-2">
                                        ${file.recommendation || '判定なし'}
                                    </div>
                                    
                                    ${file.original ? `
                                        <div class="mb-2">
                                            <small class="text-info">
                                                <i class="fas fa-link"></i> 元ファイル: ${file.original}
                                            </small>
                                        </div>
                                    ` : ''}
                                    
                                    ${file.features && file.features.length > 0 ? `
                                        <div class="mb-2">
                                            ${file.features.map(f => `<span class="badge bg-primary me-1">${f}</span>`).join('')}
                                        </div>
                                    ` : ''}
                                    
                                    ${file.issues && file.issues.length > 0 ? `
                                        <div class="mb-2">
                                            ${file.issues.map(i => `<span class="badge bg-danger me-1">${i}</span>`).join('')}
                                        </div>
                                    ` : ''}
                                    
                                    ${file.raw_url ? `
                                        <div class="file-url">
                                            <a href="${file.raw_url}" target="_blank" class="text-decoration-none">
                                                <i class="fas fa-external-link-alt"></i> Raw表示
                                            </a>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }
        
        function showDeletionCommands(filenames) {
            const commands = filenames.map(name => `rm ${name}`).join('\n');
            
            const modal = `
                <div class="modal fade" id="deletionModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">削除コマンド</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-warning">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    <strong>注意:</strong> ファイル削除は慎重に行ってください。
                                </p>
                                <h6>削除対象ファイル (${filenames.length}個):</h6>
                                <pre class="bg-light p-3"><code>${commands}</code></pre>
                                <button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText('${commands}')">
                                    <i class="fas fa-copy"></i> コマンドをコピー
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modal);
            new bootstrap.Modal(document.getElementById('deletionModal')).show();
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Ajax分析処理
if (isset($_GET['action']) && $_GET['action'] === 'analyze') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $successful_pattern = null;
        $file_list = [];
        
        // 各パターンを試行してファイル一覧を取得
        foreach ($access_patterns as $pattern) {
            $result = getFileList($github_owner, $github_repo, $pattern['path'], $github_token);
            
            if ($
