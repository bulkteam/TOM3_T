# PowerShell-Skript zum vollständigen Beenden aller Docker-Prozesse
# Führe dieses Skript als Administrator aus

Write-Host "Beende alle Docker-Prozesse..." -ForegroundColor Yellow

# 1. Alle Docker-Prozesse beenden
$dockerProcesses = Get-Process | Where-Object { 
    $_.ProcessName -like "*docker*" -or 
    $_.ProcessName -like "*com.docker*" -or
    $_.ProcessName -eq "dockerd" -or
    $_.ProcessName -eq "docker-proxy"
}

if ($dockerProcesses) {
    Write-Host "Gefundene Docker-Prozesse:" -ForegroundColor Cyan
    $dockerProcesses | ForEach-Object {
        Write-Host "  - $($_.ProcessName) (PID: $($_.Id))" -ForegroundColor Gray
    }
    
    $dockerProcesses | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    Write-Host "Docker-Prozesse beendet." -ForegroundColor Green
} else {
    Write-Host "Keine Docker-Prozesse gefunden." -ForegroundColor Green
}

# 2. Docker Desktop Service beenden
Write-Host "`nBeende Docker Desktop Service..." -ForegroundColor Yellow
$dockerService = Get-Service -Name "com.docker.service" -ErrorAction SilentlyContinue
if ($dockerService -and $dockerService.Status -eq 'Running') {
    Stop-Service -Name "com.docker.service" -Force -ErrorAction SilentlyContinue
    Write-Host "Docker Desktop Service beendet." -ForegroundColor Green
} else {
    Write-Host "Docker Desktop Service ist bereits gestoppt." -ForegroundColor Gray
}

# 3. WSL docker-desktop Distribution beenden
Write-Host "`nBeende WSL docker-desktop Distribution..." -ForegroundColor Yellow
$wslStatus = wsl --list --verbose 2>$null | Select-String "docker-desktop"
if ($wslStatus -match "Running") {
    wsl --terminate docker-desktop 2>$null
    Write-Host "WSL docker-desktop Distribution beendet." -ForegroundColor Green
} else {
    Write-Host "WSL docker-desktop Distribution ist bereits gestoppt." -ForegroundColor Gray
}

# 4. Finale Prüfung
Write-Host "`n=== Finale Prüfung ===" -ForegroundColor Cyan
$remainingProcesses = Get-Process | Where-Object { 
    $_.ProcessName -like "*docker*" -or 
    $_.ProcessName -like "*com.docker*"
}

if ($remainingProcesses) {
    Write-Host "WARNUNG: Folgende Prozesse laufen noch:" -ForegroundColor Red
    $remainingProcesses | ForEach-Object {
        Write-Host "  - $($_.ProcessName) (PID: $($_.Id))" -ForegroundColor Red
    }
    Write-Host "`nVersuche erneut zu beenden..." -ForegroundColor Yellow
    $remainingProcesses | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
} else {
    Write-Host "Alle Docker-Prozesse wurden erfolgreich beendet!" -ForegroundColor Green
}

Write-Host "`nFertig. Du kannst jetzt Docker Desktop neu starten." -ForegroundColor Cyan


