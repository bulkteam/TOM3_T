@echo off
echo Fixing MySQL Aria Recovery Error...
echo.

REM Stop MySQL if running
echo 1. Stopping MySQL...
taskkill /F /IM mysqld.exe >nul 2>&1
timeout /t 2 /nobreak >nul
echo    MySQL stopped (if it was running).
echo.

REM Navigate to MySQL data directory
cd /d C:\xampp\mysql\data

REM Delete aria_log files
echo 2. Deleting aria_log files...
for %%f in (aria_log.*) do del /F /Q "%%f" >nul 2>&1
echo    Aria log files deleted (if they existed).
echo.

REM Delete aria_log_control
echo 3. Resetting aria_log_control...
del /F /Q aria_log_control >nul 2>&1
echo    aria_log_control deleted (will be recreated on next start).
echo.

echo 4. Fix complete!
echo    Now try starting MySQL from XAMPP Control Panel.
echo.
pause

