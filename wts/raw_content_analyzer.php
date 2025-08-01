<?php
/**
 * Raw Content Analyzer
 * 福祉輸送管理システム - ファイル内容詳細分析ツール
 * 
 * GitHub rawリンクからファイル内容を取得し、詳細分析を行います
 */

// 設定
$repo_owner = 'hipper-gif';
$repo_name = 'wts';
$directory = 'wts';

/**
 * Raw URLからファイル内容を取得
 */
function fetchRawContent($raw_url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $raw_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WTS-Content-Analyzer/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("Failed to fetch raw content: HTTP {$http_code}");
    }
    
    return $content;
}

/**
 * PHPファイルの詳細分析
 */
function analyzePHPFile($content, $filename) {
    $analysis = [
        'type' => 'PHP',
        'size' => strlen($content),
        'lines' => substr_count($content, "\n") + 1,
        'functions' => [],
        'classes' => [],
        'includes' => [],
        'database_tables' => [],
        'html_forms' => [],
        'implementation_status' => 'unknown',
        'complexity_score' => 0,
        'security_issues' => [],
        'dependencies' => [],
        'todo_comments' => [],
        'deletion_candidate' => false
    ];
    
    // 関数の検出
    preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $matches);
    $analysis['functions'] = $matches[1];
    
    // クラスの検出
    preg_match_all('/class\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\{/', $content, $matches);
    $analysis['classes'] = $matches[1];
    
    // Include/Requireの検出
    preg_match_all('/(include|require)(_once)?\s*[\'"]([^\'"]+)[\'"]/', $content, $matches);
    $analysis['includes'] = array_unique($matches[3]);
    
    // データベーステーブルの検出
    preg_match_all('/(?:FROM|JOIN|INTO|UPDATE)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $content, $matches);
    $analysis['database_tables'] = array_unique($matches[1]);
    
    // HTMLフォームの検出
    preg_match_all('/<form[^>]*method=[\'"]([^\'"]*)[\'"][^>]*>/', $content, $matches);
    $analysis['html_forms'] = $matches[1];
    
    // TODOコメントの検出
    preg_match_all('/\/\/\s*(TODO|FIXME|HACK|XXX):?\s*(.+)/i', $content, $matches);
    for ($i = 0; $i < count($matches[0]); $i++) {
        $analysis['todo_comments'][] = [
            'type' => $matches[1][$i],
            'comment' => trim($matches[2][$i])
        ];
    }
    
    // セキュリティ問題の検出
    $security_patterns = [
        '/\$_GET\[.*?\](?!\s*[,)]|\s*\?\s*:)/' => 'Potential XSS: Direct $_GET usage',
        '/\$_POST\[.*?\](?!\s*[,)]|\s*\?\s*:)/' => 'Potential XSS: Direct $_POST usage',
        '/mysql_query\s*\(/' => 'Deprecated: mysql_query usage',
        '/eval\s*\(/' => 'Security Risk: eval() usage',
        '/system\s*\(/' => 'Security Risk: system() call',
        '/exec\s*\(/' => 'Security Risk: exec() call',
    ];
    
    foreach ($security_patterns as $pattern => $issue) {
        if (preg_match($pattern, $content)) {
            $analysis['security_issues'][] = $issue;
        }
    }
    
    // 実装状況の判定
    $analysis['implementation_status'] = determineImplementationStatus($content, $filename);
    
    // 複雑度スコアの計算
    $analysis['complexity_score'] = calculateComplexityScore($content, $analysis);
    
    // 削除候補の判定
    $analysis['deletion_candidate'] = isDeletionCandidate($content, $filename, $analysis);
    
    return $analysis;
}

/**
 * 実装状況の判定
 */
