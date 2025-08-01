<?php
/**
 * GitHub Repository File Analyzer
 * 福祉輸送管理システム - GitHubファイル分析ツール
 * 
 * このスクリプトはGitHub APIを使用してリポジトリのファイル一覧を取得し、
 * 各ファイルのrawリンクとファイル情報を分析・出力します。
 */

// GitHub API設定
$github_token = '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';
$repo_owner = 'hipper-gif';
$repo_name = 'wts';
$directory = 'wts'; // 対象ディレクトリ

// GitHub API URL
$api_url = "https://api.github.com/repos/{$repo_owner}/{$repo_name}/contents/{$directory}";

/**
 * cURLでGitHub APIにリクエストを送信
 */
function makeGitHubRequest($url, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $token,
        'User-Agent: WTS-File-Analyzer/1.0',
        'Accept: application/vnd.github.v3+json',
        'X-GitHub-Api-Version: 2022-11-28'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("GitHub API Error: HTTP {$http_code}");
    }
    
    return json_decode($response, true);
}

/**
 * ファイルサイズを人間が読みやすい形式に変換
 */
function formatBytes($size) {
    if ($size == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($size) / log(1024));
    return round($size / pow(1024, $i), 1) . ' ' . $units[$i];
}

/**
 * ファイル拡張子からファイルタイプを判定
 */
function getFileType($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $types = [
        'php' => '🐘 PHP',
        'html' => '🌐 HTML',
        'css' => '🎨 CSS',
        'js' => '⚡ JavaScript',
        'json' => '📄 JSON',
        'md' => '📝 Markdown',
        'txt' => '📄 Text',
        'sql' => '🗄️ SQL',
        'htaccess' => '⚙️ Config',
        '' => '📄 No Extension'
    ];
    
    return $types[$extension] ?? '📄 Other';
}

/**
 * ファイル分類を行う
 */
function categorizeFile($filename) {
    $core_files = [
        'index.php', 'dashboard.php', 'logout.php',
        'pre_duty_call.php', 'post_duty_call.php', 'daily_inspection.php', 'periodic_inspection.php',
        'departure.php', 'arrival.php', 'ride_records.php',
        'cash_management.php', 'annual_report.php', 'accident_management.php',
        'user_management.php', 'vehicle_management.php',
        'emergency_audit_kit.php', 'adaptive_export_document.php', 'audit_data_manager.php'
    ];
    
    $test_files = [
        'debug_data.php', 'add_data.php', 'test_functions.php', 'check_new_tables.php',
        'file_scanner.php', 'quick_edit.php'
    ];
    
    $setup_files = [
        'setup_audit_kit.php', 'setup_complete_system.php', 'simple_audit_setup.php',
        'fix_table_structure.php', 'fix_user_permissions.php', 'fix_system_settings.php'
    ];
    
    if (in_array($filename, $core_files)) {
        return '🎯 Core System';
    } elseif (in_array($filename, $test_files)) {
        return '🧪 Test/Debug';
    } elseif (in_array($filename, $setup_files)) {
        return '🔧 Setup/Fix';
    } elseif (strpos($filename, 'fix_') === 0) {
        return '🔧 Fix Script';
    } elseif (strpos($filename, 'check_') === 0) {
        return '🔍 Check Script';
    } elseif (strpos($filename, 'backup_') === 0) {
        return '💾 Backup';
    } elseif (strpos($filename, 'temp_') === 0) {
        return '⏳ Temporary';
    } elseif (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $filename)) {
        return '🇯🇵 Japanese Tool';
    } else {
        return '📄 Other';
    }
}

