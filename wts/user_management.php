<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// データベース接続
$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];

// 権限チェック - 統一版
try {
    // テーブル構造確認・is_managerカラム追加
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_manager BOOLEAN DEFAULT FALSE");
    } catch (PDOException $e) {
        // カラムが既に存在する場合は無視
    }
    
    // 現在のユーザーの権限を再取得
    $stmt = $pdo->prepare("SELECT role, is_driver, is_caller, is_manager, is_admin FROM users WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_user) {
        header('Location: index.php');
        exit;
    }
    
    // システム管理者権限チェック（複数の方法で確認）
    $is_system_admin = false;
    
    // 方法1: is_adminフラグ
    if (isset($current_user['is_admin']) && $current_user['is_admin'] == 1) {
        $is_system_admin = true;
    }
    
    // 方法2: roleカラム
    if (in_array($current_user['role'], ['admin', 'system_admin', 'システム管理者'])) {
        $is_system_admin = true;
    }
    
    // 方法3: セッション確認（後方互換）
    if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'system_admin', 'システム管理者'])) {
        $is_system_admin = true;
    }
    
    // システム管理者でない場合はアクセス拒否
    if (!$is_system_admin) {
        header('Location: dashboard.php?error=permission_denied');
        exit;
    }
    
    // セッション情報の更新（統一化）
    $_SESSION['user_role'] = $current_user['role'];
    $_SESSION['is_admin'] = $current_user['is_admin'];
    $_SESSION['is_caller'] = $current_user['is_caller'];
    $_SESSION['is_driver'] = $current_user['is_driver'];
    $_SESSION['is_manager'] = $current_user['is_manager'] ?? 0;
    
} catch (Exception $e) {
    header('Location: dashboard.php?error=system_error');
    exit;
}

$user_name = $_SESSION['user_name'] ?? '管理者';

