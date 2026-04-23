-- 院内介助ログテーブル
-- 院内介助の記録を管理

CREATE TABLE IF NOT EXISTS hospital_assistance_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assistance_date DATE NOT NULL,
    assistance_time TIME NULL,
    customer_name VARCHAR(100) NOT NULL,
    staff_id INT NOT NULL,
    facility_name VARCHAR(200) NOT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (assistance_date),
    INDEX idx_staff (staff_id),
    FOREIGN KEY (staff_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
