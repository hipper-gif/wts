-- =================================================================
-- 顧客マスタ テーブル作成マイグレーション
--
-- ファイル: /sql/003_customer_master.sql
-- 作成日: 2026年3月11日
-- 説明: 顧客（利用者）マスタテーブルを新規作成し、
--       予約テーブルと紐付けることで顧客情報の一元管理を実現する。
--       従来は予約ごとに利用者名・電話番号等を手入力していたが、
--       マスタ化により入力効率と情報精度を向上させる。
-- 対象テーブル:
--   1. customers（顧客マスタ）- 新規作成
--   2. frequent_destinations（よく使う行き先）- 新規作成
--   3. reservations（予約管理メイン）- customer_id カラム追加
-- =================================================================

-- -----------------------------------------------------------------
-- 1. customers（顧客マスタ）
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '顧客名',
    name_kana VARCHAR(100) NULL COMMENT 'フリガナ（検索用）',
    phone VARCHAR(20) NULL COMMENT '電話番号',
    phone_secondary VARCHAR(20) NULL COMMENT '副電話番号',
    email VARCHAR(255) NULL COMMENT 'メールアドレス',
    postal_code VARCHAR(10) NULL COMMENT '郵便番号',
    address VARCHAR(255) NULL COMMENT '住所',
    address_detail VARCHAR(255) NULL COMMENT '建物名・部屋番号',
    care_level VARCHAR(50) NULL COMMENT '介護度（要支援1-2, 要介護1-5）',
    disability_type VARCHAR(100) NULL COMMENT '障害区分',
    mobility_type ENUM('independent','wheelchair','stretcher','walker') NOT NULL DEFAULT 'independent' COMMENT '移動形態',
    wheelchair_type VARCHAR(50) NULL COMMENT '車椅子タイプ',
    default_pickup_location VARCHAR(255) NULL COMMENT 'デフォルト乗車地',
    default_dropoff_location VARCHAR(255) NULL COMMENT 'デフォルト降車地',
    emergency_contact_name VARCHAR(100) NULL COMMENT '緊急連絡先名',
    emergency_contact_phone VARCHAR(20) NULL COMMENT '緊急連絡先電話番号',
    notes TEXT NULL COMMENT '特記事項（アレルギー・注意点等）',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ（0=無効/削除済み）',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    created_by INT NULL COMMENT '作成者ユーザーID',
    INDEX idx_customers_name_kana (name_kana),
    INDEX idx_customers_phone (phone),
    INDEX idx_customers_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='顧客（利用者）マスタ';

-- -----------------------------------------------------------------
-- 2. frequent_destinations（顧客別よく使う行き先）
--    顧客ごとに頻繁に利用する病院・透析施設・自宅等を登録し、
--    予約時の入力補助として使用する。
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS frequent_destinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL COMMENT '顧客ID',
    location_name VARCHAR(255) NOT NULL COMMENT '場所名（例: ○○病院、自宅）',
    address VARCHAR(255) NULL COMMENT '住所',
    location_type ENUM('hospital','dialysis','facility','home','other') NOT NULL DEFAULT 'other' COMMENT '場所種別',
    sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順（小さい順）',
    notes TEXT NULL COMMENT '備考（受付窓口・駐車場情報等）',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    INDEX idx_fd_customer_id (customer_id),
    INDEX idx_fd_customer_sort (customer_id, sort_order),
    CONSTRAINT fk_fd_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='顧客別よく使う行き先';

-- -----------------------------------------------------------------
-- 3. reservations テーブルに customer_id カラムを追加
--    既存データとの互換性を保つため NULL 許可とする。
--    新規予約では顧客マスタから選択、既存予約は段階的に紐付ける。
-- -----------------------------------------------------------------

-- カラムが存在しない場合のみ追加（MariaDB用のプロシージャ）
DELIMITER //

DROP PROCEDURE IF EXISTS add_customer_id_to_reservations//

CREATE PROCEDURE add_customer_id_to_reservations()
BEGIN
    -- customer_id カラムが存在するか確認
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reservations'
          AND COLUMN_NAME = 'customer_id'
    ) THEN
        -- カラム追加（client_name の直後に配置）
        ALTER TABLE reservations
            ADD COLUMN customer_id INT NULL COMMENT '顧客マスタID'
            AFTER client_name;

        -- インデックス追加
        ALTER TABLE reservations
            ADD INDEX idx_reservations_customer_id (customer_id);

        -- 外部キー追加
        ALTER TABLE reservations
            ADD CONSTRAINT fk_reservations_customer
                FOREIGN KEY (customer_id) REFERENCES customers(id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END//

DELIMITER ;

CALL add_customer_id_to_reservations();
DROP PROCEDURE IF EXISTS add_customer_id_to_reservations;
