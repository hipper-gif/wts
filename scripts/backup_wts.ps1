# ============================================================
# WTS データベース自動バックアップスクリプト
# 対象: twinklemark_wts (Xserver MySQL)
# 保存先: \\LS220D679\share\06.情シス共有フォルダ\wts\backup\
# ============================================================

$ErrorActionPreference = "Stop"

$SSH_HOST  = "sv16114.xserver.jp"
$SSH_PORT  = "10022"
$SSH_USER  = "twinklemark"
$SSH_KEY   = "C:\projects\wts\twinklemark.key"
$DB_NAME   = "twinklemark_wts"
$DB_USER   = "twinklemark_taxi"
$DB_PASS   = "Smiley2525"
$NAS_DIR   = "\\LS220D679\share\06.情シス共有フォルダ\wts\backup"
$KEEP_DAYS = 30

$DATE_STR    = Get-Date -Format "yyyy-MM-dd_HH-mm"
$BACKUP_FILE = "wts_${DATE_STR}.sql.gz"
$REMOTE_TMP  = "/tmp/$BACKUP_FILE"
$LOG_FILE    = Join-Path $NAS_DIR "backup.log"
$NAS_FILE    = Join-Path $NAS_DIR $BACKUP_FILE

function Write-Log($msg) {
    $ts   = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$ts] $msg"
    Write-Host $line
    Add-Content -Path $LOG_FILE -Value $line -Encoding UTF8
}

try {
    Write-Log "バックアップ開始: $BACKUP_FILE"

    if (-not (Test-Path $NAS_DIR)) {
        throw "NASバックアップ先が見つかりません: $NAS_DIR"
    }

    # Step 1: Xserverでmysqldump → gzip → /tmp保存
    Write-Log "mysqldump 実行中..."
    $dump_cmd = "mysqldump -u $DB_USER -p'$DB_PASS' --single-transaction --routines --triggers --hex-blob $DB_NAME 2>/dev/null | gzip > $REMOTE_TMP"
    $ssh_args = @("-p", $SSH_PORT, "-i", $SSH_KEY, "-o", "StrictHostKeyChecking=no", "-o", "ConnectTimeout=30", "${SSH_USER}@${SSH_HOST}", $dump_cmd)
    & ssh @ssh_args
    if ($LASTEXITCODE -ne 0) { throw "mysqldump 失敗 (exit=$LASTEXITCODE)" }
    Write-Log "mysqldump 完了"

    # Step 2: SCPでNASへ転送
    Write-Log "SCP 転送中..."
    $scp_args = @("-P", $SSH_PORT, "-i", $SSH_KEY, "-o", "StrictHostKeyChecking=no", "${SSH_USER}@${SSH_HOST}:${REMOTE_TMP}", $NAS_FILE)
    & scp @scp_args
    if ($LASTEXITCODE -ne 0) { throw "SCP 転送失敗 (exit=$LASTEXITCODE)" }
    Write-Log "SCP 転送完了: $NAS_FILE"

    # Step 3: リモート一時ファイル削除
    $rm_args = @("-p", $SSH_PORT, "-i", $SSH_KEY, "-o", "StrictHostKeyChecking=no", "${SSH_USER}@${SSH_HOST}", "rm -f $REMOTE_TMP")
    & ssh @rm_args | Out-Null
    Write-Log "一時ファイル削除完了"

    # Step 4: 古いバックアップ削除
    $cutoff   = (Get-Date).AddDays(-$KEEP_DAYS)
    $old_list = Get-ChildItem -Path $NAS_DIR -Filter "wts_*.sql.gz" | Where-Object { $_.LastWriteTime -lt $cutoff }
    foreach ($f in $old_list) {
        Remove-Item $f.FullName -Force
        Write-Log "古いバックアップ削除: $($f.Name)"
    }

    # Step 5: サイズ確認
    $size_mb = [math]::Round((Get-Item $NAS_FILE).Length / 1MB, 2)
    Write-Log "バックアップ成功: $BACKUP_FILE (${size_mb} MB)"

    $recent = Get-ChildItem -Path $NAS_DIR -Filter "wts_*.sql.gz" | Sort-Object LastWriteTime -Descending | Select-Object -First 5
    Write-Log "最新バックアップ一覧:"
    foreach ($f in $recent) {
        $mb = [math]::Round($f.Length / 1MB, 2)
        Write-Log "  $($f.Name) ($mb MB)"
    }

} catch {
    Write-Log "エラー: $_"
    exit 1
}
