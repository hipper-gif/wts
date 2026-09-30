#!/usr/bin/env python3
"""スクショに赤枠と番号を描く（img/raw/*.png → img/*.png）。

    python docs/manual/annotate.py

枠の位置と説明は annotations.json（画像ごとに [x1, y1, x2, y2, "説明"] の並び）。
撮り直したら img/raw/ を差し替えて、ずれた枠だけ annotations.json を直して再実行する。
原稿（pages/*.md）では {{shot:名前}} と書くと、画像と「①説明 ②説明…」がまとめて入る（build.py が展開）。
"""
from __future__ import annotations

import json
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent
RAW = ROOT / "img" / "raw"
OUT = ROOT / "img"
RED = (229, 57, 53)
SCALE = 2  # 2倍で描いて縮める＝線と数字をなめらかに
NUMS = "①②③④⑤⑥⑦⑧⑨"


def font(size: int) -> ImageFont.FreeTypeFont:
    for f in ("C:/Windows/Fonts/meiryob.ttc", "C:/Windows/Fonts/YuGothB.ttc"):
        if Path(f).exists():
            return ImageFont.truetype(f, size)
    return ImageFont.load_default()


def draw(name: str, boxes: list) -> None:
    src = Image.open(RAW / f"{name}.png").convert("RGB")
    w, h = src.size
    big = src.resize((w * SCALE, h * SCALE), Image.LANCZOS)
    d = ImageDraw.Draw(big)
    f = font(15 * SCALE)
    r = 12 * SCALE
    for i, (x1, y1, x2, y2, _) in enumerate(boxes):
        x1, y1, x2, y2 = (v * SCALE for v in (x1, y1, x2, y2))
        d.rounded_rectangle((x1, y1, x2, y2), radius=8 * SCALE, outline=RED, width=3 * SCALE)
        # 番号の丸は枠の左上。画面の外に出ないよう内側へ寄せる
        cx = min(max(x1, r + 2), w * SCALE - r - 2)
        cy = min(max(y1, r + 2), h * SCALE - r - 2)
        d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=RED, outline=(255, 255, 255), width=2 * SCALE)
        d.text((cx, cy), str(i + 1), font=f, fill=(255, 255, 255), anchor="mm")
    big.resize((w, h), Image.LANCZOS).save(OUT / f"{name}.png", optimize=True)


def main() -> None:
    spec = json.loads((ROOT / "annotations.json").read_text(encoding="utf-8"))
    for raw in sorted(RAW.glob("*.png")):
        name = raw.stem
        boxes = spec.get(name, [])
        if boxes:
            draw(name, boxes)
        else:
            Image.open(raw).save(OUT / raw.name, optimize=True)
        print(f"{name}: 枠{len(boxes)}")


if __name__ == "__main__":
    main()
