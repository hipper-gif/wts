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

// 統一ヘッダー関数を読み込み
require_once 'includes/unified-header.php';

// ユーザー情報取得
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
    
} catch (PDOException $e) {
    error_log("User data fetch error: " . $e->getMessage());
    session_destroy();
    header('Location: index.php');
    exit;
}

// パラメータ取得
$action = $_GET['action'] ?? '';
$edit_id = $_GET['edit_id'] ?? '';

// デフォルト値設定
$selected_driver = $_POST['driver_id'] ?? $_GET['driver_id'] ?? $_SESSION['user_id'];
$start_date = $_POST['start_date'] ?? $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_POST['end_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

$message = '';
$message_type = '';

// 運転者リスト取得
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll();
} catch (Exception $e) {
    $drivers = [];
    error_log("Driver list error: " . $e->getMessage());
}

// 売上金確認データの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'save') {
            // 新規登録
            $confirmation_date = $_POST['confirmation_date'];
            $driver_id = $_POST['driver_id'];
            $confirmed_amount = (int)$_POST['confirmed_amount'];
            $calculated_amount = (int)$_POST['calculated_amount'];
            $difference = $confirmed_amount - $calculated_amount;
            $change_stock = (int)$_POST['change_stock'];
            $memo = $_POST['memo'] ?? '';
            
            // 現金内訳
            $cash_breakdown = [
                'bill_10000' => (int)$_POST['bill_10000'],
                'bill_5000' => (int)$_POST['bill_5000'],
                'bill_2000' => (int)$_POST['bill_2000'],
                'bill_1000' => (int)$_POST['bill_1000'],
                'coin_500' => (int)$_POST['coin_500'],
                'coin_100' => (int)$_POST['coin_100'],
                'coin_50' => (int)$_POST['coin_50'],
                'coin_10' => (int)$_POST['coin_10'],
                'coin_5' => (int)$_POST['coin_5'],
                'coin_1' => (int)$_POST['coin_1']
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO cash_management (
                    confirmation_date, driver_id, confirmed_amount, calculated_amount, 
                    difference, change_stock, memo, cash_breakdown, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $confirmation_date, $driver_id, $confirmed_amount, $calculated_amount,
                $difference, $change_stock, $memo, json_encode($cash_breakdown)
            ]);
            
            $message = '売上金確認データを登録しました。';
            $message_type = 'success';
            
        } elseif ($action === 'update' && !empty($edit_id)) {
            // 修正処理
            $confirmation_date = $_POST['confirmation_date'];
            $driver_id = $_POST['driver_id'];
            $confirmed_amount = (int)$_POST['confirmed_amount'];
            $calculated_amount = (int)$_POST['calculated_amount'];
            $difference = $confirmed_amount - $calculated_amount;
            $change_stock = (int)$_POST['change_stock'];
            $memo = $_POST['memo'] ?? '';
            
            // 現金内訳
            $cash_breakdown = [
                'bill_10000' => (int)$_POST['bill_10000'],
                'bill_5000' => (int)$_POST['bill_5000'],
                'bill_2000' => (int)$_POST['bill_2000'],
                'bill_1000' => (int)$_POST['bill_1000'],
                'coin_500' => (int)$_POST['coin_500'],
                'coin_100' => (int)$_POST['coin_100'],
                'coin_50' => (int)$_POST['coin_50'],
                'coin_10' => (int)$_POST['coin_10'],
                'coin_5' => (int)$_POST['coin_5'],
                'coin_1' => (int)$_POST['coin_1']
            ];
            
            $stmt = $pdo->prepare("
                UPDATE cash_management SET 
                    confirmation_date = ?, driver_id = ?, confirmed_amount = ?, 
                    calculated_amount = ?, difference = ?, change_stock = ?, 
                    memo = ?, cash_breakdown = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $confirmation_date, $driver_id, $confirmed_amount, $calculated_amount,
                $difference, $change_stock, $memo, json_encode($cash_breakdown), $edit_id
            ]);
            
            $message = '売上金確認データを修正しました。';
            $message_type = 'success';
            $action = '';
            $edit_id = '';
        }
    } catch (Exception $e) {
        $message = 'エラーが発生しました: ' . $e->getMessage();
        $message_type = 'danger';
        error_log("Cash management save error: " . $e->getMessage());
    }
}

// 編集データ取得
$edit_data = null;
if ($action === 'edit' && !empty($edit_id)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM cash_management WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_data = $stmt->fetch();
        
        if ($edit_data && !empty($edit_data['cash_breakdown'])) {
            $edit_data['cash_breakdown'] = json_decode($edit_data['cash_breakdown'], true);
        }
    } catch (Exception $e) {
        error_log("Edit data fetch error: " . $e->getMessage());
    }
}

