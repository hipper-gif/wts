-- マイグレーション: 017_company_info_extend.sql
-- 説明: company_info テーブルにFAX・運行管理者・メールアドレスカラムを追加
-- 作成日: 2026-04-08

ALTER TABLE company_info
    ADD COLUMN IF NOT EXISTS fax VARCHAR(20) DEFAULT '' AFTER phone;
ALTER TABLE company_info
    ADD COLUMN IF NOT EXISTS manager_name VARCHAR(100) DEFAULT '' AFTER fax;
ALTER TABLE company_info
    ADD COLUMN IF NOT EXISTS manager_email VARCHAR(200) DEFAULT '' AFTER manager_name;
