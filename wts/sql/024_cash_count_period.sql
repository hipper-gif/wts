-- =====================================================
-- 024: 現金カウントを複数日まとめて登録できるようにする
-- 目的: 毎日入金せず数日分をまとめて数える運用に対応する。
--       confirmation_date は「期間の最終日」のまま（一意キー unique_date_driver も据え置き）。
--       period_start_date が NULL なら従来どおり1日分（= confirmation_date の日だけ）。
-- 使う場所: 現金カウント（driver_cash_count.php）／売上金確認の履歴／ワークフローの完了判定
-- 実行日: 2026-10-07（杉原氏「現金カウントを複数日の合計でもできるように」）
-- 注意: 列を足すだけ。既存の値は変えない
-- =====================================================

ALTER TABLE cash_count_details
    ADD COLUMN IF NOT EXISTS period_start_date DATE NULL COMMENT 'まとめて数えた期間の開始日（NULL=confirmation_dateの1日分）' AFTER confirmation_date;
