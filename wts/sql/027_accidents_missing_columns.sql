-- ============================================================
-- 027: 事故記録（accidents）に、事故管理画面と事故報告書が使う8列を足す
-- 背景: 013_accidents.sql は CREATE TABLE IF NOT EXISTS のため、先に別の形で作られていた accidents 表には
--       効かず、accident_time ほか8列が無いままだった。事故管理画面は一覧・統計・登録のすべてが SQL エラーで、
--       全テナントで開けなかった（2026-11-18 全画面点検 D・kinki で実描画して確認。事故記録は全テナント0件）。
-- 変更: 列の追加のみ（既存の列・行は変えない）。すでに列があるテナントでは何もしない（IF NOT EXISTS）。
-- ============================================================

ALTER TABLE accidents
  ADD COLUMN IF NOT EXISTS accident_time TIME NULL COMMENT '発生時刻' AFTER accident_date,
  ADD COLUMN IF NOT EXISTS weather VARCHAR(50) NULL COMMENT '天候' AFTER location,
  ADD COLUMN IF NOT EXISTS cause_analysis TEXT NULL COMMENT '原因分析' AFTER description,
  ADD COLUMN IF NOT EXISTS damage_amount INT NOT NULL DEFAULT 0 COMMENT '損害額（円）' AFTER property_damage,
  ADD COLUMN IF NOT EXISTS police_report_number VARCHAR(100) NULL COMMENT '警察受理番号' AFTER police_report,
  ADD COLUMN IF NOT EXISTS insurance_number VARCHAR(100) NULL COMMENT '保険事故番号' AFTER insurance_claim,
  ADD COLUMN IF NOT EXISTS status ENUM('発生','調査中','処理完了') NOT NULL DEFAULT '発生' COMMENT '処理状況' AFTER prevention_measures,
  ADD COLUMN IF NOT EXISTS created_by INT NULL COMMENT '登録したユーザーID' AFTER status;
