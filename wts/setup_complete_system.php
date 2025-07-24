<?php
/**
 * 福祉輸送管理システム - 完全版セットアップスクリプト
 * 
 * このスクリプトは以下を実行します：
 * 1. 必要なテーブルの作成・更新
 * 2. 集金管理機能のテーブル作成
 * 3. 陸運局提出機能のテーブル作成
 * 4. 事故管理機能のテーブル作成
 * 5. データベース整合性チェック
 * 6. サンプルデータ投入（オプション）
 */

session_start();

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
            INDEX idx_confirmation_date (confirmation_date),
            FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf
