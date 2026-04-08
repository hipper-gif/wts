-- マイグレーション: 014_annual_report_tables.sql
-- 説明: 事業者情報・年度マスタ・陸運局提出記録テーブルの作成
-- 作成日: 2026-04-04

-- 事業者情報テーブル
CREATE TABLE IF NOT EXISTS company_info (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_name VARCHAR(200) NOT NULL DEFAULT '',
    company_kana VARCHAR(200) DEFAULT '',
    representative_name VARCHAR(100) DEFAULT '',
    postal_code VARCHAR(10) DEFAULT '',
    address VARCHAR(300) DEFAULT '',
    phone VARCHAR(20) DEFAULT '',
    fax VARCHAR(20) DEFAULT '',
    manager_name VARCHAR(100) DEFAULT '',
    manager_email VARCHAR(200) DEFAULT '',
    license_number VARCHAR(50) DEFAULT '',
    business_type VARCHAR(100) DEFAULT '一般乗用旅客自動車運送事業（福祉）',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- デフォルトの事業者レコードを挿入（既存なら無視）
-- 会社情報はプロビジョニング時またはUI画面から設定する
INSERT IGNORE INTO company_info (id) VALUES (1);

-- 年度マスタテーブル
CREATE TABLE IF NOT EXISTS fiscal_years (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fiscal_year INT NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fiscal_year (fiscal_year)
);

-- 陸運局提出記録テーブル
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
    FOREIGN KEY (submitted_by) REFERENCES users(id)
);
