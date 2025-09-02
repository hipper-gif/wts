<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 編集モード判定
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === '1';
$edit_date = $_GET['date'] ?? $today;
$edit_inspector_id = $_GET['inspector_id'] ?? null;
$edit_vehicle_id = $_GET['vehicle_id'] ?? null;

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '未設定';
$user_role = $_SESSION['permission_level'] ?? 'User';
$today = date('Y-m-d');

$success_message = '';
$error_message = '';

// 車両とドライバーの取得
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model, current_mileage FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 点検者取得の統一（運転者のみ）
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $vehicles = [];
    $drivers = [];
}

// 編集用の点検記録があるかチェック
$existing_inspection = null;
if ($edit_mode && $edit_inspector_id && $edit_vehicle_id) {
    $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
    $stmt->execute([$edit_inspector_id, $edit_vehicle_id, $edit_date]);
    $existing_inspection = $stmt->fetch();
    
    if (!$existing_inspection) {
        $error_message = '指定された日付・運転者・車両の点検記録が見つかりません。';
    }
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inspector_id = $_POST['inspector_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $inspection_date = $_POST['inspection_date'] ?? $today;
    $mileage = $_POST['mileage'];
    $inspection_time = $_POST['inspection_time'] ?? date('H:i');
    
    // 点検者の確認（統一された権限チェック）
    $stmt = $pdo->prepare("SELECT is_driver FROM users WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$inspector_id]);
    $inspector = $stmt->fetch();
    
    if (!$inspector || $inspector['is_driver'] != 1) {
        $error_message = 'エラー: 点検者は運転手のみ選択できます。';
    } else {
        // 点検項目の結果
        $inspection_items = [
            // 運転室内
            'foot_brake_result', 'parking_brake_result', 'engine_start_result', 
            'engine_performance_result', 'wiper_result', 'washer_spray_result',
            // エンジンルーム内
            'brake_fluid_result', 'coolant_result', 'engine_oil_result', 
            'battery_fluid_result', 'washer_fluid_result', 'fan_belt_result',
            // 灯火類とタイヤ
            'lights_result', 'lens_result', 'tire_pressure_result', 
            'tire_damage_result', 'tire_tread_result'
        ];
        
        try {
            // 既存レコードの確認
            $stmt = $pdo->prepare("SELECT id FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
            $stmt->execute([$inspector_id, $vehicle_id, $inspection_date]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 更新
                $sql = "UPDATE daily_inspections SET 
                    mileage = ?, inspection_time = ?,";
                
                foreach ($inspection_items as $item) {
                    $sql .= " $item = ?,";
                }
                
                $sql .= " defect_details = ?, remarks = ?, updated_at = NOW() 
                    WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?";
                
                $stmt = $pdo->prepare($sql);
                $params = [$mileage, $inspection_time];
                
                foreach ($inspection_items as $item) {
                    $params[] = $_POST[$item] ?? '省略';
                }
                
                $params[] = $_POST['defect_details'] ?? '';
                $params[] = $_POST['remarks'] ?? '';
                $params[] = $inspector_id;
                $params[] = $vehicle_id;
                $params[] = $inspection_date;
                
                $stmt->execute($params);
                $success_message = '日常点検記録を更新しました。';
            } else {
                // 新規挿入
                $sql = "INSERT INTO daily_inspections (
                    vehicle_id, driver_id, inspection_date, inspection_time, mileage,";
                
                foreach ($inspection_items as $item) {
                    $sql .= " $item,";
                }
                
                $sql .= " defect_details, remarks) VALUES (?, ?, ?, ?, ?,";
                
                $sql .= str_repeat('?,', count($inspection_items));
                $sql .= " ?, ?)";
                
                $stmt = $pdo->prepare($sql);
                $params = [$vehicle_id, $inspector_id, $inspection_date, $inspection_time, $mileage];
                
                foreach ($inspection_items as $item) {
                    $params[] = $_POST[$item] ?? '省略';
                }
                
                $params[] = $_POST['defect_details'] ?? '';
                $params[] = $_POST['remarks'] ?? '';
                
                $stmt->execute($params);
                $success_message = '日常点検記録を登録しました。';
            }
            
            // 記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
            $stmt->execute([$inspector_id, $vehicle_id, $inspection_date]);
            $existing_inspection = $stmt->fetch();
            
            // 車両の走行距離を更新
            if ($mileage) {
                $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?");
                $stmt->execute([$mileage, $vehicle_id]);
            }
            
        } catch (Exception $e) {
            $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
            error_log("Daily inspection error: " . $e->getMessage());
        }
    }
}

// ヘッダー統一用関数
function renderSystemHeader($user_name, $user_role, $back_url = 'dashboard.php') {
    return "
    <div class=\"system-header\">
        <div class=\"container-fluid\">
            <div class=\"row align-items-center\">
                <div class=\"col\">
                    <div class=\"system-title\">
                        <i class=\"fas fa-truck-medical me-2\"></i>
                        福祉輸送管理システム
                    </div>
                    <div class=\"user-info\">
                        <i class=\"fas fa-user me-1\"></i>
                        <span class=\"user-name\">{$user_name}</span>
                        <span class=\"user-role\">（{$user_role}）</span>
                        <span class=\"current-time\">" . date('Y年n月j日 H:i') . "</span>
                    </div>
                </div>
                <div class=\"col-auto\">
                    <a href=\"pre_duty_call.php\" class=\"btn btn-light btn-sm me-2\">
                        <i class=\"fas fa-clipboard-check me-1\"></i>乗務前点呼
                    </a>
                    <a href=\"{$back_url}\" class=\"btn btn-outline-light btn-sm me-2\">
                        <i class=\"fas fa-arrow-left me-1\"></i>ダッシュボード
                    </a>
                    <a href=\"logout.php\" class=\"btn btn-outline-light btn-sm\">
                        <i class=\"fas fa-sign-out-alt me-1\"></i>ログアウト
                    </a>
                </div>
            </div>
        </div>
    </div>";
}

function renderSectionHeader($icon, $title, $subtitle = '') {
    $subtitleHtml = $subtitle ? "<div class=\"section-subtitle\">{$subtitle}</div>" : '';
    return "
    <div class=\"section-header\">
        <div class=\"section-title\">
            <i class=\"fas fa-{$icon} me-2\"></i>{$title}
        </div>
        {$subtitleHtml}
    </div>";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>日常点検 - 福祉輸送管理システム</title>
    
    <!-- 必須CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        /* システム全体の基本設定 */
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* システムヘッダー統一デザイン */
        .system-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .system-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }

        .user-info {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .user-name {
            font-weight: 600;
        }

        .user-role {
            color: #e3f2fd;
        }

        .current-time {
            margin-left: 1rem;
            color: #fff3e0;
        }

        /* 機能ヘッダー */
        .function-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-bottom: 2rem;
        }

        .function-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .function-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* セクションヘッダー */
        .section-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 1rem 1.5rem;
            margin: 2rem 0 0 0;
            border-radius: 10px 10px 0 0;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }

        .section-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 0.25rem;
        }

        /* モード切り替え */
        .edit-mode-section {
            margin: 2rem 0;
        }

        .edit-mode-badge {
            background: #ffc107;
            color: #212529;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }

        /* フォームカード */
        .form-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .form-card-body {
            padding: 2rem;
        }

        /* 点検項目 */
        .inspection-item {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .inspection-item:hover {
            background: #e3f2fd;
            border-color: #2196f3;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.15);
        }

        .inspection-item.ok {
            background: #e8f5e8;
            border-color: #28a745;
            box-shadow: 0 2px 10px rgba(40, 167, 69, 0.15);
        }

        .inspection-item.ng {
            background: #f8e6e6;
            border-color: #dc3545;
            box-shadow: 0 2px 10px rgba(220, 53, 69, 0.15);
        }

        .inspection-item.skip {
            background: #fff3cd;
            border-color: #ffc107;
            box-shadow: 0 2px 10px rgba(255, 193, 7, 0.15);
        }

        /* ボタン */
        .btn-result {
            min-width: 85px;
            margin: 0 0.25rem;
            font-weight: 600;
            border-radius: 20px;
            transition: all 0.2s ease;
        }

        .btn-result.active {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .btn-result:hover {
            transform: translateY(-2px);
        }

        /* 操作ボタン */
        .control-buttons {
            text-align: center;
            margin-bottom: 1rem;
        }

        .control-buttons .btn {
            margin: 0 0.5rem;
            min-width: 120px;
            border-radius: 20px;
            font-weight: 600;
        }

        /* 走行距離情報 */
        .mileage-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border: 2px solid #2196f3;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        /* ナビゲーションリンク */
        .navigation-links {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-top: 3rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }

        .navigation-links .btn {
            border-radius: 20px;
            font-weight: 600;
            min-width: 140px;
        }

        /* アラート */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f1b0b7 100%);
            color: #721c24;
        }

        /* レスポンシブ対応 */
        @media (max-width: 768px) {
            .system-title {
                font-size: 1.2rem;
            }
            
            .function-title {
                font-size: 1.6rem;
            }
            
            .form-card-body {
                padding: 1.5rem;
            }
            
            .btn-result {
                min-width: 65px;
                font-size: 0.9rem;
            }
            
            .mode-switch .btn {
                min-width: 140px;
                margin: 0.25rem;
            }
            
            .edit-mode-badge {
                display: block;
                margin: 0.5rem 0 0 0;
            }
        }

        /* アニメーション効果 */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-card {
            animation: fadeInUp 0.5s ease forwards;
        }

        .form-card:nth-child(2) {
            animation-delay: 0.1s;
        }

        .form-card:nth-child(3) {
            animation-delay: 0.2s;
        }

        .form-card:nth-child(4) {
            animation-delay: 0.3s;
        }
    </style>
</head>
<body>
    <!-- システムヘッダー -->
    <?= renderSystemHeader($user_name, $user_role, 'dashboard.php') ?>

    <!-- 機能ヘッダー -->
    <div class="function-header">
        <div class="function-title">
            <i class="fas fa-tools"></i> 日常点検
            <?php if ($edit_mode): ?>
            <span class="edit-mode-badge">編集モード</span>
            <?php endif; ?>
        </div>
        <div class="function-subtitle">
            <?php if ($edit_mode): ?>
                <?= date('Y年n月j日 (D)', strtotime($edit_date)) ?> の記録を編集中
            <?php else: ?>
                <?= date('Y年n月j日 (D)') ?> - 17項目チェック
            <?php endif; ?>
        </div>
    </div>

    <!-- 編集モード切り替え -->
    <?php if (!$edit_mode): ?>
    <div class="edit-mode-section">
        <div class="container">
            <div class="alert alert-info">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <i class="fas fa-edit me-2"></i>
                        <strong>既存の点検記録を修正したい場合</strong>
                        <br>
                        <small>過去に入力した点検記録に間違いがあった場合、以下から修正できます</small>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editModal">
                            <i class="fas fa-search me-1"></i>記録を検索・修正
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 編集用検索モーダル -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-search me-2"></i>点検記録の検索
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editSearchForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">点検日</label>
                            <input type="date" class="form-control" id="searchDate" name="search_date" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">運転者</label>
                            <select class="form-select" id="searchInspector" name="search_inspector" required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>">
                                    <?= htmlspecialchars($driver['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">車両</label>
                            <select class="form-select" id="searchVehicle" name="search_vehicle" required>
                                <option value="">車両を選択</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?= $vehicle['id'] ?>">
                                    <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                    <?= $vehicle['model'] ? ' (' . htmlspecialchars($vehicle['model']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-search me-1"></i>記録を検索
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="container">
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
        
        <form method="POST" id="inspectionForm">
            <!-- 基本情報 -->
            <?= renderSectionHeader('info-circle', '基本情報', '点検日・点検者・車両の基本情報を入力') ?>
            <div class="form-card">
                <div class="form-card-body">
                    <!-- 操作ボタン -->
                    <div class="control-buttons">
                        <button type="button" class="btn btn-outline-success btn-sm me-2" id="allOkBtn">
                            <i class="fas fa-check-circle me-1"></i>全て可
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="allNgBtn">
                            <i class="fas fa-times-circle me-1"></i>全て否
                        </button>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt"></i> 点検日 <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" name="inspection_date" 
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_date'] : ($edit_mode ? $edit_date : $today) ?>" 
                                   <?= $edit_mode ? 'readonly' : '' ?> required>
                            <?php if ($edit_mode): ?>
                            <div class="form-text text-warning">
                                <i class="fas fa-lock me-1"></i>編集モードでは点検日は変更できません
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-clock"></i> 点検時間
                            </label>
                            <input type="time" class="form-control" name="inspection_time" 
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_time'] : date('H:i') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-user-cog"></i> 点検者（運転手） <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" name="inspector_id" <?= $edit_mode ? 'disabled' : '' ?> required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" 
                                    <?php if ($existing_inspection && $existing_inspection['driver_id'] == $driver['id']): ?>
                                        selected
                                    <?php elseif (!$existing_inspection && $driver['id'] == $user_id): ?>
                                        selected
                                    <?php endif; ?>>
                                    <?= htmlspecialchars($driver['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($edit_mode): ?>
                            <input type="hidden" name="inspector_id" value="<?= $existing_inspection['driver_id'] ?>">
                            <div class="form-text text-warning">
                                <i class="fas fa-lock me-1"></i>編集モードでは運転者は変更できません
                            </div>
                            <?php else: ?>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                日常点検は運転手が実施します
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-car"></i> 車両 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" name="vehicle_id" <?= $edit_mode ? 'disabled' : '' ?> required onchange="updateMileage()">
                                <option value="">車両を選択してください</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?= $vehicle['id'] ?>" 
                                        data-mileage="<?= $vehicle['current_mileage'] ?>"
                                        <?= ($existing_inspection && $existing_inspection['vehicle_id'] == $vehicle['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                    <?= $vehicle['model'] ? ' (' . htmlspecialchars($vehicle['model']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($edit_mode): ?>
                            <input type="hidden" name="vehicle_id" value="<?= $existing_inspection['vehicle_id'] ?>">
                            <div class="form-text text-warning">
                                <i class="fas fa-lock me-1"></i>編集モードでは車両は変更できません
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-tachometer-alt"></i> 走行距離
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="mileage" id="mileage"
                                       value="<?= $existing_inspection ? $existing_inspection['mileage'] : '' ?>"
                                       placeholder="現在の走行距離">
                                <span class="input-group-text">km</span>
                            </div>
                            <div class="mileage-info mt-2" id="mileageInfo" style="display: none;">
                                <i class="fas fa-info-circle me-1"></i>
                                <span id="mileageText">前回記録: </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 運転室内点検 -->
            <?= renderSectionHeader('car', '運転室内点検', 'ブレーキ・エンジン・ワイパー等の点検') ?>
            <div class="form-card">
                <div class="form-card-body">
                    <?php
                    $cabin_items = [
                        'foot_brake_result' => ['label' => 'フットブレーキの踏み代・効き', 'required' => true],
                        'parking_brake_result' => ['label' => 'パーキングブレーキの引き代', 'required' => true],
                        'engine_start_result' => ['label' => 'エンジンのかかり具合・異音', 'required' => false],
                        'engine_performance_result' => ['label' => 'エンジンの低速・加速', 'required' => false],
                        'wiper_result' => ['label' => 'ワイパーのふき取り能力', 'required' => false],
                        'washer_spray_result' => ['label' => 'ウインドウォッシャー液の噴射状態', 'required' => false]
                    ];
                    ?>
                    
                    <?php foreach ($cabin_items as $key => $item): ?>
                    <div class="inspection-item" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                <span class="text-danger ms-1">*必須</span>
                                <?php else: ?>
                                <span class="text-muted ms-1">※走行距離・運行状態により省略可</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-success btn-result" for="<?= $key ?>_ok">可</label>
                                    
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-danger btn-result" for="<?= $key ?>_ng">否</label>
                                    
                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-warning btn-result" for="<?= $key ?>_skip">省略</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- エンジンルーム内点検 -->
            <?= renderSectionHeader('cog', 'エンジンルーム内点検', 'オイル・冷却水・ベルト等の点検') ?>
            <div class="form-card">
                <div class="form-card-body">
                    <?php
                    $engine_items = [
                        'brake_fluid_result' => ['label' => 'ブレーキ液量', 'required' => true],
                        'coolant_result' => ['label' => '冷却水量', 'required' => false],
                        'engine_oil_result' => ['label' => 'エンジンオイル量', 'required' => false],
                        'battery_fluid_result' => ['label' => 'バッテリー液量', 'required' => false],
                        'washer_fluid_result' => ['label' => 'ウインドウォッシャー液量', 'required' => false],
                        'fan_belt_result' => ['label' => 'ファンベルトの張り・損傷', 'required' => false]
                    ];
                    ?>
                    
                    <?php foreach ($engine_items as $key => $item): ?>
                    <div class="inspection-item" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                <span class="text-danger ms-1">*必須</span>
                                <?php else: ?>
                                <span class="text-muted ms-1">※走行距離・運行状態により省略可</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-success btn-result" for="<?= $key ?>_ok">可</label>
                                    
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-danger btn-result" for="<?= $key ?>_ng">否</label>
                                    
                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-warning btn-result" for="<?= $key ?>_skip">省略</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 灯火類とタイヤ点検 -->
            <?= renderSectionHeader('lightbulb', '灯火類とタイヤ点検', 'ライト・レンズ・タイヤの点検') ?>
            <div class="form-card">
                <div class="form-card-body">
                    <?php
                    $lights_tire_items = [
                        'lights_result' => ['label' => '灯火類の点灯・点滅', 'required' => true],
                        'lens_result' => ['label' => 'レンズの損傷・汚れ', 'required' => true],
                        'tire_pressure_result' => ['label' => 'タイヤの空気圧', 'required' => true],
                        'tire_damage_result' => ['label' => 'タイヤの亀裂・損傷', 'required' => true],
                        'tire_tread_result' => ['label' => 'タイヤ溝の深さ', 'required' => false]
                    ];
                    ?>
                    
                    <?php foreach ($lights_tire_items as $key => $item): ?>
                    <div class="inspection-item" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                <span class="text-danger ms-1">*必須</span>
                                <?php else: ?>
                                <span class="text-muted ms-1">※走行距離・運行状態により省略可</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-success btn-result" for="<?= $key ?>_ok">可</label>
                                    
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-danger btn-result" for="<?= $key ?>_ng">否</label>
                                    
                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-warning btn-result" for="<?= $key ?>_skip">省略</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 不良個所・備考 -->
            <?= renderSectionHeader('exclamation-triangle', '不良個所及び処置・備考', '点検結果の詳細記録') ?>
            <div class="form-card">
                <div class="form-card-body">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-exclamation-circle"></i> 不良個所及び処置
                        </label>
                        <textarea class="form-control" name="defect_details" rows="3" 
                                  placeholder="点検で「否」となった項目の詳細と処置内容を記入"><?= $existing_inspection ? htmlspecialchars($existing_inspection['defect_details']) : '' ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-sticky-note"></i> 備考
                        </label>
                        <textarea class="form-control" name="remarks" rows="2" 
                                  placeholder="その他特記事項があれば記入"><?= $existing_inspection ? htmlspecialchars($existing_inspection['remarks']) : '' ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 保存ボタン -->
            <div class="text-center mb-4">
                <?php if ($edit_mode): ?>
                <a href="daily_inspection.php" class="btn btn-secondary btn-lg px-4 py-3 me-3">
                    <i class="fas fa-times me-2"></i>編集をキャンセル
                </a>
                <button type="submit" class="btn btn-warning btn-lg px-5 py-3">
                    <i class="fas fa-save me-2"></i>修正を保存
                </button>
                <?php else: ?>
                <button type="submit" class="btn btn-success btn-lg px-5 py-3">
                    <i class="fas fa-save me-2"></i>登録する
                </button>
                <?php endif; ?>
            </div>
        </form>
        
        <!-- ナビゲーションリンク -->
        <div class="navigation-links">
            <div class="row text-center">
                <div class="col-md-4 mb-3">
                    <h6 class="text-muted mb-3">
                        <i class="fas fa-arrow-right me-1"></i>次の作業
                    </h6>
                    <a href="pre_duty_call.php" class="btn btn-outline-primary">
                        <i class="fas fa-clipboard-check me-1"></i>乗務前点呼
                    </a>
                </div>
                <div class="col-md-4 mb-3">
                    <h6 class="text-muted mb-3">
                        <i class="fas fa-tools me-1"></i>他の点検
                    </h6>
                    <a href="periodic_inspection.php" class="btn btn-outline-info">
                        <i class="fas fa-wrench me-1"></i>定期点検
                    </a>
                </div>
                <div class="col-md-4 mb-3">
                    <h6 class="text-muted mb-3">
                        <i class="fas fa-history me-1"></i>記録管理
                    </h6>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-chart-bar me-1"></i>記録一覧
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 車両選択時の走行距離更新
        function updateMileage() {
            const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
            const mileageInput = document.getElementById('mileage');
            const mileageInfo = document.getElementById('mileageInfo');
            const mileageText = document.getElementById('mileageText');
            
            if (vehicleSelect.value) {
                const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
                const currentMileage = selectedOption.getAttribute('data-mileage');
                
                if (currentMileage && currentMileage !== '0') {
                    mileageText.textContent = `前回記録: ${currentMileage}km`;
                    mileageInfo.style.display = 'block';
                    
                    if (!mileageInput.value) {
                        mileageInput.value = currentMileage;
                    }
                } else {
                    mileageInfo.style.display = 'none';
                }
            } else {
                mileageInfo.style.display = 'none';
            }
        }
        
        // 点検結果の変更時にスタイル更新
        function updateInspectionItemStyle(itemName, value) {
            const item = document.querySelector(`[data-item="${itemName}"]`);
            if (item) {
                item.classList.remove('ok', 'ng', 'skip');
                
                if (value === '可') {
                    item.classList.add('ok');
                } else if (value === '否') {
                    item.classList.add('ng');
                } else if (value === '省略') {
                    item.classList.add('skip');
                }
            }
        }
        
        // 一括選択機能（必須項目のみ）
        function setAllResults(value) {
            const requiredItems = [
                'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
                'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
            ];
            
            requiredItems.forEach(function(itemName) {
                const radio = document.querySelector(`input[name="${itemName}"][value="${value}"]`);
                if (radio) {
                    radio.checked = true;
                    updateInspectionItemStyle(itemName, value);
                }
            });
        }
        
        // 初期化処理
        document.addEventListener('DOMContentLoaded', function() {
            // 車両選択の初期設定
            updateMileage();
            
            // 既存の点検結果のスタイル適用
            const radioButtons = document.querySelectorAll('input[type="radio"]:checked');
            radioButtons.forEach(function(radio) {
                const itemName = radio.name;
                const value = radio.value;
                updateInspectionItemStyle(itemName, value);
            });
            
            // ラジオボタンの変更イベント
            const allRadios = document.querySelectorAll('input[type="radio"]');
            allRadios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    updateInspectionItemStyle(this.name, this.value);
                });
            });
            
            // 一括選択ボタンのイベント設定
            const allOkBtn = document.getElementById('allOkBtn');
            const allNgBtn = document.getElementById('allNgBtn');
            
            if (allOkBtn) {
                allOkBtn.addEventListener('click', function() {
                    setAllResults('可');
                });
            }
            
            if (allNgBtn) {
                allNgBtn.addEventListener('click', function() {
                    setAllResults('否');
                });
            }
        });
        
        // 編集検索フォームの処理
        document.getElementById('editSearchForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const searchDate = document.getElementById('searchDate').value;
            const searchInspector = document.getElementById('searchInspector').value;
            const searchVehicle = document.getElementById('searchVehicle').value;
            
            if (!searchDate || !searchInspector || !searchVehicle) {
                alert('すべての項目を選択してください。');
                return;
            }
            
            // 編集用URLに移動
            const editUrl = `daily_inspection.php?edit=1&date=${searchDate}&inspector_id=${searchInspector}&vehicle_id=${searchVehicle}`;
            window.location.href = editUrl;
        });
        
        // フォーム送信前の確認
        document.getElementById('inspectionForm').addEventListener('submit', function(e) {
            const inspectorId = document.querySelector('select[name="inspector_id"]').value;
            const vehicleId = document.querySelector('select[name="vehicle_id"]').value;
            
            if (!inspectorId || !vehicleId) {
                e.preventDefault();
                alert('点検者と車両を選択してください。');
                return;
            }
            
            // 必須項目のチェック
            const requiredItems = [
                'foot_brake_result', 'parking_brake_result', 'brake_fluid_result',
                'lights_result', 'lens_result', 'tire_pressure_result', 'tire_damage_result'
            ];
            
            let missingItems = [];
            requiredItems.forEach(function(item) {
                const checked = document.querySelector(`input[name="${item}"]:checked`);
                if (!checked) {
                    missingItems.push(item);
                }
            });
            
            if (missingItems.length > 0) {
                e.preventDefault();
                alert('必須点検項目に未選択があります。すべての必須項目を選択してください。');
                return;
            }
            
            // 「否」の項目がある場合の確認
            const ngItems = document.querySelectorAll('input[value="否"]:checked');
            if (ngItems.length > 0) {
                const defectDetails = document.querySelector('textarea[name="defect_details"]').value.trim();
                if (!defectDetails) {
                    if (!confirm('点検結果に「否」がありますが、不良個所の詳細が未記入です。このまま保存しますか？')) {
                        e.preventDefault();
                        return;
                    }
                }
            }
        });
        
        // ページ読み込み時のスムーズアニメーション
        window.addEventListener('load', function() {
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.3s ease';
            setTimeout(function() {
                document.body.style.opacity = '1';
            }, 100);
        });
    </script>
</body>
</html>
