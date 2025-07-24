<?php
/**
 * 福祉輸送管理システム ファイル一覧自動生成
 * このファイルを wts/ ディレクトリに配置して実行
 */

// 現在のディレクトリのファイルを取得
$files = glob('*.php');
$directories = ['config', 'api', 'backup'];

// ファイル情報の定義
$fileCategories = [
    '認証・基盤システム' => [
        'index.php' => ['status' => 'completed', 'description' => 'ログイン画面'],
        'dashboard.php' => ['status' => 'completed', 'description' => 'ダッシュボード'],
        'logout.php' => ['status' => 'completed', 'description' => 'ログアウト処理']
    ],
    '点呼・点検システム' => [
        'pre_duty_call.php' => ['status' => 'completed', 'description' => '乗務前点呼'],
        'post_duty_call.php' => ['status' => 'new', 'description' => '乗務後点呼 (NEW!)'],
        'daily_inspection.php' => ['status' => 'completed', 'description' => '日常点検'],
        'periodic_inspection.php' => ['status' => 'new', 'description' => '定期点検 (NEW!)']
    ],
    '運行管理システム' => [
        'departure.php' => ['status' => 'completed', 'description' => '出庫処理'],
        'arrival.php' => ['status' => 'completed', 'description' => '入庫処理'],
        'ride_records.php' => ['status' => 'completed', 'description' => '乗車記録管理'],
        'operation.php' => ['status' => 'backup', 'description' => '旧運行記録（廃止予定）']
    ],
    '緊急監査対応システム' => [
        'emergency_audit_kit.php' => ['status' => 'new', 'description' => '緊急監査対応キット (NEW!)'],
        'adaptive_export_document.php' => ['status' => 'completed', 'description' => '適応型出力システム'],
        'audit_data_manager.php' => ['status' => 'completed', 'description' => '監査データ一括管理'],
        'fix_table_structure.php' => ['status' => 'completed', 'description' => 'テーブル構造自動修正'],
        'check_table_structure.php' => ['status' => 'completed', 'description' => 'テーブル構造確認'],
        'simple_audit_setup.php' => ['status' => 'completed', 'description' => '簡易セットアップ']
    ],
    'マスタ・管理機能' => [
        'user_management.php' => ['status' => 'completed', 'description' => 'ユーザー管理'],
        'vehicle_management.php' => ['status' => 'completed', 'description' => '車両管理'],
        'master_menu.php' => ['status' => 'completed', 'description' => 'マスターメニュー']
    ],
    '未実装機能（残り5%）' => [
        'cash_management.php' => ['status' => 'pending', 'description' => '集金管理機能'],
        'annual_report.php' => ['status' => 'pending', 'description' => '陸運局提出機能'],
        'accident_management.php' => ['status' => 'pending', 'description' => '事故管理機能']
    ]
];

// 統計計算
$totalFiles = 0;
$completedFiles = 0;
$newFiles = 0;
$pendingFiles = 0;

foreach ($fileCategories as $category => $categoryFiles) {
    foreach ($categoryFiles as $filename => $info) {
        $totalFiles++;
        switch ($info['status']) {
            case 'completed':
                $completedFiles++;
                break;
            case 'new':
                $newFiles++;
                break;
            case 'pending':
                $pendingFiles++;
                break;
        }
    }
}

// JSON形式で出力
$result = [
    'repository' => 'hipper-gif/wts',
    'scan_date' => date('Y-m-d H:i:s'),
    'statistics' => [
        'total_files' => $totalFiles,
        'completed_files' => $completedFiles,
        'new_files' => $newFiles,
        'pending_files' => $pendingFiles,
        'completion_rate' => round(($completedFiles + $newFiles) / $totalFiles * 100, 1)
    ],
    'files_by_category' => [],
    'actual_files' => [],
    'directories' => []
];

