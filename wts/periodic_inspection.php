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

$success_message = '';
$error_message = '';

// 車両とドライバーの取得
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model, current_mileage, next_inspection_date FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE role IN ('driver', 'manager', 'admin') AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $inspectors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $vehicles = [];
    $inspectors = [];
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id = $_POST['vehicle_id'];
    $inspection_date = $_POST['inspection_date'];
    $inspector_id = $_POST['inspector_id'];
    $mileage = $_POST['mileage'];
    $inspection_type = $_POST['inspection_type'];
    
    // 整備事業者情報
    $service_provider_name = $_POST['service_provider_name'] ?? '';
    $service_provider_address = $_POST['service_provider_address'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // 点検記録を保存
        $sql = "INSERT INTO periodic_inspections (
            vehicle_id, inspection_date, inspector_id, mileage, inspection_type,
            service_provider_name, service_provider_address, remarks, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $vehicle_id, $inspection_date, $inspector_id, $mileage, $inspection_type,
            $service_provider_name, $service_provider_address, $_POST['remarks'] ?? ''
        ]);
        
        $inspection_id = $pdo->lastInsertId();
        
        // 点検項目を保存
        $all_items = [
            // 1. かじ取り装置
            'steering_play', 'steering_effort', 'steering_connection',
            // 2. 制動装置
            'brake_pedal_play', 'brake_pedal_clearance', 'brake_effectiveness', 
            'parking_brake_effectiveness', 'brake_fluid_amount', 'brake_line_condition',
            // 3. 走行装置
            'wheel_bearing_play', 'wheel_nut_looseness',
            // 4. 緩衝装置
            'spring_damage', 'shock_absorber_condition',
            // 5. 動力伝達装置
            'clutch_pedal_play', 'clutch_effectiveness', 'transmission_oil_amount', 
            'transmission_oil_leakage', 'propeller_shaft_connection',
            // 6. 電気装置
            'spark_plug_condition', 'battery_terminal', 'ignition_timing', 'distributor_cap_condition',
            // 7. 原動機
            'valve_clearance', 'engine_oil_leakage', 'exhaust_gas_condition', 'air_cleaner_element'
        ];
        
        $categories = [
            'steering' => ['steering_play', 'steering_effort', 'steering_connection'],
            'braking' => ['brake_pedal_play', 'brake_pedal_clearance', 'brake_effectiveness', 'parking_brake_effectiveness', 'brake_fluid_amount', 'brake_line_condition'],
            'running' => ['wheel_bearing_play', 'wheel_nut_looseness'],
            'suspension' => ['spring_damage', 'shock_absorber_condition'],
            'power_transmission' => ['clutch_pedal_play', 'clutch_effectiveness', 'transmission_oil_amount', 'transmission_oil_leakage', 'propeller_shaft_connection'],
            'electrical' => ['spark_plug_condition', 'battery_terminal', 'ignition_timing', 'distributor_cap_condition'],
            'engine' => ['valve_clearance', 'engine_oil_leakage', 'exhaust_gas_condition', 'air_cleaner_element']
        ];
        
        // カテゴリーごとに項目を保存
        foreach ($categories as $category => $items) {
            foreach ($items as $item) {
                $result = $_POST[$item] ?? '';
                $note = $_POST[$item . '_note'] ?? '';
                
                if ($result !== '') {
                    $sql = "INSERT INTO periodic_inspection_items (
                        inspection_id, category, item_name, result, note
                    ) VALUES (?, ?, ?, ?, ?)";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$inspection_id, $category, $item, $result, $note]);
                }
            }
        }
        
        // CO・HC濃度を保存
        if (!empty($_POST['co_concentration'])) {
            $sql = "INSERT INTO periodic_inspection_items (
                inspection_id, category, item_name, result, note
            ) VALUES (?, 'engine', 'co_concentration', ?, 'CO濃度')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$inspection_id, $_POST['co_concentration']]);
        }
        
        if (!empty($_POST['hc_concentration'])) {
            $sql = "INSERT INTO periodic_inspection_items (
                inspection_id, category, item_name, result, note
            ) VALUES (?, 'engine', 'hc_concentration', ?, 'HC濃度')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$inspection_id, $_POST['hc_concentration']]);
        }
        
        // 車両の次回点検日を更新（3か月後）
        $next_inspection_date = date('Y-m-d', strtotime($inspection_date . ' +3 months'));
        $stmt = $pdo->prepare("UPDATE vehicles SET next_inspection_date = ?, current_mileage = ? WHERE id = ?");
        $stmt->execute([$next_inspection_date, $mileage, $vehicle_id]);
        
        $pdo->commit();
        $success_message = '定期点検記録を登録しました。次回点検日: ' . $next_inspection_date;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
        error_log("Periodic inspection error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>定期点検 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
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
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
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
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
        }
        
        .inspection-item:hover {
            background: #e8f5e8;
            border-color: #28a745;
        }
        
        .result-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .result-btn {
            min-width: 35px;
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            border: 2px solid;
        }
        
        .result-btn.active {
            transform: scale(1.1);
        }
        
        .result-btn-ok { 
            background: #e8f5e8; 
            color: #28a745; 
            border-color: #28a745;
        }
        
        .result-btn-ok.active { 
            background: #28a745; 
            color: white;
        }
        
        .result-btn-adjust { 
            background: #fff3cd; 
            color: #ffc107; 
            border-color: #ffc107;
        }
        
        .result-btn-adjust.active { 
            background: #ffc107; 
            color: white;
        }
        
        .result-btn-clean { 
            background: #cce5ff; 
            color: #0066cc; 
            border-color: #0066cc;
        }
        
        .result-btn-clean.active { 
            background: #0066cc; 
            color: white;
        }
        
        .result-btn-replace { 
            background: #ffe6cc; 
            color: #ff8000; 
            border-color: #ff8000;
        }
        
        .result-btn-replace.active { 
            background: #ff8000; 
            color: white;
        }
        
        .result-btn-check { 
            background: #e6f3ff; 
            color: #0099ff; 
            border-color: #0099ff;
        }
        
        .result-btn-check.active { 
            background: #0099ff; 
            color: white;
        }
        
        .result-btn-supply { 
            background: #f0e6ff; 
            color: #8000ff; 
            border-color: #8000ff;
        }
        
        .result-btn-supply.active { 
            background: #8000ff; 
            color: white;
        }
        
        .result-btn-na { 
            background: #f0f0f0; 
            color: #666; 
            border-color: #666;
        }
        
        .result-btn-na.active { 
            background: #666; 
            color: white;
        }
        
        .result-btn-repair { 
            background: #f8d7da; 
            color: #dc3545; 
            border-color: #dc3545;
        }
        
        .result-btn-repair.active { 
            background: #dc3545; 
            color: white;
        }
        
        .note-input {
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        
        .required-mark {
            color: #dc3545;
        }
        
        .service-provider-section {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
        
        .concentration-input {
            max-width: 120px;
        }
        
        .category-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
    </style>
</head>
<body>
    <!-- ヘッダー -->
    <div class="header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1><i class="fas fa-wrench me-2"></i>定期点検（3か月点検）</h1>
                    <small><?php echo date('Y年n月j日 (D)'); ?></small>
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
        <!-- アラート -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="inspectionForm">
            <!-- 基本情報 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-info-circle me-2"></i>基本情報
                    <button type="button" class="btn btn-outline-light btn-sm float-end" onclick="setAllGood()">
                        <i class="fas fa-check-circle me-1"></i>全て良好
                    </button>
                </h5>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">車両 <span class="required-mark">*</span></label>
                            <select class="form-select" name="vehicle_id" required onchange="updateMileage()">
                                <option value="">選択してください</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['id']; ?>" 
                                        data-mileage="<?php echo $vehicle['current_mileage']; ?>"
                                        data-next-date="<?php echo $vehicle['next_inspection_date']; ?>">
                                    <?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                                    <?php if ($vehicle['model']): ?>
                                        (<?php echo htmlspecialchars($vehicle['model']); ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="nextInspectionInfo" class="form-text"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検日 <span class="required-mark">*</span></label>
                            <input type="date" class="form-control" name="inspection_date" value="<?php echo $today; ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検者 <span class="required-mark">*</span></label>
                            <select class="form-select" name="inspector_id" required>
                                <option value="">選択してください</option>
                                <?php foreach ($inspectors as $inspector): ?>
                                <option value="<?php echo $inspector['id']; ?>">
                                    <?php echo htmlspecialchars($inspector['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">走行距離 <span class="required-mark">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="mileage" id="mileage" required>
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検の種類 <span class="required-mark">*</span></label>
                            <select class="form-select" name="inspection_type" required>
                                <option value="3months" selected>3か月点検</option>
                                <option value="6months">6か月点検</option>
                                <option value="12months">12か月点検</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 1. かじ取り装置 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-steering-wheel me-2"></i>1. かじ取り装置
                </h5>
                <div class="form-card-body">
                    <?php
                    $steering_items = [
                        'steering_play' => 'ハンドルの遊び',
                        'steering_effort' => 'ハンドルの操作具合',
                        'steering_connection' => 'ロッド及びアーム類の緩み、がた、損傷'
                    ];
                    ?>
                    
                    <?php foreach ($steering_items as $key => $label): ?>
                    <div class="inspection-item">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?php echo $label; ?></strong>
                            </div>
                            <div class="col-md-6">
                                <div class="result-buttons" data-item="<?php echo $key; ?>">
                                    <button type="button" class="btn result-btn result-btn-ok" onclick="setResult('<?php echo $key; ?>', '○')">○</button>
                                    <button type="button" class="btn result-btn result-btn-adjust" onclick="setResult('<?php echo $key; ?>', '△')">△</button>
                                    <button type="button" class="btn result-btn result-btn-clean" onclick="setResult('<?php echo $key; ?>', 'A')">A</button>
                                    <button type="button" class="btn result-btn result-btn-replace" onclick="setResult('<?php echo $key; ?>', 'C')">C</button>
                                    <button type="button" class="btn result-btn result-btn-check" onclick="setResult('<?php echo $key; ?>', 'V')">V</button>
                                    <button type="button" class="btn result-btn result-btn-supply" onclick="setResult('<?php echo $key; ?>', 'T')">T</button>
                                    <button type="button" class="btn result-btn result-btn-na" onclick="setResult('<?php echo $key; ?>', 'L')">L</button>
                                    <button type="button" class="btn result-btn result-btn-repair" onclick="setResult('<?php echo $key; ?>', '×')">×</button>
                                </div>
                                <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                                <input type="text" class="form-control note-input" name="<?php echo $key; ?>_note" placeholder="備考">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 2. 制動装置 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-stop-circle me-2"></i>2. 制動装置
                </h5>
                <div class="form-card-body">
                    <?php
                    $braking_items = [
                        'brake_pedal_play' => 'ブレーキ・ペダルの遊び',
                        'brake_pedal_clearance' => 'ブレーキ・ペダルの踏み込み時の床板とのすき間',
                        'brake_effectiveness' => 'ブレーキの効き具合',
                        'parking_brake_effectiveness' => '駐車ブレーキ機構の効き具合',
                        'brake_fluid_amount' => 'ブレーキ液の量',
                        'brake_line_condition' => 'ホース及びパイプの液漏れ'
                    ];
                    ?>
                    
                    <?php foreach ($braking_items as $key => $label): ?>
                    <div class="inspection-item">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?php echo $label; ?></strong>
                            </div>
                            <div class="col-md-6">
                                <div class="result-buttons" data-item="<?php echo $key; ?>">
                                    <button type="button" class="btn result-btn result-btn-ok" onclick="setResult('<?php echo $key; ?>', '○')">○</button>
                                    <button type="button" class="btn result-btn result-btn-adjust" onclick="setResult('<?php echo $key; ?>', '△')">△</button>
                                    <button type="button" class="btn result-btn result-btn-clean" onclick="setResult('<?php echo $key; ?>', 'A')">A</button>
                                    <button type="button" class="btn result-btn result-btn-replace" onclick="setResult('<?php echo $key; ?>', 'C')">C</button>
                                    <button type="button" class="btn result-btn result-btn-check" onclick="setResult('<?php echo $key; ?>', 'V')">V</button>
                                    <button type="button" class="btn result-btn result-btn-supply" onclick="setResult('<?php echo $key; ?>', 'T')">T</button>
                                    <button type="button" class="btn result-btn result-btn-na" onclick="setResult('<?php echo $key; ?>', 'L')">L</button>
                                    <button type="button" class="btn result-btn result-btn-repair" onclick="setResult('<?php echo $key; ?>', '×')">×</button>
                                </div>
                                <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                                <input type="text" class="form-control note-input" name="<?php echo $key; ?>_note" placeholder="備考">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 3. 走行装置 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-circle me-2"></i>3. 走行装置
                </h5>
                <div class="form-card-body">
                    <?php
                    $running_items = [
                        'wheel_bearing_play' => 'ホイール・ベアリングのがた',
                        'wheel_nut_looseness' => 'ホイール・ナットの緩み'
                    ];
                    ?>
                    
                    <?php foreach ($running_items as $key => $label): ?>
                    <div class="inspection-item">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?php echo $label; ?></strong>
                            </div>
                            <div class="col-md-6">
                                <div class="result-buttons" data-item="<?php echo $key; ?>">
                                    <button type="button" class="btn result-btn result-btn-ok" onclick="setResult('<?php echo $key; ?>', '○')">○</button>
                                    <button type="button" class="btn result-btn result-btn-adjust" onclick="setResult('<?php echo $key; ?>', '△')">△</button>
                                    <button type="button" class="btn result-btn result-btn-clean" onclick="setResult('<?php echo $key; ?>', 'A')">A</button>
                                    <button type="button" class="btn result-btn result-btn-replace" onclick="setResult('<?php echo $key; ?>', 'C')">C</button>
                                    <button type="button" class="btn result-btn result-btn-check" onclick="setResult('<?php echo $key; ?>', 'V')">V</button>
                                    <button type="button" class="btn result-btn result-btn-supply" onclick="setResult('<?php echo $key; ?>', 'T')">T</button>
                                    <button type="button" class="btn result-btn result-btn-na" onclick="setResult('<?php echo $key; ?>', 'L')">L</button>
                                    <button type="button" class="btn result-btn result-btn-repair" onclick="setResult('<?php echo $key; ?>', '×')">×</button>
                                </div>
                                <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                                <input type="text" class="form-control note-input" name="<?php echo $key; ?>_note" placeholder="備考">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- CO・HC濃度測定 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-cloud me-2"></i>CO・HC濃度測定
                </h5>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">CO濃度</label>
                            <div class="input-group concentration-input">
                                <input type="number" class="form-control" name="co_concentration" step="0.01" min="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">HC濃度</label>
                            <div class="input-group concentration-input">
                                <input type="number" class="form-control" name="hc_concentration" step="1" min="0">
                                <span class="input-group-text">ppm</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 整備事業者情報 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-building me-2"></i>整備事業者情報
                </h5>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">事業者名</label>
                            <input type="text" class="form-control" name="service_provider_name" placeholder="整備を実施した事業者名">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">事業者住所</label>
                            <input type="text" class="form-control" name="service_provider_address" placeholder="事業者の住所">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 備考 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-comment me-2"></i>備考
                </h5>
                <div class="form-card-body">
                    <textarea class="form-control" name="remarks" rows="3" 
                              placeholder="特記事項があれば記入してください"></textarea>
                </div>
            </div>
            
            <!-- 保存ボタン -->
            <div class="text-center mb-4">
                <button type="submit" class="btn btn-danger btn-lg">
                    <i class="fas fa-save me-2"></i>
                    点検記録を保存
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
            const nextInfo = document.getElementById('nextInspectionInfo');
            
            if (vehicleSelect.value) {
                const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
                const currentMileage = selectedOption.getAttribute('data-mileage');
                const nextDate = selectedOption.getAttribute('data-next-date');
                
                if (currentMileage && currentMileage !== '0') {
                    mileageInput.value = currentMileage;
                }
                
                if (nextDate && nextDate !== 'null' && nextDate !== '') {
                    const today = new Date();
                    const next = new Date(nextDate);
                    const diffTime = next - today;
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    
                    if (diffDays < 0) {
                        nextInfo.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> 点検期限切れ（' + Math.abs(diffDays) + '日経過）</span>';
                    } else if (diffDays <= 7) {
                        nextInfo.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-circle"></i> 点検期限まであと' + diffDays + '日</span>';
                    } else {
                        nextInfo.innerHTML = '<span class="text-muted">次回点検予定日: ' + nextDate + '</span>';
                    }
                } else {
                    nextInfo.innerHTML = '<span class="text-muted">点検予定日未設定</span>';
                }
            } else {
                nextInfo.innerHTML = '';
            }
        }
        
        // 点検結果の設定
        function setResult(itemName, value) {
            // 隠しフィールドに値を設定
            document.getElementById(itemName).value = value;
            
            // ボタンのアクティブ状態を更新
            const buttons = document.querySelectorAll('[data-item="' + itemName + '"] .result-btn');
            buttons.forEach(function(btn) {
                btn.classList.remove('active');
                if (btn.textContent === value) {
                    btn.classList.add('active');
                }
            });
        }
        
        // 全て良好にする
        function setAllGood() {
            const allItems = document.querySelectorAll('.result-buttons');
            allItems.forEach(function(item) {
                const itemName = item.getAttribute('data-item');
                setResult(itemName, '○');
            });
        }
        
        // 初期化処理
        document.addEventListener('DOMContentLoaded', function() {
            updateMileage();
        });
        
        // フォーム送信前の確認
        document.getElementById('inspectionForm').addEventListener('submit', function(e) {
            const vehicleId = document.querySelector('select[name="vehicle_id"]').value;
            const inspectorId = document.querySelector('select[name="inspector_id"]').value;
            const mileage = document.getElementById('mileage').value;
            
            if (!vehicleId || !inspectorId || !mileage) {
                e.preventDefault();
                alert('必須項目を入力してください。');
                return;
            }
            
            if (!confirm('点検記録を保存しますか？')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>