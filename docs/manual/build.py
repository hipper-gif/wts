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
    cfg["tenant_id"] = tid
    # 料金シミュレーターの画面は会社ごとに違う（社名・電話・料金）→ その会社のスクショがある時だけ載せる
    if "simulator" in cfg["products"] and (ROOT / "img" / f"sim-{tid}.png").exists():
        cfg["products"] = cfg["products"] + ["sim_shot"]
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


SHOT = re.compile(r"\{\{shot:([a-z0-9,\-]+)\}\}")
NUMS = "①②③④⑤⑥⑦⑧⑨"


def expand_shots(text: str) -> str:
    """{{shot:名前[,名前]}} → 赤枠つきスクショ＋「①説明 ②説明」。枠と説明の正本は annotations.json"""
    spec = json.loads((ROOT / "annotations.json").read_text(encoding="utf-8"))

    def fig(name: str) -> str:
        if not (ROOT / "img" / f"{name}.png").exists():
            sys.exit(f"img/{name}.png がありません（annotate.py を実行）")
        items = "".join(f"<li><b>{NUMS[i]}</b> {b[4]}</li>" for i, b in enumerate(spec.get(name, [])))
        cap = f"<figcaption><ol>{items}</ol></figcaption>" if items else ""
        v = int((ROOT / "img" / f"{name}.png").stat().st_mtime)  # 画像を描き直したら古いキャッシュを使わせない
        return f'<figure class="shot"><img src="img/{name}.png?v={v}" alt="" loading="lazy">{cap}</figure>'

    return SHOT.sub(lambda m: '<div class="shots">' + "".join(fig(n) for n in m.group(1).split(",")) + "</div>", text)


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
        src = expand_shots(render_text(p.read_text(encoding="utf-8"), cfg))
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
    # スクショ（img/）をそのまま添える。見本会社 verify2 の架空データで撮る＝実在の利用者を載せない
    import shutil
    if (ROOT / "img").is_dir():
        shutil.copytree(ROOT / "img", out / "img", dirs_exist_ok=True)
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