function determineImplementationStatus($content, $filename) {
    // テスト・デバッグファイル
    if (preg_match('/(test|debug|temp|backup)/', $filename)) {
        return 'test_debug';
    }
    
    // TODO/FIXMEが多い場合
    $todo_count = preg_match_all('/\/\/\s*(TODO|FIXME|HACK|XXX)/i', $content);
    if ($todo_count > 5) {
        return 'in_development';
    }
    
    // 基本的な構造があるか
    if (preg_match('/function\s+\w+|class\s+\w+/', $content) && strlen($content) > 1000) {
        return 'implemented';
    }
    
    // 空または非常に小さいファイル
    if (strlen(trim($content)) < 100) {
        return 'placeholder';
    }
    
    return 'partial';
}

/**
 * 複雑度スコアの計算
 */
function calculateComplexityScore($content, $analysis) {
    $score = 0;
    
    // ファイルサイズ (10KB = 1ポイント)
    $score += strlen($content) / 10240;
    
    // 関数数 (1関数 = 2ポイント)
    $score += count($analysis['functions']) * 2;
    
    // データベーステーブル数 (1テーブル = 3ポイント)
    $score += count($analysis['database_tables']) * 3;
    
    // HTMLフォーム数 (1フォーム = 5ポイント)
    $score += count($analysis['html_forms']) * 5;
    
    // セキュリティ問題 (1問題 = -5ポイント)
    $score -= count($analysis['security_issues']) * 5;
    
    return round($score, 1);
}

/**
 * 削除候補の判定
 */
function isDeletionCandidate($content, $filename, $analysis) {
    // テスト・デバッグファイル
    if (preg_match('/(test|debug|temp|backup|fix|check)/', $filename)) {
        return true;
    }
    
    // 日本語ファイル（ツール類）
    if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $filename)) {
        return true;
    }
    
    // 非常に小さいファイル（100行未満）
    if ($analysis['lines'] < 100 && !in_array($filename, ['index.php', 'logout.php'])) {
        return true;
    }
    
    // セキュリティ問題が多い
    if (count($analysis['security_issues']) > 3) {
        return true;
    }
    
    return false;
}

/**
 * HTMLファイルの分析
 */
function analyzeHTMLFile($content, $filename) {
    return [
        'type' => 'HTML',
        'size' => strlen($content),
        'lines' => substr_count($content, "\n") + 1,
        'deletion_candidate' => true // HTMLファイルは基本的に削除候補
    ];
}

/**
 * その他ファイルの分析
 */
function analyzeOtherFile($content, $filename) {
    return [
        'type' => 'Other',
        'size' => strlen($content),
        'lines' => substr_count($content, "\n") + 1,
        'deletion_candidate' => false
    ];
}

// メイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['files'])) {
    $selected_files = $_POST['files'];
    $analysis_results = [];
    
    foreach ($selected_files as $file_data) {
        $file_info = json_decode($file_data, true);
        $filename = $file_info['name'];
        $raw_url = $file_info['raw_url'];
        
        try {
            $content = fetchRawContent($raw_url);
            
            if (pathinfo($filename, PATHINFO_EXTENSION) === 'php') {
                $analysis = analyzePHPFile($content, $filename);
            } elseif (in_array(pathinfo($filename, PATHINFO_EXTENSION), ['html', 'htm'])) {
                $analysis = analyzeHTMLFile($content, $filename);
            } else {
                $analysis = analyzeOtherFile($content, $filename);
            }
            
            $analysis['filename'] = $filename;
            $analysis['raw_url'] = $raw_url;
            $analysis_results[] = $analysis;
            
        } catch (Exception $e) {
            $analysis_results[] = [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'deletion_candidate' => false
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Content Analyzer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 20px; background: #f6f8fa; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
        .file-selector { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .file-checkbox { margin: 5px 10px; }
        .analysis-result { background: #f8f9fa; border: 1px solid #e1e4e8; border-radius: 8px; margin: 15px 0; padding: 20px; }
        .filename { font-size: 1.2em; font-weight: bold; color: #0366d6; margin-bottom: 10px; }
        .status-implemented { color: #28a745; font-weight: bold; }
        .status-partial { color: #ffc107; font-weight: bold; }
        .status-test_debug { color: #6f42c1; font-weight: bold; }
        .status-placeholder { color: #dc3545; font-weight: bold; }
        .deletion-candidate { background: #ffe6e6; border-color: #ff9999; }
        .complexity-high { color: #dc3545; }
        .complexity-medium { color: #ffc107; }
        .complexity-low { color: #28a745; }
        .security-issue { background: #fff5f5; border: 1px solid #fed7d7; border-radius: 4px; padding: 8px; margin: 5px 0; color: #c53030; }
        .btn { padding: 10px 20px; background: #0366d6; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .btn:hover { background: #0256cc; }
        .summary-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-card { background: #f8f9fa; border: 1px solid #e1e4e8; border-radius: 8px; padding: 15px; text-align: center; }
        .deletion-list { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Raw Content Analyzer</h1>
            <p>選択したファイルの内容を詳細分析します</p>
        </div>

        <?php if (!isset($analysis_results)): ?>
        <div class="file-selector">
            <h3>📁 分析するファイルを選択してください</h3>
            <p>⚠️ 大量のファイルを選択すると時間がかかります。まずは重要なファイルのみを選択することをお勧めします。</p>
            
            <form method="POST">
                <h4>🎯 コアシステムファイル（推奨）</h4>
                <?php
                $core_files = [
                    'index.php', 'dashboard.php', 'logout.php',
                    'pre_duty_call.php', 'post_duty_call.php', 'daily_inspection.php', 'periodic_inspection.php',
                    'departure.php', 'arrival.php', 'ride_records.php',
                    'cash_management.php', 'annual_report.php', 'accident_management.php',
                    'user_management.php', 'vehicle_management.php'
                ];
                
                foreach ($core_files as $file) {
                    $raw_url = "https://raw.githubusercontent.com/{$repo_owner}/{$repo_name}/main/{$directory}/{$file}";
                    $file_data = json_encode(['name' => $file, 'raw_url' => $raw_url]);
                    echo "<label class='file-checkbox'>";
                    echo "<input type='checkbox' name='files[]' value='" . htmlspecialchars($file_data) . "' checked>";
                    echo " {$file}";
                    echo "</label><br>";
                }
                ?>
                
                <h4>🔧 テスト・保守ファイル</h4>
                <?php
                $maintenance_files = [
                    'debug_data.php', 'fix_table_structure.php', 'check_table_structure.php',
                    'setup_audit_kit.php', 'emergency_audit_kit.php', 'file_scanner.php'
                ];
                
                foreach ($maintenance_files as $file) {
                    $raw_url = "https://raw.githubusercontent.com/{$repo_owner}/{$repo_name}/main/{$directory}/{$file}";
                    $file_data = json_encode(['name' => $file, 'raw_url' => $raw_url]);
                    echo "<label class='file-checkbox'>";
                    echo "<input type='checkbox' name='files[]' value='" . htmlspecialchars($file_data) . "'>";
                    echo " {$file}";
                    echo "</label><br>";
                }
                ?>
                
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn">🔍 選択したファイルを分析</button>
                </div>
            </form>
        </div>

        <?php else: ?>
        
        <!-- 分析結果の表示 -->
        <h2>📊 分析結果</h2>
        
        <?php
        // 統計計算
        $total_files = count($analysis_results);
        $deletion_candidates = array_filter($analysis_results, function($r) { return $r['deletion_candidate'] ?? false; });
        $implemented_count = count(array_filter($analysis_results, function($r) { return ($r['implementation_status'] ?? '') === 'implemented'; }));
        $total_size = array_sum(array_column($analysis_results, 'size'));
        ?>
        
        <div class="summary-stats">
            <div class="stat-card">
                <div style="font-size: 2em; font-weight: bold; color: #0366d6;"><?= $total_files ?></div>
                <div>分析ファイル数</div>
            </div>
            <div class="stat-card">
                <div style="font-size: 2em; font-weight: bold; color: #28a745;"><?= $implemented_count ?></div>
                <div>実装完了ファイル</div>
            </div>
            <div class="stat-card">
                <div style="font-size: 2em; font-weight: bold; color: #dc3545;"><?= count($deletion_candidates) ?></div>
                <div>削除候補ファイル</div>
            </div>
            <div class="stat-card">
                <div style="font-size: 2em; font-weight: bold; color: #6f42c1;"><?= round($total_size/1024, 1) ?>KB</div>
                <div>総サイズ</div>
            </div>
        </div>

        <?php if (!empty($deletion_candidates)): ?>
        <div class="deletion-list">
            <h3>🗑️ 削除候補ファイル</h3>
            <p>以下のファイルは削除候補として特定されました：</p>
            <ul>
                <?php foreach ($deletion_candidates as $file): ?>
                <li><strong><?= htmlspecialchars($file['filename']) ?></strong> - 
                    <?= isset($file['implementation_status']) ? $file['implementation_status'] : 'unknown' ?>
                    (<?= round(($file['size'] ?? 0)/1024, 1) ?>KB)
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php foreach ($analysis_results as $result): ?>
        <div class="analysis-result <?= ($result['deletion_candidate'] ?? false) ? 'deletion-candidate' : '' ?>">
            <div class="filename">
                📄 <?= htmlspecialchars($result['filename']) ?>
                <?php if ($result['deletion_candidate'] ?? false): ?>
                    <span style="color: #dc3545; font-size: 0.8em;">🗑️ 削除候補</span>
                <?php endif; ?>
            </div>
            
            <?php if (isset($result['error'])): ?>
                <div style="color: #dc3545;">❌ エラー: <?= htmlspecialchars($result['error']) ?></div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 15px 0;">
                    <div><strong>ファイルタイプ:</strong> <?= $result['type'] ?></div>
                    <div><strong>サイズ:</strong> <?= round(($result['size'] ?? 0)/1024, 1) ?> KB</div>
                    <div><strong>行数:</strong> <?= $result['lines'] ?? 0 ?></div>
                    <?php if (isset($result['implementation_status'])): ?>
                    <div><strong>実装状況:</strong> 
                        <span class="status-<?= $result['implementation_status'] ?>">
                            <?= $result['implementation_status'] ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (isset($result['complexity_score'])): ?>
                <div style="margin: 10px 0;">
                    <strong>複雑度スコア:</strong> 
                    <span class="complexity-<?= $result['complexity_score'] > 20 ? 'high' : ($result['complexity_score'] > 10 ? 'medium' : 'low') ?>">
                        <?= $result['complexity_score'] ?>
                    </span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($result['functions'])): ?>
                <div style="margin: 10px 0;">
                    <strong>関数 (<?= count($result['functions']) ?>個):</strong> 
                    <?= implode(', ', array_slice($result['functions'], 0, 5)) ?>
                    <?php if (count($result['functions']) > 5): ?>...<?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($result['database_tables'])): ?>
                <div style="margin: 10px 0;">
                    <strong>データベーステーブル:</strong> <?= implode(', ', $result['database_tables']) ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($result['security_issues'])): ?>
                <div style="margin: 10px 0;">
                    <strong>⚠️ セキュリティ問題:</strong>
                    <?php foreach ($result['security_issues'] as $issue): ?>
                        <div class="security-issue"><?= htmlspecialchars($issue) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($result['todo_comments'])): ?>
                <div style="margin: 10px 0;">
                    <strong>📝 TODO/FIXME:</strong>
                    <?php foreach (array_slice($result['todo_comments'], 0, 3) as $todo): ?>
                        <div style="color: #6f42c1;">• <?= htmlspecialchars($todo['comment']) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px;">
                    <a href="<?= htmlspecialchars($result['raw_url']) ?>" target="_blank" style="color: #0366d6;">
                        🔗 Raw Content を表示
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        
        <div style="margin-top: 30px;">
            <a href="?" class="btn">🔄 別のファイルを分析</a>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>
