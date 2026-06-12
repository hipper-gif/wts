-- 020: Remember Me 自動ログイントークン
-- Cookieには selector:validator を保存し、DBには validator のSHA-256ハッシュのみ保存する。
-- DB漏洩時もCookieを偽造できない（validator原文はDBに存在しない）。

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector CHAR(24) NOT NULL COMMENT 'Cookie照合用キー（平文）',
    validator_hash CHAR(64) NOT NULL COMMENT 'validatorのSHA-256ハッシュ',
    prev_validator_hash CHAR(64) DEFAULT NULL COMMENT 'ローテーション直前のハッシュ（並行リクエスト猶予用）',
    rotated_at DATETIME DEFAULT NULL COMMENT '最終ローテーション日時',
    expires_at DATETIME NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_selector (selector),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Remember Me 自動ログイントークン';
