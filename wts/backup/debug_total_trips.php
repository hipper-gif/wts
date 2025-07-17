-- 最終的なtotal_trips修正SQL
-- phpMyAdminで実行してください

-- 1. 不足しているテーブルにtotal_tripsカラムを追加
ALTER TABLE ride_records ADD COLUMN total_trips INT DEFAULT 0;
ALTER TABLE daily_operations ADD COLUMN total_trips INT DEFAULT 0;
ALTER TABLE arrival_records ADD COLUMN total_trips INT DEFAULT 0;
ALTER TABLE departure_records ADD COLUMN total_trips INT DEFAULT 0;

-- 2. 必要に応じて他のテーブルにも追加
ALTER TABLE accidents ADD COLUMN total_trips INT DEFAULT 0;
ALTER TABLE daily_inspections ADD COLUMN total_trips INT DEFAULT 0;
ALTER TABLE pre_duty_calls ADD COLUMN total_trips INT DEFAULT 0;
ALTER TABLE post_duty_calls ADD COLUMN total_trips INT DEFAULT 0;

-- 3. ride_recordsテーブルのtotal_tripsを更新（各レコードは1回の乗車として計算）
UPDATE ride_records SET total_trips = 1;

-- 4. 統計データの整合性を確保
UPDATE users u 
SET total_trips = (
    SELECT COUNT(*) 
    FROM ride_records r 
    WHERE r.driver_id = u.id
);

UPDATE vehicles v 
SET total_trips = (
    SELECT COUNT(*) 
    FROM ride_records r 
    WHERE r.vehicle_id = v.id
);

-- 5. 今日の統計を更新
INSERT INTO statistics (stat_date, total_trips, total_passengers, total_revenue)
SELECT 
    CURDATE() as stat_date,
    COUNT(*) as total_trips,
    SUM(passenger_count) as total_passengers,
    SUM(fare + COALESCE(charge, 0)) as total_revenue
FROM ride_records 
WHERE DATE(ride_date) = CURDATE()
ON DUPLICATE KEY UPDATE 
    total_trips = VALUES(total_trips),
    total_passengers = VALUES(total_passengers),
    total_revenue = VALUES(total_revenue);

-- 完了確認
SELECT 'すべてのテーブルにtotal_tripsカラムを追加完了' AS status;