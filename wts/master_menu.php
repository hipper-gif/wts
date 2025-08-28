<?php
session_start();

// データベース接続設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'twinklemark_wts');
define('DB_USER', 'twinklemark_taxi');
define('DB_PASS', 'Smiley2525');
define('DB_CHARSET', 'utf8mb4');

// データベース接続
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("データベース接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ✅ 修正：permission_levelベースの権限チェック
try {
    $stmt = $pdo->prepare("SELECT name, permission_level, is_driver, is_caller, is_manager FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch();
    
    if (!$user_data) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    
    $user_name = $user_data['name'];
    $user_permission_level = $user_data['permission_level'];
    $is_admin = ($user_permission_level === 'Admin');
    
    // 表示用の役職名を生成
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
    error_log("User data fetch error: " . $e->getMessage());
    session_destroy();
    header('Location: index.php');
    exit;
}

// 統計情報取得
try {
    // ユーザー数
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = TRUE");
    $user_count = $stmt->fetchColumn() ?: 0;
    
    // 車両数
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles");
    $vehicle_count = $stmt->fetchColumn() ?: 0;
    
    // 輸送分類数（テーブルが存在する場合のみ）
    $category_count = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM transport_categories WHERE is_active = TRUE");
        $category_count = $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {
        // テーブルが存在しない場合は0
    }
    
    // 場所マスタ数（テーブルが存在する場合のみ）
    $location_count = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM location_master WHERE is_active = TRUE");
        $location_count = $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {
        // テーブルが存在しない場合は0
    }
    
    // 会社情報の有無（テーブルが存在する場合のみ）
    $company_info_exists = false;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM company_info");
        $company_info_exists = $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        // テーブルが存在しない場合はfalse
    }
    
} catch (Exception $e) {
    error_log("Master menu statistics error: " . $e->getMessage());
    $user_count = $vehicle_count = $category_count = $location_count = 0;
    $company_info_exists = false;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>マスタ管理 - 福祉輸送管理システム</title>
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
            padding: 1.5rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .master-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        
        .master-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            color: inherit;
            text-decoration: none;
        }
        
        .master-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }
        
        .icon-users { color: #007bff; }
        .icon-cars { color: #28a745; }
        .icon-building { color: #17a2b8; }
        .icon-settings { color: #6c757d; }
        .icon-categories { color: #ffc107; }
        .icon-locations { color: #dc3545; }
        .icon-backup { color: #6f42c1; }
        .icon-reports { color: #fd7e14; }
        
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .user-only {
            opacity: 0.7;
        }
        
        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 12px;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .card-description {
            color: #6c757d;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 2rem;
        }
        
        .breadcrumb-item a {
            color: #6f42c1;
            text-decoration: none;
        }
        
        .permission-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }
        
        .coming-soon {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .coming-soon:hover {
            transform: none !important;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08) !important;
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-cogs me-2"></i>マスタ管理</h1>
                    <p class="mb-0">システムの基本設定とマスタデータの管理</p>
                    <small class="text-light">
                        <i class="fas fa-user me-1"></i><?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_role_display) ?>)
                    </small>
                </div>
                <div class="col-auto">
                    <a href="dashboard.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left me-1"></i>ダッシュボード
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container mt-4">
        <!-- パンくずリスト -->
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">ダッシュボード</a></li>
                <li class="breadcrumb-item active">マスタ管理</li>
            </ol>
        </nav>
        
        <!-- 権限に関する注意 -->
        <?php if (!$is_admin): ?>
        <div class="permission-note">
            <i class="fas fa-info-circle me-2"></i>
            <strong>権限について：</strong>一部の機能はAdmin権限が必要です。現在の権限：<?= htmlspecialchars($user_role_display) ?>
        </div>
        <?php endif; ?>
        
        <!-- メインメニュー -->
        <div class="row">
            <!-- ユーザー管理 -->
            <div class="col-lg-4 col-md-6 mb-4">
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
                        <h5 class="card-title">ユーザー管理</h5>
                        <p class="card-description">
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
            <div class="col-lg-4 col-md-6 mb-4">
                <a href="vehicle_management.php" class="master-card">
                    <div class="position-relative">
                        <i class="fas fa-car master-icon icon-cars"></i>
                        <h5 class="card-title">車両管理</h5>
                        <p class="card-description">
                            車両情報の登録・編集・削除<br>
                            点検期限管理と稼働状況
                        </p>
                        <div class="stats-number text-success"><?= $vehicle_count ?></div>
                        <div class="stats-label">登録車両数</div>
                    </div>
                </a>
            </div>
            
            <!-- システム設定（車両管理の一部として統合） -->
            <div class="col-lg-4 col-md-6 mb-4">
                <a href="vehicle_management.php#system-settings" class="master-card">
                    <div class="position-relative">
                        <i class="fas fa-cog master-icon icon-settings"></i>
                        <h5 class="card-title">システム設定</h5>
                        <p class="card-description">
                            システム名の設定<br>
                            基本設定の管理
                        </p>
                        <div class="stats-number text-secondary">設定中</div>
                        <div class="stats-label">設定状況</div>
                    </div>
                </a>
            </div>
        </div>
        
        <!-- 拡張機能（実装済み・予定） -->
        <div class="row">
            <div class="col-12">
                <h4 class="mb-3"><i class="fas fa-plus me-2"></i>拡張機能</h4>
            </div>
            
            <!-- 会社情報設定 -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card coming-soon" title="今後実装予定">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発予定</span>
                        <i class="fas fa-building master-icon icon-building"></i>
                        <h5 class="card-title">会社情報設定</h5>
                        <p class="card-description">
                            会社基本情報と許可証情報<br>
                            帳票出力時の会社情報
                        </p>
                        <div class="stats-number text-muted">Coming Soon</div>
                        <div class="stats-label">開発予定</div>
                    </div>
                </div>
            </div>
            
            <!-- 輸送分類管理 -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card coming-soon" title="今後実装予定">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発予定</span>
                        <i class="fas fa-tags master-icon icon-categories"></i>
                        <h5 class="card-title">輸送分類管理</h5>
                        <p class="card-description">
                            通院・外出等の輸送分類<br>
                            分類の追加・編集・並び順設定
                        </p>
                        <div class="stats-number text-muted">Coming Soon</div>
                        <div class="stats-label">開発予定</div>
                    </div>
                </div>
            </div>
            
            <!-- 場所マスタ管理 -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card coming-soon" title="今後実装予定">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発予定</span>
                        <i class="fas fa-map-marker-alt master-icon icon-locations"></i>
                        <h5 class="card-title">場所マスタ管理</h5>
                        <p class="card-description">
                            よく使う場所の登録管理<br>
                            病院・施設・駅などの情報
                        </p>
                        <div class="stats-number text-muted">Coming Soon</div>
                        <div class="stats-label">開発予定</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 今後実装予定の機能 -->
        <div class="row mt-4">
            <div class="col-12">
                <h4 class="mb-3"><i class="fas fa-clock me-2"></i>将来実装予定</h4>
            </div>
            
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-secondary text-white">Phase2</span>
                        <i class="fas fa-yen-sign master-icon icon-reports"></i>
                        <h5 class="card-title">運賃マスタ管理</h5>
                        <p class="card-description">
                            距離別・時間帯別運賃設定<br>
                            特別料金・割引設定
                        </p>
                        <div class="stats-number text-muted">Phase2</div>
                        <div class="stats-label">将来実装</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-secondary text-white">Phase2</span>
                        <i class="fas fa-database master-icon icon-backup"></i>
                        <h5 class="card-title">バックアップ・復元</h5>
                        <p class="card-description">
                            データベースバックアップ<br>
                            自動バックアップ・復元機能
                        </p>
                        <div class="stats-number text-muted">Phase2</div>
                        <div class="stats-label">将来実装</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card coming-soon">
                    <div class="position-relative">
                        <span class="status-badge bg-secondary text-white">Phase2</span>
                        <i class="fas fa-calendar-alt master-icon text-info"></i>
                        <h5 class="card-title">予約管理</h5>
                        <p class="card-description">
                            顧客予約システム<br>
                            スケジュール管理機能
                        </p>
                        <div class="stats-number text-muted">Phase2</div>
                        <div class="stats-label">将来実装</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ヘルプ・サポート -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-primary">
                    <div class="card-body">
                        <h5 class="card-title text-primary">
                            <i class="fas fa-question-circle me-2"></i>マスタ管理について
                        </h5>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>現在利用可能な機能</h6>
                                <ul class="small">
                                    <li><strong>ユーザー管理</strong>：Admin権限で利用可能</li>
                                    <li><strong>車両管理</strong>：全ユーザーで利用可能</li>
                                    <li><strong>システム設定</strong>：車両管理画面で設定</li>
                                </ul>
                                
                                <h6 class="mt-3">推奨設定順序</h6>
                                <ol class="small">
                                    <li>システム設定（システム名等）</li>
                                    <li>車両管理（基本設定のため）</li>
                                    <li>ユーザー管理（運用体制構築のため）</li>
                                </ol>
                            </div>
                            <div class="col-md-6">
                                <h6>権限について</h6>
                                <ul class="small">
                                    <li><strong>Admin権限</strong>：全機能利用可能</li>
                                    <li><strong>User権限</strong>：車両管理・システム設定のみ</li>
                                </ul>
                                
                                <h6 class="mt-3">注意事項</h6>
                                <ul class="small">
                                    <li>マスタデータ変更は運用中のデータに影響します</li>
                                    <li>削除ではなく無効化を推奨します</li>
                                    <li>重要な変更前にはバックアップを取得してください</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showPermissionAlert() {
            alert('この機能を利用するにはAdmin権限が必要です。\n\n現在の権限：<?= htmlspecialchars($user_role_display) ?>');
        }
        
        // Coming Soon機能のクリック無効化
        document.querySelectorAll('.coming-soon').forEach(function(element) {
            element.addEventListener('click', function(e) {
                e.preventDefault();
                alert('この機能は今後実装予定です。\n\n現在利用可能な機能：\n・ユーザー管理（Admin権限）\n・車両管理（全ユーザー）\n・システム設定（車両管理内）');
            });
        });
    </script>
</body>
</html>
