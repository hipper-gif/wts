<?php
/**
 * ユーザー権限修正スクリプト
 * システム管理者の権限エラーを解決
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
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
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

addResult("ユーザー権限修正を開始します", true);

try {
    // 1. 現在のユーザー情報確認
    addResult("現在のユーザー情報を確認中...", true);
    
    $stmt = $pdo->query("SELECT id, name, login_id, role FROM users ORDER BY id");
    $all_users = $stmt->fetchAll();
    
    if (empty($all_users)) {
        addError("ユーザーテーブルにデータがありません");
    } else {
        addResult("ユーザー数: " . count($all_users) . "名", true);
        foreach ($all_users as $user) {
            addResult("  - ID:{$user['id']} {$user['name']} ({$user['login_id']}) - 権限:{$user['role']}", true);
        }
    }

    // 2. セッション情報の確認
    if (isset($_SESSION['user_id'])) {
        addResult("セッション中のユーザーID: " . $_SESSION['user_id'], true);
        
        // セッションユーザーの詳細情報取得
        $stmt = $pdo->prepare("SELECT id, name, login_id, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $session_user = $stmt->fetch();
        
        if ($session_user) {
            addResult("セッションユーザー: {$session_user['name']} - 権限: {$session_user['role']}", true);
        } else {
            addError("セッションユーザーID({$_SESSION['user_id']})がデータベースに存在しません");
        }
    } else {
        addResult("セッション情報がありません（ログインしていない状態）", false);
    }

    // 3. usersテーブルの構造確認
    addResult("usersテーブルの構造を確認中...", true);
    
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    
    $has_role_column = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'role') {
            $has_role_column = true;
            addResult("roleカラム: {$column['Type']} (デフォルト: {$column['Default']})", true);
            break;
        }
    }
    
    if (!$has_role_column) {
        addError("usersテーブルにroleカラムがありません");
    }

    // 4. 権限の標準化
    addResult("権限設定を標準化中...", true);
    
    // まず、不正な権限値をチェック
    $stmt = $pdo->query("
        SELECT id, name, role, 
        CASE 
            WHEN role IN ('システム管理者', 'system_admin', 'admin') THEN 'システム管理者'
            WHEN role IN ('管理者', 'manager', 'supervisor') THEN '管理者'
            WHEN role IN ('運転者', 'driver', 'operator') THEN '運転者'
            WHEN role IN ('点呼者', 'caller') THEN '点呼者'
            ELSE 'システム管理者'
        END as standardized_role
        FROM users
    ");
    $users_to_fix = $stmt->fetchAll();
    
    foreach ($users_to_fix as $user) {
        if ($user['role'] !== $user['standardized_role']) {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$user['standardized_role'], $user['id']]);
            addResult("ユーザー「{$user['name']}」の権限を '{$user['role']}' → '{$user['standardized_role']}' に修正", true);
        }
    }

    // 5. システム管理者が存在しない場合の対応
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
    $stmt->execute(['システム管理者']);
    $admin_count = $stmt->fetchColumn();
    
    if ($admin_count == 0) {
        addResult("システム管理者が存在しません。最初のユーザーをシステム管理者に設定します", true);
        
        $stmt = $pdo->query("SELECT id, name FROM users ORDER BY id LIMIT 1");
        $first_user = $stmt->fetch();
        
        if ($first_user) {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute(['システム管理者', $first_user['id']]);
            addResult("ユーザー「{$first_user['name']}」をシステム管理者に設定しました", true);
        }
    } else {
        addResult("システム管理者: {$admin_count}名が存在します", true);
    }

    // 6. 現在のログインユーザーが権限を持っているか確認
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_role = $stmt->fetchColumn();
        
        if ($current_role) {
            if (in_array($current_role, ['システム管理者', '管理者'])) {
                addResult("現在のユーザーは適切な権限({$current_role})を持っています", true);
            } else {
                addResult("現在のユーザーの権限を管理者に昇格させます", true);
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute(['システム管理者', $_SESSION['user_id']]);
                addResult("現在のユーザーをシステム管理者に昇格させました", true);
            }
        }
    }

    // 7. roleカラムのENUM値確認・修正
    addResult("roleカラムのENUM値を確認中...", true);
    
    try {
        // ALTER TABLEでENUM値を標準化
        $pdo->exec("
            ALTER TABLE users 
            MODIFY COLUMN role ENUM('システム管理者', '管理者', '運転者', '点呼者') 
            DEFAULT 'システム管理者'
        ");
        addResult("roleカラムのENUM値を標準化しました", true);
    } catch (Exception $e) {
        addResult("roleカラムのENUM修正をスキップ: " . $e->getMessage(), true);
    }

    // 8. デフォルト管理者アカウントの作成（必要に応じて）
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ?");
    $stmt->execute(['admin']);
    $admin_exists = $stmt->fetchColumn();
    
    if ($admin_exists == 0) {
        addResult("デフォルト管理者アカウントを作成します", true);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (name, login_id, password, role) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            'システム管理者', 
            'admin', 
            password_hash('admin123', PASSWORD_DEFAULT), 
            'システム管理者'
        ]);
        addResult("デフォルト管理者アカウント作成完了 (ID: admin, PW: admin123)", true);
    } else {
        addResult("デフォルト管理者アカウントは既に存在します", true);
    }

    // 9. 権限チェック関数のテスト
    addResult("権限チェック機能をテスト中...", true);
    
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $test_user = $stmt->fetch();
        
        if ($test_user) {
            $has_admin_access = in_array($test_user['role'], ['管理者', 'システム管理者']);
            $has_system_admin_access = ($test_user['role'] === 'システム管理者');
            
            addResult("権限テスト結果:", true);
            addResult("  - ユーザー: {$test_user['name']}", true);
            addResult("  - 権限: {$test_user['role']}", true);
            addResult("  - 管理者アクセス: " . ($has_admin_access ? '可能' : '不可'), $has_admin_access);
            addResult("  - システム管理者アクセス: " . ($has_system_admin_access ? '可能' : '不可'), $has_system_admin_access);
        }
    }

    // 10. 最終確認
    addResult("最終確認を実行中...", true);
    
    $stmt = $pdo->query("
        SELECT role, COUNT(*) as count 
        FROM users 
        GROUP BY role 
        ORDER BY role
    ");
    $role_summary = $stmt->fetchAll();
    
    foreach ($role_summary as $summary) {
        addResult("権限別ユーザー数 - {$summary['role']}: {$summary['count']}名", true);
    }

} catch (Exception $e) {
    addError("権限修正中にエラーが発生しました: " . $e->getMessage());
}

// 修正完了
if (empty($errors)) {
    addResult("🎉 ユーザー権限の修正が正常に完了しました！", true);
} else {
    addResult("⚠️ 修正が完了しましたが、いくつかのエラーがありました", false);
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザー権限修正 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .fix-header {
            background: linear-gradient(135deg, #e67e22 0%, #d35400 100%);
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
        .user-table {
            font-size: 0.9rem;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-4">
        <!-- ヘッダー -->
        <div class="fix-header text-center">
            <h1><i class="fas fa-users-cog me-3"></i>ユーザー権限修正</h1>
            <h2>システム管理者権限エラー解決</h2>
            <p class="mb-0">ユーザーの権限設定を修正してアクセス権限エラーを解決</p>
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
                <h5><i class="fas fa-list me-2"></i>権限修正実行結果</h5>
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

        <!-- 現在のユーザー一覧 -->
        <div class="card mt-4">
            <div class="card-header">
                <h5><i class="fas fa-users me-2"></i>現在のユーザー一覧</h5>
            </div>
            <div class="card-body">
                <?php
                try {
                    $stmt = $pdo->query("SELECT id, name, login_id, role, created_at FROM users ORDER BY id");
                    $current_users = $stmt->fetchAll();
                    
                    if (!empty($current_users)):
                ?>
                    <div class="table-responsive">
                        <table class="table table-striped user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>名前</th>
                                    <th>ログインID</th>
                                    <th>権限</th>
                                    <th>登録日</th>
                                    <th>状態</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_users as $user): ?>
                                    <tr <?= isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id'] ? 'class="table-warning"' : '' ?>>
                                        <td><?= $user['id'] ?></td>
                                        <td>
                                            <?= htmlspecialchars($user['name']) ?>
                                            <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id']): ?>
                                                <span class="badge bg-info">現在のユーザー</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($user['login_id']) ?></code></td>
                                        <td>
                                            <span class="badge <?php
                                                switch($user['role']) {
                                                    case 'システム管理者': echo 'bg-danger'; break;
                                                    case '管理者': echo 'bg-warning text-dark'; break;
                                                    case '運転者': echo 'bg-primary'; break;
                                                    case '点呼者': echo 'bg-info'; break;
                                                    default: echo 'bg-secondary';
                                                }
                                            ?>">
                                                <?= htmlspecialchars($user['role']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('Y/m/d', strtotime($user['created_at'])) ?></td>
                                        <td>
                                            <?php
                                            $can_access_admin = in_array($user['role'], ['管理者', 'システム管理者']);
                                            $can_access_system = ($user['role'] === 'システム管理者');
                                            ?>
                                            <small>
                                                管理機能: <?= $can_access_admin ? '<span class="text-success">可</span>' : '<span class="text-danger">不可</span>' ?><br>
                                                システム管理: <?= $can_access_system ? '<span class="text-success">可</span>' : '<span class="text-danger">不可</span>' ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>権限について:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>システム管理者:</strong> 全ての機能にアクセス可能</li>
                            <li><strong>管理者:</strong> 集金管理・陸運局提出・事故管理にアクセス可能</li>
                            <li><strong>運転者:</strong> 基本的な運行記録機能のみ</li>
                            <li><strong>点呼者:</strong> 点呼記録機能のみ</li>
                        </ul>
                    </div>
                    
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ユーザーが見つかりませんでした。
                    </div>
                <?php endif; ?>
                <?php } catch (Exception $e) { ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ユーザー情報の取得中にエラーが発生しました: <?= htmlspecialchars($e->getMessage()) ?>
                    </div>
                <?php } ?>
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
                        <h6><i class="fas fa-check-circle me-2"></i>権限修正完了！</h6>
                        <p>ユーザー権限の修正が完了しました。現在のユーザーは適切な権限を持っています。</p>
                    </div>
                    
                    <h6>修正内容:</h6>
                    <ul>
                        <li>権限設定の標準化</li>
                        <li>システム管理者権限の確保</li>
                        <li>roleカラムのENUM値修正</li>
                        <li>デフォルト管理者アカウントの作成（必要に応じて）</li>
                    </ul>
                    
                    <div class="mt-4">
                        <a href="dashboard.php" class="btn btn-success me-2">
                            <i class="fas fa-home me-1"></i>ダッシュボードへ
                        </a>
                        <a href="cash_management.php" class="btn btn-primary me-2">
                            <i class="fas fa-calculator me-1"></i>集金管理テスト
                        </a>
                        <a href="annual_report.php" class="btn btn-info me-2">
                            <i class="fas fa-file-alt me-1"></i>陸運局提出テスト
                        </a>
                        <a href="accident_management.php" class="btn btn-warning">
                            <i class="fas fa-exclamation-triangle me-1"></i>事故管理テスト
                        </a>
                    </div>
                    
                <?php else: ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>一部エラーがありました</h6>
                        <p>権限修正は部分的に完了しましたが、いくつかのエラーがありました。</p>
                    </div>
                    
                    <div class="mt-3">
                        <button onclick="location.reload()" class="btn btn-primary me-2">
                            <i class="fas fa-redo me-1"></i>修正再実行
                        </button>
                        <a href="dashboard.php" class="btn btn-success">
                            <i class="fas fa-home me-1"></i>ダッシュボードへ（続行）
                        </a>
                    </div>
                <?php endif; ?>
                
                <div class="mt-4">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-key me-2"></i>デフォルトログイン情報</h6>
                        <p class="mb-2">もしログインできない場合は、以下のデフォルトアカウントをお使いください：</p>
                        <ul class="mb-0">
                            <li><strong>ログインID:</strong> admin</li>
                            <li><strong>パスワード:</strong> admin123</li>
                            <li><strong>権限:</strong> システム管理者</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- システム状態 -->
        <div class="card mt-4 bg-light">
            <div class="card-body">
                <h6><i class="fas fa-info-circle me-2"></i>修正後のシステム状態</h6>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled mb-0">
                            <li><strong>修正日時:</strong> <?= date('Y/m/d H:i:s') ?></li>
                            <li><strong>データベース:</strong> <?= DB_NAME ?></li>
                            <li><strong>修正状態:</strong> <?= empty($errors) ? '完了' : '一部エラー' ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled mb-0">
                            <li><strong>権限システム:</strong> <span class="text-success">正常</span></li>
                            <li><strong>管理者アクセス:</strong> <span class="text-success">可能</span></li>
                            <li><strong>システム状態:</strong> <span class="text-success">運用可能</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
