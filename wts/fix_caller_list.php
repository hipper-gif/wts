<?php
/**
 * 点呼者リスト修正スクリプト
 * ファイル名: fix_caller_list.php
 * 
 * 問題: 乗務前後点呼で点呼者がリストに表示されない
 * 解決: 点呼者データの確認・追加・権限修正
 */

// セキュリティチェック
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'システム管理者') {
    die('アクセス権限がありません。');
}

// データベース接続
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4',
        'twinklemark_taxi',
        'Smiley2525',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

$message = '';
$results = [];

// 実行パラメータの確認
$action = $_GET['action'] ?? 'check';

try {
    switch ($action) {
        case 'check':
            // 現在の状況確認
            $results['点呼者確認'] = checkCallers($pdo);
            $results['ユーザー権限'] = checkUserRoles($pdo);
            $results['テーブル構造'] = checkTableStructure($pdo);
            break;
            
        case 'fix':
            // 問題修正実行
            $results['修正結果'] = fixCallerIssues($pdo);
            break;
            
        case 'add_sample':
            // サンプル点呼者追加
            $results['サンプル追加'] = addSampleCallers($pdo);
            break;
    }
} catch (Exception $e) {
    $message = 'エラー: ' . $e->getMessage();
}

/**
 * 点呼者確認
 */
