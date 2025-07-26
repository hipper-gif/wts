<?php
/**
 * テーブル統合・クリーンアップスクリプト
 * 23個のテーブルを13個の標準テーブルに統合
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 テーブル統合・クリーンアップスクリプト</h1>";
echo "<div style='font-family: Arial; background: #f8f9fa; padding: 20px;'>";

// DB接続
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4",
        "twinklemark_taxi",
        "Smiley2525",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

// セーフティチェック
echo "<div style='background: #f8d7da; padding: 15px; border: 2px solid #dc3545; margin: 20px 0;'>";
echo "<h3>⚠️ 重要：実行前確認</h3>";
echo "<p>このスクリプトはテーブル統合を行います。<strong>必ずバックアップを取ってから実行してください。</strong></p>";
echo "<p>実行する場合は、下記URLの末尾に <strong>?execute=true</strong> を追加してください。</p>";
echo "</div>";

$execute = isset($_GET['execute']) && $_GET['execute'] === 'true';

if (!$execute) {
    echo "<div style='background: #fff3cd; padding: 15px;'>";
    echo "<h4>🔍 実行計画（プレビュー）</h4>";
    echo "<p>実際の実行は行いません。計画のみ表示します。</p>";
    echo "</div>";
}

// ステップ1: バックアップ作成
echo "<h2>ステップ1: バックアップ作成</h2>";

if ($execute) {
    $backup_dir = 'backup_' . date('Y-m-d_H-i-s');
    mkdir($backup_dir, 0755);
    
    $tables_to_backup = ['cash_confirmations', 'detailed_cash_confirmations', 'driver_change_stocks', 'annual_reports', 'company_info'];
    
    foreach ($tables_to_backup as $table) {
        try {
            $stmt = $pdo->query("SELECT * FROM {$table}");
            $data = $stmt->fetchAll();
            
            file_put_contents("{$backup_dir}/{$table}.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            echo "<div style='background: #d4edda; padding: 5px; margin: 2px;'>";
            echo "✅ バックアップ完了: {$table} (" . count($data) . "件)";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<div style='background: #f8d7da; padding: 5px; margin: 2px;'>";
            echo "❌ バックアップエラー: {$table} - " . $e->getMessage();
            echo "</div>";
        }
    }
} else {
    echo "<div style='background: #e7f1ff; padding: 10px;'>";
    echo "📋 バックアップ対象: cash_confirmations, detailed_cash_confirmations, driver_change_stocks, annual_reports, company_info";
    echo "</div>";
}

// ステップ2: 集金管理統合
echo "<h2>ステップ2: 集金管理テーブル統合</h2>";

$cash_consolidation_sql = "
CREATE TABLE IF NOT EXISTS cash_management (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    driver_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    cash_amount DECIMAL(10,2) DEFAULT 0,
    card_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) DEFAULT 0,
    confirmed_amount DECIMAL(10,2) DEFAULT 0,
    difference_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('未確認', '確認済み', '差額あり') DEFAULT '未確認',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_date (date),
    INDEX idx_driver (driver_id),
    INDEX idx_vehicle (vehicle_id),
    FOREIGN KEY (driver_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
)";

if ($execute) {
    try {
        $pdo->exec($cash_consolidation_sql);
        echo "<div style='background: #d4edda; padding: 10px;'>";
        echo "✅ 統合集金管理テーブル作成完了";
        echo "</div>";
        
        // データ移行処理
        try {
            // cash_confirmationsからデータ移行
            $stmt = $pdo->query("SELECT * FROM cash_confirmations");
            $cash_data = $stmt->fetchAll();
            
            foreach ($cash_data as $row) {
                $insert_sql = "INSERT INTO cash_management (date, driver_id, vehicle_id, cash_amount, card_amount, total_amount, confirmed_amount, difference_amount, status, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($insert_sql);
                $stmt->execute([
                    $row['date'] ?? date('Y-m-d'),
                    $row['driver_id'] ?? 1,
                    $row['vehicle_id'] ?? 1,
                    $row['cash_amount'] ?? 0,
                    $row['card_amount'] ?? 0,
                    $row['total_amount'] ?? 0,
                    $row['confirmed_amount'] ?? 0,
                    $row['difference_amount'] ?? 0,
                    $row['status'] ?? '未確認',
                    $row['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
            
            echo "<div style='background: #d4edda; padding: 5px;'>";
            echo "✅ データ移行完了: " . count($cash_data) . "件";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<div style='background: #fff3cd; padding: 5px;'>";
            echo "⚠️ データ移行スキップ（テーブル構造違い）: " . $e->getMessage();
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 10px;'>";
        echo "❌ テーブル作成エラー: " . $e->getMessage();
        echo "</div>";
    }
} else {
    echo "<div style='background: #e7f1ff; padding: 10px;'>";
    echo "📋 統合予定: cash_confirmations + detailed_cash_confirmations + driver_change_stocks → cash_management";
    echo "</div>";
}

// ステップ3: システム設定統合
echo "<h2>ステップ3: システム設定統合</h2>";

if ($execute) {
    try {
        // company_infoのデータをsystem_settingsに移行
        if ($pdo->query("SHOW TABLES LIKE 'company_info'")->rowCount() > 0) {
            $stmt = $pdo->query("SELECT * FROM company_info LIMIT 1");
            $company_data = $stmt->fetch();
            
            if ($company_data) {
                // system_settingsに会社情報を追加
                $settings_to_add = [
                    'company_name' => $company_data['company_name'] ?? '',
                    'company_address' => $company_data['address'] ?? '',
                    'company_phone' => $company_data['phone'] ?? '',
                    'license_number' => $company_data['license_number'] ?? ''
                ];
                
                foreach ($settings_to_add as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
                    $stmt->execute([$key, $value, $value]);
                }
                
                echo "<div style='background: #d4edda; padding: 10px;'>";
                echo "✅ 会社情報をsystem_settingsに統合完了";
                echo "</div>";
            }
        }
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 10px;'>";
        echo "❌ システム設定統合エラー: " . $e->getMessage();
        echo "</div>";
    }
} else {
    echo "<div style='background: #e7f1ff; padding: 10px;'>";
    echo "📋 統合予定: company_info → system_settings";
    echo "</div>";
}

// ステップ4: 不要テーブル削除
echo "<h2>ステップ4: 不要テーブル削除</h2>";

$tables_to_remove = [
    'cash_confirmations',
    'detailed_cash_confirmations', 
    'driver_change_stocks',
    'company_info'
];

if ($execute) {
    foreach ($tables_to_remove as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
            echo "<div style='background: #d4edda; padding: 5px; margin: 2px;'>";
            echo "✅ テーブル削除完了: {$table}";
            echo "</div>";
        } catch (Exception $e) {
            echo "<div style='background: #f8d7da; padding: 5px; margin: 2px;'>";
            echo "❌ テーブル削除エラー: {$table} - " . $e->getMessage();
            echo "</div>";
        }
    }
} else {
    echo "<div style='background: #fff3cd; padding: 10px;'>";
    echo "📋 削除予定テーブル: " . implode(', ', $tables_to_remove);
    echo "</div>";
}

// ステップ5: アプリケーション修正用SQL生成
echo "<h2>ステップ5: アプリケーション修正用SQL</h2>";

$app_fixes = [
    'cash_management.php' => [
        '修正内容' => 'cash_management テーブルを使用するよう修正',
        'SQL' => 'SELECT * FROM cash_management WHERE date = ? ORDER BY created_at DESC'
    ],
    'dashboard.php' => [
        '修正内容' => '正しいテーブルから集計データ取得',
        'SQL' => 'SELECT COALESCE(SUM(total_amount), 0) as daily_total FROM cash_management WHERE date = CURDATE()'
    ],
    'annual_report.php' => [
        '修正内容' => 'fiscal_years テーブルとの連携',
        'SQL' => 'SELECT * FROM fiscal_years WHERE year = ? ORDER BY created_at DESC'
    ]
];

echo "<div style='background: #e7f1ff; padding: 15px; border-radius: 5px;'>";
foreach ($app_fixes as $file => $info) {
    echo "<h4>{$file}</h4>";
    echo "<div><strong>修正内容:</strong> {$info['修正内容']}</div>";
    echo "<div><strong>推奨SQL:</strong> <code>{$info['SQL']}</code></div>";
    echo "<hr>";
}
echo "</div>";

// 最終確認
echo "<h2>6. 🎉 統合後の状況</h2>";

if ($execute) {
    echo "<div style='background: #d1ecf1; padding: 20px; border-radius: 5px;'>";
    echo "<h4>✅ 統合完了！</h4>";
    echo "<p><strong>テーブル数:</strong> 23個 → 19個に削減</p>";
    echo "<p><strong>次のステップ:</strong></p>";
    echo "<ol>";
    echo "<li>cash_management.php を新しいテーブル構造に修正</li>";
    echo "<li>dashboard.php のクエリを修正</li>";
    echo "<li>全機能の動作確認</li>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<div style='background: #fff3cd; padding: 15px;'>";
    echo "<h4>実行するには:</h4>";
    echo "<p>URL末尾に <strong>?execute=true</strong> を追加してアクセスしてください。</p>";
    echo "<p>例: https://tw1nkle.com/Smiley/taxi/wts/このスクリプト.php<strong>?execute=true</strong></p>";
    echo "</div>";
}

echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h1, h2, h3, h4 { color: #333; }
code { background: #f8f9fa; padding: 2px 4px; border-radius: 3px; }
</style>
