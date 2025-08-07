<?php
/**
 * 福祉輸送管理システム - ファイル分析ツール（改良版）
 * フォルダとフォルダ内のファイルを再帰的に分析
 */

// 設定
$target_directory = '.'; // 分析対象ディレクトリ（現在のディレクトリ）
$exclude_patterns = [
    '.git',
    '.gitignore',
    'node_modules',
    'vendor',
    '.env',
    '*.log',
    'thumbs.db',
    '.DS_Store'
];

// 分析除外ファイル（自分自身も除外）
$exclude_files = [
    'file_analyzer.php',
    basename(__FILE__)
];

/**
 * ファイルサイズを人間が読みやすい形式に変換
 */
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

/**
 * ファイル拡張子を取得
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * 除外パターンに一致するかチェック
 */
function isExcluded($path, $excludePatterns) {
    foreach ($excludePatterns as $pattern) {
        if (fnmatch($pattern, basename($path)) || fnmatch($pattern, $path)) {
            return true;
        }
    }
    return false;
}

/**
 * ディレクトリを再帰的にスキャン
 */
function scanDirectoryRecursive($dir, $excludePatterns, $excludeFiles, $currentDepth = 0, $maxDepth = 10) {
    $files = [];
    $directories = [];
    
    if ($currentDepth > $maxDepth) {
        return ['files' => $files, 'directories' => $directories];
    }
    
    if (!is_dir($dir) || !is_readable($dir)) {
        return ['files' => $files, 'directories' => $directories];
    }
    
    $items = scandir($dir);
    if ($items === false) {
        return ['files' => $files, 'directories' => $directories];
    }
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
        $relativePath = ltrim(str_replace('.', '', $fullPath), '/\\');
        
        // 除外パターンチェック
        if (isExcluded($fullPath, $excludePatterns) || in_array($item, $excludeFiles)) {
            continue;
        }
        
        if (is_dir($fullPath)) {
            // ディレクトリの場合
            $dirInfo = [
                'name' => $item,
                'path' => $relativePath,
                'full_path' => $fullPath,
                'depth' => $currentDepth,
                'items_count' => 0,
                'total_size' => 0
            ];
            
            // ディレクトリ内をスキャン
            $subResult = scanDirectoryRecursive($fullPath, $excludePatterns, $excludeFiles, $currentDepth + 1, $maxDepth);
            $dirInfo['files'] = $subResult['files'];
            $dirInfo['subdirectories'] = $subResult['directories'];
            $dirInfo['items_count'] = count($subResult['files']) + count($subResult['directories']);
            
            // 総サイズ計算
            foreach ($subResult['files'] as $file) {
                $dirInfo['total_size'] += $file['size'];
            }
            foreach ($subResult['directories'] as $subdir) {
                $dirInfo['total_size'] += $subdir['total_size'];
            }
            
            $directories[] = $dirInfo;
            
        } else {
            // ファイルの場合
            if (!is_readable($fullPath)) {
                continue;
            }
            
            $fileInfo = [
                'name' => $item,
                'path' => $relativePath,
                'full_path' => $fullPath,
                'size' => filesize($fullPath),
                'extension' => getFileExtension($item),
                'modified' => filemtime($fullPath),
                'depth' => $currentDepth
            ];
            
            // ファイル分析
            $fileInfo['type'] = analyzeFileType($fileInfo);
            $fileInfo['category'] = categorizeFile($fileInfo);
            
            $files[] = $fileInfo;
        }
    }
    
    return ['files' => $files, 'directories' => $directories];
}

/**
 * ファイルタイプを分析
 */
