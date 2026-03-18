#!/usr/bin/env python3
"""
TimeTree ICSファイルから顧客マスタ・場所マスタを自動抽出するスクリプト

使い方:
    python parse_timetree_ics.py

出力:
    - customers.csv   顧客マスタ（名前、電話、住所、介助情報、利用回数）
    - locations.csv   場所マスタ（名称、種別、住所、利用回数）
    - summary.txt     抽出結果サマリー
"""

import re
import csv
import json
from collections import defaultdict, Counter
from pathlib import Path

# === 設定 ===
ICS_FILES = [
    (r"C:\Users\nikon\Desktop\timetree01.ics", "ドライバーA"),
    (r"C:\Users\nikon\Desktop\timetree02.ics", "ドライバーB"),
    (r"C:\Users\nikon\Desktop\timetree03.ics", "ドライバーC"),
]
OUTPUT_DIR = Path(__file__).parent


# === ICSパーサー ===
def parse_ics(filepath):
    """ICSファイルをパースしてイベントリストを返す"""
    with open(filepath, encoding="utf-8") as f:
        content = f.read()

    events = []
    # 行の折り返し（RFC 5545: 行頭のスペースは前行の続き）を結合
    content = re.sub(r"\r?\n[ \t]", "", content)

    for block in re.split(r"BEGIN:VEVENT", content)[1:]:
        block = block.split("END:VEVENT")[0]
        event = {}
        for line in block.strip().split("\n"):
            line = line.strip()
            if ":" in line:
                key, _, value = line.partition(":")
                # パラメータ付きキー (e.g., DTSTART;TZID=Asia/Tokyo) からキー名だけ取得
                key = key.split(";")[0]
                event[key] = value
        events.append(event)
    return events


# === SUMMARY解析 ===
def parse_summary(summary):
    """
    SUMMARYから顧客名・出発地・到着地を抽出
    パターン例:
      - 成田邦夫様　自宅〜松下記念病院
      - 市川様　自宅→北浜(勤務先)
      - 自社上野むつ子様小松病院→自宅
      - 大山様　サテライトなごみの里〜ドクターサンゴ守口
    """
    if not summary:
        return None, None, None

    # 送迎系でないイベントをスキップ
    skip_keywords = [
        "ミーティング", "会食", "懇親会", "免許", "研修", "会議",
        "休み", "休日", "祝日", "お盆", "正月", "年末", "GW",
        "メンテナンス", "車検", "洗車", "給油"
    ]
    for kw in skip_keywords:
        if kw in summary:
            return None, None, None

    # 顧客名を抽出（「様」「さん」の前の名前）
    customer = None
    # パターン1: "XXX様　YYY〜ZZZ" or "XXX様 YYY→ZZZ"
    m = re.match(r"(?:自社)?(.+?様)\s*(.+)", summary)
    if not m:
        m = re.match(r"(?:自社)?(.+?さん)\s*(.+)", summary)
    if m:
        customer = m.group(1).strip()
        route_part = m.group(2).strip()
    else:
        # 「様」がないケース → 送迎イベントでない可能性
        return None, None, None

    # 出発地・到着地を分割
    departure = None
    arrival = None
    for sep in ["〜", "～", "→", "⇒", "➡", "−", "ー", "→"]:
        if sep in route_part:
            parts = route_part.split(sep, 1)
            departure = parts[0].strip()
            arrival = parts[1].strip() if len(parts) > 1 else None
            break

    if departure is None:
        departure = route_part

    return customer, departure, arrival


# === DESCRIPTION解析 ===
def parse_description(desc):
    """DESCRIPTIONから電話番号・介助情報等を抽出"""
    if not desc:
        return {}

    # エスケープされた改行を実際の改行に
    desc = desc.replace("\\n", "\n").replace("\\,", ",")

    info = {}

    # 電話番号抽出
    phones = re.findall(r"[\d０-９]{2,4}[-ー－][\d０-９]{3,4}[-ー－][\d０-９]{3,4}", desc)
    if phones:
        # 全角→半角変換
        info["phone"] = re.sub(r"[０-９]", lambda m: chr(ord(m.group()) - 0xFEE0), phones[0])
        info["phone"] = info["phone"].replace("ー", "-").replace("－", "-")

    # 車椅子情報
    if "車椅子" in desc or "車いす" in desc:
        info["wheelchair"] = True
        if "ご自身の車椅子" in desc or "自身の車椅子" in desc:
            info["own_wheelchair"] = True

    # 介助情報
    assist_types = []
    for keyword in ["院内介助", "室内介助", "階段", "外出介助", "担ぎ"]:
        if keyword in desc:
            assist_types.append(keyword)
    if assist_types:
        info["assist"] = assist_types

    # リクライニング・ストレッチャー
    if "リクライニング" in desc:
        info["reclining"] = True
    if "ストレッチャー" in desc:
        info["stretcher"] = True

    # 料金情報
    price_match = re.search(r"([\d,]+)円", desc)
    if price_match:
        info["price"] = price_match.group(1)

    # 依頼者（CMなど）
    cm_match = re.search(r"(.+?)(?:CM|ケアマネ)(?:依頼|さん)", desc)
    if cm_match:
        info["care_manager"] = cm_match.group(1).strip()

    # その他の備考（電話番号と既知パターンを除いた残り）
    info["raw"] = desc.strip()

    return info


