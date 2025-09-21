<?php
/**
 * ユーザー管理システム v3.1
 * 福祉輸送管理システム - 統一ヘッダー対応版
 * 
 * 機能: 
 * - ユーザーの追加・編集・削除（論理削除）
 * - 6つの職務フラグ管理（is_driver, is_caller, is_inspector, is_admin, is_manager, is_mechanic）
 * - permission_level権限管理（Admin/User）
 * - パスワード変更機能
 * - 最適化済みusersテーブル（18カラム）完全対応
 * 
 * @version 3.1.0
 * @author 福祉輸送管理システム開発チーム
 * @created 2025-09-22
 */

session_start();
require_once 'config/database.php';
require_once 'includes/unified-header.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// システム管理者権限チェック - permission_level基準
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT permission_level, name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_data || $user_data['permission_level'] !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $user_data['name'];
$user_role = 'システム管理者';

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
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            // 職務フラグの処理（6つ）
            $is_driver = isset($_POST['is_driver']) ? 1 : 0;
            $is_caller = isset($_POST['is_caller']) ? 1 : 0;
            $is_inspector = isset($_POST['is_inspector']) ? 1 : 0;
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
            $is_manager = isset($_POST['is_manager']) ? 1 : 0;
            $is_mechanic = isset($_POST['is_mechanic']) ? 1 : 0;
            
            // バリデーション
            if (empty($name) || empty($login_id) || empty($password)) {
                throw new Exception('名前、ログインID、パスワードは必須です。');
            }
            
            if (!$is_driver && !$is_caller && !$is_inspector && !$is_admin && !$is_manager && !$is_mechanic) {
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
            
            // ユーザー追加（最適化済みテーブル構造対応）
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    name, login_id, password, permission_level, 
                    is_driver, is_caller, is_inspector, is_admin, is_manager, is_mechanic,
                    phone, email, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $name, $login_id, $hashed_password, $permission_level, 
                $is_driver, $is_caller, $is_inspector, $is_admin, $is_manager, $is_mechanic,
                $phone, $email
            ]);
            
            $success_message = 'ユーザーを追加しました。';
            
        } elseif ($action === 'edit') {
            // 編集
            $edit_user_id = $_POST['user_id'];
            $name = trim($_POST['name']);
            $login_id = trim($_POST['login_id']);
            $permission_level = $_POST['permission_level'];
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // 職務フラグの処理（6つ）
            $is_driver = isset($_POST['is_driver']) ? 1 : 0;
            $is_caller = isset($_POST['is_caller']) ? 1 : 0;
            $is_inspector = isset($_POST['is_inspector']) ? 1 : 0;
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
            $is_manager = isset($_POST['is_manager']) ? 1 : 0;
            $is_mechanic = isset($_POST['is_mechanic']) ? 1 : 0;
            
            // バリデーション
            if (empty($name) || empty($login_id)) {
                throw new Exception('名前とログインIDは必須です。');
            }
            
            if (!$is_driver && !$is_caller && !$is_inspector && !$is_admin && !$is_manager && !$is_mechanic) {
                throw new Exception('少なくとも1つの職務を選択してください。');
            }
            
            // ログインID重複チェック（自分以外）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login_id = ? AND id != ?");
            $stmt->execute([$login_id, $edit_user_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('このログインIDは既に使用されています。');
            }
            
            // ユーザー更新（最適化済みテーブル構造対応）
            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = ?, login_id = ?, permission_level = ?, 
                    is_driver = ?, is_caller = ?, is_inspector = ?, 
                    is_admin = ?, is_manager = ?, is_mechanic = ?,
                    phone = ?, email = ?, is_active = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $login_id, $permission_level,
                $is_driver, $is_caller, $is_inspector, $is_admin, $is_manager, $is_mechanic,
                $phone, $email, $is_active, $edit_user_id
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
            
            $stmt = $pdo->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$delete_user_id]);
            
            $success_message = 'ユーザーを削除しました。';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ユーザー一覧取得（最適化済みテーブル構造対応）
try {
    $stmt = $pdo->prepare("
        SELECT id, name, login_id, permission_level, 
               is_driver, is_caller, is_inspector, is_admin, is_manager, is_mechanic,
               phone, email, is_active, created_at, last_login_at
        FROM users 
        ORDER BY is_active DESC, permission_level, name
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
    if (!empty($user['is_driver'])) $permissions[] = '運転者';
    if (!empty($user['is_caller'])) $permissions[] = '点呼者';
    if (!empty($user['is_inspector'])) $permissions[] = '点検者';
    if (!empty($user['is_admin'])) $permissions[] = 'システム管理';
    if (!empty($user['is_manager'])) $permissions[] = '管理者';
    if (!empty($user['is_mechanic'])) $permissions[] = '整備者';
    return empty($permissions) ? '権限なし' : implode(' + ', $permissions);
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

// 統一ヘッダーシステム適用
$page_config = getPageConfiguration('user_management');

// ページオプション
$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [],
    'additional_js' => [],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '管理機能', 'url' => 'master_menu.php'],
        ['text' => 'ユーザー管理', 'url' => 'user_management.php']
    ]
];

