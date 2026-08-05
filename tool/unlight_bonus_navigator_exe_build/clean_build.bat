@echo off
cd /d "%~dp0"
if exist build rmdir /s /q build
if exist dist rmdir /s /q dist
echo Build output cleaned.
pause
