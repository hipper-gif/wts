<?php
/**
 * 一時マイグレーションスクリプト: 015_company_info_form4_columns.sql
 *
 * 実行後はこのファイルを削除すること（次のデプロイで自動的に消える）。
 * Admin限定。
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

if ($user_role !== 'Admin') {
    http_response_code(403);
    exit('Admin限定');
}

header('Content-Type: text/plain; charset=UTF-8');

$pdo = getDBConnection();

echo "=== company_info テーブル拡張マイグレーション ===\n\n";

// 1. 現状確認
$stmt = $pdo->query("SHOW COLUMNS FROM company_info");
$existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
echo "現在のカラム: " . implode(', ', $existing) . "\n\n";

$to_add = [
    'business_number' => "ALTER TABLE company_info ADD COLUMN business_number VARCHAR(20) DEFAULT '' COMMENT '事業者番号'",
    'capital_thousand_yen' => "ALTER TABLE company_info ADD COLUMN capital_thousand_yen INT DEFAULT 0 COMMENT '資本金千円'",
    'concurrent_business' => "ALTER TABLE company_info ADD COLUMN concurrent_business VARCHAR(100) DEFAULT '' COMMENT '兼営事業'",
];

foreach ($to_add as $col => $sql) {
    if (in_array($col, $existing, true)) {
        echo "[SKIP] $col は既に存在\n";
        continue;
    }
    try {
        $pdo->exec($sql);
        echo "[ADD ] $col 追加成功\n";
    } catch (Exception $e) {
        echo "[ERR ] $col: " . $e->getMessage() . "\n";
    }
}

echo "\n=== 実行後カラム ===\n";
$stmt = $pdo->query("DESCRIBE company_info");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    printf("  %-25s %-20s %s\n", $row['Field'], $row['Type'], $row['Comment'] ?? '');
}

echo "\n完了。このファイル(migrate_form4.php)はサーバから削除してください。\n";
