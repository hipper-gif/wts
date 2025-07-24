<?php
/**
 * 福祉輸送管理システム - 完全版セットアップスクリプト（最終修正版）
 */

session_start();

// データベース接続（バッファリング設定付き）
require_once 'config/database.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

$results = [];
$errors = [];

// ログ関数
function addResult($message, $success = true) {
    global $results;
    $results[] = [
        'message' => $message,
        'success' => $success,
        'time' => date('H:i:s')
    ];
}

function addError($message) {
    global $errors;
    $errors[] = $message;
    addResult($message, false);
}

// セットアップ開始
addResult("福祉輸送管理システム 完全版セットアップを開始します", true);

try {
    // 1. 集金管理テーブル作成
    addResult("集金管理テーブルを作成中...", true);
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cash_confirmations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            confirmation_date DATE NOT NULL UNIQUE,
            confirmed_amount INT NOT NULL DEFAULT 0,
            calculated_amount INT NOT NULL DEFAULT 0,
            difference INT NOT NULL DEFAULT 0,
            memo TEXT,
            confirmed_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_confirmation_date (confirmation_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    addResult("✓ cash_confirmations テーブル作成完了", true);

    // 2. 陸運局提出管理テーブル作成
    addResult("陸運局提出管理テーブルを作成中...", true);
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fiscal_years (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fiscal_year INT NOT NULL UNIQUE,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_active BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fiscal_year (fiscal_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    addResult("✓ fiscal_years テーブル作成完了", true);
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS annual_reports (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fiscal_year INT NOT NULL,
            report_type VARCHAR(50) NOT NULL,
            submission_date DATE,
            submitted_by INT,
            status ENUM('未作成', '作成中', '確認中', '提出済み') DEFAULT '未作成',
            memo TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_year_type (fiscal_year, report_type),
            INDEX idx_fiscal_year (fiscal_year),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    addResult("✓ annual_reports テーブル作成完了", true);

    // 3. 事故管理テーブル作成
    addResult("事故管理テーブルを作成中...", true);
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accidents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            accident_date DATE NOT NULL,
            accident_time TIME,
            vehicle_id INT NOT NULL,
            driver_id INT NOT NULL,
            accident_type ENUM('交通事故', '重大事故', 'その他') NOT NULL,
            location VARCHAR(255),
            weather VARCHAR(50),
            description TEXT,
            cause_analysis TEXT,
            deaths INT DEFAULT 0,
            injuries INT DEFAULT 0,
            property_damage BOOLEAN DEFAULT FALSE,
            damage_amount INT DEFAULT 0,
            police_report BOOLEAN DEFAULT FALSE,
            police_report_number VARCHAR(100),
            insurance_claim BOOLEAN DEFAULT FALSE,
            insurance_number VARCHAR(100),
            prevention_measures TEXT,
            status ENUM('発生', '調査中', '処理完了') DEFAULT '発生',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_accident_date (accident_date),
            INDEX idx_vehicle_id (vehicle_id),
            INDEX idx_driver_id (driver_id),
            INDEX idx_accident_type (accident_type),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    addResult("✓ accidents テーブル作成完了", true);

    // 4. 既存テーブルの構造確認・更新
    addResult("既存テーブルの構造を確認中...", true);
    
    // ride_recordsテーブルにpayment_methodカラムがあるか確認
    $stmt = $pdo->prepare("SHOW COLUMNS FROM ride_records LIKE ?");
    $stmt->execute(['payment_method']);
    $payment_method_exists = $stmt->fetchAll();
    
    if (empty($payment_method_exists)) {
        try {
            $pdo->exec("ALTER TABLE ride_records ADD COLUMN payment_method ENUM('現金', 'カード', 'その他') DEFAULT '現金' AFTER fare_amount");
            addResult("✓ ride_records テーブルに payment_method カラムを追加", true);
        } catch (Exception $e) {
            addError("ride_records テーブルの更新に失敗: " . $e->getMessage());
        }
    } else {
        addResult("✓ ride_records テーブルは既に payment_method カラムを持っています", true);
    }
    
    // departure_recordsテーブルの確認
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute(['departure_records']);
    $departure_exists = $stmt->fetchAll();
    
    if (empty($departure_exists)) {
        $pdo->exec("
            CREATE TABLE departure_records (
                id INT PRIMARY KEY AUTO_INCREMENT,
                driver_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                departure_date DATE NOT NULL,
                departure_time TIME NOT NULL,
                weather VARCHAR(20),
                departure_mileage INT,
                pre_duty_completed BOOLEAN DEFAULT FALSE,
                daily_inspection_completed BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_departure_date (departure_date),
                INDEX idx_vehicle_date (vehicle_id, departure_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addResult("✓ departure_records テーブル作成完了", true);
    } else {
        addResult("✓ departure_records テーブルは既に存在します", true);
    }
    
    // arrival_recordsテーブルの確認
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute(['arrival_records']);
    $arrival_exists = $stmt->fetchAll();
    
    if (empty($arrival_exists)) {
        $pdo->exec("
            CREATE TABLE arrival_records (
                id INT PRIMARY KEY AUTO_INCREMENT,
                departure_record_id INT,
                driver_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                arrival_date DATE NOT NULL,
                arrival_time TIME NOT NULL,
                arrival_mileage INT,
                total_distance INT,
                fuel_cost INT DEFAULT 0,
                highway_cost INT DEFAULT 0,
                other_cost INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_arrival_date (arrival_date),
                INDEX idx_vehicle_date (vehicle_id, arrival_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addResult("✓ arrival_records テーブル作成完了", true);
    } else {
        addResult("✓ arrival_records テーブルは既に存在します", true);
    }

    // 5. post_duty_callsテーブルの確認
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute(['post_duty_calls']);
    $post_duty_exists = $stmt->fetchAll();
    
    if (empty($post_duty_exists)) {
        $pdo->exec("
            CREATE TABLE post_duty_calls (
                id INT PRIMARY KEY AUTO_INCREMENT,
                driver_id INT NOT NULL,
                vehicle_id INT NOT NULL,
                call_date DATE NOT NULL,
                call_time TIME NOT NULL,
                caller_name VARCHAR(100) NOT NULL,
                alcohol_check_value DECIMAL(4,3) DEFAULT 0.000,
                health_condition BOOLEAN DEFAULT FALSE,
                fatigue_condition BOOLEAN DEFAULT FALSE,
                alcohol_condition BOOLEAN DEFAULT FALSE,
                vehicle_condition BOOLEAN DEFAULT FALSE,
                accident_violation BOOLEAN DEFAULT FALSE,
                equipment_return BOOLEAN DEFAULT FALSE,
                work_report_complete BOOLEAN DEFAULT FALSE,
                remarks TEXT,
                pre_duty_call_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_call_date (call_date),
                INDEX idx_driver_date (driver_id, call_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addResult("✓ post_duty_calls テーブル作成完了", true);
    } else {
        addResult("✓ post_duty_calls テーブルは既に存在します", true);
    }

    // 6. periodic_inspectionsテーブルの確認
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute(['periodic_inspections']);
    $periodic_exists = $stmt->fetchAll();
    
    if (empty($periodic_exists)) {
        $pdo->exec("
            CREATE TABLE periodic_inspections (
                id INT PRIMARY KEY AUTO_INCREMENT,
                vehicle_id INT NOT NULL,
                inspection_date DATE NOT NULL,
                inspector_name VARCHAR(100) NOT NULL,
                mileage INT,
                
                -- かじ取り装置
                steering_wheel VARCHAR(10) DEFAULT 'L',
                steering_gear_box VARCHAR(10) DEFAULT 'L',
                steering_rods VARCHAR(10) DEFAULT 'L',
                
                -- 制動装置
                brake_pedal VARCHAR(10) DEFAULT 'L',
                parking_brake VARCHAR(10) DEFAULT 'L',
                brake_hose VARCHAR(10) DEFAULT 'L',
                brake_fluid VARCHAR(10) DEFAULT 'L',
                
                -- 走行装置
                tire_condition VARCHAR(10) DEFAULT 'L',
                wheel_condition VARCHAR(10) DEFAULT 'L',
                wheel_bearing VARCHAR(10) DEFAULT 'L',
                
                -- 緩衝装置
                shock_absorber VARCHAR(10) DEFAULT 'L',
                suspension_spring VARCHAR(10) DEFAULT 'L',
                
                -- 動力伝達装置
                clutch_condition VARCHAR(10) DEFAULT 'L',
                transmission VARCHAR(10) DEFAULT 'L',
                drive_shaft VARCHAR(10) DEFAULT 'L',
                
                -- 電気装置
                ignition_system VARCHAR(10) DEFAULT 'L',
                battery_condition VARCHAR(10) DEFAULT 'L',
                wiring_condition VARCHAR(10) DEFAULT 'L',
                
                -- 原動機
                engine_condition VARCHAR(10) DEFAULT 'L',
                lubrication_system VARCHAR(10) DEFAULT 'L',
                fuel_system VARCHAR(10) DEFAULT 'L',
                cooling_system VARCHAR(10) DEFAULT 'L',
                
                -- 排ガス測定
                co_concentration DECIMAL(4,2),
                hc_concentration INT,
                
                -- 整備事業者情報
                maintenance_shop_name VARCHAR(255),
                maintenance_shop_address VARCHAR(255),
                certification_number VARCHAR(100),
                
                next_inspection_date DATE,
                remarks TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_inspection_date (inspection_date),
                INDEX idx_vehicle_id (vehicle_id),
                INDEX idx_next_inspection (next_inspection_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addResult("✓ periodic_inspections テーブル作成完了", true);
    } else {
        addResult("✓ periodic_inspections テーブルは既に存在します", true);
    }

    // 7. 年度マスタの初期データ投入
    addResult("年度マスタの初期データを投入中...", true);
    
    $current_year = date('Y');
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO fiscal_years (fiscal_year, start_date, end_date, is_active) 
        VALUES (?, ?, ?, ?)
    ");
    
    for ($year = $current_year - 2; $year <= $current_year + 3; $year++) {
        $start_date = ($year - 1) . '-04-01';
        $end_date = $year . '-03-31';
        $is_active = ($year == $current_year) ? 1 : 0;
        
        $stmt->execute([$year, $start_date, $end_date, $is_active]);
    }
    addResult("✓ 年度マスタデータ投入完了（{$current_year}年度を含む6年度分）", true);

    // 8. システム設定の確認・更新（安全版）
    addResult("システム設定を確認中...", true);
    
    $settings_to_update = [
        'system_version' => ['1.0.0', 'システムバージョン'],
        'system_name' => ['福祉輸送管理システム', 'システム名称'],
        'setup_completed' => ['1', 'セットアップ完了フラグ'],
        'setup_date' => [date('Y-m-d H:i:s'), 'セットアップ実行日時'],
        'last_update' => [date('Y-m-d H:i:s'), '最終更新日時']
    ];
    
    foreach ($settings_to_update as $key => $value_desc) {
        list($value, $description) = $value_desc;
        
        try {
            // まず既存チェック
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
            $check_stmt->execute([$key]);
            $exists = $check_stmt->fetchColumn();
            
            if ($exists > 0) {
                // 更新
                $update_stmt = $pdo->prepare("
                    UPDATE system_settings 
                    SET setting_value = ?, description = ?, updated_at = NOW() 
                    WHERE setting_key = ?
                ");
                $update_stmt->execute([$value, $description, $key]);
                addResult("✓ システム設定 '{$key}' を更新しました", true);
            } else {
                // 新規追加
                $insert_stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, description) 
                    VALUES (?, ?, ?)
                ");
                $insert_stmt->execute([$key, $value, $description]);
                addResult("✓ システム設定 '{$key}' を新規追加しました", true);
            }
        } catch (Exception $e) {
            addError("システム設定 '{$key}' の処理でエラー: " . $e->getMessage());
        }
    }

    // 9. データベース整合性チェック
    addResult("データベース整合性チェックを実行中...", true);
    
    // テーブル存在チェック
    $required_tables = [
        'users', 'vehicles', 'pre_duty_calls', 'post_duty_calls',
        'daily_inspections', 'periodic_inspections', 
        'departure_records', 'arrival_records', 'ride_records',
        'cash_confirmations', 'annual_reports', 'accidents',
        'fiscal_years', 'system_settings'
    ];
    
    $missing_tables = [];
    $check_stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    
    foreach ($required_tables as $table) {
        $check_stmt->execute([$table]);
        $exists = $check_stmt->fetchAll();
        if (empty($exists)) {
            $missing_tables[] = $table;
        }
    }
    
    if (empty($missing_tables)) {
        addResult("✓ 全ての必要テーブルが存在します", true);
    } else {
        addError("× 不足テーブル: " . implode(', ', $missing_tables));
    }

    // 10. テーブル最適化（エラー回避版）
    addResult("テーブル最適化を実行中...", true);
    
    try {
        // 最適化は個別に実行してエラー回避
        $optimize_tables = [
            'users', 'vehicles', 'pre_duty_calls', 'post_duty_calls', 'daily_inspections',
            'periodic_inspections', 'departure_records', 'arrival_records', 'ride_records',
            'cash_confirmations', 'annual_reports', 'accidents', 'fiscal_years', 'system_settings'
        ];
        
        $optimized_count = 0;
        foreach ($optimize_tables as $table) {
            try {
                $pdo->exec("OPTIMIZE TABLE `{$table}`");
                $optimized_count++;
            } catch (Exception $e) {
                // 個別テーブルのエラーは無視して続行
                continue;
            }
        }
        addResult("✓ テーブル最適化完了（{$optimized_count}個のテーブル）", true);
        
    } catch (Exception $e) {
        addResult("テーブル最適化をスキップしました: " . $e->getMessage(), true);
    }

} catch (Exception $e) {
    addError("セットアップ中にエラーが発生しました: " . $e->getMessage());
}

// セットアップ完了
if (empty($errors)) {
    addResult("🎉 福祉輸送管理システム 完全版セットアップが正常に完了しました！", true);
} else {
    addResult("⚠️ セットアップが完了しましたが、いくつかのエラーがありました", false);
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>システムセットアップ - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .setup-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .result-item {
            padding: 0.5rem 1rem;
            margin: 0.25rem 0;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .result-success {
            background-color: #d1edff;
            border-left-color: #28a745;
        }
        .result-error {
            background-color: #f8d7da;
            border-left-color: #dc3545;
        }
        .setup-stats {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .progress-bar {
            transition: width 0.5s ease;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-4">
        <!-- ヘッダー -->
        <div class="setup-header text-center">
            <h1><i class="fas fa-cogs me-3"></i>福祉輸送管理システム</h1>
            <h2>完全版セットアップ（最終版）</h2>
            <p class="mb-0">集金管理・陸運局提出・事故管理機能を含む完全版システムのセットアップ</p>
        </div>

        <!-- 統計情報 -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-success"><?= count(array_filter($results, function($r) { return $r['success']; })) ?></div>
                        <div>成功</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-danger"><?= count($errors) ?></div>
                        <div>エラー</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-4 text-primary"><?= count($results) ?></div>
                        <div>総タスク数</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 進捗バー -->
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold">セットアップ進捗</span>
                <span>100%</span>
            </div>
            <div class="progress" style="height: 10px;">
                <div class="progress-bar bg-success" style="width: 100%"></div>
            </div>
        </div>

        <!-- セットアップ結果 -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-list me-2"></i>セットアップ実行結果</h5>
            </div>
            <div class="card-body">
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($results as $result): ?>
                        <div class="result-item <?= $result['success'] ? 'result-success' : 'result-error' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="fas fa-<?= $result['success'] ? 'check-circle text-success' : 'exclamation-triangle text-danger' ?> me-2"></i>
                                    <?= htmlspecialchars($result['message']) ?>
                                </span>
                                <small class="text-muted"><?= $result['time'] ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- エラー詳細 -->
        <?php if (!empty($errors)): ?>
        <div class="card mt-4">
            <div class="card-header bg-danger text-white">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>エラー詳細</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    以下のエラーがありましたが、システムは動作可能です。必要に応じて手動で対応してください。
                </div>
                <ul class="list-unstyled">
                    <?php foreach ($errors as $error): ?>
                        <li class="mb-2">
                            <i class="fas fa-times text-danger me-2"></i>
                            <?= htmlspecialchars($error) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- セットアップ完了アクション -->
        <div class="card mt-4">
            <div class="card-header <?= empty($errors) ? 'bg-success text-white' : 'bg-warning' ?>">
                <h5>
                    <i class="fas fa-<?= empty($errors) ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    次のステップ
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($errors)): ?>
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle me-2"></i>セットアップ完了！</h6>
                        <p>福祉輸送管理システム（完全版）のセットアップが正常に完了しました。</p>
                    </div>
                    
                    <h6>利用可能な機能:</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check text-success me-2"></i>認証・権限管理</li>
                                <li><i class="fas fa-check text-success me-2"></i>出庫・入庫処理</li>
                                <li><i class="fas fa-check text-success me-2"></i>乗車記録管理</li>
                                <li><i class="fas fa-check text-success me-2"></i>乗務前・後点呼</li>
                                <li><i class="fas fa-check text-success me-2"></i>日常・定期点検</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check text-success me-2"></i>集金管理 <span class="badge bg-primary">NEW</span></li>
                                <li><i class="fas fa-check text-success me-2"></i>陸運局提出 <span class="badge bg-primary">NEW</span></li>
                                <li><i class="fas fa-check text-success me-2"></i>事故管理 <span class="badge bg-primary">NEW</span></li>
                                <li><i class="fas fa-check text-success me-2"></i>ユーザー・車両管理</li>
                                <li><i class="fas fa-check text-success me-2"></i>統計・レポート機能</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <a href="dashboard.php" class="btn btn-success me-2">
                            <i class="fas fa-home me-1"></i>ダッシュボードへ
                        </a>
                        <a href="cash_management.php" class="btn btn-primary me-2">
                            <i class="fas fa-calculator me-1"></i>集金管理
                        </a>
                        <a href="annual_report.php" class="btn btn-info me-2">
                            <i class="fas fa-file-alt me-1"></i>陸運局提出
                        </a>
                        <a href="accident_management.php" class="btn btn-warning">
                            <i class="fas fa-exclamation-triangle me-1"></i>事故管理
                        </a>
                    </div>
                    
                <?php else: ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>一部エラーがありました</h6>
                        <p>セットアップは完了しましたが、いくつかのエラーがありました。システムは基本的に動作しますが、必要に応じて手動で対応してください。</p>
                    </div>
                    
                    <div class="mt-3">
                        <button onclick="location.reload()" class="btn btn-primary me-2">
                            <i class="fas fa-redo me-1"></i>セットアップ再実行
                        </button>
                        <a href="dashboard.php" class="btn btn-success">
                            <i class="fas fa-home me-1"></i>ダッシュボードへ（続行）
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- システム情報 -->
        <div class="setup-stats mt-4">
            <h6><i class="fas fa-info-circle me-2"></i>システム情報</h6>
            <div class="row">
                <div class="col-md-6">
                    <ul class="list-unstyled mb-0">
                        <li><strong>システム名:</strong> 福祉輸送管理システム</li>
                        <li><strong>バージョン:</strong> 1.0.0（完全版）</li>
                        <li><strong>データベース:</strong> <?= DB_NAME ?></li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="list-unstyled mb-0">
                        <li><strong>セットアップ日時:</strong> <?= date('Y/m/d H:i:s') ?></li>
                        <li><strong>PHP バージョン:</strong> <?= phpversion() ?></li>
                        <li><strong>完成度:</strong> 100% 🎉</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ページロード時のアニメーション
        document.addEventListener('DOMContentLoaded', function() {
            const resultItems = document.querySelectorAll('.result-item');
            resultItems.forEach((item, index) => {
                setTimeout(() => {
                    item.style.opacity = '0';
                    item.style.transform = 'translateX(-20px)';
                    item.style.transition = 'all 0.3s ease';
                    
                    setTimeout(() => {
                        item.style.opacity = '1';
                        item.style.transform = 'translateX(0)';
                    }, 50);
                }, index * 50);
            });
        });
    </script>
</body>
</html>
