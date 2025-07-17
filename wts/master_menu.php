<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 管理者権限チェック
if (!in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getDBConnection();
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

// 統計情報取得
try {
    // ユーザー数
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = TRUE");
    $user_count = $stmt->fetchColumn();
    
    // 車両数
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = TRUE");
    $vehicle_count = $stmt->fetchColumn();
    
    // 輸送分類数
    $stmt = $pdo->query("SELECT COUNT(*) FROM transport_categories WHERE is_active = TRUE");
    $category_count = $stmt->fetchColumn();
    
    // 場所マスタ数
    $stmt = $pdo->query("SELECT COUNT(*) FROM location_master WHERE is_active = TRUE");
    $location_count = $stmt->fetchColumn();
    
    // 会社情報の有無
    $stmt = $pdo->query("SELECT COUNT(*) FROM company_info");
    $company_info_exists = $stmt->fetchColumn() > 0;
    
} catch (Exception $e) {
    error_log("Master menu error: " . $e->getMessage());
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
        
        .admin-only {
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
        <?php if ($user_role !== 'admin'): ?>
        <div class="permission-note">
            <i class="fas fa-info-circle me-2"></i>
            <strong>権限について：</strong>一部の機能は管理者権限またはシステム管理者権限が必要です。
        </div>
        <?php endif; ?>
        
        <!-- メインメニュー -->
        <div class="row">
            <!-- ユーザー管理 -->
            <div class="col-lg-4 col-md-6 mb-4">
                <?php if ($user_role === 'admin'): ?>
                    <a href="user_management.php" class="master-card">
                <?php else: ?>
                    <div class="master-card admin-only">
                <?php endif; ?>
                    <div class="position-relative">
                        <?php if ($user_role !== 'admin'): ?>
                            <span class="status-badge bg-warning text-dark">要管理者権限</span>
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
                <?php if ($user_role === 'admin'): ?>
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
                        <h5 class="card-title">車両・システム管理</h5>
                        <p class="card-description">
                            車両情報とシステム名の設定<br>
                            点検期限管理と稼働状況
                        </p>
                        <div class="stats-number text-success"><?= $vehicle_count ?></div>
                        <div class="stats-label">登録車両数</div>
                    </div>
                </a>
            </div>
            
            <!-- 会社情報設定 -->
            <div class="col-lg-4 col-md-6 mb-4">
                <a href="company_settings.php" class="master-card">
                    <div class="position-relative">
                        <?php if (!$company_info_exists): ?>
                            <span class="status-badge bg-danger text-white">要設定</span>
                        <?php endif; ?>
                        <i class="fas fa-building master-icon icon-building"></i>
                        <h5 class="card-title">会社情報設定</h5>
                        <p class="card-description">
                            会社基本情報と許可証情報<br>
                            帳票出力時の会社情報
                        </p>
                        <div class="stats-number text-info">
                            <?= $company_info_exists ? '設定済み' : '未設定' ?>
                        </div>
                        <div class="stats-label">設定状況</div>
                    </div>
                </a>
            </div>
            
            <!-- 輸送分類管理 -->
            <div class="col-lg-4 col-md-6 mb-4">
                <a href="transport_category_master.php" class="master-card">
                    <div class="position-relative">
                        <i class="fas fa-tags master-icon icon-categories"></i>
                        <h5 class="card-title">輸送分類管理</h5>
                        <p class="card-description">
                            通院・外出等の輸送分類<br>
                            分類の追加・編集・並び順設定
                        </p>
                        <div class="stats-number text-warning"><?= $category_count ?></div>
                        <div class="stats-label">登録分類数</div>
                    </div>
                </a>
            </div>
            
            <!-- 場所マスタ管理 -->
            <div class="col-lg-4 col-md-6 mb-4">
                <a href="location_master.php" class="master-card">
                    <div class="position-relative">
                        <i class="fas fa-map-marker-alt master-icon icon-locations"></i>
                        <h5 class="card-title">場所マスタ管理</h5>
                        <p class="card-description">
                            よく使う場所の登録管理<br>
                            病院・施設・駅などの情報
                        </p>
                        <div class="stats-number text-danger"><?= $location_count ?></div>
                        <div class="stats-label">登録場所数</div>
                    </div>
                </a>
            </div>
            
            </div>
        </div>
        
        <!-- 今後実装予定の機能 -->
        <div class="row mt-4">
            <div class="col-12">
                <h4 class="mb-3"><i class="fas fa-clock me-2"></i>今後実装予定</h4>
            </div>
            
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card" style="opacity: 0.5;">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発中</span>
                        <i class="fas fa-yen-sign master-icon icon-reports"></i>
                        <h5 class="card-title">運賃マスタ管理</h5>
                        <p class="card-description">
                            距離別・時間帯別運賃設定<br>
                            特別料金・割引設定
                        </p>
                        <div class="stats-number text-muted">Coming Soon</div>
                        <div class="stats-label">今後実装予定</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="master-card" style="opacity: 0.5;">
                    <div class="position-relative">
                        <span class="status-badge bg-info text-white">開発中</span>
                        <i class="fas fa-database master-icon icon-backup"></i>
                        <h5 class="card-title">バックアップ・復元</h5>
                        <p class="card-description">
                            データベースバックアップ<br>
                            自動バックアップ・復元機能
                        </p>
                        <div class="stats-number text-muted">Coming Soon</div>
                        <div class="stats-label">今後実装予定</div>
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
                                <h6>推奨設定順序</h6>
                                <ol class="small">
                                    <li>会社情報設定（帳票出力のため）</li>
                                    <li>車両・システム管理（基本設定のため）</li>
                                    <li>ユーザー管理（運用体制構築のため）</li>
                                    <li>輸送分類・場所マスタ（効率化のため）</li>
                                </ol>
                            </div>
                            <div class="col-md-6">
                                <h6>注意事項</h6>
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
</body>
</html>