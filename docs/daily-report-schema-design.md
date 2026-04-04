# 運行日報 手書き分のシステム化 — 設計変更案

## 設計方針

**入力効率を最優先**に、8項目を以下の3カテゴリに分類:

| カテゴリ | 方針 | ドライバーの入力負荷 |
|---------|------|-------------------|
| A. 法的必須 | カラム追加＋入力UI変更 | 最小限に抑える |
| B. 業務分析用 | カラム追加＋自動化・任意入力 | ゼロ〜ほぼゼロ |
| C. 対応不要 | 既存で対応済み | なし |

---

## 項目別の判定と設計

### C. 対応不要（既存で対応済み）

| # | 項目 | 現状 | 判定理由 |
|---|------|------|---------|
| 3 | 迎車料金の独立項目 | `ride_records.charge` カラムが迎車料として機能中。日報テンプレートでも「迎車料」列として表示済み | **変更不要** |
| 8 | 事業許可番号 | `company_info.license_number` に保存済み。日報テンプレートで表示済み | **変更不要** |

---

### A. 法的必須（入力が必要だが最小限に）

#### A-1. 降車時刻 (`dropoff_time`)

**法的根拠**: 旅客自動車運送事業運輸規則 第25条 — 運行日報に「乗務の開始・終了の日時」記載義務

**設計**:
```sql
ALTER TABLE ride_records ADD COLUMN dropoff_time TIME NULL AFTER ride_time;
```

**入力効率の工夫**:
- 次の乗車記録の `ride_time` から逆算してデフォルト提案（移動時間を差し引く）
- 予約の `estimated_duration` があれば `ride_time + duration` を初期値にセット
- **任意入力**（NULL許容）— 入力がなくても保存可能。日報には空欄で出力
- 入力UIは既存の `ride_time` の隣に時刻ピッカーを追加するだけ

**年次報告への影響**: なし（年次報告は時刻を集計しない）

---

#### A-2. 乗車ごとの走行距離 (`ride_distance`)

**法的根拠**: 輸送実績報告書（第4号様式）で「実車キロ」「走行キロ」の報告が必要

**設計**:
```sql
ALTER TABLE ride_records ADD COLUMN ride_distance DECIMAL(6,1) NULL AFTER dropoff_location;
```

**入力効率の工夫**:
- **任意入力**（NULL許容）— 入力なしでも保存可能
- 回送距離は **自動計算**: `total_distance（入庫-出庫メーター差）- SUM(ride_distance)` で算出
- 年次報告の実車キロは `SUM(ride_distance)` で自動集計
- 将来的にはGPS連携で自動取得も可能（現時点では手入力）

**年次報告への影響**: `annual_report.php` の `getTransportResults()` に実車キロ集計を追加

---

#### A-3. 乗務員証番号 (`operator_card_number`)

**法的根拠**: 一般乗用旅客自動車運送事業の乗務員は乗務員証の携行が義務（道路運送法施行規則）

**設計**:
```sql
ALTER TABLE users ADD COLUMN operator_card_number VARCHAR(20) NULL AFTER driver_license_expiry;
```

**入力効率の工夫**:
- **マスタデータ**（管理者が1回登録するだけ）
- ドライバーは一切入力不要
- 日報テンプレートで `users.operator_card_number` を自動表示
- 乗務員台帳（driver_ledger.php）の管理画面に入力欄を追加

**年次報告への影響**: なし

---

### B. 業務分析用（入力負荷ゼロ〜任意）

#### B-1. 障害者割引フラグ (`disability_discount`)

**用途**: 割引適用件数の把握、自治体報告、売上分析

**設計**:
```sql
ALTER TABLE ride_records ADD COLUMN disability_discount TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method;
```

**入力効率の工夫**:
- **予約からの自動コピー**: `reservations.disability_card` → `ride_records.disability_discount` に自動引き継ぎ
- 予約→乗車記録変換（`convert_to_ride.php`）の処理に1行追加するだけ
- 予約なしの直接入力時のみ、チェックボックス1つ追加（デフォルトOFF）

---

#### B-2. 利用券使用額 (`ticket_amount`)

**用途**: 自治体の福祉タクシー利用券の使用額管理。自治体への請求・精算の根拠

**設計**:
```sql
ALTER TABLE ride_records ADD COLUMN ticket_amount INT NOT NULL DEFAULT 0 AFTER disability_discount;
```

**入力効率の工夫**:
- **任意入力**（デフォルト0 = 使用なし）
- 利用券を使った時だけ金額入力
- 入力UIは料金セクションに「利用券」フィールドを追加
- `payment_method` が「利用券」の場合のみ表示するなど、条件付き表示も検討

---

#### B-3. 事故・ヒヤリハット記録の日報連携

**用途**: 日報から当日の事故・ヒヤリハットを確認可能にする。安全管理の可視化

