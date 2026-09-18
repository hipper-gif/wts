-- ============================================================
-- WTS 新テナント初期データ（会社名が決まる前に流してよい部分だけ）
--
-- 000_schema.sql の直後に流す。会社固有の値は空のまま入れてあり、
-- provision_tenant.sh の Step 5/5.5 とヒアリング結果で後から上書きする。
--
--   mysql -u <user> -p <DB名> < 001_seed.sql
--
-- 冪等（何度流しても同じ結果）。既存行は壊さない。
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. company_info: id=1 の空行を必ず1件作る
--    provision_tenant.sh の Step 5.5 が「UPDATE ... WHERE id = 1」で
--    事業者情報を流し込むため、行が無いと黙って何も入らない。
-- ------------------------------------------------------------
INSERT INTO company_info (id, company_name, representative_name, postal_code,
                          address, phone, fax, manager_name, manager_email, license_number)
VALUES (1, '', '', '', '', '', '', '', '', '')
ON DUPLICATE KEY UPDATE id = id;

-- ------------------------------------------------------------
-- 2. system_settings: テナント共通の既定値
--    company_name / company_address / company_phone / theme_color は
--    会社固有のため空のまま。ヒアリング後に埋める。
-- ------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('system_name',           'スマルト'),
    ('system_version',        '1.0.0'),
    ('version',               '1.0.0'),
    ('timezone',              'Asia/Tokyo'),
    ('date_format',           'Y-m-d'),
    ('time_format',           'H:i'),
    ('currency',              'JPY'),
    ('business_hours_start',  '08:00'),
    ('business_hours_end',    '18:00'),
    ('session_timeout',       '28800'),
    ('auto_logout_minutes',   '480'),
    ('inspection_alert_days', '7'),
    ('auto_backup',           '1'),
    ('backup_retention_days', '30'),
    ('setup_completed',       '0'),
    ('setup_date',            ''),
    ('last_update',           ''),
    ('company_name',          ''),
    ('company_address',       ''),
    ('company_phone',         ''),
    ('theme_color',           '#00C896'),
    ('current_fiscal_year',   CAST(YEAR(CURDATE()) - IF(MONTH(CURDATE()) < 4, 1, 0) AS CHAR))
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ------------------------------------------------------------
-- 3. transport_categories: 輸送分類マスタ（全テナント共通の7分類）
--    Smiley・Lino 両テナントで同一内容であることを 2026-09-18 に確認済み。
-- ------------------------------------------------------------
INSERT INTO transport_categories (id, category_name, category_code, description, is_active, sort_order) VALUES
    (1, '通院',     'MED', NULL, 1, 1),
    (2, '外出等',   'OUT', NULL, 1, 2),
    (3, '入院',     'HOS', NULL, 1, 3),
    (4, '退院',     'DIS', NULL, 1, 4),
    (5, '転院',     'TRA', NULL, 1, 5),
    (6, '施設入所', 'ADM', NULL, 1, 6),
    (7, 'その他',   'OTH', NULL, 1, 7)
ON DUPLICATE KEY UPDATE id = id;

-- ------------------------------------------------------------
-- 4. fiscal_years: 当年度と前年度（4月始まり）
--    年度集計・運輸局提出帳票がこの行を前提にしている。
-- ------------------------------------------------------------
SET @fy = YEAR(CURDATE()) - IF(MONTH(CURDATE()) < 4, 1, 0);

INSERT INTO fiscal_years (fiscal_year, start_date, end_date, is_active)
VALUES (@fy - 1, MAKEDATE(@fy - 1, 1) + INTERVAL 3 MONTH, MAKEDATE(@fy, 1) + INTERVAL 3 MONTH - INTERVAL 1 DAY, 0)
ON DUPLICATE KEY UPDATE fiscal_year = fiscal_year;

INSERT INTO fiscal_years (fiscal_year, start_date, end_date, is_active)
VALUES (@fy, MAKEDATE(@fy, 1) + INTERVAL 3 MONTH, MAKEDATE(@fy + 1, 1) + INTERVAL 3 MONTH - INTERVAL 1 DAY, 1)
ON DUPLICATE KEY UPDATE fiscal_year = fiscal_year;

-- 確認用
SELECT 'company_info'          AS table_name, COUNT(*) AS rows_now FROM company_info
UNION ALL SELECT 'system_settings',      COUNT(*) FROM system_settings
UNION ALL SELECT 'transport_categories', COUNT(*) FROM transport_categories
UNION ALL SELECT 'fiscal_years',         COUNT(*) FROM fiscal_years;
