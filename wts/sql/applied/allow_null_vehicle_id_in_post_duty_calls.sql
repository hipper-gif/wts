-- 乗務後点呼テーブルの vehicle_id カラムを NULL 許容に変更
-- 出庫記録がなくても乗務後点呼を実施できるようにするための変更

-- post_duty_calls テーブルの vehicle_id カラムを NULL 許容に変更
ALTER TABLE post_duty_calls
MODIFY COLUMN vehicle_id INT NULL
COMMENT '車両ID（出庫記録がない場合は NULL）';

-- 変更の確認
DESCRIBE post_duty_calls;
