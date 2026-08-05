@echo off
REM 請以 ANSI（Big5）或 UTF-8 w/o BOM 編碼儲存此檔案

REM 1) 切到本批次檔所在資料夾
cd /d %~dp0

REM 2) 強制終止所有 python.exe
taskkill /F /IM python.exe >nul 2>&1

REM 3) 等 2 秒
timeout /T 2 >nul

REM 4) 執行匯入腳本（同視窗）
start "importScript" cmd /c py "%~dp0import_and_compare.py"

REM 5) 再次強制終止 python.exe
taskkill /F /IM python.exe >nul 2>&1

REM 6) 等 5 秒
timeout /T 5 >nul

REM 7) 新視窗執行 ChannelWatcher，保持開啟
start "SteamLog" cmd /c py "%~dp0channel_watcher_unlight.py"

REM 8) 主視窗按任意鍵才會關閉 pause