**設計**:
```sql
-- 既存 accidents テーブルにヒヤリハット種別を追加
ALTER TABLE accidents MODIFY COLUMN accident_type
  ENUM('交通事故', '重大事故', 'ヒヤリハット', 'その他') NOT NULL;

-- 乗車記録との任意紐付け
ALTER TABLE accidents ADD COLUMN ride_record_id INT NULL AFTER driver_id;
ALTER TABLE accidents ADD INDEX idx_ride_record (ride_record_id);
ALTER TABLE accidents ADD CONSTRAINT fk_accidents_ride
  FOREIGN KEY (ride_record_id) REFERENCES ride_records(id) ON DELETE SET NULL;
```

**入力効率の工夫**:
- **ドライバーの乗車記録入力には一切変更なし**
- 事故・ヒヤリハット登録時に、該当する乗車記録を選択する機能を追加
- 日報テンプレートでは `accidents` テーブルを JOIN して当日分を自動表示
- ヒヤリハット登録は `accident_management.php` から行う（既存フロー活用）

---

## まとめ：入力負荷の影響

| 項目 | ドライバーの操作変更 | 入力頻度 |
|------|-------------------|---------|
| 降車時刻 | 時刻ピッカー1つ追加（任意） | 毎回（入れたい時だけ） |
| 乗車距離 | 数値フィールド1つ追加（任意） | 毎回（入れたい時だけ） |
| 乗務員証番号 | なし（管理者が登録） | なし |
| 障害者割引 | なし（予約から自動コピー） | なし |
| 利用券使用額 | 金額フィールド1つ追加（任意） | 利用時のみ |
| 事故・ヒヤリハット連携 | なし（事故管理から紐付け） | なし |
| 迎車料金 | 変更なし（既存chargeカラム） | — |
| 事業許可番号 | 変更なし（既存） | — |

**ドライバーが毎回意識する変更: 最大2項目（降車時刻・乗車距離）、いずれも任意入力**

---

## マイグレーションファイル

ファイル名: `012_daily_report_fields.sql`

```sql
-- ============================================================
-- 012: 運行日報 手書き項目のシステム化
-- 実行日: 2026-03-27
-- 目的: 法定記載事項の補完 + 業務分析フィールド追加
-- ============================================================

-- A-1: 降車時刻（法的必須・任意入力）
ALTER TABLE ride_records ADD COLUMN dropoff_time TIME NULL AFTER ride_time;

-- A-2: 乗車ごとの走行距離（法的必須・任意入力）
ALTER TABLE ride_records ADD COLUMN ride_distance DECIMAL(6,1) NULL AFTER dropoff_location;

-- A-3: 乗務員証番号（法的必須・マスタデータ）
ALTER TABLE users ADD COLUMN operator_card_number VARCHAR(20) NULL AFTER driver_license_expiry;

-- B-1: 障害者割引フラグ（業務分析・予約から自動コピー）
ALTER TABLE ride_records ADD COLUMN disability_discount TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method;

-- B-2: 利用券使用額（業務分析・任意入力）
ALTER TABLE ride_records ADD COLUMN ticket_amount INT NOT NULL DEFAULT 0 AFTER disability_discount;

-- B-3: 事故・ヒヤリハット連携（業務分析・自動紐付け）
ALTER TABLE accidents MODIFY COLUMN accident_type
  ENUM('交通事故', '重大事故', 'ヒヤリハット', 'その他') NOT NULL;
ALTER TABLE accidents ADD COLUMN ride_record_id INT NULL AFTER driver_id;
ALTER TABLE accidents ADD INDEX idx_ride_record (ride_record_id);
-- 外部キーは既存データとの整合性確認後に追加
-- ALTER TABLE accidents ADD CONSTRAINT fk_accidents_ride
--   FOREIGN KEY (ride_record_id) REFERENCES ride_records(id) ON DELETE SET NULL;
```

---

## 影響範囲

### 変更が必要なファイル

| ファイル | 変更内容 | 優先度 |
|---------|---------|--------|
| `ride_records.php` | 入力フォームに dropoff_time, ride_distance, disability_discount, ticket_amount 追加 | 高 |
| `templates/daily_report.php` | 降車時刻・走行距離の表示列追加、事故/ヒヤリハット表示 | 高 |
| `annual_report.php` | 実車キロ集計ロジック追加 | 高 |
| `calendar/api/convert_to_ride.php` | 予約→乗車記録変換時に disability_discount を自動コピー | 中 |
| `user_management.php` or `driver_ledger.php` | 乗務員証番号の入力欄追加 | 中 |
| `accident_management.php` | ヒヤリハット種別追加、乗車記録紐付けUI | 低 |
| `css/ride-records.css` (該当CSS) | 新フィールドのスタイル | 低 |

### 変更不要なファイル
- `departure.php` / `arrival.php` — 出庫・入庫フローは変更なし
- `calendar/index.php` — 予約カレンダーは変更なし
- `daily_inspection.php` — 日常点検は無関係
- `config/database.php` — DB接続設定は変更なし
