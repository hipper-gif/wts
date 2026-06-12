# Wts - CLAUDE.md

## プロジェクト概要

介護タクシー運行管理システム。Smiley自社運用 + Lino（他社事業者）提供。

## デプロイ先

- **使う人区分**:
  - 🌐 顧客（Lino他社事業者向け）= **🔒 提供済・URL/パス恒久維持**
  - 🏢 社内（Smiley運用）
- **本番URL**: https://tw1nkle.com/Smiley/taxi/wts/
- **テストURL**: https://twinklemark.xsrv.jp/Smiley/taxi/wts/
- **本番パス**: `~/tw1nkle.com/public_html/Smiley/taxi/wts/`
- **テストパス**: `~/twinklemark.xsrv.jp/public_html/Smiley/taxi/wts/`
- **DB**: `twinklemark_wts` + `twinklemark_wtslino`（テナント分離）
- **⚠️ 重要**: Lino提供済のため**URL構成変更不可**。新URL構造への物理移行はしない
- **詳細ルール**: `clio/knowledge/deploy-layout.md` 参照

---

## Clio連携ルール（必須・全プロジェクト共通）

このリポジトリは **Clio（パーソナル秘書AI）** のタスク管理対象です。
サブセッション（このリポジトリで開発作業をするClaude Codeセッション）は、以下のルールに従ってください。

### SPEC尊重ルール（磨き込み沼防止・必須）

- リポジトリ直下の **SPEC.md が スコープの正本**。実装は SPEC.md の中核(Must)と受入基準に従う
- **SPEC.md に記載のない改善（UX磨き・リファクタ・機能追加の思いつき）は実装しない。** SPEC.md の `対象外(Won't)` の W-later に1行記録して報告するだけにする
- 実装順: 中核(Must)→受入基準 全緑=テスト中リリース → その後に準中核(Should)（公開中昇格の条件）。任意(Could)は指示があるときだけ
- Should欄はSPEC確定時に凍結済み。実装中の思いつきをShouldに追加しない（W-laterへ記録）
- SPEC.md が無い既存プロジェクトでは、Clio側の「リリース判定チェック」結果（ROADMAP.md の DoD）を正とする
- 型の定義: `clio/knowledge/spec-method.md`

### Nicolio API 情報

| 項目 | 値 |
|------|-----|
| API URL | `https://twinklemark.xsrv.jp/nicolio-api/api.php` |
| 認証 | `Authorization: Bearer nicolio_secret_2525xsrv` |
| タスク更新 | `PATCH /tasks?id=eq.{UUID}` |

### タスクステータス即時更新（必須）

**作業が進んだら、その場でタスクのnext_actionとstatusを更新する。セッション終了まで待たない。**

以下のいずれかに該当したら即更新:
- 成果物（ファイル・コード・設計書等）を完成した
- git commit & push した
- ブロッカーが解消された（前提機能の完成等）
- フェーズが進んだ（要件定義→実装等）

**next_actionの書き方**: 完了した内容（過去形）ではなく、**次にやるべきこと**を書く。

```bash
# 更新例
python -c "
import urllib.request, json
url = 'https://twinklemark.xsrv.jp/nicolio-api/api.php/tasks?id=eq.{TASK_UUID}'
data = json.dumps({'next_action': '次にやること', 'status': '進行中'}).encode()
req = urllib.request.Request(url, data=data, headers={
    'Authorization': 'Bearer nicolio_secret_2525xsrv',
    'Content-Type': 'application/json'
}, method='PATCH')
urllib.request.urlopen(req)
"
```

### セッション完了時プロトコル（必須）

セッション終了前に**必ず**以下を実行:

1. **git commit & push** — 未コミット変更を残さない
2. **タスク更新** — next_actionが「次のステップ」を指しているか確認。完了したアクションが残っていたら書き換える
3. **git status** — 未コミットがないことを最終確認

### Git運用ルール

- デプロイしたら**必ず** git commit & push（デプロイ済みコードがGitHub未反映の状態を残さない）
- 作業の区切り（機能追加・バグ修正・設定変更等）ごとにこまめに commit & push
- コミットメッセージは日本語で簡潔に
