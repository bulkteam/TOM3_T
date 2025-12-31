@echo off
REM MySQL Fix: Aria Recovery und Plugin Table (Batch-Version)
REM Behebt die spezifischen Fehler: Aria recovery failed und mysql.plugin table

echo === MySQL Aria ^& Plugin Table Reparatur ===
echo.

REM Stoppe MySQL falls es läuft
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo Stoppe MySQL...
    taskkill /F /IM mysqld.exe >nul 2>&1
    timeout /t 3 /nobreak >nul
    echo   OK: MySQL gestoppt
)

set MYSQL_DATA_DIR=C:\xampp\mysql\data
set MYSQL_BIN_DIR=C:\xampp\mysql\bin

REM Lösche aria_log Dateien
echo.
echo Loesche aria_log Dateien...
if exist "%MYSQL_DATA_DIR%\aria_log.*" (
    for /f %%f in ('dir /b "%MYSQL_DATA_DIR%\aria_log.*" 2^>nul') do (
        del /F /Q "%MYSQL_DATA_DIR%\%%f" >nul 2>&1
    )
    echo   OK: aria_log Dateien geloescht
) else (
    echo   OK: Keine aria_log Dateien gefunden
)

REM Lösche aria_log_control
echo.
echo Loesche aria_log_control...
if exist "%MYSQL_DATA_DIR%\aria_log_control" (
    del /F /Q "%MYSQL_DATA_DIR%\aria_log_control" >nul 2>&1
    echo   OK: aria_log_control geloescht
) else (
    echo   OK: aria_log_control nicht vorhanden
)

REM Lösche mysql.plugin Tabelle (wird beim Start neu erstellt)
echo.
echo Pruefe mysql.plugin Tabelle...
if exist "%MYSQL_DATA_DIR%\mysql\plugin.MAD" (
    echo   Loesche mysql.plugin Tabelle...
    del /F /Q "%MYSQL_DATA_DIR%\mysql\plugin.MAD" >nul 2>&1
    echo   OK: plugin.MAD geloescht
)
if exist "%MYSQL_DATA_DIR%\mysql\plugin.MAI" (
    del /F /Q "%MYSQL_DATA_DIR%\mysql\plugin.MAI" >nul 2>&1
    echo   OK: plugin.MAI geloescht
)
if exist "%MYSQL_DATA_DIR%\mysql\plugin.frm" (
    del /F /Q "%MYSQL_DATA_DIR%\mysql\plugin.frm" >nul 2>&1
    echo   OK: plugin.frm geloescht
)

if not exist "%MYSQL_DATA_DIR%\mysql\plugin.MAD" (
    if not exist "%MYSQL_DATA_DIR%\mysql\plugin.MAI" (
        if not exist "%MYSQL_DATA_DIR%\mysql\plugin.frm" (
            echo   OK: mysql.plugin wird beim Start neu erstellt
        )
    )
)

echo.
echo === Reparatur abgeschlossen ===
echo.
echo Naechste Schritte:
echo   1. Starte MySQL ueber XAMPP Control Panel
echo   2. Oder fuehre aus: scripts\ensure-mysql-running.bat
echo.
echo MySQL sollte jetzt starten koennen!

pause
