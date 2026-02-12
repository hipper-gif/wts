-- =================================================================
-- カレンダー予約管理システム テーブル作成マイグレーション
--
-- ファイル: /sql/create_calendar_tables.sql
-- 作成日: 2026年2月12日
-- 説明: 予約管理カレンダーに必要な4テーブルを作成
--       calendar/install.php で手動作成されていた定義をSQL化
-- 対象テーブル:
--   1. reservations（予約管理メイン）
--   2. partner_companies（協力会社管理）
--   3. frequent_locations（よく使う場所）
--   4. calendar_audit_logs（操作ログ）
-- =================================================================

-- -----------------------------------------------------------------
-- 1. reservations（予約管理メイン）
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_date DATE NOT NULL COMMENT '予約日',
    reservation_time TIME NOT NULL COMMENT '予約時刻',
    client_name VARCHAR(100) NOT NULL COMMENT '利用者名',
    pickup_location VARCHAR(255) NOT NULL COMMENT '乗車地',
    dropoff_location VARCHAR(255) NOT NULL COMMENT '降車地',
    passenger_count INT NOT NULL DEFAULT 1 COMMENT '乗客数',
    driver_id INT NULL COMMENT '担当運転者ID',
    vehicle_id INT NULL COMMENT '使用車両ID',
    service_type VARCHAR(50) NOT NULL COMMENT 'サービス種別（お迎え/送り等）',
    is_time_critical TINYINT(1) NOT NULL DEFAULT 0 COMMENT '時間厳守フラグ',
    rental_service VARCHAR(50) NOT NULL DEFAULT 'なし' COMMENT 'レンタルサービス',
    entrance_assistance TINYINT(1) NOT NULL DEFAULT 0 COMMENT '玄関介助',
    disability_card TINYINT(1) NOT NULL DEFAULT 0 COMMENT '障害者手帳',
    care_service_user TINYINT(1) NOT NULL DEFAULT 0 COMMENT '介護サービス利用者',
    hospital_escort_staff VARCHAR(100) DEFAULT '' COMMENT '院内介助スタッフ',
    dual_assistance_staff VARCHAR(100) DEFAULT '' COMMENT '二人介助スタッフ',
    referrer_type VARCHAR(50) DEFAULT '' COMMENT '紹介元種別',
    referrer_name VARCHAR(100) DEFAULT '' COMMENT '紹介元名称',
    referrer_contact VARCHAR(100) DEFAULT '' COMMENT '紹介元連絡先',
    is_return_trip TINYINT(1) NOT NULL DEFAULT 0 COMMENT '復路フラグ',
    parent_reservation_id INT NULL COMMENT '往路予約ID（復路の場合）',
    return_hours_later INT NULL COMMENT '復路までの時間（時間）',
    estimated_fare INT NOT NULL DEFAULT 0 COMMENT '見積運賃',
    actual_fare INT NULL COMMENT '実績運賃',
    payment_method VARCHAR(20) NOT NULL DEFAULT '現金' COMMENT '支払方法',
    status VARCHAR(20) NOT NULL DEFAULT '予約' COMMENT 'ステータス（予約/確定/完了/キャンセル）',
    special_notes TEXT NULL COMMENT '特記事項',
    created_by INT NULL COMMENT '作成者ID（ユーザーまたは協力会社）',
    ride_record_id INT NULL COMMENT '紐付け乗車記録ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reservation_date (reservation_date),
    INDEX idx_driver_id (driver_id),
    INDEX idx_vehicle_id (vehicle_id),
    INDEX idx_status (status),
    INDEX idx_parent_reservation (parent_reservation_id),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------
-- 2. partner_companies（協力会社管理）
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partner_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(100) NOT NULL COMMENT '会社名',
    contact_person VARCHAR(100) NULL COMMENT '担当者名',
    phone VARCHAR(20) NULL COMMENT '電話番号',
    email VARCHAR(255) NULL COMMENT 'メールアドレス',
    access_level VARCHAR(20) NOT NULL DEFAULT '閲覧のみ' COMMENT 'アクセス権限（閲覧のみ/部分作成）',
    display_color VARCHAR(7) NOT NULL DEFAULT '#2196F3' COMMENT '表示カラー（HEX）',
    sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------
-- 3. frequent_locations（よく使う場所）
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS frequent_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_name VARCHAR(100) NOT NULL COMMENT '場所名',
    location_type VARCHAR(20) NOT NULL COMMENT '種別（病院/駅/施設等）',
    address VARCHAR(255) NULL COMMENT '住所',
    phone VARCHAR(20) NULL COMMENT '電話番号',
    usage_count INT NOT NULL DEFAULT 0 COMMENT '使用回数',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------
-- 4. calendar_audit_logs（カレンダー操作ログ）
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT '操作ユーザーID',
    user_type VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT 'ユーザー種別（user/partner）',
    action VARCHAR(20) NOT NULL COMMENT '操作種別（create/edit/delete）',
    target_type VARCHAR(30) NOT NULL COMMENT '対象種別（reservation等）',
    target_id INT NOT NULL COMMENT '対象ID',
    old_data JSON NULL COMMENT '変更前データ',
    new_data JSON NULL COMMENT '変更後データ',
    ip_address VARCHAR(45) NULL COMMENT 'IPアドレス',
    user_agent VARCHAR(500) NULL COMMENT 'ユーザーエージェント',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_target (target_type, target_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
