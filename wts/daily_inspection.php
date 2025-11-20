<?php
session_start();
require_once 'config/database.php';
require_once 'includes/unified-header.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// モード判定を追加
$mode = $_GET['mode'] ?? 'normal';

if ($mode === 'historical') {
    include 'includes/historical_daily_inspection.php';
    exit;
}

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '未設定';
$user_role = $_SESSION['user_role'] ?? 'User';
$today = date('Y-m-d');

$success_message = '';
$error_message = '';
$is_edit_mode = false;

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

// 特定日の点検記録があるかチェック（日付指定対応）
$target_date = $_GET['date'] ?? $today;
$existing_inspection = null;
if (($_GET['inspector_id'] ?? null) && ($_GET['vehicle_id'] ?? null)) {
    $stmt = $pdo->prepare("SELECT * FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
    $stmt->execute([$_GET['inspector_id'], $_GET['vehicle_id'], $target_date]);
    $existing_inspection = $stmt->fetch();
    $is_edit_mode = (bool)$existing_inspection;
}

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM daily_inspections WHERE driver_id = ? AND vehicle_id = ? AND inspection_date = ?");
        $stmt->execute([$_POST['inspector_id'], $_POST['vehicle_id'], $_POST['inspection_date']]);
        $success_message = '日常点検記録を削除しました。';
        $existing_inspection = null;
        $is_edit_mode = false;
    } catch (Exception $e) {
        $error_message = '削除中にエラーが発生しました: ' . $e->getMessage();
        error_log("Daily inspection delete error: " . $e->getMessage());
    }
}

// フォーム送信処理（登録・更新）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
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
            $is_edit_mode = true;

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

