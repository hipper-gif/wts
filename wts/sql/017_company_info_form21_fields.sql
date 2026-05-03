-- マイグレーション: 017_company_info_form21_fields.sql
-- 説明: 第21号様式（福祉タクシー車両）の前年度数値・計画内容を保存するため company_info を拡張
-- 作成日: 2026-05-04

ALTER TABLE company_info
  ADD COLUMN IF NOT EXISTS form21_prev_total INT DEFAULT 0 COMMENT '第21号様式 前年度車両数 計',
  ADD COLUMN IF NOT EXISTS form21_prev_wheelchair INT DEFAULT 0 COMMENT '第21号様式 前年度 車椅子対応車数',
  ADD COLUMN IF NOT EXISTS form21_prev_udt INT DEFAULT 0 COMMENT '第21号様式 前年度 UDT認定車数',
  ADD COLUMN IF NOT EXISTS form21_prev_stretcher INT DEFAULT 0 COMMENT '第21号様式 前年度 寝台対応車数',
  ADD COLUMN IF NOT EXISTS form21_prev_combo INT DEFAULT 0 COMMENT '第21号様式 前年度 兼用車数',
  ADD COLUMN IF NOT EXISTS form21_prev_rotation INT DEFAULT 0 COMMENT '第21号様式 前年度 回転シート車数',
  ADD COLUMN IF NOT EXISTS form21_plan_content TEXT COMMENT '第21号様式 計画内容（計画対象期間及び事業の主な内容）',
  ADD COLUMN IF NOT EXISTS form21_change_content TEXT COMMENT '第21号様式 前年度の計画からの変更内容';
