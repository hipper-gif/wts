#!/bin/bash
# ============================================================
# 公開デモ（テナント demo）を毎朝作り直す。サーバー上で cron から動かす。
#
#   1. DB の全テーブルを消す
#   2. 開設直後の状態（~/private/demo/wtsdemo_base.sql）を流し直す
#   3. tools/demo_seed.php で「今日」基準の架空データを入れる
#
# 開設直後の状態を取り直すとき（WTS/HaiGO のスキーマが変わった後）:
#   bash reset_demo.sh --rebase   … 全消し → base を流す → WTS/HaiGO の未適用マイグレーション → base を取り直す → 架空データ
#
# 安全装置: .env の DB_NAME が twinklemark_wtsdemo でなければ止まる（本番・他社テナントは消さない）。
# DB ユーザーはデモ専用の twinklemark_wdmo（このDBにしか権限が無い）。
# 配置: ~/private/demo/reset_demo.sh（Web公開領域の外）
# ============================================================
set -euo pipefail

TENANT_DIR="$HOME/tw1nkle.com/public_html/wts-tenants/demo"
BASE="$HOME/private/demo/wtsdemo_base.sql"
ENVF="${TENANT_DIR}/.env"

get() { grep "^$1=" "$ENVF" | cut -d= -f2- | tr -d '\r'; }
DB_NAME="$(get DB_NAME)"
DB_USER="$(get DB_USER)"
DB_PASS="$(get DB_PASS)"

if [ "$DB_NAME" != "twinklemark_wtsdemo" ] || [ -z "$(get DEMO_LOGIN_ID)" ]; then
    echo "中止: デモ用テナントではない（DB_NAME=${DB_NAME}）" >&2
    exit 1
fi
[ -f "$BASE" ] || { echo "中止: ${BASE} が無い" >&2; exit 1; }

MYSQL=(mysql -u "$DB_USER" -p"$DB_PASS")

# 1. 全テーブルを消す
TABLES=$("${MYSQL[@]}" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB_NAME}'")
if [ -n "$TABLES" ]; then
    { echo "SET FOREIGN_KEY_CHECKS=0;"; for t in $TABLES; do echo "DROP TABLE \`${t}\`;"; done; } | "${MYSQL[@]}" "$DB_NAME"
fi

# 2. 開設直後の状態
"${MYSQL[@]}" "$DB_NAME" < "$BASE"

if [ "${1:-}" = "--rebase" ]; then
    (cd "$TENANT_DIR" && php sql/run_migration.php)
    echo "※ HaiGO の未適用 SQL は haigo/scripts/deploy.sh --tenant demo で流してから、もう一度 --rebase する"
    mysqldump -u "$DB_USER" -p"$DB_PASS" --single-transaction --no-tablespaces "$DB_NAME" > "${BASE}.new"
    mv "${BASE}.new" "$BASE"
    echo "base を取り直した: ${BASE}"
fi

# 3. 架空データ
(cd "$TENANT_DIR" && php tools/demo_seed.php)
echo "$(date '+%F %T') reset ok"
