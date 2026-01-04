# MySQL Aria-Logs Fix
# Löscht aria_log_control und alle aria_log.* Dateien

$ErrorActionPreference = "Continue"
$mysqlDataDir = "C:\xampp\mysql\data"

Write-Host "=== MySQL Aria-Logs Fix ===" -ForegroundColor Cyan
Write-Host ""

# Stoppe MySQL falls es läuft
$mysqlRunning = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if ($mysqlRunning) {
    Write-Host "Stoppe MySQL..." -ForegroundColor Yellow
    Stop-Process -Name mysqld -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    Write-Host "  ✓ MySQL gestoppt" -ForegroundColor Green
    Write-Host ""
}

# Lösche aria_log_control
Write-Host "Lösche aria_log_control..." -ForegroundColor Yellow
$ariaLogControl = Join-Path $mysqlDataDir "aria_log_control"
if (Test-Path $ariaLogControl) {
    Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
    Write-Host "  ✓ aria_log_control gelöscht" -ForegroundColor Green
} else {
    Write-Host "  ✓ aria_log_control nicht vorhanden" -ForegroundColor Green
}

# Lösche alle aria_log.* Dateien
Write-Host ""
Write-Host "Lösche aria_log.* Dateien..." -ForegroundColor Yellow
$ariaLogFiles = Get-ChildItem -Path $mysqlDataDir -Filter "aria_log.*" -ErrorAction SilentlyContinue
if ($ariaLogFiles) {
    $count = ($ariaLogFiles | Measure-Object).Count
    $ariaLogFiles | Remove-Item -Force -ErrorAction SilentlyContinue
    Write-Host "  ✓ $count aria_log.* Dateien gelöscht" -ForegroundColor Green
} else {
    Write-Host "  ✓ Keine aria_log.* Dateien gefunden" -ForegroundColor Green
}

Write-Host ""
Write-Host "=== Fertig ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "MySQL kann jetzt gestartet werden!" -ForegroundColor Green
Write-Host ""


