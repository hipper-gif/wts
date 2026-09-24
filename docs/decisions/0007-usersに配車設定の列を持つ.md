# ADR-0007: users に配車（HaiGO）の設定4列を持つ

| 項目 | 値 |
|---|---|
| Status | `accepted` |
| 日付 | 2026-09-24 |
| Supersedes | なし（ADR-0006「HaiGO の表は基盤スキーマに入れない」はそのまま＝HaiGO 専用テーブルは HaiGO が、**人に紐づく設定列**は WTS が持つ） |
| Superseded-by | なし |
| 関連 | `wts/sql/021_dispatch_driver_settings.sql`・`wts/sql/tenant_base/000_schema.sql`・`wts/user_management.php`・HaiGO ADR-0005 |

## Context

HaiGO（配車PWA）を他社にも渡すにあたり、運転者ごとの配車設定（担当色・自動振り分けの優先順位・いつもの車・曜日シフト）を
その会社が自分で入力できる場所が要る。WTS には運転者台帳（ユーザー管理画面）が既にある。

## Decision

- `users` に `dispatch_color` / `dispatch_priority` / `default_vehicle_id` / `dispatch_shift` を足す（sql/021・列追加のみ・FK なし）。
- ユーザー管理画面の運転者セクションに「配車（HaiGO）」欄を足し、ここで入力する。運転者でない人は保存時に NULL に戻す。
- **これらの列の意味・値の書式の正本は HaiGO ADR-0005**。WTS 本体はこの列を業務ロジックに使わない（読むのは HaiGO）。
- 基盤スキーマ `tenant_base/000_schema.sql` にも列を手で反映（新テナントは 021 を baseline 登録するため）。

## Consequences

- Smiley（kinki）・Lino・verify2 の3テナントに 021 が流れる。既存の値は変わらない。
- Lino は HaiGO を使っていないので列は空のまま（害なし）。
- 列の追加・変更は今後も WTS の migration で行う（HaiGO が WTS の表を ALTER しない）。
