<?php
/**
 * データベース構造確認ツール
 * このスクリプトは診断用です。確認後に削除してください。
 */
session_start();

// 管理者認証チェック
if (!isset($_SESSION['user_id'])) {
    die('認証が必要です。ログインしてください。');
}
$is_admin = false;
if (isset($_SESSION['user_permission_level']) && $_SESSION['user_permission_level'] === 'Admin') $is_admin = true;
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) $is_admin = true;
if (isset($_SESSION['is_admin_user']) && $_SESSION['is_admin_user']) $is_admin = true;
if (!$is_admin) {
    die('管理者権限が必要です。');
}

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    die('DB接続エラー: ' . $e->getMessage());
}

// 期待されるテーブル一覧
$expected_tables = [
    'users', 'vehicles', 'ride_records', 'departure_records', 'arrival_records',
    'pre_duty_calls', 'post_duty_calls', 'daily_inspections', 'periodic_inspections',
    'cash_count_details', 'cash_management', 'cash_collections',
    'reservations', 'partner_companies', 'frequent_locations', 'calendar_audit_logs',
    'reservation_field_options', 'system_settings', 'transport_categories', 'location_master',
    'company_info', 'fiscal_years', 'annual_reports', 'accidents'
];

// テーブル一覧取得
$tables_stmt = $pdo->query("SHOW TABLES");
$all_tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);

// テーブルサイズ取得
$size_stmt = $pdo->prepare("
    SELECT table_name, table_rows, data_length, index_length,
           ROUND((data_length + index_length) / 1024, 2) AS size_kb
    FROM information_schema.TABLES
    WHERE table_schema = ?
    ORDER BY table_name
");
$size_stmt->execute([DB_NAME]);
$table_sizes = [];
while ($row = $size_stmt->fetch()) {
    $table_sizes[$row['table_name']] = $row;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WTS データベース構造確認</title>
<style>
body { font-family: 'Noto Sans JP', sans-serif; margin: 20px; background: #f5f5f5; }
.warning { background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: bold; }
h1 { color: #333; } h2 { color: #555; margin-top: 30px; }
table { border-collapse: collapse; width: 100%; margin-bottom: 20px; background: #fff; }
th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; font-size: 14px; }
th { background: #2196F3; color: white; }
tr:nth-child(even) { background: #f9f9f9; }
.exists { color: green; font-weight: bold; }
.missing { color: red; font-weight: bold; }
.empty { color: orange; }
.unexpected { background: #fff3cd; }
.section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; color: white; }
.badge-green { background: #28a745; } .badge-red { background: #dc3545; } .badge-yellow { background: #ffc107; color: #333; }
</style>
</head>
<body>
<div class="warning">このスクリプトは診断用です。確認後に削除してください。</div>

<h1>WTS データベース構造確認</h1>
<p><strong>データベース:</strong> <?= htmlspecialchars(DB_NAME) ?> | <strong>ホスト:</strong> <?= htmlspecialchars(DB_HOST) ?> | <strong>確認日時:</strong> <?= date('Y-m-d H:i:s') ?></p>

<div class="section">
<h2>1. テーブル一覧 (<?= count($all_tables) ?>テーブル)</h2>
<table>
<tr><th>#</th><th>テーブル名</th><th>行数</th><th>サイズ(KB)</th><th>ステータス</th></tr>
<?php foreach ($all_tables as $i => $table): ?>
<?php
    $info = $table_sizes[$table] ?? null;
    $rows = $info ? $info['table_rows'] : '?';
    $size = $info ? $info['size_kb'] : '?';
    $in_expected = in_array($table, $expected_tables);
    $class = !$in_expected ? 'unexpected' : '';
?>
<tr class="<?= $class ?>">
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($table) ?></td>
    <td><?= $rows == 0 ? '<span class="empty">0</span>' : $rows ?></td>
    <td><?= $size ?></td>
    <td>
        <?php if (!$in_expected): ?><span class="badge badge-yellow">想定外</span><?php endif; ?>
        <?php if ($rows == 0): ?><span class="badge badge-red">空</span><?php endif; ?>
        <?php if ($in_expected && $rows > 0): ?><span class="badge badge-green">正常</span><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="section">
<h2>2. 期待テーブルの存在チェック</h2>
<table>
<tr><th>テーブル名</th><th>存在</th><th>行数</th></tr>
<?php foreach ($expected_tables as $table): ?>
<?php $exists = in_array($table, $all_tables); ?>
<tr>
    <td><?= htmlspecialchars($table) ?></td>
    <td class="<?= $exists ? 'exists' : 'missing' ?>"><?= $exists ? 'YES' : 'NO' ?></td>
    <td><?= $exists ? ($table_sizes[$table]['table_rows'] ?? '?') : '-' ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="section">
<h2>3. 各テーブルのカラム詳細</h2>
<?php foreach ($all_tables as $table): ?>
<h3><?= htmlspecialchars($table) ?></h3>
<table>
<tr><th>カラム名</th><th>型</th><th>NULL</th><th>キー</th><th>デフォルト</th></tr>
<?php
    $col_stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
    while ($col = $col_stmt->fetch()):
?>
<tr>
    <td><?= htmlspecialchars($col['Field']) ?></td>
    <td><?= htmlspecialchars($col['Type']) ?></td>
    <td><?= $col['Null'] ?></td>
    <td><?= $col['Key'] ?></td>
    <td><?= htmlspecialchars($col['Default'] ?? 'NULL') ?></td>
</tr>
<?php endwhile; ?>
</table>
<?php endforeach; ?>
</div>

<div class="section">
<h2>4. is_sample_data カラムを持つテーブル</h2>
<table>
<tr><th>テーブル名</th><th>サンプルデータ行数</th><th>実データ行数</th></tr>
<?php foreach ($all_tables as $table):
    $col_check = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE 'is_sample_data'");
    if ($col_check->rowCount() > 0):
        $sample_count = $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "` WHERE is_sample_data = 1")->fetchColumn();
        $real_count = $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "` WHERE COALESCE(is_sample_data, 0) = 0")->fetchColumn();
?>
<tr>
    <td><?= htmlspecialchars($table) ?></td>
    <td class="<?= $sample_count > 0 ? 'empty' : '' ?>"><?= $sample_count ?></td>
    <td><?= $real_count ?></td>
</tr>
<?php endif; endforeach; ?>
</table>
</div>

<div class="section">
<h2>5. 想定外のテーブル</h2>
<?php
$unexpected = array_diff($all_tables, $expected_tables);
if (empty($unexpected)): ?>
<p class="exists">想定外のテーブルはありません。</p>
<?php else: ?>
<table>
<tr><th>テーブル名</th><th>行数</th><th>対応</th></tr>
<?php foreach ($unexpected as $table): ?>
<tr>
    <td><?= htmlspecialchars($table) ?></td>
    <td><?= $table_sizes[$table]['table_rows'] ?? '?' ?></td>
    <td>要確認 - 削除候補の可能性</td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</div>

</body>
</html>
