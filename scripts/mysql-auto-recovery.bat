@echo off
REM MySQL/MariaDB Auto-Recovery Script (Batch-Version)
REM Prüft und repariert Aria-Fehler automatisch

echo === MySQL Auto-Recovery ===
echo.

REM Prüfe ob MySQL läuft und stoppe es
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo MySQL laeuft. Stoppe MySQL...
    taskkill /F /IM mysqld.exe >nul 2>&1
    timeout /t 2 /nobreak >nul
)

REM Navigiere zu MySQL Data Directory
cd /d C:\xampp\mysql\data

REM Lösche aria_log Dateien
echo Loesche aria_log Dateien...
for %%f in (aria_log.*) do del /F /Q "%%f" >nul 2>&1

REM Lösche aria_log_control
echo Loesche aria_log_control...
del /F /Q aria_log_control >nul 2>&1

echo.
echo Reparatur abgeschlossen!
echo Starte MySQL jetzt ueber XAMPP Control Panel.
echo.
pause