function analyzeFileType($fileInfo) {
    $ext = $fileInfo['extension'];
    
    $types = [
        'php' => 'PHP スクリプト',
        'html' => 'HTML ファイル',
        'htm' => 'HTML ファイル',
        'css' => 'CSS スタイルシート',
        'js' => 'JavaScript',
        'json' => 'JSON データ',
        'xml' => 'XML ファイル',
        'txt' => 'テキストファイル',
        'md' => 'Markdown',
        'sql' => 'SQL スクリプト',
        'htaccess' => 'Apache 設定',
        'log' => 'ログファイル',
        'ini' => '設定ファイル',
        'conf' => '設定ファイル',
        'yml' => 'YAML 設定',
        'yaml' => 'YAML 設定',
        'jpg' => '画像ファイル',
        'jpeg' => '画像ファイル',
        'png' => '画像ファイル',
        'gif' => '画像ファイル',
        'svg' => 'SVG 画像',
        'pdf' => 'PDF ドキュメント',
        'zip' => '圧縮ファイル',
        'tar' => '圧縮ファイル',
        'gz' => '圧縮ファイル'
    ];
    
    return $types[$ext] ?? '不明なファイル';
}

/**
 * ファイルカテゴリを分類
 */
function categorizeFile($fileInfo) {
    $name = strtolower($fileInfo['name']);
    $ext = $fileInfo['extension'];
    
    // システムファイル
    if (in_array($ext, ['php', 'html', 'css', 'js'])) {
        if (strpos($name, 'index') !== false) {
            return '🔐 認証システム';
        } elseif (strpos($name, 'dashboard') !== false) {
            return '📊 ダッシュボード';
        } elseif (strpos($name, 'user') !== false || strpos($name, 'vehicle') !== false) {
            return '👥 マスタ管理';
        } elseif (strpos($name, 'pre_duty') !== false || strpos($name, 'post_duty') !== false || strpos($name, 'inspection') !== false) {
            return '🎯 点呼・点検システム';
        } elseif (strpos($name, 'departure') !== false || strpos($name, 'arrival') !== false || strpos($name, 'ride') !== false) {
            return '🚀 運行管理システム';
        } elseif (strpos($name, 'cash') !== false || strpos($name, 'annual') !== false || strpos($name, 'accident') !== false) {
            return '💰 集金・報告システム';
        } elseif (strpos($name, 'audit') !== false || strpos($name, 'emergency') !== false || strpos($name, 'export') !== false) {
            return '🚨 緊急監査対応';
        } elseif (strpos($name, 'fix') !== false || strpos($name, 'check') !== false || strpos($name, 'debug') !== false) {
            return '🔧 保守・修正ツール';
        }
        return '📄 システムファイル';
    }
    
    // 設定ファイル
    if (in_array($ext, ['ini', 'conf', 'htaccess', 'yml', 'yaml', 'json'])) {
        return '⚙️ 設定ファイル';
    }
    
    // ドキュメント
    if (in_array($ext, ['md', 'txt', 'pdf'])) {
        return '📚 ドキュメント';
    }
    
    // 画像
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg'])) {
        return '🖼️ 画像ファイル';
    }
    
    return '📋 その他';
}

// メイン処理
$analysisResult = scanDirectoryRecursive($target_directory, $exclude_patterns, $exclude_files);
$allFiles = $analysisResult['files'];
$allDirectories = $analysisResult['directories'];

// 統計計算
$totalFiles = count($allFiles);
$totalDirectories = count($allDirectories);
$totalSize = array_sum(array_column($allFiles, 'size'));

// カテゴリ別統計
$categoryStats = [];
foreach ($allFiles as $file) {
    $category = $file['category'];
    if (!isset($categoryStats[$category])) {
        $categoryStats[$category] = ['count' => 0, 'size' => 0];
    }
    $categoryStats[$category]['count']++;
    $categoryStats[$category]['size'] += $file['size'];
}

// 拡張子別統計
$extensionStats = [];
foreach ($allFiles as $file) {
    $ext = $file['extension'] ?: '(拡張子なし)';
    if (!isset($extensionStats[$ext])) {
        $extensionStats[$ext] = ['count' => 0, 'size' => 0];
    }
    $extensionStats[$ext]['count']++;
    $extensionStats[$ext]['size'] += $file['size'];
}

// ファイルをサイズ順でソート
usort($allFiles, function($a, $b) {
    return $b['size'] - $a['size'];
});

