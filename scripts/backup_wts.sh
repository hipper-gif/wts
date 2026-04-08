#!/bin/bash
# ============================================================
# WTS データベース自動バックアップスクリプト（マルチテナント対応）
# 対象: tenants.conf に定義された全テナントのMySQL DB (Xserver)
# 保存先: //LS220D679/share/06.情シス共有フォルダ/wts/backup/
# 実行: C:\Program Files\Git\bin\bash.exe -c /c/projects/wts/scripts/backup_wts.sh
# ============================================================

set -uo pipefail

# ---- 設定 ----
SSH_HOST="sv16114.xserver.jp"
SSH_PORT="10022"
SSH_USER="twinklemark"
SSH_KEY="C:/projects/wts/twinklemark.key"
DB_USER="twinklemark_taxi"
DB_PASS="Smiley2525"
NAS_DIR="//LS220D679/share/06.情シス共有フォルダ/wts/backup"
KEEP_DAYS=30

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
TENANTS_CONF="${SCRIPT_DIR}/tenants.conf"

# ---- 日付 ----
DATE_STR=$(date +"%Y-%m-%d_%H-%M")
LOG_FILE="${NAS_DIR}/backup.log"

# ---- ログ関数 ----
log() {
    local ts
    ts=$(date +"%Y-%m-%d %H:%M:%S")
    local line="[${ts}] $1"
    echo "$line"
    echo "$line" >> "${LOG_FILE}"
}

# ---- テナント読み込み関数 ----
# tenants.conf を読み込み、テナントID と DB名 の組を返す
# ファイルが存在しない場合はデフォルト（kinki/twinklemark_wts）を返す
load_tenants() {
    if [ -f "${TENANTS_CONF}" ]; then
        # コメント行・空行を除外し、テナントID(第1列) と DB名(第2列) を返す
        grep -v '^\s*#' "${TENANTS_CONF}" | grep -v '^\s*$' | awk '{print $1, $2}'
    else
        log "tenants.conf が見つかりません。デフォルト設定で実行します。"
        echo "kinki twinklemark_wts"
    fi
}

# ---- メイン処理 ----
log "===== マルチテナントバックアップ開始 ====="

# NASディレクトリ存在確認
if [ ! -d "${NAS_DIR}" ]; then
    echo "エラー: NASバックアップ先が見つかりません: ${NAS_DIR}" >&2
    exit 1
fi

# テナント一覧読み込み
TENANT_LIST=$(load_tenants)
TENANT_COUNT=$(echo "${TENANT_LIST}" | wc -l | tr -d ' ')
log "対象テナント数: ${TENANT_COUNT}"

SUCCESS_COUNT=0
FAIL_COUNT=0

# 各テナントをバックアップ
while read -r TENANT_ID DB_NAME; do
    # 空行スキップ
    [ -z "${TENANT_ID}" ] && continue

    BACKUP_FILE="wts_${TENANT_ID}_${DATE_STR}.sql.gz"
    REMOTE_TMP="/tmp/${BACKUP_FILE}"
    NAS_FILE="${NAS_DIR}/${BACKUP_FILE}"

    log "[${TENANT_ID}] バックアップ開始 (DB: ${DB_NAME})"

    # Step 1: Xserverでmysqldump → gzip → /tmp保存
    log "[${TENANT_ID}] mysqldump 実行中..."
    if ! ssh -p "${SSH_PORT}" \
        -i "${SSH_KEY}" \
        -o StrictHostKeyChecking=no \
        -o ConnectTimeout=30 \
        "${SSH_USER}@${SSH_HOST}" \
        "mysqldump -u ${DB_USER} -p'${DB_PASS}' --single-transaction --routines --triggers --hex-blob ${DB_NAME} 2>/dev/null | gzip > ${REMOTE_TMP}"; then
        log "[${TENANT_ID}] エラー: mysqldump 失敗。スキップします。"
        FAIL_COUNT=$((FAIL_COUNT + 1))
        continue
    fi
    log "[${TENANT_ID}] mysqldump 完了"

    # Step 2: SCPでNASへ転送
    log "[${TENANT_ID}] SCP 転送中..."
    if ! scp -P "${SSH_PORT}" \
        -i "${SSH_KEY}" \
        -o StrictHostKeyChecking=no \
        "${SSH_USER}@${SSH_HOST}:${REMOTE_TMP}" \
        "${NAS_FILE}"; then
        log "[${TENANT_ID}] エラー: SCP 転送失敗。スキップします。"
        # リモート一時ファイルは削除を試みる
        ssh -p "${SSH_PORT}" -i "${SSH_KEY}" -o StrictHostKeyChecking=no \
            "${SSH_USER}@${SSH_HOST}" "rm -f ${REMOTE_TMP}" 2>/dev/null || true
        FAIL_COUNT=$((FAIL_COUNT + 1))
        continue
    fi
    log "[${TENANT_ID}] SCP 転送完了: ${NAS_FILE}"

    # Step 3: リモート一時ファイル削除
    ssh -p "${SSH_PORT}" -i "${SSH_KEY}" -o StrictHostKeyChecking=no \
        "${SSH_USER}@${SSH_HOST}" "rm -f ${REMOTE_TMP}" 2>/dev/null || true
    log "[${TENANT_ID}] 一時ファイル削除完了"

    # Step 4: サイズ記録
    SIZE=$(du -sh "${NAS_FILE}" 2>/dev/null | cut -f1)
    log "[${TENANT_ID}] バックアップ成功: ${BACKUP_FILE} (${SIZE})"

    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))

done <<< "${TENANT_LIST}"

# ---- 古いバックアップ一括削除（全テナント対象） ----
log "古いバックアップの削除 (${KEEP_DAYS}日超)..."
while read -r OLD_FILE; do
    [ -z "${OLD_FILE}" ] && continue
    log "削除: $(basename "${OLD_FILE}")"
    rm -f "${OLD_FILE}"
done < <(find "${NAS_DIR}" -name "wts_*.sql.gz" -mtime +"${KEEP_DAYS}" 2>/dev/null)

# ---- サマリー ----
log "===== バックアップ完了: 成功=${SUCCESS_COUNT} / 失敗=${FAIL_COUNT} / 合計=${TENANT_COUNT} ====="

# 最新バックアップ一覧
log "最新バックアップ一覧:"
ls -lh "${NAS_DIR}"/wts_*.sql.gz 2>/dev/null | tail -10 | while read -r line; do
    log "  ${line}"
done

# 失敗があった場合は終了コード1
if [ "${FAIL_COUNT}" -gt 0 ]; then
    exit 1
fi
