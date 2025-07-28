<?php
/**
 * user_management.php デバッグ・修正版
 * 権限修正後の動作確認とトラブルシューティング
 */

session_start();
require_once 'config/database.php';
require_once 'updated_user_functions.php';

// デバッグモード（問題調査用）
$debug_mode = isset($_GET['debug']) ? true : false;

if ($debug_mode) {
    echo "<div style='background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin: 10px 0;'>";
    echo "<h4>🔍 デバッグ情報</h4>";
    
    // セッション情報確認
    echo "<strong>現在のセッション情報:</strong><br>";
    if (isset($_SESSION['user_id'])) {
        echo "ユーザーID: " . $_SESSION['user_id'] . "<br>";
        echo "ユーザー名: " . ($_SESSION['user_name'] ?? '未設定') . "<br>";
        echo "権限: " . ($_SESSION['role'] ?? '未設定') . "<br>";
    } else {
        echo "❌ セッション情報なし（ログインしていない）<br>";
    }
    
    // データベース接続確認
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
        echo "✅ データベース接続成功<br>";
        
        // usersテーブル構造確認
        $stmt = $pdo->query("DESCRIBE users");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<strong>usersテーブル構造:</strong><br>";
        foreach ($columns as $column) {
            $highlight = in_array($column['Field'], ['role', 'is_driver', 'is_caller', 'is_inspector']) ? 
                        ' style="background-color: #e8f5e8;"' : '';
            echo "<span{$highlight}>{$column['Field']} ({$column['Type']})</span><br>";
        }
        
        // 現在のユーザーデータ確認
        $stmt = $pdo->query("SELECT * FROM users ORDER BY name");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<strong>現在のユーザーデータ:</strong><br>";
        echo "<table border='1' style='border-collapse: collapse; font-size: 12px;'>";
        echo "<tr><th>ID</th><th>名前</th><th>role</th><th>運転者</th><th>点呼者</th><th>点検者</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['name']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>" . (isset($user['is_driver']) ? ($user['is_driver'] ? 'Yes' : 'No') : '未設定') . "</td>";
            echo "<td>" . (isset($user['is_caller']) ? ($user['is_caller'] ? 'Yes' : 'No') : '未設定') . "</td>";
            echo "<td>" . (isset($user['is_inspector']) ? ($user['is_inspector'] ? 'Yes' : 'No') : '未設定') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } catch (PDOException $e) {
        echo "❌ データベース接続エラー: " . $e->getMessage() . "<br>";
    }
    
    echo "</div>";
    echo "<hr>";
}

// 権限チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=login_required');
    exit;
}

// 管理者権限チェック（既存方式と新方式の併用）
$is_admin = false;
if (isset($_SESSION['role'])) {
    $is_admin = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'システム管理者' || $_SESSION['role'] === '管理者');
}