// 売上金確認履歴取得
$cash_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT cm.*, u.name as driver_name 
        FROM cash_management cm
        JOIN users u ON cm.driver_id = u.id
        WHERE cm.driver_id = ? AND cm.confirmation_date BETWEEN ? AND ?
        ORDER BY cm.confirmation_date DESC, cm.created_at DESC
    ");
    $stmt->execute([$selected_driver, $start_date, $end_date]);
    $cash_history = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Cash history error: " . $e->getMessage());
}

// 指定期間の売上計算
$calculated_revenue = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_fare), 0) as total_revenue
        FROM ride_records 
        WHERE driver_id = ? AND ride_date BETWEEN ? AND ?
    ");
    $stmt->execute([$selected_driver, $start_date, $end_date]);
    $result = $stmt->fetch();
    $calculated_revenue = $result['total_revenue'] ?? 0;
} catch (Exception $e) {
    error_log("Revenue calculation error: " . $e->getMessage());
}

// 最新の釣銭在庫取得
$latest_change_stock = 10000; // デフォルト値
try {
    $stmt = $pdo->prepare("
        SELECT change_stock 
        FROM cash_management 
        WHERE driver_id = ? 
        ORDER BY confirmation_date DESC, created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_driver]);
    $result = $stmt->fetch();
    if ($result) {
        $latest_change_stock = $result['change_stock'];
    }
} catch (Exception $e) {
    error_log("Change stock error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>売上金確認 - 福祉輸送管理システム</title>
    
    <!-- 統一UI CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/ui-unified-v3.css">
    
    <style>
        .cash-denomination {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .cash-denomination:focus-within {
            border-color: #2196F3;
            box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.1);
        }
        
        .denomination-input {
            border: none;
            background: transparent;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
        }
        
        .denomination-input:focus {
            outline: none;
            box-shadow: none;
        }
        
        .bill {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .coin {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: white;
        }
        
        .cash-summary {
            background: linear-gradient(135deg, #2196F3 0%, #21CBF3 100%);
            color: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);
        }
    </style>
</head>
<body>
    <!-- 統一システムヘッダー -->
    <?= renderSystemHeader($user_name, $user_permission_level) ?>
    
    <!-- 統一ページヘッダー -->
    <?= renderPageHeader('yen-sign', '売上金確認', '日次売上・現金管理') ?>
    
    <!-- メインコンテンツ -->
    <div class="main-content">
        <div class="content-container">
            
            <!-- メッセージ表示 -->
            <?php if ($message): ?>
                <?= renderAlert($message, $message_type) ?>
            <?php endif; ?>
            
            <!-- 検索・フィルター -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-search icon-primary"></i>
                    検索・フィルター
                </h3>
            </div>
            
            <form method="GET" class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">運転者</label>
                            <select name="driver_id" class="form-select">
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver['id'] ?>" <?= $driver['id'] == $selected_driver ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($driver['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">開始日</label>
                            <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">終了日</label>
                            <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block w-100">
                                <i class="fas fa-search"></i> 検索
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- 売上金確認フォーム -->
            <?php if ($action === 'new' || $action === 'edit'): ?>
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-plus-circle icon-success"></i>
                    <?= $action === 'edit' ? '売上金確認修正' : '新規売上金確認' ?>
                </h3>
            </div>
            
            <form method="POST" action="?action=<?= $action === 'edit' ? 'update' : 'save' ?><?= $action === 'edit' ? '&edit_id=' . $edit_id : '' ?>" class="card mb-4">
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">確認日</label>
                            <input type="date" name="confirmation_date" class="form-control" 
                                   value="<?= $edit_data['confirmation_date'] ?? date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">運転者</label>
                            <select name="driver_id" class="form-select" required>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver['id'] ?>" <?= $driver['id'] == ($edit_data['driver_id'] ?? $selected_driver) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($driver['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">システム計算額</label>
                            <input type="number" name="calculated_amount" class="form-control" 
                                   value="<?= $edit_data['calculated_amount'] ?? $calculated_revenue ?>" required>
                        </div>
                    </div>
                    
                    <!-- 現金内訳入力 -->
                    <h5 class="mb-3">
                        <i class="fas fa-coins"></i> 現金内訳
                    </h5>
                    
                    <div class="row g-3 mb-4">
                        <!-- 紙幣 -->
                        <div class="col-md-6">
                            <h6 class="text-success mb-3"><i class="fas fa-money-bill-wave"></i> 紙幣</h6>
                            
                            <div class="cash-denomination bill mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>10,000円札</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="bill_10000" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['bill_10000'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="bill_10000_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination bill mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>5,000円札</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="bill_5000" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['bill_5000'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="bill_5000_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination bill mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>2,000円札</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="bill_2000" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['bill_2000'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="bill_2000_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination bill mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>1,000円札</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="bill_1000" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['bill_1000'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="bill_1000_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 硬貨 -->
                        <div class="col-md-6">
                            <h6 class="text-warning mb-3"><i class="fas fa-coins"></i> 硬貨</h6>
                            
                            <div class="cash-denomination coin mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>500円玉</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="coin_500" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['coin_500'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="coin_500_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination coin mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>100円玉</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="coin_100" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['coin_100'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="coin_100_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination coin mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>50円玉</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="coin_50" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['coin_50'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="coin_50_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination coin mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>10円玉</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="coin_10" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['coin_10'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="coin_10_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination coin mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>5円玉</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="coin_5" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['coin_5'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="coin_5_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="cash-denomination coin mb-3 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>1円玉</span>
                                    <div class="d-flex align-items-center">
                                        <input type="number" name="coin_1" class="denomination-input" 
                                               value="<?= $edit_data['cash_breakdown']['coin_1'] ?? 0 ?>" min="0" style="width: 60px;">
                                        <span class="ms-2">枚</span>
                                        <span class="ms-3 fw-bold" id="coin_1_total">¥0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 集計・確認 -->
                    <div class="cash-summary p-4 mb-4">
                        <div class="row g-3 text-center">
                            <div class="col-md-3">
                                <h6>実際の現金額</h6>
                                <div class="h4 mb-0" id="confirmed_total">¥0</div>
                                <input type="hidden" name="confirmed_amount" id="confirmed_amount_input" value="0">
                            </div>
                            <div class="col-md-3">
                                <h6>システム計算額</h6>
                                <div class="h4 mb-0">¥<?= number_format($edit_data['calculated_amount'] ?? $calculated_revenue) ?></div>
                            </div>
                            <div class="col-md-3">
                                <h6>差額</h6>
                                <div class="h4 mb-0" id="difference_display">¥0</div>
                            </div>
                            <div class="col-md-3">
                                <h6>釣銭在庫</h6>
                                <input type="number" name="change_stock" class="form-control text-center fw-bold" 
                                       value="<?= $edit_data['change_stock'] ?? $latest_change_stock ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">メモ・備考</label>
                            <textarea name="memo" class="form-control" rows="3" placeholder="差額の理由や特記事項があれば記入"><?= htmlspecialchars($edit_data['memo'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="cash_management.php?driver_id=<?= $selected_driver ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> 戻る
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> <?= $action === 'edit' ? '修正保存' : '確認完了' ?>
                        </button>
                    </div>
                </div>
            </form>
            
            <?php else: ?>
            
            <!-- アクションボタン -->
            <div class="d-flex justify-content-between mb-4">
                <div>
                    <h5 class="mb-0">
                        選択期間: <?= date('Y年n月j日', strtotime($start_date)) ?> 〜 <?= date('Y年n月j日', strtotime($end_date)) ?>
                    </h5>
                    <p class="text-muted mb-0">
                        運転者: <?php 
                        $selected_driver_name = '';
                        foreach ($drivers as $driver) {
                            if ($driver['id'] == $selected_driver) {
                                $selected_driver_name = $driver['name'];
                                break;
                            }
                        }
                        echo htmlspecialchars($selected_driver_name);
                        ?>
                    </p>
                </div>
                <a href="cash_management.php?action=new&driver_id=<?= $selected_driver ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> 新規確認
                </a>
            </div>
            
            <!-- 期間サマリー -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-calculator fa-2x text-primary mb-3"></i>
                            <h5>システム計算額</h5>
                            <div class="h4 text-primary">¥<?= number_format($calculated_revenue) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <?php
                            $total_confirmed = 0;
                            foreach ($cash_history as $record) {
                                $total_confirmed += $record['confirmed_amount'];
                            }
                            ?>
                            <i class="fas fa-coins fa-2x text-success mb-3"></i>
                            <h5>確認済み現金</h5>
                            <div class="h4 text-success">¥<?= number_format($total_confirmed) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <?php
                            $total_difference = $total_confirmed - $calculated_revenue;
                            $diff_class = $total_difference >= 0 ? 'text-success' : 'text-danger';
                            ?>
                            <i class="fas fa-balance-scale fa-2x <?= $diff_class ?> mb-3"></i>
                            <h5>合計差額</h5>
                            <div class="h4 <?= $diff_class ?>">
                                <?= $total_difference >= 0 ? '+' : '' ?>¥<?= number_format($total_difference) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 売上金確認履歴 -->
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-history icon-info"></i>
                    売上金確認履歴
                </h3>
            </div>
            
            <?php if (empty($cash_history)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">確認記録がありません</h5>
                        <p class="text-muted mb-4">指定した期間・運転者の売上金確認記録が見つかりませんでした。</p>
                        <a href="cash_management.php?action=new&driver_id=<?= $selected_driver ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-primary">
                            <i class="fas fa-plus"></i> 新規確認を追加
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead class="table-dark">
                            <tr>
                                <th>確認日</th>
                                <th>運転者</th>
                                <th>実際の現金</th>
                                <th>システム計算</th>
                                <th>差額</th>
                                <th>釣銭在庫</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cash_history as $record): ?>
                                <tr>
                                    <td><?= date('Y/m/d', strtotime($record['confirmation_date'])) ?></td>
                                    <td><?= htmlspecialchars($record['driver_name']) ?></td>
                                    <td class="text-end">¥<?= number_format($record['confirmed_amount']) ?></td>
                                    <td class="text-end">¥<?= number_format($record['calculated_amount']) ?></td>
                                    <td class="text-end">
                                        <span class="<?= $record['difference'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= $record['difference'] >= 0 ? '+' : '' ?>¥<?= number_format($record['difference']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">¥<?= number_format($record['change_stock']) ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-info" onclick="showDetail(<?= $record['id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="cash_management.php?action=edit&edit_id=<?= $record['id'] ?>" class="btn btn-outline-warning">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" onclick="confirmDelete(<?= $record['id'] ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 詳細表示モーダル -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">売上金確認詳細</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailModalBody">
                    <!-- 詳細内容がここに表示されます -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 統一JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/ui-interactions.js"></script>
    
    <script>
        // 現金内訳の自動計算
        document.addEventListener('DOMContentLoaded', function() {
            const denominations = [
                { name: 'bill_10000', value: 10000 },
                { name: 'bill_5000', value: 5000 },
                { name: 'bill_2000', value: 2000 },
                { name: 'bill_1000', value: 1000 },
                { name: 'coin_500', value: 500 },
                { name: 'coin_100', value: 100 },
                { name: 'coin_50', value: 50 },
                { name: 'coin_10', value: 10 },
                { name: 'coin_5', value: 5 },
                { name: 'coin_1', value: 1 }
            ];
            
            function updateTotals() {
                let grandTotal = 0;
                
                denominations.forEach(denom => {
                    const input = document.querySelector(`input[name="${denom.name}"]`);
                    const totalSpan = document.getElementById(`${denom.name}_total`);
                    
                    if (input && totalSpan) {
                        const count = parseInt(input.value) || 0;
                        const total = count * denom.value;
                        totalSpan.textContent = '¥' + total.toLocaleString();
                        grandTotal += total;
                    }
                });
                
                // 合計金額を更新
                const confirmedTotalElement = document.getElementById('confirmed_total');
                const confirmedAmountInput = document.getElementById('confirmed_amount_input');
                
                if (confirmedTotalElement && confirmedAmountInput) {
                    confirmedTotalElement.textContent = '¥' + grandTotal.toLocaleString();
                    confirmedAmountInput.value = grandTotal;
                }
                
                // 差額を計算
                const calculatedAmount = parseInt(document.querySelector('input[name="calculated_amount"]')?.value) || 0;
                const difference = grandTotal - calculatedAmount;
                const differenceElement = document.getElementById('difference_display');
                
                if (differenceElement) {
                    const diffClass = difference >= 0 ? 'text-success' : 'text-danger';
                    differenceElement.className = `h4 mb-0 ${diffClass}`;
                    differenceElement.textContent = (difference >= 0 ? '+' : '') + '¥' + difference.toLocaleString();
                }
            }
            
            // 入力フィールドにイベントリスナーを追加
            denominations.forEach(denom => {
                const input = document.querySelector(`input[name="${denom.name}"]`);
                if (input) {
                    input.addEventListener('input', updateTotals);
                    input.addEventListener('change', updateTotals);
                }
            });
            
            // システム計算額の変更にも対応
            const calculatedAmountInput = document.querySelector('input[name="calculated_amount"]');
            if (calculatedAmountInput) {
                calculatedAmountInput.addEventListener('input', updateTotals);
                calculatedAmountInput.addEventListener('change', updateTotals);
            }
            
            // 初期計算
            updateTotals();
        });
        
        // 詳細表示
        function showDetail(recordId) {
            fetch(`api/cash_detail.php?id=${recordId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const record = data.data;
                        const breakdown = JSON.parse(record.cash_breakdown || '{}');
                        
                        const detailHtml = `
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>基本情報</h6>
                                    <table class="table table-sm">
                                        <tr><td>確認日</td><td>${record.confirmation_date}</td></tr>
                                        <tr><td>運転者</td><td>${record.driver_name}</td></tr>
                                        <tr><td>実際の現金</td><td>¥${parseInt(record.confirmed_amount).toLocaleString()}</td></tr>
                                        <tr><td>システム計算</td><td>¥${parseInt(record.calculated_amount).toLocaleString()}</td></tr>
                                        <tr><td>差額</td><td class="${record.difference >= 0 ? 'text-success' : 'text-danger'}">
                                            ${record.difference >= 0 ? '+' : ''}¥${parseInt(record.difference).toLocaleString()}</td></tr>
                                        <tr><td>釣銭在庫</td><td>¥${parseInt(record.change_stock).toLocaleString()}</td></tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>現金内訳</h6>
                                    <table class="table table-sm">
                                        <tr><td>10,000円札</td><td>${breakdown.bill_10000 || 0}枚</td><td>¥${((breakdown.bill_10000 || 0) * 10000).toLocaleString()}</td></tr>
                                        <tr><td>5,000円札</td><td>${breakdown.bill_5000 || 0}枚</td><td>¥${((breakdown.bill_5000 || 0) * 5000).toLocaleString()}</td></tr>
                                        <tr><td>2,000円札</td><td>${breakdown.bill_2000 || 0}枚</td><td>¥${((breakdown.bill_2000 || 0) * 2000).toLocaleString()}</td></tr>
                                        <tr><td>1,000円札</td><td>${breakdown.bill_1000 || 0}枚</td><td>¥${((breakdown.bill_1000 || 0) * 1000).toLocaleString()}</td></tr>
                                        <tr><td>500円玉</td><td>${breakdown.coin_500 || 0}枚</td><td>¥${((breakdown.coin_500 || 0) * 500).toLocaleString()}</td></tr>
                                        <tr><td>100円玉</td><td>${breakdown.coin_100 || 0}枚</td><td>¥${((breakdown.coin_100 || 0) * 100).toLocaleString()}</td></tr>
                                        <tr><td>50円玉</td><td>${breakdown.coin_50 || 0}枚</td><td>¥${((breakdown.coin_50 || 0) * 50).toLocaleString()}</td></tr>
                                        <tr><td>10円玉</td><td>${breakdown.coin_10 || 0}枚</td><td>¥${((breakdown.coin_10 || 0) * 10).toLocaleString()}</td></tr>
                                        <tr><td>5円玉</td><td>${breakdown.coin_5 || 0}枚</td><td>¥${((breakdown.coin_5 || 0) * 5).toLocaleString()}</td></tr>
                                        <tr><td>1円玉</td><td>${breakdown.coin_1 || 0}枚</td><td>¥${((breakdown.coin_1 || 0) * 1).toLocaleString()}</td></tr>
                                    </table>
                                </div>
                            </div>
                            ${record.memo ? `<div class="mt-3"><h6>メモ・備考</h6><p class="bg-light p-2 rounded">${record.memo}</p></div>` : ''}
                        `;
                        
                        document.getElementById('detailModalBody').innerHTML = detailHtml;
                        new bootstrap.Modal(document.getElementById('detailModal')).show();
                    } else {
                        alert('詳細情報の取得に失敗しました。');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('詳細情報の取得中にエラーが発生しました。');
                });
        }
        
        // 削除確認
        function confirmDelete(recordId) {
            if (confirm('この売上金確認記録を削除してもよろしいですか？')) {
                fetch('api/cash_delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: recordId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('削除に失敗しました: ' + (data.message || ''));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('削除中にエラーが発生しました。');
                });
            }
        }
        
        // フォーム送信時の確認
        const form = document.querySelector('form[method="POST"]');
        if (form) {
            form.addEventListener('submit', function(e) {
                const confirmedAmount = parseInt(document.getElementById('confirmed_amount_input')?.value) || 0;
                const calculatedAmount = parseInt(document.querySelector('input[name="calculated_amount"]')?.value) || 0;
                const difference = confirmedAmount - calculatedAmount;
                
                if (Math.abs(difference) > 1000) {
                    if (!confirm(`差額が¥${Math.abs(difference).toLocaleString()}あります。本当に登録しますか？`)) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        }
    </script>
</body>
</html>
