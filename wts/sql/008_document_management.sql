-- ============================================================
-- 書類管理テーブル
-- 許可証・保険・車検証等のファイル管理+有効期限アラート
-- ============================================================

CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- ファイル情報
    original_filename VARCHAR(255) NOT NULL COMMENT '元ファイル名',
    stored_filename VARCHAR(255) NOT NULL COMMENT '保存時ファイル名(UUID)',
    file_path VARCHAR(500) NOT NULL COMMENT 'uploads/からの相対パス',
    file_size INT NOT NULL COMMENT 'バイト数',
    mime_type VARCHAR(100) NOT NULL COMMENT 'MIMEタイプ',

    -- 分類・メタデータ
    category ENUM(
        'license',
        'insurance',
        'vehicle',
        'driver',
        'contract',
        'report',
        'other'
    ) NOT NULL DEFAULT 'other' COMMENT 'カテゴリ',

    title VARCHAR(255) NOT NULL COMMENT '書類タイトル',
    description TEXT NULL COMMENT 'メモ・説明',

    -- 有効期限
    expiry_date DATE NULL COMMENT '有効期限',

    -- 関連エンティティ
    related_driver_id INT NULL COMMENT '関連する乗務員',
    related_vehicle_id INT NULL COMMENT '関連する車両',

    -- 管理
    uploaded_by INT NOT NULL COMMENT 'アップロードしたユーザーID',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_category (category),
    INDEX idx_expiry (is_active, expiry_date),
    INDEX idx_driver (related_driver_id),
    INDEX idx_vehicle (related_vehicle_id),

    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (related_driver_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (related_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='書類管理';