// カテゴリ別ファイル情報
foreach ($fileCategories as $category => $categoryFiles) {
    $result['files_by_category'][$category] = [];
    foreach ($categoryFiles as $filename => $info) {
        $exists = file_exists($filename);
        $size = $exists ? filesize($filename) : 0;
        
        $result['files_by_category'][$category][] = [
            'filename' => $filename,
            'description' => $info['description'],
            'status' => $info['status'],
            'exists' => $exists,
            'size' => $size,
            'raw_url' => "https://raw.githubusercontent.com/hipper-gif/wts/main/wts/{$filename}",
            'github_url' => "https://github.com/hipper-gif/wts/blob/main/wts/{$filename}"
        ];
    }
}

// 実際に存在するファイル一覧
foreach ($files as $file) {
    if ($file !== basename(__FILE__)) { // このスクリプト自体は除外
        $result['actual_files'][] = [
            'filename' => $file,
            'size' => filesize($file),
            'modified' => date('Y-m-d H:i:s', filemtime($file)),
            'raw_url' => "https://raw.githubusercontent.com/hipper-gif/wts/main/wts/{$file}",
            'github_url' => "https://github.com/hipper-gif/wts/blob/main/wts/{$file}"
        ];
    }
}

// ディレクトリ情報
foreach ($directories as $dir) {
    if (is_dir($dir)) {
        $dirFiles = glob("{$dir}/*");
        $result['directories'][$dir] = [
            'file_count' => count($dirFiles),
            'files' => array_map('basename', $dirFiles)
        ];
    }
}

