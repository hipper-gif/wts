#!/bin/bash
# 導入先向けマニュアルを会社の置き場へ配る: https://tw1nkle.com/wts-tenants/<ID>/manual/
#   bash docs/manual/deploy.sh fmb
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
TID="${1:?テナントIDを指定（例: fmb）}"
KEY="${WTS_SSH_KEY:-C:/Users/nikon/projects/SmartClock/twinklemark.key}"
python "${DIR}/build.py" "${TID}"
REMOTE="tw1nkle.com/public_html/wts-tenants/${TID}/manual"
ssh -p 10022 -i "${KEY}" -o StrictHostKeyChecking=no twinklemark@sv16114.xserver.jp "mkdir -p ~/${REMOTE}"
scp -q -P 10022 -i "${KEY}" -o StrictHostKeyChecking=no "${DIR}/dist/${TID}/"*.html "twinklemark@sv16114.xserver.jp:~/${REMOTE}/"
echo "完了: https://tw1nkle.com/wts-tenants/${TID}/manual/"