// 現在時刻
$currentTime = date('Y-m-d H:i:s');
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - ファイル分析レポート</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .analysis-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
        }
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .file-item {
            border-left: 3px solid #dee2e6;
            margin-bottom: 0.5rem;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 0 5px 5px 0;
        }
        .directory-item {
            border-left: 3px solid #28a745;
            background: #e8f5e9;
        }
        .category-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        .depth-indicator {
            margin-left: 1rem;
            border-left: 2px dashed #ccc;
            padding-left: 1rem;
        }
        .progress-custom {
            height: 8px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="analysis-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-search"></i> ファイル分析レポート</h1>
                    <p class="mb-0">福祉輸送管理システム - 完全ディレクトリ分析</p>
                </div>
                <div class="col-md-4 text-end">
                    <h5><i class="fas fa-clock"></i> <?= $currentTime ?></h5>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <!-- 統計サマリー -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="fas fa-file fa-2x text-primary mb-2"></i>
                        <h3 class="text-primary"><?= number_format($totalFiles) ?></h3>
                        <p class="mb-0">総ファイル数</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="fas fa-folder fa-2x text-success mb-2"></i>
                        <h3 class="text-success"><?= number_format($totalDirectories) ?></h3>
                        <p class="mb-0">ディレクトリ数</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="fas fa-hdd fa-2x text-warning mb-2"></i>
                        <h3 class="text-warning"><?= formatFileSize($totalSize) ?></h3>
                        <p class="mb-0">総サイズ</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="fas fa-chart-pie fa-2x text-info mb-2"></i>
                        <h3 class="text-info"><?= count($categoryStats) ?></h3>
                        <p class="mb-0">カテゴリ数</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- タブナビゲーション -->
        <ul class="nav nav-tabs" id="analysisTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tree-tab" data-bs-toggle="tab" data-bs-target="#tree" type="button">
                    <i class="fas fa-sitemap"></i> ディレクトリツリー
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="files-tab" data-bs-toggle="tab" data-bs-target="#files" type="button">
                    <i class="fas fa-list"></i> ファイル一覧
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button">
                    <i class="fas fa-tags"></i> カテゴリ別統計
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="extensions-tab" data-bs-toggle="tab" data-bs-target="#extensions" type="button">
                    <i class="fas fa-file-code"></i> 拡張子別統計
                </button>
            </li>
        </ul>

        <div class="tab-content" id="analysisTabContent">
            <!-- ディレクトリツリー -->
            <div class="tab-pane fade show active" id="tree" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <h5><i class="fas fa-sitemap"></i> ディレクトリ構造</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        function displayDirectoryTree($directories, $files, $depth = 0) {
                            // 現在の深度のディレクトリを表示
                            foreach ($directories as $dir) {
                                if ($dir['depth'] === $depth) {
                                    echo '<div class="directory-item">';
                                    echo str_repeat('<div class="depth-indicator">', $depth);
                                    echo '<i class="fas fa-folder"></i> <strong>' . htmlspecialchars($dir['name']) . '</strong>';
                                    echo ' <span class="badge bg-success">' . $dir['items_count'] . ' items</span>';
                                    echo ' <span class="badge bg-info">' . formatFileSize($dir['total_size']) . '</span>';
                                    echo str_repeat('</div>', $depth);
                                    echo '</div>';
                                    
                                    // サブディレクトリがあれば再帰表示
                                    if (!empty($dir['subdirectories'])) {
                                        displayDirectoryTree($dir['subdirectories'], $dir['files'], $depth + 1);
                                    }
                                    
                                    // このディレクトリ内のファイルを表示
                                    foreach ($dir['files'] as $file) {
                                        echo '<div class="file-item">';
                                        echo str_repeat('<div class="depth-indicator">', $depth + 1);
                                        echo '<i class="fas fa-file"></i> ' . htmlspecialchars($file['name']);
                                        echo ' <span class="badge category-badge bg-secondary">' . $file['category'] . '</span>';
                                        echo ' <span class="badge bg-light text-dark">' . formatFileSize($file['size']) . '</span>';
                                        echo str_repeat('</div>', $depth + 1);
                                        echo '</div>';
                                    }
                                }
                            }
                        }
                        
                        // ルートディレクトリのファイルを表示
                        foreach ($allFiles as $file) {
                            if ($file['depth'] === 0) {
                                echo '<div class="file-item">';
                                echo '<i class="fas fa-file"></i> ' . htmlspecialchars($file['name']);
                                echo ' <span class="badge category-badge bg-secondary">' . $file['category'] . '</span>';
                                echo ' <span class="badge bg-light text-dark">' . formatFileSize($file['size']) . '</span>';
                                echo '</div>';
                            }
                        }
                        
                        // ディレクトリツリーを表示
                        displayDirectoryTree($allDirectories, [], 0);
                        ?>
                    </div>
                </div>
            </div>

            <!-- ファイル一覧 -->
            <div class="tab-pane fade" id="files" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <h5><i class="fas fa-list"></i> 全ファイル一覧（サイズ順）</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ファイル名</th>
                                        <th>パス</th>
                                        <th>カテゴリ</th>
                                        <th>サイズ</th>
                                        <th>タイプ</th>
                                        <th>更新日時</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allFiles as $file): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-file text-muted"></i>
                                            <strong><?= htmlspecialchars($file['name']) ?></strong>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= htmlspecialchars($file['path']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge category-badge bg-secondary"><?= $file['category'] ?></span>
                                        </td>
                                        <td><?= formatFileSize($file['size']) ?></td>
                                        <td><small><?= $file['type'] ?></small></td>
                                        <td><small><?= date('Y/m/d H:i', $file['modified']) ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- カテゴリ別統計 -->
            <div class="tab-pane fade" id="categories" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <h5><i class="fas fa-tags"></i> カテゴリ別統計</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // カテゴリをサイズ順でソート
                        uasort($categoryStats, function($a, $b) {
                            return $b['size'] - $a['size'];
                        });
                        ?>
                        <?php foreach ($categoryStats as $category => $stats): ?>
                        <?php $percentage = ($stats['size'] / $totalSize) * 100; ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge category-badge bg-secondary"><?= $category ?></span>
                                <small><?= $stats['count'] ?> ファイル (<?= formatFileSize($stats['size']) ?>)</small>
                            </div>
                            <div class="progress progress-custom">
                                <div class="progress-bar" role="progressbar" style="width: <?= $percentage ?>%" 
                                     aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                </div>
                            </div>
                            <small class="text-muted"><?= number_format($percentage, 1) ?>%</small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 拡張子別統計 -->
            <div class="tab-pane fade" id="extensions" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <h5><i class="fas fa-file-code"></i> 拡張子別統計</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>拡張子</th>
                                        <th>ファイル数</th>
                                        <th>総サイズ</th>
                                        <th>平均サイズ</th>
                                        <th>比率</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // 拡張子をファイル数順でソート
                                    uasort($extensionStats, function($a, $b) {
                                        return $b['count'] - $a['count'];
                                    });
                                    ?>
                                    <?php foreach ($extensionStats as $ext => $stats): ?>
                                    <?php 
                                    $percentage = ($stats['count'] / $totalFiles) * 100;
                                    $avgSize = $stats['size'] / $stats['count'];
                                    ?>
                                    <tr>
                                        <td><code>.<?= htmlspecialchars($ext) ?></code></td>
                                        <td><?= $stats['count'] ?></td>
                                        <td><?= formatFileSize($stats['size']) ?></td>
                                        <td><?= formatFileSize($avgSize) ?></td>
                                        <td>
                                            <div class="progress progress-custom" style="width: 100px;">
                                                <div class="progress-bar bg-info" style="width: <?= $percentage ?>%"></div>
                                            </div>
                                            <small><?= number_format($percentage, 1) ?>%</small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- フッター -->
        <div class="text-center mt-4 mb-4">
            <p class="text-muted">
                <i class="fas fa-info-circle"></i> 
                福祉輸送管理システム - ファイル分析ツール v2.0
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
