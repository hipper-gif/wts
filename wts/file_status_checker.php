<?php
/**
 * ファイル状況確認ツール
 * GitHubで削除したファイルが本番環境に残っているかチェック
 */

// セキュリティ: 本番環境では削除推奨
$allowed_ips = ['127.0.0.1', '::1']; // 必要に応じて管理者IPを追加
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed_ips) && !isset($_GET['allow'])) {
    // 本番環境での実行時はパラメータ ?allow=true を追加
}

$current_dir = __DIR__;
$files = [];
$github_files = [];

// 現在のディレクトリのファイル一覧取得
function scanDirectory($dir, $prefix = '') {
    $result = [];
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $full_path = $dir . '/' . $item;
        $relative_path = $prefix . $item;
        
        if (is_dir($full_path)) {
            // サブディレクトリは config と api のみスキャン
            if (in_array($item, ['config', 'api', 'backup'])) {
                $result = array_merge($result, scanDirectory($full_path, $relative_path . '/'));
            }
        } else {
            $result[] = [
                'name' => $relative_path,
                'size' => filesize($full_path),
                'modified' => filemtime($full_path),
                'type' => pathinfo($full_path, PATHINFO_EXTENSION)
            ];
        }
    }
    
    return $result;
}

$current_files = scanDirectory($current_dir);

// GitHubの期待ファイル一覧（要件定義に基づく核心ファイル）
$expected_core_files = [
    // 基盤システム
    'index.php',
    'dashboard.php', 
    'logout.php',
    'config/database.php',
    
    // 点呼・点検
    'pre_duty_call.php',
    'post_duty_call.php',
    'daily_inspection.php',
    'periodic_inspection.php',
    
    // 運行管理
    'departure.php',
    'arrival.php',
    'ride_records.php',
    
    // 新機能（完成済み）
    'cash_management.php',
    'annual_report.php',
    'accident_management.php',
    
    // 緊急監査対応
    'emergency_audit_kit.php',
    'adaptive_export_document.php',
    'audit_data_manager.php',
    
    // マスタ管理
    'user_management.php',
    'vehicle_management.php',
    'master_menu.php',
    
    // 帳票
    'export_document.php',
    'fixed_export_document.php',
    
    // API
    'api/check_prerequisites.php',
    'api/get_previous_mileage.php'
];

