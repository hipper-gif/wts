<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// システム管理者権限チェック - permission_level基準に変更
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_permission = $stmt->fetchColumn();

if ($user_permission !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

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
            $permission_level = $_POST['permission_level'];
            
            // 職務フラグの処理
            $is_driver = isset($_POST['is_driver']) ? 1 : 0;
            $is_caller = isset($_POST['is_caller']) ? 1 : 0;
            $is_manager = isset($_POST['is_manager']) ? 1 : 0;
            
            // バリデーション
            if (empty($name) || empty($login_id) || empty($password)) {
                throw new Exception('すべての必須項目を入力してください。');
            }
            
            if (!$is_driver && !$is_caller && !$is_manager) {
                throw new Exception('少なくとも1つの職務を選択してください。');
            }
            
            // ログインID重複チェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ?");
            $stmt->execute([$login_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('このログインIDは既に使用されています。');
            }
            
            // パスワードハッシュ化
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // ユーザー追加
            $stmt = $pdo->prepare("
                INSERT INTO users (name, login_id, password, permission_level, is_driver, is_caller, is_manager, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, NOW())
            ");
            $stmt->execute([
                $name, $login_id, $hashed_password, $permission_level, 
                $is_driver, $is_caller, $is_manager
            ]);
            
            $success_message = 'ユーザーを追加しました。';
            
        } elseif ($action === 'edit') {
            // 編集
            $edit_user_id = $_POST['user_id'];
            $name = trim($_POST['name']);
            $login_id = trim($_POST['login_id']);
            $permission_level = $_POST['permission_level'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // 職務フラグの処理
            $is_driver = isset($_POST['is_driver']) ? 1 : 0;
            $is_caller = isset($_POST['is_caller']) ? 1 : 0;
            $is_manager = isset($_POST['is_manager']) ? 1 : 0;
            
            // バリデーション
            if (empty($name) || empty($login_id)) {
                throw new Exception('名前とログインIDは必須です。');
            }
            
            if (!$is_driver && !$is_caller && !$is_manager) {
                throw new Exception('少なくとも1つの職務を選択してください。');
            }
            
            // ログインID重複チェック（自分以外）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ? AND id != ?");
            $stmt->execute([$login_id, $edit_user_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('このログインIDは既に使用されています。');
            }
            
            // ユーザー更新
            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = ?, login_id = ?, permission_level = ?, is_driver = ?, is_caller = ?, is_manager = ?, is_active = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $login_id, $permission_level,
                $is_driver, $is_caller, $is_manager, $is_active, $edit_user_id
            ]);
            
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
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ユーザー一覧取得 - permission_level基準に変更
try {
    $stmt = $pdo->prepare("
        SELECT id, name, login_id, permission_level, is_driver, is_caller, is_manager, 
               COALESCE(is_active, 1) as is_active, created_at 
        FROM users 
        ORDER BY COALESCE(is_active, 1) DESC, permission_level, name
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = 'ユーザー一覧の取得に失敗しました: ' . $e->getMessage();
    $users = [];
}

// 権限表示用の関数
function getUserPermissions($user) {
    $permissions = [];
    if (isset($user['is_driver']) && $user['is_driver']) $permissions[] = '運転者';
    if (isset($user['is_caller']) && $user['is_caller']) $permissions[] = '点呼者';
    if (isset($user['is_manager']) && $user['is_manager']) $permissions[] = '管理者';
    return implode(' + ', $permissions);
}

// 安全な値取得関数
function safeGet($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

// permission_levelの表示名
function getPermissionLevelName($level) {
    switch ($level) {
        case 'Admin': return 'システム管理者';
        case 'User': return '一般ユーザー';
        default: return $level;
    }
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
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #6f42c1;
            box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.25);
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
                    <small>システム管理者専用</small>
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
        
        <!-- 新規追加ボタン -->
        <div class="mb-4">
            <button type="button" class="btn btn-primary" onclick="showAddModal()">
                <i class="fas fa-user-plus me-2"></i>新規ユーザー追加
            </button>
        </div>
        
        <!-- ユーザー一覧 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>ユーザー一覧</h5>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <p class="text-muted text-center">ユーザーが登録されていません。</p>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                    <div class="user-row <?= safeGet($user, 'is_active', 1) ? '' : 'inactive' ?>">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <h6 class="mb-1"><?= htmlspecialchars(safeGet($user, 'name', '名前未設定')) ?></h6>
                                <small class="text-muted">ID: <?= htmlspecialchars(safeGet($user, 'login_id', '未設定')) ?></small>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-<?= safeGet($user, 'permission_level') === 'Admin' ? 'danger' : 'primary' ?>">
                                    <?= getPermissionLevelName(safeGet($user, 'permission_level', 'User')) ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <div class="permissions-badges">
                                    <?php if (safeGet($user, 'is_driver')): ?>
                                        <span class="badge bg-success me-1 mb-1">運転者</span>
                                    <?php endif; ?>
                                    <?php if (safeGet($user, 'is_caller')): ?>
                                        <span class="badge bg-warning me-1 mb-1">点呼者</span>
                                    <?php endif; ?>
                                    <?php if (safeGet($user, 'is_manager')): ?>
                                        <span class="badge bg-info me-1 mb-1">管理者</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-1">
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
                            <label for="modalPermissionLevel" class="form-label">権限レベル <span class="text-danger">*</span></label>
                            <select class="form-select" id="modalPermissionLevel" name="permission_level" required>
                                <option value="User">一般ユーザー</option>
                                <option value="Admin">システム管理者</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">職務 <span class="text-danger">*</span></label>
                            <div class="border rounded p-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="modalIsDriver" name="is_driver">
                                    <label class="form-check-label" for="modalIsDriver">
                                        <span class="badge bg-success me-2">運転者</span>
                                        車両の運転業務を行う
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="modalIsCaller" name="is_caller">
                                    <label class="form-check-label" for="modalIsCaller">
                                        <span class="badge bg-warning me-2">点呼者</span>
                                        乗務前・乗務後点呼を実施する
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="modalIsManager" name="is_manager">
                                    <label class="form-check-label" for="modalIsManager">
                                        <span class="badge bg-info me-2">管理者</span>
                                        業務管理・報告業務を行う
                                    </label>
                                </div>
                            </div>
                            <small class="form-text text-muted">複数の職務を同時に選択することができます。</small>
                        </div>
                        
                        <div class="mb-3" id="isActiveField" style="display: none;">
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
            document.getElementById('isActiveField').style.display = 'none';
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
            document.getElementById('modalPermissionLevel').value = user.permission_level || 'User';
            document.getElementById('modalIsDriver').checked = user.is_driver == 1;
            document.getElementById('modalIsCaller').checked = user.is_caller == 1;
            document.getElementById('modalIsManager').checked = user.is_manager == 1;
            document.getElementById('modalIsActive').checked = user.is_active == 1;
            document.getElementById('passwordField').style.display = 'none';
            document.getElementById('isActiveField').style.display = 'block';
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
