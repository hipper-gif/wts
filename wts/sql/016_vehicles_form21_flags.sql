-- マイグレーション: 016_vehicles_form21_flags.sql
-- 説明: 第21号様式（移動等円滑化実績等報告書 福祉タクシー車両）出力に必要な車両区分フラグを追加
-- 作成日: 2026-05-04

ALTER TABLE vehicles
  ADD COLUMN IF NOT EXISTS is_wheelchair_compatible TINYINT(1) DEFAULT 0 COMMENT '車椅子対応車（基準省令第45条第1項）',
  ADD COLUMN IF NOT EXISTS is_universal_design_taxi TINYINT(1) DEFAULT 0 COMMENT 'ユニバーサルデザインタクシー認定',
  ADD COLUMN IF NOT EXISTS is_stretcher_compatible TINYINT(1) DEFAULT 0 COMMENT '寝台対応車（基準省令第45条第1項）',
  ADD COLUMN IF NOT EXISTS is_combo_vehicle TINYINT(1) DEFAULT 0 COMMENT '兼用車（車椅子・寝台どちらも輸送可）',
  ADD COLUMN IF NOT EXISTS is_rotation_seat TINYINT(1) DEFAULT 0 COMMENT '回転シート車（基準省令第45条第2項）';
