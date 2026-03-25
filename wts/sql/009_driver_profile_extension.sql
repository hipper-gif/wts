-- ============================================================
-- 乗務員台帳拡張: usersテーブルへの台帳カラム追加
-- 対象: 運転者の詳細プロフィール管理
-- ============================================================

ALTER TABLE users
    -- 基本情報
    ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL COMMENT '生年月日' AFTER email,
    ADD COLUMN IF NOT EXISTS hire_date DATE NULL COMMENT '入社日' AFTER date_of_birth,
    ADD COLUMN IF NOT EXISTS address TEXT NULL COMMENT '住所' AFTER hire_date,
    ADD COLUMN IF NOT EXISTS emergency_contact VARCHAR(255) NULL COMMENT '緊急連絡先（氏名・電話番号）' AFTER address,

    -- 運転免許情報
    ADD COLUMN IF NOT EXISTS driver_license_number VARCHAR(20) NULL COMMENT '運転免許証番号' AFTER emergency_contact,
    ADD COLUMN IF NOT EXISTS driver_license_type VARCHAR(100) NULL COMMENT '免許種別（普通二種等）' AFTER driver_license_number,
    ADD COLUMN IF NOT EXISTS driver_license_expiry DATE NULL COMMENT '運転免許有効期限' AFTER driver_license_type,

    -- 介護資格情報
    ADD COLUMN IF NOT EXISTS care_qualification VARCHAR(100) NULL COMMENT '介護資格名' AFTER driver_license_expiry,
    ADD COLUMN IF NOT EXISTS care_qualification_date DATE NULL COMMENT '介護資格取得日' AFTER care_qualification,

    -- 健康診断
    ADD COLUMN IF NOT EXISTS health_check_date DATE NULL COMMENT '直近健康診断日' AFTER care_qualification_date,
    ADD COLUMN IF NOT EXISTS health_check_next DATE NULL COMMENT '次回健康診断予定日' AFTER health_check_date,

    -- 適性診断
    ADD COLUMN IF NOT EXISTS aptitude_test_date DATE NULL COMMENT '直近適性診断日' AFTER health_check_next,
    ADD COLUMN IF NOT EXISTS aptitude_test_next DATE NULL COMMENT '次回適性診断予定日' AFTER aptitude_test_date,

    -- 写真
    ADD COLUMN IF NOT EXISTS photo_path VARCHAR(500) NULL COMMENT '顔写真パス（uploads/からの相対）' AFTER aptitude_test_next,

    -- 備考
    ADD COLUMN IF NOT EXISTS notes TEXT NULL COMMENT '備考・メモ' AFTER photo_path;

-- 免許有効期限・診断期限の検索用インデックス
ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_license_expiry (driver_license_expiry),
    ADD INDEX IF NOT EXISTS idx_health_check_next (health_check_next),
    ADD INDEX IF NOT EXISTS idx_aptitude_test_next (aptitude_test_next);