// 完全ページ生成
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'user_management',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

// HTMLヘッダー出力
echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<main class="main-content">
    <div class="container-fluid">
        <!-- アラート表示 -->
        <?php if ($success_message): ?>
            <?= renderAlert('success', '成功', $success_message) ?>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>
        
        <!-- メインアクションエリア -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="action-content">
                        <h3>新規ユーザー追加</h3>
                        <p>システムの新しいユーザーを追加し、適切な権限と職務を設定します</p>
                        <button type="button" class="btn btn-primary" onclick="showAddModal()">
                            <i class="fas fa-plus me-2"></i>ユーザー追加
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-header">
                        <h4>ユーザー統計</h4>
                    </div>
                    <div class="stats-content">
                        <div class="stat-item">
                            <span class="stat-value"><?= count(array_filter($users, function($u) { return $u['is_active']; })) ?></span>
                            <span class="stat-label">有効ユーザー</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= count(array_filter($users, function($u) { return $u['permission_level'] === 'Admin'; })) ?></span>
                            <span class="stat-label">管理者</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= count(array_filter($users, function($u) { return $u['is_driver']; })) ?></span>
                            <span class="stat-label">運転者</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ユーザー一覧 -->
        <div class="content-card">
            <div class="card-header-flex">
                <div>
                    <h2><i class="fas fa-list me-2"></i>ユーザー一覧</h2>
                    <p class="text-muted">システムに登録されているユーザーの管理</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-outline-primary btn-sm" onclick="refreshUserList()">
                        <i class="fas fa-sync-alt me-1"></i>更新
                    </button>
                </div>
            </div>
            
            <div class="user-list">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x"></i>
                        <h3>ユーザーが登録されていません</h3>
                        <p>最初のユーザーを追加してください</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                    <div class="user-item <?= $user['is_active'] ? '' : 'user-inactive' ?>">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-info">
                            <div class="user-name-section">
                                <h4><?= htmlspecialchars($user['name']) ?></h4>
                                <span class="user-id">ID: <?= htmlspecialchars($user['login_id']) ?></span>
                            </div>
                            <div class="user-details">
                                <div class="user-permission">
                                    <span class="permission-badge <?= $user['permission_level'] === 'Admin' ? 'admin' : 'user' ?>">
                                        <?= getPermissionLevelName($user['permission_level']) ?>
                                    </span>
                                </div>
                                <div class="user-roles">
                                    <?php if ($user['is_driver']): ?>
                                        <span class="role-badge driver">運転者</span>
                                    <?php endif; ?>
                                    <?php if ($user['is_caller']): ?>
                                        <span class="role-badge caller">点呼者</span>
                                    <?php endif; ?>
                                    <?php if ($user['is_inspector']): ?>
                                        <span class="role-badge inspector">点検者</span>
                                    <?php endif; ?>
                                    <?php if ($user['is_admin']): ?>
                                        <span class="role-badge admin">システム管理</span>
                                    <?php endif; ?>
                                    <?php if ($user['is_manager']): ?>
                                        <span class="role-badge manager">管理者</span>
                                    <?php endif; ?>
                                    <?php if ($user['is_mechanic']): ?>
                                        <span class="role-badge mechanic">整備者</span>
                                    <?php endif; ?>
                                </div>
                                <div class="user-contact">
                                    <?php if (!empty($user['phone'])): ?>
                                        <span><i class="fas fa-phone me-1"></i><?= htmlspecialchars($user['phone']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($user['email'])): ?>
                                        <span><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($user['email']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="user-status">
                            <div class="status-indicator <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $user['is_active'] ? '有効' : '無効' ?>
                            </div>
                            <?php if (!empty($user['last_login_at'])): ?>
                                <div class="last-login">
                                    最終ログイン: <?= date('m/d H:i', strtotime($user['last_login_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="user-actions">
                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                    onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)"
                                    title="編集">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                    onclick="changePassword(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name']) ?>')"
                                    title="パスワード変更">
                                <i class="fas fa-key"></i>
                            </button>
                            <?php if ($user['id'] != $user_id): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                    onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name']) ?>')"
                                    title="削除">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- ユーザー追加・編集モーダル -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">ユーザー追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm" method="POST">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="user_id" id="modalUserId">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="modalName" class="form-label">氏名 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modalName" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="modalLoginId" class="form-label">ログインID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modalLoginId" name="login_id" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="modalPhone" class="form-label">電話番号</label>
                                <input type="tel" class="form-control" id="modalPhone" name="phone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="modalEmail" class="form-label">メールアドレス</label>
                                <input type="email" class="form-control" id="modalEmail" name="email">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="passwordField">
                        <label for="modalPassword" class="form-label">パスワード <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="modalPassword" name="password">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="modalPermissionLevel" class="form-label">権限レベル <span class="text-danger">*</span></label>
                                <select class="form-select" id="modalPermissionLevel" name="permission_level" required>
                                    <option value="User">一般ユーザー</option>
                                    <option value="Admin">システム管理者</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6" id="isActiveField" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">ステータス</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="modalIsActive" name="is_active" checked>
                                    <label class="form-check-label" for="modalIsActive">
                                        有効
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">職務権限 <span class="text-danger">*</span></label>
                        <div class="roles-grid">
                            <div class="role-item">
                                <input class="form-check-input" type="checkbox" id="modalIsDriver" name="is_driver">
                                <label class="form-check-label" for="modalIsDriver">
                                    <span class="role-badge driver">運転者</span>
                                    <small class="role-description">車両の運転業務を行う</small>
                                </label>
                            </div>
                            <div class="role-item">
                                <input class="form-check-input" type="checkbox" id="modalIsCaller" name="is_caller">
                                <label class="form-check-label" for="modalIsCaller">
                                    <span class="role-badge caller">点呼者</span>
                                    <small class="role-description">乗務前・乗務後点呼を実施</small>
                                </label>
                            </div>
                            <div class="role-item">
                                <input class="form-check-input" type="checkbox" id="modalIsInspector" name="is_inspector">
                                <label class="form-check-label" for="modalIsInspector">
                                    <span class="role-badge inspector">点検者</span>
                                    <small class="role-description">日常・定期点検を実施</small>
                                </label>
                            </div>
                            <div class="role-item">
                                <input class="form-check-input" type="checkbox" id="modalIsAdmin" name="is_admin">
                                <label class="form-check-label" for="modalIsAdmin">
                                    <span class="role-badge admin">システム管理</span>
                                    <small class="role-description">システム全体の管理権限</small>
                                </label>
                            </div>
                            <div class="role-item">
                                <input class="form-check-input" type="checkbox" id="modalIsManager" name="is_manager">
                                <label class="form-check-label" for="modalIsManager">
                                    <span class="role-badge manager">管理者</span>
                                    <small class="role-description">業務管理・監督を行う</small>
                                </label>
                            </div>
                            <div class="role-item">
                                <input class="form-check-input" type="checkbox" id="modalIsMechanic" name="is_mechanic">
                                <label class="form-check-label" for="modalIsMechanic">
                                    <span class="role-badge mechanic">整備者</span>
                                    <small class="role-description">車両整備・メンテナンス</small>
                                </label>
                            </div>
                        </div>
                        <small class="form-text text-muted">複数の職務を同時に選択することができます。少なくとも1つは選択してください。</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>保存
                    </button>
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
                        <input type="password" class="form-control" id="newPassword" name="new_password" required minlength="6">
                        <small class="form-text text-muted">6文字以上で入力してください</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i>パスワード変更
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
    document.getElementById('modalPhone').value = user.phone || '';
    document.getElementById('modalEmail').value = user.email || '';
    document.getElementById('modalPermissionLevel').value = user.permission_level || 'User';
    
    // 職務フラグ設定（6つ）
    document.getElementById('modalIsDriver').checked = user.is_driver == 1;
    document.getElementById('modalIsCaller').checked = user.is_caller == 1;
    document.getElementById('modalIsInspector').checked = user.is_inspector == 1;
    document.getElementById('modalIsAdmin').checked = user.is_admin == 1;
    document.getElementById('modalIsManager').checked = user.is_manager == 1;
    document.getElementById('modalIsMechanic').checked = user.is_mechanic == 1;
    
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
    if (confirm(`ユーザー「${userName}」を削除しますか？\n\n※論理削除のため、データは残りますが無効になります。`)) {
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

// リスト更新
function refreshUserList() {
    location.reload();
}

// 職務選択バリデーション
document.getElementById('userForm').addEventListener('submit', function(e) {
    const checkboxes = ['modalIsDriver', 'modalIsCaller', 'modalIsInspector', 'modalIsAdmin', 'modalIsManager', 'modalIsMechanic'];
    const isAnyChecked = checkboxes.some(id => document.getElementById(id).checked);
    
    if (!isAnyChecked) {
        e.preventDefault();
        alert('少なくとも1つの職務を選択してください。');
    }
});
</script>

<?= $page_data['html_footer'] ?>
