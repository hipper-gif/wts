# マイグレーション手順: 入庫記録編集履歴カラムの追加

## 概要

入庫記録の修正機能を完全に有効化するために、`arrival_records`テーブルに編集履歴を記録するカラムを追加します。

## 追加されるカラム

- `is_edited`: 編集済みフラグ（BOOLEAN）
- `edit_reason`: 修正理由（VARCHAR(100)）
- `last_edited_by`: 最終編集者ID（INT、外部キー）
- `last_edited_at`: 最終編集日時（TIMESTAMP）

## 現在の状態

**マイグレーション未実行の場合:**
- 入庫記録の一覧表示と修正は可能
- ただし、修正履歴は記録されません
- 画面上に警告メッセージが表示されます

**マイグレーション実行後:**
- 修正履歴が完全に記録されます
- 誰が、いつ、何の理由で修正したかが追跡可能
- 修正済み記録には「修正済み」バッジが表示されます

## マイグレーション実行方法

### 方法1: SQLファイルを直接実行（推奨）

データベース管理ツール（phpMyAdmin等）で以下のファイルを実行してください：

```
/home/user/wts/wts/sql/add_arrival_edit_columns.sql
```

### 方法2: MySQLコマンドで実行

```bash
cd /home/user/wts/wts/sql
mysql -h localhost -u [ユーザー名] -p [データベース名] < add_arrival_edit_columns.sql
```

### 方法3: phpMyAdminで実行

1. phpMyAdminにログイン
2. 対象のデータベースを選択
3. 「SQL」タブを開く
4. `add_arrival_edit_columns.sql`ファイルの内容を貼り付けて実行

## 実行後の確認

マイグレーション実行後、以下のSQLでカラムが追加されたことを確認できます：

```sql
SHOW COLUMNS FROM arrival_records;
```

以下のカラムが表示されれば成功です：
- `is_edited`
- `edit_reason`
- `last_edited_by`
- `last_edited_at`

また、以下のSQLで確認用ビューも作成されます：

```sql
SELECT * FROM arrival_edit_summary LIMIT 10;
```

## 影響範囲

- **変更対象**: `arrival_records` テーブルにカラムとインデックスを追加
- **データへの影響**: なし（既存データは変更されません）
- **機能への影響**:
  - 入庫記録の修正履歴が記録されるようになります
  - 画面上の警告メッセージが消えます

## 注意事項

- このマイグレーションは **後方互換性があります**
- マイグレーション実行前でも基本機能は動作します
- ただし、完全な機能を利用するにはマイグレーションの実行を推奨します

## トラブルシューティング

### エラー: テーブルが見つからない

```
Table 'arrival_records' doesn't exist
```

→ `arrival_records`テーブルが存在しません。データベースの初期設定を確認してください。

### エラー: カラムが既に存在する

```
Duplicate column name 'is_edited'
```

→ マイグレーションは既に実行済みです。再実行の必要はありません。

### エラー: 外部キー制約違反

```
Cannot add foreign key constraint
```

→ `users`テーブルが存在するか確認してください。

## サポート

マイグレーション実行でご不明な点がございましたら、システム管理者にお問い合わせください。
