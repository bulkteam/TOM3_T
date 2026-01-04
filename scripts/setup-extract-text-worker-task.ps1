# TOM3 - Extract Text Worker Windows Task Scheduler Setup
# 
# Erstellt einen Windows Task Scheduler Job, der alle 5 Minuten den Extract Text Worker ausführt
#
# Usage:
#   .\scripts\setup-extract-text-worker-task.ps1
#
# Hinweis: Muss als Administrator ausgeführt werden

$ErrorActionPreference = "Stop"

# Projekt-Pfad ermitteln
$projectRoot = Split-Path -Parent $PSScriptRoot
$workerScript = Join-Path $projectRoot "scripts\jobs\extract-text-worker.php"

# PHP-Pfad ermitteln (versuche verschiedene Möglichkeiten)
$phpExe = "php"
$phpPaths = @(
    "C:\xampp\php\php.exe",
    "C:\Program Files\PHP\php.exe",
    "php.exe"
)

foreach ($path in $phpPaths) {
    if (Test-Path $path) {
        $phpExe = $path
        Write-Host "PHP gefunden: $phpExe" -ForegroundColor Green
        break
    }
}

# Wenn PHP nicht gefunden, versuche es über PATH
if ($phpExe -eq "php") {
    try {
        $phpVersion = & php -v 2>&1 | Select-Object -First 1
        if ($phpVersion) {
            Write-Host "PHP gefunden im PATH: $phpVersion" -ForegroundColor Green
        }
    } catch {
        Write-Host "WARNUNG: PHP nicht gefunden. Verwende 'php' (muss im PATH sein)." -ForegroundColor Yellow
    }
}

# Prüfe, ob Worker-Script existiert
if (-not (Test-Path $workerScript)) {
    Write-Host "FEHLER: Worker-Script nicht gefunden: $workerScript" -ForegroundColor Red
    exit 1
}

# Prüfe, ob PHP verfügbar ist
try {
    $phpVersion = & $phpExe -v 2>&1 | Select-Object -First 1
    Write-Host "PHP gefunden: $phpVersion" -ForegroundColor Green
    Write-Host "PHP-Pfad: $phpExe" -ForegroundColor Cyan
} catch {
    Write-Host "FEHLER: PHP nicht gefunden. Bitte PHP installieren oder Pfad in \$phpExe anpassen." -ForegroundColor Red
    exit 1
}

# Task-Name
$taskName = "TOM3-ExtractTextWorker"

# Prüfe, ob Task bereits existiert
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existingTask) {
    Write-Host "Task '$taskName' existiert bereits. Lösche alten Task..." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

# VBScript-Wrapper für unsichtbare Ausführung (keine aufblinkende Konsole)
$vbsWrapper = Join-Path $PSScriptRoot "extract-text-worker.vbs"
if (Test-Path $vbsWrapper) {
    $action = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$vbsWrapper`""
} else {
    # Fallback: PHP direkt (kann kurz aufblinken)
    $action = New-ScheduledTaskAction -Execute $phpExe -Argument "`"$workerScript`"" -WorkingDirectory $projectRoot
}

# Trigger: Alle 5 Minuten
$trigger = New-ScheduledTaskTrigger -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 365) -Once -At (Get-Date)

# Task-Einstellungen
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

# Task-Principal (als aktueller Benutzer, aber läuft auch ohne eingeloggten Benutzer)
$principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType ServiceAccount `
    -RunLevel Highest

# Task erstellen
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Description "TOM3 Extract Text Worker - Extrahiert Text aus Dokumenten (PDF, DOCX, XLSX, etc.)" | Out-Null
    
    Write-Host "✅ Task '$taskName' erfolgreich erstellt!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Task-Details:" -ForegroundColor Cyan
    Write-Host "  Name: $taskName"
    if (Test-Path $vbsWrapper) {
        Write-Host "  Script: $workerScript (über VBScript-Wrapper - unsichtbar)" -ForegroundColor Green
    } else {
        Write-Host "  Script: $workerScript (direkt - kann kurz aufblinken)" -ForegroundColor Yellow
    }
    Write-Host "  Intervall: Alle 5 Minuten"
    Write-Host "  User: $env:USERDOMAIN\$env:USERNAME (sichtbar und überwachbar)"
    Write-Host ""
    Write-Host "Task verwalten:" -ForegroundColor Cyan
    Write-Host "  Anzeigen: Get-ScheduledTask -TaskName '$taskName'"
    Write-Host "  Starten: Start-ScheduledTask -TaskName '$taskName'"
    Write-Host "  Stoppen: Stop-ScheduledTask -TaskName '$taskName'"
    Write-Host "  Löschen: Unregister-ScheduledTask -TaskName '$taskName' -Confirm:`$false"
    Write-Host ""
    Write-Host "Log-Datei: $projectRoot\logs\extract-text-worker.log" -ForegroundColor Cyan
    
} catch {
    Write-Host "FEHLER beim Erstellen des Tasks: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "Hinweis: Dieses Script muss als Administrator ausgeführt werden!" -ForegroundColor Yellow
    exit 1
}


