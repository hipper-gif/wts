# ADR-0001: baseline（現在の主要な決定のスナップショット）

> **この型の目的**: 「何を決めたか・なぜ・そこからどう変わったか」を1本の鎖で追えるようにする（Nygard式 ADR）。
> **これは baseline**: これまでに確定した WTS の**主要決定のスナップショット**。以降、決定が変わったら本書は書き換えず**新ADR（0002以降）を作って該当行（D番号）を supersede** する。
> **出典**: repo CLAUDE.md / scripts/{tenants.conf,lino_company.json} / wts/config/{tenant.php,database.php} / wts/sql/。エコシステム側の決定(HaiGO/TimeTree/smycle)はclio memory project_{xenia,haigo,smycle}・CLAUDE-dataflow.mdを出典に付す。※SPEC.md/ROADMAP.md/README.mdは未整備＝本baseline作成時点で不在。

| 項目 | 値 |
|---|---|
| Status | `accepted`（D10のみ `proposed`＝SPEC正本未整備） |
| 日付 | 2026-07-08 |
| Supersedes | なし |
| Superseded-by | （後で個別ADRが特定の決定を置き換えたらここへ追記） |
| 関連 | docs/facts.md(F1-F5) / clio twinklemark_wts / project_xenia・project_haigo |

## Context（背景・なぜ決める必要があるか）

介護タクシー運行管理システム。Smiley自社運用（近畿ケアタクシー部門）で先行開発したものを、他社事業者「合同会社LINO」へ2026-04-10に外販（Lino介護タクシー・テナントlino）。**Lino提供済のため本体コードとURL/パス構成は恒久的に変更できない**という制約が全設計の土台になる。顧客ドメインの正本はXenia（twinklemark_customer）へ集約する社内方針が2026-06に確定したが、WTSは本体不改造の縛りがあるため例外扱いが要る。配車手配は現状TimeTree手運用で、配車PWA（HaiGO）が別repoで本番稼働・WTS DBを共有する形で並走している。SPEC.md等の正本文書は本baseline作成時点で未整備で、CLAUDE.md＋コード＋clio memoryが実質の一次記録。

## Decision（確定した主要決定＝1決定1行）

| # | 決定 | 理由（1行） | 出典 |
|---|------|------|------|
| D1 | **WTS=介護タクシー運行管理システム。Smiley自社運用＋Lino他社外販の単一プロダクトをマルチテナントで運用** | 自社用に作った運行管理を他社へ横展開(WTS→Lino＝将来外販の前例) | CLAUDE L5 / tenants.conf |
| D2 | **Lino外販済につき本体コード・URL構成・本番パスは恒久変更不可**(新URL構造への物理移行はしない＝W-never) | 提供済顧客のブックマーク/PWA/運用を壊さない。破壊的移行の恒久リスクを構造的に排除 | CLAUDE L16-17 |
| D3 | **マルチテナント方式＝単一コードベース＋テナント別DB(twinklemark_wts/twinklemark_wtslino)を各テナントの.env(DB_NAME・APP_BASE_PATH)で切替。表示名はsystem_settingsからDB動的取得** | 本体を分岐/改造せず他社を足せる。テナント境界＝DBとベースパスで物理分離 | config/tenant.php・database.php / tenants.conf |
| D4 | **顧客正本のXenia集約における唯一の例外＝WTSはミラー方式**(Xenia側external_linksでWTS顧客を突合保持し、WTS本体には報告I/F(Xenia呼び出し)を持たせない) | 本体不変更(D2)と正本集約を両立。密結合を避けXeniaが片側で名寄せを負う | project_xenia L22-23 / dataflow L85 |
| D5 | **配車PWA「HaiGO」はtwinklemark_wtsを正本共有するB案構成。WTS本体コードには一切触れず、新規はdispatch_プレフィックス3テーブルのみ・既存テーブルのALTER禁止** | 本体不変更(D2)を守りつつ配車機能を新造。WTS calendar/モジュールは未完成・実務未使用のため改修でなく別造 | project_haigo L15-16 |
| D6 | **TimeTree(現状の配車手運用)は段階的廃止。同期APIは作らない。移行支援は「配車表テキストコピー」のみ** | 二重運用を残さず廃止方向へ寄せる。双方向同期はコスト過大で不採用 | project_haigo L17 |
| D7 | **自費サービス「スマイクル」(smycle)はWTSと直接統合せず、別DB twinklemark_smycleで部門連携**(介護タクシー空き枠突合は将来のAPI疎結合) | WTSは運行管理、smycleは自費サービス管理で責務分離。本体を膨らませない | project_smycle L18,57 / dataflow L85 |
| D8 | **DBスキーマ管理＝wts/sql/の連番SQL(001…020)＋migration_history＋適用済はapplied/へ隔離** | テナント複数へ同一DDLを再現適用するため適用履歴を追跡可能にする | wts/sql/007_migration_history.sql |
| D9 | **認証＝セッションベース＋Remember Me**(長期ログイン自動化、remember_tokens) | 現場ドライバーの再ログイン負担を下げる。既存URL/PWA運用の中に閉じて実装 | sql/020_remember_tokens.sql |
| D10 | `proposed` **スコープ正本＝SPEC.mが未整備。当面はCLAUDE.md＋ClioのROADMAP DoDを正とし、SPEC.mdをこれから起こす** | ADR/facts/INDEXは2026-07-08に雛形設置のみ＝スコープ正本が空。磨き込み沼防止にSPEC確定が要る | docs/INDEX.md / docs/facts.md(空) |

## Consequences（この決定で何が変わるか・トレードオフ）

- 良くなること: 本体を改造せず他社テナントを足せる（D1/D3）。Lino顧客の運用を壊す物理移行リスクが構造的に消える（D2）。顧客名寄せの重複(旧WTS×YomiCare二重登録)がXenia片側集約で解ける一方WTS本体は無傷（D4）。配車/自費サービスがWTS本体を膨らませず別レイヤ(HaiGO/smycle)で足せる（D5/D7）。DDLがテナント横断で再現適用できる（D8）。
- 引き受けるコスト・制約: 本体は恒久的に「触れない資産」化し、機能追加は原則テナント別DDLか外付けPWAで行う（D2/D5）。WTS顧客はカナ・生年月日が全件空で機械名寄せ不能（F2）＝Xenia側ミラーは人手レビュー前提（D4）。TimeTree廃止までは配車が二重運用として残る（D6）。SPEC.mが無く受入基準がCLAUDE.md/ROADMAP DoDに散在＝スコープ判断が弱い（D10）。
- 捨てた選択肢と理由:
  - 新URL構造への物理移行・本体リファクタ → 提供済Lino環境を壊すためW-never（D2）。
  - WTS本体にXenia報告I/Fを組み込む案 → 本体不変更に反し密結合化するためミラー方式を採用（D4）。
  - WTS calendar/モジュールを改修して配車機能化 → 未完成・実務未使用で作者が封印済み→HaiGOで別造（D5）。
  - TimeTree双方向同期API → 廃止方針にコストが見合わず、テキストコピー移行のみ（D6）。
  - スマイクルのWTS本体統合 → 責務が異なり本体を膨らませるため別DB部門連携（D7）。
