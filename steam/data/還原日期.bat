@echo off
REM =====================================================
REM   sync_time.bat — 以 SYSTEM 權限啟動並同步系統時間
REM   必須以「系統管理員」身分執行本 .bat
REM =====================================================

echo [%date% %time%] 開始同步系統時間...
echo.

REM 嘗試啟動 Windows Time 服務（若已啟動則無害）
net start w32time >nul 2>&1
if errorlevel 1 (
    echo 無法啟動 Windows Time 服務，嘗試重新註冊...
    w32tm /register >nul 2>&1
    net start w32time >nul 2>&1
)

REM 強制向預設 NTP 伺服器同步
echo 正在向 NTP 伺服器同步…
w32tm /resync /force

if errorlevel 1 (
    echo.
    echo ? 同步失敗，請檢查網路或服務設定。
) else (
    echo.
    echo ? 同步成功！
)

echo.
pause
