<?php
/**
 * テーブル重複・統合診断ツール
 * 23個のテーブルを分析し、重複・統合すべきテーブルを特定
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 テーブル重複・統合診断ツール</h1>";
echo "<div style='font-family: Arial; background: #f8f9fa; padding: 20px;'>";

// DB接続
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=twinklemark_wts;charset=utf8mb4",
        "twinklemark_taxi",
        "Smiley2525",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

// 1. 全テーブル一覧と詳細
echo "<h2>1. 📊 全テーブル分析（23個）</h2>";

$stmt = $pdo->query("SHOW TABLES");
$all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 基本設計との比較
$original_tables = [
    'users' => 'ユーザーマスタ',
    'vehicles' => '車両マスタ', 
    'pre_duty_calls' => '乗務前点呼',
    'post_duty_calls' => '乗務後点呼',
    'daily_inspections' => '日常点検',
    'periodic_inspections' => '定期点検',
    'daily_operations' => '運行記録（旧）',
    'departure_records' => '出庫記録（新）',
    'arrival_records' => '入庫記録（新）',
    'ride_records' => '乗車記録',
    'accidents' => '事故記録',
    'fiscal_years' => '年度マスタ',
    'system_settings' => 'システム設定'
];

echo "<div style='display: flex; gap: 20px;'>";

// 元々の設計テーブル
echo "<div style='flex: 1; background: #e7f1ff; padding: 15px; border-radius: 5px;'>";
echo "<h3>📋 元々の設計（13個）</h3>";
foreach ($original_tables as $table => $desc) {
    $exists = in_array($table, $all_tables);
    $status = $exists ? "✅" : "❌";
    $color = $exists ? "#d4edda" : "#f8d7da";
    echo "<div style='background: {$color}; padding: 5px; margin: 2px; border-radius: 3px;'>";
    echo "{$status} {$table} - {$desc}";
    echo "</div>";
}
echo "</div>";

// 追加されたテーブル
$additional_tables = array_diff($all_tables, array_keys($original_tables));
echo "<div style='flex: 1; background: #fff3cd; padding: 15px; border-radius: 5px;'>";
echo "<h3>⚠️ 追加テーブル（" . count($additional_tables) . "個）</h3>";
foreach ($additional_tables as $table) {
    echo "<div style='background: #f8d7da; padding: 5px; margin: 2px; border-radius: 3px;'>";
    echo "🔍 {$table}";
    echo "</div>";
}
echo "</div>";

echo "</div>";

// 2. テーブル詳細分析
echo "<h2>2. 🔍 テーブル詳細分析</h2>";

foreach ($all_tables as $table) {
    try {
        // テーブル構造取得
        $stmt = $pdo->query("DESCRIBE {$table}");
        $columns = $stmt->fetchAll();
        
        // レコード数取得
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        
        // 最終更新日取得
        $stmt = $pdo->query("SHOW TABLE STATUS LIKE '{$table}'");
        $status = $stmt->fetch();
        
        $is_original = isset($original_tables[$table]);
        $bg_color = $is_original ? "#e7f1ff" : "#fff3cd";
        
        echo "<div style='background: {$bg_color}; padding: 10px; margin: 5px; border-radius: 5px;'>";
        echo "<h4>{$table} ";
        if ($is_original) {
            echo "<span style='background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 12px;'>元設計</span>";
        } else {
            echo "<span style='background: #ffc107; color: black; padding: 2px 6px; border-radius: 3px; font-size: 12px;'>追加</span>";
        }
        echo "</h4>";
        
        echo "<div style='display: flex; gap: 15px; margin: 10px 0;'>";
        echo "<div><strong>レコード数:</strong> {$count}</div>";
        echo "<div><strong>カラム数:</strong> " . count($columns) . "</div>";
        echo "<div><strong>作成日:</strong> " . $status['Create_time'] . "</div>";
        echo "</div>";
        
        echo "<div><strong>カラム:</strong> ";
        $column_names = array_column($columns, 'Field');
        echo implode(', ', array_slice($column_names, 0, 8));
        if (count($column_names) > 8) echo "...";
        echo "</div>";
        
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 5px;'>エラー: {$table} - " . $e->getMessage() . "</div>";
    }
}

// 3. 重複・統合候補の特定
echo "<h2>3. 🔄 重複・統合候補の特定</h2>";

$redundancy_analysis = [
    'cash関連' => [
        'tables' => ['cash_confirmations', 'detailed_cash_confirmations', 'driver_change_stocks'],
        'issue' => '集金管理で複数テーブルが作られ、データが分散',
        'solution' => '1つの統合テーブルに集約'
    ],
    'reports関連' => [
        'tables' => ['annual_reports'],
        'issue' => '年次報告用に新規作成',
        'solution' => '既存のfiscal_yearsとの統合検討'
    ],
    'company_info' => [
        'tables' => ['company_info'],
        'issue' => 'システム設定と重複する可能性',
        'solution' => 'system_settingsとの統合検討'
    ]
];

foreach ($redundancy_analysis as $category => $info) {
    echo "<div style='background: #f8d7da; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<h4>⚠️ {$category}</h4>";
    echo "<div><strong>対象テーブル:</strong> " . implode(', ', $info['tables']) . "</div>";
    echo "<div><strong>問題:</strong> {$info['issue']}</div>";
    echo "<div><strong>解決策:</strong> {$info['solution']}</div>";
    echo "</div>";
}

// 4. データ分散状況確認
echo "<h2>4. 📊 データ分散状況確認</h2>";

try {
    // 今日のデータがどのテーブルに入っているか確認
    $data_distribution = [];
    
    // 運行関連
    if (in_array('daily_operations', $all_tables)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM daily_operations WHERE DATE(created_at) = CURDATE()");
        $data_distribution['daily_operations（旧運行）'] = $stmt->fetchColumn();
    }
    
    if (in_array('departure_records', $all_tables)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM departure_records WHERE departure_date = CURDATE()");
        $data_distribution['departure_records（新出庫）'] = $stmt->fetchColumn();
    }
    
    if (in_array('arrival_records', $all_tables)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM arrival_records WHERE arrival_date = CURDATE()");
        $data_distribution['arrival_records（新入庫）'] = $stmt->fetchColumn();
    }
    
    if (in_array('ride_records', $all_tables)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM ride_records WHERE DATE(created_at) = CURDATE()");
        $data_distribution['ride_records（乗車記録）'] = $stmt->fetchColumn();
    }
    
    // 集金関連
    foreach (['cash_confirmations', 'detailed_cash_confirmations', 'driver_change_stocks'] as $table) {
        if (in_array($table, $all_tables)) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
            $data_distribution[$table] = $stmt->fetchColumn();
        }
    }
    
    echo "<div style='background: #e7f1ff; padding: 15px; border-radius: 5px;'>";
    echo "<h4>今日のデータ分散状況</h4>";
    foreach ($data_distribution as $table => $count) {
        $color = $count > 0 ? "#d4edda" : "#f8d7da";
        echo "<div style='background: {$color}; padding: 5px; margin: 2px; border-radius: 3px;'>";
        echo "{$table}: {$count}件";
        echo "</div>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 10px;'>データ分散確認エラー: " . $e->getMessage() . "</div>";
}

// 5. 統合推奨アクション
echo "<h2>5. 🛠️ 統合推奨アクション</h2>";

echo "<div style='background: #d1ecf1; padding: 20px; border-radius: 5px;'>";
echo "<h4>緊急対応（今すぐ実行）:</h4>";
echo "<ol>";
echo "<li><strong>ダッシュボード修正</strong> - 正しいテーブルを参照するよう修正</li>";
echo "<li><strong>cash_management修正</strong> - 使用テーブルを標準化</li>";
echo "<li><strong>重複テーブルのバックアップ</strong> - データ移行前の安全確保</li>";
echo "</ol>";

echo "<h4>根本解決（段階的実行）:</h4>";
echo "<ol>";
echo "<li><strong>テーブル統合計画作成</strong> - どのテーブルを残し、どれを統合するか</li>";
echo "<li><strong>データ移行スクリプト作成</strong> - 重複データの統合</li>";
echo "<li><strong>不要テーブル削除</strong> - 23個→13個に削減</li>";
echo "<li><strong>アプリケーション修正</strong> - 統合後のテーブル構造に対応</li>";
echo "</ol>";
echo "</div>";

echo "</div>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h1, h2, h3, h4 { color: #333; }
</style>
