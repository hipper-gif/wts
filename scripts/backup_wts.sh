#!/bin/bash
# ============================================================
# WTS データベース自動バックアップスクリプト
# 対象: twinklemark_wts (Xserver MySQL)
# 保存先: //LS220D679/share/06.情シス共有フォルダ/wts/backup/
# 実行: C:\Program Files\Git\bin\bash.exe -c /c/projects/wts/scripts/backup_wts.sh
# ============================================================

set -euo pipefail

# ---- 設定 ----
SSH_HOST="sv16114.xserver.jp"
SSH_PORT="10022"
SSH_USER="twinklemark"
SSH_KEY="C:/projects/wts/twinklemark.key"
DB_NAME="twinklemark_wts"
DB_USER="twinklemark_taxi"
DB_PASS="Smiley2525"
NAS_DIR="//LS220D679/share/06.情シス共有フォルダ/wts/backup"
KEEP_DAYS=30

# ---- 日付ベースのファイル名 ----
DATE_STR=$(date +"%Y-%m-%d_%H-%M")
BACKUP_FILE="wts_${DATE_STR}.sql.gz"
REMOTE_TMP="/tmp/${BACKUP_FILE}"
LOG_FILE="${NAS_DIR}/backup.log"
NAS_FILE="${NAS_DIR}/${BACKUP_FILE}"

# ---- ログ関数 ----
log() {
    local ts
    ts=$(date +"%Y-%m-%d %H:%M:%S")
    local line="[${ts}] $1"
    echo "$line"
    echo "$line" >> "${LOG_FILE}"
}

# ---- メイン処理 ----
log "バックアップ開始: ${BACKUP_FILE}"

# NASディレクトリ存在確認
if [ ! -d "${NAS_DIR}" ]; then
    echo "エラー: NASバックアップ先が見つかりません: ${NAS_DIR}" >&2
    exit 1
fi

# Step 1: Xserverでmysqldump → gzip → /tmp保存
log "mysqldump 実行中..."
ssh -p "${SSH_PORT}" \
    -i "${SSH_KEY}" \
    -o StrictHostKeyChecking=no \
    -o ConnectTimeout=30 \
    "${SSH_USER}@${SSH_HOST}" \
    "mysqldump -u ${DB_USER} -p'${DB_PASS}' --single-transaction --routines --triggers --hex-blob ${DB_NAME} 2>/dev/null | gzip > ${REMOTE_TMP}"
log "mysqldump 完了"

# Step 2: SCPでNASへ転送
log "SCP 転送中..."
scp -P "${SSH_PORT}" \
    -i "${SSH_KEY}" \
    -o StrictHostKeyChecking=no \
    "${SSH_USER}@${SSH_HOST}:${REMOTE_TMP}" \
    "${NAS_FILE}"
log "SCP 転送完了: ${NAS_FILE}"

# Step 3: リモート一時ファイル削除
ssh -p "${SSH_PORT}" -i "${SSH_KEY}" -o StrictHostKeyChecking=no \
    "${SSH_USER}@${SSH_HOST}" "rm -f ${REMOTE_TMP}"
log "一時ファイル削除完了"

# Step 4: 古いバックアップ削除 (KEEP_DAYS 日より古いもの)
find "${NAS_DIR}" -name "wts_*.sql.gz" -mtime +"${KEEP_DAYS}" -exec rm -f {} \; -exec log "古いバックアップ削除: {}" \;

# Step 5: 完了ログ
SIZE=$(du -sh "${NAS_FILE}" | cut -f1)
log "バックアップ成功: ${BACKUP_FILE} (${SIZE})"
log "最新バックアップ一覧:"
ls -lh "${NAS_DIR}"/wts_*.sql.gz 2>/dev/null | tail -5 | while read -r line; do
    log "  ${line}"
done
