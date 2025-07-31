<?php
// includes/historical_daily_inspection.php
// 日常点検 - 過去データ入力モード

require_once 'includes/historical_common.php';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate_data') {
        // データ生成処理
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $vehicle_id = $_POST['vehicle_id'] ?? '';
        $inspector_id = $_POST['inspector_id'] ?? '';
        $input_mode = $_POST['input_mode'] ?? 'bulk';
        
        $validation_errors = [];
        
        // 入力値検証
        if (empty($start_date)) $validation_errors[] = '開始日を入力してください';
        if (empty($end_date)) $validation_errors[] = '終了日を入力してください';
        if (empty($vehicle_id)) $validation_errors[] = '車両を選択してください';
        if (empty($inspector_id)) $validation_errors[] = '点検者を選択してください';
        
        if (empty($validation_errors)) {
            // 営業日生成
            $business_dates = generateBusinessDates($start_date, $end_date);
            
            // 既存データ確認
            $existing_dates = checkExistingData($pdo, 'daily_inspections', 'inspection_date', 
                $business_dates, ['vehicle_id' => $vehicle_id]);
            
            $date_categories = categorizeDates($business_dates, $existing_dates);
            
            // セッションに保存
            $_SESSION['historical_data'] = [
                'type' => 'daily_inspection',
                'dates' => $date_categories,
                'vehicle_id' => $vehicle_id,
                'inspector_id' => $inspector_id,
                'input_mode' => $input_mode
            ];
        }
    } elseif ($action === 'save_data') {
        // データ保存処理
        $historical_data = $_SESSION['historical_data'] ?? null;
        $inspection_data = $_POST['inspection_data'] ?? [];
        
        if ($historical_data && !empty($inspection_data)) {
            try {
                $pdo->beginTransaction();
                
                $success_count = 0;
                $errors = [];
                
                foreach ($inspection_data as $date => $data) {
                    if (!isset($data['skip']) || $data['skip'] !== '1') {
                        // データ準備
                        $insert_data = generateDailyInspectionDefaults([
                            'vehicle_id' => $historical_data['vehicle_id'],
                            'inspector_id' => $historical_data['inspector_id'],
                            'inspection_date' => $date
                        ]);
                        
                        // カスタマイズされたデータを上書き
                        foreach ($data as $key => $value) {
                            if ($key !== 'skip') {
                                $insert_data[$key] = $value;
                            }
                        }
                        
                        // データ検証
                        $validation = validateHistoricalData($insert_data, 'daily_inspection');
                        if (!empty($validation['errors'])) {
                            $errors[] = $date . ': ' . implode(', ', $validation['errors']);
                            continue;
                        }
                        
                        // データベース挿入
                        $columns = array_keys($insert_data);
                        $placeholders = ':' . implode(', :', $columns);
                        
                        $sql = "INSERT INTO daily_inspections (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
                        $stmt = $pdo->prepare($sql);
                        
                        if ($stmt->execute($insert_data)) {
                            $success_count++;
                        } else {
                            $errors[] = $date . ': データベース保存エラー';
                        }
                    }
                }
                
                if (empty($errors)) {
                    $pdo->commit();
                    $success_message = "{$success_count}件のデータを正常に保存しました。";
                    unset($_SESSION['historical_data']); // セッションクリア
                } else {
                    $pdo->rollback();
                    $error_message = "保存中にエラーが発生しました: " . implode('<br>', $errors);
                }
                
            } catch (Exception $e) {
                $pdo->rollback();
                $error_message = "データベースエラー: " . $e->getMessage();
            }
        }
    }
}

// 車両とユーザーのデータ取得
$vehicles = getVehicles($pdo);
$inspectors = getUsersByRole($pdo, 'driver'); // 運転者が点検も行うと仮定

