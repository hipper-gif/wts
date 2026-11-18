-- ============================================================
-- 028: 画面から消した記録の控えを残す表（deleted_records_archive）
-- 背景: データ管理の「サンプルデータ削除」は7表を物理削除し、控えも記録も残らなかった
--       （2026-11-18 全画面点検 C-4）。消す前に、消す行を1行ずつ JSON でここへ写す。
--       同じトランザクションの中で写すので、削除が途中で失敗すれば控えも残らない（半端にならない）。
-- 戻し方: row_json を元の表へ INSERT し直す（画面は無い。システム担当が行う）。
-- ============================================================

CREATE TABLE IF NOT EXISTS deleted_records_archive (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id VARCHAR(32) NOT NULL COMMENT '1回の削除操作の番号（同じ操作で消した行は同じ番号）',
  table_name VARCHAR(64) NOT NULL COMMENT '元の表',
  record_id INT NOT NULL COMMENT '元の行の id',
  row_json LONGTEXT NOT NULL COMMENT '消す直前の行（全列）',
  reason VARCHAR(100) NOT NULL COMMENT '消した操作（例: サンプルデータ削除）',
  deleted_by INT NULL COMMENT '操作したユーザーID',
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_archive_batch (batch_id),
  KEY idx_archive_table_record (table_name, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='画面から消した記録の控え（028）';
