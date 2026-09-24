-- =====================================================
-- 021: 配車（HaiGO）の運転者設定を users に持つ
-- 目的: HaiGO の担当色・自動振り分けの優先順位・いつもの車・曜日シフトを
--       WTS のユーザー管理画面から入力できるようにする（HaiGO ADR-0005 / WTS ADR-0007）。
--       これまで HaiGO は dispatch_settings（JSON）に直接入れていて、他社テナントには渡す手段が無かった。
-- 実行日: 2026-09-24
-- 注意: 列を足すだけ。既存の値は変えない。FK は張らない（006 の MariaDB 構文問題を避ける）
-- =====================================================

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS dispatch_color VARCHAR(7) NULL COMMENT '配車ボードの担当色 #RRGGBB（HaiGO。NULL=自動）' AFTER is_mechanic,
    ADD COLUMN IF NOT EXISTS dispatch_priority INT NULL COMMENT '自動振り分けの優先順位（小さいほど先。NULL=順位なし・ID順）（HaiGO）' AFTER dispatch_color,
    ADD COLUMN IF NOT EXISTS default_vehicle_id INT NULL COMMENT 'いつもの担当車 vehicles.id（HaiGO。NULL=車両担当なし＝自動振り分けの対象外）' AFTER dispatch_priority,
    ADD COLUMN IF NOT EXISTS dispatch_shift TEXT NULL COMMENT '曜日シフト JSON {"0".."6":"main|sub|off","holiday":"off"}（HaiGO。0=日..6=土）' AFTER default_vehicle_id;
