-- マイグレーション: 013_accidents.sql
-- 説明: 事故記録テーブルの作成
-- 作成日: 2026-04-04

CREATE TABLE IF NOT EXISTS accidents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    accident_date DATE NOT NULL,
    accident_time TIME,
    vehicle_id INT NOT NULL,
    driver_id INT NOT NULL,
    accident_type ENUM('交通事故', '重大事故', 'ヒヤリハット', 'その他') NOT NULL,
    location VARCHAR(255),
    weather VARCHAR(50),
    description TEXT,
    cause_analysis TEXT,
    deaths INT DEFAULT 0,
    injuries INT DEFAULT 0,
    property_damage BOOLEAN DEFAULT FALSE,
    damage_amount INT DEFAULT 0,
    police_report BOOLEAN DEFAULT FALSE,
    police_report_number VARCHAR(100),
    insurance_claim BOOLEAN DEFAULT FALSE,
    insurance_number VARCHAR(100),
    prevention_measures TEXT,
    status ENUM('発生', '調査中', '処理完了') DEFAULT '発生',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_accident_date (accident_date),
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_driver_id (driver_id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    FOREIGN KEY (driver_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
