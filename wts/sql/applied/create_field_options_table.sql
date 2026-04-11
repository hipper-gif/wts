-- =================================================================
-- 予約項目カスタマイズ テーブル作成
--
-- ファイル: /sql/create_field_options_table.sql
-- 作成日: 2026年2月13日
-- 説明: 予約フォームのドロップダウン項目を管理するテーブル
-- =================================================================

CREATE TABLE IF NOT EXISTS reservation_field_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_name VARCHAR(50) NOT NULL COMMENT '対象フィールド名',
    option_value VARCHAR(100) NOT NULL COMMENT '選択肢の値',
    option_label VARCHAR(100) NOT NULL COMMENT '表示ラベル',
    sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_field_option (field_name, option_value),
    INDEX idx_field_active (field_name, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- デフォルト選択肢を投入
-- サービス種別
INSERT IGNORE INTO reservation_field_options (field_name, option_value, option_label, sort_order) VALUES
('service_type', 'お迎え', 'お迎え', 1),
('service_type', '送り', '送り', 2),
('service_type', '往復', '往復', 3),
('service_type', '病院', '病院', 4),
('service_type', '買い物', '買い物', 5),
('service_type', 'その他', 'その他', 99);

-- レンタルサービス
INSERT IGNORE INTO reservation_field_options (field_name, option_value, option_label, sort_order) VALUES
('rental_service', 'なし', 'なし', 0),
('rental_service', '車椅子', '車椅子', 1),
('rental_service', 'ストレッチャー', 'ストレッチャー', 2),
('rental_service', '酸素ボンベ', '酸素ボンベ', 3);

-- 紹介者種別
INSERT IGNORE INTO reservation_field_options (field_name, option_value, option_label, sort_order) VALUES
('referrer_type', 'CM', 'CM (ケアマネージャー)', 1),
('referrer_type', 'MSW', 'MSW (医療ソーシャルワーカー)', 2),
('referrer_type', '病院', '病院', 3),
('referrer_type', '施設', '施設', 4),
('referrer_type', '個人', '個人', 5),
('referrer_type', 'その他', 'その他', 99);

-- 支払い方法
INSERT IGNORE INTO reservation_field_options (field_name, option_value, option_label, sort_order) VALUES
('payment_method', '現金', '現金', 1),
('payment_method', 'クレジットカード', 'クレジットカード', 2),
('payment_method', '請求書', '請求書', 3),
('payment_method', '介護保険', '介護保険', 4);
