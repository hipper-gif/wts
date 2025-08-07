-- 権限管理システム完全修正スクリプト
-- roleカラム → permission_levelカラムへの統一

-- 1. permission_levelカラムが存在しない場合は追加
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS permission_level ENUM('User', 'Admin') DEFAULT 'User' AFTER password_hash;

-- 2. 職務フラグカラムの追加（存在しない場合）
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS is_driver BOOLEAN DEFAULT FALSE AFTER permission_level,
ADD COLUMN IF NOT EXISTS is_caller BOOLEAN DEFAULT FALSE AFTER is_driver,
ADD COLUMN IF NOT EXISTS is_mechanic BOOLEAN DEFAULT FALSE AFTER is_caller,
ADD COLUMN IF NOT EXISTS is_manager BOOLEAN DEFAULT FALSE AFTER is_mechanic;

-- 3. activeカラムの統一（is_activeがある場合はactiveに統一）
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE;

-- 4. 既存のroleデータをpermission_levelに移行（roleカラムが存在する場合）
UPDATE users 
SET permission_level = CASE 
    WHEN role IN ('admin', 'システム管理者', 'Admin') THEN 'Admin'
    ELSE 'User'
END
WHERE role IS NOT NULL;

-- 5. デフォルトユーザーの設定（存在しない場合のみ）
INSERT IGNORE INTO users (login_id, name, password_hash, permission_level, is_driver, is_caller, is_manager, active) 
VALUES 
('admin', 'システム管理者', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', TRUE, TRUE, TRUE, TRUE),
('sugihara_hoshi', '杉原 星', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', TRUE, TRUE, TRUE, TRUE),
('sugihara_mitsuru', '杉原 充', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'User', TRUE, TRUE, TRUE, TRUE),
('yasuda_sho', '保田 翔', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'User', TRUE, FALSE, FALSE, TRUE),
('hattori_yusuke', '服部 優佑', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'User', TRUE, FALSE, FALSE, TRUE);

-- 6. 既存ユーザーの職務フラグ設定
UPDATE users SET 
    is_driver = TRUE,
    is_caller = CASE WHEN permission_level = 'Admin' OR name IN ('杉原 星', '杉原 充') THEN TRUE ELSE FALSE END,
    is_manager = CASE WHEN permission_level = 'Admin' OR name IN ('杉原 星', '杉原 充') THEN TRUE ELSE FALSE END
WHERE active = TRUE;

-- 7. roleカラムを削除（オプション - 慎重に実行）
-- ALTER TABLE users DROP COLUMN role;

-- 8. インデックスの追加（パフォーマンス向上）
CREATE INDEX IF NOT EXISTS idx_users_permission_level ON users(permission_level);
CREATE INDEX IF NOT EXISTS idx_users_active ON users(active);
CREATE INDEX IF NOT EXISTS idx_users_job_flags ON users(is_driver, is_caller, is_manager);

-- 9. システム設定テーブルの初期化
CREATE TABLE IF NOT EXISTS system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- システム設定の初期値
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('system_name', '福祉輸送管理システム', 'システム名称'),
('company_name', 'スマイリーケアタクシー', '会社名'),
('permission_system_version', '2.0', '権限システムバージョン'),
('last_permission_update', NOW(), '最終権限更新日時');

-- 10. vehiclesテーブルの基本構造確認
CREATE TABLE IF NOT EXISTS vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_number VARCHAR(20) UNIQUE NOT NULL,
    model VARCHAR(100),
    current_mileage INT DEFAULT 0,
    next_inspection_date DATE,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- サンプル車両データ（存在しない場合のみ）
INSERT IGNORE INTO vehicles (vehicle_number, model, current_mileage, next_inspection_date) VALUES
('車両A', 'トヨタ ハイエース', 50000, DATE_ADD(CURDATE(), INTERVAL 3 MONTH)),
('車両B', 'ニッサン セレナ', 40000, DATE_ADD(CURDATE(), INTERVAL 2 MONTH));

-- 完了メッセージ用のビュー作成
CREATE OR REPLACE VIEW permission_system_status AS
SELECT 
    'Permission System Status' as component,
    COUNT(*) as total_users,
    SUM(CASE WHEN permission_level = 'Admin' THEN 1 ELSE 0 END) as admin_users,
    SUM(CASE WHEN permission_level = 'User' THEN 1 ELSE 0 END) as regular_users,
    SUM(CASE WHEN is_driver = TRUE THEN 1 ELSE 0 END) as drivers,
    SUM(CASE WHEN is_caller = TRUE THEN 1 ELSE 0 END) as callers,
    SUM(CASE WHEN is_manager = TRUE THEN 1 ELSE 0 END) as managers,
    SUM(CASE WHEN active = TRUE THEN 1 ELSE 0 END) as active_users,
    NOW() as check_time
FROM users;

-- 権限システム確認クエリ
SELECT * FROM permission_system_status;
