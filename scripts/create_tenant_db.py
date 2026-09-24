#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""WTS 新テナント用の MySQL DB を XServer API で作成する。

サーバーパネルを人手で触る必要はない。DB作成・権限付与までこれ1本で終わる。

使い方:
    python scripts/create_tenant_db.py <テナントID>           # 計画表示のみ（既定）
    python scripts/create_tenant_db.py <テナントID> --apply   # 実際に作成
    python scripts/create_tenant_db.py --list                 # 既存DBの一覧

命名規則（docs/tenants/README.md §2）:
    DB名 = twinklemark_wts<テナントID>

DBユーザーは既存の共有ユーザー（Smiley・Lino と同じ）をそのまま使う。
テナントごとに新しいDBユーザーは作らない。パスワードが増えると
Mneme への登録漏れが起きるため、既存の1本に権限を足すだけにする。

APIキーは clio 側（Mneme credentials → clio/.env の順で解決）にあり、
このリポジトリには置かない。鍵を叩くのはローカルの開発ツールだけで、
サーバー上のアプリからは絶対に呼ばない（clio/knowledge/external_tools.md の方針）。
"""

import sys
import os
import re

CLIO_SCRIPTS = r"C:\Users\nikon\clio\scripts"
# 既存テナントが共有している DB ユーザー（サーバーの .env と同じもの）
SHARED_DB_USER = "twinklemark_taxi"
PREFIX = "twinklemark_"


def _client():
    if CLIO_SCRIPTS not in sys.path:
        sys.path.insert(0, CLIO_SCRIPTS)
    try:
        import xserver_client as xs
    except ImportError as e:
        print(f"[ERROR] xserver_client を読み込めません（{CLIO_SCRIPTS}）: {e}", file=sys.stderr)
        sys.exit(1)
    return xs


def _say(msg):
    """出力して即フラッシュする。

    clio の mneme_api.py は読み込み時に sys.stdout を新しい TextIOWrapper へ
    差し替える（module直下・ガードなし）。xserver_client が APIキーを取りに行く
    ついでにこれが読み込まれるため、パイプ・リダイレクト時は差し替え前に
    バッファへ溜まっていた出力が黙って消える。都度フラッシュして取りこぼさない。
    """
    print(msg)
    sys.stdout.flush()


def list_dbs(xs):
    """MySQL DB の一覧を返す。クライアント側に一覧関数が無いため _request を直接使う。"""
    res = xs._request("GET", "/db")
    items = res.get("db_list") or res.get("list") or res.get("databases") or []
    names = []
    for it in items:
        if isinstance(it, dict):
            names.append(it.get("db_name") or it.get("name") or str(it))
        else:
            names.append(str(it))
    return sorted(names)


def main():
    args = [a for a in sys.argv[1:]]
    apply_mode = "--apply" in args
    args = [a for a in args if a != "--apply"]

    xs = _client()

    if "--list" in args:
        for n in list_dbs(xs):
            _say(" ", n)
        return 0

    if len(args) != 1:
        print(__doc__)
        return 1

    tenant_id = args[0].strip().lower()
    if not re.fullmatch(r"[a-z0-9]+", tenant_id):
        print(f"[ERROR] テナントIDは英小文字と数字のみ: {tenant_id!r}", file=sys.stderr)
        return 1

    suffix = f"wts{tenant_id}"
    db_full = PREFIX + suffix
    mode = "実行" if apply_mode else "DRY-RUN（作成しません）"

    _say(f"WTS テナントDB作成 [{mode}]")
    _say(f"  テナントID : {tenant_id}")
    _say(f"  DB名       : {db_full}")
    _say(f"  DBユーザー : {SHARED_DB_USER}（既存の共有ユーザーに権限を足すだけ）")

    try:
        existing = list_dbs(xs)
    except Exception as e:
        print(f"  [ERROR] DB一覧を取得できません: {e}", file=sys.stderr)
        return 1

    if db_full in existing:
        _say(f"  [中止] {db_full} は既に存在します。テナントIDを確認してください。")
        return 1

    if not apply_mode:
        _say("  → 実際に作成するには --apply を付けて再実行")
        return 0

    try:
        xs.create_db(suffix, character_set="utf8mb4", memo=f"WTS tenant {tenant_id}")
        _say(f"  DB {db_full} → 作成完了")
    except Exception as e:
        print(f"  [ERROR] DB作成に失敗: {e}", file=sys.stderr)
        return 1

    try:
        xs.grant(SHARED_DB_USER, db_full)
        _say(f"  {SHARED_DB_USER} に権限付与 → 完了")
    except Exception as e:
        print(f"  [ERROR] 権限付与に失敗: {e}", file=sys.stderr)
        print(f"          DBは作成済みです。権限だけ付け直してください。", file=sys.stderr)
        return 1

    after = list_dbs(xs)
    ok = db_full in after
    _say(f"  確認: 一覧に {db_full} が{'ある → OK' if ok else '無い → 要確認★'}")
    _say("")
    _say("次の手順:")
    _say(f"  bash scripts/provision_tenant.sh {tenant_id} {db_full} /wts-tenants/{tenant_id} \"<表示名>\"")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
