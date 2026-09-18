#!/bin/bash
# ============================================================
# WTS テナントプロビジョニングスクリプト
#
# 新テナントをXserver上にセットアップする。
# - テナントディレクトリ作成
# - 既存テナントからコードをコピー（.env / uploads は除外）
# - .env 生成
# - uploads ディレクトリ作成
# - SQLマイグレーション実行（sql/ 配下の番号付きファイルを順次実行）
# - system_name 設定
# - 管理者ユーザー作成（login_id: admin）
#
# 使い方:
#   bash scripts/provision_tenant.sh <テナントID> <DB名> <ベースパス> <表示名> [company_info.json]
#
# 例:
#   bash scripts/provision_tenant.sh acme twinklemark_wts_acme /wts-tenants/acme "テナントA社"
#   bash scripts/provision_tenant.sh acme twinklemark_wts_acme /wts-tenants/acme "テナントA社" /tmp/company.json
#
# company_info.json が指定された場合、事業者情報をDBに自動登録する。
# parse_hearing_sheet.py の出力から生成できる。
#
# 前提条件:
#   - XserverでDB（DB名）を管理パネルから事前作成済み
#   - Git Bash (Windows) で実行
#   - DB認証は環境変数 WTS_DB_USER / WTS_DB_PASS で渡す
#     （未設定ならサーバー上のSmiley本番 .env から自動取得。スクリプトに直書きしない）
#   - SSH鍵は WTS_SSH_KEY で上書き可
# ============================================================

set -euo pipefail

