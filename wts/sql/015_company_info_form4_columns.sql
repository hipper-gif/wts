-- マイグレーション: 015_company_info_form4_columns.sql
-- 説明: 第4号様式（近畿運輸局 輸送実績報告書）出力に必要な事業者情報を追加
-- 作成日: 2026-05-04

ALTER TABLE company_info
  ADD COLUMN IF NOT EXISTS business_number VARCHAR(20) DEFAULT '' COMMENT '事業者番号（例: 261）',
  ADD COLUMN IF NOT EXISTS capital_thousand_yen INT DEFAULT 0 COMMENT '資本金（資金）千円単位',
  ADD COLUMN IF NOT EXISTS concurrent_business VARCHAR(100) DEFAULT '' COMMENT '兼営事業（"有"/"無し"または事業名）';
