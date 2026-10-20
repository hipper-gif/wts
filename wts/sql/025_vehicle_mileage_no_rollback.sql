-- =====================================================
-- 025: 入庫の登録・修正で車両の現在メーターが巻き戻らないようにする
-- 目的: arrival_records のトリガーが日付を見ずに current_mileage = 入庫メーター で上書きしていたため、
--       過去日の入庫を事後登録・修正すると、その後の運行で進んだメーターが古い値に戻っていた
--       （2026-10-20 発見: 車両2 83427→83401、車両4 46072→46043）。
--       大きい方を残す（GREATEST）。走行距離の累計(total_distance)の足し方は従来どおり。
-- 注意: run_migration.php は ; で文を区切るため、トリガー本体は BEGIN…END を使わず1文で書く
-- 実行日: 2026-10-20（杉原氏「根本的な改善をしてほしい」）
-- =====================================================

DROP TRIGGER IF EXISTS update_vehicle_mileage_on_arrival;

CREATE TRIGGER update_vehicle_mileage_on_arrival
    AFTER INSERT ON arrival_records
    FOR EACH ROW
    UPDATE vehicles
    SET current_mileage = GREATEST(COALESCE(current_mileage, 0), NEW.arrival_mileage),
        total_distance = total_distance + (NEW.arrival_mileage - IFNULL((SELECT departure_mileage FROM departure_records WHERE id = NEW.departure_record_id), 0)),
        updated_at = NOW()
    WHERE id = NEW.vehicle_id;

DROP TRIGGER IF EXISTS update_vehicle_mileage_on_arrival_update;

CREATE TRIGGER update_vehicle_mileage_on_arrival_update
    AFTER UPDATE ON arrival_records
    FOR EACH ROW
    UPDATE vehicles
    SET current_mileage = GREATEST(COALESCE(current_mileage, 0), NEW.arrival_mileage),
        total_distance = total_distance
            - (OLD.arrival_mileage - IFNULL((SELECT departure_mileage FROM departure_records WHERE id = OLD.departure_record_id), 0))
            + (NEW.arrival_mileage - IFNULL((SELECT departure_mileage FROM departure_records WHERE id = NEW.departure_record_id), 0)),
        updated_at = NOW()
    WHERE id = NEW.vehicle_id;