if (!$is_admin) {
    echo "<div class='alert alert-danger'>管理者権限が必要です。現在の権限: " . ($_SESSION['role'] ?? '未設定') . "</div>";
    echo "<a href='dashboard.php'>ダッシュボードに戻る</a>";
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // POST処理（ユーザー更新）
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeeba; padding: 10px; margin: 10px 0;'>";
        echo "<strong>🔄 更新処理実行中...</strong><br>";
        
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update':
                    echo "更新処理開始<br>";
                    echo "受信データ: ";
                    print_r($_POST);
                    echo "<br>";
                    
                    // 業務属性のチェック状態確認
                    $is_driver = isset($_POST['is_driver']) ? 1 : 0;
                    $is_caller = isset($_POST['is_caller']) ? 1 : 0;
                    $is_inspector = isset($_POST['is_inspector']) ? 1 : 0;
                    
                    echo "設定値: driver={$is_driver}, caller={$is_caller}, inspector={$is_inspector}<br>";
                    
                    // データベース更新
                    $stmt = $pdo->prepare("
                        UPDATE users SET 
                            name = ?, 
                            login_id = ?, 
                            role = ?,
                            is_driver = ?,
                            is_caller = ?,
                            is_inspector = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    
                    $result = $stmt->execute([
                        $_POST['name'],
                        $_POST['login_id'],
                        $_POST['role'],
                        $is_driver,
                        $is_caller,
                        $is_inspector,
                        $_POST['user_id']
                    ]);
                    
                    if ($result) {
                        echo "✅ 更新成功（影響行数: " . $stmt->rowCount() . "）<br>";
                    } else {
                        echo "❌ 更新失敗<br>";
                    }
                    break;
                    
                case 'add':
                    echo "新規追加処理開始<br>";
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, login_id, password, role, is_driver, is_caller, is_inspector) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $result = $stmt->execute([
                        $_POST['name'],
                        $_POST['login_id'],
                        password_hash($_POST['password'], PASSWORD_DEFAULT),
                        $_POST['role'],
                        isset($_POST['is_driver']) ? 1 : 0,
                        isset($_POST['is_caller']) ? 1 : 0,
                        isset($_POST['is_inspector']) ? 1 : 0
                    ]);
                    
                    if ($result) {
                        echo "✅ 新規追加成功<br>";
                    } else {
                        echo "❌ 新規追加失敗<br>";
                    }
                    break;
                    
                case 'delete':
                    echo "削除処理開始<br>";
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $result = $stmt->execute([$_POST['user_id']]);
                    
                    if ($result) {
                        echo "✅ 削除成功（影響行数: " . $stmt->rowCount() . "）<br>";
                    } else {
                        echo "❌ 削除失敗<br>";
                    }
                    break;
            }
        }
        echo "</div>";
    }
    
    // ユーザー一覧取得
    $users = getAllUsers($pdo);
    
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>データベースエラー: " . $e->getMessage() . "</div>";
    exit;
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
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-users"></i> ユーザー管理</h2>
                    <div>
                        <a href="?debug=1" class="btn btn-info btn-sm">🔍 デバッグモード</a>
                        <a href="dashboard.php" class="btn btn-secondary">ダッシュボード</a>
                    </div>
                </div>

                <!-- ユーザー一覧 -->
                <div class="card">
                    <div class="card-header">
                        <h5>登録済みユーザー</h5>
                        <button class="btn btn-primary btn-sm" onclick="showAddUserModal()">
                            <i class="fas fa-plus"></i> 新規追加
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>ユーザー名</th>
                                        <th>ログインID</th>
                                        <th>権限</th>
                                        <th>業務属性</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= $user['id'] ?></td>
                                        <td><?= htmlspecialchars($user['name']) ?></td>
                                        <td><?= htmlspecialchars($user['login_id']) ?></td>
                                        <td>
                                            <span class="badge <?= $user['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                                                <?= $user['role_display'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small><?= $user['attributes_display'] ?></small>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
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

    <!-- ユーザー編集モーダル -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="userForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">ユーザー編集</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="action" value="update">
                        <input type="hidden" name="user_id" id="user_id">
                        
                        <div class="mb-3">
                            <label class="form-label">ユーザー名</label>
                            <input type="text" name="name" id="name" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ログインID</label>
                            <input type="text" name="login_id" id="login_id" class="form-control" required>
                        </div>
                        
                        <div class="mb-3" id="passwordField" style="display: none;">
                            <label class="form-label">パスワード</label>
                            <input type="password" name="password" id="password" class="form-control">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">システム権限</label>
                            <select name="role" id="role" class="form-control" required>
                                <option value="user">ユーザー</option>
                                <option value="admin">管理者</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">業務属性（複数選択可）</label>
                            <div class="form-check">
                                <input type="checkbox" name="is_driver" id="is_driver" class="form-check-input" value="1">
                                <label class="form-check-label" for="is_driver">運転者</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_caller" id="is_caller" class="form-check-input" value="1">
                                <label class="form-check-label" for="is_caller">点呼者</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_inspector" id="is_inspector" class="form-check-input" value="1">
                                <label class="form-check-label" for="is_inspector">点検者</label>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showAddUserModal() {
            document.getElementById('modalTitle').textContent = '新規ユーザー追加';
            document.getElementById('action').value = 'add';
            document.getElementById('user_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('login_id').value = '';
            document.getElementById('password').value = '';
            document.getElementById('role').value = 'user';
            document.getElementById('is_driver').checked = true;
            document.getElementById('is_caller').checked = false;
            document.getElementById('is_inspector').checked = false;
            document.getElementById('passwordField').style.display = 'block';
            document.getElementById('password').required = true;
            
            new bootstrap.Modal(document.getElementById('userModal')).show();
        }

        function editUser(user) {
            document.getElementById('modalTitle').textContent = 'ユーザー編集';
            document.getElementById('action').value = 'update';
            document.getElementById('user_id').value = user.id;
            document.getElementById('name').value = user.name;
            document.getElementById('login_id').value = user.login_id;
            document.getElementById('role').value = user.role;
            document.getElementById('is_driver').checked = user.is_driver == 1;
            document.getElementById('is_caller').checked = user.is_caller == 1;
            document.getElementById('is_inspector').checked = user.is_inspector == 1;
            document.getElementById('passwordField').style.display = 'none';
            document.getElementById('password').required = false;
            
            new bootstrap.Modal(document.getElementById('userModal')).show();
        }

        function deleteUser(userId, userName) {
            if (confirm('ユーザー「' + userName + '」を削除しますか？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete">' +
                               '<input type="hidden" name="user_id" value="' + userId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
