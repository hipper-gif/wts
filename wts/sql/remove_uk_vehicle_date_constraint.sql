-- departure_recordsテーブルのuk_vehicle_date制約を削除
-- 同一車両が同一日に複数回出庫できるようにするための変更
-- （入庫済みの場合、再出庫を許可する機能に対応）

-- 1. ユニークキー制約の削除
ALTER TABLE departure_records DROP INDEX uk_vehicle_date;

-- 2. 変更の確認
SHOW INDEX FROM departure_records;

-- 説明:
-- uk_vehicle_date制約は(vehicle_id, departure_date)の組み合わせを一意に強制していましたが、
-- 実際の業務では、同じ車両が同じ日に複数回出庫する（入庫→再出庫）ケースがあるため、
-- この制約を削除します。
--
-- アプリケーションレベルでは、departure.phpの77-94行で
-- 未入庫の出庫記録がある場合のみエラーとする制御を行っています。
