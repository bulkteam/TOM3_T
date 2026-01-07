# TOM3 - Fix Pending Scans Worker Setup (Alternative mit schtasks.exe)
# Diese Version verwendet schtasks.exe, was manchmal ohne Admin-Rechte funktioniert

$ErrorActionPreference = "Stop"

$taskName = "TOM3-FixPendingScans"
# Korrigierter Pfad: PSScriptRoot ist bereits scripts/, daher direkt jobs/
$scriptPath = Join-Path $PSScriptRoot "jobs\fix-pending-scans.php"
# Normalisiere den Pfad (entfernt ..\ und macht ihn absolut)
$scriptPath = (Resolve-Path $scriptPath -ErrorAction SilentlyContinue).Path
if (-not $scriptPath) {
    # Fallback: Konstruiere Pfad manuell
    $scriptPath = Join-Path (Split-Path $PSScriptRoot -Parent) "scripts\jobs\fix-pending-scans.php"
    $scriptPath = [System.IO.Path]::GetFullPath($scriptPath)
}
$phpPath = "php"  # Oder: "C:\xampp\php\php.exe"

# Prüfe, ob Script existiert
if (-not (Test-Path $scriptPath)) {
    Write-Error "Script nicht gefunden: $scriptPath"
    exit 1
}

# Prüfe, ob PHP verfügbar ist
try {
    $phpVersion = & $phpPath -v 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "PHP nicht gefunden"
    }
    Write-Host "PHP gefunden: $($phpVersion[0])" -ForegroundColor Green
} catch {
    Write-Error "PHP nicht gefunden. Bitte PHP installieren oder Pfad in \$phpPath anpassen."
    exit 1
}

# Prüfe, ob Task bereits existiert
$ErrorActionPreference = "SilentlyContinue"
$existingTask = schtasks /Query /TN $taskName /FO LIST 2>&1
$taskExists = $LASTEXITCODE -eq 0
$ErrorActionPreference = "Stop"

if ($taskExists) {
    Write-Host "Task '$taskName' existiert bereits." -ForegroundColor Yellow
    $overwrite = Read-Host "Überschreiben? (j/n)"
    if ($overwrite -ne "j" -and $overwrite -ne "J") {
        Write-Host "Abgebrochen." -ForegroundColor Yellow
        exit 0
    }
    
    # Task entfernen
    $ErrorActionPreference = "SilentlyContinue"
    schtasks /Delete /TN $taskName /F 2>&1 | Out-Null
    $ErrorActionPreference = "Stop"
    Write-Host "Alter Task entfernt." -ForegroundColor Yellow
}

# Erstelle Task mit schtasks.exe (ohne XML, direkt mit Parametern)
try {
    # Verwende schtasks.exe mit direkten Parametern
    $startTime = Get-Date -Format "HH:mm"
    $result = schtasks /Create `
        /TN $taskName `
        /TR "$phpPath `"$scriptPath`"" `
        /SC MINUTE `
        /MO 15 `
        /ST $startTime `
        /RU "$env:USERDOMAIN\$env:USERNAME" `
        /RL LIMITED `
        /F `
        2>&1
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Task '$taskName' erfolgreich erstellt!" -ForegroundColor Green
        Write-Host ""
        Write-Host "Konfiguration:" -ForegroundColor Cyan
        Write-Host "  - Name: $taskName"
        Write-Host "  - Script: $scriptPath"
        Write-Host "  - Intervall: Alle 15 Minuten"
        Write-Host "  - User: $env:USERDOMAIN\$env:USERNAME"
        Write-Host ""
        Write-Host "Status prüfen:" -ForegroundColor Cyan
        Write-Host "  schtasks /Query /TN $taskName"
        Write-Host ""
        Write-Host "Manuell ausführen:" -ForegroundColor Cyan
        Write-Host "  schtasks /Run /TN $taskName"
    } else {
        Write-Error "Fehler beim Erstellen des Tasks: $result"
        Write-Host ""
        Write-Host "Hinweis: Falls 'Zugriff verweigert' erscheint, führen Sie PowerShell als Administrator aus." -ForegroundColor Yellow
        exit 1
    }
} catch {
    Write-Error "Fehler beim Erstellen des Tasks: $_"
    Write-Host ""
    Write-Host "Hinweis: Falls 'Zugriff verweigert' erscheint, führen Sie PowerShell als Administrator aus." -ForegroundColor Yellow
    exit 1
}

