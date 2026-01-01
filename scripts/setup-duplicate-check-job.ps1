# TOM3 - Duplikaten-Prüfung Job Setup
# Erstellt einen täglichen Windows Task Scheduler Job für Duplikaten-Prüfung

$ErrorActionPreference = "Stop"

$taskName = "TOM3-DuplicateCheck"
$scriptPath = "C:\xampp\htdocs\TOM3\scripts\check-duplicates.php"
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

Write-Host "=== TOM3 Duplikaten-Prüfung Job Setup ===" -ForegroundColor Cyan
Write-Host ""

# Entferne existierenden Task (falls vorhanden)
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existingTask) {
    Write-Host "Entferne existierenden Task..." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

# Erstelle Task-Action
$action = New-ScheduledTaskAction -Execute $phpPath -Argument "`"$scriptPath`"" -WorkingDirectory "C:\xampp\htdocs\TOM3"

# Erstelle Task-Trigger (täglich um 02:00 Uhr)
$trigger = New-ScheduledTaskTrigger -Daily -At "02:00"

# Erstelle Task-Settings
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false

# Erstelle Task-Principal
$principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive `
    -RunLevel Highest

# Beschreibung
$description = "TOM3 Duplikaten-Housekeeper - Prüft täglich auf potenzielle Duplikate in Organisationen und Personen"

# Registriere Task
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Description $description `
        -Force

    Write-Host "✓ Task erfolgreich erstellt: $taskName" -ForegroundColor Green
    Write-Host ""
    Write-Host "Konfiguration:" -ForegroundColor Cyan
    Write-Host "  - Name: $taskName"
    Write-Host "  - Intervall: Täglich um 02:00 Uhr"
    Write-Host "  - Script: $scriptPath"
    Write-Host ""
    Write-Host "Nächste Ausführung:" -ForegroundColor Cyan
    $task = Get-ScheduledTask -TaskName $taskName
    $taskInfo = Get-ScheduledTaskInfo -TaskName $taskName
    if ($taskInfo.NextRunTime) {
        Write-Host "  " $taskInfo.NextRunTime -ForegroundColor Green
    } else {
        Write-Host "  Wird beim nächsten Systemstart ausgeführt" -ForegroundColor Yellow
    }
    Write-Host ""
    Write-Host "Hinweis: Migration 033 muss ausgeführt sein:" -ForegroundColor Yellow
    Write-Host "  php scripts\run-migration-033.php" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Manuelle Ausführung:" -ForegroundColor Cyan
    Write-Host "  php scripts\check-duplicates.php" -ForegroundColor White
    Write-Host ""
    Write-Host "Ergebnisse anzeigen:" -ForegroundColor Cyan
    Write-Host "  http://localhost/TOM3/public/monitoring.html" -ForegroundColor White

} catch {
    Write-Host "✗ Fehler beim Erstellen des Tasks: $_" -ForegroundColor Red
    exit 1
}
