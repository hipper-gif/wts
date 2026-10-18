#!/usr/bin/env bash
# WTS の検査(Xサーバーの CI が push ごとに回す。2026-10-18 GitHub Actions の deploy-dev.yml の検査部分から移した)
# 中身: wts/ の全 PHP の構文チェック(PHP 8.1=旧 workflow と同じ)・セキュリティの注意(赤にはしない=旧と同じ)
# 本番(全テナント)の配備は今までどおり scripts/deploy_all_tenants.sh。仕組み= clio/knowledge/xserver-ci.md
set -euo pipefail
cd "$(dirname "$0")/.."
step() { echo; echo "===== $* ($(date '+%H:%M:%S'))"; }

step "PHP 構文チェック(php8.1)"
bad=0
while IFS= read -r -d '' f; do
  out=$(/usr/bin/php8.1 -l "$f" 2>&1) || { echo "❌ $out"; bad=$((bad+1)); }
done < <(find ./wts -name "*.php" -type f -print0)
[ "$bad" -eq 0 ] || { echo "!! 構文エラー $bad 件" >&2; exit 1; }
echo "構文チェック OK"

step "セキュリティの注意(赤にはしない)"
if grep -rn "password\s*=\s*['\"][^'\"]*['\"]" ./wts/config/ --include="*.php" \
   | grep -v "getenv\|ENV\|env\|\.env\|example\|placeholder\|DB_PASS\|password_verify\|password_hash"; then
  echo "⚠️ ハードコードされたパスワードの可能性があります"
fi
if grep -rn "display_errors.*1\|display_errors.*On\|display_errors.*true" ./wts/ --include="*.php" \
   | grep -v "ini_set.*display_errors.*0\|display_errors.*Off\|CALENDAR_DEBUG_MODE"; then
  echo "⚠️ display_errors が有効なファイルがあります"
fi
step "すべて通りました"
