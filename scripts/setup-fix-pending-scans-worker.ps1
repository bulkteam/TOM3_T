# TOM3 - Fix Pending Scans Worker Setup
# Erstellt Windows Task Scheduler Job für automatische Behebung von pending Blobs

$ErrorActionPreference = "Stop"

$taskName = "TOM3-FixPendingScans"
$scriptPath = Join-Path $PSScriptRoot "..\scripts\jobs\fix-pending-scans.php"
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
$vbsWrapper = Join-Path $PSScriptRoot "fix-pending-scans-worker.vbs"
if (Test-Path $vbsWrapper) {
    $action = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$vbsWrapper`""
    Write-Host "Verwende VBScript-Wrapper für unsichtbare Ausführung" -ForegroundColor Green
} else {
    # Fallback: PHP direkt (kann kurz aufblinken)
    $action = New-ScheduledTaskAction -Execute $phpPath -Argument "`"$scriptPath`""
    Write-Host "WARNUNG: VBScript-Wrapper nicht gefunden, verwende PHP direkt (kann kurz aufblinken)" -ForegroundColor Yellow
}

# Task-Trigger: Alle 15 Minuten
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration (New-TimeSpan -Days 365)

# Task-Settings
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1)

# Task-Principal (als aktueller Benutzer, aber läuft auch ohne eingeloggten Benutzer)
# Versuche zuerst ohne RunLevel Highest (funktioniert ohne Admin-Rechte)
try {
    $principal = New-ScheduledTaskPrincipal `
        -UserId "$env:USERDOMAIN\$env:USERNAME" `
        -LogonType S4U
} catch {
    # Fallback: Mit RunLevel Highest (benötigt Admin-Rechte)
    Write-Host "Versuche mit RunLevel Highest..." -ForegroundColor Yellow
    $principal = New-ScheduledTaskPrincipal `
        -UserId "$env:USERDOMAIN\$env:USERNAME" `
        -LogonType ServiceAccount `
        -RunLevel Highest
}

# Task registrieren
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Description "TOM3 Fix Pending Scans Worker - Behebt automatisch Blobs mit pending Status" | Out-Null
    
    Write-Host "Task '$taskName' erfolgreich erstellt!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Konfiguration:" -ForegroundColor Cyan
    Write-Host "  - Name: $taskName"
    if (Test-Path $vbsWrapper) {
        Write-Host "  - Script: $scriptPath (über VBScript-Wrapper - unsichtbar)" -ForegroundColor Green
    } else {
        Write-Host "  - Script: $scriptPath (direkt - kann kurz aufblinken)" -ForegroundColor Yellow
    }
    Write-Host "  - Intervall: Alle 15 Minuten"
    Write-Host "  - User: $env:USERDOMAIN\$env:USERNAME (sichtbar und überwachbar)"
    Write-Host ""
    Write-Host "Status prüfen:" -ForegroundColor Cyan
    Write-Host "  Get-ScheduledTask -TaskName '$taskName' | Get-ScheduledTaskInfo"
    Write-Host ""
    Write-Host "Manuell ausführen:" -ForegroundColor Cyan
    Write-Host "  Start-ScheduledTask -TaskName '$taskName'"
    
} catch {
    $errorMsg = $_.Exception.Message
    Write-Host ""
    Write-Host "FEHLER beim Erstellen des Tasks: $errorMsg" -ForegroundColor Red
    Write-Host ""
    Write-Host "Hinweis: Das Erstellen von Scheduled Tasks erfordert Administrator-Rechte." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Bitte führen Sie das Script als Administrator aus:" -ForegroundColor Cyan
    Write-Host "  1. Rechtsklick auf PowerShell" -ForegroundColor Cyan
    Write-Host "  2. 'Als Administrator ausführen' wählen" -ForegroundColor Cyan
    Write-Host "  3. Dann ausführen: .\scripts\setup-fix-pending-scans-worker.ps1" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Oder verwenden Sie diesen Befehl:" -ForegroundColor Cyan
    Write-Host "  Start-Process powershell -Verb RunAs -ArgumentList '-ExecutionPolicy Bypass -File \"$scriptPath\"'" -ForegroundColor Cyan
    Write-Host ""
    exit 1
}


