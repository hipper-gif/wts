# マイグレーション手順: uk_vehicle_date制約の削除

## 問題の概要

出庫処理および入庫処理で以下のエラーが発生します：

**出庫処理:**
```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '2-2025-12-01' for key 'uk_vehicle_date'
```

**入庫処理:**
```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-2025-12-01' for key 'uk_vehicle_date'
```

## 原因

`departure_records` と `arrival_records` の両方のテーブルに設定されている `uk_vehicle_date` ユニークキー制約が、同じ車両が同じ日に複数回出庫・入庫することを禁止しています。

一方、アプリケーションコードでは、入庫済みの場合に同じ車両が再度出庫・入庫することを許可するロジックが実装されており、テーブル制約とコードが矛盾しています：

- `departure.php` 77-94行: 未入庫の場合のみエラーとし、入庫済みなら再出庫を許可
- `arrival.php` 125-148行: 出庫記録に対応する入庫記録を作成

## 解決方法

### 方法1: PHPスクリプトで実行（推奨）

以下のコマンドを実行してください：

```bash
cd /home/user/wts/wts/sql
php run_migration.php
```

実行後、以下のようなメッセージが表示されれば成功です：

```
✓ uk_vehicle_date制約が正常に削除されました
マイグレーション完了!
```

### 方法2: MySQLコマンドで実行

```bash
mysql -h localhost -u twinklemark_taxi -pSmiley2525 twinklemark_wts < remove_uk_vehicle_date_constraint.sql
```

### 方法3: phpMyAdminまたは他のDB管理ツールで実行

`remove_uk_vehicle_date_constraint.sql` ファイルを開き、SQLステートメントを実行してください：

```sql
ALTER TABLE departure_records DROP INDEX uk_vehicle_date;
ALTER TABLE arrival_records DROP INDEX uk_vehicle_date;
```

## 実行後の確認

マイグレーション実行後、以下のSQLで制約が削除されたことを確認できます：

```sql
SHOW INDEX FROM departure_records;
SHOW INDEX FROM arrival_records;
```

両テーブルで `uk_vehicle_date` がインデックス一覧に表示されなければ成功です。

## 影響範囲

- **変更対象**: `departure_records` と `arrival_records` テーブルのインデックス
- **データへの影響**: なし（既存データは変更されません）
- **機能への影響**: 同じ車両が同じ日に複数回出庫・入庫できるようになります

## 注意事項

この変更により、同じ車両が同じ日に複数回出庫できるようになりますが、アプリケーションレベルで以下の制御が行われます：

- 未入庫の出庫記録がある場合は、再出庫を禁止
- 入庫済みの出庫記録のみがある場合は、再出庫を許可

この制御により、データの整合性は保たれます。
