# 開設チェックリスト — fmb（Fire Mountain Brothers 株式会社）

手順の正本は `docs/tenants/README.md`。ここはこの1社の進捗だけを追う。
状態は `未着手` / `進行中` / `完了` / `保留` のみ使う。「完了（残あり）」とは書かない。

**起票日**: 2026-09-18
**利用開始の目安**: 2026年10月ごろ（facts F18・未確定）

## 決めること

| 項目 | 値 | 状態 |
|---|---|---|
| 会社名 | Fire Mountain Brothers 株式会社（facts F1） | 完了 |
| テナントID | `fmb`（杉原氏 2026-09-25 承認） | 完了 |
| DB名 `twinklemark_wts<ID>` | twinklemark_wtsfmb | 完了 |
| ベースパス `/wts-tenants/<ID>` | /wts-tenants/fmb | 完了 |
| 画面の表示名（system_name） | Fire Mountain Brothers 運行管理 | 完了 |
| テーマカラー | | 未着手 |
| **10月に渡す範囲** | WTS＋HaiGO＋料金シミュレーター（杉原氏 2026-09-25「シミュレーターも渡す」） | 完了 |

## 手順

| # | やること | 誰が | 状態 |
|---|---|---|---|
| 1 | ヒアリングシート送付 | 杉原氏 | 未着手 |
| 2 | 回収した内容を `facts.md` へ転記 | Claude Code | 未着手 |
| 3 | 記入済シート → JSON（`parse_hearing_sheet.py`） | Claude Code | 未着手 |
| 4 | DB作成 + 権限付与（`create_tenant_db.py --apply`・XServer API） | Claude Code | 完了（2026-09-25） |
| 5 | `provision_tenant.sh` 実行 | Claude Code | 完了（2026-09-25） |
| 6 | 動作確認（ログイン・会社情報・車両・利用者） | Claude Code | 完了（2026-09-25） |
| 7 | 初期パスワード変更 | 杉原氏 | 未着手 |
| 8 | `scripts/tenants.conf` に行追加 | Claude Code | 完了（2026-09-25） |
| 9 | 導入ガイドを作って渡す（`lino-startup-guide.html` を流用） | Claude Code | 未着手 |
| 10 | 運転者向けマニュアルを渡す（`driver-guide.html`） | 杉原氏 | 未着手 |
| 11 | **HaiGO**（渡す場合）: `haigo/scripts/provision_tenant.sh <ID> --build` → ログイン・配車ボード・push 確認（`haigo/docs/tenants.md`） | Claude Code | 完了（2026-09-25） |
| 12 | **料金シミュレーター**（渡す場合）: `taxi-simulation/docs/tenant-hearing.md` の項目を聞く → `tenants/<ID>.json` → `build.py` → `deploy_tenant.sh` | 杉原氏（聞く）／Claude Code（作る） | 完了（2026-09-25） |

## 引っかかりそうな点

- **URLは開設後に変えられない。** 手順4より前にテナントIDを確定させる。
- **開設の通し稽古は 2026-09-24 に3製品とも完了**（`verify2`・README §8）。手順どおりに流せば止まらない。
- 空DBへの基盤スキーマ投入は 2026-09-18 に検証済み（`twinklemark_wtsverify`）。
  想定外が出たら `docs/tenants/README.md` の §7 に書き足す。

## 開設記録（2026-09-25）

- WTS: https://tw1nkle.com/wts-tenants/fmb/ （admin でログイン確認・会社情報/代表者/住所/電話を登録済）
- HaiGO: https://tw1nkle.com/wts-tenants/fmb/haigo/ （WTS の admin でログイン確認・Xenia同期なし・cron巡回登録済）
- 料金シミュレーター: https://tw1nkle.com/wts-tenants/fmb/simulation/ （運賃は facts F21-F27。**機材料金・介助料は未ヒアリングで Smiley と同じ値を仮置き**。ロゴは仮）
- admin の初期パスワードは開設ログにのみ出力（リポジトリに書かない）→ 杉原氏が保管し、渡す前に変更する
- 残り: 車両・運転者・利用者の登録（ヒアリングシート）／機材料金・介助料／本物のロゴ・色／導入ガイド・運転者マニュアル

### 2026-09-26
- 車両1台を登録（練馬880り1047・車検証より）。**他に車両があるかは未確認**
- 開設直後に「2025年度 陸運局提出 期限超過」が出ていた → WTS利用開始日（wts_start_date=2026-09-25）より前に終わった年度は警告しないよう修正（全テナント配布）
- 車検満了日の欄を WTS に追加（sql/023・全テナント配布）→ 練馬880り1047 に 2028-08-27 を登録。満了60日前からダッシュボードに警告
