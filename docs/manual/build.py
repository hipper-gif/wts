#!/usr/bin/env python3
"""導入先の会社向け 使い方マニュアルを組み立てる（WTS・HaiGO・料金シミュレーターの3製品まとめ）。

    python docs/manual/build.py fmb          # → docs/manual/dist/fmb/ に index.html と各ページ
    python docs/manual/build.py --all
    bash   docs/manual/deploy.sh fmb         # → https://tw1nkle.com/wts-tenants/fmb/manual/

入力: pages/*.md（原稿・会社に依らない）＋ tenants/<ID>.json（会社名・URL・連絡先）
原稿の {{key}} を tenants/<ID>.json の値で置き換える。{{#haigo}}…{{/haigo}} はその製品を渡している会社だけ残す。
パスワードは書かない（ID・初期パスワードは別紙で渡す＝マニュアルは誰に見られてもよい内容にする）。
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

import markdown

ROOT = Path(__file__).resolve().parent
PAGES = sorted((ROOT / "pages").glob("*.md"))
PH = re.compile(r"\{\{([a-z_]+)\}\}")
BLOCK = re.compile(r"\{\{#([a-z_]+)\}\}(.*?)\{\{/\1\}\}", re.S)


def load(tid: str) -> dict:
    p = ROOT / "tenants" / f"{tid}.json"
    if not p.exists():
        sys.exit(f"tenants/{tid}.json がありません（tenants/_example.json をコピー）")
    cfg = json.loads(p.read_text(encoding="utf-8"))
    cfg.setdefault("products", ["wts", "haigo", "simulator"])
    return cfg


def render_text(text: str, cfg: dict) -> str:
    # 製品ブロック: 渡していない製品の説明は消す
    text = BLOCK.sub(lambda m: m.group(2) if m.group(1) in cfg["products"] else "", text)

    def sub(m: re.Match) -> str:
        k = m.group(1)
        if k not in cfg:
            sys.exit(f"原稿の {{{{{k}}}}} に対応する値が tenants の設定にありません")
        return str(cfg[k])

    return PH.sub(sub, text)


def title_of(md_text: str) -> str:
    m = re.search(r"^# (.+)$", md_text, re.M)
    return m.group(1).strip() if m else "マニュアル"


def build(tid: str) -> Path:
    cfg = load(tid)
    out = ROOT / "dist" / tid
    out.mkdir(parents=True, exist_ok=True)
    tpl = (ROOT / "template.html").read_text(encoding="utf-8")
    pages = []
    for p in PAGES:
        src = render_text(p.read_text(encoding="utf-8"), cfg)
        if not src.strip():
            continue
        slug = p.stem.split("-", 1)[1] if "-" in p.stem else p.stem
        pages.append((slug, title_of(src), src))
    nav = "".join(f'<a href="{s}.html">{t}</a>' for s, t, _ in pages)
    for slug, title, src in pages:
        body = markdown.markdown(src, extensions=["tables", "toc", "attr_list", "md_in_html"])
        html = (tpl.replace("{{page_title}}", title).replace("{{nav}}", nav).replace("{{body}}", body))
        html = render_text(html, cfg)
        (out / f"{slug}.html").write_text(html, encoding="utf-8", newline="\n")
    # index = 最初のページ（はじめてガイド）
    (out / "index.html").write_text((out / f"{pages[0][0]}.html").read_text(encoding="utf-8"), encoding="utf-8", newline="\n")
    print(f"build: {tid} → {out.relative_to(ROOT)}  {len(pages)}ページ（{cfg['company_name']}）")
    return out


if __name__ == "__main__":
    args = sys.argv[1:]
    if not args:
        print(__doc__)
        sys.exit(0)
    ids = sorted(p.stem for p in (ROOT / "tenants").glob("*.json") if not p.stem.startswith("_")) if args[0] == "--all" else args
    for t in ids:
        build(t)
