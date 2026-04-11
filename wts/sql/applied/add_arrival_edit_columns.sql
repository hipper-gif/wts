-- 入庫記録編集機能用カラム追加
-- arrival_records テーブルに編集管理カラムを追加

-- 1. 編集管理カラムの追加
ALTER TABLE arrival_records ADD COLUMN (
    is_edited BOOLEAN DEFAULT FALSE COMMENT '編集済みフラグ',
    edit_reason VARCHAR(100) DEFAULT NULL COMMENT '修正理由',
    last_edited_by INT DEFAULT NULL COMMENT '最終編集者ID',
    last_edited_at TIMESTAMP NULL DEFAULT NULL COMMENT '最終編集日時'
);

-- 2. 外部キー制約の追加（編集者参照）
ALTER TABLE arrival_records 
ADD CONSTRAINT fk_arrival_last_edited_by 
FOREIGN KEY (last_edited_by) REFERENCES users(id) 
ON DELETE SET NULL ON UPDATE CASCADE;

-- 3. インデックスの追加（検索性能向上）
CREATE INDEX idx_arrival_edited ON arrival_records(is_edited);
CREATE INDEX idx_arrival_edit_date ON arrival_records(last_edited_at);
CREATE INDEX idx_arrival_editor ON arrival_records(last_edited_by);

-- 4. コメント追加
ALTER TABLE arrival_records 
MODIFY COLUMN arrival_date DATE NOT NULL COMMENT '入庫日',
MODIFY COLUMN arrival_time TIME NOT NULL COMMENT '入庫時刻',
MODIFY COLUMN arrival_mileage INT NOT NULL COMMENT '入庫時メーター(km)',
MODIFY COLUMN total_distance INT DEFAULT NULL COMMENT '走行距離(km)',
MODIFY COLUMN fuel_cost INT DEFAULT 0 COMMENT '燃料代(円)',
MODIFY COLUMN highway_cost INT DEFAULT 0 COMMENT '高速代(円)',
MODIFY COLUMN toll_cost INT DEFAULT 0 COMMENT '通行料(円)',
MODIFY COLUMN other_cost INT DEFAULT 0 COMMENT 'その他費用(円)',
MODIFY COLUMN remarks TEXT DEFAULT NULL COMMENT '備考';

-- 5. 確認用ビュー作成（編集履歴確認用）
CREATE VIEW arrival_edit_summary AS
SELECT 
    a.id,
    a.arrival_date,
    u1.name as driver_name,
    v.vehicle_number,
    a.arrival_mileage,
    a.total_distance,
    a.is_edited,
    a.edit_reason,
    u2.name as last_edited_by_name,
    a.last_edited_at,
    a.created_at,
    a.updated_at
FROM arrival_records a
JOIN users u1 ON a.driver_id = u1.id
JOIN vehicles v ON a.vehicle_id = v.id
LEFT JOIN users u2 ON a.last_edited_by = u2.id
ORDER BY a.arrival_date DESC, a.arrival_time DESC;

-- 6. 編集統計用クエリ（実行例）
/*
-- 編集済み記録の確認
SELECT 
    DATE(arrival_date) as date,
    COUNT(*) as total_records,
    SUM(CASE WHEN is_edited THEN 1 ELSE 0 END) as edited_records,
    ROUND(SUM(CASE WHEN is_edited THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as edit_rate
FROM arrival_records 
WHERE arrival_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY DATE(arrival_date)
ORDER BY date DESC;

-- 編集理由別統計
SELECT 
    edit_reason,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM arrival_records WHERE is_edited = TRUE), 2) as percentage
FROM arrival_records 
WHERE is_edited = TRUE AND edit_reason IS NOT NULL
GROUP BY edit_reason
ORDER BY count DESC;

-- ユーザー別編集回数
SELECT 
    u.name as editor_name,
    COUNT(*) as edit_count,
    MIN(a.last_edited_at) as first_edit,
    MAX(a.last_edited_at) as last_edit
FROM arrival_records a
JOIN users u ON a.last_edited_by = u.id
WHERE a.is_edited = TRUE
GROUP BY u.id, u.name
ORDER BY edit_count DESC;
*/
