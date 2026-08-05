@echo off
REM 切到你的程式資料夾
pushd "%~dp0"

REM 啟動 Python 並執行 steam_tmp_path.py
REM 如果你想把 log 都留在檔案裡，可以加上   >> steam.log 2>&1
python channel_watcher_unlight.py