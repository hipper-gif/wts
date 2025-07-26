<?php
/**
 * 福祉輸送管理システム - 改良版ユーザー管理画面
 * 統一権限管理システム対応
 */

session_start();

// データベース接続
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4',
        'twinklemark_taxi',
        'Smiley2525',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// 権限定義
$roles = [
    'system_admin' => [
        'name' => 'システム管理者',
        'description' => '全機能にアクセス可能',
        'color' => 'danger'
    ],
    'admin' => [
        'name' => '管理者',
        'description' => 'マスタ管理・帳票出力可能',
        'color' => 'warning'
    ],
    'driver' => [
        'name' => '運転者',
        'description' => '基本業務（点呼・点検・運行）',
        'color' => 'primary'
    ],
    'caller' => [
        'name' => '点呼者',
        'description' => '点呼関連のみ',
        'color' => 'info'
    ]
];

// 処理実行
$message = '';
$messageType = '';

// ユーザー権限更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'update_role') {
            $userId = (int)$_POST['user_id'];
            $newRole = $_POST['role'];
            
            if (array_key_exists($newRole, $roles)) {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET role = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$newRole, $userId]);
                
                $message = "ユーザーの権限を「{$roles[$newRole]['name']}」に変更しました。";
                $messageType = 'success';
            }
        } elseif ($_POST['action'] === 'toggle_active') {
            $userId = (int)$_POST['user_id'];
            $isActive = (int)$_POST['is_active'];
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET is_active = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$isActive, $userId]);
            
            $status = $isActive ? '有効' : '無効';
            $message = "ユーザーを「{$status}」に変更しました。";
            $messageType = 'success';
        } elseif ($_POST['action'] === 'add_user') {
            $name = trim($_POST['name']);
            $loginId = trim($_POST['login_id']);
            $password = $_POST['password'];
            $role = $_POST['role'];
            
            if ($name && $loginId && $password && array_key_exists($role, $roles)) {
                // ログインID重複チェック
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ?");
                $stmt->execute([$loginId]);
                
                if ($stmt->fetchColumn() > 0) {
                    $message = "ログインIDが既に使用されています。";
                    $messageType = 'danger';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, login_id, password, role, is_active) 
                        VALUES (?, ?, ?, ?, TRUE)
                    ");
                    $stmt->execute([$name, $loginId, $hashedPassword, $role]);
                    
                    $message = "新しいユーザー「{$name}」を追加しました。";
                    $messageType = 'success';
                }
            } else {
                $message = "入力内容に不備があります。";
                $messageType = 'danger';
            }
        }
    } catch (PDOException $e) {
        $message = "エラーが発生しました: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// ユーザー一覧取得
$stmt = $pdo->query("
    SELECT u.*, ur.role_name 
    FROM users u
    LEFT JOIN user_roles ur ON u.role = ur.role_key
    ORDER BY u.id
");
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザー管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .user-card {
            transition: transform 0.2s;
        }
        .user-card:hover {
            transform: translateY(-2px);
        }
        .role-badge {
            font-size: 0.9em;
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-user-cog"></i> ユーザー管理
        </a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-arrow-left"></i> ダッシュボードに戻る
            </a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    
    <!-- メッセージ表示 -->
    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        
        <!-- 新規ユーザー追加 -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5><i class="fas fa-user-plus"></i> 新規ユーザー追加</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_user">
                        
                        <div class="mb-3">
                            <label class="form-label">氏名</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ログインID</label>
                            <input type="text" name="login_id" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">パスワード</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">権限</label>
                            <select name="role" class="form-select" required>
                                <?php foreach ($roles as $roleKey => $roleData): ?>
                                <option value="<?php echo $roleKey; ?>">
                                    <?php echo $roleData['name']; ?> - <?php echo $roleData['description']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-plus"></i> 追加
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- 権限説明 -->
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h6><i class="fas fa-info-circle"></i> 権限説明</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($roles as $roleKey => $roleData): ?>
                    <div class="mb-2">
                        <span class="badge bg-<?php echo $roleData['color']; ?> role-badge">
                            <?php echo $roleData['name']; ?>
                        </span>
                        <br>
                        <small class="text-muted"><?php echo $roleData['description']; ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- ユーザー一覧 -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5><i class="fas fa-users"></i> ユーザー一覧 (<?php echo count($users); ?>名)</h5>
                </div>
                <div class="card-body">
                    
                    <?php if (empty($users)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-users fa-3x mb-3"></i>
                        <p>登録されているユーザーがありません。</p>
                    </div>
                    <?php else: ?>
                    
                    <div class="row">
                        <?php foreach ($users as $user): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card user-card h-100">
                                <div class="card-body">
                                    
                                    <!-- ユーザー情報 -->
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($user['name']); ?>
                                        </h6>
                                        
                                        <?php if ($user['is_active']): ?>
                                        <span class="badge bg-success">有効</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">無効</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="card-text small text-muted mb-2">
                                        ID: <?php echo $user['login_id']; ?>
                                    </p>
                                    
                                    <!-- 現在の権限 -->
                                    <div class="mb-3">
                                        <?php 
                                        $roleInfo = $roles[$user['role']] ?? ['name' => '不明', 'color' => 'secondary'];
                                        ?>
                                        <span class="badge bg-<?php echo $roleInfo['color']; ?> role-badge">
                                            <?php echo $roleInfo['name']; ?>
                                        </span>
                                    </div>
                                    
                                    <!-- 権限変更フォーム -->
                                    <form method="POST" class="mb-2">
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        
                                        <div class="input-group input-group-sm">
                                            <select name="role" class="form-select form-select-sm">
                                                <?php foreach ($roles as $roleKey => $roleData): ?>
                                                <option value="<?php echo $roleKey; ?>" 
                                                    <?php echo $user['role'] === $roleKey ? 'selected' : ''; ?>>
                                                    <?php echo $roleData['name']; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                                変更
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <!-- 有効/無効切り替え -->
                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $user['is_active'] ? 0 : 1; ?>">
                                        
                                        <button type="submit" class="btn btn-sm w-100 
                                            <?php echo $user['is_active'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>">
                                            <i class="fas fa-<?php echo $user['is_active'] ? 'pause' : 'play'; ?>"></i>
                                            <?php echo $user['is_active'] ? '無効にする' : '有効にする'; ?>
                                        </button>
                                    </form>
                                    
                                </div>
                                
                                <!-- カードフッター（最終更新） -->
                                <?php if ($user['updated_at']): ?>
                                <div class="card-footer text-muted small">
                                    <i class="fas fa-clock"></i> 
                                    更新: <?php echo date('Y/m/d H:i', strtotime($user['updated_at'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 権限テーブル詳細 -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6><i class="fas fa-table"></i> 権限詳細一覧</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>ユーザー名</th>
                                    <th>ログインID</th>
                                    <th>権限</th>
                                    <th>状態</th>
                                    <th>最終ログイン</th>
                                    <th>作成日</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td>
                                        <i class="fas fa-user"></i> 
                                        <?php echo htmlspecialchars($user['name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['login_id']); ?></td>
                                    <td>
                                        <?php 
                                        $roleInfo = $roles[$user['role']] ?? ['name' => '不明', 'color' => 'secondary'];
                                        ?>
                                        <span class="badge bg-<?php echo $roleInfo['color']; ?>">
                                            <?php echo $roleInfo['name']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                        <span class="badge bg-success">有効</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">無効</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                        <?php echo date('Y/m/d H:i', strtotime($user['last_login'])); ?>
                                        <?php else: ?>
                                        <span class="text-muted">未ログイン</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['created_at']): ?>
                                        <?php echo date('Y/m/d', strtotime($user['created_at'])); ?>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
