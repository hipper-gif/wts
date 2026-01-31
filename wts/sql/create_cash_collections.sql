-- cash_collections テーブル作成
-- 集金処理の簡易チェック（完了/未完了）を管理するテーブル
-- 既存の cash_count_details（金種別カウント）とは独立して動作

CREATE TABLE IF NOT EXISTS cash_collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL,
    collection_date DATE NOT NULL,
    is_collected TINYINT(1) NOT NULL DEFAULT 1,
    collected_by INT NULL COMMENT '集金処理を実行したユーザーID',
    memo TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_driver_date (driver_id, collection_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