// Web APIとして使用する場合
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// HTML出力（デフォルト）
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>福祉輸送管理システム - ファイル一覧</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-completed { border-left: 4px solid #28a745; }
        .status-new { border-left: 4px solid #007bff; }
        .status-pending { border-left: 4px solid #ffc107; }
        .status-backup { border-left: 4px solid #6c757d; }
        .file-item { 
            border: 1px solid #dee2e6; 
            border-radius: 8px; 
            padding: 15px; 
            margin-bottom: 10px; 
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h3><i class="fas fa-folder-open me-2"></i>福祉輸送管理システム - ファイル一覧</h3>
                <p class="mb-0">最終更新: <?= htmlspecialchars($result['scan_date']) ?></p>
            </div>
            <div class="card-body">
                <!-- 統計情報 -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h2 class="text-primary"><?= $result['statistics']['completion_rate'] ?>%</h2>
                                <p class="card-text">完成度</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h2 class="text-success"><?= $result['statistics']['completed_files'] + $result['statistics']['new_files'] ?></h2>
                                <p class="card-text">実装済み</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h2 class="text-warning"><?= $result['statistics']['pending_files'] ?></h2>
                                <p class="card-text">未実装</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h2 class="text-info"><?= $result['statistics']['total_files'] ?></h2>
                                <p class="card-text">総ファイル数</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ファイル一覧 -->
                <?php foreach ($result['files_by_category'] as $category => $files_in_category): ?>
                    <h5 class="mt-4 mb-3">
                        <i class="fas fa-folder me-2"></i><?= htmlspecialchars($category) ?>
                        <span class="badge bg-secondary ms-2"><?= count($files_in_category) ?>個</span>
                    </h5>
                    
                    <?php foreach ($files_in_category as $file): ?>
                        <?php
                        $statusClass = 'status-' . $file['status'];
                        $statusIcons = [
                            'completed' => '<i class="fas fa-check-circle text-success"></i>',
                            'new' => '<i class="fas fa-star text-primary"></i>',
                            'pending' => '<i class="fas fa-clock text-warning"></i>',
                            'backup' => '<i class="fas fa-archive text-secondary"></i>'
                        ];
                        $statusIcon = isset($statusIcons[$file['status']]) ? $statusIcons[$file['status']] : '<i class="fas fa-file text-muted"></i>';
                        ?>
                        
                        <div class="file-item <?= $statusClass ?>">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h6 class="mb-1">
                                        <?= $statusIcon ?> <?= htmlspecialchars($file['filename']) ?>
                                        <?php if ($file['status'] === 'new'): ?>
                                            <span class="badge bg-primary ms-2">NEW!</span>
                                        <?php endif; ?>
                                        <?php if (!$file['exists']): ?>
                                            <span class="badge bg-danger ms-2">未作成</span>
                                        <?php endif; ?>
                                    </h6>
                                    <p class="text-muted mb-0"><?= htmlspecialchars($file['description']) ?></p>
                                    <?php if ($file['exists']): ?>
                                        <small class="text-muted">Size: <?= number_format($file['size']) ?> bytes</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4 text-end">
                                    <?php if ($file['exists']): ?>
                                        <div class="btn-group" role="group">
                                            <a href="<?= htmlspecialchars($file['raw_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-code me-1"></i>Raw
                                            </a>
                                            <a href="<?= htmlspecialchars($file['github_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                <i class="fab fa-github me-1"></i>GitHub
                                            </a>
                                            <button class="btn btn-sm btn-outline-success" onclick="copyUrl('<?= htmlspecialchars($file['raw_url']) ?>')">
                                                <i class="fas fa-copy me-1"></i>Copy
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">未作成</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- 実際のファイル一覧 -->
                <h5 class="mt-5 mb-3">
                    <i class="fas fa-server me-2"></i>実際のファイル一覧
                    <span class="badge bg-info ms-2"><?= count($result['actual_files']) ?>個</span>
                </h5>
                <div class="row">
                    <?php foreach ($result['actual_files'] as $file): ?>
                        <div class="col-md-6 mb-2">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($file['filename']) ?></strong><br>
                                            <small class="text-muted"><?= number_format($file['size']) ?> bytes | <?= htmlspecialchars($file['modified']) ?></small>
                                        </div>
                                        <div class="btn-group" role="group">
                                            <a href="<?= htmlspecialchars($file['raw_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-code"></i>
                                            </a>
                                            <a href="<?= htmlspecialchars($file['github_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                <i class="fab fa-github"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ディレクトリ情報 -->
                <?php if (!empty($result['directories'])): ?>
                    <h5 class="mt-4 mb-3">
                        <i class="fas fa-folder me-2"></i>ディレクトリ構造
                    </h5>
                    <?php foreach ($result['directories'] as $dir => $info): ?>
                        <div class="card mb-2">
                            <div class="card-header">
                                <strong><?= htmlspecialchars($dir) ?>/</strong>
                                <span class="badge bg-secondary ms-2"><?= $info['file_count'] ?>個</span>
                            </div>
                            <div class="card-body">
                                <?php foreach ($info['files'] as $dirFile): ?>
                                    <span class="badge bg-light text-dark me-2 mb-1"><?= htmlspecialchars($dirFile) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- API情報 -->
                <div class="mt-4 p-3 bg-light rounded">
                    <h6><i class="fas fa-api me-2"></i>API として使用</h6>
                    <p class="mb-2">このページをAPIとして使用する場合：</p>
                    <?php 
                    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                    $apiUrl = $currentUrl . (strpos($currentUrl, '?') !== false ? '&' : '?') . 'format=json';
                    ?>
                    <code><?= htmlspecialchars($apiUrl) ?></code>
                    <button class="btn btn-sm btn-outline-primary ms-2" onclick="copyUrl('<?= htmlspecialchars($apiUrl) ?>')">
                        <i class="fas fa-copy"></i> Copy API URL
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copyUrl(url) {
            navigator.clipboard.writeText(url).then(() => {
                alert('URL をクリップボードにコピーしました');
            }).catch(() => {
                // フォールバック
                const textArea = document.createElement('textarea');
                textArea.value = url;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('URL をクリップボードにコピーしました');
            });
        }

        // 自動更新（5分ごと）
        setTimeout(() => {
            location.reload();
        }, 5 * 60 * 1000);
    </script>
</body>
</html>
