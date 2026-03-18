#!/usr/bin/env python3
"""
TimeTree APIから色情報（ラベル）付きでイベントデータを取得するスクリプト

使い方:
    python fetch_timetree_with_colors.py

TimeTreeのメールアドレスとパスワードを入力すると、
カレンダー一覧が表示されるので対象カレンダーを選択。
全イベントをJSON形式で保存し、色(category)→ドライバー名のマッピング付きCSVを出力。
"""

import json
import sys
import getpass
from pathlib import Path
from collections import Counter, defaultdict
from datetime import datetime, timezone, timedelta

# timetree-exporterのAPI部分を再利用
from timetree_exporter.api.auth import login
from timetree_exporter.api.calendar import TimeTreeCalendar

OUTPUT_DIR = Path(__file__).parent

# TimeTreeの色(category)マッピング
# TimeTreeのcategoryは数値。実際の色は以下の対応（TimeTree内部仕様）
CATEGORY_COLORS = {
    1: "ラベル1（赤系）",
    2: "ラベル2（橙系）",
    3: "ラベル3（黄系）",
    4: "ラベル4（緑系）",
    5: "ラベル5（青系）",
    6: "ラベル6（紫系）",
    7: "ラベル7（灰系）",
    8: "ラベル8（桃系）",
    9: "ラベル9（水色系）",
    10: "ラベル10（茶系）",
}


def main():
    print("=" * 50)
    print("TimeTree 色情報付きデータ取得ツール")
    print("=" * 50)
    print()

    # ログイン
    email = input("TimeTree メールアドレス: ")
    password = getpass.getpass("パスワード: ")

    print("\nログイン中...")
    try:
        session_id = login(email, password)
    except Exception as e:
        print(f"ログイン失敗: {e}")
        sys.exit(1)

    if not session_id:
        print("セッションIDの取得に失敗しました。")
        sys.exit(1)

    print("ログイン成功！\n")

    # カレンダー一覧取得
    cal = TimeTreeCalendar(session_id)
    calendars = cal.get_metadata()

    print("カレンダー一覧:")
    for i, c in enumerate(calendars):
        print(f"  [{i}] {c.get('name', '不明')} (ID: {c.get('id')})")

    print()
    selection = input("エクスポートするカレンダーの番号を入力 (全部なら 'all'): ")

    if selection.lower() == "all":
        selected = calendars
    else:
        try:
            idx = int(selection)
            selected = [calendars[idx]]
        except (ValueError, IndexError):
            print("無効な選択です。")
            sys.exit(1)

    # イベント取得
    all_events = []
    for calendar in selected:
        cal_id = calendar["id"]
        cal_name = calendar.get("name", "不明")
        print(f"\n「{cal_name}」からイベント取得中...")

        events = cal.get_events(cal_id, cal_name)
        print(f"  {len(events)}件取得")

        for event in events:
            event["_calendar_name"] = cal_name
            event["_calendar_id"] = cal_id

        all_events.extend(events)

    print(f"\n合計: {len(all_events)}件")

    # === JSON保存（生データ） ===
    json_file = OUTPUT_DIR / "timetree_raw_events.json"
    with open(json_file, "w", encoding="utf-8") as f:
        json.dump(all_events, f, ensure_ascii=False, indent=2)
    print(f"\n生データ保存: {json_file}")

    # === 色(category)の分析 ===
    print("\n" + "=" * 50)
    print("色(category)の分析")
    print("=" * 50)

    category_counter = Counter()
    category_samples = defaultdict(list)

    for event in all_events:
        cat = event.get("category", "不明")
        title = event.get("title", "")
        category_counter[cat] += 1
        if len(category_samples[cat]) < 5:
            category_samples[cat].append(title)

    print(f"\n色(category)別イベント数:")
    for cat, count in category_counter.most_common():
        color_name = CATEGORY_COLORS.get(cat, f"不明(category={cat})")
        print(f"  category {cat} ({color_name}): {count}件")
        for sample in category_samples[cat]:
            print(f"    例: {sample[:60]}")

    # === ドライバーマッピング入力 ===
    print("\n" + "=" * 50)
    print("ドライバー割り当て")
    print("=" * 50)
    print("各色に対応するドライバー名を入力してください。")
    print("（スキップする場合はEnter）\n")

    driver_map = {}
    for cat in sorted(category_counter.keys()):
        color_name = CATEGORY_COLORS.get(cat, f"category={cat}")
        samples = ", ".join(category_samples[cat][:3])
        name = input(f"  category {cat} ({color_name}) [{category_counter[cat]}件] → ドライバー名: ")
        if name.strip():
            driver_map[cat] = name.strip()

    # === CSV出力 ===
    import csv

    csv_file = OUTPUT_DIR / "timetree_events_with_colors.csv"
    with open(csv_file, "w", encoding="utf-8-sig", newline="") as f:
        writer = csv.writer(f)
        writer.writerow([
            "日付", "開始時刻", "終了時刻", "タイトル",
            "場所", "備考", "色番号", "色名", "ドライバー",
            "カレンダー名"
        ])

        for event in all_events:
            # 日時変換（UNIXタイムスタンプ → JST）
            start_ts = event.get("start_at")
            end_ts = event.get("end_at")
            jst = timezone(timedelta(hours=9))

            if start_ts:
                start_dt = datetime.fromtimestamp(start_ts, tz=jst)
                date_str = start_dt.strftime("%Y-%m-%d")
                start_time = start_dt.strftime("%H:%M")
            else:
                date_str = ""
                start_time = ""

            if end_ts:
                end_dt = datetime.fromtimestamp(end_ts, tz=jst)
                end_time = end_dt.strftime("%H:%M")
            else:
                end_time = ""

            cat = event.get("category", "")
            color_name = CATEGORY_COLORS.get(cat, "")
            driver = driver_map.get(cat, "")

            writer.writerow([
                date_str,
                start_time,
                end_time,
                event.get("title", ""),
                event.get("location", ""),
                (event.get("note", "") or "").replace("\n", " "),
                cat,
                color_name,
                driver,
                event.get("_calendar_name", ""),
            ])

    print(f"\nCSV出力: {csv_file}")
    print(f"JSON出力: {json_file}")

    # ドライバーマッピング保存
    if driver_map:
        map_file = OUTPUT_DIR / "driver_color_map.json"
        with open(map_file, "w", encoding="utf-8") as f:
            json.dump(
                {str(k): v for k, v in driver_map.items()},
                f, ensure_ascii=False, indent=2
            )
        print(f"ドライバーマッピング: {map_file}")

    print("\n完了！")


if __name__ == "__main__":
    main()
