<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$today = date('Y-m-d');
$current_time = date('H:i');

$success_message = '';
$error_message = '';

// 車両とドライバーの取得
try {
    // 車両一覧取得（アクティブな車両のみ）
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model, current_mileage FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 運転者一覧取得（新権限システム対応）
    $stmt = $pdo->prepare("SELECT id, name, permission_level FROM users WHERE is_driver = TRUE ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $vehicles = [];
    $drivers = [];
}

// 今日の点検記録があるかチェック
$existing_inspection = null;
if (($_GET['inspector_id'] ?? null) && ($_GET['vehicle_id'] ?? null)) {
    $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
    $stmt->execute([$_GET['inspector_id'], $_GET['vehicle_id'], $today]);
    $existing_inspection = $stmt->fetch();
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inspector_id = $_POST['inspector_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $inspection_date = $_POST['inspection_date'] ?? $today;
    $inspection_time = $_POST['inspection_time'] ?? $current_time;
    $mileage = $_POST['mileage'];
    
    // 新権限システム：運転者・整備者かどうかの確認
    $stmt = $pdo->prepare("SELECT permission_level, is_driver, is_mechanic, name FROM users WHERE id = ?");
    $stmt->execute([$inspector_id]);
    $inspector = $stmt->fetch();
    
    if (!$inspector || (!$inspector['is_driver'] && !$inspector['is_mechanic'])) {
        $error_message = 'エラー: 点検者は運転者または整備者のみ選択できます。';
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
                // 更新処理
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
                // 新規挿入処理
                $sql = "INSERT INTO daily_inspections (
                    vehicle_id, driver_id, inspection_date, inspection_time, mileage,";
                
                foreach ($inspection_items as $item) {
                    $sql .= " $item,";
                }
                
                $sql .= " defect_details, remarks, created_at) VALUES (?, ?, ?, ?, ?,";
                
                $sql .= str_repeat('?,', count($inspection_items));
                $sql .= " ?, ?, NOW())";
                
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
            
            // 連続業務フロー：乗務前点呼への遷移
            if (isset($_POST['continue_to_call'])) {
                header("Location: pre_duty_call.php?auto_flow=1&vehicle_id={$vehicle_id}&driver_id={$inspector_id}&inspection_completed=1");
                exit;
            }
            
        } catch (Exception $e) {
            $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
            error_log("Daily inspection error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>日常点検 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .form-card-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 15px 15px 0 0;
            margin: 0;
        }
        
        .form-card-body {
            padding: 1.5rem;
        }
        
        .inspection-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }
        
        .inspection-item:hover {
            background: #e3f2fd;
            border-color: #2196f3;
        }
        
        .inspection-item.ok {
            background: #e8f5e8;
            border-color: #28a745;
        }
        
        .inspection-item.ng {
            background: #f8e6e6;
            border-color: #dc3545;
        }
        
        .inspection-item.skip {
            background: #fff3cd;
            border-color: #ffc107;
        }
        
        .btn-result {
            min-width: 80px;
            margin: 0 0.25rem;
        }
        
        .btn-result.active {
            font-weight: 600;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .required-mark {
            color: #dc3545;
        }
        
        .optional-mark {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .mileage-info {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .flow-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .form-card-body {
                padding: 1rem;
            }
            .btn-result {
                min-width: 60px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-tools me-2"></i>日常点検</h1>
                    <small><?= date('Y年n月j日 (D)') ?></small>
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
        <!-- 連続業務フロー案内 -->
        <div class="flow-info">
            <div class="row align-items-center">
                <div class="col">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>朝の業務フロー:</strong> 日常点検 → 乗務前点呼 → 出庫処理
                </div>
                <div class="col-auto">
                    <small class="text-muted">「登録して乗務前点呼へ」でスムーズに次の業務に進めます</small>
                </div>
            </div>
        </div>
        
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
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-info-circle me-2"></i>基本情報
                    <div class="float-end">
                        <button type="button" class="btn btn-outline-light btn-sm me-2" id="allOkBtn">
                            <i class="fas fa-check-circle me-1"></i>全て可
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" id="allNgBtn">
                            <i class="fas fa-times-circle me-1"></i>全て否
                        </button>
                    </div>
                </h5>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検日 <span class="required-mark">*</span></label>
                            <input type="date" class="form-control" name="inspection_date" 
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_date'] : $today ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検時刻 <span class="required-mark">*</span></label>
                            <input type="time" class="form-control" name="inspection_time" 
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_time'] : $current_time ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検者（運転者・整備者） <span class="required-mark">*</span></label>
                            <select class="form-select" name="inspector_id" required>
                                <option value="">点検者を選択してください</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" <?= ($existing_inspection && $existing_inspection['driver_id'] == $driver['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['name']) ?>
                                    <?php if ($driver['permission_level'] === 'Admin'): ?>
                                        <span class="text-muted">(管理者・運転者)</span>
                                    <?php else: ?>
                                        <span class="text-muted">(運転者)</span>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                日常点検は運転者が実施します
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">車両 <span class="required-mark">*</span></label>
                            <select class="form-select" name="vehicle_id" required onchange="updateMileage()">
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
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">走行距離</label>
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
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-car me-2"></i>運転室内点検
                </h5>
                <div class="form-card-body">
                    <div class="section-title">必須項目</div>
                    
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
                                <?php if (!$item['required']): ?>
                                <span class="optional-mark">※走行距離・運行状態により省略可</span>
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
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略' || $existing_inspection[$key] == '') ? 'checked' : '' ?>>
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
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-cog me-2"></i>エンジンルーム内点検
                </h5>
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
                                <?php if (!$item['required']): ?>
                                <span class="optional-mark">※走行距離・運行状態により省略可</span>
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
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略' || $existing_inspection[$key] == '') ? 'checked' : '' ?>>
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
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-lightbulb me-2"></i>灯火類とタイヤ点検
                </h5>
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
                                <?php if (!$item['required']): ?>
                                <span class="optional-mark">※走行距離・運行状態により省略可</span>
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
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略' || $existing_inspection[$key] == '') ? 'checked' : '' ?>>
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
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-exclamation-triangle me-2"></i>不良個所及び処置・備考
                </h5>
                <div class="form-card-body">
                    <div class="mb-3">
                        <label class="form-label">不良個所及び処置</label>
                        <textarea class="form-control" name="defect_details" rows="3" 
                                  placeholder="点検で「否」となった項目の詳細と処置内容を記入"><?= $existing_inspection ? htmlspecialchars($existing_inspection['defect_details']) : '' ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea class="form-control" name="remarks" rows="2" 
                                  placeholder="その他特記事項があれば記入"><?= $existing_inspection ? htmlspecialchars($existing_inspection['remarks']) : '' ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 保存ボタン（連続業務フロー対応） -->
            <div class="text-center mb-4">
                <button type="submit" class="btn btn-success btn-lg me-2">
                    <i class="fas fa-save me-2"></i>
                    <?= $existing_inspection ? '更新する' : '登録する' ?>
                </button>
                <button type="submit" name="continue_to_call" value="1" class="btn btn-primary btn-lg">
                    <i class="fas fa-arrow-right me-2"></i>登録して乗務前点呼へ
                </button>
            </div>
        </form>
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
        
        // 一括選択機能
        function setAllResults(value) {
            // 必須項目のみ対象
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
        
        // 全て可
        function setAllOk() {
            setAllResults('可');
        }
        
        // 全て否
        function setAllNg() {
            setAllResults('否');
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
                allOkBtn.addEventListener('click', setAllOk);
            }
            
            if (allNgBtn) {
                allNgBtn.addEventListener('click', setAllNg);
            }
            
            // 日時の自動設定
            const today = new Date();
            const dateInput = document.querySelector('input[name="inspection_date"]');
            const timeInput = document.querySelector('input[name="inspection_time"]');
            
            // デフォルト値が設定されていない場合のみ現在日時を設定
            if (dateInput && !dateInput.value) {
                dateInput.value = today.toISOString().split('T')[0];
            }
            
            if (timeInput && !timeInput.value) {
                const hours = today.getHours().toString().padStart(2, '0');
                const minutes = today.getMinutes().toString().padStart(2, '0');
                timeInput.value = `${hours}:${minutes}`;
            }
        });
        
        // フォーム送信前の確認
        document.getElementById('inspectionForm').addEventListener('submit', function(e) {
            const inspectorId = document.querySelector('select[name="inspector_id"]').value;
            const vehicleId = document.querySelector('select[name="vehicle_id"]').value;
            const inspectionDate = document.querySelector('input[name="inspection_date"]').value;
            const inspectionTime = document.querySelector('input[name="inspection_time"]').value;
            
            if (!inspectorId || !vehicleId || !inspectionDate || !inspectionTime) {
                e.preventDefault();
                alert('点検者、車両、点検日、点検時刻をすべて入力してください。');
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
            
            // 連続業務フローの確認
            if (e.submitter && e.submitter.name === 'continue_to_call') {
                if (!confirm('日常点検を登録して、乗務前点呼画面に移動しますか？')) {
                    e.preventDefault();
                    return;
                }
            }
        });
        
        // キーボードショートカット
        document.addEventListener('keydown', function(e) {
            // Ctrl + S で保存
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('inspectionForm').submit();
            }
            
            // Ctrl + Enter で連続フロー
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                const continueBtn = document.querySelector('button[name="continue_to_call"]');
                if (continueBtn) {
                    continueBtn.click();
                }
            }
        });
        
        // PWA対応：オフライン状態の検知
        window.addEventListener('online', function() {
            const alerts = document.querySelectorAll('.alert-warning.offline-alert');
            alerts.forEach(alert => alert.remove());
        });
        
        window.addEventListener('offline', function() {
            const container = document.querySelector('.container');
            if (container) {
                const offlineAlert = document.createElement('div');
                offlineAlert.className = 'alert alert-warning offline-alert';
                offlineAlert.innerHTML = '<i class="fas fa-wifi me-2"></i>オフライン状態です。データは一時保存され、オンライン復帰時に送信されます。';
                container.insertBefore(offlineAlert, container.firstChild);
            }
        });
    </script>
</body>
</html>