# === 場所の正規化 ===
def normalize_location(name):
    """場所名を正規化"""
    if not name:
        return None
    name = name.strip()
    # 括弧内の補足情報を除去して正規化キーを作成
    normalized = re.sub(r"[（(].+?[）)]", "", name).strip()
    return normalized if normalized else None


def classify_location(name):
    """場所名から種別を推定"""
    if not name:
        return "その他"
    if "自宅" in name:
        return "自宅"
    if any(kw in name for kw in ["病院", "医大", "医科", "医療センター", "医院"]):
        return "病院"
    if any(kw in name for kw in ["クリニック", "診療所", "診療"]):
        return "クリニック"
    if any(kw in name for kw in ["デイ", "施設", "特養", "老健", "グループホーム",
                                   "ケアホーム", "なごみ", "サテライト", "ホーム"]):
        return "介護施設"
    if any(kw in name for kw in ["薬局", "調剤"]):
        return "薬局"
    if any(kw in name for kw in ["駅", "空港"]):
        return "交通機関"
    return "その他"


# === メイン処理 ===
def main():
    all_events = []
    customers = defaultdict(lambda: {
        "name": "",
        "phones": set(),
        "addresses": set(),
        "locations": Counter(),
        "wheelchair": False,
        "own_wheelchair": False,
        "assist_types": set(),
        "reclining": False,
        "stretcher": False,
        "care_managers": set(),
        "drivers": set(),
        "ride_count": 0,
        "first_seen": None,
        "last_seen": None,
    })

    locations = defaultdict(lambda: {
        "name": "",
        "type": "",
        "addresses": set(),
        "usage_count": 0,
        "customers": set(),
    })

    skipped = 0
    parsed = 0

    for filepath, driver_label in ICS_FILES:
        events = parse_ics(filepath)
        print(f"  {filepath}: {len(events)}件読込")

        for event in events:
            summary = event.get("SUMMARY", "")
            customer_name, departure, arrival = parse_summary(summary)

            if not customer_name:
                skipped += 1
                continue

            parsed += 1
            desc_info = parse_description(event.get("DESCRIPTION", ""))
            location_address = event.get("LOCATION", "")
            dtstart = event.get("DTSTART", "")

            # 日付抽出
            date_match = re.search(r"(\d{8})", dtstart)
            event_date = date_match.group(1) if date_match else None

            # 顧客情報の蓄積
            ckey = customer_name
            c = customers[ckey]
            c["name"] = customer_name
            c["ride_count"] += 1
            c["drivers"].add(driver_label)
            if desc_info.get("phone"):
                c["phones"].add(desc_info["phone"])
            if location_address:
                c["addresses"].add(location_address)
            if desc_info.get("wheelchair"):
                c["wheelchair"] = True
            if desc_info.get("own_wheelchair"):
                c["own_wheelchair"] = True
            if desc_info.get("reclining"):
                c["reclining"] = True
            if desc_info.get("stretcher"):
                c["stretcher"] = True
            if desc_info.get("assist"):
                c["assist_types"].update(desc_info["assist"])
            if desc_info.get("care_manager"):
                c["care_managers"].add(desc_info["care_manager"])
            if event_date:
                if c["first_seen"] is None or event_date < c["first_seen"]:
                    c["first_seen"] = event_date
                if c["last_seen"] is None or event_date > c["last_seen"]:
                    c["last_seen"] = event_date

            # 場所情報の蓄積
            for loc_name in [departure, arrival]:
                if not loc_name or loc_name == "自宅":
                    continue
                norm = normalize_location(loc_name)
                if norm:
                    l = locations[norm]
                    l["name"] = loc_name
                    l["type"] = classify_location(loc_name)
                    l["usage_count"] += 1
                    l["customers"].add(customer_name)
                    if location_address and loc_name == departure:
                        # LOCATIONは多くの場合自宅住所
                        pass

            # 出発地・到着地を顧客の利用場所として記録
            if departure:
                c["locations"][departure] += 1
            if arrival:
                c["locations"][arrival] += 1

    # === CSV出力: 顧客マスタ ===
    customers_file = OUTPUT_DIR / "customers_extracted.csv"
    with open(customers_file, "w", encoding="utf-8-sig", newline="") as f:
        writer = csv.writer(f)
        writer.writerow([
            "顧客名", "電話番号", "住所", "車椅子", "自身の車椅子", "リクライニング",
            "ストレッチャー", "介助内容", "ケアマネ", "利用回数",
            "主なドライバー", "よく行く場所", "初回利用日", "最終利用日"
        ])
        for ckey in sorted(customers.keys(), key=lambda k: -customers[k]["ride_count"]):
            c = customers[ckey]
            top_locations = [f"{loc}({cnt}回)" for loc, cnt in c["locations"].most_common(5) if loc != "自宅"]
            writer.writerow([
                c["name"],
                ", ".join(sorted(c["phones"])) if c["phones"] else "",
                ", ".join(sorted(c["addresses"])) if c["addresses"] else "",
                "○" if c["wheelchair"] else "",
                "○" if c["own_wheelchair"] else "",
                "○" if c["reclining"] else "",
                "○" if c["stretcher"] else "",
                ", ".join(sorted(c["assist_types"])) if c["assist_types"] else "",
                ", ".join(sorted(c["care_managers"])) if c["care_managers"] else "",
                c["ride_count"],
                ", ".join(sorted(c["drivers"])),
                " / ".join(top_locations),
                f"{c['first_seen'][:4]}-{c['first_seen'][4:6]}-{c['first_seen'][6:]}" if c["first_seen"] else "",
                f"{c['last_seen'][:4]}-{c['last_seen'][4:6]}-{c['last_seen'][6:]}" if c["last_seen"] else "",
            ])

    # === CSV出力: 場所マスタ ===
    locations_file = OUTPUT_DIR / "locations_extracted.csv"
    with open(locations_file, "w", encoding="utf-8-sig", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["場所名", "種別", "利用回数", "利用顧客数", "主な利用者"])
        for lkey in sorted(locations.keys(), key=lambda k: -locations[k]["usage_count"]):
            l = locations[lkey]
            top_customers = sorted(l["customers"])[:5]
            writer.writerow([
                l["name"],
                l["type"],
                l["usage_count"],
                len(l["customers"]),
                ", ".join(top_customers),
            ])

    # === サマリー出力 ===
    summary_lines = [
        "=" * 60,
        "TimeTree ICS データ抽出結果サマリー",
        "=" * 60,
        f"",
        f"処理ファイル数: {len(ICS_FILES)}",
        f"全イベント数: {parsed + skipped}",
        f"送迎イベント: {parsed}",
        f"スキップ: {skipped}（会議・休日等）",
        f"",
        f"抽出結果:",
        f"  顧客数: {len(customers)}名",
        f"  場所数: {len(locations)}箇所",
        f"",
        f"顧客トップ10（利用回数順）:",
    ]
    for i, ckey in enumerate(sorted(customers.keys(), key=lambda k: -customers[k]["ride_count"])[:10]):
        c = customers[ckey]
        summary_lines.append(f"  {i+1}. {c['name']}: {c['ride_count']}回 ({', '.join(sorted(c['drivers']))})")

    summary_lines.extend([
        f"",
        f"場所トップ10（利用回数順）:",
    ])
    for i, lkey in enumerate(sorted(locations.keys(), key=lambda k: -locations[k]["usage_count"])[:10]):
        l = locations[lkey]
        summary_lines.append(f"  {i+1}. {l['name']} [{l['type']}]: {l['usage_count']}回 ({len(l['customers'])}名)")

    # 種別統計
    type_counts = Counter(l["type"] for l in locations.values())
    summary_lines.extend([
        f"",
        f"場所の種別内訳:",
    ])
    for t, cnt in type_counts.most_common():
        summary_lines.append(f"  {t}: {cnt}箇所")

    # ドライバー別統計
    driver_counts = Counter()
    for c in customers.values():
        for d in c["drivers"]:
            driver_counts[d] += c["ride_count"]
    summary_lines.extend([
        f"",
        f"ドライバー別送迎回数:",
    ])
    for d, cnt in driver_counts.most_common():
        summary_lines.append(f"  {d}: {cnt}回")

    summary_text = "\n".join(summary_lines)
    print(summary_text)

    summary_file = OUTPUT_DIR / "extraction_summary.txt"
    with open(summary_file, "w", encoding="utf-8") as f:
        f.write(summary_text)

    print(f"\n出力ファイル:")
    print(f"  {customers_file}")
    print(f"  {locations_file}")
    print(f"  {summary_file}")


if __name__ == "__main__":
    main()
