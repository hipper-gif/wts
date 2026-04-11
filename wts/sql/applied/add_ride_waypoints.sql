-- 乗車記録の経由地テーブル
CREATE TABLE IF NOT EXISTS ride_waypoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ride_record_id INT NOT NULL,
    stop_order INT NOT NULL DEFAULT 1,
    location VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ride_record (ride_record_id),
    FOREIGN KEY (ride_record_id) REFERENCES ride_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
