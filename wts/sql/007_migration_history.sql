-- ============================================================
-- マイグレーション履歴管理テーブル
-- 適用済みSQLファイルを追跡し、二重実行を防止
-- ============================================================

CREATE TABLE IF NOT EXISTS migration_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    checksum VARCHAR(64) NOT NULL COMMENT 'SHA-256 of SQL file content',
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_by VARCHAR(100) DEFAULT NULL COMMENT 'Executor user or script',
    execution_time_ms INT DEFAULT NULL COMMENT 'Execution duration in ms',
    status ENUM('success', 'failed', 'rolled_back') NOT NULL DEFAULT 'success',
    notes TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='マイグレーション適用履歴';
