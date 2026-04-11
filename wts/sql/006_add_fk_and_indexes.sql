-- =====================================================
-- 006: 外部キー制約とインデックスの追加
-- 目的: データ整合性の強化とクエリパフォーマンス改善
-- 実行日: 2026-03-23
-- =====================================================

-- departure_records に複合インデックス追加
CREATE INDEX IF NOT EXISTS idx_departure_date_driver ON departure_records (departure_date, driver_id);
CREATE INDEX IF NOT EXISTS idx_departure_vehicle_date ON departure_records (vehicle_id, departure_date);

-- arrival_records に複合インデックス追加
CREATE INDEX IF NOT EXISTS idx_arrival_date_driver ON arrival_records (arrival_date, driver_id);
CREATE INDEX IF NOT EXISTS idx_arrival_departure_record ON arrival_records (departure_record_id);

-- 外部キー制約: departure_records → users, vehicles
-- MariaDB: ADD CONSTRAINT IF NOT EXISTS は10.5.2+
ALTER TABLE departure_records
    ADD CONSTRAINT IF NOT EXISTS fk_departure_driver FOREIGN KEY (driver_id)
        REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE departure_records
    ADD CONSTRAINT IF NOT EXISTS fk_departure_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- 外部キー制約: arrival_records → departure_records, users, vehicles
ALTER TABLE arrival_records
    ADD CONSTRAINT IF NOT EXISTS fk_arrival_departure FOREIGN KEY (departure_record_id)
        REFERENCES departure_records(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE arrival_records
    ADD CONSTRAINT IF NOT EXISTS fk_arrival_driver FOREIGN KEY (driver_id)
        REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE arrival_records
    ADD CONSTRAINT IF NOT EXISTS fk_arrival_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id) ON DELETE RESTRICT ON UPDATE CASCADE;
