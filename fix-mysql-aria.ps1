# Fix MySQL Aria Recovery Error
# Run this script as Administrator

Write-Host "Fixing MySQL Aria Recovery Error..." -ForegroundColor Yellow

# Stop MySQL if running
Write-Host "`n1. Stopping MySQL..." -ForegroundColor Cyan
$mysqlProcess = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if ($mysqlProcess) {
    Stop-Process -Name mysqld -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    Write-Host "   MySQL stopped." -ForegroundColor Green
} else {
    Write-Host "   MySQL is not running." -ForegroundColor Green
}

# Navigate to MySQL data directory
$mysqlDataDir = "C:\xampp\mysql\data"
Set-Location $mysqlDataDir

# Delete aria_log files
Write-Host "`n2. Deleting aria_log files..." -ForegroundColor Cyan
$ariaLogFiles = Get-ChildItem -Path $mysqlDataDir -Filter "aria_log.*" -ErrorAction SilentlyContinue
if ($ariaLogFiles) {
    foreach ($file in $ariaLogFiles) {
        Remove-Item $file.FullName -Force -ErrorAction SilentlyContinue
        Write-Host "   Deleted: $($file.Name)" -ForegroundColor Yellow
    }
    Write-Host "   Aria log files deleted." -ForegroundColor Green
} else {
    Write-Host "   No aria_log files found." -ForegroundColor Green
}

# Run aria_chk to repair Aria tables
Write-Host "`n3. Repairing Aria tables..." -ForegroundColor Cyan
$ariaChkPath = "C:\xampp\mysql\bin\aria_chk.exe"
if (Test-Path $ariaChkPath) {
    # Find all Aria tables (.MAD files)
    $ariaTables = Get-ChildItem -Path $mysqlDataDir -Recurse -Filter "*.MAD" -ErrorAction SilentlyContinue
    if ($ariaTables) {
        foreach ($table in $ariaTables) {
            $tableName = $table.FullName -replace '\.MAD$', ''
            Write-Host "   Repairing: $($table.Name)" -ForegroundColor Yellow
            & $ariaChkPath -r "$tableName" 2>&1 | Out-Null
        }
        Write-Host "   Aria tables repaired." -ForegroundColor Green
    } else {
        Write-Host "   No Aria tables found to repair." -ForegroundColor Green
    }
} else {
    Write-Host "   aria_chk.exe not found at: $ariaChkPath" -ForegroundColor Red
    Write-Host "   Skipping Aria table repair." -ForegroundColor Yellow
}

# Alternative: Delete aria_log_control and let MySQL recreate it
Write-Host "`n4. Resetting aria_log_control..." -ForegroundColor Cyan
$ariaLogControl = Join-Path $mysqlDataDir "aria_log_control"
if (Test-Path $ariaLogControl) {
    Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
    Write-Host "   aria_log_control deleted (will be recreated on next start)." -ForegroundColor Green
} else {
    Write-Host "   aria_log_control not found." -ForegroundColor Green
}

Write-Host "`n5. Fix complete! Try starting MySQL from XAMPP Control Panel." -ForegroundColor Green
Write-Host "   If it still fails, you may need to:" -ForegroundColor Yellow
Write-Host "   - Check Windows Event Viewer for more details" -ForegroundColor Yellow
Write-Host "   - Verify MySQL data directory permissions" -ForegroundColor Yellow
Write-Host "   - Consider backing up and recreating the database" -ForegroundColor Yellow




