@echo off
REM TOM3 - Neo4j Sync Worker (Batch Wrapper)
REM Wird vom Task Scheduler aufgerufen
REM Konsole wird stumm geschaltet (--quiet Modus)

cd /d "%~dp0\.."

REM Führe PHP-Script im quiet-Modus aus (keine Konsole, keine Ausgabe)
php scripts\sync-neo4j-worker.php --quiet >nul 2>nul

REM Exit-Code weitergeben
exit /b %ERRORLEVEL%
