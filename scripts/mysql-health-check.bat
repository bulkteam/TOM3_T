@echo off
REM MySQL Health Check - Batch Wrapper
REM Ruft das PowerShell-Script auf

echo === MySQL Health Check ===
echo.

REM Pruefe ob PowerShell verfuegbar ist
powershell -Command "exit 0" >nul 2>&1
if "%ERRORLEVEL%" neq "0" (
    echo FEHLER: PowerShell nicht verfuegbar
    pause
    exit /b 1
)

REM Fuehre PowerShell-Script aus
cd /d "%~dp0"
powershell -ExecutionPolicy Bypass -File "%~dp0mysql-health-check.ps1" %*

pause


