# ADR-0006: HaiGO の dispatch_* は WTS の基盤スキーマに入れない（HaiGO 側が自分で足す）

| 項目 | 値 |
|---|---|
| Status | `accepted` |
| 日付 | 2026-09-24 |
| Supersedes | なし（`wts/sql/tenant_base/000_schema.sql` ヘッダの「HaiGO も同梱するテナントなら、この除外を見直すこと」を決着） |
| Superseded-by | なし |
| 関連 | HaiGO ADR-0004 / `haigo/scripts/provision_tenant.sh` / `haigo/sql/` / Clio `knowledge/taxi-bundle-tenant-runbook.md` |

## Context

HaiGO を他社にも渡すことになった（HaiGO ADR-0004）。HaiGO は WTS の DB に同居し `dispatch_*` 8テーブルを使う。
基盤スキーマ（`tenant_base/000_schema.sql`）に含めるか、HaiGO 側で足すかを決める必要があった。

## Decision

- **含めない。** WTS の基盤スキーマは WTS のテーブルだけ。HaiGO を渡す会社には、WTS 開設の後に
  `haigo/scripts/provision_tenant.sh <ID>` が HaiGO の連番 SQL（001〜）を流し、`dispatch_migrations` に記録する。
- 理由: 所有権を分ける（HaiGO の表の変更は HaiGO の SQL で追う。WTS の `migration_history` に混ぜない）。
  WTS 単体で使う会社に配車用の表を作らない。HaiGO の SQL は `dispatch_*` にしか触れず WTS の表を ALTER しないので、
  WTS の基盤スキーマ投入後の空 DB に順に流せる（2026-09-24 確認）。
- `000_schema.sql` の再生成コマンド（`--ignore-table=<DB>.dispatch_<各表>`）は**このまま維持**する。

## Consequences

- 開設は「WTS → HaiGO」の2手になる（順番は Clio の runbook）。
- `dispatch_migrations` が HaiGO 側に、`migration_history` が WTS 側に、それぞれ独立して存在する。
- Smiley 本番の `twinklemark_wts` には 2026-09-24 に `dispatch_migrations` を baseline 登録済（16件）。