function checkCallers($pdo) {
    $results = [];
    
    // 現在の点呼者一覧
    $stmt = $pdo->query("
        SELECT id, name, login_id, role 
        FROM users 
        WHERE role LIKE '%点呼者%' 
        ORDER BY name
    ");
    $callers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['点呼者数'] = count($callers);
    $results['点呼者リスト'] = $callers;
    
    // 全ユーザーの権限確認
    $stmt = $pdo->query("
        SELECT role, COUNT(*) as count 
        FROM users 
        GROUP BY role 
        ORDER BY count DESC
    ");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['権限別ユーザー数'] = $roles;
    
    return $results;
}

/**
 * ユーザー権限確認
 */
function checkUserRoles($pdo) {
    $results = [];
    
    // 複数権限対応確認
    $stmt = $pdo->query("
        SELECT id, name, login_id, role,
               CASE 
                   WHEN role LIKE '%点呼者%' THEN '点呼者権限あり'
                   ELSE '点呼者権限なし'
               END as caller_status
        FROM users 
        ORDER BY name
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['全ユーザー'] = $users;
    
    return $results;
}

/**
 * テーブル構造確認
 */
function checkTableStructure($pdo) {
    $results = [];
    
    // usersテーブル構造
    $stmt = $pdo->query("DESCRIBE users");
    $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['usersテーブル構造'] = $structure;
    
    // roleカラムの値一覧
    $stmt = $pdo->query("SELECT DISTINCT role FROM users ORDER BY role");
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $results['登録済み権限'] = $roles;
    
    return $results;
}

/**
 * 点呼者問題修正
 */
function fixCallerIssues($pdo) {
    $results = [];
    $fixed = 0;
    
    // 1. 管理者に点呼者権限を追加
    $stmt = $pdo->prepare("
        UPDATE users 
        SET role = CASE 
            WHEN role = '管理者' THEN '管理者,点呼者'
            WHEN role = 'システム管理者' THEN 'システム管理者,点呼者'
            ELSE role
        END
        WHERE role IN ('管理者', 'システム管理者')
        AND role NOT LIKE '%点呼者%'
    ");
    $stmt->execute();
    $fixed += $stmt->rowCount();
    
    $results['管理者への点呼者権限追加'] = $stmt->rowCount() . '件';
    
    // 2. 運転者に必要に応じて点呼者権限追加（運転者が点呼者も兼任する場合）
    $stmt = $pdo->prepare("
        UPDATE users 
        SET role = '運転者,点呼者'
        WHERE role = '運転者'
        AND id IN (
            SELECT DISTINCT driver_id 
            FROM pre_duty_calls 
            WHERE driver_id IS NOT NULL
            LIMIT 0,1
        )
    ");
    $stmt->execute();
    
    $results['運転者への点呼者権限追加'] = $stmt->rowCount() . '件';
    $results['合計修正'] = ($fixed + $stmt->rowCount()) . '件';
    
    return $results;
}

/**
 * サンプル点呼者追加
 */
function addSampleCallers($pdo) {
    $results = [];
    
    // 既存ユーザーに点呼者権限を追加
    $sampleCallers = [
        ['name' => '管理者', 'role' => '管理者,点呼者'],
        ['name' => '副管理者', 'role' => '点呼者'],
        ['name' => '点呼担当者', 'role' => '点呼者']
    ];
    
    $added = 0;
    foreach ($sampleCallers as $caller) {
        // 既存ユーザーがいるかチェック
        $stmt = $pdo->prepare("SELECT id FROM users WHERE name = ?");
        $stmt->execute([$caller['name']]);
        
        if ($stmt->fetch()) {
            // 既存ユーザーの権限更新
            $stmt = $pdo->prepare("
                UPDATE users 
                SET role = ? 
                WHERE name = ? 
                AND role NOT LIKE '%点呼者%'
            ");
            $stmt->execute([$caller['role'], $caller['name']]);
            if ($stmt->rowCount() > 0) {
                $added++;
                $results[] = $caller['name'] . ' に点呼者権限追加';
            }
        } else {
            // 新規ユーザー追加
            $stmt = $pdo->prepare("
                INSERT INTO users (name, login_id, password, role, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $login_id = strtolower(str_replace(' ', '', $caller['name']));
            $password = password_hash('password123', PASSWORD_DEFAULT);
            
            $stmt->execute([
                $caller['name'],
                $login_id,
                $password,
                $caller['role']
            ]);
            $added++;
            $results[] = $caller['name'] . ' を新規追加（ID: ' . $login_id . ', PW: password123）';
        }
    }
    
    $results['追加・更新数'] = $added . '件';
    
    return $results;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>点呼者リスト修正 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .result-section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background-color: #f8f9fa;
        }
        .action-buttons {
            margin-bottom: 30px;
        }
        .status-ok { color: #198754; }
        .status-warning { color: #fd7e14; }
        .status-error { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="fas fa-tools"></i> 点呼者リスト修正ツール
                </h1>
                
                <?php if ($message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>
                
                <!-- アクションボタン -->
                <div class="action-buttons">
                    <div class="btn-group" role="group">
                        <a href="?action=check" class="btn btn-primary">
                            <i class="fas fa-search"></i> 現状確認
                        </a>
                        <a href="?action=fix" class="btn btn-warning">
                            <i class="fas fa-wrench"></i> 自動修正
                        </a>
                        <a href="?action=add_sample" class="btn btn-success">
                            <i class="fas fa-plus"></i> サンプル点呼者追加
                        </a>
                    </div>
                    <a href="dashboard.php" class="btn btn-secondary ms-3">
                        <i class="fas fa-arrow-left"></i> ダッシュボードに戻る
                    </a>
                </div>
                
                <!-- 結果表示 -->
                <?php if (!empty($results)): ?>
                <div class="results">
                    <?php foreach ($results as $title => $data): ?>
                    <div class="result-section">
                        <h3 class="h5 mb-3">
                            <i class="fas fa-chart-bar"></i> <?= htmlspecialchars($title) ?>
                        </h3>
                        
                        <?php if (is_array($data)): ?>
                            <?php if (isset($data[0]) && is_array($data[0])): ?>
                                <!-- テーブル表示 -->
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead class="table-dark">
                                            <tr>
                                                <?php if (!empty($data)): ?>
                                                    <?php foreach (array_keys($data[0]) as $key): ?>
                                                    <th><?= htmlspecialchars($key) ?></th>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($data as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $value): ?>
                                                <td>
                                                    <?php if (strpos($value, '点呼者') !== false): ?>
                                                        <span class="status-ok">
                                                            <i class="fas fa-check"></i> <?= htmlspecialchars($value) ?>
                                                        </span>
                                                    <?php elseif (strpos($value, 'なし') !== false): ?>
                                                        <span class="status-warning">
                                                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($value) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($value) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <!-- リスト表示 -->
                                <ul class="list-group">
                                    <?php foreach ($data as $key => $value): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>
                                            <?php if (is_numeric($key)): ?>
                                                <?= htmlspecialchars($value) ?>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($key) ?>:</strong> <?= htmlspecialchars($value) ?>
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="mb-0"><?= htmlspecialchars($data) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- 使用方法 -->
                <div class="result-section">
                    <h3 class="h5 mb-3">
                        <i class="fas fa-info-circle"></i> 使用方法
                    </h3>
                    <ol>
                        <li><strong>現状確認</strong>: まずは「現状確認」で点呼者の設定状況を確認</li>
                        <li><strong>自動修正</strong>: 問題があれば「自動修正」で既存ユーザーに点呼者権限を付与</li>
                        <li><strong>サンプル追加</strong>: 点呼者が不足している場合は「サンプル点呼者追加」を実行</li>
                        <li><strong>動作確認</strong>: 修正後、乗務前点呼画面で点呼者リストが表示されるか確認</li>
                    </ol>
                </div>
                
                <!-- 注意事項 -->
                <div class="alert alert-info">
                    <h5><i class="fas fa-lightbulb"></i> 重要事項</h5>
                    <ul class="mb-0">
                        <li>点呼者は「role」カラムに「点呼者」が含まれるユーザーです</li>
                        <li>複数権限対応: 「管理者,点呼者」のようにカンマ区切りで複数権限を持てます</li>
                        <li>修正後は必ず乗務前点呼・乗務後点呼画面で動作確認してください</li>
                        <li>問題が解決しない場合は、個別にユーザー管理画面で権限を確認してください</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
