-- 既存の NULL ride_time レコードを修正
-- ride_time が NULL のレコードに対して、同じ日の平均時刻または '12:00' をデフォルト値として設定

UPDATE ride_records
SET ride_time = COALESCE(
    (SELECT TIME_FORMAT(SEC_TO_TIME(AVG(TIME_TO_SEC(ride_time))), '%H:%i')
     FROM (SELECT ride_time FROM ride_records WHERE ride_time IS NOT NULL LIMIT 1000) as temp),
    '12:00'
)
WHERE ride_time IS NULL OR ride_time = '';

-- 念のため、ride_time が空文字列の場合も修正
UPDATE ride_records
SET ride_time = '12:00'
WHERE ride_time = '';
