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

require_once 'config/database.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

// 権限チェック（Admin限定ページ）
if ($user_role !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

$success_message = '';
$error_message = '';

// --- 監査ログ関数 ---
function logUserManagementAudit($pdo, $user_id, $user_name, $action, $details = '') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inspection_audit_logs (user_id, user_name, action, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $user_name, '[ユーザー管理] ' . $action, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (PDOException $e) {
        // ログ記録失敗は握り潰す
    }
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
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
            $pdo->beginTransaction();
            try {
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
                logUserManagementAudit($pdo, $user_id, $user_name, 'ユーザー追加', "name={$name}, login_id={$login_id}");
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

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

            // 乗務員台帳フィールド
            $date_of_birth = trim($_POST['date_of_birth'] ?? '') ?: null;
            $hire_date = trim($_POST['hire_date'] ?? '') ?: null;
            $address = trim($_POST['address'] ?? '') ?: null;
            $emergency_contact = trim($_POST['emergency_contact'] ?? '') ?: null;
            $driver_license_number = trim($_POST['driver_license_number'] ?? '') ?: null;
            $driver_license_type = trim($_POST['driver_license_type'] ?? '') ?: null;
            $driver_license_expiry = trim($_POST['driver_license_expiry'] ?? '') ?: null;
            $care_qualification = trim($_POST['care_qualification'] ?? '') ?: null;
            $care_qualification_date = trim($_POST['care_qualification_date'] ?? '') ?: null;
            $health_check_date = trim($_POST['health_check_date'] ?? '') ?: null;
            $health_check_next = trim($_POST['health_check_next'] ?? '') ?: null;
            $aptitude_test_date = trim($_POST['aptitude_test_date'] ?? '') ?: null;
            $aptitude_test_next = trim($_POST['aptitude_test_next'] ?? '') ?: null;
            $notes = trim($_POST['notes'] ?? '') ?: null;

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

            // ユーザー更新（乗務員台帳フィールド含む）
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET name = ?, login_id = ?, permission_level = ?,
                        is_driver = ?, is_caller = ?, is_inspector = ?,
                        is_admin = ?, is_manager = ?, is_mechanic = ?,
                        phone = ?, email = ?, is_active = ?,
                        date_of_birth = ?, hire_date = ?, address = ?, emergency_contact = ?,
                        driver_license_number = ?, driver_license_type = ?, driver_license_expiry = ?,
                        care_qualification = ?, care_qualification_date = ?,
                        health_check_date = ?, health_check_next = ?,
                        aptitude_test_date = ?, aptitude_test_next = ?,
                        notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name, $login_id, $permission_level,
                    $is_driver, $is_caller, $is_inspector, $is_admin, $is_manager, $is_mechanic,
                    $phone, $email, $is_active,
                    $date_of_birth, $hire_date, $address, $emergency_contact,
                    $driver_license_number, $driver_license_type, $driver_license_expiry,
                    $care_qualification, $care_qualification_date,
                    $health_check_date, $health_check_next,
                    $aptitude_test_date, $aptitude_test_next,
                    $notes, $edit_user_id
                ]);
                logUserManagementAudit($pdo, $user_id, $user_name, 'ユーザー編集', "target_id={$edit_user_id}, name={$name}");
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
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
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $edit_user_id]);
                logUserManagementAudit($pdo, $user_id, $user_name, 'パスワード変更', "target_id={$edit_user_id}");
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            $success_message = 'パスワードを変更しました。';
            
        } elseif ($action === 'delete') {
            // 削除（論理削除）
            $delete_user_id = $_POST['user_id'];
            
            // 自分自身は削除不可
            if ($delete_user_id == $user_id) {
                throw new Exception('自分自身は削除できません。');
            }
            
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$delete_user_id]);
                logUserManagementAudit($pdo, $user_id, $user_name, 'ユーザー削除', "target_id={$delete_user_id}");
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

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
               phone, email, is_active, created_at, last_login_at,
               date_of_birth, hire_date, address, emergency_contact,
               driver_license_number, driver_license_type, driver_license_expiry,
               care_qualification, care_qualification_date,
               health_check_date, health_check_next,
               aptitude_test_date, aptitude_test_next,
               notes
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
    'additional_css' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
    ],
    'additional_js' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
        'js/ui-interactions.js'
    ],
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
        <!-- モーダル動作確認用CSS -->
        <style>
        .modal {
            z-index: 1055 !important;
        }
        .modal-backdrop {
            z-index: 1050 !important;
        }
        .modal-dialog {
            pointer-events: auto !important;
        }
        .modal-content {
            pointer-events: auto !important;
        }
        
        /* ユーザー管理専用スタイル */
        .user-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 1rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .user-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .user-item.user-inactive {
            opacity: 0.6;
            background: #f8f9fa;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            margin-right: 1rem;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name-section h4 {
            margin: 0;
            font-size: 1.1rem;
            color: #333;
        }
        
        .user-id {
            color: #666;
            font-size: 0.9rem;
        }
        
        .permission-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .permission-badge.admin {
            background: #dc3545;
            color: white;
        }
        
        .permission-badge.user {
            background: #6c757d;
            color: white;
        }
        
        .role-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            margin: 0.2rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .role-badge.driver { background: #28a745; color: white; }
        .role-badge.caller { background: #ffc107; color: #333; }
        .role-badge.inspector { background: #17a2b8; color: white; }
        .role-badge.admin { background: #dc3545; color: white; }
        .role-badge.manager { background: #6f42c1; color: white; }
        .role-badge.mechanic { background: #fd7e14; color: white; }
        
        .user-contact span {
            display: block;
            color: #666;
            font-size: 0.8rem;
        }
        
        .status-indicator {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            text-align: center;
        }
        
        .status-indicator.active {
            background: #d1e7dd;
            color: #0f5132;
        }
        
        .status-indicator.inactive {
            background: #f8d7da;
            color: #842029;
        }
        
        .last-login {
            font-size: 0.75rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .user-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .role-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .role-item:hover {
            background-color: #f8f9fa;
        }
        
        .role-item input[type="checkbox"] {
            margin-right: 0.75rem;
        }
        
        .role-item label {
            margin: 0;
            cursor: pointer;
            flex: 1;
        }
        
        .role-description {
            display: block;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .action-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .action-icon {
            font-size: 3rem;
            margin-right: 1.5rem;
            opacity: 0.8;
        }
        
        .action-content h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.5rem;
        }
        
        .action-content p {
            margin: 0 0 1rem 0;
            opacity: 0.9;
        }
        
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            height: 100%;
        }
        
        .stats-content {
            display: flex;
            justify-content: space-around;
            margin-top: 1rem;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            display: block;
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #666;
        }
        
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .card-header-flex h2 {
            margin: 0;
            color: #333;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .empty-state i {
            color: #ddd;
            margin-bottom: 1rem;
        }
        </style>
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
            <div class="col-lg-8">
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
            <div class="col-lg-4">
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
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">ユーザー追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="user_id" id="modalUserId">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="modalName" class="form-label">氏名 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modalName" name="name" required>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="modalLoginId" class="form-label">ログインID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modalLoginId" name="login_id" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="modalPhone" class="form-label">電話番号</label>
                                <input type="tel" class="form-control" id="modalPhone" name="phone">
                            </div>
                        </div>
                        <div class="col-lg-6">
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
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label for="modalPermissionLevel" class="form-label">権限レベル <span class="text-danger">*</span></label>
                                <select class="form-select" id="modalPermissionLevel" name="permission_level" required>
                                    <option value="User">一般ユーザー</option>
                                    <option value="Admin">システム管理者</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6" id="isActiveField" style="display: none;">
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
                                <input class="form-check-input" type="checkbox" id="modalIsDriver" name="is_driver" onchange="toggleDriverProfileSection()">
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

                    <!-- 乗務員台帳セクション（is_driverのみ表示） -->
                    <div id="driverProfileSection" style="display:none;">
                        <hr class="my-3">
                        <h6 class="fw-bold text-primary mb-3"><i class="fas fa-id-card me-2"></i>乗務員台帳情報</h6>

                        <!-- 基本情報 -->
                        <div class="row">
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label for="modalDateOfBirth" class="form-label">生年月日</label>
                                    <input type="date" class="form-control" id="modalDateOfBirth" name="date_of_birth">
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label for="modalHireDate" class="form-label">入社日</label>
                                    <input type="date" class="form-control" id="modalHireDate" name="hire_date">
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label for="modalEmergencyContact" class="form-label">緊急連絡先</label>
                                    <input type="text" class="form-control" id="modalEmergencyContact" name="emergency_contact" placeholder="氏名・電話番号">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="modalAddress" class="form-label">住所</label>
                            <input type="text" class="form-control" id="modalAddress" name="address">
                        </div>

                        <!-- 運転免許情報 -->
                        <h6 class="fw-bold text-secondary mb-2 mt-3"><i class="fas fa-car me-2"></i>運転免許情報</h6>
                        <div class="row">
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label for="modalLicenseNumber" class="form-label">免許証番号</label>
                                    <input type="text" class="form-control" id="modalLicenseNumber" name="driver_license_number" placeholder="12桁">
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label for="modalLicenseType" class="form-label">免許種別</label>
                                    <input type="text" class="form-control" id="modalLicenseType" name="driver_license_type" placeholder="普通二種 等">
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label for="modalLicenseExpiry" class="form-label">有効期限</label>
                                    <input type="date" class="form-control" id="modalLicenseExpiry" name="driver_license_expiry">
                                </div>
                            </div>
                        </div>

                        <!-- 介護資格 -->
                        <h6 class="fw-bold text-secondary mb-2 mt-3"><i class="fas fa-hands-helping me-2"></i>介護資格</h6>
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label for="modalCareQualification" class="form-label">資格名</label>
                                    <input type="text" class="form-control" id="modalCareQualification" name="care_qualification" placeholder="介護職員初任者研修 等">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label for="modalCareQualificationDate" class="form-label">取得日</label>
                                    <input type="date" class="form-control" id="modalCareQualificationDate" name="care_qualification_date">
                                </div>
                            </div>
                        </div>

                        <!-- 健康診断・適性診断 -->
                        <h6 class="fw-bold text-secondary mb-2 mt-3"><i class="fas fa-stethoscope me-2"></i>健康診断・適性診断</h6>
                        <div class="row">
                            <div class="col-lg-3">
                                <div class="mb-3">
                                    <label for="modalHealthCheckDate" class="form-label">健康診断（実施日）</label>
                                    <input type="date" class="form-control" id="modalHealthCheckDate" name="health_check_date">
                                </div>
                            </div>
                            <div class="col-lg-3">
                                <div class="mb-3">
                                    <label for="modalHealthCheckNext" class="form-label">健康診断（次回予定）</label>
                                    <input type="date" class="form-control" id="modalHealthCheckNext" name="health_check_next">
                                </div>
                            </div>
                            <div class="col-lg-3">
                                <div class="mb-3">
                                    <label for="modalAptitudeTestDate" class="form-label">適性診断（実施日）</label>
                                    <input type="date" class="form-control" id="modalAptitudeTestDate" name="aptitude_test_date">
                                </div>
                            </div>
                            <div class="col-lg-3">
                                <div class="mb-3">
                                    <label for="modalAptitudeTestNext" class="form-label">適性診断（次回予定）</label>
                                    <input type="date" class="form-control" id="modalAptitudeTestNext" name="aptitude_test_next">
                                </div>
                            </div>
                        </div>

                        <!-- 備考 -->
                        <div class="mb-3">
                            <label for="modalNotes" class="form-label">備考・メモ</label>
                            <textarea class="form-control" id="modalNotes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success">
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
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
// Bootstrap確実読み込み確認とフォールバック機能
document.addEventListener('DOMContentLoaded', function() {
    // Bootstrapが読み込まれていない場合の緊急対応
    if (typeof bootstrap === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
        script.onload = function() {
            console.log('Bootstrap loaded successfully');
        };
        document.head.appendChild(script);
        
        // Bootstrap CSSも確実に読み込み
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
        document.head.appendChild(link);
    }
    
    // シンプルなモーダル機能（フォールバック）
    window.showModalFallback = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'block';
            modal.classList.add('show');
            document.body.classList.add('modal-open');
            
            // 背景を追加
            let backdrop = document.querySelector('.modal-backdrop');
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                document.body.appendChild(backdrop);
                
                // 背景クリックで閉じる
                backdrop.addEventListener('click', function() {
                    hideModalFallback(modalId);
                });
            }
        }
    };
    
    window.hideModalFallback = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');
            
            // 背景を削除
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
        }
    };
});

// 新規追加モーダル（確実動作版）
function showAddModal() {
    const modalElement = document.getElementById('userModal');
    if (!modalElement) {
        console.error('Modal element not found');
        return;
    }
    
    document.getElementById('userModalTitle').textContent = 'ユーザー追加';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalUserId').value = '';
    document.getElementById('passwordField').style.display = 'block';
    document.getElementById('isActiveField').style.display = 'none';
    document.getElementById('modalPassword').required = true;
    
    // フォームリセット
    document.getElementById('userForm').reset();
    toggleDriverProfileSection();

    // Bootstrap使用可能かチェック
    if (typeof bootstrap !== 'undefined') {
        try {
            const existingModal = bootstrap.Modal.getInstance(modalElement);
            if (existingModal) {
                existingModal.dispose();
            }

            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            modal.show();
        } catch (error) {
            console.error('Bootstrap modal error:', error);
            showModalFallback('userModal');
        }
    } else {
        // フォールバック使用
        showModalFallback('userModal');
    }
}

// 運転者チェックで台帳セクション表示切替
function toggleDriverProfileSection() {
    const isDriver = document.getElementById('modalIsDriver').checked;
    document.getElementById('driverProfileSection').style.display = isDriver ? 'block' : 'none';
}

// 編集モーダル（確実動作版）
function editUser(user) {
    const modalElement = document.getElementById('userModal');
    if (!modalElement) {
        console.error('Modal element not found');
        return;
    }
    
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

    // 乗務員台帳フィールド
    document.getElementById('modalDateOfBirth').value = user.date_of_birth || '';
    document.getElementById('modalHireDate').value = user.hire_date || '';
    document.getElementById('modalAddress').value = user.address || '';
    document.getElementById('modalEmergencyContact').value = user.emergency_contact || '';
    document.getElementById('modalLicenseNumber').value = user.driver_license_number || '';
    document.getElementById('modalLicenseType').value = user.driver_license_type || '';
    document.getElementById('modalLicenseExpiry').value = user.driver_license_expiry || '';
    document.getElementById('modalCareQualification').value = user.care_qualification || '';
    document.getElementById('modalCareQualificationDate').value = user.care_qualification_date || '';
    document.getElementById('modalHealthCheckDate').value = user.health_check_date || '';
    document.getElementById('modalHealthCheckNext').value = user.health_check_next || '';
    document.getElementById('modalAptitudeTestDate').value = user.aptitude_test_date || '';
    document.getElementById('modalAptitudeTestNext').value = user.aptitude_test_next || '';
    document.getElementById('modalNotes').value = user.notes || '';

    // 運転者の場合に台帳セクション表示
    toggleDriverProfileSection();
    
    // Bootstrap使用可能かチェック
    if (typeof bootstrap !== 'undefined') {
        try {
            const existingModal = bootstrap.Modal.getInstance(modalElement);
            if (existingModal) {
                existingModal.dispose();
            }
            
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            modal.show();
        } catch (error) {
            console.error('Bootstrap modal error:', error);
            showModalFallback('userModal');
        }
    } else {
        showModalFallback('userModal');
    }
}

// パスワード変更モーダル（確実動作版）
function changePassword(userId, userName) {
    const modalElement = document.getElementById('passwordModal');
    if (!modalElement) {
        console.error('Password modal element not found');
        return;
    }
    
    document.getElementById('passwordUserId').value = userId;
    document.getElementById('passwordUserName').textContent = userName;
    document.getElementById('newPassword').value = '';
    
    // Bootstrap使用可能かチェック
    if (typeof bootstrap !== 'undefined') {
        try {
            const existingModal = bootstrap.Modal.getInstance(modalElement);
            if (existingModal) {
                existingModal.dispose();
            }
            
            const modal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            modal.show();
        } catch (error) {
            console.error('Bootstrap modal error:', error);
            showModalFallback('passwordModal');
        }
    } else {
        showModalFallback('passwordModal');
    }
}

// モーダル閉じるボタンのイベントハンドラー（確実動作版）
document.addEventListener('DOMContentLoaded', function() {
    // 閉じるボタンにイベントリスナーを追加
    const closeButtons = document.querySelectorAll('[data-bs-dismiss="modal"]');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const modal = button.closest('.modal');
            if (modal) {
                if (typeof bootstrap !== 'undefined') {
                    try {
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) {
                            modalInstance.hide();
                        } else {
                            hideModalFallback(modal.id);
                        }
                    } catch (error) {
                        hideModalFallback(modal.id);
                    }
                } else {
                    hideModalFallback(modal.id);
                }
            }
        });
    });
    
    // ESCキーでモーダルを閉じる
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const visibleModals = document.querySelectorAll('.modal.show');
            visibleModals.forEach(modal => {
                if (typeof bootstrap !== 'undefined') {
                    try {
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) {
                            modalInstance.hide();
                        } else {
                            hideModalFallback(modal.id);
                        }
                    } catch (error) {
                        hideModalFallback(modal.id);
                    }
                } else {
                    hideModalFallback(modal.id);
                }
            });
        }
    });
});

// 削除確認
function deleteUser(userId, userName) {
    showConfirm('ユーザー「' + userName + '」を削除しますか？\n\n※論理削除のため、データは残りますが無効になります。', function() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="${userId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }, {
        type: 'danger',
        confirmText: '削除する'
    });
}

// リスト更新
function refreshUserList() {
    location.reload();
}

// 職務選択バリデーション（確実動作版）
document.addEventListener('DOMContentLoaded', function() {
    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', function(e) {
            const checkboxes = ['modalIsDriver', 'modalIsCaller', 'modalIsInspector', 'modalIsAdmin', 'modalIsManager', 'modalIsMechanic'];
            const isAnyChecked = checkboxes.some(id => {
                const element = document.getElementById(id);
                return element && element.checked;
            });
            
            if (!isAnyChecked) {
                e.preventDefault();
                showToast('少なくとも1つの職務を選択してください。', 'warning');
                return false;
            }
        });
    }
});

// デバッグ用：モーダル状態確認
function debugModal() {
    console.log('Bootstrap available:', typeof bootstrap !== 'undefined');
    console.log('User modal element:', document.getElementById('userModal'));
    console.log('Password modal element:', document.getElementById('passwordModal'));
}
</script>

<?= $page_data['html_footer'] ?>
