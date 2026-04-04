-- ============================================================
-- 012: 運行日報 手書き項目のシステム化
-- 実行日: 2026-03-27
-- 目的: 法定記載事項の補完 + 業務分析フィールド追加
-- ============================================================

-- A-1: 降車時刻（法的必須・任意入力）
ALTER TABLE ride_records ADD COLUMN dropoff_time TIME NULL AFTER ride_time;

-- A-2: 乗車ごとの走行距離（法的必須・任意入力、km単位・小数1桁）
ALTER TABLE ride_records ADD COLUMN ride_distance DECIMAL(6,1) NULL AFTER dropoff_location;

-- A-3: 乗務員証番号（法的必須・マスタデータ）
ALTER TABLE users ADD COLUMN operator_card_number VARCHAR(20) NULL AFTER driver_license_expiry;

-- B-1: 障害者割引フラグ（業務分析・予約から自動コピー）
ALTER TABLE ride_records ADD COLUMN disability_discount TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method;

-- B-2: 利用券使用額（業務分析・任意入力）
ALTER TABLE ride_records ADD COLUMN ticket_amount INT NOT NULL DEFAULT 0 AFTER disability_discount;

-- B-3: 事故・ヒヤリハット連携
-- ヒヤリハット種別を追加
ALTER TABLE accidents MODIFY COLUMN accident_type
  ENUM('交通事故', '重大事故', 'ヒヤリハット', 'その他') NOT NULL;

-- 乗車記録との任意紐付け
ALTER TABLE accidents ADD COLUMN ride_record_id INT NULL AFTER driver_id;
ALTER TABLE accidents ADD INDEX idx_accident_ride_record (ride_record_id);
