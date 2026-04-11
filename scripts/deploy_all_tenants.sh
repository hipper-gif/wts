#!/bin/bash
# ============================================================
# WTS 全テナント一括コード更新スクリプト
#
# tenants.conf に定義された全テナントに最新コードをデプロイする。
# .env と uploads/ は保持（上書きしない）。
#
# Xserverでは rsync が使えない可能性があるため、
# tar でアーカイブ → SCP → リモートで展開 の方式を採用。
#
# 使い方:
#   bash scripts/deploy_all_tenants.sh
#   bash scripts/deploy_all_tenants.sh --dry-run    # 実際にはデプロイせず対象を表示
#   bash scripts/deploy_all_tenants.sh --tenant acme # 特定テナントのみデプロイ
#
# 前提条件:
#   - Git Bash (Windows) で実行
#   - tenants.conf にテナント一覧が定義済み
# ============================================================

set -euo pipefail

# ---- スクリプトのディレクトリ ----
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CONF_FILE="${SCRIPT_DIR}/tenants.conf"

# ---- SSH接続設定（backup_wts.sh と共通）----
SSH_HOST="sv16114.xserver.jp"
SSH_PORT="10022"
SSH_USER="twinklemark"
SSH_KEY="C:/projects/wts/twinklemark.key"

# ---- パス設定 ----
LOCAL_CODE_DIR="$(cd "${SCRIPT_DIR}/../wts" && pwd)"
REMOTE_BASE="/home/twinklemark/tw1nkle.com/public_html"
SOURCE_DIR="${REMOTE_BASE}/Smiley/taxi/wts"

# ---- SSH共通オプション ----
SSH_OPTS="-p ${SSH_PORT} -i ${SSH_KEY} -o StrictHostKeyChecking=no -o ConnectTimeout=30"
SSH_CMD="ssh ${SSH_OPTS} ${SSH_USER}@${SSH_HOST}"
SCP_CMD="scp -P ${SSH_PORT} -i ${SSH_KEY} -o StrictHostKeyChecking=no"

# ---- オプション解析 ----
DRY_RUN=false
TARGET_TENANT=""

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --tenant)
            TARGET_TENANT="$2"
            shift 2
            ;;
        *)
            echo "不明なオプション: $1" >&2
            exit 1
            ;;
    esac
done

# ---- ログ関数 ----
log() {
    local ts
    ts=$(date +"%Y-%m-%d %H:%M:%S")
    echo "[${ts}] $1"
}

# ---- tenants.conf 存在チェック ----
if [ ! -f "${CONF_FILE}" ]; then
    echo "エラー: ${CONF_FILE} が見つかりません" >&2
    exit 1
fi

# ---- ローカルコードディレクトリチェック ----
if [ ! -d "${LOCAL_CODE_DIR}" ]; then
    echo "エラー: ローカルコードディレクトリが見つかりません: ${LOCAL_CODE_DIR}" >&2
    exit 1
fi

