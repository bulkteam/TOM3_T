# TOM3 - ClamAV Scan Worker Setup
# Erstellt Windows Task Scheduler Job für automatisches Scannen von Dokumenten

$ErrorActionPreference = "Stop"

$taskName = "TOM3-ClamAV-Scan-Worker"
$scriptPath = Join-Path $PSScriptRoot "..\scripts\jobs\scan-blob-worker.php"
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
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($existingTask) {
    Write-Host "Task '$taskName' existiert bereits." -ForegroundColor Yellow
    $overwrite = Read-Host "Überschreiben? (j/n)"
    if ($overwrite -ne "j" -and $overwrite -ne "J") {
        Write-Host "Abgebrochen." -ForegroundColor Yellow
        exit 0
    }
    
    # Task entfernen
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host "Alter Task entfernt." -ForegroundColor Yellow
}

# VBScript-Wrapper für unsichtbare Ausführung (keine aufblinkende Konsole)
$vbsWrapper = Join-Path $PSScriptRoot "scan-blob-worker.vbs"
if (Test-Path $vbsWrapper) {
    $action = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$vbsWrapper`""
} else {
    # Fallback: PHP direkt (kann kurz aufblinken)
    $action = New-ScheduledTaskAction -Execute $phpPath -Argument "`"$scriptPath`""
}

# Task-Trigger: Alle 5 Minuten
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 365)

# Task-Settings
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1)

# Task-Principal (als aktueller Benutzer, aber läuft auch ohne eingeloggten Benutzer)
$principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType ServiceAccount `
    -RunLevel Highest

# Task registrieren
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Description "TOM3 ClamAV Scan Worker - Verarbeitet Scan-Jobs für Dokumente" | Out-Null
    
    Write-Host "Task '$taskName' erfolgreich erstellt!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Konfiguration:" -ForegroundColor Cyan
    Write-Host "  - Name: $taskName"
    if (Test-Path $vbsWrapper) {
        Write-Host "  - Script: $scriptPath (über VBScript-Wrapper - unsichtbar)" -ForegroundColor Green
    } else {
        Write-Host "  - Script: $scriptPath (direkt - kann kurz aufblinken)" -ForegroundColor Yellow
    }
    Write-Host "  - Intervall: Alle 5 Minuten"
    Write-Host "  - User: $env:USERDOMAIN\$env:USERNAME (sichtbar und überwachbar)"
    Write-Host ""
    Write-Host "Status prüfen:" -ForegroundColor Cyan
    Write-Host "  Get-ScheduledTask -TaskName '$taskName' | Get-ScheduledTaskInfo"
    Write-Host ""
    Write-Host "Manuell ausführen:" -ForegroundColor Cyan
    Write-Host "  Start-ScheduledTask -TaskName '$taskName'"
    Write-Host ""
    Write-Host "Logs:" -ForegroundColor Cyan
    Write-Host "  Get-Content logs\scan-blob-worker.log -Tail 50"
    
} catch {
    Write-Error "Fehler beim Erstellen des Tasks: $_"
    exit 1
}


