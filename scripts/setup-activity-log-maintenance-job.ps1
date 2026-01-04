# TOM3 - Activity-Log Wartungs-Job Setup
# Erstellt einen monatlichen Windows Task Scheduler Job für Activity-Log-Wartung

$ErrorActionPreference = "Stop"

$taskName = "TOM3-ActivityLog-Maintenance"
$scriptPath = "C:\xampp\htdocs\TOM3\scripts\jobs\activity-log-maintenance.php"
$phpPath = "C:\xampp\php\php.exe"

# Prüfe ob PHP existiert
if (-not (Test-Path $phpPath)) {
    Write-Host "Fehler: PHP nicht gefunden unter: $phpPath" -ForegroundColor Red
    Write-Host "Bitte passen Sie den Pfad in diesem Script an." -ForegroundColor Yellow
    exit 1
}

# Prüfe ob Script existiert
if (-not (Test-Path $scriptPath)) {
    Write-Host "Fehler: Script nicht gefunden unter: $scriptPath" -ForegroundColor Red
    exit 1
}

Write-Host "=== TOM3 Activity-Log Wartungs-Job Setup ===" -ForegroundColor Cyan
Write-Host ""

# Entferne existierenden Task (falls vorhanden)
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existingTask) {
    Write-Host "Entferne existierenden Task..." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

# Erstelle Task-Action
$action = New-ScheduledTaskAction -Execute $phpPath -Argument "`"$scriptPath`"" -WorkingDirectory "C:\xampp\htdocs\TOM3"

# Erstelle Task-Trigger (monatlich am 1. Tag um 02:00 Uhr)
$trigger = New-ScheduledTaskTrigger -Monthly -DaysOfMonth 1 -At "02:00"

# Erstelle Task-Settings
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2)

# Erstelle Task-Principal
$principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType ServiceAccount `
    -RunLevel Highest

# Beschreibung
$description = "TOM3 Activity-Log Wartung - Führt monatlich Archivierung, Partitionierung und Löschung alter Activity-Log-Einträge aus"

# Registriere Task
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Description $description `
        -Force | Out-Null
    
    Write-Host "✓ Task erfolgreich erstellt: $taskName" -ForegroundColor Green
    Write-Host ""
    Write-Host "Konfiguration:" -ForegroundColor Cyan
    Write-Host "  - Name: $taskName" -ForegroundColor White
    Write-Host "  - Ausführung: Monatlich am 1. Tag um 02:00 Uhr" -ForegroundColor White
    Write-Host "  - Script: $scriptPath" -ForegroundColor White
    Write-Host ""
    Write-Host "Nächste Ausführung:" -ForegroundColor Cyan
    $taskInfo = Get-ScheduledTaskInfo -TaskName $taskName
    Write-Host "  - " $taskInfo.NextRunTime -ForegroundColor White
    Write-Host ""
    Write-Host "Hinweis: Der Job führt automatisch folgende Aufgaben aus:" -ForegroundColor Yellow
    Write-Host "  1. Archivierung alter Einträge (älter als 24 Monate)" -ForegroundColor White
    Write-Host "  2. Erstellung neuer Partitionen (für nächste 3 Monate)" -ForegroundColor White
    Write-Host "  3. Löschung sehr alter Archiv-Einträge (älter als 7 Jahre)" -ForegroundColor White
    Write-Host ""
    Write-Host "Log-Datei: C:\xampp\htdocs\TOM3\logs\activity-log-maintenance.log" -ForegroundColor Cyan
    
} catch {
    Write-Host "✗ Fehler beim Erstellen des Tasks: $_" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "=== Setup abgeschlossen ===" -ForegroundColor Green