$historical_data = $_SESSION['historical_data'] ?? null;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>日常点検 - 過去データ入力</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .inspection-date-card {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }
        .date-header {
            background-color: #f8f9fa;
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        .date-existing {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .date-missing {
            background-color: #f8d7da;
            color: #721c24;
        }
        .inspection-items {
            padding: 1rem;
        }
        .quick-set-buttons {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-3">
        <!-- モード表示 -->
        <div class="alert alert-info">
            <i class="fas fa-history"></i> <strong>過去データ入力モード</strong> - 日常点検
        </div>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?= $error_message ?></div>
        <?php endif; ?>

        <?php if (!empty($validation_errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($validation_errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$historical_data): ?>
            <!-- Step 1: 期間・対象選択 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> 期間・対象選択</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="generate_data">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">開始日</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" 
                                           value="<?= $_POST['start_date'] ?? date('Y-m-01') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">終了日</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" 
                                           value="<?= $_POST['end_date'] ?? date('Y-m-t') ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="vehicle_id" class="form-label">対象車両</label>
                                    <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                        <option value="">車両を選択してください</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?= $vehicle['id'] ?>" 
                                                    <?= ($_POST['vehicle_id'] ?? '') == $vehicle['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($vehicle['vehicle_number']) ?> 
                                                (<?= htmlspecialchars($vehicle['model']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="inspector_id" class="form-label">点検者</label>
                                    <select class="form-select" id="inspector_id" name="inspector_id" required>
                                        <option value="">点検者を選択してください</option>
                                        <?php foreach ($inspectors as $inspector): ?>
                                            <option value="<?= $inspector['id'] ?>" 
                                                    <?= ($_POST['inspector_id'] ?? '') == $inspector['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($inspector['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">入力方式</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="input_mode" id="bulk_mode" value="bulk" 
                                       <?= ($_POST['input_mode'] ?? 'bulk') === 'bulk' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bulk_mode">
                                    <strong>一括設定モード</strong> - 全て「可」で生成し、問題のある項目のみ個別修正
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="input_mode" id="individual_mode" value="individual"
                                       <?= ($_POST['input_mode'] ?? '') === 'individual' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="individual_mode">
                                    <strong>個別入力モード</strong> - 各日付・各項目を個別に設定
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-cog"></i> データ生成
                        </button>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Step 2: データ入力・編集 -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-edit"></i> データ入力・編集</h5>
                    <small class="text-muted">
                        期間: <?= $historical_data['dates']['missing'][0] ?? 'なし' ?> ～ 
                        <?= end($historical_data['dates']['missing']) ?: 'なし' ?>
                    </small>
                </div>
                <div class="card-body">
                    <?php if (!empty($historical_data['dates']['existing'])): ?>
                        <div class="alert alert-warning">
                            <strong>既存データ:</strong> <?= count($historical_data['dates']['existing']) ?>件
                            (スキップされます)
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($historical_data['dates']['missing'])): ?>
                        <div class="alert alert-info">
                            <strong>入力対象:</strong> <?= count($historical_data['dates']['missing']) ?>件
                        </div>

                        <form method="POST" id="inspection-form">
                            <input type="hidden" name="action" value="save_data">

                            <!-- 一括操作ボタン -->
                            <div class="quick-set-buttons">
                                <button type="button" class="btn btn-success btn-sm" onclick="setAllItems('可')">
                                    <i class="fas fa-check"></i> 全て「可」
                                </button>
                                <button type="button" class="btn btn-warning btn-sm" onclick="setAllItems('否')">
                                    <i class="fas fa-times"></i> 全て「否」
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllSkip()">
                                    <i class="fas fa-eye-slash"></i> 全てスキップ切替
                                </button>
                            </div>

                            <?php foreach ($historical_data['dates']['missing'] as $date): ?>
                                <div class="inspection-date-card">
                                    <div class="date-header date-missing">
                                        <div class="form-check form-switch d-flex justify-content-between">
                                            <div>
                                                📅 <?= formatDateJapanese($date) ?> (<?= $date ?>)
                                            </div>
                                            <div>
                                                <input class="form-check-input" type="checkbox" 
                                                       name="inspection_data[<?= $date ?>][skip]" value="1"
                                                       id="skip_<?= str_replace('-', '_', $date) ?>">
                                                <label class="form-check-label" for="skip_<?= str_replace('-', '_', $date) ?>">
                                                    スキップ
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="inspection-items" id="items_<?= str_replace('-', '_', $date) ?>">
                                        <!-- 運転室内点検 -->
                                        <h6 class="text-primary">運転室内点検</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label class="form-label">フットブレーキの踏み代・効き</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][cabin_brake_pedal]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">パーキングブレーキの引き代</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][cabin_parking_brake]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※エンジンのかかり具合・異音</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][cabin_engine_condition]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row mt-2">
                                            <div class="col-md-4">
                                                <label class="form-label">※エンジンの低速・加速</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][cabin_engine_performance]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※ワイパーのふき取り能力</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][cabin_wiper_performance]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※ウインドウォッシャー液の噴射状態</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][cabin_washer_fluid_spray]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- エンジンルーム内点検 -->
                                        <h6 class="text-success mt-4">エンジンルーム内点検</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label class="form-label">ブレーキ液量</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][engine_brake_fluid]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※冷却水量</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][engine_coolant_level]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※エンジンオイル量</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][engine_oil_level]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-4">
                                                <label class="form-label">※バッテリー液量</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][engine_battery_fluid]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※ウインドウォッシャー液量</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][engine_washer_fluid_level]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※ファンベルトの張り・損傷</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][engine_fan_belt]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- 灯火類・タイヤ点検 -->
                                        <h6 class="text-warning mt-4">灯火類・タイヤ点検</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label class="form-label">灯火類の点灯・点滅</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][light_headlights]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">レンズの損傷・汚れ</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][light_lens_condition]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">タイヤの空気圧</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][tire_air_pressure]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-4">
                                                <label class="form-label">タイヤの亀裂・損傷</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][tire_condition]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">※タイヤ溝の深さ</label>
                                                <select class="form-select form-select-sm inspection-item" 
                                                        name="inspection_data[<?= $date ?>][tire_tread_depth]">
                                                    <option value="可" selected>可</option>
                                                    <option value="否">否</option>
                                                    <option value="省略">省略</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- 不良個所詳細 -->
                                        <div class="mt-3">
                                            <label class="form-label">不良個所及び処置</label>
                                            <textarea class="form-control form-control-sm" rows="2" 
                                                      name="inspection_data[<?= $date ?>][defect_details]" 
                                                      placeholder="問題があった場合は詳細を記入"></textarea>
                                        </div>
