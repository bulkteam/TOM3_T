# TOM3 - Erstellt alle Tasks als aktueller Benutzer (sichtbar und überwachbar)
# 
# Usage:
#   PowerShell als Administrator öffnen, dann:
#   cd C:\xampp\htdocs\TOM3
#   powershell -ExecutionPolicy Bypass -File scripts\recreate-all-tasks-as-user.ps1

$ErrorActionPreference = "Stop"

Write-Host "=== TOM3 - Erstelle alle Tasks als aktueller Benutzer ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Aktueller Benutzer: $env:USERDOMAIN\$env:USERNAME" -ForegroundColor Green
Write-Host ""

# Prüfe Administrator-Rechte
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "FEHLER: Dieses Script muss als Administrator ausgeführt werden!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Bitte:" -ForegroundColor Yellow
    Write-Host "1. PowerShell als Administrator öffnen (Rechtsklick -> Als Administrator ausführen)" -ForegroundColor Yellow
    Write-Host "2. Dieses Script erneut ausführen" -ForegroundColor Yellow
    exit 1
}

$projectRoot = Split-Path -Parent $PSScriptRoot

# Liste der Tasks, die neu erstellt werden sollen
$tasksToRecreate = @(
    @{
        Name = "TOM3-ExtractTextWorker"
        Script = "scripts\setup-extract-text-worker-task.ps1"
        Description = "Extract Text Worker"
    },
    @{
        Name = "TOM3-ClamAV-Scan-Worker"
        Script = "scripts\setup-clamav-scan-worker.ps1"
        Description = "ClamAV Scan Worker"
    }
)

Write-Host "Schritt 1: Lösche alte Tasks (falls vorhanden)..." -ForegroundColor Yellow
foreach ($task in $tasksToRecreate) {
    $existingTask = Get-ScheduledTask -TaskName $task.Name -ErrorAction SilentlyContinue
    if ($existingTask) {
        try {
            Unregister-ScheduledTask -TaskName $task.Name -Confirm:$false
            Write-Host "  [OK] $($task.Name) gelöscht" -ForegroundColor Green
        } catch {
            Write-Host "  [WARN] $($task.Name) konnte nicht gelöscht werden: $_" -ForegroundColor Yellow
        }
    } else {
        Write-Host "  - $($task.Name) existiert nicht (überspringe)" -ForegroundColor Gray
    }
}

Write-Host ""
Write-Host "Schritt 2: Erstelle Tasks neu als aktueller Benutzer..." -ForegroundColor Yellow
foreach ($task in $tasksToRecreate) {
    $scriptPath = Join-Path $projectRoot $task.Script
    if (-not (Test-Path $scriptPath)) {
        Write-Host "  [WARN] Script nicht gefunden: $scriptPath" -ForegroundColor Yellow
        continue
    }
    
    Write-Host ""
    Write-Host "  Erstelle: $($task.Name)..." -ForegroundColor Cyan
    try {
        & $scriptPath
        Write-Host "  [OK] $($task.Name) erfolgreich erstellt" -ForegroundColor Green
    } catch {
        Write-Host "  [FEHLER] Fehler beim Erstellen von $($task.Name): $_" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Schritt 3: Prüfe alle TOM3-Tasks..." -ForegroundColor Yellow
Write-Host ""
$allTom3Tasks = Get-ScheduledTask | Where-Object { $_.TaskName -like "TOM3-*" }
if ($allTom3Tasks) {
    Write-Host "Gefundene TOM3-Tasks:" -ForegroundColor Green
    foreach ($task in $allTom3Tasks) {
        $info = Get-ScheduledTaskInfo -TaskName $task.TaskName
        $user = $task.Principal.UserId
        Write-Host "  [OK] $($task.TaskName)" -ForegroundColor Green
        Write-Host "    Benutzer: $user" -ForegroundColor Gray
        Write-Host "    Status: $($task.State)" -ForegroundColor Gray
        Write-Host "    Letztes Ergebnis: $($info.LastTaskResult)" -ForegroundColor Gray
    }
} else {
    Write-Host "[WARN] Keine TOM3-Tasks gefunden!" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Fertig ===" -ForegroundColor Green
Write-Host ""
Write-Host "Die Tasks sollten jetzt:" -ForegroundColor Cyan
Write-Host "  [OK] Als aktueller Benutzer laufen ($env:USERDOMAIN\$env:USERNAME)" -ForegroundColor Green
Write-Host "  [OK] In Get-ScheduledTask sichtbar sein" -ForegroundColor Green
Write-Host "  [OK] Im Monitoring angezeigt werden" -ForegroundColor Green
Write-Host "  [OK] Auch ohne eingeloggten Benutzer laufen (LogonType ServiceAccount)" -ForegroundColor Green
Write-Host ""
Write-Host "Bitte Monitoring-Seite neu laden und prüfen!" -ForegroundColor Yellow
