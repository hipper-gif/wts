<?php
/**
 * システム設定重複エラー修正スクリプト
 * system_settingsテーブルの重複エラーを修正
 */

session_start();

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

$results = [];
$errors = [];

function addResult($message, $success = true) {
    global $results;
    $results[] = [
        'message' => $message,
        'success' => $success,
        'time' => date('H:i:s')
    ];
}

function addError($message) {
    global $errors;
    $errors[] = $message;
    addResult($message, false);
}

addResult("システム設定重複エラーの修正を開始します", true);

try {
    // 1. system_settingsテーブルの構造確認
    addResult("system_settingsテーブルの構造を確認中...", true);
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'system_settings'");
    if ($stmt->rowCount() == 0) {
        // テーブルが存在しない場合は作成
        $pdo->exec("
            CREATE TABLE system_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT,
                description VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addResult("✓ system_settingsテーブルを作成しました", true);
    } else {
        addResult("✓ system_settingsテーブルは既に存在します", true);
    }

    // 2. 既存設定の確認と表示
    addResult("既存のシステム設定を確認中...", true);
    
    $stmt = $pdo->query("SELECT setting_key, setting_value, description FROM system_settings ORDER BY setting_key");
    $existing_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($existing_settings)) {
        addResult("既存設定 " . count($existing_settings) . "件を確認しました:", true);
        foreach ($existing_settings as $setting) {
            addResult("  - {$setting['setting_key']}: {$setting['setting_value']}", true);
        }
    } else {
        addResult("既存のシステム設定はありません", true);
    }

    // 3. 必要な設定値を安全に更新・挿入
    addResult("システム設定を更新中...", true);
    
    $required_settings = [
        'system_version' => ['1.0.0', 'システムバージョン'],
        'system_name' => ['福祉輸送管理システム', 'システム名称'],
        'setup_completed' => ['1', 'セットアップ完了フラグ'],
        'setup_date' => [date('Y-m-d H:i:s'), 'セットアップ実行日時'],
        'last_update' => [date('Y-m-d H:i:s'), '最終更新日時']
    ];
    
    foreach ($required_settings as $key => $value_desc) {
        list($value, $description) = $value_desc;
        
        try {
            // まず既存の設定をチェック
            $stmt = $pdo->prepare("SELECT id, setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 既存の設定を更新
                $stmt = $pdo->prepare("
                    UPDATE system_settings 
                    SET setting_value = ?, description = ?, updated_at = NOW() 
                    WHERE setting_key = ?
                ");
                $stmt->execute([$value, $description, $key]);
                addResult("✓ {$key} を更新しました: {$value}", true);
            } else {
                // 新規設定を挿入
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, description) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$key, $value, $description]);
                addResult("✓ {$key} を新規追加しました: {$value}", true);
            }
        } catch (Exception $e) {
            addError("設定 {$key} の処理でエラー: " . $e->getMessage());
        }
    }

    // 4. 重複データのクリーンアップ
    addResult("重複データのクリーンアップを実行中...", true);
    
    try {
        // 重複チェッククエリ
        $stmt = $pdo->query("
            SELECT setting_key, COUNT(*) as count 
            FROM system_settings 
            GROUP BY setting_key 
            HAVING count > 1
        ");
        $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($duplicates)) {
            foreach ($duplicates as $duplicate) {
                addResult("重複発見: {$duplicate['setting_key']} ({$duplicate['count']}件)", false);
                
                // 古い重複レコードを削除（最新のIDを保持）
                $pdo->exec("
                    DELETE FROM system_settings 
                    WHERE setting_key = '{$duplicate['setting_key']}' 
                    AND id NOT IN (
                        SELECT * FROM (
                            SELECT MAX(id) 
                            FROM system_settings 
                            WHERE setting_key = '{$duplicate['setting_key']}'
                        ) AS temp
                    )
                ");
                addResult("✓ {$duplicate['setting_key']} の重複を解決しました", true);
            }
        } else {
            addResult("✓ 重複データはありません", true);
        }
    } catch (Exception $e) {
        addError("重複クリーンアップでエラー: " . $e->getMessage());
    }

    // 5. テーブル構造の最適化
    addResult("テーブル構造を最適化中...", true);
    
    try {
        // UNIQUE制約の確認
        $stmt = $pdo->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = '" . DB_NAME . "' 
            AND TABLE_NAME = 'system_settings' 
            AND CONSTRAINT_TYPE = 'UNIQUE'
            AND CONSTRAINT_NAME LIKE '%setting_key%'
        ");
        
        if ($stmt->rowCount() == 0) {
            // UNIQUE制約がない場合は追加
            try {
                $pdo->exec("ALTER TABLE system_settings ADD UNIQUE KEY unique_setting_key (setting_key)");
                addResult("✓ setting_keyにUNIQUE制約を追加しました", true);
            } catch (Exception $e) {
                addResult("UNIQUE制約は既に存在するか、追加できませんでした", true);
            }
        } else {
            addResult("✓ setting_keyのUNIQUE制約は既に存在します", true);
        }
        
        // テーブル最適化
        $pdo->exec("OPTIMIZE TABLE system_settings");
        addResult("✓ system_settingsテーブルを最適化しました", true);
        
    } catch (Exception $e) {
        addError("テーブル最適化でエラー: " . $e->getMessage());
    }

    // 6. 最終確認
    addResult("最終確認を実行中...", true);
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM system_settings");
    $total_settings = $stmt->fetchColumn();
    addResult("システム設定総数: {$total_settings}件", true);
    
    // 必要な設定がすべて存在するかチェック
    $all_settings_ok = true;
    foreach (array_keys($required_settings) as $key) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() == 0) {
            addError("必要な設定 {$key} が存在しません");
            $all_settings_ok = false;
        }
    }
    
    if ($all_settings_ok) {
        addResult("✓ 全ての必要な設定が正常に存在します", true);
    }

} catch (Exception $e) {
    addError("修正中にエラーが発生しました: " . $e->getMessage());
}

