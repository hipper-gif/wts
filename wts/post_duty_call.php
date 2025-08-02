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
    // 運転者取得（フラグベース）
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 点呼者取得（管理者・マネージャーのみ）
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_caller = 1 AND is_active = 1 ORDER BY name");
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
    $stmt = $pdo->prepare("SELECT * FROM post_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$driver_id, $today]);
    $existing_call = $stmt->fetch();
}

// 対応する乗務前点呼の取得
$pre_duty_call = null;
if ($existing_call) {
    $stmt = $pdo->prepare("SELECT * FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
    $stmt->execute([$existing_call['driver_id'], $today]);
    $pre_duty_call = $stmt->fetch();
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'];
    $call_time = $_POST['call_time'];
    
    // 今日の出庫記録から車両IDを取得
    $stmt = $pdo->prepare("SELECT vehicle_id FROM departure_records WHERE driver_id = ? AND departure_date = ? LIMIT 1");
    $stmt->execute([$driver_id, $today]);
    $departure_record = $stmt->fetch();
    
    if (!$departure_record) {
        $error_message = '本日の出庫記録が見つかりません。先に出庫処理を行ってください。';
    } else {
        $vehicle_id = $departure_record['vehicle_id'];
        
        // 点呼者名の処理
        $caller_name = $_POST['caller_name'];
        if ($caller_name === 'その他') {
            $caller_name = $_POST['other_caller'];
        }
        
        $alcohol_check_value = $_POST['alcohol_check_value'];
        
        // 確認事項のチェック（7項目）
        $check_items = [
            'health_condition_check',     // 健康状態
            'fatigue_check',             // 疲労・睡眠不足
            'alcohol_drug_check',        // 酒気・薬物
            'vehicle_condition_check',   // 車両の状態
            'accident_violation_check',  // 事故・違反の有無
            'equipment_return_check',    // 業務用品の返却
            'report_completion_check'    // 業務報告の完了
        ];
        
        try {
            // 対応する乗務前点呼の確認
            $stmt = $pdo->prepare("SELECT id FROM pre_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $pre_duty_record = $stmt->fetch();
            
            if (!$pre_duty_record) {
                throw new Exception('対応する乗務前点呼記録が見つかりません。先に乗務前点呼を実施してください。');
            }
            
            $pre_duty_call_id = $pre_duty_record['id'];
            
            // 既存レコードの確認
            $stmt = $pdo->prepare("SELECT id FROM post_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // 更新
                $sql = "UPDATE post_duty_calls SET 
                    call_time = ?, caller_name = ?, alcohol_check_value = ?, alcohol_check_time = ?, 
                    pre_duty_call_id = ?,";
                
                foreach ($check_items as $item) {
                    $sql .= " $item = ?,";
                }
                
                $sql .= " remarks = ?, is_completed = TRUE, updated_at = NOW() 
                    WHERE driver_id = ? AND call_date = ?";
                
                $stmt = $pdo->prepare($sql);
                $params = [$call_time, $caller_name, $alcohol_check_value, $call_time, $pre_duty_call_id];
                
                foreach ($check_items as $item) {
                    $params[] = isset($_POST[$item]) ? 1 : 0;
                }
                
                $params[] = $_POST['remarks'] ?? '';
                $params[] = $driver_id;
                $params[] = $today;
                
                $stmt->execute($params);
                $success_message = '乗務後点呼記録を更新しました。';
            } else {
                // 新規挿入
                $sql = "INSERT INTO post_duty_calls (
                    driver_id, vehicle_id, call_date, call_time, caller_name, 
                    alcohol_check_value, alcohol_check_time, pre_duty_call_id,";
                
                foreach ($check_items as $item) {
                    $sql .= " $item,";
                }
                
                $sql .= " remarks, is_completed) VALUES (?, ?, ?, ?, ?, ?, ?, ?,";
                
                $sql .= str_repeat('?,', count($check_items));
                $sql .= " ?, TRUE)";
                
                $stmt = $pdo->prepare($sql);
                $params = [$driver_id, $vehicle_id, $today, $call_time, $caller_name, $alcohol_check_value, $call_time, $pre_duty_call_id];
                
                foreach ($check_items as $item) {
                    $params[] = isset($_POST[$item]) ? 1 : 0;
                }
                
                $params[] = $_POST['remarks'] ?? '';
                
                $stmt->execute($params);
                $success_message = '乗務後点呼記録を登録しました。';
            }
            
            // 記録を再取得
            $stmt = $pdo->prepare("SELECT * FROM post_duty_calls WHERE driver_id = ? AND call_date = ? LIMIT 1");
            $stmt->execute([$driver_id, $today]);
            $existing_call = $stmt->fetch();
            
        } catch (Exception $e) {
            $error_message = '記録の保存中にエラーが発生しました: ' . $e->getMessage();
            error_log("Post duty call error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>乗務後点呼 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
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
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
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
            background: #ffeaa7;
            border-color: #fdcb6e;
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
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
            color: white;
        }
        
        .required-mark {
            color: #dc3545;
        }
        
        .pre-duty-info {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .badge-completed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
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
                    <h1><i class="fas fa-clipboard-check me-2"></i>乗務後点呼</h1>
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
        
        <!-- 乗務前点呼情報表示 -->
        <?php if ($pre_duty_call): ?>
        <div class="pre-duty-info">
            <h6><i class="fas fa-info-circle me-2"></i>対応する乗務前点呼情報</h6>
            <div class="row">
                <div class="col-md-6">
                    <small><strong>点呼時刻:</strong> <?= substr($pre_duty_call['call_time'], 0, 5) ?></small>
                </div>
                <div class="col-md-6">
                    <small><strong>点呼者:</strong> <?= htmlspecialchars($pre_duty_call['caller_name']) ?></small>
                </div>
                <div class="col-md-6">
                    <small><strong>アルコールチェック:</strong> <?= $pre_duty_call['alcohol_check_value'] ?> mg/L</small>
                </div>
                <div class="col-md-6">
                    <small><span class="badge badge-completed">乗務前点呼完了</span></small>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="postDutyForm">
            <!-- 基本情報 -->
            <div class="form-card">
                <h5 class="form-card-header">
                    <i class="fas fa-info-circle me-2"></i>基本情報
                </h5>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">運転者 <span class="required-mark">*</span></label>
                            <select class="form-select" name="driver_id" required>
<option value="">運転者を選択</option>
<?php foreach ($drivers as $driver): ?>
    <option value="<?php echo $driver['id']; ?>" 
        <?php echo ($driver['id'] == $user_id) ? 'selected' : ''; ?>>
        <?php echo htmlspecialchars($driver['name']); ?>
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
                            <i class="fas fa-tasks me-2"></i>確認事項（7項目）
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
                    $check_items_labels = [
                        'health_condition_check' => '健康状態に異常はないか',
                        'fatigue_check' => '疲労・睡眠不足はないか',
                        'alcohol_drug_check' => '酒気・薬物の影響はないか',
                        'vehicle_condition_check' => '車両に異常・損傷はないか',
                        'accident_violation_check' => '事故・違反の発生はないか',
                        'equipment_return_check' => '業務用品は適切に返却されているか',
                        'report_completion_check' => '業務報告は完了しているか'
                    ];
                    ?>
                    
                    <div class="row">
                        <?php foreach ($check_items_labels as $key => $label): ?>
                        <div class="col-md-6">
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
                <h5 class="form-card-header">
                    <i class="fas fa-wine-bottle me-2"></i>アルコールチェック
                </h5>
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
                <h5 class="form-card-header">
                    <i class="fas fa-comment me-2"></i>備考
                </h5>
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
        document.getElementById('postDutyForm').addEventListener('submit', function(e) {
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
        });
    </script>
</body>
</html>
