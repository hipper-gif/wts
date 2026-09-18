# 新テナント（他社）開設手順

WTS を新しい事業者に提供するときの手順書。**この文書が開設作業の正本。**
個別の会社の進捗は `docs/tenants/<テナントID>/checklist.md`、聞いた事実は同じ階層の `facts.md` に書く。

会社名が決まる前でも、ここに書かれた「事前にできること」は先に済ませてよい。

---

## 0. いま揃っているもの（器）

| もの | 場所 | 状態 |
|---|---|---|
| ヒアリングシート（客に送る） | `docs/hearing-sheet.xlsx` | 空欄・そのまま送付可 |
| シート生成スクリプト | `scripts/generate_hearing_sheet.py` | 再生成が必要なときだけ |
| 記入済シート → JSON 変換 | `scripts/parse_hearing_sheet.py` | |
| テナント基盤スキーマ（37テーブル） | `wts/sql/tenant_base/000_schema.sql` | 2026-09-18 に本番から取得 |
| テナント初期データ | `wts/sql/tenant_base/001_seed.sql` | 会社固有の値は空 |
| DB作成（XServer API） | `scripts/create_tenant_db.py` | 2026-09-18 実地確認済 |
| 構築スクリプト | `scripts/provision_tenant.sh` | |
| テナント登録簿 | `scripts/tenants.conf` | |
| 全テナント一括デプロイ | `scripts/deploy_all_tenants.sh` | |
| 導入ガイド（客に渡す） | `docs/lino-startup-guide.html` | Lino向け。流用して作る |
| 運転者向けマニュアル | `docs/driver-guide.html` | |

---

## 1. 会社名が決まる前にやること

1. **ヒアリングシートを送る** — `docs/hearing-sheet.xlsx` をそのまま送付する。
   会社名・代表者・住所・許可番号・車両・利用者・営業時間までこれ1枚で揃う。
2. **聞いた内容を `docs/tenants/_pending/facts.md` に書く。**
   会話やチャットで先に分かったことは、シートの返送を待たずにここへ入れる。
3. テナントIDとURLの案を決めておく（下の §2 の規則に従う）。

> 会社名が決まったら `docs/tenants/_pending/` を `docs/tenants/<テナントID>/` にリネームする。

---

## 2. 命名規則

| 項目 | 規則 | 例（Lino） |
|---|---|---|
| テナントID | 英小文字と数字のみ。ディレクトリ名になる | `lino` |
| DB名 | `twinklemark_wts<テナントID>` | `twinklemark_wtslino` |
| ベースパス | `/wts-tenants/<テナントID>` | `/wts-tenants/lino` |
| 本番URL | `https://tw1nkle.com/wts-tenants/<テナントID>/` | |

> **提供後にURLは変えられない。** Lino で確定済みの方針（`CLAUDE.md`）。開設前に決め切ること。

---

## 3. DB を作る（XServer API・スクリプト1本）

サーバーパネルを人手で触る必要はない。**XServer API** で作成から権限付与まで終わる。

```bash
python scripts/create_tenant_db.py <テナントID>           # 計画表示のみ（既定）
python scripts/create_tenant_db.py <テナントID> --apply   # 実際に作成
python scripts/create_tenant_db.py --list                 # 既存DBの一覧
```

やること: `twinklemark_wts<テナントID>` を utf8mb4 で作り、
**既存の共有DBユーザー `twinklemark_taxi` に権限を足す**。
テナントごとに新しいDBユーザーは作らない（パスワードが増えると Mneme への登録漏れが起きるため）。
同名のDBが既にあるときは作成せず中止する。

> **APIキーはこのリポジトリに無い。** 解決順は Mneme credentials → `clio/.env`。
> 鍵を叩くのは**ローカルの開発ツールだけ**で、サーバー上のアプリからは呼ばない
> （`clio/knowledge/external_tools.md` の方針。Web配信されるファイルには絶対に置かない）。

> **SSH経由のDBユーザーでは作れない。** `twinklemark_taxi` の権限は
> `GRANT USAGE ON *.*` のみで `CREATE DATABASE` を持たない。だからAPIを使う。

---

## 4. 構築（スクリプト1本）

```bash
bash scripts/provision_tenant.sh <テナントID> <DB名> <ベースパス> "<表示名>" [company_info.json]
```

スクリプトがやること:

| Step | 内容 |
|---|---|
| 1 | Smiley本番のコードをテナントディレクトリへコピー（`.env` / `uploads` は除く） |
| 2 | `.env` 生成（DB接続先とベースパス） |
| 3 | `uploads/` 作成 |
| 4 | **空DBなら** 基盤スキーマ → 初期データ → `run_migration.php --baseline` |
| 5 | `system_name` 設定 |
| 5.5 | 事業者情報を登録（JSON を渡したときだけ） |
| 6 | 管理者ユーザー `admin` 作成。初期パスワードは実行ログに出る |

**DB認証情報はスクリプトに書かれていない。** 環境変数 `WTS_DB_USER` / `WTS_DB_PASS` が
未設定なら、サーバー上の Smiley本番 `.env` から自動で読む。SSH鍵は `WTS_SSH_KEY` で上書きできる。

### なぜ基盤スキーマが要るか

`wts/sql/` の連番マイグレーションは **003 から始まり、土台のテーブルを作るSQLが無い**。
さらに `migration_history` と実スキーマがずれている（`vehicles` の form21 系4列は
履歴に無いまま本番に存在する）。積み上げでは現行スキーマを再現できないため、
**空DBの土台は `wts/sql/tenant_base/000_schema.sql` が正本**。

---

## 5. 構築後の確認

1. `https://tw1nkle.com<ベースパス>/` を開き、ログイン画面が出る
2. `admin` とログに出た初期パスワードでログインできる
3. 会社情報・車両・利用者が画面に出る
4. **初期パスワードを変更する**
5. `scripts/tenants.conf` に行を追加する
6. `docs/tenants/<テナントID>/checklist.md` を埋める

---

## 6. 開設後の運用で気をつけること

- **テナントのコードは自動では更新されない。** Smiley本番に修正を入れても、
  テナント側は `scripts/deploy_all_tenants.sh` で配らないと古いままになる。
- **Lino は実際に古い。** 2026-09-18 時点で `driver_cash_count.php` が Smiley版と
  301行違い、DBにも `vehicles` の form21 系4列が無い。新テナントを足すときに
  まとめて揃えるかどうかは別途判断する。
- `dispatch_*` 8テーブルは **HaiGO（配車PWA）のテーブル**で、WTSのDBに同居している
  （HaiGO ADR-0001 D1「B案＝WTSのDBを正本として共有」）。HaiGO はまだ Smiley 専用で
  マルチテナント化は未決（HaiGO ADR-0001 D12 が `proposed`）のため、基盤スキーマから外してある。
  **HaiGO も一緒に渡すことになったら、この除外を見直す。**

---

## 7. 既知の未解決

| 件 | 内容 |
|---|---|
| 検証用DB | 実地検証に使った `twinklemark_wtsverify` は**保持**（2026-09-18 杉原氏「今後も使うかも」）。削除しない |
| 平文パスワード | `scripts/backup_wts.sh` と `scripts/setup_lino_data.php` にDBパスワードが直書きされたまま（社内規約の禁止事項）。バックアップ定時実行を壊す恐れがあるため未修正 |
| Lino のスキーマ差分 | 上記 §6 |
