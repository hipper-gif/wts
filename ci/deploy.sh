#!/usr/bin/env bash
# WTS の開発環境への配置(Xサーバーの CI が main の検査が緑になった直後に回す。
# 2026-10-18 GitHub Actions の deploy-dev.yml から移した。FTP でなくサーバー内のコピー)
# 配置先= twinklemark.xsrv.jp/.../Smiley/taxi/wts(開発環境)。本番(tw1nkle.com 側・全テナント)は scripts/deploy_all_tenants.sh
# 除外は旧 workflow と同じ(.env・sql・uploads/documents 等は上書きしない)。消えたファイルは消さない(旧 FTP-Deploy-Action と同じく
# 追加・更新だけ)
set -euo pipefail
cd "$(dirname "$0")/.."
dest="$HOME/twinklemark.xsrv.jp/public_html/Smiley/taxi/wts/"
[ -d "$dest" ] || { echo "!! 配置先がありません: $dest" >&2; exit 1; }
rsync -a \
  --exclude='.git*' --exclude='README.md' --exclude='.github/' --exclude='node_modules/' \
  --exclude='.env' --exclude='.env.example' --exclude='sql/' --exclude='uploads/documents/' \
  wts/ "$dest"
echo "開発環境に配置しました: $dest"
