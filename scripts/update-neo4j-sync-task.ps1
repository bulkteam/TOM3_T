# TOM3 - Neo4j Sync Task Update
# Aktualisiert den bestehenden Task, um VBScript-Wrapper zu verwenden (unsichtbare Konsole)

$taskName = "TOM3-Neo4j-Sync-Worker"
$vbsWrapper = Join-Path $PSScriptRoot "sync-neo4j-worker.vbs"

Write-Host "=== TOM3 Neo4j Sync Task Update ===" -ForegroundColor Cyan
Write-Host ""

# Prüfe ob Task existiert
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if (-not $existingTask) {
    Write-Host "FEHLER: Task '$taskName' nicht gefunden!" -ForegroundColor Red
    Write-Host "Führe zuerst setup-neo4j-sync-automation.ps1 aus" -ForegroundColor Yellow
    exit 1
}

# Prüfe ob VBScript-Wrapper existiert
if (-not (Test-Path $vbsWrapper)) {
    Write-Host "FEHLER: VBScript-Wrapper nicht gefunden: $vbsWrapper" -ForegroundColor Red
    exit 1
}

Write-Host "Aktualisiere Task '$taskName'..." -ForegroundColor Yellow

# Hole aktuelle Task-Einstellungen
$task = Get-ScheduledTask -TaskName $taskName
$settings = $task.Settings
$principal = $task.Principal
$triggers = $task.Triggers

# Erstelle neue Action mit VBScript-Wrapper
$newAction = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$vbsWrapper`""

# Aktualisiere Task
try {
    Set-ScheduledTask -TaskName $taskName -Action $newAction -Settings $settings -Principal $principal -Trigger $triggers | Out-Null
    
    Write-Host "Task erfolgreich aktualisiert!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Aenderungen:" -ForegroundColor Cyan
    Write-Host "  - Verwendet jetzt VBScript-Wrapper (unsichtbare Konsole)" -ForegroundColor White
    Write-Host "  - Keine aufblinkende Konsole mehr" -ForegroundColor White
    Write-Host "  - Deprecated-Warnungen werden unterdrueckt" -ForegroundColor White
    Write-Host ""
    
} catch {
    Write-Host "FEHLER beim Aktualisieren des Tasks: $_" -ForegroundColor Red
    exit 1
}