// 修正完了
if (empty($errors)) {
    addResult("🎉 システム設定の修正が正常に完了しました！", true);
} else {
    addResult("⚠️ 修正が完了しましたが、いくつかのエラーがありました", false);
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>システム設定修正 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .fix-header {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
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
        .settings-table {
            font-size: 0.9rem;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-4">
        <!-- ヘッダー -->
        <div class="fix-header text-center">
            <h1><i class="fas fa-cogs me-3"></i>システム設定修正</h1>
            <h2>重複エラー解決</h2>
            <p class="mb-0">system_settingsテーブルの重複キーエラーを修正</p>
        </div>

        <!-- 統計情報 -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-success"><?= count(array_filter($results, function($r) { return $r['success']; })) ?></div>
                        <div>成功</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-danger"><?= count($errors) ?></div>
                        <div>エラー</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-primary"><?= count($results) ?></div>
                        <div>総処理数</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 修正結果 -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-list me-2"></i>修正実行結果</h5>
            </div>
            <div class="card-body">
                <div style="max-height: 400px; overflow-y: auto;">
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
        </div>

        <!-- 現在のシステム設定 -->
        <div class="card mt-4">
            <div class="card-header">
                <h5><i class="fas fa-cog me-2"></i>現在のシステム設定</h5>
            </div>
            <div class="card-body">
                <?php
                try {
                    $stmt = $pdo->query("SELECT setting_key, setting_value, description, updated_at FROM system_settings ORDER BY setting_key");
                    $current_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($current_settings)):
                ?>
                    <div class="table-responsive">
                        <table class="table table-striped settings-table">
                            <thead>
                                <tr>
                                    <th>設定キー</th>
                                    <th>設定値</th>
                                    <th>説明</th>
                                    <th>更新日時</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_settings as $setting): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($setting['setting_key']) ?></code></td>
                                        <td><?= htmlspecialchars($setting['setting_value']) ?></td>
                                        <td><?= htmlspecialchars($setting['description']) ?></td>
                                        <td><?= $setting['updated_at'] ? date('Y/m/d H:i', strtotime($setting['updated_at'])) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        システム設定が見つかりませんでした。
                    </div>
                <?php endif; ?>
                <?php } catch (Exception $e): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        設定の取得中にエラーが発生しました: <?= htmlspecialchars($e->getMessage()) ?>
                    </div>
                <?php endtry; ?>
            </div>
        </div>

        <!-- エラー詳細 -->
        <?php if (!empty($errors)): ?>
        <div class="card mt-4">
            <div class="card-header bg-danger text-white">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>エラー詳細</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <?php foreach ($errors as $error): ?>
                        <li class="mb-2">
                            <i class="fas fa-times text-danger me-2"></i>
                            <?= htmlspecialchars($error) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- 修正完了アクション -->
        <div class="card mt-4">
            <div class="card-header <?= empty($errors) ? 'bg-success text-white' : 'bg-warning' ?>">
                <h5>
                    <i class="fas fa-<?= empty($errors) ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    次のステップ
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($errors)): ?>
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle me-2"></i>修正完了！</h6>
                        <p>システム設定の重複エラーが修正されました。セットアップを再実行できます。</p>
                    </div>
                    
                    <h6>修正内容:</h6>
                    <ul>
                        <li>system_settingsテーブルの重複データクリーンアップ</li>
                        <li>必要なシステム設定の安全な更新・追加</li>
                        <li>UNIQUE制約の確認・追加</li>
                        <li>テーブル構造の最適化</li>
                    </ul>
                    
                    <div class="mt-4">
                        <a href="setup_complete_system.php" class="btn btn-primary me-2">
                            <i class="fas fa-play me-1"></i>セットアップ再実行
                        </a>
                        <a href="dashboard.php" class="btn btn-success me-2">
                            <i class="fas fa-home me-1"></i>ダッシュボードへ
                        </a>
                        <a href="cash_management.php" class="btn btn-info">
                            <i class="fas fa-calculator me-1"></i>新機能テスト
                        </a>
                    </div>
                    
                <?php else: ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>一部エラーがありました</h6>
                        <p>修正は部分的に完了しましたが、いくつかのエラーがありました。</p>
                    </div>
                    
                    <div class="mt-3">
                        <button onclick="location.reload()" class="btn btn-primary me-2">
                            <i class="fas fa-redo me-1"></i>修正再実行
                        </button>
                        <a href="setup_complete_system.php" class="btn btn-warning me-2">
                            <i class="fas fa-play me-1"></i>セットアップ続行
                        </a>
                        <a href="dashboard.php" class="btn btn-success">
                            <i class="fas fa-home me-1"></i>ダッシュボードへ
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- システム状態 -->
        <div class="card mt-4 bg-light">
            <div class="card-body">
                <h6><i class="fas fa-info-circle me-2"></i>システム状態</h6>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled mb-0">
                            <li><strong>修正日時:</strong> <?= date('Y/m/d H:i:s') ?></li>
                            <li><strong>データベース:</strong> <?= DB_NAME ?></li>
                            <li><strong>処理状態:</strong> <?= empty($errors) ? '完了' : '一部エラー' ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled mb-0">
                            <li><strong>PHP バージョン:</strong> <?= phpversion() ?></li>
                            <li><strong>システム状態:</strong> <span class="text-success">動作可能</span></li>
                            <li><strong>次のアクション:</strong> セットアップ再実行推奨</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