$success_message = '';
$error_message = '';

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            // 新規追加
            $name = trim($_POST['name']);
            $login_id = trim($_POST['login_id']);
            $password = $_POST['password'];
            
            // 権限の処理（4種類対応）
            $role = $_POST['role'] ?? '';
            
            if (empty($role)) {
                throw new Exception('権限を選択してください。');
            }
            
            // 権限フラグの設定
            $is_driver = ($role === 'driver') ? 1 : 0;
            $is_caller = ($role === 'caller') ? 1 : 0;
            $is_manager = ($role === 'manager') ? 1 : 0;
            $is_admin = ($role === 'system_admin') ? 1 : 0;
            
            // バリデーション
            if (empty($name) || empty($login_id) || empty($password)) {
                throw new Exception('すべての必須項目を入力してください。');
            }
            
            // ログインID重複チェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ?");
            $stmt->execute([$login_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('このログインIDは既に使用されています。');
            }
            
            // パスワードハッシュ化
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // テーブル構造確認・is_managerカラム追加
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN is_manager BOOLEAN DEFAULT FALSE");
            } catch (PDOException $e) {
                // カラムが既に存在する場合は無視
            }
            
            // ユーザー追加（is_managerカラム対応）
            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, login_id, password, role, is_driver, is_caller, is_manager, is_admin, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE, NOW())");
                $stmt->execute([
                    $name, $login_id, $hashed_password, $role, 
                    $is_driver, $is_caller, $is_manager, $is_admin
                ]);
            } catch (PDOException $e) {
                // is_managerカラムが存在しない場合のフォールバック
                $stmt = $pdo->prepare("INSERT INTO users (name, login_id, password, role, is_driver, is_caller, is_admin, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, NOW())");
                $stmt->execute([
                    $name, $login_id, $hashed_password, $role, 
                    $is_driver, $is_caller, $is_admin
                ]);
            }
            
            $success_message = 'ユーザーを追加しました。';
            
        } elseif ($action === 'edit') {
            // 編集
            $edit_user_id = $_POST['user_id'];
            $name = trim($_POST['name']);
            $login_id = trim($_POST['login_id']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // 権限の処理（4種類対応）
            $role = $_POST['role'] ?? '';
            
            if (empty($role)) {
                throw new Exception('権限を選択してください。');
            }
            
            // 権限フラグの設定
            $is_driver = ($role === 'driver') ? 1 : 0;
            $is_caller = ($role === 'caller') ? 1 : 0;
            $is_manager = ($role === 'manager') ? 1 : 0;
            $is_admin = ($role === 'system_admin') ? 1 : 0;
            
            // バリデーション
            if (empty($name) || empty($login_id)) {
                throw new Exception('名前とログインIDは必須です。');
            }
            
            // ログインID重複チェック（自分以外）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ? AND id != ?");
            $stmt->execute([$login_id, $edit_user_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('このログインIDは既に使用されています。');
            }
            
            // ユーザー更新（is_managerカラム対応）
            try {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, login_id = ?, role = ?, is_driver = ?, is_caller = ?, is_manager = ?, is_admin = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([
                    $name, $login_id, $role,
                    $is_driver, $is_caller, $is_manager, $is_admin,
                    $is_active, $edit_user_id
                ]);
            } catch (PDOException $e) {
                // is_managerカラムが存在しない場合のフォールバック
                $stmt = $pdo->prepare("UPDATE users SET name = ?, login_id = ?, role = ?, is_driver = ?, is_caller = ?, is_admin = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([
                    $name, $login_id, $role,
                    $is_driver, $is_caller, $is_admin,
                    $is_active, $edit_user_id
                ]);
            }
            
            $success_message = 'ユーザー情報を更新しました。';
            
        } elseif ($action === 'change_password') {
            // パスワード変更
            $edit_user_id = $_POST['user_id'];
            $new_password = $_POST['new_password'];
            
            if (empty($new_password)) {
                throw new Exception('新しいパスワードを入力してください。');
            }
            
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $edit_user_id]);
            
            $success_message = 'パスワードを変更しました。';
            
        } elseif ($action === 'delete') {
            // 削除（論理削除）
            $delete_user_id = $_POST['user_id'];
            
            // 自分自身は削除不可
            if ($delete_user_id == $user_id) {
                throw new Exception('自分自身は削除できません。');
            }
            
            $stmt = $pdo->prepare("UPDATE users SET is_active = FALSE, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$delete_user_id]);
            
            $success_message = 'ユーザーを削除しました。';
            

        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ユーザー一覧取得 - 4権限対応版
try {
    // まずis_managerカラムの存在確認
    try {
        $stmt = $pdo->prepare("SELECT id, name, login_id, role, is_driver, is_caller, is_manager, is_admin, is_active, created_at FROM users ORDER BY is_active DESC, role, name");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // is_managerカラムが存在しない場合のフォールバック
        $stmt = $pdo->prepare("SELECT id, name, login_id, role, is_driver, is_caller, is_admin, is_active, created_at, 0 as is_manager FROM users ORDER BY is_active DESC, role, name");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error_message = 'ユーザー一覧の取得に失敗しました: ' . $e->getMessage();
    $users = [];
}

// 権限表示用の関数
function getUserPermissions($user) {
    $role = $user['role'] ?? '';
    
    // roleカラムが空の場合、フラグから推定
    if (empty($role)) {
        if (isset($user['is_admin']) && $user['is_admin']) {
            $role = 'system_admin';
        } elseif (isset($user['is_manager']) && $user['is_manager']) {
            $role = 'manager';
        } elseif (isset($user['is_caller']) && $user['is_caller']) {
            $role = 'caller';
        } else {
            $role = 'driver';
        }
    }
    
    switch ($role) {
        case 'driver':
            return '運転者';
        case 'caller':
            return '点呼者';
        case 'manager':
            return '管理者';
        case 'system_admin':
            return 'システム管理者';
        default:
            return '不明';
    }
}

// 権限表示用のバッジ色
function getRoleBadgeClass($role) {
    // roleカラムが空の場合のデフォルト処理は上の関数で対応
    switch ($role) {
        case 'driver':
            return 'bg-success';
        case 'caller':
            return 'bg-info';
        case 'manager':
            return 'bg-warning';
        case 'system_admin':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

// 安全な値取得関数
function safeGet($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

// 権限統計取得 - 4権限対応
try {
    // まずis_managerカラムの存在確認
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN role = 'system_admin' THEN 1 ELSE 0 END) as admin_count,
                SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as manager_count,
                SUM(CASE WHEN role = 'caller' THEN 1 ELSE 0 END) as caller_count,
                SUM(CASE WHEN role = 'driver' THEN 1 ELSE 0 END) as driver_count,
                SUM(is_active) as active_count
            FROM users
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // フォールバック版（既存カラムのみ使用）
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(is_admin) as admin_count,
                0 as manager_count,
                SUM(is_caller) as caller_count,
                SUM(is_driver) as driver_count,
                SUM(is_active) as active_count
            FROM users
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $stats = ['total_users' => 0, 'admin_count' => 0, 'manager_count' => 0, 'caller_count' => 0, 'driver_count' => 0, 'active_count' => 0];
}
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
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #6f42c1 0%, #5a2d91 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, #6f42c1 0%, #5a2d91 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1rem 1.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6f42c1 0%, #5a2d91 100%);
            border: none;
            border-radius: 25px;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a2d91 0%, #4a236b 100%);
            transform: translateY(-1px);
        }
        
        .user-row {
            border-left: 4px solid #6f42c1;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .user-row:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .user-row.inactive {
            opacity: 0.6;
            border-left-color: #6c757d;
        }
        
        .role-badge {
            font-size: 0.8em;
            padding: 4px 12px;
            border-radius: 20px;
        }
        
        .role-admin { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
        .role-manager { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); }
        .role-driver { background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #6f42c1;
            box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.25);
        }
        
        .stats-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #6f42c1;
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-users-cog me-2"></i>ユーザー管理</h1>
                    <small>システム管理者専用 - 統一権限管理対応版</small>
                </div>
                <div class="col-auto">
                    <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>ダッシュボード
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container mt-4">
        <!-- アラート -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        
        <!-- 統計情報 -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['total_users'] ?></div>
                    <div class="text-muted">総ユーザー数</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['active_count'] ?></div>
                    <div class="text-muted">有効ユーザー</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['admin_count'] ?></div>
                    <div class="text-muted">システム管理者</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['manager_count'] ?></div>
                    <div class="text-muted">管理者</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['caller_count'] ?></div>
                    <div class="text-muted">点呼者</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number"><?= $stats['driver_count'] ?></div>
                    <div class="text-muted">運転者</div>
                </div>
            </div>
        </div>
        
        <!-- 新規追加ボタン -->
        <div class="mb-4">
            <button type="button" class="btn btn-primary" onclick="showAddModal()">
                <i class="fas fa-user-plus me-2"></i>新規ユーザー追加
            </button>
        </div>
        
        <!-- ユーザー一覧 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>ユーザー一覧 (<?= count($users) ?>名)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <p class="text-muted text-center">ユーザーが登録されていません。</p>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                    <div class="user-row <?= safeGet($user, 'is_active', 1) ? '' : 'inactive' ?>">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h6 class="mb-1">
                                    <?= htmlspecialchars(safeGet($user, 'name', '名前未設定')) ?>
                                    <?php if (safeGet($user, 'id') == $user_id): ?>
                                    <span class="badge bg-info ms-2">現在のユーザー</span>
                                    <?php endif; ?>
                                </h6>
                                <small class="text-muted">
                                    ID: <?= htmlspecialchars(safeGet($user, 'login_id', '未設定')) ?>
                                    | Role: <?= htmlspecialchars(safeGet($user, 'role', '未設定')) ?>
                                </small>
                            </div>
                            <div class="col-md-3">
                                <div class="permissions-badges">
                                    <span class="badge <?= getRoleBadgeClass(safeGet($user, 'role')) ?>">
                                        <?= getUserPermissions($user) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <span class="badge <?= safeGet($user, 'is_active', 1) ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= safeGet($user, 'is_active', 1) ? '有効' : '無効' ?>
                                </span>
                            </div>
                            <div class="col-md-3 text-end">
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" 
                                            onclick="changePassword(<?= safeGet($user, 'id') ?>, '<?= htmlspecialchars(safeGet($user, 'name', '名前未設定')) ?>')">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <?php if (safeGet($user, 'id') != $user_id): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteUser(<?= safeGet($user, 'id') ?>, '<?= htmlspecialchars(safeGet($user, 'name', '名前未設定')) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- ユーザー追加・編集モーダル -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">ユーザー追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="userForm" method="POST">
                    <input type="hidden" name="action" id="modalAction" value="add">
                    <input type="hidden" name="user_id" id="modalUserId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="modalName" class="form-label">氏名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modalName" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="modalLoginId" class="form-label">ログインID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modalLoginId" name="login_id" required>
                        </div>
                        
                        <div class="mb-3" id="passwordField">
                            <label for="modalPassword" class="form-label">パスワード <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="modalPassword" name="password">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">権限 <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" id="modalRole" required>
                                <option value="">権限を選択してください</option>
                                <option value="driver">運転者 - 基本業務（点呼・点検・運行）</option>
                                <option value="caller">点呼者 - 乗務前・乗務後点呼を実施する</option>
                                <option value="manager">管理者 - マスタ管理・帳票出力・集金管理</option>
                                <option value="system_admin">システム管理者 - システム全体の管理権限を持つ</option>
                            </select>
                            <small class="form-text text-muted">
                                <div class="mt-2">
                                    <span class="badge bg-success me-2">運転者</span>点呼・点検・運行業務<br>
                                    <span class="badge bg-info me-2">点呼者</span>点呼業務のみ<br>
                                    <span class="badge bg-warning me-2">管理者</span>マスタ管理・帳票・集金<br>
                                    <span class="badge bg-danger me-2">システム管理者</span>全機能アクセス可能
                                </div>
                            </small>
                        </div>
                        
                        <div class="mb-3" id="activeField" style="display: none;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="modalIsActive" name="is_active" checked>
                                <label class="form-check-label" for="modalIsActive">
                                    有効
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- パスワード変更モーダル -->
    <div class="modal fade" id="passwordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">パスワード変更</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="user_id" id="passwordUserId">
                    
                    <div class="modal-body">
                        <p>ユーザー: <strong id="passwordUserName"></strong></p>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">新しいパスワード <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="newPassword" name="new_password" required>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-warning">パスワード変更</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 新規追加モーダル
        function showAddModal() {
            document.getElementById('userModalTitle').textContent = 'ユーザー追加';
            document.getElementById('modalAction').value = 'add';
            document.getElementById('modalUserId').value = '';
            document.getElementById('passwordField').style.display = 'block';
            document.getElementById('activeField').style.display = 'none';
            document.getElementById('modalPassword').required = true;
            
            // フォームリセット
            document.getElementById('userForm').reset();
            
            new bootstrap.Modal(document.getElementById('userModal')).show();
        }
        
        // 編集モーダル
        function editUser(user) {
            document.getElementById('userModalTitle').textContent = 'ユーザー編集';
            document.getElementById('modalAction').value = 'edit';
            document.getElementById('modalUserId').value = user.id || '';
            document.getElementById('modalName').value = user.name || '';
            document.getElementById('modalLoginId').value = user.login_id || '';
            document.getElementById('modalRole').value = user.role || '';
            document.getElementById('modalIsActive').checked = user.is_active == 1;
            document.getElementById('passwordField').style.display = 'none';
            document.getElementById('activeField').style.display = 'block';
            document.getElementById('modalPassword').required = false;
            
            new bootstrap.Modal(document.getElementById('userModal')).show();
        }
        
        // パスワード変更モーダル
        function changePassword(userId, userName) {
            document.getElementById('passwordUserId').value = userId;
            document.getElementById('passwordUserName').textContent = userName;
            document.getElementById('newPassword').value = '';
            
            new bootstrap.Modal(document.getElementById('passwordModal')).show();
        }
        
        // 削除確認
        function deleteUser(userId, userName) {
            if (confirm(`ユーザー「${userName}」を削除しますか？`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="${userId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
