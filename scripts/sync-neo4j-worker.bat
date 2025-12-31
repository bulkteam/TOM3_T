@echo off
REM TOM3 - Neo4j Sync Worker (Batch Wrapper)
REM Wird vom Task Scheduler aufgerufen

cd /d "%~dp0\.."
php scripts\sync-neo4j-worker.php

REM Exit-Code weitergeben
exit /b %ERRORLEVEL%
