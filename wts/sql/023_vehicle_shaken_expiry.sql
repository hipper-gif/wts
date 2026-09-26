-- =====================================================
-- 023: 車検の満了日（車検証「有効期間の満了する日」）
-- 目的: これまで車検の期限を入れる欄が無く、備考に書くしかなかった＝期限の警告に効かなかった。
--       「次回点検日（next_inspection_date）」は3ヶ月点検の日で、車検とは別物。
-- 使う場所: 車両管理（入力・一覧の色分け）／ダッシュボード（満了60日前から警告）
-- 実行日: 2026-09-26（杉原氏「車検満了日の欄を足す」）
-- 注意: 列を足すだけ。既存の値は変えない
-- =====================================================

ALTER TABLE vehicles
    ADD COLUMN IF NOT EXISTS shaken_expiry_date DATE NULL COMMENT '車検の満了日（車検証「有効期間の満了する日」）' AFTER next_inspection_date,
    ADD INDEX IF NOT EXISTS idx_vehicles_shaken_expiry (shaken_expiry_date);
