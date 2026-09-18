# 開設チェックリスト — 新規テナント（会社名 未ヒアリング）

手順の正本は `docs/tenants/README.md`。ここはこの1社の進捗だけを追う。
状態は `未着手` / `進行中` / `完了` / `保留` のみ使う。「完了（残あり）」とは書かない。

**起票日**: 2026-09-18

## 決めること

| 項目 | 値 | 状態 |
|---|---|---|
| 会社名 | | 未着手 |
| テナントID | | 未着手 |
| DB名 `twinklemark_wts<ID>` | | 未着手 |
| ベースパス `/wts-tenants/<ID>` | | 未着手 |
| 画面の表示名（system_name） | | 未着手 |
| テーマカラー | | 未着手 |

## 手順

| # | やること | 誰が | 状態 |
|---|---|---|---|
| 1 | ヒアリングシート送付 | 杉原氏 | 未着手 |
| 2 | 回収した内容を `facts.md` へ転記 | Claude Code | 未着手 |
| 3 | 記入済シート → JSON（`parse_hearing_sheet.py`） | Claude Code | 未着手 |
| 4 | **Xserverパネルで DB 作成 + `twinklemark_taxi` に権限付与** | 杉原氏（手作業・代行不可） | 未着手 |
| 5 | `provision_tenant.sh` 実行 | Claude Code | 未着手 |
| 6 | 動作確認（ログイン・会社情報・車両・利用者） | Claude Code | 未着手 |
| 7 | 初期パスワード変更 | 杉原氏 | 未着手 |
| 8 | `scripts/tenants.conf` に行追加 | Claude Code | 未着手 |
| 9 | 導入ガイドを作って渡す（`lino-startup-guide.html` を流用） | Claude Code | 未着手 |
| 10 | 運転者向けマニュアルを渡す（`driver-guide.html`） | 杉原氏 | 未着手 |

## 引っかかりそうな点

- **手順4は代行できない。** Xserver のDB作成はサーバーパネルからしか行えず、SSHの
  DBユーザーに `CREATE DATABASE` 権限が無い（2026-09-18 確認）。ここで必ず一度止まる。
- **URLは開設後に変えられない。** 手順4より前にテナントIDを確定させる。
- 空DBへの基盤スキーマ投入は**この会社が初回**になる。手順5で想定外が出たら
  `docs/tenants/README.md` の §7 に書き足す。
