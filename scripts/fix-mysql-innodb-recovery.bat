@echo off
REM MySQL InnoDB Recovery Fix
REM Behebt "log sequence number is in the future" Fehler

setlocal enabledelayedexpansion

set MYSQL_DATA_DIR=C:\xampp\mysql\data
set MYSQL_BIN_DIR=C:\xampp\mysql\bin
set MYSQL_CONFIG=C:\xampp\mysql\bin\my.ini

echo === MySQL InnoDB Recovery Fix ===
echo.
echo WICHTIG: Dieses Script stoppt MySQL, repariert InnoDB-Logs und startet MySQL neu.
echo.
pause

REM 1. Stoppe MySQL
echo [1/5] Stoppe MySQL...
taskkill /F /IM mysqld.exe >nul 2>&1
timeout /t 3 /nobreak >nul

REM 2. Backup der my.ini erstellen
echo [2/5] Erstelle Backup der my.ini...
if exist "%MYSQL_CONFIG%" (
    copy "%MYSQL_CONFIG%" "%MYSQL_CONFIG%.backup_%date:~-4,4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%" >nul 2>&1
    echo   Backup erstellt
)

REM 3. Prüfe ob innodb_force_recovery bereits gesetzt ist
echo [3/5] Prüfe MySQL-Konfiguration...
findstr /C:"innodb_force_recovery" "%MYSQL_CONFIG%" >nul 2>&1
if "%ERRORLEVEL%"=="0" (
    echo   innodb_force_recovery ist bereits gesetzt
    echo   Entferne alte Einstellung...
    powershell -Command "(Get-Content '%MYSQL_CONFIG%') | Where-Object { $_ -notmatch 'innodb_force_recovery' } | Set-Content '%MYSQL_CONFIG%'"
)

REM 4. Füge innodb_force_recovery=1 hinzu (unter [mysqld])
echo [4/5] Setze InnoDB Recovery-Modus...
powershell -Command "$content = Get-Content '%MYSQL_CONFIG%'; $newContent = @(); $foundMysqld = $false; foreach ($line in $content) { $newContent += $line; if ($line -match '^\s*\[mysqld\]') { $foundMysqld = $true; $newContent += 'innodb_force_recovery=1'; } }; if (-not $foundMysqld) { $newContent += '[mysqld]'; $newContent += 'innodb_force_recovery=1'; }; $newContent | Set-Content '%MYSQL_CONFIG%'"
echo   innodb_force_recovery=1 wurde hinzugefuegt

REM 5. Lösche InnoDB-Log-Dateien
echo [5/5] Loesche InnoDB-Log-Dateien...
if exist "%MYSQL_DATA_DIR%\ib_logfile0" (
    del /F /Q "%MYSQL_DATA_DIR%\ib_logfile0" >nul 2>&1
    echo   ib_logfile0 geloescht
)
if exist "%MYSQL_DATA_DIR%\ib_logfile1" (
    del /F /Q "%MYSQL_DATA_DIR%\ib_logfile1" >nul 2>&1
    echo   ib_logfile1 geloescht
)

echo.
echo === Recovery-Konfiguration abgeschlossen ===
echo.
echo Naechste Schritte:
echo 1. Starte MySQL ueber XAMPP Control Panel
echo 2. Pruefe ob MySQL erfolgreich startet
echo 3. Wenn MySQL laeuft, entferne innodb_force_recovery=1 aus my.ini
echo 4. Starte MySQL erneut
echo.
echo WICHTIG: Nach erfolgreichem Start entferne innodb_force_recovery=1 aus:
echo   %MYSQL_CONFIG%
echo.
pause


