-- 指導監督記録テーブル
-- 乗務員への安全運転指導・教育の記録を管理

CREATE TABLE IF NOT EXISTS supervision_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    supervision_date DATE NOT NULL,
    supervision_type ENUM('初任教育', '適齢教育', '安全運転指導', '接遇マナー', '車椅子操作', '応急救護', 'その他') NOT NULL,
    driver_id INT NOT NULL,
    supervisor_id INT NOT NULL,
    duration_minutes INT DEFAULT 60 COMMENT '指導時間（分）',
    subject VARCHAR(255) NOT NULL COMMENT '指導テーマ',
    content TEXT NOT NULL COMMENT '指導内容',
    result ENUM('良好', '概ね良好', '要改善', '要再指導') DEFAULT '良好',
    follow_up_date DATE NULL COMMENT 'フォローアップ予定日',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (supervision_date),
    INDEX idx_driver (driver_id),
    INDEX idx_type (supervision_type),
    FOREIGN KEY (driver_id) REFERENCES users(id),
    FOREIGN KEY (supervisor_id) REFERENCES users(id)
);
