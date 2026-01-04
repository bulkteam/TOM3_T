@echo off
REM MySQL Backup Script für TOM3
REM Erstellt ein Backup der Datenbank 'tom'

set MYSQL_BIN=C:\xampp\mysql\bin
set MYSQL_USER=tomcat
set MYSQL_PASSWORD=tim@2025!
set DB_NAME=tom
set BACKUP_DIR=C:\xampp\mysql\backup
set DATE_STR=%date:~-4,4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set DATE_STR=%DATE_STR: =0%
set BACKUP_FILE=%BACKUP_DIR%\tom_backup_%DATE_STR%.sql

echo === MySQL Backup ==="
echo.

REM Erstelle Backup-Verzeichnis falls nicht vorhanden
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

REM Prüfe ob MySQL läuft
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="1" (
    echo FEHLER: MySQL laeuft nicht!
    echo Starte MySQL ueber XAMPP Control Panel.
    pause
    exit /b 1
)

echo Erstelle Backup: %BACKUP_FILE%
echo.

"%MYSQL_BIN%\mysqldump.exe" -u %MYSQL_USER% -p%MYSQL_PASSWORD% %DB_NAME% > "%BACKUP_FILE%" 2>nul

if %ERRORLEVEL%==0 (
    echo.
    echo Backup erfolgreich erstellt!
    echo Datei: %BACKUP_FILE%
    echo.
    
    REM Zeige Dateigröße
    for %%A in ("%BACKUP_FILE%") do echo Groesse: %%~zA Bytes
    echo.
    
    REM Lösche alte Backups (älter als 7 Tage)
    echo Loesche alte Backups (aelter als 7 Tage)...
    forfiles /p "%BACKUP_DIR%" /m tom_backup_*.sql /d -7 /c "cmd /c del @path" 2>nul
    echo.
    echo Fertig!
) else (
    echo.
    echo FEHLER: Backup fehlgeschlagen!
    echo Pruefe Benutzername/Passwort in diesem Skript.
    pause
    exit /b 1
)

pause




