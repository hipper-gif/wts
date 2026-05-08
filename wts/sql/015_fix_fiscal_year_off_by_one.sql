-- マイグレーション: 015_fix_fiscal_year_off_by_one.sql
-- 説明: annual_reports.fiscal_year を「終了年（カレンダー年）」から「標準和暦年度（開始年）」へ変換
-- 背景:
--   旧仕様: fiscal_year=2026 が「2025-04〜2026-03」を指すという非標準扱いで、
--           PDF/Excelに「令和8年度」と誤表記されていた（実態は令和7年度）。
--   新仕様: fiscal_year=2025 が「令和7年度（2025-04〜2026-03）」を指す標準和暦年度。
-- 影響: dashboard.php は既に標準（開始年）で照会しているため、これで両者が整合する。
-- 作成日: 2026-05-08

-- 既存全レコードの fiscal_year を -1
-- UNIQUE(fiscal_year, report_type) があるため、衝突しないかは事前確認必要
-- （新旧両方の年度レコードが共存していた場合は衝突する）

UPDATE annual_reports SET fiscal_year = fiscal_year - 1;

-- 確認用クエリ:
-- SELECT fiscal_year, report_type, status, updated_at FROM annual_reports ORDER BY fiscal_year DESC, report_type;
