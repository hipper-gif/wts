-- 輸送分類マスタテーブル
-- 乗車記録の輸送目的（通院・外出等）をDB管理化

CREATE TABLE IF NOT EXISTS transport_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL,
    category_code VARCHAR(10) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- デフォルト分類データ
INSERT IGNORE INTO transport_categories (category_name, category_code, sort_order) VALUES
    ('通院', 'MED', 1),
    ('外出等', 'OUT', 2),
    ('入院', 'ADM', 3),
    ('退院', 'DIS', 4),
    ('転院', 'TRF', 5),
    ('施設入所', 'FAC', 6),
    ('その他', 'OTH', 7);
