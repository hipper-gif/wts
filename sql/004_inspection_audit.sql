-- =================================================================
-- 日常点検 監査証跡（Audit Trail）マイグレーション
--
-- ファイル: /sql/004_inspection_audit.sql
-- 作成日: 2026年3月12日
-- 説明: 日常点検データの編集履歴・監査証跡を管理する仕組みを構築する。
--       従来は編集時にデータが上書きされ、削除は物理削除であったため、
--       変更履歴が一切残らなかった。本マイグレーションにより：
--         - 全ての変更（作成・編集・削除・管理者解除）を記録
--         - 当日中の編集を許可し、日付が変わると自動ロック
--         - 管理者は理由入力によりロック解除・編集が可能
-- 対象テーブル:
--   1. inspection_audit_logs（点検監査ログ）- 新規作成
--   2. daily_inspections（日常点検）- ロック管理カラム追加
-- =================================================================

-- -----------------------------------------------------------------
-- 1. inspection_audit_logs（点検監査ログ）
--    日常点検レコードに対する全ての変更操作を記録する。
--    フィールド単位で変更前後の値を保持し、完全な監査証跡を実現する。
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspection_id INT NOT NULL COMMENT '対象の点検レコードID',
    action ENUM('create','edit','delete','admin_unlock') NOT NULL COMMENT '操作種別（作成/編集/削除/管理者ロック解除）',
    edited_by INT NOT NULL COMMENT '操作実行ユーザーID',
    edited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作日時',
    field_changed VARCHAR(100) NULL COMMENT '変更フィールド名（create/deleteの場合はNULL）',
    old_value TEXT NULL COMMENT '変更前の値',
    new_value TEXT NULL COMMENT '変更後の値',
    reason TEXT NULL COMMENT '変更理由（admin_unlock時・安全項目変更時は必須）',
    ip_address VARCHAR(45) NULL COMMENT '操作元IPアドレス（IPv6対応）',
    user_agent VARCHAR(255) NULL COMMENT '操作元ブラウザ情報',
    INDEX idx_audit_inspection_id (inspection_id),
    INDEX idx_audit_edited_by (edited_by),
    INDEX idx_audit_edited_at (edited_at),
    CONSTRAINT fk_audit_inspection
        FOREIGN KEY (inspection_id) REFERENCES daily_inspections(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='日常点検 監査証跡ログ';

-- -----------------------------------------------------------------
-- 2. daily_inspections テーブルにロック管理・編集追跡カラムを追加
--    既存データとの互換性を保つためデフォルト値を設定する。
--    既存レコードは未ロック（is_locked=0）として扱う。
-- -----------------------------------------------------------------

DELIMITER //

DROP PROCEDURE IF EXISTS add_audit_columns_to_daily_inspections//

CREATE PROCEDURE add_audit_columns_to_daily_inspections()
BEGIN
    -- is_locked カラム: レコードがロックされているか
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'daily_inspections'
          AND COLUMN_NAME = 'is_locked'
    ) THEN
        ALTER TABLE daily_inspections
            ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ロック状態（0=編集可, 1=ロック済）';
    END IF;

    -- locked_at カラム: ロックされた日時
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'daily_inspections'
          AND COLUMN_NAME = 'locked_at'
    ) THEN
        ALTER TABLE daily_inspections
            ADD COLUMN locked_at DATETIME NULL COMMENT 'ロック日時'
            AFTER is_locked;
    END IF;

    -- last_edited_by カラム: 最終編集者
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'daily_inspections'
          AND COLUMN_NAME = 'last_edited_by'
    ) THEN
        ALTER TABLE daily_inspections
            ADD COLUMN last_edited_by INT NULL COMMENT '最終編集者ユーザーID'
            AFTER locked_at;
    END IF;

    -- last_edited_at カラム: 最終編集日時
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'daily_inspections'
          AND COLUMN_NAME = 'last_edited_at'
    ) THEN
        ALTER TABLE daily_inspections
            ADD COLUMN last_edited_at DATETIME NULL COMMENT '最終編集日時'
            AFTER last_edited_by;
    END IF;

    -- edit_count カラム: 編集回数
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'daily_inspections'
          AND COLUMN_NAME = 'edit_count'
    ) THEN
        ALTER TABLE daily_inspections
            ADD COLUMN edit_count INT NOT NULL DEFAULT 0 COMMENT '編集回数'
            AFTER last_edited_at;
    END IF;
END//

DELIMITER ;

CALL add_audit_columns_to_daily_inspections();
DROP PROCEDURE IF EXISTS add_audit_columns_to_daily_inspections;

-- -----------------------------------------------------------------
-- 3. 自動ロック処理について
--    日付が変わった点検レコードの自動ロックはPHP側で制御する。
--    判定ロジック: inspection_date < CURDATE() の場合はロック扱い
--
--    【PHP側の実装方針】
--    - 点検レコード取得時に inspection_date < CURDATE() かつ
--      is_locked = 0 の場合、自動的に is_locked = 1 に更新する
--    - 管理者（admin）ロールのユーザーは reason（理由）を入力することで
--      ロックを解除し編集が可能（admin_unlock としてログに記録）
--    - DBのEVENTスケジューラによる一括ロックも将来的に検討可能だが、
--      現時点ではPHPアプリケーション側での制御を優先する
-- -----------------------------------------------------------------
