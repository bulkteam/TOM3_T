# TOM3 - Neo4j Sync Automation Setup
# Richtet einen Windows Task Scheduler Job ein, der den Neo4j Sync-Worker regelmäßig ausführt

param(
    [string]$ScriptPath = "$PSScriptRoot\sync-neo4j-worker.bat",
    [int]$IntervalMinutes = 5
)

Write-Host "=== TOM3 Neo4j Sync Automation Setup ===" -ForegroundColor Cyan
Write-Host ""

# Prüfe ob Script existiert
if (-not (Test-Path $ScriptPath)) {
    Write-Host "FEHLER: Script nicht gefunden: $ScriptPath" -ForegroundColor Red
    Write-Host "Bitte erstelle zuerst sync-neo4j-worker.bat" -ForegroundColor Yellow
    exit 1
}

$taskName = "TOM3-Neo4j-Sync-Worker"
$taskDescription = "TOM3 Neo4j Sync Worker - Verarbeitet Events aus der Outbox und synchronisiert sie nach Neo4j"

# Prüfe ob Task bereits existiert
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($existingTask) {
    Write-Host "WARNUNG: Task '$taskName' existiert bereits." -ForegroundColor Yellow
    $overwrite = Read-Host "Ueberschreiben? (j/n)"
    if ($overwrite -ne 'j' -and $overwrite -ne 'J') {
        Write-Host "Abgebrochen." -ForegroundColor Yellow
        exit 0
    }
    
    # Entferne existierenden Task
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host "Alten Task entfernt" -ForegroundColor Green
}

# Erstelle Task-Action
# Verwende VBScript-Wrapper für unsichtbare Ausführung (keine aufblinkende Konsole)
$vbsWrapper = Join-Path $PSScriptRoot "sync-neo4j-worker.vbs"
if (Test-Path $vbsWrapper) {
    $action = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$vbsWrapper`""
} else {
    # Fallback: Batch-Script direkt (kann kurz aufblinken)
    $action = New-ScheduledTaskAction -Execute $ScriptPath
}

# Erstelle Task-Trigger (alle X Minuten)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) -RepetitionDuration (New-TimeSpan -Days 365)

# Erstelle Task-Settings
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

# Erstelle Task-Principal (als aktueller User)
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive

# Registriere Task
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Description $taskDescription | Out-Null
    
    Write-Host "Task '$taskName' erfolgreich erstellt!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Konfiguration:" -ForegroundColor Cyan
    Write-Host "  - Name: $taskName" -ForegroundColor White
    Write-Host "  - Script: $ScriptPath" -ForegroundColor White
    Write-Host "  - Intervall: Alle $IntervalMinutes Minuten" -ForegroundColor White
    Write-Host "  - Start: Sofort" -ForegroundColor White
    Write-Host ""
    Write-Host "Verwaltung:" -ForegroundColor Cyan
    Write-Host "  - Anzeigen: Get-ScheduledTask -TaskName '$taskName'" -ForegroundColor Gray
    Write-Host "  - Starten: Start-ScheduledTask -TaskName '$taskName'" -ForegroundColor Gray
    Write-Host "  - Stoppen: Stop-ScheduledTask -TaskName '$taskName'" -ForegroundColor Gray
    Write-Host "  - Entfernen: Unregister-ScheduledTask -TaskName '$taskName' -Confirm:`$false" -ForegroundColor Gray
    Write-Host "  - Oder: taskschd.msc (Task Scheduler GUI)" -ForegroundColor Gray
    Write-Host ""
    
    # Starte Task sofort
    $startNow = Read-Host "Task jetzt starten? (j/n)"
    if ($startNow -eq 'j' -or $startNow -eq 'J') {
        Start-ScheduledTask -TaskName $taskName
        Write-Host "Task gestartet" -ForegroundColor Green
    }
    
} catch {
    Write-Host "FEHLER beim Erstellen des Tasks: $_" -ForegroundColor Red
    exit 1
}


