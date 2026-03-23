# ============================================================
# WTS バックアップ タスクスケジューラ登録スクリプト
# 管理者権限で実行してください
# ============================================================

$TASK_NAME   = "WTS_DatabaseBackup"
$SCRIPT_PATH = "C:\projects\wts\scripts\backup_wts.ps1"
$LOG_PATH    = "\\LS220D679\share\06.情シス共有フォルダ\wts\backup\task_scheduler.log"

# 既存タスクを削除（再登録用）
$existing = Get-ScheduledTask -TaskName $TASK_NAME -ErrorAction SilentlyContinue
if ($existing) {
    Unregister-ScheduledTask -TaskName $TASK_NAME -Confirm:$false
    Write-Host "既存タスクを削除しました"
}

# アクション: PowerShell でスクリプト実行
$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NonInteractive -NoProfile -ExecutionPolicy Bypass -File `"$SCRIPT_PATH`""

# トリガー: 毎日 02:00
$trigger = New-ScheduledTaskTrigger -Daily -At "02:00"

# 設定: ネットワーク接続時のみ実行 / AC電源接続時のみ不要
$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable

# 実行ユーザー: 現在のユーザー
$principal = New-ScheduledTaskPrincipal `
    -UserId $env:USERNAME `
    -RunLevel Highest

# タスク登録
Register-ScheduledTask `
    -TaskName $TASK_NAME `
    -TaskPath "\WTS\" `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description "WTS (介護タクシー運行管理) Xserver MySQL バックアップ - 毎日02:00実行"

Write-Host ""
Write-Host "タスクスケジューラ登録完了"
Write-Host "タスク名: \WTS\$TASK_NAME"
Write-Host "実行時刻: 毎日 02:00"
Write-Host "スクリプト: $SCRIPT_PATH"
Write-Host ""
Write-Host "確認コマンド:"
Write-Host "  Get-ScheduledTask -TaskPath '\WTS\'"
Write-Host ""
Write-Host "手動テスト実行:"
Write-Host "  Start-ScheduledTask -TaskName '$TASK_NAME' -TaskPath '\WTS\'"