# ---- 引数チェック ----
if [ $# -lt 4 ]; then
    echo "使い方: $0 <テナントID> <DB名> <ベースパス> <表示名>"
    echo "例:     $0 acme twinklemark_wts_acme /wts-tenants/acme \"テナントA社\""
    exit 1
fi

TENANT_ID="$1"
DB_NAME="$2"
BASE_PATH="$3"
SYSTEM_NAME="$4"
COMPANY_JSON="${5:-}"

# ---- SSH接続設定（backup_wts.sh と共通）----
SSH_HOST="sv16114.xserver.jp"
SSH_PORT="10022"
SSH_USER="twinklemark"
# 鍵の場所は backup_wts.sh と揃える（このマシンでの実パス）。環境変数 WTS_SSH_KEY で上書き可。
SSH_KEY="${WTS_SSH_KEY:-C:/Users/nikon/projects/SmartClock/twinklemark.key}"

# ---- DB接続情報 ----
# 秘匿情報はこのファイルに書かない（社内規約の禁止リスト）。
# 環境変数 WTS_DB_USER / WTS_DB_PASS が最優先。未設定なら
# 稼働中Smiley本番の .env（サーバー上の正本）から読む。
DB_HOST="localhost"
DB_USER="${WTS_DB_USER:-}"
DB_PASS="${WTS_DB_PASS:-}"

# ---- パス設定 ----
REMOTE_BASE="/home/twinklemark/tw1nkle.com/public_html"
SOURCE_DIR="${REMOTE_BASE}/Smiley/taxi/wts"
TENANT_DIR="${REMOTE_BASE}${BASE_PATH}"

# ---- SSH共通オプション ----
SSH_OPTS="-p ${SSH_PORT} -i ${SSH_KEY} -o StrictHostKeyChecking=no -o ConnectTimeout=30"
SSH_CMD="ssh ${SSH_OPTS} ${SSH_USER}@${SSH_HOST}"

# ---- DB認証情報の解決（環境変数が無ければサーバー上の .env から取得）----
if [ -z "${DB_USER}" ] || [ -z "${DB_PASS}" ]; then
    echo "DB認証情報を Smiley本番の .env から取得します"
    SRC_ENV="/home/twinklemark/tw1nkle.com/public_html/Smiley/taxi/wts/.env"
    DB_USER="${DB_USER:-$(${SSH_CMD} "grep '^DB_USER=' ${SRC_ENV} | cut -d= -f2-" | tr -d '\r')}"
    DB_PASS="${DB_PASS:-$(${SSH_CMD} "grep '^DB_PASS=' ${SRC_ENV} | cut -d= -f2-" | tr -d '\r')}"
fi
if [ -z "${DB_USER}" ] || [ -z "${DB_PASS}" ]; then
    echo "エラー: DB認証情報を取得できません。WTS_DB_USER / WTS_DB_PASS を設定してください。" >&2
    echo "  正本: python scripts/mneme_api.py get credentials \"service_name=eq.ssh-sv16114.xserver.jp\"" >&2
    exit 1
fi

# ---- ログ関数 ----
log() {
    local ts
    ts=$(date +"%Y-%m-%d %H:%M:%S")
    echo "[${ts}] $1"
}

# ---- 初期パスワード生成（ランダム12文字）----
# head -c で途中打ち切りするとパイプ前段がSIGPIPEで落ち、set -o pipefail と
# 相まってスクリプトごと終了コード141で死ぬ。入力側を先に打ち切り、
# 切り出しはbashの文字列展開で行う（パイプの早期クローズを作らない）。
INIT_PASSWORD_RAW=$(head -c 96 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9')
INIT_PASSWORD="${INIT_PASSWORD_RAW:0:12}"
if [ ${#INIT_PASSWORD} -ne 12 ]; then
    echo "エラー: 初期パスワードを生成できませんでした" >&2
    exit 1
fi

log "=========================================="
log "WTS テナントプロビジョニング開始"
log "=========================================="
log "テナントID: ${TENANT_ID}"
log "DB名:       ${DB_NAME}"
log "ベースパス: ${BASE_PATH}"
log "表示名:     ${SYSTEM_NAME}"
log "テナントDir: ${TENANT_DIR}"
log "=========================================="

# ---- Step 1: テナントディレクトリ作成 & コードコピー ----
log "Step 1: テナントディレクトリ作成 & コードコピー"

${SSH_CMD} bash -s <<REMOTE_SCRIPT
set -euo pipefail

# ディレクトリが既に存在する場合は中止
if [ -d "${TENANT_DIR}" ]; then
    echo "警告: テナントディレクトリが既に存在します: ${TENANT_DIR}"
    echo "既存のテナントを上書きしたくない場合は Ctrl+C で中止してください"
fi

# 親ディレクトリ作成
mkdir -p "\$(dirname "${TENANT_DIR}")"

# 既存テナントのコードをコピー（.env と uploads を除外）
# tar で除外しながらコピー
cd "${SOURCE_DIR}"
tar cf - --exclude='.env' --exclude='uploads' --exclude='.git' . | (mkdir -p "${TENANT_DIR}" && cd "${TENANT_DIR}" && tar xf -)

echo "コードコピー完了"
REMOTE_SCRIPT

log "Step 1 完了"

# ---- Step 2: .env 生成 ----
log "Step 2: .env ファイル生成"

${SSH_CMD} bash -s <<REMOTE_SCRIPT
cat > "${TENANT_DIR}/.env" <<'ENVEOF'
DB_HOST=${DB_HOST}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
APP_BASE_PATH=${BASE_PATH}
ENVEOF
chmod 600 "${TENANT_DIR}/.env"
echo ".env 生成完了"
REMOTE_SCRIPT

log "Step 2 完了"

# ---- Step 3: uploads ディレクトリ作成 ----
log "Step 3: uploads ディレクトリ作成"

${SSH_CMD} bash -s <<REMOTE_SCRIPT
mkdir -p "${TENANT_DIR}/uploads/documents"
chmod 755 "${TENANT_DIR}/uploads"
chmod 755 "${TENANT_DIR}/uploads/documents"
echo "uploads ディレクトリ作成完了"
REMOTE_SCRIPT

log "Step 3 完了"

# ---- Step 4: SQLマイグレーション実行 ----
log "Step 4: SQLマイグレーション実行"

${SSH_CMD} bash -s <<REMOTE_SCRIPT
set -euo pipefail

cd "${TENANT_DIR}"

# 空のDBかどうかを判定する
TABLE_COUNT=\$(mysql -u "${DB_USER}" -p'${DB_PASS}' -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}'" 2>/dev/null || echo 0)

if [ "\$TABLE_COUNT" -eq 0 ]; then
    # 空DB: 基盤スキーマ→初期データ→マイグレーションを適用済み登録、の順で流す。
    # sql/ の連番SQLは003始まりで土台のテーブルを作れないため、この順でないと失敗する。
    echo "空のDBを検出。基盤スキーマを適用します。"
    mysql -u "${DB_USER}" -p'${DB_PASS}' "${DB_NAME}" < sql/tenant_base/000_schema.sql
    mysql -u "${DB_USER}" -p'${DB_PASS}' "${DB_NAME}" < sql/tenant_base/001_seed.sql
    php sql/run_migration.php --baseline
    echo "基盤スキーマ + 初期データ + baseline登録 完了"
else
    echo "既存テーブルを検出（\${TABLE_COUNT}件）。未適用マイグレーションのみ実行します。"
    php sql/run_migration.php
fi

echo "マイグレーション完了"
REMOTE_SCRIPT

log "Step 4 完了"

# ---- Step 5: system_name 設定 ----
log "Step 5: system_name 設定（${SYSTEM_NAME}）"

${SSH_CMD} bash -s <<REMOTE_SCRIPT
mysql -u "${DB_USER}" -p'${DB_PASS}' "${DB_NAME}" <<'SQLEOF'
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('system_name', '${SYSTEM_NAME}')
ON DUPLICATE KEY UPDATE setting_value = '${SYSTEM_NAME}';
SQLEOF
echo "system_name 設定完了"
REMOTE_SCRIPT

log "Step 5 完了"

# ---- Step 5.5: 事業者情報登録（company_info.json 指定時）----
if [ -n "${COMPANY_JSON}" ] && [ -f "${COMPANY_JSON}" ]; then
    log "Step 5.5: 事業者情報登録（${COMPANY_JSON}）"

    # JSONからPHPで読み取ってDB投入
    ${SSH_CMD} bash -s <<REMOTE_SCRIPT
    php -r "
    \\\$json = json_decode(file_get_contents('php://stdin'), true);
    if (!\\\$json) { echo 'JSONパースエラー'; exit(1); }
    \\\$pdo = new PDO('mysql:host=${DB_HOST};dbname=${DB_NAME};charset=utf8mb4', '${DB_USER}', '${DB_PASS}');
    \\\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \\\$stmt = \\\$pdo->prepare('UPDATE company_info SET
        company_name = ?, representative_name = ?, postal_code = ?,
        address = ?, phone = ?, fax = ?,
        manager_name = ?, manager_email = ?, license_number = ?
        WHERE id = 1');
    \\\$stmt->execute([
        \\\$json['company_name'] ?? '',
        \\\$json['representative'] ?? '',
        \\\$json['postal_code'] ?? '',
        \\\$json['address'] ?? '',
        \\\$json['phone'] ?? '',
        \\\$json['fax'] ?? '',
        \\\$json['manager_name'] ?? '',
        \\\$json['manager_email'] ?? '',
        \\\$json['license_number'] ?? ''
    ]);
    echo '事業者情報登録完了';
    " < "${COMPANY_JSON}"
REMOTE_SCRIPT

    log "Step 5.5 完了"
else
    if [ -n "${COMPANY_JSON}" ]; then
        log "警告: 指定されたJSONファイルが見つかりません: ${COMPANY_JSON}"
    fi
fi

# ---- Step 6: 管理者ユーザー作成 ----
log "Step 6: 管理者ユーザー作成"

# bcryptハッシュをPHPで生成してリモートで実行
${SSH_CMD} bash -s <<REMOTE_SCRIPT
# PHPでbcryptハッシュ生成 & INSERT
php -r "
\\\$hash = password_hash('${INIT_PASSWORD}', PASSWORD_DEFAULT);
\\\$sql = \"INSERT INTO users (name, login_id, password, permission_level, is_admin, is_active, created_at)
VALUES ('管理者', 'admin', '\" . \\\$hash . \"', 'Admin', 1, 1, NOW())
ON DUPLICATE KEY UPDATE password = '\" . \\\$hash . \"', is_active = 1;\";
\\\$pdo = new PDO('mysql:host=${DB_HOST};dbname=${DB_NAME};charset=utf8mb4', '${DB_USER}', '${DB_PASS}');
\\\$pdo->exec(\\\$sql);
echo '管理者ユーザー作成完了';
"
REMOTE_SCRIPT

log "Step 6 完了"

# ---- 完了メッセージ ----
log "=========================================="
log "テナントプロビジョニング完了!"
log "=========================================="
log ""
log "テナントURL: https://tw1nkle.com${BASE_PATH}/"
log "管理者ログイン:"
log "  login_id: admin"
log "  password: ${INIT_PASSWORD}"
log ""
log "重要: 初期パスワードを安全な場所に記録し、初回ログイン後に変更してください。"
log "注意: DBはXserver管理パネルで事前に作成済みである必要があります。"
log "=========================================="
