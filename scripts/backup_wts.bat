@echo off
rem ASCII only in this file: cmd parses cp932, UTF-8 comments break execution (known schtasks trap)
rem %~dp0 = directory of this bat (path survives workspace moves)
set "SCRIPT_DIR=%~dp0"
"C:\Program Files\Git\bin\bash.exe" "%SCRIPT_DIR%backup_wts.sh" >> "%SCRIPT_DIR%backup_task.log" 2>&1
