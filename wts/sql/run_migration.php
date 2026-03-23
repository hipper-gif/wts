<?php
// ============================================================
// WTS マイグレーションランナー
//
// 使い方:
//   php run_migration.php                    # 未適用の全SQLを順番に実行
//   php run_migration.php 007_migration.sql  # 指定ファイルのみ実行
//   php run_migration.php --status           # 適用状況を表示
//   php run_migration.php --dry-run          # 実行せず計画を表示
// ============================================================

require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();

// ---- コマンドライン引数解析 ----
$args     = array_slice($argv ?? [], 1);
$dry_run  = in_array('--dry-run', $args);
$status   = in_array('--status', $args);
$specific = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

// ---- migration_history テーブル確保 ----
ensureMigrationTable($pdo);

if ($status) {
    showStatus($pdo);
    exit(0);
}

// ---- SQLファイル収集 ----
$sql_dir = __DIR__;
$all_files = glob("$sql_dir/*.sql");
sort($all_files); // ファイル名順（番号付きが先）

// 除外: このスクリプト自身のSQLや特殊ファイルは含まない
$migration_files = [];
foreach ($all_files as $file) {
    $basename = basename($file);
    // .htaccess等は除外
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'sql') continue;
    $migration_files[] = $file;
}

// 特定ファイル指定時
if (!empty($specific)) {
    $migration_files = array_filter($migration_files, function($f) use ($specific) {
        return in_array(basename($f), $specific);
    });
    if (empty($migration_files)) {
        echo "エラー: 指定されたファイルが見つかりません\n";
        exit(1);
    }
}

// ---- 適用済みファイル取得 ----
$applied = getAppliedMigrations($pdo);

// ---- 未適用を抽出 ----
$pending = [];
foreach ($migration_files as $file) {
    $basename = basename($file);
    if (!isset($applied[$basename])) {
        $pending[] = $file;
    }
}

if (empty($pending)) {
    echo "全てのマイグレーションが適用済みです。\n";
    showStatus($pdo);
    exit(0);
}

echo "=== WTS マイグレーションランナー ===\n\n";
echo "未適用マイグレーション: " . count($pending) . " 件\n\n";

foreach ($pending as $i => $file) {
    $basename = basename($file);
    $n = $i + 1;
    echo "  [{$n}] {$basename}\n";
}
echo "\n";

if ($dry_run) {
    echo "(dry-run モード - 実行しません)\n";
    exit(0);
}

// ---- 実行 ----
$success = 0;
$failed  = 0;

foreach ($pending as $file) {
    $basename = basename($file);
    $content  = file_get_contents($file);
    $checksum = hash('sha256', $content);

    echo "--- {$basename} ---\n";

    // SQL文を分割
    $statements = parseSqlStatements($content);
    if (empty($statements)) {
        echo "  (空のファイル - スキップ)\n\n";
        recordMigration($pdo, $basename, $checksum, 0, 'success', 'Empty file');
        $success++;
        continue;
    }

    $start = microtime(true);
    try {
        $pdo->beginTransaction();
        foreach ($statements as $stmt) {
            echo "  実行: " . mb_substr(trim(preg_replace('/\s+/', ' ', $stmt)), 0, 80) . "...\n";
            $pdo->exec($stmt);
        }
        $pdo->commit();

        $elapsed = (int)((microtime(true) - $start) * 1000);
        recordMigration($pdo, $basename, $checksum, $elapsed, 'success');
        echo "  OK ({$elapsed}ms)\n\n";
        $success++;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $elapsed = (int)((microtime(true) - $start) * 1000);
        recordMigration($pdo, $basename, $checksum, $elapsed, 'failed', $e->getMessage());
        echo "  エラー: " . $e->getMessage() . "\n\n";
        $failed++;
        // 失敗したら後続のマイグレーションは実行しない
        break;
    }
}

echo "=== 結果: 成功 {$success} / 失敗 {$failed} ===\n";
exit($failed > 0 ? 1 : 0);

// ============================================================
// ヘルパー関数
// ============================================================

function ensureMigrationTable($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migration_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            checksum VARCHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by VARCHAR(100) DEFAULT NULL,
            execution_time_ms INT DEFAULT NULL,
            status ENUM('success', 'failed', 'rolled_back') NOT NULL DEFAULT 'success',
            notes TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function getAppliedMigrations($pdo) {
    $stmt = $pdo->query("SELECT filename, status, applied_at FROM migration_history WHERE status = 'success' ORDER BY applied_at");
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['filename']] = $row;
    }
    return $result;
}

function recordMigration($pdo, $filename, $checksum, $elapsed, $status, $notes = null) {
    // 失敗→再実行の場合、既存のfailedレコードを更新
    $stmt = $pdo->prepare("
        INSERT INTO migration_history (filename, checksum, applied_by, execution_time_ms, status, notes)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            checksum = VALUES(checksum),
            applied_at = CURRENT_TIMESTAMP,
            applied_by = VALUES(applied_by),
            execution_time_ms = VALUES(execution_time_ms),
            status = VALUES(status),
            notes = VALUES(notes)
    ");
    $stmt->execute([$filename, $checksum, php_uname('n'), $elapsed, $status, $notes]);
}

function showStatus($pdo) {
    echo "\n=== マイグレーション適用状況 ===\n\n";

    $stmt = $pdo->query("SELECT filename, status, applied_at, execution_time_ms FROM migration_history ORDER BY applied_at");
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo "  (適用済みマイグレーションなし)\n";
    } else {
        foreach ($rows as $row) {
            $icon = $row['status'] === 'success' ? 'OK' : 'NG';
            $ms   = $row['execution_time_ms'] ?? '-';
            echo "  [{$icon}] {$row['filename']}  ({$row['applied_at']}, {$ms}ms)\n";
        }
    }
    echo "\n";
}

function parseSqlStatements($sql) {
    // コメント除去してから ; で分割
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
        $clean[] = $line;
    }
    $joined = implode("\n", $clean);

    $statements = array_filter(
        array_map('trim', explode(';', $joined)),
        fn($s) => !empty($s) && !preg_match('/^\s*$/', $s)
    );
    return array_values($statements);
}
?>
