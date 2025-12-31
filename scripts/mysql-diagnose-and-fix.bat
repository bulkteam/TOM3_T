@echo off
REM MySQL Diagnose und Reparatur Script (Batch-Version)
REM Analysiert MySQL-Logs und behebt häufige Startprobleme

echo === MySQL Diagnose und Reparatur ===
echo.

REM Prüfe ob MySQL läuft
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo MySQL laeuft bereits. Stoppe MySQL fuer Diagnose...
    taskkill /F /IM mysqld.exe >nul 2>&1
    timeout /t 3 /nobreak >nul
)

set MYSQL_DATA_DIR=C:\xampp\mysql\data
set MYSQL_BIN_DIR=C:\xampp\mysql\bin
set ERROR_LOG=%MYSQL_DATA_DIR%\mysql_error.log

REM Prüfe Error Log
if exist "%ERROR_LOG%" (
    echo Analysiere MySQL Error Log...
    echo.
    echo Letzte Fehler:
    powershell -Command "Get-Content '%ERROR_LOG%' -Tail 10"
    echo.
    
    REM Prüfe auf Aria-Fehler
    findstr /C:"Aria recovery failed" /C:"aria_chk" /C:"aria_log_control" "%ERROR_LOG%" >nul 2>&1
    if "%ERRORLEVEL%"=="0" (
        echo Aria-Fehler erkannt!
        set HAS_ARIA_ERROR=1
    )
) else (
    echo MySQL Error Log nicht gefunden: %ERROR_LOG%
)

REM Prüfe Port 3306
echo.
echo Pruefe Port 3306...
powershell -Command "$listener = Get-NetTCPConnection -LocalPort 3306 -ErrorAction SilentlyContinue; if ($listener) { $proc = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue; Write-Host '  Port 3306 wird verwendet von:' $proc.Name '(PID:' $proc.Id ')' -ForegroundColor Red; exit 1 } else { Write-Host '  Port 3306 ist frei' -ForegroundColor Green; exit 0 }"
if "%ERRORLEVEL%"=="1" (
    echo WARNUNG: Port 3306 ist blockiert!
    set HAS_PORT_ERROR=1
)

REM Prüfe Aria-Log-Dateien
echo.
echo Pruefe Aria-Log-Dateien...
if exist "%MYSQL_DATA_DIR%\aria_log.*" (
    echo Aria-Log-Dateien gefunden
    set HAS_ARIA_FILES=1
)

if exist "%MYSQL_DATA_DIR%\aria_log_control" (
    echo Pruefe aria_log_control...
    for %%A in ("%MYSQL_DATA_DIR%\aria_log_control") do (
        if %%~zA==0 (
            echo aria_log_control ist leer/korrupt!
            set HAS_ARIA_ERROR=1
        )
    )
)

REM Zusammenfassung
echo.
echo === Zusammenfassung ===
echo.

if defined HAS_ARIA_ERROR (
    echo Gefundene Probleme:
    echo   - Aria-Log-Fehler
    echo.
    echo Empfohlene Reparatur:
    echo   - Loesche aria_log Dateien
    echo   - Loesche aria_log_control
    echo.
    set /p REPAIR="Soll ich die Reparatur jetzt durchfuehren? (J/N): "
    if /i "%REPAIR%"=="J" (
        echo.
        echo Starte Reparatur...
        if exist "%MYSQL_DATA_DIR%\aria_log.*" (
            echo   Loesche aria_log Dateien...
            del /F /Q "%MYSQL_DATA_DIR%\aria_log.*" >nul 2>&1
            echo   OK: Geloescht
        )
        if exist "%MYSQL_DATA_DIR%\aria_log_control" (
            for %%A in ("%MYSQL_DATA_DIR%\aria_log_control") do (
                if %%~zA==0 (
                    echo   Loesche korrupte aria_log_control...
                    del /F /Q "%MYSQL_DATA_DIR%\aria_log_control" >nul 2>&1
                    echo   OK: Geloescht
                )
            )
        )
        echo.
        echo OK: Reparatur abgeschlossen
    )
)

if defined HAS_PORT_ERROR (
    echo WARNUNG: Port 3306 ist blockiert!
    echo   Beende andere MySQL-Instanzen oder aendere MySQL-Port
    echo.
)

REM Versuche MySQL zu starten
echo === MySQL Start-Versuch ===
echo.

set MYSQL_START_SCRIPT=C:\xampp\mysql_start.bat
if exist "%MYSQL_START_SCRIPT%" (
    echo Starte MySQL...
    call "%MYSQL_START_SCRIPT%" >nul 2>&1
    timeout /t 5 /nobreak >nul
    
    REM Prüfe ob MySQL läuft
    tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
    if "%ERRORLEVEL%"=="0" (
        echo OK: MySQL-Prozess laeuft
        echo Warte auf MySQL-Port...
        
        set WAITED=0
        :wait_loop
        powershell -Command "$tcp = New-Object System.Net.Sockets.TcpClient; try { $tcp.Connect('localhost', 3306); $tcp.Close(); exit 0 } catch { exit 1 }" >nul 2>&1
        if "%ERRORLEVEL%"=="0" (
            echo OK: MySQL ist bereit und antwortet auf Port 3306
            exit /b 0
        )
        
        set /a WAITED+=1
        if %WAITED% geq 15 (
            echo WARNUNG: MySQL-Prozess laeuft, aber Port 3306 antwortet noch nicht
            echo   Pruefe MySQL-Logs: %ERROR_LOG%
            exit /b 1
        )
        
        timeout /t 1 /nobreak >nul
        goto wait_loop
    ) else (
        echo FEHLER: MySQL konnte nicht gestartet werden
        echo   Pruefe MySQL-Logs: %ERROR_LOG%
        exit /b 1
    )
) else (
    echo FEHLER: mysql_start.bat nicht gefunden: %MYSQL_START_SCRIPT%
    echo   Bitte starte MySQL manuell ueber XAMPP Control Panel
    exit /b 1
)

echo.
echo === Fertig ===
echo.
echo Naechste Schritte:
echo   1. Pruefe MySQL-Logs: %ERROR_LOG%
echo   2. Pruefe XAMPP Control Panel
echo   3. Bei Port-Konflikten: Beende andere MySQL-Instanzen
echo   4. Bei Berechtigungsfehlern: Fuehre als Administrator aus