// 削除対象ファイル（GitHubで削除済みのはず）
$should_be_deleted = [
    // 修正・診断ツール
    'fix_accident_table.php',
    'fix_caller_display.php',
    'fix_database_error.php',
    'check_db_structure.php',
    'debug_data.php',
    'setup_audit_kit.php',
    
    // HTMLファイル
    'file_list.html',
    'github_file_checker.html',
    
    // 日本語ツール
    '修正スクリプト.php',
    '診断ツール.php',
    '詳細分析ツール.php',
    
    // 廃止予定
    'operation.php'
];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ファイル状況確認</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4><i class="fas fa-search"></i> ファイル状況確認</h4>
                        <small>GitHubで削除したファイルが本番環境に残っているかチェック</small>
                    </div>
                    <div class="card-body">
                        
                        <!-- 統計情報 -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card bg-info text-white">
                                    <div class="card-body text-center">
                                        <h5><?php echo count($current_files); ?>個</h5>
                                        <small>現在のファイル数</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-success text-white">
                                    <div class="card-body text-center">
                                        <h5><?php echo count($expected_core_files); ?>個</h5>
                                        <small>期待される核心ファイル</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-warning text-white">
                                    <div class="card-body text-center">
                                        <?php
                                        $remaining_deleted = 0;
                                        foreach ($current_files as $file) {
                                            if (in_array($file['name'], $should_be_deleted)) {
                                                $remaining_deleted++;
                                            }
                                        }
                                        ?>
                                        <h5><?php echo $remaining_deleted; ?>個</h5>
                                        <small>削除されるべきファイル</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 削除されるべきだが残っているファイル -->
                        <?php if ($remaining_deleted > 0): ?>
                        <div class="alert alert-warning">
                            <h5><i class="fas fa-exclamation-triangle"></i> 削除が必要なファイル</h5>
                            <p>GitHubで削除済みのはずですが、まだ残っているファイルです：</p>
                            <ul>
                                <?php foreach ($current_files as $file): ?>
                                    <?php if (in_array($file['name'], $should_be_deleted)): ?>
                                        <li>
                                            <code><?php echo htmlspecialchars($file['name']); ?></code>
                                            <small class="text-muted">
                                                (<?php echo number_format($file['size']); ?> bytes, 
                                                <?php echo date('Y-m-d H:i:s', $file['modified']); ?>)
                                            </small>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        
                        <!-- 核心ファイルの確認 -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5><i class="fas fa-check-circle"></i> 核心ファイル確認</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($expected_core_files as $expected): ?>
                                        <?php 
                                        $exists = false;
                                        foreach ($current_files as $file) {
                                            if ($file['name'] === $expected) {
                                                $exists = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <div class="col-md-6 col-lg-4 mb-2">
                                            <span class="badge <?php echo $exists ? 'bg-success' : 'bg-danger'; ?>">
                                                <i class="fas <?php echo $exists ? 'fa-check' : 'fa-times'; ?>"></i>
                                                <?php echo htmlspecialchars($expected); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 全ファイル一覧 -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5><i class="fas fa-list"></i> 全ファイル一覧</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>ファイル名</th>
                                                <th>サイズ</th>
                                                <th>更新日時</th>
                                                <th>状態</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($current_files as $file): ?>
                                                <tr>
                                                    <td>
                                                        <code><?php echo htmlspecialchars($file['name']); ?></code>
                                                    </td>
                                                    <td><?php echo number_format($file['size']); ?> bytes</td>
                                                    <td><?php echo date('Y-m-d H:i:s', $file['modified']); ?></td>
                                                    <td>
                                                        <?php if (in_array($file['name'], $expected_core_files)): ?>
                                                            <span class="badge bg-success">核心</span>
                                                        <?php elseif (in_array($file['name'], $should_be_deleted)): ?>
                                                            <span class="badge bg-warning">削除対象</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">その他</span>
                                                        <?php endif; ?>
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
                
                <!-- 解決方法 -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-tools"></i> 解決方法</h5>
                    </div>
                    <div class="card-body">
                        <h6>1. エックスサーバーのファイルマネージャーで手動削除</h6>
                        <ul>
                            <li>エックスサーバーの管理画面にログイン</li>
                            <li>ファイルマネージャー → public_html/Smiley/taxi/wts/</li>
                            <li>削除対象ファイルを手動で削除</li>
                        </ul>
                        
                        <h6>2. 自動デプロイ設定の確認</h6>
                        <ul>
                            <li>GitHub Actions の設定確認</li>
                            <li>rsync の --delete オプション有効化</li>
                            <li>同期方式を「増分」から「完全同期」に変更</li>
                        </ul>
                        
                        <h6>3. 一括削除スクリプト実行</h6>
                        <p>
                            <button class="btn btn-warning" onclick="if(confirm('削除対象ファイルを一括削除しますか？')) window.location.href='?delete_obsolete=true'">
                                <i class="fas fa-trash"></i> 削除対象ファイルを一括削除
                            </button>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    // 削除処理
    if (isset($_GET['delete_obsolete']) && $_GET['delete_obsolete'] === 'true') {
        echo '<div class="container mt-4"><div class="alert alert-info"><h5>削除処理実行中...</h5><ul>';
        
        foreach ($current_files as $file) {
            if (in_array($file['name'], $should_be_deleted)) {
                $file_path = $current_dir . '/' . $file['name'];
                if (file_exists($file_path) && unlink($file_path)) {
                    echo '<li><span class="text-success">✓ 削除成功:</span> ' . htmlspecialchars($file['name']) . '</li>';
                } else {
                    echo '<li><span class="text-danger">✗ 削除失敗:</span> ' . htmlspecialchars($file['name']) . '</li>';
                }
            }
        }
        
        echo '</ul><p><a href="?" class="btn btn-primary">再読み込み</a></p></div></div>';
    }
    ?>
    
</body>
</html>
