@echo off
REM TOM3 - Duplikaten-Prüfung (Batch-Wrapper)
cd /d C:\xampp\htdocs\TOM3
php scripts\check-duplicates.php >> logs\duplicate-check.log 2>&1
