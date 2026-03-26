-- 苦情処理記録テーブル
-- 顧客からの苦情を受付から解決まで一元管理する

CREATE TABLE IF NOT EXISTS complaint_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    complaint_date DATE NOT NULL,
    complaint_time TIME,
    complainant_name VARCHAR(100) NOT NULL COMMENT '苦情申立者名',
    complainant_phone VARCHAR(20) COMMENT '連絡先',
    complaint_type ENUM('運転マナー', '接客態度', '遅刻・時間', '車両状態', '料金', 'その他') NOT NULL,
    severity ENUM('軽度', '中程度', '重度') DEFAULT '中程度',
    related_date DATE NULL COMMENT '事象発生日',
    driver_id INT NULL,
    vehicle_id INT NULL,
    description TEXT NOT NULL COMMENT '苦情内容',
    response TEXT COMMENT '対応内容',
    response_status ENUM('未対応', '対応中', '対応完了', '保留') DEFAULT '未対応',
    response_date DATE NULL COMMENT '対応完了日',
    handled_by INT NULL COMMENT '対応者',
    prevention_measures TEXT COMMENT '再発防止策',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (complaint_date),
    INDEX idx_status (response_status),
    INDEX idx_driver (driver_id),
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