// ページ設定を取得
$page_config = getPageConfiguration('daily_inspection');
?>
<!DOCTYPE html>
<?= renderCompleteHTMLHead($page_config['title'], [
    'description' => $page_config['description'],
    'additional_css' => [],
    'additional_js' => []
]) ?>
<body>
    <?= renderSystemHeader($user_name, $user_role, 'daily_inspection') ?>
    <?= renderPageHeader($page_config['icon'], $page_config['title'], $page_config['subtitle'], $page_config['category']) ?>
    
    <div class="container mt-4">
        <!-- モード切替ボタン -->
        <div class="mode-switch mb-4">
            <div class="btn-group" role="group">
                <a href="daily_inspection.php" class="btn btn-primary">
                    <i class="fas fa-edit"></i> 通常入力
                </a>
                <a href="daily_inspection.php?mode=historical" class="btn btn-outline-success">
                    <i class="fas fa-history"></i> 過去データ入力
                </a>
            </div>
        </div>

        <!-- 次のステップへの案内バナー -->
        <?php if ($success_message && $existing_inspection): ?>
        <div class="alert alert-success border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle text-success fs-3 me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">日常点検完了</h5>
                    <p class="mb-0">次は乗務前点呼を行ってください</p>
                </div>
                <a href="pre_duty_call.php?driver_id=<?= $existing_inspection['driver_id'] ?>"
                   class="btn btn-success btn-lg">
                    <i class="fas fa-clipboard-check me-2"></i>乗務前点呼へ進む
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- アラート表示 -->
        <?php if ($success_message): ?>
            <?= renderAlert('success', '完了', $success_message) ?>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <?= renderAlert('danger', 'エラー', $error_message) ?>
        <?php endif; ?>
        
        <form method="POST" id="inspectionForm">
            <?php if ($is_edit_mode): ?>
                <input type="hidden" name="inspector_id" value="<?= $existing_inspection['driver_id'] ?>">
                <input type="hidden" name="vehicle_id" value="<?= $existing_inspection['vehicle_id'] ?>">
                <input type="hidden" name="inspection_date" value="<?= $existing_inspection['inspection_date'] ?>">
            <?php endif; ?>

            <!-- 基本情報 -->
            <?php
            $actions = [];
            if (!$is_edit_mode) {
                $actions = [
                    [
                        'icon' => 'check-circle',
                        'text' => '全て可',
                        'url' => 'javascript:setAllOk()',
                        'class' => 'btn-success btn-sm'
                    ],
                    [
                        'icon' => 'times-circle',
                        'text' => '全て否',
                        'url' => 'javascript:setAllNg()',
                        'class' => 'btn-danger btn-sm'
                    ]
                ];
            }
            echo renderSectionHeader('info-circle', '基本情報', '必須項目の入力', $actions);
            ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検日 <span class="text-danger">*</span></label>
                            <input type="<?= $is_edit_mode ? 'text' : 'date' ?>" class="form-control" name="<?= $is_edit_mode ? 'inspection_date_display' : 'inspection_date' ?>"
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_date'] : $target_date ?>"
                                   <?= $is_edit_mode ? 'readonly' : 'required' ?>>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検時間</label>
                            <input type="time" class="form-control" name="inspection_time"
                                   value="<?= $existing_inspection ? $existing_inspection['inspection_time'] : date('H:i') ?>"
                                   <?= $is_edit_mode ? 'readonly' : '' ?>>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">点検者（運転手） <span class="text-danger">*</span></label>
                            <?php if ($is_edit_mode): ?>
                                <?php
                                $inspector_name = '';
                                foreach ($drivers as $driver) {
                                    if ($driver['id'] == $existing_inspection['driver_id']) {
                                        $inspector_name = htmlspecialchars($driver['name']);
                                        break;
                                    }
                                }
                                ?>
                                <input type="text" class="form-control" value="<?= $inspector_name ?>" readonly>
                            <?php else: ?>
                                <select class="form-select" name="inspector_id" required>
                                    <option value="">運転者を選択</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?= $driver['id'] ?>"
                                            <?= ($driver['id'] == $user_id) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($driver['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    日常点検は運転手が実施します
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">車両 <span class="text-danger">*</span></label>
                            <?php if ($is_edit_mode): ?>
                                <?php
                                $vehicle_name = '';
                                foreach ($vehicles as $vehicle) {
                                    if ($vehicle['id'] == $existing_inspection['vehicle_id']) {
                                        $vehicle_name = htmlspecialchars($vehicle['vehicle_number']);
                                        if ($vehicle['model']) {
                                            $vehicle_name .= ' (' . htmlspecialchars($vehicle['model']) . ')';
                                        }
                                        break;
                                    }
                                }
                                ?>
                                <input type="text" class="form-control" value="<?= $vehicle_name ?>" readonly>
                            <?php else: ?>
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
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">走行距離</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="mileage" id="mileage"
                                       value="<?= $existing_inspection ? $existing_inspection['mileage'] : '' ?>"
                                       placeholder="現在の走行距離"
                                       <?= $is_edit_mode ? 'readonly' : '' ?>>
                                <span class="input-group-text">km</span>
                            </div>
                            <?php if (!$is_edit_mode): ?>
                            <div class="alert alert-info mt-2" id="mileageInfo" style="display: none;">
                                <i class="fas fa-info-circle me-1"></i>
                                <span id="mileageText">前回記録: </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 運転室内点検 -->
            <?= renderSectionHeader('car', '運転室内点検', '必須項目含む6項目') ?>
            <div class="card mb-4">
                <div class="card-body">
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
                    <div class="inspection-item border rounded p-3 mb-3" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                    <span class="badge bg-danger ms-2">必須</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">省略可</span>
                                    <div class="text-muted small">※走行距離・運行状態により省略可</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- エンジンルーム内点検 -->
            <?= renderSectionHeader('cog', 'エンジンルーム内点検', '必須項目含む6項目') ?>
            <div class="card mb-4">
                <div class="card-body">
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
                    <div class="inspection-item border rounded p-3 mb-3" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                    <span class="badge bg-danger ms-2">必須</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">省略可</span>
                                    <div class="text-muted small">※走行距離・運行状態により省略可</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 灯火類とタイヤ点検 -->
            <?= renderSectionHeader('lightbulb', '灯火類とタイヤ点検', '必須項目含む5項目') ?>
            <div class="card mb-4">
                <div class="card-body">
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
                    <div class="inspection-item border rounded p-3 mb-3" data-item="<?= $key ?>">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><?= htmlspecialchars($item['label']) ?></strong>
                                <?php if ($item['required']): ?>
                                    <span class="badge bg-danger ms-2">必須</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">省略可</span>
                                    <div class="text-muted small">※走行距離・運行状態により省略可</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="可" id="<?= $key ?>_ok"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '可') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-success" for="<?= $key ?>_ok">可</label>

                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="否" id="<?= $key ?>_ng"
                                           <?= ($existing_inspection && $existing_inspection[$key] == '否') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-danger" for="<?= $key ?>_ng">否</label>

                                    <?php if (!$item['required']): ?>
                                    <input type="radio" class="btn-check" name="<?= $key ?>" value="省略" id="<?= $key ?>_skip"
                                           <?= (!$existing_inspection || $existing_inspection[$key] == '省略') ? 'checked' : '' ?>
                                           <?= $is_edit_mode ? 'disabled' : '' ?>>
                                    <label class="btn btn-outline-warning" for="<?= $key ?>_skip">省略</label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 不良個所・備考 -->
            <?= renderSectionHeader('exclamation-triangle', '不良個所及び処置・備考', '詳細記録') ?>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">不良個所及び処置</label>
                        <textarea class="form-control" name="defect_details" rows="3"
                                  placeholder="点検で「否」となった項目の詳細と処置内容を記入"
                                  <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_inspection ? htmlspecialchars($existing_inspection['defect_details']) : '' ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea class="form-control" name="remarks" rows="2"
                                  placeholder="その他特記事項があれば記入"
                                  <?= $is_edit_mode ? 'readonly' : '' ?>><?= $existing_inspection ? htmlspecialchars($existing_inspection['remarks']) : '' ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 操作ボタン -->
            <div class="text-center mb-4" id="actionButtons">
                <?php if ($is_edit_mode): ?>
                    <button type="button" class="btn btn-warning btn-lg me-2" onclick="enableEditMode()">
                        <i class="fas fa-edit me-2"></i>修正
                    </button>
                    <button type="button" class="btn btn-danger btn-lg" onclick="confirmDelete()">
                        <i class="fas fa-trash me-2"></i>削除
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save me-2"></i>
                        <?= $existing_inspection ? '更新する' : '登録する' ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <!-- 削除フォーム -->
        <?php if ($is_edit_mode): ?>
        <form method="POST" id="deleteForm" style="display: none;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="inspector_id" value="<?= $existing_inspection['driver_id'] ?>">
            <input type="hidden" name="vehicle_id" value="<?= $existing_inspection['vehicle_id'] ?>">
            <input type="hidden" name="inspection_date" value="<?= $existing_inspection['inspection_date'] ?>">
        </form>
        <?php endif; ?>
        
        <!-- ナビゲーションリンク -->
        <div class="card bg-light">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-4 mb-2">
                        <h6 class="text-muted mb-2">次の作業</h6>
                        <a href="pre_duty_call.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-clipboard-check me-1"></i>乗務前点呼
                        </a>
                    </div>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-muted mb-2">他の点検</h6>
                        <a href="periodic_inspection.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-wrench me-1"></i>定期点検
                        </a>
                    </div>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-muted mb-2">記録管理</h6>
                        <a href="daily_inspection_history.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-history me-1"></i>履歴・編集
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?= renderCompleteHTMLFooter() ?>
    
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
                item.classList.remove('border-success', 'border-danger', 'border-warning', 'bg-success', 'bg-danger', 'bg-warning');
                
                if (value === '可') {
                    item.classList.add('border-success', 'bg-success', 'bg-opacity-10');
                } else if (value === '否') {
                    item.classList.add('border-danger', 'bg-danger', 'bg-opacity-10');
                } else if (value === '省略') {
                    item.classList.add('border-warning', 'bg-warning', 'bg-opacity-10');
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

        // 編集モード有効化
        function enableEditMode() {
            // readonly/disabled属性を削除
            document.querySelectorAll('input[readonly], textarea[readonly]').forEach(element => {
                element.removeAttribute('readonly');
            });

            document.querySelectorAll('input[disabled]').forEach(element => {
                element.removeAttribute('disabled');
            });

            // 基本情報の選択フィールドを復元
            const inspectorId = document.querySelector('input[name="inspector_id"]').value;
            const vehicleId = document.querySelector('input[name="vehicle_id"]').value;
            const inspectionDate = document.querySelector('input[name="inspection_date"]').value;

            // 点検日フィールドを復元
            const dateInput = document.querySelector('input[name="inspection_date_display"]');
            if (dateInput) {
                dateInput.name = 'inspection_date';
                dateInput.type = 'date';
                dateInput.removeAttribute('readonly');
            }

            // ボタンを変更
            const actionButtons = document.getElementById('actionButtons');
            actionButtons.innerHTML = `
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-save me-2"></i>変更を保存
                </button>
                <button type="button" class="btn btn-secondary btn-lg ms-2" onclick="location.reload()">
                    <i class="fas fa-times me-2"></i>キャンセル
                </button>
            `;
        }

        // 削除確認
        function confirmDelete() {
            if (confirm('本当に削除しますか？この操作は取り消せません。')) {
                document.getElementById('deleteForm').submit();
            }
        }
    </script>
