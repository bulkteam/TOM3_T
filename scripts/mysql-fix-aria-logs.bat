@echo off
REM MySQL Aria-Logs Fix
REM Löscht aria_log_control und alle aria_log.* Dateien

echo === MySQL Aria-Logs Fix ===
echo.

REM Stoppe MySQL falls es läuft
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo Stoppe MySQL...
    taskkill /F /IM mysqld.exe >nul 2>&1
    timeout /t 2 /nobreak >nul
    echo   OK: MySQL gestoppt
    echo.
)

set MYSQL_DATA_DIR=C:\xampp\mysql\data

REM Lösche aria_log_control
echo Loesche aria_log_control...
if exist "%MYSQL_DATA_DIR%\aria_log_control" (
    del /F /Q "%MYSQL_DATA_DIR%\aria_log_control" >nul 2>&1
    echo   OK: aria_log_control geloescht
) else (
    echo   OK: aria_log_control nicht vorhanden
)

REM Lösche alle aria_log.* Dateien
echo.
echo Loesche aria_log.* Dateien...
if exist "%MYSQL_DATA_DIR%\aria_log.*" (
    for /f %%f in ('dir /b "%MYSQL_DATA_DIR%\aria_log.*" 2^>nul') do (
        del /F /Q "%MYSQL_DATA_DIR%\%%f" >nul 2>&1
    )
    echo   OK: aria_log.* Dateien geloescht
) else (
    echo   OK: Keine aria_log.* Dateien gefunden
)

echo.
echo === Fertig ===
echo.
echo MySQL kann jetzt gestartet werden!
echo.