try {
    echo "<!DOCTYPE html>\n";
    echo "<html lang='ja'>\n";
    echo "<head>\n";
    echo "    <meta charset='UTF-8'>\n";
    echo "    <meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
    echo "    <title>GitHub Repository File Analyzer</title>\n";
    echo "    <style>\n";
    echo "        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 20px; background: #f6f8fa; }\n";
    echo "        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }\n";
    echo "        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 30px; }\n";
    echo "        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }\n";
    echo "        .stat-card { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; text-align: center; }\n";
    echo "        .stat-number { font-size: 2em; font-weight: bold; color: #0366d6; }\n";
    echo "        .file-table { width: 100%; border-collapse: collapse; margin-top: 20px; }\n";
    echo "        .file-table th, .file-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e1e4e8; }\n";
    echo "        .file-table th { background: #f6f8fa; font-weight: 600; position: sticky; top: 0; }\n";
    echo "        .file-table tr:hover { background: #f6f8fa; }\n";
    echo "        .category { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; white-space: nowrap; }\n";
    echo "        .core-system { background: #e6ffed; color: #28a745; }\n";
    echo "        .test-debug { background: #fff3cd; color: #856404; }\n";
    echo "        .setup-fix { background: #cce5ff; color: #0366d6; }\n";
    echo "        .japanese-tool { background: #ffebee; color: #d73a49; }\n";
    echo "        .other { background: #f1f3f4; color: #586069; }\n";
    echo "        .file-size { color: #586069; font-family: monospace; }\n";
    echo "        .raw-link { color: #0366d6; text-decoration: none; font-family: monospace; font-size: 0.9em; }\n";
    echo "        .raw-link:hover { text-decoration: underline; }\n";
    echo "        .filter-buttons { margin: 20px 0; }\n";
    echo "        .filter-btn { padding: 8px 16px; margin: 5px; border: 1px solid #d1d5da; background: white; border-radius: 6px; cursor: pointer; }\n";
    echo "        .filter-btn.active { background: #0366d6; color: white; }\n";
    echo "        .summary { background: #f6f8fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }\n";
    echo "    </style>\n";
    echo "</head>\n";
    echo "<body>\n";
    
    echo "<div class='container'>\n";
    echo "    <div class='header'>\n";
    echo "        <h1>🔍 GitHub Repository File Analyzer</h1>\n";
    echo "        <p>福祉輸送管理システム - リポジトリファイル分析ツール</p>\n";
    echo "        <p><strong>Repository:</strong> {$repo_owner}/{$repo_name}/{$directory}</p>\n";
    echo "    </div>\n";
    
    echo "    <div class='summary'>\n";
    echo "        <h2>📊 分析結果サマリー</h2>\n";
    echo "        <p>このツールはGitHub APIを使用してリポジトリ内のファイルを分析し、各ファイルの詳細情報とrawリンクを生成します。</p>\n";
    echo "    </div>\n";
    
    // GitHub APIからファイル一覧を取得
    echo "    <p>🔄 GitHub APIからファイル一覧を取得中...</p>\n";
    flush();
    
    $files = makeGitHubRequest($api_url, $github_token);
    
    // 統計情報の計算
    $total_files = count($files);
    $total_size = 0;
    $categories = [];
    $file_types = [];
    $core_files = 0;
    $test_files = 0;
    $setup_files = 0;
    $other_files = 0;
    
    foreach ($files as $file) {
        if ($file['type'] === 'file') {
            $total_size += $file['size'];
            
            $category = categorizeFile($file['name']);
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            
            $file_type = getFileType($file['name']);
            $file_types[$file_type] = ($file_types[$file_type] ?? 0) + 1;
            
            if (strpos($category, 'Core') !== false) $core_files++;
            elseif (strpos($category, 'Test') !== false) $test_files++;
            elseif (strpos($category, 'Setup') !== false || strpos($category, 'Fix') !== false) $setup_files++;
            else $other_files++;
        }
    }
    
    // 統計カード表示
    echo "    <div class='stats'>\n";
    echo "        <div class='stat-card'>\n";
    echo "            <div class='stat-number'>{$total_files}</div>\n";
    echo "            <div>総ファイル数</div>\n";
    echo "        </div>\n";
    echo "        <div class='stat-card'>\n";
    echo "            <div class='stat-number'>" . formatBytes($total_size) . "</div>\n";
    echo "            <div>総ファイルサイズ</div>\n";
    echo "        </div>\n";
    echo "        <div class='stat-card'>\n";
    echo "            <div class='stat-number'>{$core_files}</div>\n";
    echo "            <div>コアシステムファイル</div>\n";
    echo "        </div>\n";
    echo "        <div class='stat-card'>\n";
    echo "            <div class='stat-number'>" . ($test_files + $setup_files + $other_files) . "</div>\n";
    echo "            <div>補助・保守ファイル</div>\n";
    echo "        </div>\n";
    echo "    </div>\n";
    
    // フィルターボタン
    echo "    <div class='filter-buttons'>\n";
    echo "        <button class='filter-btn active' onclick=\"filterFiles('all')\">🗂️ すべて ({$total_files})</button>\n";
    foreach ($categories as $category => $count) {
        $category_class = strtolower(str_replace([' ', '/'], ['_', '_'], $category));
        echo "        <button class='filter-btn' onclick=\"filterFiles('{$category_class}')\">{$category} ({$count})</button>\n";
    }
    echo "    </div>\n";
    
    // ファイル一覧テーブル
    echo "    <table class='file-table' id='fileTable'>\n";
    echo "        <thead>\n";
    echo "            <tr>\n";
    echo "                <th>📁 ファイル名</th>\n";
    echo "                <th>📂 カテゴリー</th>\n";
    echo "                <th>📄 タイプ</th>\n";
    echo "                <th>📏 サイズ</th>\n";
    echo "                <th>🔗 Raw Link</th>\n";
    echo "            </tr>\n";
    echo "        </thead>\n";
    echo "        <tbody>\n";
    
    foreach ($files as $file) {
        if ($file['type'] === 'file') {
            $filename = htmlspecialchars($file['name']);
            $size = formatBytes($file['size']);
            $category = categorizeFile($file['name']);
            $file_type = getFileType($file['name']);
            
            // カテゴリーのCSSクラス
            $category_class = '';
            if (strpos($category, 'Core') !== false) $category_class = 'core-system';
            elseif (strpos($category, 'Test') !== false || strpos($category, 'Debug') !== false) $category_class = 'test-debug';
            elseif (strpos($category, 'Setup') !== false || strpos($category, 'Fix') !== false || strpos($category, 'Check') !== false) $category_class = 'setup-fix';
            elseif (strpos($category, 'Japanese') !== false) $category_class = 'japanese-tool';
            else $category_class = 'other';
            
            // Raw リンクの生成
            $raw_link = "https://raw.githubusercontent.com/{$repo_owner}/{$repo_name}/main/{$directory}/{$filename}";
            
            // データ属性用のカテゴリークラス
            $data_category = strtolower(str_replace([' ', '/'], ['_', '_'], $category));
            
            echo "            <tr data-category='{$data_category}'>\n";
            echo "                <td><strong>{$filename}</strong></td>\n";
            echo "                <td><span class='category {$category_class}'>{$category}</span></td>\n";
            echo "                <td>{$file_type}</td>\n";
            echo "                <td class='file-size'>{$size}</td>\n";
            echo "                <td><a href='{$raw_link}' target='_blank' class='raw-link'>📥 Raw Link</a></td>\n";
            echo "            </tr>\n";
        }
    }
    
    echo "        </tbody>\n";
    echo "    </table>\n";
    
    // ファイル分類統計
    echo "    <div style='margin-top: 30px;'>\n";
    echo "        <h3>📊 ファイル分類統計</h3>\n";
    echo "        <div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 15px;'>\n";
    
    foreach ($categories as $category => $count) {
        $percentage = round(($count / $total_files) * 100, 1);
        echo "            <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #0366d6;'>\n";
        echo "                <div style='font-weight: bold;'>{$category}</div>\n";
        echo "                <div style='color: #586069;'>{$count} ファイル ({$percentage}%)</div>\n";
        echo "            </div>\n";
    }
    
    echo "        </div>\n";
    echo "    </div>\n";
    
    // 重要な発見事項
    echo "    <div style='margin-top: 30px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px;'>\n";
    echo "        <h3>⚠️ 重要な発見事項</h3>\n";
    echo "        <ul>\n";
    echo "            <li><strong>コアシステムファイル:</strong> {$core_files}個のメイン機能ファイルが存在</li>\n";
    echo "            <li><strong>保守・補助ファイル:</strong> " . ($total_files - $core_files) . "個の保守・テスト・修正用ファイルが存在</li>\n";
    echo "            <li><strong>システム完成度:</strong> コアシステムファイルの存在から、システムは高い完成度と推測</li>\n";
    echo "            <li><strong>保守性:</strong> 多数の修正・セットアップツールにより、高い保守性を確保</li>\n";
    echo "        </ul>\n";
    echo "    </div>\n";
    
    echo "</div>\n";
    
    // JavaScript for filtering
    echo "<script>\n";
    echo "function filterFiles(category) {\n";
    echo "    const rows = document.querySelectorAll('#fileTable tbody tr');\n";
    echo "    const buttons = document.querySelectorAll('.filter-btn');\n";
    echo "    \n";
    echo "    buttons.forEach(btn => btn.classList.remove('active'));\n";
    echo "    event.target.classList.add('active');\n";
    echo "    \n";
    echo "    rows.forEach(row => {\n";
    echo "        if (category === 'all' || row.dataset.category === category) {\n";
    echo "            row.style.display = '';\n";
    echo "        } else {\n";
    echo "            row.style.display = 'none';\n";
    echo "        }\n";
    echo "    });\n";
    echo "}\n";
    echo "</script>\n";
    
    echo "</body>\n";
    echo "</html>\n";
    
} catch (Exception $e) {
    echo "<div style='color: red; padding: 20px; background: #ffe6e6; border-radius: 5px; margin: 20px;'>\n";
    echo "<h3>❌ エラーが発生しました</h3>\n";
    echo "<p><strong>エラー詳細:</strong> " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<h4>考えられる原因:</h4>\n";
    echo "<ul>\n";
    echo "<li>GitHub APIトークンが無効または期限切れ</li>\n";
    echo "<li>リポジトリが存在しないまたはアクセス権限がない</li>\n";
    echo "<li>ネットワーク接続の問題</li>\n";
    echo "<li>GitHub APIの利用制限に達している</li>\n";
    echo "</ul>\n";
    echo "<h4>解決方法:</h4>\n";
    echo "<ol>\n";
    echo "<li>GitHub Personal Access Tokenが正しく設定されているか確認</li>\n";
    echo "<li>トークンにリポジトリへの読み取り権限があるか確認</li>\n";
    echo "<li>リポジトリ名・オーナー名が正しいか確認</li>\n";
    echo "<li>しばらく時間をおいてから再試行</li>\n";
    echo "</ol>\n";
    echo "</div>\n";
}
?>
