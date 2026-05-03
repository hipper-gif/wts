-- マイグレーション: 019_company_info_form21_target_vehicles.sql
-- 説明: 第21号様式 Ⅱの「対象となる福祉タクシー車両」列を独立フィールドとして保存
-- 作成日: 2026-05-04

ALTER TABLE company_info
  ADD COLUMN IF NOT EXISTS form21_target_vehicles VARCHAR(200) DEFAULT '' COMMENT '第21号様式Ⅱ 対象となる福祉タクシー車両';
