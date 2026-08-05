@echo off
setlocal
cd /d "%~dp0"

echo ==========================================
echo  UNLIGHT Bonus Navigator EXE Builder
echo ==========================================
echo.

if not exist "unlight_bonus_navigator.py" (
  echo [ERROR] Missing unlight_bonus_navigator.py
  pause
  exit /b 1
)

if not exist "ul_sniffer.py" (
  echo [ERROR] Missing ul_sniffer.py
  echo Copy your working ul_sniffer.py into this folder.
  pause
  exit /b 1
)

set "PYTHON_CMD="

if exist ".venv\Scripts\python.exe" (
  set "PYTHON_CMD=.venv\Scripts\python.exe"
) else (
  where py >nul 2>nul
  if not errorlevel 1 set "PYTHON_CMD=py -3.11"
)

if not defined PYTHON_CMD (
  where python >nul 2>nul
  if not errorlevel 1 set "PYTHON_CMD=python"
)

if not defined PYTHON_CMD (
  echo [ERROR] Python 3.11 was not found.
  pause
  exit /b 1
)

echo [1/4] Python:
%PYTHON_CMD% --version
if errorlevel 1 goto :failed

echo.
echo [2/4] Installing build dependencies...
%PYTHON_CMD% -m pip install --upgrade pip
if errorlevel 1 goto :failed
%PYTHON_CMD% -m pip install -r requirements-build.txt
if errorlevel 1 goto :failed

echo.
echo [3/4] Checking source syntax...
%PYTHON_CMD% -m py_compile unlight_bonus_navigator.py ul_sniffer.py
if errorlevel 1 goto :failed

echo.
echo [4/4] Building EXE...
%PYTHON_CMD% -m PyInstaller --noconfirm --clean UnlightBonusNavigator.spec
if errorlevel 1 goto :failed

echo.
echo ==========================================
echo BUILD SUCCESS
echo EXE:
echo %CD%\dist\UnlightBonusNavigator.exe
echo ==========================================
explorer "%CD%\dist"
pause
exit /b 0

:failed
echo.
echo ==========================================
echo BUILD FAILED
echo Please copy the complete error output.
echo ==========================================
pause
exit /b 1
