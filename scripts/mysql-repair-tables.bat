@echo off
REM MySQL Tabellen-Reparatur mit mysqlcheck
REM Repariert alle Tabellen in allen Datenbanken

echo === MySQL Tabellen-Reparatur ===
echo.

REM Prüfe ob MySQL läuft (Prozess oder Port)
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="1" (
    echo MySQL laeuft nicht.
    echo.
    echo Bitte starte MySQL zuerst ueber XAMPP Control Panel.
    echo Dann fuehre dieses Script erneut aus.
    echo.
    pause
    exit /b 1
)

echo MySQL laeuft. Starte Tabellen-Reparatur...
echo.

REM Navigiere zu MySQL bin Verzeichnis
cd /d C:\xampp\mysql\bin

REM Repariere mysql System-Datenbank
echo [1/2] Repariere mysql System-Datenbank...
mysqlcheck -u root --auto-repair mysql
if "%ERRORLEVEL%"=="0" (
    echo   OK: mysql Datenbank repariert
) else (
    echo   WARNUNG: Fehler bei mysql Datenbank
)
echo.

REM Repariere alle Datenbanken
echo [2/2] Repariere alle Datenbanken...
mysqlcheck -u root --auto-repair --all-databases
if "%ERRORLEVEL%"=="0" (
    echo   OK: Alle Datenbanken repariert
) else (
    echo   WARNUNG: Fehler bei einigen Datenbanken
)
echo.

echo === Reparatur abgeschlossen ===
echo.

pause
