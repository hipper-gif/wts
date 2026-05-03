-- マイグレーション: 018_vehicles_accessibility_category.sql
-- 説明: 第21号様式の車両区分は排他（基準省令上、1台の車両は1区分にのみ該当）のため、
--       BOOLEAN 4つから ENUM 1つに統合する。UDT は車椅子対応のサブセットなので保持。
-- 作成日: 2026-05-04

ALTER TABLE vehicles
  ADD COLUMN IF NOT EXISTS accessibility_category
    ENUM('none','wheelchair','stretcher','combo','rotation')
    DEFAULT 'none'
    COMMENT '福祉車両区分（第21号様式 基準省令第45条 / wheelchair=車椅子のみ・stretcher=寝台のみ・combo=兼用・rotation=回転シート）';

-- 既存データの移行（旧BOOLEANカラムから値を判定して ENUM に集約）
-- 優先順位: combo > rotation > stretcher > wheelchair > none
UPDATE vehicles SET accessibility_category =
  CASE
    WHEN is_combo_vehicle = 1 THEN 'combo'
    WHEN is_rotation_seat = 1 THEN 'rotation'
    WHEN is_stretcher_compatible = 1 THEN 'stretcher'
    WHEN is_wheelchair_compatible = 1 THEN 'wheelchair'
    ELSE 'none'
  END
WHERE accessibility_category = 'none';

-- 旧カラム削除（is_universal_design_taxi は車椅子対応のサブセットフラグとして保持）
ALTER TABLE vehicles
  DROP COLUMN IF EXISTS is_wheelchair_compatible,
  DROP COLUMN IF EXISTS is_stretcher_compatible,
  DROP COLUMN IF EXISTS is_combo_vehicle,
  DROP COLUMN IF EXISTS is_rotation_seat;
