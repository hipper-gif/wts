# ADR-0005: テナントDBの作成は XServer API で行う（ADR-0004 D24 を置き換え）

| 項目 | 値 |
|---|---|
| Status | `accepted` |
| 日付 | 2026-09-18 |
| Supersedes | **ADR-0004 D24**（Xserverパネルでの DB 作成は人手の前提作業とする） |
| Superseded-by | — |
| 関連 | docs/tenants/README.md §3 / scripts/create_tenant_db.py / clio/knowledge/external_tools.md「XServer API」節 / wts/sql/tenant_base/000_schema.sql |

## Context（背景・なぜ決める必要があるか）

ADR-0004 D24 で「Xserver のDB作成はサーバーパネルからしか行えないので人手の前提作業とする」と決めた。
**これは事実誤認だった。** 根拠にしたのは SSH経由のDBユーザー `twinklemark_taxi` が
`CREATE DATABASE` 権限を持たないことだけで、別経路の存在を確認していなかった。

実際には **XServer API**（2026-04-16 提供開始の公式REST API）があり、
MySQL DB の一覧・作成・削除、DBユーザー、権限付与まで揃っている。
社内では既に `clio/scripts/xserver_client.py` として実装され、`init_project.py` から使われていた。
社内規約の8条「自作ツールをClioが操作する導線は機械口を正とする」にも沿う。

この訂正にあたり、空DBへの実投入も実施した。そこで **ADR-0004 では見えていなかった不具合**が出た。

## Decision（確定した主要決定＝1決定1行）

| # | 決定 | 理由（1行） | 出典 |
|---|------|------|------|
| D25 | **テナントDBの作成・権限付与は XServer API で行う**（`scripts/create_tenant_db.py`）。ADR-0004 D24 は破棄 | パネル手作業は不要だった。機械口を正とする規約8条にも沿う | 杉原氏 2026-09-18「その辺はAPIでするのです」 |
| D26 | **テナントごとに新しいDBユーザーを作らず、既存の共有ユーザー `twinklemark_taxi` に権限を足す** | 既存2テナントと同じ構成。パスワードが増えると Mneme 登録漏れが起きる | 実機構成 |
| D27 | **基盤スキーマから DEFINER 句を必ず削る** | 残すと `ERROR 1227`（SUPER権限が要る）で**途中停止し、テーブル3個だけの半端なDBが残る** | 2026-09-18 実投入で発覚 |
| D28 | **基盤スキーマにトリガー4件を含める** | `arrival_records` の走行距離・車両積算距離の自動計算。Lino にも存在し業務に必要 | 実機比較 |

## Consequences（この決定で何が変わるか・トレードオフ）

- 良くなること: 開設の手作業がゼロになった。会社名とテナントIDが決まれば
  `create_tenant_db.py --apply` → `provision_tenant.sh` の2本で立ち上がる。
- **ADR-0004 の「未完」が解消した**: 空DB `twinklemark_wtsverify` への実投入を行い、
  雛形元と**テーブル37・列構成（型と桁まで）・トリガー4・外部キー54**がすべて一致、
  初期データも会社固有欄が空のまま揃うこと、業務テーブル33件がすべて0行であることを確認した。
  初期データSQLの2回流しでも行数が増えないこと（冪等）も確認済み。
- 引き受けるコスト・制約: XServer API キーは**サーバー全体を操作できる最重要秘匿**。
  叩いてよいのはローカルの開発ツールだけで、Web配信されるファイルには絶対に置かない
  （`iris/.env` 露出事故の教訓）。`create_tenant_db.py` はローカル実行専用。
- 捨てた選択肢と理由:
  - *テナント専用のDBユーザーを作る* — 権限分離としては筋が良いが、
    パスワードが1本増えるたび Mneme 登録漏れの risk が乗る。既存2テナントと揃える方を取った。
- 副作用: 検証用に作った `twinklemark_wtsverify` が残っている。
  不要なら削除する（API に `DELETE /db/{db_name}` がある。`xserver_client.py` には未実装）。

## 学び（なぜ間違えたか）

「SSHのDBユーザーに権限が無い」→「だからパネルでしかできない」と、
**1つの経路を確認しただけで不可能と結論した**。社内に既にAPIクライアントが
あることを `clio/knowledge/` で確認していれば防げた。
「できない」と書く前に、機械口が既にあるかを `clio/knowledge/external_tools.md` で調べる。