# ---- テナント一覧読み込み ----
declare -a TENANTS=()
while IFS=$'\t' read -r tid tdb tpath tname; do
    # コメント・空行をスキップ
    [[ -z "${tid}" || "${tid}" =~ ^# ]] && continue

    # 特定テナント指定時はフィルタ
    if [ -n "${TARGET_TENANT}" ] && [ "${tid}" != "${TARGET_TENANT}" ]; then
        continue
    fi

    TENANTS+=("${tid}|${tdb}|${tpath}|${tname}")
done < "${CONF_FILE}"

if [ ${#TENANTS[@]} -eq 0 ]; then
    echo "デプロイ対象のテナントがありません。" >&2
    exit 1
fi

log "=========================================="
log "WTS 全テナント一括コード更新"
log "=========================================="
log "対象テナント数: ${#TENANTS[@]}"

# 対象一覧表示
for entry in "${TENANTS[@]}"; do
    IFS='|' read -r tid tdb tpath tname <<< "${entry}"
    log "  - ${tid} (${tname}) → ${tpath}"
done

if [ "${DRY_RUN}" = true ]; then
    log "-- dry-run モード: 実際のデプロイは行いません --"
    exit 0
fi

log "=========================================="

# ---- Step 1: ローカルコードをアーカイブ ----
log "Step 1: ローカルコードのアーカイブ作成"

ARCHIVE_NAME="wts_deploy_$(date +%Y%m%d_%H%M%S).tar.gz"
LOCAL_ARCHIVE="/tmp/${ARCHIVE_NAME}"
REMOTE_ARCHIVE="/tmp/${ARCHIVE_NAME}"

# .env, uploads/, .git を除外してアーカイブ（sql/は含める — マイグレーション用）
(cd "${LOCAL_CODE_DIR}" && tar czf "${LOCAL_ARCHIVE}" \
    --exclude='.env' \
    --exclude='uploads' \
    --exclude='sql/applied' \
    --exclude='.git' \
    --exclude='.gitignore' \
    .)

ARCHIVE_SIZE=$(du -sh "${LOCAL_ARCHIVE}" | cut -f1)
log "アーカイブ作成完了: ${ARCHIVE_NAME} (${ARCHIVE_SIZE})"

# ---- Step 2: アーカイブをXserverに転送 ----
log "Step 2: アーカイブをXserverに転送"

${SCP_CMD} "${LOCAL_ARCHIVE}" "${SSH_USER}@${SSH_HOST}:${REMOTE_ARCHIVE}"

log "転送完了"

# ---- Step 3: 各テナントにデプロイ ----
DEPLOY_COUNT=0
FAIL_COUNT=0

for entry in "${TENANTS[@]}"; do
    IFS='|' read -r tid tdb tpath tname <<< "${entry}"
    TENANT_DIR="${REMOTE_BASE}${tpath}"

    log "デプロイ中: ${tid} (${tname}) → ${TENANT_DIR}"

    if ${SSH_CMD} bash -s <<REMOTE_SCRIPT
set -euo pipefail

TENANT_DIR="${TENANT_DIR}"
REMOTE_ARCHIVE="${REMOTE_ARCHIVE}"

if [ ! -d "\${TENANT_DIR}" ]; then
    echo "エラー: テナントディレクトリが存在しません: \${TENANT_DIR}"
    echo "先に provision_tenant.sh でプロビジョニングしてください。"
    exit 1
fi

# .env と uploads をバックアップ（シンボリックリンクや特殊ファイルも保護）
BACKUP_DIR="/tmp/wts_deploy_backup_${tid}_\$\$"
mkdir -p "\${BACKUP_DIR}"

# .env を退避
if [ -f "\${TENANT_DIR}/.env" ]; then
    cp "\${TENANT_DIR}/.env" "\${BACKUP_DIR}/.env"
fi

# uploads を退避（存在する場合）
if [ -d "\${TENANT_DIR}/uploads" ]; then
    cp -a "\${TENANT_DIR}/uploads" "\${BACKUP_DIR}/uploads"
fi

# アーカイブを展開（既存ファイルを上書き）
cd "\${TENANT_DIR}"
tar xzf "\${REMOTE_ARCHIVE}"

# .env を復元
if [ -f "\${BACKUP_DIR}/.env" ]; then
    cp "\${BACKUP_DIR}/.env" "\${TENANT_DIR}/.env"
    chmod 600 "\${TENANT_DIR}/.env"
fi

# uploads を復元
if [ -d "\${BACKUP_DIR}/uploads" ]; then
    rm -rf "\${TENANT_DIR}/uploads"
    cp -a "\${BACKUP_DIR}/uploads" "\${TENANT_DIR}/uploads"
fi

# バックアップ削除
rm -rf "\${BACKUP_DIR}"

# マイグレーション実行（未適用のSQLがあれば自動適用）
if [ -f "\${TENANT_DIR}/sql/run_migration.php" ]; then
    echo "マイグレーション実行中: ${tid}"
    cd "\${TENANT_DIR}"
    php sql/run_migration.php 2>&1 || echo "警告: マイグレーションでエラーが発生しました（${tid}）"
fi

echo "デプロイ完了: ${tid}"
REMOTE_SCRIPT
    then
        DEPLOY_COUNT=$((DEPLOY_COUNT + 1))
        log "完了: ${tid}"
    else
        log "エラー: ${tid} のデプロイに失敗しました"
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
done

# ---- Step 4: リモートのアーカイブ削除 ----
log "Step 4: リモートアーカイブ削除"
${SSH_CMD} "rm -f ${REMOTE_ARCHIVE}"

# ---- Step 5: ローカルのアーカイブ削除 ----
rm -f "${LOCAL_ARCHIVE}"

# ---- 完了メッセージ ----
log "=========================================="
log "全テナント一括コード更新完了"
log "成功: ${DEPLOY_COUNT} / 失敗: ${FAIL_COUNT} / 合計: ${#TENANTS[@]}"
log "=========================================="
