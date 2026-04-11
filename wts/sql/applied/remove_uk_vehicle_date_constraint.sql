-- departure_recordsとarrival_recordsテーブルのuk_vehicle_date制約を削除
-- 同一車両が同一日に複数回出庫・入庫できるようにするための変更
-- （入庫済みの場合、再出庫・再入庫を許可する機能に対応）

-- 1. departure_recordsテーブルのユニークキー制約を削除
ALTER TABLE departure_records DROP INDEX uk_vehicle_date;

-- 2. arrival_recordsテーブルのユニークキー制約を削除
ALTER TABLE arrival_records DROP INDEX uk_vehicle_date;

-- 3. 変更の確認
SHOW INDEX FROM departure_records;
SHOW INDEX FROM arrival_records;

-- 説明:
-- uk_vehicle_date制約は(vehicle_id, date)の組み合わせを一意に強制していましたが、
-- 実際の業務では、同じ車両が同じ日に複数回出庫・入庫する（出庫→入庫→再出庫→再入庫）
-- ケースがあるため、両方のテーブルからこの制約を削除します。
--
-- アプリケーションレベルでは、以下の制御を行っています：
-- - departure.php 77-94行: 未入庫の出庫記録がある場合のみエラーとする
-- - arrival.php: 出庫記録に対応する入庫記録を作成
