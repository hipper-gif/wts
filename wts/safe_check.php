<?php
session_start();
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die("接続エラー: " . $e->getMessage());
}

// ログインチェック
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("管理者権限が必要です");
}

$action = $_GET['action'] ?? '';

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安全確認ツール</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        .safe { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .danger { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 8px; margin: 10px 0; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #f2f2f2; }
        .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px; }
        .btn-safe { background: #28a745; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-danger { background: #dc3545; }
    </style>
</head>
<body>

<h1>🛡️ データ安全確認ツール</h1>

<?php if ($action === 'check'): ?>

<h2>📊 現在のデータ状況</h2>

<?php
// データ状況を詳細チェック
$tables = ['ride_records', 'daily_inspections', 'pre_duty_calls', 'post_duty_calls', 
           'departure_records', 'arrival_records'];

foreach ($tables as $table) {
    try {
        echo "<h3>📋 {$table}</h3>";
        
        // テーブル存在確認
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() == 0) {
            echo "<div class='warning'>⚠️ テーブル {$table} が存在しません</div>";
            continue;
        }
        
        // 総件数
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $total = $stmt->fetchColumn();
        
        // is_sample_dataカラム存在確認
        $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'is_sample_data'");
        $has_sample_flag = $stmt->rowCount() > 0;
        
        echo "<table>";
        echo "<tr><th>項目</th><th>状況</th><th>安全性</th></tr>";
        echo "<tr><td>総レコード数</td><td><strong>{$total}</strong></td><td>";
        if ($total > 0) {
            echo "<span style='color: #28a745;'>✅ データ存在</span>";
        } else {
            echo "<span style='color: #6c757d;'>📭 データなし</span>";
        }
        echo "</td></tr>";
        
        if ($has_sample_flag) {
            // サンプルフラグ付きデータの確認
            $stmt = $pdo->query("
                SELECT 
                    COUNT(CASE WHEN is_sample_data = TRUE THEN 1 END) as sample_count,
                    COUNT(CASE WHEN is_sample_data = FALSE OR is_sample_data IS NULL THEN 1 END) as real_count
                FROM {$table}
            ");
            $flags = $stmt->fetch();
            
            echo "<tr><td>サンプルデータ</td><td>{$flags['sample_count']}</td><td>";
            if ($flags['sample_count'] > 0) {
                echo "<span style='color: #ffc107;'>⚠️ 削除対象</span>";
            } else {
                echo "<span style='color: #28a745;'>✅ 削除対象なし</span>";
            }
            echo "</td></tr>";
            
            echo "<tr><td>実務データ</td><td><strong>{$flags['real_count']}</strong></td><td>";
            echo "<span style='color: #28a745;'>🛡️ 保護される</span>";
            echo "</td></tr>";
            
            echo "<tr><td>フラグ状態</td><td>設定済み</td><td><span style='color: #28a745;'>✅ 管理可能</span></td></tr>";
        } else {
            echo "<tr><td>サンプルフラグ</td><td>未設定</td><td><span style='color: #17a2b8;'>ℹ️ 全データ保護</span></td></tr>";
        }
        
        // 日付範囲確認（該当するテーブルのみ）
        $date_columns = [
            'ride_records' => 'ride_date',
            'daily_inspections' => 'inspection_date',
            'pre_duty_calls' => 'call_date',
            'post_duty_calls' => 'call_date',
            'departure_records' => 'departure_date',
            'arrival_records' => 'arrival_date'
        ];
        
        if (isset($date_columns[$table]) && $total > 0) {
            $date_col = $date_columns[$table];
            $stmt = $pdo->query("SELECT MIN({$date_col}) as min_date, MAX({$date_col}) as max_date FROM {$table}");
            $dates = $stmt->fetch();
            
            echo "<tr><td>データ期間</td><td>{$dates['min_date']} ～ {$dates['max_date']}</td><td>";
            $days_ago = (strtotime('now') - strtotime($dates['max_date'])) / 86400;
            if ($days_ago <= 7) {
                echo "<span style='color: #28a745;'>✅ 最新データあり</span>";
            } else {
                echo "<span style='color: #ffc107;'>⚠️ {$days_ago}日前が最新</span>";
            }
            echo "</td></tr>";
        }
        
        echo "</table>";
        
    } catch (Exception $e) {
        echo "<div class='danger'>❌ エラー: {$e->getMessage()}</div>";
    }
}
?>

<h2>🎯 推奨される安全な手順</h2>

<div class="info">
<h3>📋 現在の状況に基づく推奨アクション</h3>

<?php
// 推奨アクション決定
$stmt = $pdo->query("SELECT COUNT(*) FROM ride_records");
$ride_count = $stmt->fetchColumn();

$stmt = $pdo->query("SHOW COLUMNS FROM ride_records LIKE 'is_sample_data'");
$has_flags = $stmt->rowCount() > 0;

if ($ride_count == 0) {
    echo "<p><strong>状況:</strong> まだ本番データがありません</p>";
    echo "<p><strong>推奨:</strong> まずは通常通り運用を開始してください</p>";
} elseif (!$has_flags) {
    echo "<p><strong>状況:</strong> 本番データがありますが、サンプルフラグは未設定です</p>";
    echo "<p><strong>推奨:</strong> 以下の安全な手順で管理機能を追加できます</p>";
    echo "<ol>";
    echo "<li>🛡️ まず実務データをバックアップ（エクスポート）</li>";
    echo "<li>🏷️ サンプルフラグ機能を追加（既存データに影響なし）</li>";
    echo "<li>📅 必要に応じて過去のテストデータをサンプルとしてマーク</li>";
    echo "</ol>";
} else {
    echo "<p><strong>状況:</strong> サンプルフラグが設定済みです</p>";
    echo "<p><strong>推奨:</strong> 現在の設定で安全に管理できています</p>";
}
?>
</div>

<h2>🔧 安全な操作メニュー</h2>

<div style="display: flex; gap: 15px; flex-wrap: wrap;">
    <a href="?action=backup_first" class="btn btn-safe">💾 まずバックアップ</a>
    <a href="?action=add_flags_safe" class="btn btn-warning">🏷️ 安全にフラグ追加</a>
    <a href="dashboard.php" class="btn">🏠 ダッシュボード</a>
</div>

<?php elseif ($action === 'backup_first'): ?>

<h2>💾 安全なバックアップ作成</h2>

<div class="safe">
<h3>✅ この操作は100%安全です</h3>
<p>既存のデータは一切変更されません。読み取り専用でバックアップを作成します。</p>
</div>

<?php
if ($_GET['execute'] === 'yes') {
    // バックアップ実行
    $backup_data = [];
    $tables = ['users', 'vehicles', 'ride_records', 'daily_inspections', 'pre_duty_calls', 
               'post_duty_calls', 'departure_records', 'arrival_records', 'system_settings'];
    
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->query("SELECT * FROM {$table}");
                $backup_data[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo "<p>✅ {$table}: " . count($backup_data[$table]) . "件をバックアップ</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ {$table}: {$e->getMessage()}</p>";
        }
    }
    
    // JSON形式でダウンロード
    $filename = "wts_backup_" . date('Y-m-d_H-i-s') . ".json";
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
} else {
?>

<div class="info">
<h3>📦 作成されるバックアップの内容</h3>
<ul>
<li>🧑‍💼 ユーザー情報（パスワードは暗号化済み）</li>
<li>🚗 車両情報</li>
<li>📊 全ての乗車記録</li>
<li>✅ 点呼・点検記録</li>
<li>🚀 出庫・入庫記録</li>
<li>⚙️ システム設定</li>
</ul>
<p><strong>形式:</strong> JSON形式（他のシステムでも読み込み可能）</p>
<p><strong>ファイル名:</strong> wts_backup_日時.json</p>
</div>

<a href="?action=backup_first&execute=yes" class="btn btn-safe">📥 バックアップをダウンロード</a>
<a href="?action=check" class="btn">← 戻る</a>

<?php } ?>

<?php elseif ($action === 'add_flags_safe'): ?>

<h2>🏷️ 安全なサンプルフラグ追加</h2>

<div class="safe">
<h3>✅ この操作も100%安全です</h3>
<p>既存のデータは変更されません。各テーブルにフラグ用のカラムを追加するだけです。</p>
</div>

<?php
if ($_GET['execute'] === 'yes') {
    $tables = ['ride_records', 'daily_inspections', 'pre_duty_calls', 'post_duty_calls', 
               'departure_records', 'arrival_records', 'periodic_inspections'];
    
    foreach ($tables as $table) {
        try {
            // テーブル存在確認
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() == 0) {
                echo "<p>⚠️ {$table} テーブルが存在しません（スキップ）</p>";
                continue;
            }
            
            // カラム存在確認
            $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'is_sample_data'");
            if ($stmt->rowCount() > 0) {
                echo "<p>ℹ️ {$table}: サンプルフラグは既に存在します</p>";
            } else {
                // カラム追加
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN is_sample_data BOOLEAN DEFAULT FALSE");
                echo "<p>✅ {$table}: サンプルフラグを追加しました</p>";
            }
            
            // 件数確認
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            echo "<p>📊 {$table}: {$count}件のデータ（全て実務データとして保護）</p>";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ {$table}: {$e->getMessage()}</p>";
        }
    }
    
    echo "<div class='safe'>";
    echo "<h3>🎉 完了！</h3>";
    echo "<p>サンプルフラグ機能が追加されました。既存の全てのデータは実務データとして保護されています。</p>";
    echo "<p><a href='data_management.php' class='btn btn-safe'>詳細管理画面へ</a></p>";
    echo "</div>";
    
} else {
?>

<div class="info">
<h3>🔧 実行される処理</h3>
<ol>
<li>各テーブルに「is_sample_data」カラムを追加</li>
<li>既存データは全て「FALSE」（実務データ）として設定</li>
<li>今後、明示的にマークしたもののみサンプル扱い</li>
</ol>

<h4>📋 対象テーブル</h4>
<ul>
<li>ride_records（乗車記録）</li>
<li>daily_inspections（日常点検）</li>
<li>pre_duty_calls（乗務前点呼）</li>
<li>post_duty_calls（乗務後点呼）</li>
<li>departure_records（出庫記録）</li>
<li>arrival_records（入庫記録）</li>
<li>periodic_inspections（定期点検）</li>
</ul>
</div>

<a href="?action=add_flags_safe&execute=yes" class="btn btn-safe">🔧 フラグ機能を追加</a>
<a href="?action=check" class="btn">← 戻る</a>

<?php } ?>

<?php else: ?>

<div class="safe">
<h2>🛡️ データ安全確認について</h2>
<p>既存の本番データを保護しながら、サンプルデータ管理機能を安全に追加できます。</p>
</div>

<div class="info">
<h3>📋 確認できること</h3>
<ul>
<li>🔍 現在のデータ状況（件数・期間・種別）</li>
<li>🛡️ 各テーブルの安全性確認</li>
<li>💾 安全なバックアップ作成</li>
<li>🏷️ 既存データを保護したまま管理機能追加</li>
</ul>
</div>

<div class="warning">
<h3>⚠️ 重要な注意事項</h3>
<ul>
<li>作業前に必ずバックアップを作成することをお勧めします</li>
<li>不安な場合は、まずテスト環境で動作確認してください</li>
<li>疑問があれば作業を中断して確認してください</li>
</ul>
</div>

<a href="?action=check" class="btn btn-safe">📊 データ状況を確認</a>
<a href="dashboard.php" class="btn">🏠 ダッシュボード</a>

<?php endif; ?>

</body>
</html>
