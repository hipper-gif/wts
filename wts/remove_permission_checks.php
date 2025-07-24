<?php
/**
 * 権限チェック削除スクリプト
 * 集金管理・陸運局提出・事故管理から権限チェックを削除して全ユーザーアクセス可能にする
 */

session_start();

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ]
    );
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

$results = [];

function addResult($message, $success = true) {
    global $results;
    $results[] = [
        'message' => $message,
        'success' => $success,
        'time' => date('H:i:s')
    ];
}

addResult("権限チェック削除処理を開始します", true);

// ファイル修正処理
$files_to_modify = [
    'cash_management.php' => '集金管理',
    'annual_report.php' => '陸運局提出',
    'accident_management.php' => '事故管理'
];

foreach ($files_to_modify as $filename => $description) {
    addResult("{$description}ファイル（{$filename}）の権限チェックを削除中...", true);
    
    $filepath = __DIR__ . '/' . $filename;
    
    if (file_exists($filepath)) {
        $content = file_get_contents($filepath);
        
        // 権限チェック部分を削除
        $original_content = $content;
        
        // 権限チェックのパターンを削除
        $patterns_to_remove = [
            // 権限チェック全体を削除
            '/\/\/ 権限チェック.*?exit\(\);\s*}/s',
            '/if \(!in_array\(\$user\[\'role\'\].*?exit\(\);\s*}/s',
            '/if \(!\$user.*?exit\(\);\s*}/s',
            // 権限エラーメッセージを削除
            '/die\(\'アクセス権限がありません。.*?\'\);/',
            // 具体的な権限チェック削除
            '/if \(!in_array\(\$user\[\'role\'\], \[\'管理者\', \'システム管理者\'\]\)\) \{[^}]*die\([^}]*\);[^}]*\}/s'
        ];
        
        foreach ($patterns_to_remove as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }
        
        // より確実に権限チェック部分を削除
        $lines = explode("\n", $content);
        $modified_lines = [];
        $skip_mode = false;
        
        foreach ($lines as $line) {
            // 権限チェック開始を検出
            if (strpos($line, '権限チェック') !== false || 
                strpos($line, 'アクセス権限がありません') !== false ||
                preg_match('/if\s*\(\s*!\s*in_array\s*\(\s*\$user\s*\[\s*[\'"]role/', $line)) {
                $skip_mode = true;
                continue;
            }
            
            // 權限チェック終了を検出
            if ($skip_mode && (strpos($line, '}') !== false || strpos($line, 'exit()') !== false)) {
                $skip_mode = false;
                continue;
            }
            
            // スキップモード中でなければ行を保持
            if (!$skip_mode) {
                $modified_lines[] = $line;
            }
        }
        
        $content = implode("\n", $modified_lines);
        
        // 変更があった場合のみファイル更新
        if ($content !== $original_content) {
            file_put_contents($filepath, $content);
            addResult("✓ {$description}の権限チェックを削除しました", true);
        } else {
            addResult("- {$description}には権限チェックが見つかりませんでした", true);
        }
    } else {
        addResult("⚠ {$description}ファイルが見つかりません", false);
    }
}

addResult("権限チェック削除処理が完了しました", true);

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>権限チェック削除 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .remove-header {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .result-item {
            padding: 0.5rem 1rem;
            margin: 0.25rem 0;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .result-success {
            background-color: #d1edff;
            border-left-color: #28a745;
        }
        .result-error {
            background-color: #f8d7da;
            border-left-color: #dc3545;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-4">
        <!-- ヘッダー -->
        <div class="remove-header text-center">
            <h1><i class="fas fa-unlock me-3"></i>権限チェック削除完了</h1>
            <h2>全ユーザーアクセス可能化</h2>
            <p class="mb-0">集金管理・陸運局提出・事故管理の権限制限を解除</p>
        </div>

        <!-- 処理結果 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-list me-2"></i>処理実行結果</h5>
            </div>
            <div class="card-body">
                <?php foreach ($results as $result): ?>
                    <div class="result-item <?= $result['success'] ? 'result-success' : 'result-error' ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-<?= $result['success'] ? 'check-circle text-success' : 'exclamation-triangle text-danger' ?> me-2"></i>
                                <?= htmlspecialchars($result['message']) ?>
                            </span>
                            <small class="text-muted"><?= $result['time'] ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- アクセス可能機能 -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5><i class="fas fa-check-circle me-2"></i>全ユーザーアクセス可能機能</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <i class="fas fa-calculator fa-3x text-success mb-3"></i>
                                <h6>集金管理</h6>
                                <p class="small text-muted">日次売上集計・現金確認・月次サマリー</p>
                                <a href="cash_management.php" class="btn btn-success btn-sm">
                                    <i class="fas fa-arrow-right me-1"></i>アクセス
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <i class="fas fa-file-alt fa-3x text-info mb-3"></i>
                                <h6>陸運局提出</h6>
                                <p class="small text-muted">年度データ集計・第4号様式・事故統計</p>
                                <a href="annual_report.php" class="btn btn-info btn-sm">
                                    <i class="fas fa-arrow-right me-1"></i>アクセス
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                                <h6>事故管理</h6>
                                <p class="small text-muted">事故記録・統計分析・処理状況管理</p>
                                <a href="accident_management.php" class="btn btn-warning btn-sm">
                                    <i class="fas fa-arrow-right me-1"></i>アクセス
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-success mt-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>全ユーザーアクセス可能:</strong> 運転者・点呼者・管理者・システム管理者、全ての権限のユーザーが上記機能にアクセスできます。
                </div>
            </div>
        </div>

        <!-- テスト推奨 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-vial me-2"></i>動作テスト推奨</h5>
            </div>
            <div class="card-body">
                <p>権限チェックを削除したため、以下の手順でテストすることを推奨します：</p>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-user me-2"></i>運転者権限でテスト</h6>
                        <ol>
                            <li>運転者権限のユーザーでログイン</li>
                            <li>各機能（集金管理・陸運局提出・事故管理）にアクセス</li>
                            <li>正常に表示されることを確認</li>
                        </ol>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-user-shield me-2"></i>管理者権限でテスト</h6>
                        <ol>
                            <li>管理者権限のユーザーでログイン</li>
                            <li>全機能が正常に動作することを確認</li>
                            <li>データ入力・更新・削除テスト</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- アクションボタン -->
        <div class="card">
            <div class="card-body text-center">
                <h5><i class="fas fa-rocket me-2"></i>システム利用開始</h5>
                <p class="text-muted">権限制限が解除されました。福祉輸送管理システムの全機能をご利用ください。</p>
                
                <div class="btn-group" role="group">
                    <a href="dashboard.php" class="btn btn-primary">
                        <i class="fas fa-home me-1"></i>ダッシュボード
                    </a>
                    <a href="cash_management.php" class="btn btn-success">
                        <i class="fas fa-calculator me-1"></i>集金管理
                    </a>
                    <a href="annual_report.php" class="btn btn-info">
                        <i class="fas fa-file-alt me-1"></i>陸運局提出
                    </a>
                    <a href="accident_management.php" class="btn btn-warning">
                        <i class="fas fa-exclamation-triangle me-1"></i>事故管理
                    </a>
                </div>
            </div>
        </div>

        <!-- システム情報 -->
        <div class="mt-4 p-3 bg-light rounded">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                <strong>修正日時:</strong> <?= date('Y/m/d H:i:s') ?> | 
                <strong>対象ファイル:</strong> cash_management.php, annual_report.php, accident_management.php | 
                <strong>アクセス権限:</strong> 全ユーザー
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
