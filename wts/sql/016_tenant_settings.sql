-- マイグレーション: 016_tenant_settings.sql
-- 説明: テナント設定の追加（他社展開対応）
-- 作成日: 2026-04-08

-- テーマカラー設定
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('theme_color', '#00C896')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
