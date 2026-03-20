-- =================================================================
-- 場所マスタ テーブル作成マイグレーション
--
-- ファイル: /sql/005_location_master.sql
-- 作成日: 2026年3月20日
-- 説明: 場所マスタテーブルを新規作成し、顧客との紐付け中間テーブルを
--       作成する。従来は予約ごとに乗降車地をテキスト入力していたが、
--       場所マスタ化により入力補助・利用頻度の把握を実現する。
-- 対象テーブル:
--   1. location_master（場所マスタ）- 新規作成
--   2. customer_locations（顧客-場所 中間テーブル）- 新規作成
--   3. customers（顧客マスタ）- デフォルト乗降車地・担当ドライバーカラム追加
-- =================================================================

-- -----------------------------------------------------------------
-- 1. location_master（場所マスタ）
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS location_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT '場所名',
    name_kana VARCHAR(255) NULL COMMENT 'フリガナ',
    location_type ENUM('hospital','clinic','care_facility','home','station','pharmacy','other') NOT NULL DEFAULT 'other' COMMENT '種別',
    postal_code VARCHAR(10) NULL COMMENT '郵便番号',
    address VARCHAR(255) NULL COMMENT '住所',
    phone VARCHAR(20) NULL COMMENT '電話番号',
    notes TEXT NULL COMMENT '備考',
    usage_count INT NOT NULL DEFAULT 0 COMMENT '利用回数',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_type (location_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='場所マスタ';

-- -----------------------------------------------------------------
-- 2. customer_locations（顧客-場所 中間テーブル）
--    顧客と場所の紐付けを管理する。relationship_type により
--    自宅・よく使う場所・デフォルト乗車地・デフォルト降車地を区別する。
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    location_id INT NOT NULL,
    relationship_type ENUM('home','frequent','default_pickup','default_dropoff') NOT NULL DEFAULT 'frequent',
    visit_count INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_location (location_id),
    UNIQUE KEY uk_customer_location (customer_id, location_id, relationship_type),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES location_master(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='顧客-場所 紐付け';

-- -----------------------------------------------------------------
-- 3. customers テーブルにカラム追加
--    default_pickup_location_id / default_dropoff_location_id / assigned_driver_id
--    既存データとの互換性を保つため NULL 許可とする。
-- -----------------------------------------------------------------

DELIMITER //

DROP PROCEDURE IF EXISTS add_location_columns_to_customers//

CREATE PROCEDURE add_location_columns_to_customers()
BEGIN
    -- default_pickup_location_id カラムが存在するか確認
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'customers'
          AND COLUMN_NAME = 'default_pickup_location_id'
    ) THEN
        ALTER TABLE customers
            ADD COLUMN default_pickup_location_id INT NULL COMMENT 'デフォルト乗車地（場所マスタID）'
            AFTER default_dropoff_location;

        ALTER TABLE customers
            ADD INDEX idx_customers_pickup_location (default_pickup_location_id);

        ALTER TABLE customers
            ADD CONSTRAINT fk_customers_pickup_location
                FOREIGN KEY (default_pickup_location_id) REFERENCES location_master(id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    -- default_dropoff_location_id カラムが存在するか確認
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'customers'
          AND COLUMN_NAME = 'default_dropoff_location_id'
    ) THEN
        ALTER TABLE customers
            ADD COLUMN default_dropoff_location_id INT NULL COMMENT 'デフォルト降車地（場所マスタID）'
            AFTER default_pickup_location_id;

        ALTER TABLE customers
            ADD INDEX idx_customers_dropoff_location (default_dropoff_location_id);

        ALTER TABLE customers
            ADD CONSTRAINT fk_customers_dropoff_location
                FOREIGN KEY (default_dropoff_location_id) REFERENCES location_master(id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    -- assigned_driver_id カラムが存在するか確認
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'customers'
          AND COLUMN_NAME = 'assigned_driver_id'
    ) THEN
        ALTER TABLE customers
            ADD COLUMN assigned_driver_id INT NULL COMMENT '担当ドライバー（ユーザーID）'
            AFTER default_dropoff_location_id;

        ALTER TABLE customers
            ADD INDEX idx_customers_assigned_driver (assigned_driver_id);

        ALTER TABLE customers
            ADD CONSTRAINT fk_customers_assigned_driver
                FOREIGN KEY (assigned_driver_id) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END//

DELIMITER ;

CALL add_location_columns_to_customers();
DROP PROCEDURE IF EXISTS add_location_columns_to_customers;
