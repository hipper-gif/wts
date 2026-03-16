<?php
require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/session_check.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'ユーザー';
$user_role = $_SESSION['permission_level'] ?? $_SESSION['user_role'] ?? 'User';

// 統一ヘッダーシステム
require_once 'includes/unified-header.php';
$page_config = getPageConfiguration('master_menu');

// 権限チェック
try {
    $stmt = $pdo->prepare("SELECT name, permission_level, is_driver, is_caller, is_manager FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();

    if (!$user_data) {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    $user_name = $user_data['name'];
    $user_permission_level = $user_data['permission_level'];
    $is_admin = ($user_permission_level === 'Admin');

    $user_role_display = '';
    if ($is_admin) {
        $user_role_display = 'システム管理者';
    } else {
        $roles = [];
        if ($user_data['is_driver']) $roles[] = '運転者';
        if ($user_data['is_caller']) $roles[] = '点呼者';
        if ($user_data['is_manager']) $roles[] = '管理者';
        $user_role_display = !empty($roles) ? implode('・', $roles) : '一般ユーザー';
    }
} catch (PDOException $e) {
    error_log("ユーザーデータ取得エラー: " . $e->getMessage());
    session_destroy();
    header('Location: index.php');
    exit;
}

// セッションタイムアウト設定の保存処理（Admin限定）
$settings_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    validateCsrfToken();

    if (isset($_POST['session_timeout'])) {
        $timeout = (int)$_POST['session_timeout'];
        $valid_values = [1800, 3600, 7200, 14400, 28800];
        if (in_array($timeout, $valid_values)) {
            try {
                $pdo->beginTransaction();

                // system_settingsテーブルがなければ作成
                $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) UNIQUE NOT NULL,
                    setting_value TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('session_timeout', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$timeout, $timeout]);

                $_SESSION['session_timeout_seconds'] = $timeout;
                $_SESSION['session_timeout_cached_at'] = time();

                $pdo->commit();
                $settings_message = 'success';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("セッションタイムアウト保存エラー: " . $e->getMessage());
                $settings_message = 'error';
            }
        }
    }
}

// 現在のセッションタイムアウト設定を取得
$current_timeout = 28800;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false) $current_timeout = (int)$val;
} catch (PDOException $e) {
    // テーブルがない場合はデフォルト
}

// 統計情報取得（安全なカウント関数）
function safeTableCount($pdo, $query) {
    try {
        $stmt = $pdo->query($query);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        return 0;
    }
}

$user_count = safeTableCount($pdo, "SELECT COUNT(*) FROM users WHERE is_active = TRUE");
$vehicle_count = safeTableCount($pdo, "SELECT COUNT(*) FROM vehicles WHERE is_active = 1");
$category_count = safeTableCount($pdo, "SELECT COUNT(*) FROM transport_categories WHERE is_active = TRUE");
$location_count = safeTableCount($pdo, "SELECT COUNT(*) FROM location_master WHERE is_active = TRUE");
$company_info_exists = safeTableCount($pdo, "SELECT COUNT(*) FROM company_info") > 0;

