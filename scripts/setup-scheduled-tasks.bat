@echo off
REM Batch-Version zum Einrichten von Scheduled Tasks
REM Startet das PowerShell-Skript als Administrator

echo === Einrichten von Scheduled Tasks ===
echo.
echo Dieses Skript muss als Administrator ausgefuehrt werden.
echo.

REM Prüfe ob als Administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo FEHLER: Dieses Skript muss als Administrator ausgefuehrt werden!
    echo Rechtsklick auf diese Datei -^> "Als Administrator ausfuehren"
    pause
    exit /b 1
)

REM Starte PowerShell-Skript
powershell.exe -ExecutionPolicy Bypass -File "%~dp0setup-scheduled-tasks.ps1"

pause




