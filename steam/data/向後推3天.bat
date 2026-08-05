@echo off
REM 確保以系統管理員執行本 .bat 
powershell -Command "Set-Date (Get-Date).AddDays(4)"
echo 系統時間已向後推3 天：%date% %time%
