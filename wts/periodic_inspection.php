<?php
session_start();
require_once 'config/database.php';
require_once 'includes/unified-header.php';

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

// 車両とユーザーの取得
try {
    $stmt = $pdo->prepare("SELECT id, vehicle_number, model, current_mileage, next_inspection_date FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE (is_driver = 1 OR is_manager = 1 OR is_mechanic = 1 OR is_inspector = 1) AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $inspectors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $vehicles = [];
    $inspectors = [];
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // 基本情報の取得
        $vehicle_id = $_POST['vehicle_id'];
        $inspection_date = $_POST['inspection_date'];
        $inspector_id = $_POST['inspector_id'];
        $mileage = $_POST['mileage'];
        $inspection_type = $_POST['inspection_type'] ?? '3months';
        
        // 点検記録をperiodic_inspectionsテーブルに保存
        $sql = "INSERT INTO periodic_inspections (
            vehicle_id, inspection_date, inspector_id, mileage, inspection_type,
            service_provider_name, service_provider_address, remarks, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $vehicle_id, 
            $inspection_date, 
            $inspector_id, 
            $mileage, 
            $inspection_type,
            $_POST['service_provider_name'] ?? '',
            $_POST['service_provider_address'] ?? '',
            $_POST['remarks'] ?? ''
        ]);
        
        $inspection_id = $pdo->lastInsertId();
        
        // 点検項目を保存（periodic_inspection_itemsテーブル）
        $categories = [
            'steering' => ['steering_play', 'steering_effort', 'steering_connection'],
            'braking' => ['brake_pedal_play', 'brake_pedal_clearance', 'brake_effectiveness', 
                         'parking_brake_effectiveness', 'brake_fluid_amount', 'brake_line_condition'],
            'running' => ['wheel_bearing_play', 'wheel_nut_looseness'],
            'suspension' => ['spring_damage', 'shock_absorber_condition'],
            'power_transmission' => ['clutch_pedal_play', 'clutch_effectiveness', 'transmission_oil_amount', 
                                   'transmission_oil_leakage', 'propeller_shaft_connection'],
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

// 統一ヘッダーの表示
echo renderCompleteHTMLHead();
echo renderSystemHeader();

$page_actions = [
    [
        'icon' => 'arrow-left',
        'text' => 'ダッシュボード',
        'url' => 'dashboard.php',
        'class' => 'btn-secondary'
    ],
    [
        'icon' => 'check-double',
        'text' => '全て良好',
        'url' => 'javascript:setAllGood()',
        'class' => 'btn-success'
    ]
];

echo renderPageHeader('periodic_inspection', $page_actions);

?>

<div class="container-fluid px-4">
    <!-- アラート表示 -->
    <?php if ($success_message): ?>
        <?php echo renderAlert('success', $success_message, true); ?>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <?php echo renderAlert('danger', $error_message, true); ?>
    <?php endif; ?>

    <form method="POST" id="inspectionForm">
        <!-- 基本情報セクション -->
        <?php
        $basic_info_actions = [
            [
                'icon' => 'check-circle',
                'text' => '自動入力',
                'url' => 'javascript:updateMileage()',
                'class' => 'btn-info btn-sm'
            ]
        ];
        echo renderSectionHeader('info-circle', '基本情報', '必須項目を入力してください', $basic_info_actions);
        ?>
        
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">車両</label>
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
                    
                    <div class="col-md-6">
                        <label class="form-label required">点検日</label>
                        <input type="date" class="form-control" name="inspection_date" value="<?php echo $today; ?>" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">点検者</label>
                        <select class="form-select" name="inspector_id" required>
                            <option value="">選択してください</option>
                            <?php foreach ($inspectors as $inspector): ?>
                            <option value="<?php echo $inspector['id']; ?>">
                                <?php echo htmlspecialchars($inspector['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">走行距離</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="mileage" id="mileage" required>
                            <span class="input-group-text">km</span>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label required">点検の種類</label>
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
        <?php echo renderSectionHeader('steering-wheel', 'かじ取り装置', '3項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $steering_items = [
                    'steering_play' => 'ハンドルの遊び',
                    'steering_effort' => 'ハンドルの操作具合',
                    'steering_connection' => 'ロッド及びアーム類の緩み、がた、損傷'
                ];
                foreach ($steering_items as $key => $label): ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-md-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 7. 原動機 -->
        <?php echo renderSectionHeader('car-side', '原動機', '4項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $engine_items = [
                    'valve_clearance' => 'バルブ・クリアランス',
                    'engine_oil_leakage' => 'エンジン・オイルの漏れ',
                    'exhaust_gas_condition' => '排気の状況',
                    'air_cleaner_element' => 'エア・クリーナー・エレメントの状況'
                ];
                foreach ($engine_items as $key => $label): ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-md-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- CO・HC濃度測定 -->
        <?php echo renderSectionHeader('cloud', 'CO・HC濃度測定', '排気ガス測定値'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">CO濃度</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="co_concentration" step="0.01" min="0" placeholder="0.00">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">基準値：1.0%以下</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">HC濃度</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="hc_concentration" step="1" min="0" placeholder="0">
                            <span class="input-group-text">ppm</span>
                        </div>
                        <div class="form-text">基準値：300ppm以下</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 整備事業者情報 -->
        <?php echo renderSectionHeader('building', '整備事業者情報', '整備実施者の情報'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">事業者名</label>
                        <input type="text" class="form-control" name="service_provider_name" 
                               placeholder="整備を実施した事業者名" value="スマイリーケアタクシー">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">事業者住所</label>
                        <input type="text" class="form-control" name="service_provider_address" 
                               placeholder="事業者の住所">
                    </div>
                </div>
            </div>
        </div>

        <!-- 備考 -->
        <?php echo renderSectionHeader('comment', '備考', '特記事項の記載'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <textarea class="form-control" name="remarks" rows="3" 
                          placeholder="整備内容や特記事項があれば記入してください"></textarea>
            </div>
        </div>

        <!-- 保存ボタン -->
        <div class="text-center mb-5">
            <button type="submit" class="btn btn-primary btn-lg px-5">
                <i class="fas fa-save me-2"></i>定期点検記録を保存
            </button>
        </div>
    </form>
</div>

<style>
/* 点検項目のスタイル */
.inspection-item {
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
    transition: all 0.2s ease;
}

.inspection-item:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
}

/* 結果ボタンのスタイル（3段階評価） */
.result-btn {
    min-width: 80px;
    height: 36px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    border: 2px solid;
    padding: 0.25rem 0.75rem;
}

.result-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.result-btn.active {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

/* 3段階評価の色設定 */
.result-btn-good { 
    background: #f0fdf4; 
    color: #16a34a; 
    border-color: #16a34a;
}
.result-btn-good.active { 
    background: #16a34a; 
    color: white;
}

.result-btn-caution { 
    background: #fefce8; 
    color: #ca8a04; 
    border-color: #ca8a04;
}
.result-btn-caution.active { 
    background: #ca8a04; 
    color: white;
}

.result-btn-bad { 
    background: #fef2f2; 
    color: #dc2626; 
    border-color: #dc2626;
}
.result-btn-bad.active { 
    background: #dc2626; 
    color: white;
}

/* フォーム必須マーク */
.required::after {
    content: " *";
    color: #dc2626;
    font-weight: bold;
}

/* カード統一スタイル */
.card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.card-body {
    padding: 1.5rem;
}

/* レスポンシブ対応 */
@media (max-width: 768px) {
    .result-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .result-btn {
        min-width: 64px;
        height: 32px;
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
    
    .inspection-item .col-md-4,
    .inspection-item .col-md-8 {
        margin-bottom: 0.5rem;
    }
}
</style>

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
                nextInfo.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>点検期限切れ（' + Math.abs(diffDays) + '日経過）</span>';
            } else if (diffDays <= 7) {
                nextInfo.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>点検期限まであと' + diffDays + '日</span>';
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
        if (btn.textContent.trim() === value) {
            btn.classList.add('active');
        }
    });
}

// 全て良好にする
function setAllGood() {
    const allItems = document.querySelectorAll('.result-buttons');
    allItems.forEach(function(item) {
        const itemName = item.getAttribute('data-item');
        setResult(itemName, '良好');
    });
    
    // 成功メッセージを表示
    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            全ての項目を「良好」に設定しました。
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);
    
    // 3秒後にアラートを自動で閉じる
    setTimeout(() => {
        const alert = document.querySelector('.alert-success');
        if (alert) {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }
    }, 3000);
}

// 初期化処理
document.addEventListener('DOMContentLoaded', function() {
    updateMileage();
    
    // フォーム送信前の確認
    document.getElementById('inspectionForm').addEventListener('submit', function(e) {
        const vehicleId = document.querySelector('select[name="vehicle_id"]').value;
        const inspectorId = document.querySelector('select[name="inspector_id"]').value;
        const mileage = document.getElementById('mileage').value;
        
        if (!vehicleId || !inspectorId || !mileage) {
            e.preventDefault();
            alert('必須項目（車両、点検者、走行距離）を入力してください。');
            return;
        }
        
        if (!confirm('定期点検記録を保存しますか？\n\n次回点検日が自動的に3か月後に設定されます。')) {
            e.preventDefault();
        }
    });
});
</script>

<?php echo '</body></html>'; ?> me-1" onclick="setResult('<?php echo $key; ?>', 'V')">V</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-supply me-1" onclick="setResult('<?php echo $key; ?>', 'T')">T</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-1" onclick="setResult('<?php echo $key; ?>', 'L')">L</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-repair me-1" onclick="setResult('<?php echo $key; ?>', '×')">×</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 2. 制動装置 -->
        <?php echo renderSectionHeader('stop-circle', '制動装置', '6項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $braking_items = [
                    'brake_pedal_play' => 'ブレーキ・ペダルの遊び',
                    'brake_pedal_clearance' => 'ブレーキ・ペダルの踏み込み時の床板とのすき間',
                    'brake_effectiveness' => 'ブレーキの効き具合',
                    'parking_brake_effectiveness' => '駐車ブレーキ機構の効き具合',
                    'brake_fluid_amount' => 'ブレーキ液の量',
                    'brake_line_condition' => 'ホース及びパイプの液漏れ'
                ];
                foreach ($braking_items as $key => $label): ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-md-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 3. 走行装置 -->
        <?php echo renderSectionHeader('circle', '走行装置', '2項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $running_items = [
                    'wheel_bearing_play' => 'ホイール・ベアリングのがた',
                    'wheel_nut_looseness' => 'ホイール・ナットの緩み'
                ];
                foreach ($running_items as $key => $label): ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-md-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 4. 緩衝装置 -->
        <?php echo renderSectionHeader('wave-square', '緩衝装置', '2項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $suspension_items = [
                    'spring_damage' => 'スプリングの損傷',
                    'shock_absorber_condition' => 'ショック・アブソーバーの損傷及び油の漏れ'
                ];
                foreach ($suspension_items as $key => $label): ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-md-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-good me-2" onclick="setResult('<?php echo $key; ?>', '良好')">良好</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-caution me-2" onclick="setResult('<?php echo $key; ?>', '要注意')">要注意</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-bad me-2" onclick="setResult('<?php echo $key; ?>', '不良')">不良</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 5. 動力伝達装置 -->
        <?php echo renderSectionHeader('cogs', '動力伝達装置', '5項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $power_transmission_items = [
                    'clutch_pedal_play' => 'クラッチ・ペダルの遊び',
                    'clutch_effectiveness' => 'クラッチの効き具合',
                    'transmission_oil_amount' => 'トランスミッション・オイルの量',
                    'transmission_oil_leakage' => 'トランスミッション・オイルの漏れ',
                    'propeller_shaft_connection' => 'プロペラ・シャフト等の連結部の緩み'
                ];
                foreach ($power_transmission_items as $key => $label): ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-md-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-ok me-1" onclick="setResult('<?php echo $key; ?>', '○')">○</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-adjust me-1" onclick="setResult('<?php echo $key; ?>', '△')">△</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-clean me-1" onclick="setResult('<?php echo $key; ?>', 'A')">A</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-replace me-1" onclick="setResult('<?php echo $key; ?>', 'C')">C</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-check me-1" onclick="setResult('<?php echo $key; ?>', 'V')">V</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-supply me-1" onclick="setResult('<?php echo $key; ?>', 'T')">T</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-na me-1" onclick="setResult('<?php echo $key; ?>', 'L')">L</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-repair me-1" onclick="setResult('<?php echo $key; ?>', '×')">×</button>
                            </div>
                            <input type="hidden" name="<?php echo $key; ?>" id="<?php echo $key; ?>">
                            <input type="text" class="form-control form-control-sm" name="<?php echo $key; ?>_note" placeholder="備考（必要な場合）">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 6. 電気装置 -->
        <?php echo renderSectionHeader('bolt', '電気装置', '4項目の点検'); ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $electrical_items = [
                    'spark_plug_condition' => '点火プラグの状況',
                    'battery_terminal' => 'バッテリー・ターミナルの接続状況',
                    'ignition_timing' => '点火時期',
                    'distributor_cap_condition' => 'デストリビューター・キャップの状況'
                ];
                foreach ($electrical_items as $key => $label): ?>
                <div class="inspection-item mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <strong><?php echo $label; ?></strong>
                        </div>
                        <div class="col-md-8">
                            <div class="result-buttons mb-2" data-item="<?php echo $key; ?>">
                                <button type="button" class="btn btn-sm result-btn result-btn-ok me-1" onclick="setResult('<?php echo $key; ?>', '○')">○</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-adjust me-1" onclick="setResult('<?php echo $key; ?>', '△')">△</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-clean me-1" onclick="setResult('<?php echo $key; ?>', 'A')">A</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-replace me-1" onclick="setResult('<?php echo $key; ?>', 'C')">C</button>
                                <button type="button" class="btn btn-sm result-btn result-btn-check
