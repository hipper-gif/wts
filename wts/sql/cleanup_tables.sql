-- =============================================================================
-- cleanup_tables.sql
--
-- Drops unnecessary database tables, views, and sample data.
-- These have been identified as old backups, empty/redundant tables,
-- or unused views that are safe to remove.
--
-- Run this script against the production database after confirming
-- that no active code references these objects.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. BACKUP TABLES
--    Old backup copies of production tables. The originals still exist,
--    so these snapshots are no longer needed.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS ride_records_backup;
DROP TABLE IF EXISTS ride_records_backup_20250808;
DROP TABLE IF EXISTS ride_records_backup_20250905_before_optimization;
DROP TABLE IF EXISTS ride_records_backup_before_cleanup;
DROP TABLE IF EXISTS ride_records_full_backup_20250905;
DROP TABLE IF EXISTS users_backup_20250906_safe_optimization;

-- -----------------------------------------------------------------------------
-- 2. LEGACY / DUPLICATE TABLES
--    Empty or redundant tables that are not referenced by application code.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS daily_operations;           -- 0 rows, unused
DROP TABLE IF EXISTS detailed_cash_confirmations; -- 0 rows, duplicate of cash_count_details
DROP TABLE IF EXISTS statistics;                  -- 1 row, unused

-- -----------------------------------------------------------------------------
-- 3. UNUSED VIEWS
--    Views that are not referenced by any application code or reports.
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS v_periodic_inspection_summary;
DROP VIEW IF EXISTS v_reservations_to_rides;

-- -----------------------------------------------------------------------------
-- 4. CLEAN UP SAMPLE DATA
--    Remove the single sample/test row from periodic_inspections.
-- -----------------------------------------------------------------------------
DELETE FROM periodic_inspections WHERE is_sample_data = 1;

-- -----------------------------------------------------------------------------
-- 5. TABLES REQUIRING MANUAL REVIEW — DO NOT DROP AUTOMATICALLY
--
--    The following tables contain a small amount of data that may be legitimate.
--    Verify with stakeholders before removing.
--
--    - cash_confirmations  (11 rows — might hold real data, similar to cash_management)
--    - user_roles          (4 rows  — might be referenced by code not yet detected)
-- -----------------------------------------------------------------------------
