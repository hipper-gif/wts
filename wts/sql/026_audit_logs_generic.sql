-- ============================================================
-- 026: 監査ログを点検専用から全レコード種別の汎用表にする（Issue #78・ADR-0010）
-- 背景: logAudit() は arrival/departure/ride_record/… 全種別を inspection_audit_logs に入れるが、
--       inspection_id の外部キーが daily_inspections(id) 固定のため、点検以外は id が偶然一致した時しか
--       記録されず（1452 で静かに失敗）、入庫・出庫・乗車記録の履歴が一度も残っていなかった（2026-04〜10 実査）。
-- 変更: ①外部キーを外す ②record_type 列を足す ③既存行の種別を field_changed から復元 ④種別+IDの索引
-- 既存のテナントにも同じ順で適用する（run_migration.php）。新テナントは tenant_base/000_schema.sql が同じ形。
-- ============================================================

-- 変える前の控え（同じDB内。確認後に落としてよい）
CREATE TABLE IF NOT EXISTS inspection_audit_logs_bak_20261026 AS SELECT * FROM inspection_audit_logs;

ALTER TABLE inspection_audit_logs
  DROP FOREIGN KEY fk_audit_inspection;

ALTER TABLE inspection_audit_logs
  ADD COLUMN record_type VARCHAR(30) NULL
    COMMENT 'レコード種別（inspection/arrival/departure/ride_record/pre_duty_call/post_duty_call/location/vehicle/cash/…）'
    AFTER inspection_id,
  MODIFY COLUMN inspection_id INT(11) NOT NULL COMMENT '対象レコードID（record_type の表の id。旧名のまま）',
  COMMENT = '監査証跡ログ（全レコード種別・026 で汎用化）';

-- 既存行の種別を復元（変更明細なしの行は field_changed に種別が入っている）
UPDATE inspection_audit_logs
   SET record_type = field_changed
 WHERE record_type IS NULL
   AND field_changed IN ('inspection','arrival','departure','ride_record','pre_duty_call','post_duty_call',
                         'location','vehicle','cash','annual_report','periodic_inspection',
                         'company_settings','user_management');
-- 明細付きの編集行（field_changed=項目名）のうち、項目名から種別が確定できるもの
UPDATE inspection_audit_logs SET record_type = 'inspection'
 WHERE record_type IS NULL AND field_changed IN ('inspection_time');
UPDATE inspection_audit_logs SET record_type = 'vehicle'
 WHERE record_type IS NULL AND field_changed IN ('vehicle_number','mileage');

ALTER TABLE inspection_audit_logs
  ADD INDEX idx_audit_type_record (record_type, inspection_id);