// ページ生成（統一ヘッダーシステム）
$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'master_menu',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    [
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => $page_config['title'], 'url' => 'master_menu.php']
        ]
    ]
);
?>
<?= $page_data['html_head'] ?>
<style>
    .master-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border: 1px solid rgba(0,0,0,0.06);
        transition: all 0.3s ease;
        text-decoration: none;
        color: inherit;
        display: block;
        height: 100%;
    }
    .master-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        color: inherit;
        text-decoration: none;
    }
    .master-icon {
        font-size: 2.5rem;
        margin-bottom: 0.75rem;
        display: block;
    }
    .icon-users { color: #667eea; }
    .icon-cars { color: #28a745; }
    .icon-building { color: #17a2b8; }
    .icon-settings { color: #6c757d; }
    .icon-categories { color: #f59e0b; }
    .icon-locations { color: #ef4444; }
    .icon-backup { color: #6f42c1; }
    .icon-reports { color: #fd7e14; }
    .stats-number {
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }
    .stats-label {
        color: #6c757d;
        font-size: 0.85rem;
    }
    .card-description {
        color: #6c757d;
        font-size: 0.85rem;
        line-height: 1.5;
        margin-bottom: 0.75rem;
    }
    .status-badge {
        position: absolute;
        top: 0;
        right: 0;
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 10px;
    }
    .user-only {
        opacity: 0.6;
        cursor: pointer;
    }
    .coming-soon {
        opacity: 0.45;
        cursor: not-allowed;
    }
    .coming-soon:hover {
        transform: none !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    }
    .section-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #667eea;
    }
</style>
</head>
<body>
    <?= $page_data['system_header'] ?>
    <?= $page_data['page_header'] ?>

    <div class="container mt-4">

        <!-- 設定保存結果 -->
        <?php if ($settings_message === 'success'): ?>
            <?= renderAlert('success', '保存完了', 'セッションタイムアウト設定を保存しました。') ?>
        <?php elseif ($settings_message === 'error'): ?>
            <?= renderAlert('danger', 'エラー', '設定の保存に失敗しました。') ?>
        <?php endif; ?>

        <!-- 権限に関する注意 -->
        <?php if (!$is_admin): ?>
            <?= renderAlert('warning', '権限について', '一部の機能はAdmin権限が必要です。現在の権限：' . htmlspecialchars($user_role_display)) ?>
        <?php endif; ?>

        <!-- 基本マスタ管理 -->
        <h5 class="section-title"><i class="fas fa-cogs me-2"></i>基本マスタ管理</h5>

        <div class="row mb-4">
            <!-- ユーザー管理 -->
            <div class="col-lg-4 col-md-6 mb-3">
                <?php if ($is_admin): ?>
                <a href="user_management.php" class="master-card">
                <?php else: ?>
                <div class="master-card user-only" onclick="showPermissionAlert()">
                <?php endif; ?>
                    <div class="position-relative">
                        <?php if (!$is_admin): ?>
                            <span class="status-badge bg-warning text-dark">要Admin権限</span>
                        <?php endif; ?>
                        <i class="fas fa-users master-icon icon-users"></i>
                        <h6 class="fw-bold">ユーザー管理</h6>
                        <p class="card-description mb-2">
                            システムユーザーの追加・編集・削除<br>
                            権限設定とアカウント管理
                        </p>
                        <div class="stats-number text-primary"><?= $user_count ?></div>
                        <div class="stats-label">登録ユーザー数</div>
                    </div>
                <?php if ($is_admin): ?>
                </a>
                <?php else: ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 車両管理 -->
            <div class="col-lg-4 col-md-6 mb-3">
                <a href="vehicle_management.php" class="master-card">
                    <div class="position-relative">
                        <i class="fas fa-car master-icon icon-cars"></i>
                        <h6 class="fw-bold">車両管理</h6>
                        <p class="card-description mb-2">
                            車両情報の登録・編集・削除<br>
                            点検期限管理と稼働状況
                        </p>
                        <div class="stats-number text-success"><?= $vehicle_count ?></div>
                        <div class="stats-label">登録車両数</div>
                    </div>
                </a>
            </div>

            <!-- システム設定 -->
            <div class="col-lg-4 col-md-6 mb-3">
                <?php if ($is_admin): ?>
                <div class="master-card">
                    <div class="position-relative">
                        <i class="fas fa-cog master-icon icon-settings"></i>
                        <h6 class="fw-bold">システム設定</h6>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <label class="form-label unified-label mb-1">
                                <i class="fas fa-clock me-1"></i>セッションタイムアウト
                            </label>
                            <select name="session_timeout" class="form-select unified-select form-select-sm mb-2">
                                <option value="1800" <?= $current_timeout == 1800 ? 'selected' : '' ?>>30分</option>
                                <option value="3600" <?= $current_timeout == 3600 ? 'selected' : '' ?>>1時間</option>
                                <option value="7200" <?= $current_timeout == 7200 ? 'selected' : '' ?>>2時間</option>
                                <option value="14400" <?= $current_timeout == 14400 ? 'selected' : '' ?>>4時間</option>
                                <option value="28800" <?= $current_timeout == 28800 ? 'selected' : '' ?>>8時間（業務中）</option>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-save me-1"></i>保存
                            </button>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="master-card user-only" onclick="showPermissionAlert()">
                    <div class="position-relative">
                        <span class="status-badge bg-secondary">Admin限定</span>
                        <i class="fas fa-cog master-icon icon-settings"></i>
                        <h6 class="fw-bold">システム設定</h6>
                        <p class="card-description">
                            セッションタイムアウト<br>
                            基本設定の管理
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 拡張機能（開発予定） -->
        <h5 class="section-title"><i class="fas fa-puzzle-piece me-2"></i>拡張機能<span class="badge bg-info ms-2" style="font-size:0.7rem;">開発予定</span></h5>

        <div class="row mb-4">
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発予定</span>
                        <i class="fas fa-building master-icon icon-building"></i>
                        <h6 class="fw-bold">会社情報設定</h6>
                        <p class="card-description">
                            会社基本情報と許可証情報<br>
                            帳票出力時の会社情報
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-3">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発予定</span>
                        <i class="fas fa-tags master-icon icon-categories"></i>
                        <h6 class="fw-bold">輸送分類管理</h6>
                        <p class="card-description">
                            通院・外出等の輸送分類<br>
                            分類の追加・編集・並び順設定
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-3">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発予定</span>
                        <i class="fas fa-map-marker-alt master-icon icon-locations"></i>
                        <h6 class="fw-bold">場所マスタ管理</h6>
                        <p class="card-description">
                            よく使う場所の登録管理<br>
                            病院・施設・駅などの情報
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 管理ツール -->
        <h5 class="section-title"><i class="fas fa-toolbox me-2"></i>管理ツール</h5>

        <div class="row mb-4">
            <!-- DBバックアップ -->
            <div class="col-lg-4 col-md-6 mb-3">
                <?php if ($is_admin): ?>
                <div class="master-card" style="cursor:pointer;" onclick="performBackup()">
                    <div class="position-relative">
                        <span class="status-badge bg-success text-white">利用可能</span>
                        <i class="fas fa-database master-icon icon-backup"></i>
                        <h6 class="fw-bold">データベースバックアップ</h6>
                        <p class="card-description">
                            データベースをJSON形式でエクスポート<br>
                            管理者専用のバックアップ機能
                        </p>
                        <div class="stats-number" style="color:#6f42c1;">
                            <i class="fas fa-download"></i>
                        </div>
                        <div class="stats-label">クリックしてバックアップ</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="master-card user-only" onclick="showPermissionAlert()">
                    <div class="position-relative">
                        <span class="status-badge bg-warning text-dark">要Admin権限</span>
                        <i class="fas fa-database master-icon icon-backup"></i>
                        <h6 class="fw-bold">データベースバックアップ</h6>
                        <p class="card-description">
                            データベースをJSON形式でエクスポート<br>
                            管理者専用のバックアップ機能
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- 運賃マスタ（Phase2） -->
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-secondary text-white">Phase2</span>
                        <i class="fas fa-yen-sign master-icon icon-reports"></i>
                        <h6 class="fw-bold">運賃マスタ管理</h6>
                        <p class="card-description">
                            距離別・時間帯別運賃設定<br>
                            特別料金・割引設定
                        </p>
                    </div>
                </div>
            </div>

            <!-- 予約管理（Phase2） -->
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-secondary text-white">Phase2</span>
                        <i class="fas fa-calendar-alt master-icon text-info"></i>
                        <h6 class="fw-bold">予約管理</h6>
                        <p class="card-description">
                            顧客予約システム<br>
                            スケジュール管理機能
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ヘルプ -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-question-circle text-info me-2"></i>マスタ管理について</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="small fw-bold"><i class="fas fa-check-circle text-success me-2"></i>現在利用可能な機能</h6>
                        <ul class="small mb-3">
                            <li><strong>ユーザー管理</strong>：Admin権限で利用可能</li>
                            <li><strong>車両管理</strong>：全ユーザーで利用可能</li>
                            <li><strong>システム設定</strong>：Admin権限で利用可能</li>
                            <li><strong>DBバックアップ</strong>：Admin権限で利用可能</li>
                        </ul>
                        <h6 class="small fw-bold"><i class="fas fa-list-ol text-primary me-2"></i>推奨設定順序</h6>
                        <ol class="small mb-0">
                            <li>システム設定（セッションタイムアウト等）</li>
                            <li>車両管理（基本設定のため）</li>
                            <li>ユーザー管理（運用体制構築のため）</li>
                        </ol>
                    </div>
                    <div class="col-md-6">
                        <h6 class="small fw-bold"><i class="fas fa-user-shield text-info me-2"></i>権限について</h6>
                        <ul class="small mb-3">
                            <li><strong>Admin権限</strong>：全機能利用可能</li>
                            <li><strong>User権限</strong>：車両管理のみ</li>
                        </ul>
                        <h6 class="small fw-bold"><i class="fas fa-exclamation-triangle text-warning me-2"></i>注意事項</h6>
                        <ul class="small mb-0">
                            <li>マスタデータ変更は運用中のデータに影響します</li>
                            <li>削除ではなく無効化を推奨します</li>
                            <li>重要な変更前にはバックアップを取得してください</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
    function showPermissionAlert() {
        alert('この機能を利用するにはAdmin権限が必要です。\n\n現在の権限：<?= htmlspecialchars($user_role_display) ?>');
    }

    document.querySelectorAll('.coming-soon').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            alert('この機能は今後実装予定です。');
        });
    });

    function performBackup() {
        if (!confirm('データベースのバックアップを作成しますか？\n\nJSON形式のファイルがダウンロードされます。')) {
            return;
        }
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'api/backup.php';
        form.style.display = 'none';
        form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">';
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    </script>

<?php echo $page_data['html_footer']; ?>
