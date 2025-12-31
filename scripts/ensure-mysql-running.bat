@echo off
REM MySQL Ensure Running Script (Batch-Version)
REM Prüft ob MySQL läuft, führt Recovery durch wenn nötig, startet MySQL und wartet bis es bereit ist

setlocal enabledelayedexpansion

set MAX_WAIT=30
set MYSQL_DATA_DIR=C:\xampp\mysql\data
set MYSQL_BIN_DIR=C:\xampp\mysql\bin
set MYSQL_START_SCRIPT=C:\xampp\mysql_start.bat

echo === MySQL Status-Pruefung ===
echo.

REM Prüfe ob MySQL-Prozess läuft
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo MySQL-Prozess laeuft.
    echo Pruefe ob Port 3306 antwortet...
    
    REM Einfache Port-Prüfung (PowerShell)
    powershell -Command "$tcp = New-Object System.Net.Sockets.TcpClient; try { $tcp.Connect('localhost', 3306); $tcp.Close(); exit 0 } catch { exit 1 }"
    if "%ERRORLEVEL%"=="0" (
        echo OK: MySQL laeuft und ist bereit
        exit /b 0
    ) else (
        echo MySQL-Prozess laeuft, aber Port antwortet nicht. Stoppe und starte neu...
        taskkill /F /IM mysqld.exe >nul 2>&1
        timeout /t 2 /nobreak >nul
    )
)

REM Prüfe auf Aria-Fehler
set NEEDS_RECOVERY=0
if exist "%MYSQL_DATA_DIR%\mysql_error.log" (
    findstr /C:"Aria recovery failed" /C:"aria_chk" /C:"corrupt" "%MYSQL_DATA_DIR%\mysql_error.log" >nul 2>&1
    if "%ERRORLEVEL%"=="0" (
        set NEEDS_RECOVERY=1
        echo Aria-Fehler in Log erkannt
    )
)

REM Prüfe aria_log_control
if exist "%MYSQL_DATA_DIR%\aria_log_control" (
    for %%A in ("%MYSQL_DATA_DIR%\aria_log_control") do (
        if %%~zA==0 (
            set NEEDS_RECOVERY=1
        )
    )
)

REM Führe Recovery durch wenn nötig
if "%NEEDS_RECOVERY%"=="1" (
    echo Fuehre MySQL Recovery durch...
    
    REM Lösche aria_log Dateien
    if exist "%MYSQL_DATA_DIR%\aria_log.*" (
        echo   Loesche aria_log Dateien...
        del /F /Q "%MYSQL_DATA_DIR%\aria_log.*" >nul 2>&1
    )
    
    REM Lösche aria_log_control wenn korrupt
    if exist "%MYSQL_DATA_DIR%\aria_log_control" (
        for %%A in ("%MYSQL_DATA_DIR%\aria_log_control") do (
            if %%~zA==0 (
                echo   Loesche korrupte aria_log_control...
                del /F /Q "%MYSQL_DATA_DIR%\aria_log_control" >nul 2>&1
            )
        )
    )
    
    echo   Recovery abgeschlossen
    echo.
)

REM Starte MySQL
echo Starte MySQL...
if exist "%MYSQL_START_SCRIPT%" (
    call "%MYSQL_START_SCRIPT%" >nul 2>&1
    timeout /t 2 /nobreak >nul
) else (
    echo FEHLER: mysql_start.bat nicht gefunden
    echo Bitte starte MySQL manuell ueber XAMPP Control Panel
    exit /b 1
)

REM Warte bis MySQL bereit ist
echo Warte auf MySQL (max. %MAX_WAIT% Sekunden)...
set WAITED=0

:wait_loop
powershell -Command "$tcp = New-Object System.Net.Sockets.TcpClient; try { $tcp.Connect('localhost', 3306); $tcp.Close(); exit 0 } catch { exit 1 }" >nul 2>&1
if "%ERRORLEVEL%"=="0" (
    echo OK: MySQL laeuft und ist bereit
    exit /b 0
)

set /a WAITED+=1
if %WAITED% geq %MAX_WAIT% (
    echo FEHLER: MySQL ist nach %MAX_WAIT% Sekunden nicht bereit
    echo Bitte pruefe:
    echo   1. MySQL-Logs: %MYSQL_DATA_DIR%\mysql_error.log
    echo   2. Port 3306 ist nicht blockiert
    echo   3. MySQL-Konfiguration ist korrekt
    exit /b 1
)

if %WAITED% %% 5 == 0 (
    echo   Warte auf MySQL... (!WAITED!/%MAX_WAIT% Sekunden)
)

timeout /t 1 /nobreak >nul
goto wait_loop
