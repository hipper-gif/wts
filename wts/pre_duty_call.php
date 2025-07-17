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

// ドライバーと点呼者の取得
try {
    // 運転者のみ取得（実際に運転する人のみ）
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE (role = 'driver' OR is_driver = 1) AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 点呼者取得（管理者・マネージャーのみ）
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE role IN ('manager', 'admin') AND is_active = TRUE ORDER BY name");
    $stmt->execute();
    $callers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    $drivers = [];
    $callers = [];
}

// 今日の点呼記録があるかチェック
$existing_call = null;
if ($_GET['driver_id'] ?? null) {
    $driver_id = $_GET['driver_id'];
    $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$driver_id, $today]);
    $existing_call = $stmt->fetch();
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'];
    $call_time = $_POST['call_time'];
    
    // 使用予定車両を取得（前回使用車両から推定）
    $stmt = $pdo->prepare("
        SELECT v.id 
        FROM vehicles v 
        WHERE v.is_active = TRUE 
        AND v.id = (
            SELECT ar.vehicle_id 
            FROM arrival_records ar 
            WHERE ar.driver_id = ? 
            ORDER BY ar.arrival_date DESC 
            LIMIT 1
        )
    ");
    $stmt->execute([$driver_id]);
    $vehicle_record = $stmt->fetch();
    
    if (!$vehicle_record) {
        // デフォルト車両を使用
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE is_active = TRUE ORDER BY vehicle_number LIMIT 1");
        $stmt->execute();
        $vehicle_record = $stmt->fetch();
    }
    
    if (!$vehicle_record) {
        $error_message = '使用可能な車両が見つかりません。';
    } else {
        $vehicle_id = $vehicle_record['id'];
        
        // 点呼者名の処理
        $caller_name = $_POST['caller_name'];
        if ($caller_name === 'その他') {
            $caller_name = $_POST['other_caller'];
        }
        
        $alcohol_check_value = $_POST['alcohol_check_value'];
        
        // 確認事項のチェック
        $check_items = [
            'health_check', 'clothing_check', 'footwear_check', 'pre_inspection_check',
            'license_check', 'vehicle_registration_check', 'insurance_check', 'emergency_tools_check',
            'map_check', 'taxi_card_check', 'emergency_signal_check', 'change_money_check',
            'crew_id_check', 'operation_record_check', 'receipt_check', 'stop_sign_check'
        ];
        
        try {
            // 既存レコードの確認（車両IDは使わず、運転者と日付のみで検索）
            $stmt = $pdo->prepare("SELECT id FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 更新
                $sql = "UPDATE pre_duty_calls SET 
                    call_time = ?, caller_name = ?, alcohol_check_value = ?, alcohol_check_time = ?,";
                
                foreach ($check_items as $item) {
                    $sql .= " $item = ?,";
                }
                
                $sql .= " remarks = ?, is_completed = TRUE, updated_at = NOW() 
                    WHERE driver_id = ? AND call_date = ?";
                
                $stmt = $pdo->prepare($sql);
                $params = [$call_time, $caller_name, $alcohol_check_value, $call_time];
                
                foreach ($check_items as $item) {
                    $params[] = isset($_POST[$item]) ? 1 : 0;
                }
                
                $params[] = $_POST['remarks'] ?? '';
                $params[] = $driver_id;
                $params[] = $today;
                
                $stmt->execute($params);
                $success_message = '乗務前点呼記録を更新しました。';
            } else {
                // 新規挿入
                $sql = "INSERT INTO pre_duty_calls (
                    driver_id, vehicle_id, call_date, call_time, caller_name, 
                    alcohol_check_value, alcohol_check_time,";
                
                foreach ($check_items as $item) {
                    $sql .= " $item,";
                }
                
                $sql .= " remarks, is_completed) VALUES (?, ?, ?, ?, ?, ?, ?,";
                
                $sql .= str_repeat('?,', count($check_items));
                $sql .= " ?, TRUE)";
                
                $stmt = $pdo->prepare($sql);
                $params = [$driver_id, $vehicle_id, $today, $call_time, $caller_name, $alcohol_check_value, $call_time];
                
                foreach ($check_items as $item) {
                    $params[] = isset($_POST[$item]) ? 1 : 0;
                }
                
                $params[] = $_POST['remarks'] ?? '';
                
                $stmt->execute($params);
                $success_message = '乗務前点呼記録を登録しました。';
            }
            
            // 記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing_call = $stmt->fetch();
            
        } catch (Exception $e) {
            $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
            error_log("Pre duty call error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗務前点呼 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 15px 15px 0 0;
            margin: 0;
        }
        
        .form-card-body {
            padding: 1.5rem;
        }
        
        .check-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .check-item:hover {
            background: #e3f2fd;
            border-color: #2196f3;
        }
        
        .check-item.checked {
            background: #e8f5e8;
            border-color: #28a745;
        }
        
        .form-check-input:checked {
            background-color: #28a745;
            border-color: #28a745;
        }
        
        .alcohol-input {
            max-width: 150px;
        }
        
        .btn-save {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .required-mark {
            color: #dc3545;
        }
        
        .vehicle-info {
            background: #e8f5e8;
            border: 1px solid #28a745;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        /* スマートフォン用レイアウト修正 */
        @media (max-width: 768px) {
            .form-card-header {
                padding: 0.75rem 1rem;
            }
            
            /* ヘッダーボタンのスマホ対応 - ヘッダー外に配置 */
            .mobile-buttons {
                display: flex;
                gap: 0.5rem;
                margin-bottom: 1rem;
                justify-content: center;
            }
            
            .mobile-buttons .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
                flex: 1;
                max-width: 150px;
            }
            
            /* デスクトップのボタンを非表示 */
            .header-buttons {
                display: none;
            }
            
            /* タイトル部分はシンプルに */
            .form-card-header-content {
                display: block;
            }
            
            .form-card-title {
                margin-bottom: 0;
                font-size: 1.1rem;
            }
            
            .form-card-body {
                padding: 1rem;
            }
        }
        
        /* 極小画面（320px以下）対応 */
        @media (max-width: 320px) {
            .mobile-buttons .btn {
                font-size: 0.8rem;
                padding: 0.5rem 0.8rem;
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
                    <h1><i class="fas fa-clipboard-check me-2"></i>乗務前点呼</h1>
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
        
        <form method="POST" id="predutyForm">
            <!-- 基本情報 -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-content">
                        <h5 class="form-card-title mb-0">
                            <i class="fas fa-info-circle me-2"></i>基本情報
                        </h5>
                    </div>
                </div>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">運転者 <span class="required-mark">*</span></label>
                            <select class="form-select" name="driver_id" required>
                                <option value="">選択してください</option>
                                <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>" <?= ($existing_call && $existing_call['driver_id'] == $driver['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点呼時刻 <span class="required-mark">*</span></label>
                            <input type="time" class="form-control" name="call_time" 
                                   value="<?= $existing_call ? $existing_call['call_time'] : $current_time ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点呼者 <span class="required-mark">*</span></label>
                            <select class="form-select" name="caller_name" required>
                                <option value="">選択してください</option>
                                <?php foreach ($callers as $caller): ?>
                                <option value="<?= htmlspecialchars($caller['name']) ?>" 
                                        <?= ($existing_call && $existing_call['caller_name'] == $caller['name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($caller['name']) ?>
                                </option>
                                <?php endforeach; ?>
                                <option value="その他" <?= ($existing_call && !in_array($existing_call['caller_name'], array_column($callers, 'name')) && $existing_call['caller_name'] != '') ? 'selected' : '' ?>>その他</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="other_caller" name="other_caller" 
                                   placeholder="その他の場合は名前を入力" style="display: none;"
                                   value="<?= ($existing_call && !in_array($existing_call['caller_name'], array_column($callers, 'name')) && $existing_call['caller_name'] != '') ? htmlspecialchars($existing_call['caller_name']) : '' ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 確認事項 -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-content">
                        <h5 class="form-card-title mb-0">
                            <i class="fas fa-tasks me-2"></i>確認事項（15項目）
                        </h5>
                        <div class="header-buttons d-none d-md-flex float-end">
                            <button type="button" class="btn btn-outline-light btn-sm me-2" id="checkAllBtn">
                                <i class="fas fa-check-double me-1"></i>全てチェック
                            </button>
                            <button type="button" class="btn btn-outline-light btn-sm" id="uncheckAllBtn">
                                <i class="fas fa-times me-1"></i>全て解除
                            </button>
                        </div>
                    </div>
                </div>
                <div class="form-card-body">
                    <!-- スマホ用ボタン（ヘッダー外に配置） -->
                    <div class="mobile-buttons d-md-none">
                        <button type="button" class="btn btn-success" id="checkAllBtnMobile">
                            <i class="fas fa-check-double me-1"></i>全てチェック
                        </button>
                        <button type="button" class="btn btn-warning" id="uncheckAllBtnMobile">
                            <i class="fas fa-times me-1"></i>全て解除
                        </button>
                    </div>
                    
                    <?php
                    $check_items = [
                        'health_check' => '健康状態',
                        'clothing_check' => '服装',
                        'footwear_check' => '履物',
                        'pre_inspection_check' => '運行前点検',
                        'license_check' => '免許証',
                        'vehicle_registration_check' => '車検証',
                        'insurance_check' => '保険証',
                        'emergency_tools_check' => '応急工具',
                        'map_check' => '地図',
                        'taxi_card_check' => 'タクシーカード',
                        'emergency_signal_check' => '非常信号用具',
                        'change_money_check' => '釣銭',
                        'crew_id_check' => '乗務員証',
                        'operation_record_check' => '運行記録用用紙',
                        'receipt_check' => '領収書',
                        'stop_sign_check' => '停止表示機'
                    ];
                    ?>
                    
                    <div class="row">
                        <?php foreach ($check_items as $key => $label): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="check-item" onclick="toggleCheck('<?= $key ?>')">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>"
                                           <?= ($existing_call && $existing_call[$key]) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="<?= $key ?>">
                                        <?= htmlspecialchars($label) ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- アルコールチェック -->
            <div class="form-card">
                <div class="form-card-header">
                    <h5 class="form-card-title mb-0">
                        <i class="fas fa-wine-bottle me-2"></i>アルコールチェック
                    </h5>
                </div>
                <div class="form-card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <label class="form-label mb-0">測定値 <span class="required-mark">*</span></label>
                        </div>
                        <div class="col-auto">
                            <input type="number" class="form-control alcohol-input" name="alcohol_check_value" 
                                   step="0.001" min="0" max="1" 
                                   value="<?= $existing_call ? $existing_call['alcohol_check_value'] : '0.000' ?>" required>
                        </div>
                        <div class="col-auto">
                            <span class="text-muted">mg/L</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 備考 -->
            <div class="form-card">
                <div class="form-card-header">
                    <h5 class="form-card-title mb-0">
                        <i class="fas fa-comment me-2"></i>備考
                    </h5>
                </div>
                <div class="form-card-body">
                    <textarea class="form-control" name="remarks" rows="3" 
                              placeholder="特記事項があれば記入してください"><?= $existing_call ? htmlspecialchars($existing_call['remarks']) : '' ?></textarea>
                </div>
            </div>
            
            <!-- 保存ボタン -->
            <div class="text-center mb-4">
                <button type="submit" class="btn btn-save btn-lg">
                    <i class="fas fa-save me-2"></i>
                    <?= $existing_call ? '更新する' : '登録する' ?>
                </button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 点呼者選択の表示切替
        function toggleCallerInput() {
            const callerSelect = document.querySelector('select[name="caller_name"]');
            const otherInput = document.getElementById('other_caller');
            
            if (callerSelect.value === 'その他') {
                otherInput.style.display = 'block';
                otherInput.required = true;
            } else {
                otherInput.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }
        
        // 全てチェック
        function checkAll() {
            const checkboxes = document.querySelectorAll('.check-item .form-check-input[type="checkbox"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = true;
                const container = checkbox.closest('.check-item');
                if (container) {
                    container.classList.add('checked');
                }
            });
        }
        
        // 全て解除
        function uncheckAll() {
            const checkboxes = document.querySelectorAll('.check-item .form-check-input[type="checkbox"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = false;
                const container = checkbox.closest('.check-item');
                if (container) {
                    container.classList.remove('checked');
                }
            });
        }
        
        // チェック項目のクリック処理
        function toggleCheck(itemId) {
            const checkbox = document.getElementById(itemId);
            const container = checkbox.closest('.check-item');
            
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                container.classList.add('checked');
            } else {
                container.classList.remove('checked');
            }
        }
        
        // 初期化処理
        document.addEventListener('DOMContentLoaded', function() {
            // 既存チェック項目のスタイル適用
            const checkboxes = document.querySelectorAll('.form-check-input[type="checkbox"]');
            checkboxes.forEach(function(checkbox) {
                const container = checkbox.closest('.check-item');
                if (checkbox.checked && container) {
                    container.classList.add('checked');
                }
            });
            
            // 点呼者選択の初期設定
            const callerSelect = document.querySelector('select[name="caller_name"]');
            if (callerSelect) {
                callerSelect.addEventListener('change', toggleCallerInput);
                toggleCallerInput(); // 初期表示
            }
            
            // 一括チェックボタンのイベント設定（デスクトップ・モバイル両対応）
            const checkAllBtn = document.getElementById('checkAllBtn');
            const uncheckAllBtn = document.getElementById('uncheckAllBtn');
            const checkAllBtnMobile = document.getElementById('checkAllBtnMobile');
            const uncheckAllBtnMobile = document.getElementById('uncheckAllBtnMobile');
            
            if (checkAllBtn) {
                checkAllBtn.addEventListener('click', checkAll);
            }
            
            if (uncheckAllBtn) {
                uncheckAllBtn.addEventListener('click', uncheckAll);
            }
            
            if (checkAllBtnMobile) {
                checkAllBtnMobile.addEventListener('click', checkAll);
            }
            
            if (uncheckAllBtnMobile) {
                uncheckAllBtnMobile.addEventListener('click', uncheckAll);
            }
        });
        
        // フォーム送信前の確認
        document.getElementById('predutyForm').addEventListener('submit', function(e) {
            const driverId = document.querySelector('select[name="driver_id"]').value;
            
            if (!driverId) {
                e.preventDefault();
                alert('運転者を選択してください。');
                return;
            }
            
            // 点呼者名の確認
            const callerSelect = document.querySelector('select[name="caller_name"]');
            const otherInput = document.getElementById('other_caller');
            
            if (callerSelect.value === 'その他' && !otherInput.value.trim()) {
                e.preventDefault();
                alert('点呼者名を入力してください。');
                return;
            }
            
            // 必須チェック項目の確認
            const requiredChecks = ['health_check', 'pre_inspection_check', 'license_check'];
            let allChecked = true;
            
            requiredChecks.forEach(function(checkId) {
                if (!document.getElementById(checkId).checked) {
                    allChecked = false;
                }
            });
            
            if (!allChecked) {
                if (!confirm('必須項目が未チェックです。このまま保存しますか？')) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html>