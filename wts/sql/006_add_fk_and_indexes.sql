-- =====================================================
-- 006: 外部キー制約とインデックスの追加
-- 目的: データ整合性の強化とクエリパフォーマンス改善
-- 実行日: 2026-03-23
-- =====================================================

-- departure_records に複合インデックス追加
ALTER TABLE departure_records
    ADD INDEX idx_departure_date_driver (departure_date, driver_id),
    ADD INDEX idx_departure_vehicle_date (vehicle_id, departure_date);

-- arrival_records に複合インデックス追加
ALTER TABLE arrival_records
    ADD INDEX idx_arrival_date_driver (arrival_date, driver_id),
    ADD INDEX idx_arrival_departure_record (departure_record_id);

-- 外部キー制約: departure_records → users, vehicles
ALTER TABLE departure_records
    ADD CONSTRAINT fk_departure_driver FOREIGN KEY (driver_id)
        REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_departure_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- 外部キー制約: arrival_records → departure_records
ALTER TABLE arrival_records
    ADD CONSTRAINT fk_arrival_departure FOREIGN KEY (departure_record_id)
        REFERENCES departure_records(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- 外部キー制約: arrival_records → users, vehicles
ALTER TABLE arrival_records
    ADD CONSTRAINT fk_arrival_driver FOREIGN KEY (driver_id)
        REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_arrival_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id) ON DELETE RESTRICT ON UPDATE CASCADE;
