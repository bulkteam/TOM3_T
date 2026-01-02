# TOM3 - Prüft alle erwarteten Windows Task Scheduler Jobs
# 
# Usage:
#   powershell -ExecutionPolicy Bypass -File scripts\check-all-scheduled-tasks.ps1

$ErrorActionPreference = "Continue"

Write-Host "=== TOM3 Task Scheduler Jobs - Status Check ===" -ForegroundColor Cyan
Write-Host ""

# Erwartete Tasks
$expectedTasks = @(
    @{Name="TOM3-Neo4j-Sync-Worker"; Required=$true; Description="Synchronisiert Events aus MySQL nach Neo4j"},
    @{Name="TOM3-ClamAV-Scan-Worker"; Required=$true; Description="Verarbeitet Scan-Jobs für Dokumente (ClamAV)"},
    @{Name="TOM3-ExtractTextWorker"; Required=$true; Description="Extrahiert Text aus Dokumenten"},
    @{Name="TOM3-DuplicateCheck"; Required=$false; Description="Prüft auf potenzielle Duplikate"},
    @{Name="TOM3-ActivityLog-Maintenance"; Required=$false; Description="Wartung für Activity-Log"},
    @{Name="MySQL-Auto-Recovery"; Required=$false; Description="Prüft und startet MySQL automatisch"},
    @{Name="MySQL-Daily-Backup"; Required=$false; Description="Erstellt tägliches Datenbank-Backup"}
)

$foundTasks = @()
$missingTasks = @()
$errorTasks = @()

foreach ($task in $expectedTasks) {
    $taskName = $task.Name
    
    # Versuche Task zu finden (verschiedene Methoden)
    $taskFound = $false
    $taskInfo = $null
    $taskState = $null
    $lastResult = $null
    
    # Methode 1: Get-ScheduledTask (funktioniert nur für Tasks des aktuellen Users)
    try {
        $taskObj = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
        if ($taskObj) {
            $taskFound = $true
            $taskState = $taskObj.State
            $taskInfo = Get-ScheduledTaskInfo -TaskName $taskName -ErrorAction SilentlyContinue
            if ($taskInfo) {
                $lastResult = $taskInfo.LastTaskResult
            }
        }
    } catch {
        # Ignorieren
    }
    
    # Methode 2: schtasks (findet auch SYSTEM-Tasks)
    if (-not $taskFound) {
        try {
            $result = schtasks /query /tn $taskName /fo LIST /v 2>&1
            if ($LASTEXITCODE -eq 0 -and $result -notmatch "FEHLER|ERROR") {
                $taskFound = $true
                
                # Parse Status
                $statusLine = $result | Select-String "Status:"
                if ($statusLine) {
                    $taskState = ($statusLine -split "Status:")[1].Trim()
                }
                
                # Parse Last Result
                $resultLine = $result | Select-String "Letztes Ergebnis:"
                if ($resultLine) {
                    $lastResultStr = ($resultLine -split "Letztes Ergebnis:")[1].Trim()
                    if ($lastResultStr -match "0x(\d+)") {
                        $lastResult = [int]("0x" + $matches[1])
                    } elseif ($lastResultStr -match "(\d+)") {
                        $lastResult = [int]$matches[1]
                    }
                }
            }
        } catch {
            # Ignorieren
        }
    }
    
    if ($taskFound) {
        $foundTasks += @{
            Name = $taskName
            State = $taskState
            LastResult = $lastResult
            Required = $task.Required
            Description = $task.Description
        }
        
        # Prüfe auf Fehler (0x1 = Fehler, 0x0 = Erfolg)
        if ($lastResult -ne $null -and $lastResult -ne 0) {
            $errorTasks += @{
                Name = $taskName
                LastResult = $lastResult
                Description = $task.Description
            }
        }
    } else {
        $missingTasks += @{
            Name = $taskName
            Required = $task.Required
            Description = $task.Description
        }
    }
}

# Ausgabe
Write-Host "Gefundene Tasks: $($foundTasks.Count) von $($expectedTasks.Count)" -ForegroundColor $(if ($foundTasks.Count -eq $expectedTasks.Count) { "Green" } else { "Yellow" })
Write-Host ""

if ($foundTasks.Count -gt 0) {
    Write-Host "[OK] Gefundene Tasks:" -ForegroundColor Green
    foreach ($task in $foundTasks) {
        $statusColor = if ($task.LastResult -eq 0 -or $task.LastResult -eq $null) { "Green" } else { "Red" }
        $resultText = if ($task.LastResult -eq $null) { "Unbekannt" } elseif ($task.LastResult -eq 0) { "Erfolgreich (0x0)" } else { "Fehler (0x$($task.LastResult.ToString('X')))" }
        
        Write-Host "  [+] $($task.Name)" -ForegroundColor Green
        Write-Host "    Status: $($task.State)" -ForegroundColor Cyan
        Write-Host "    Letztes Ergebnis: $resultText" -ForegroundColor $statusColor
        Write-Host "    Beschreibung: $($task.Description)" -ForegroundColor Gray
        Write-Host ""
    }
}

if ($errorTasks.Count -gt 0) {
    Write-Host "[WARNUNG] Tasks mit Fehlern:" -ForegroundColor Yellow
    foreach ($task in $errorTasks) {
        Write-Host "  [!] $($task.Name) - Fehlercode: 0x$($task.LastResult.ToString('X'))" -ForegroundColor Red
        Write-Host "    Beschreibung: $($task.Description)" -ForegroundColor Gray
        
        # Fehlercode-Erklärung
        switch ($task.LastResult) {
            1 { Write-Host "    Bedeutung: Unbekannter Fehler oder ungultige Parameter" -ForegroundColor Yellow }
            2 { Write-Host "    Bedeutung: Zugriff verweigert" -ForegroundColor Yellow }
            267011 { Write-Host "    Bedeutung: Task konnte nicht gestartet werden (z.B. PHP nicht gefunden)" -ForegroundColor Yellow }
            default { Write-Host "    Bedeutung: Unbekannter Fehlercode" -ForegroundColor Yellow }
        }
        Write-Host ""
    }
}

if ($missingTasks.Count -gt 0) {
    Write-Host "[FEHLER] Fehlende Tasks:" -ForegroundColor Red
    foreach ($task in $missingTasks) {
        $requiredText = if ($task.Required) { " (PFLICHT)" } else { " (Optional/Empfohlen)" }
        $color = if ($task.Required) { "Red" } else { "Yellow" }
        Write-Host "  [-] $($task.Name)$requiredText" -ForegroundColor $color
        Write-Host "    Beschreibung: $($task.Description)" -ForegroundColor Gray
        Write-Host ""
    }
}

# Zusammenfassung
Write-Host "=== Zusammenfassung ===" -ForegroundColor Cyan
$foundColor = if ($foundTasks.Count -eq $expectedTasks.Count) { "Green" } else { "Yellow" }
$errorColor = if ($errorTasks.Count -eq 0) { "Green" } else { "Red" }
$missingColor = if ($missingTasks.Count -eq 0) { "Green" } else { "Yellow" }
Write-Host "Gefunden: $($foundTasks.Count)/$($expectedTasks.Count)" -ForegroundColor $foundColor
Write-Host "Fehler: $($errorTasks.Count)" -ForegroundColor $errorColor
Write-Host "Fehlend: $($missingTasks.Count)" -ForegroundColor $missingColor

# Exit-Code
if ($missingTasks.Count -gt 0 -or $errorTasks.Count -gt 0) {
    exit 1
} else {
    exit 0
}
