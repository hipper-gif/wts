-- =====================================================
-- 022: 利用者の「入居先」（施設・病院に入居・入院中の人の居場所）
-- 目的: 施設の人を「施設＋名前」で1人に決める（HaiGO ADR-0006）。住所（自宅）は上書きしない
--       ＝ Smiley の顧客正本 Xenia に施設の住所が流れず、退所したら入居先を空にするだけで済む。
-- 値: location_master.id（場所マスタから選ぶ＝表記がぶれない）。NULL=入居先なし（自宅）
-- 実行日: 2026-09-25
-- 注意: 列を足すだけ。既存の値は変えない。FK は張らない（006 の MariaDB 構文問題を避ける）
-- =====================================================

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS residence_location_id INT NULL COMMENT '入居先 location_master.id（施設・病院。NULL=自宅）（HaiGO）',
    ADD INDEX IF NOT EXISTS idx_customers_residence (residence_location_id);
