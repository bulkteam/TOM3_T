# PowerShell Script zum Einrichten von Scheduled Tasks für MySQL Wartung
# Führt aus als Administrator

param(
    [switch]$CreateRecoveryTask = $true,
    [switch]$CreateBackupTask = $true
)

# Prüfe ob als Administrator ausgeführt
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "FEHLER: Dieses Skript muss als Administrator ausgeführt werden!" -ForegroundColor Red
    Write-Host "Rechtsklick auf PowerShell -> 'Als Administrator ausführen'" -ForegroundColor Yellow
    pause
    exit 1
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptDir
$recoveryScript = Join-Path $scriptDir "mysql-auto-recovery.ps1"
$backupScript = Join-Path $scriptDir "mysql-backup.bat"

Write-Host "=== Einrichten von Scheduled Tasks ===" -ForegroundColor Cyan
Write-Host ""

# 1. Recovery Task (vor jedem Systemstart)
if ($CreateRecoveryTask) {
    Write-Host "1. Erstelle Recovery Task..." -ForegroundColor Yellow
    
    $taskName = "MySQL-Auto-Recovery"
    $taskDescription = "Führt automatisch MySQL Aria-Recovery vor dem Start durch"
    
    # Entferne existierende Task falls vorhanden
    $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($existingTask) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        Write-Host "   Alte Task entfernt" -ForegroundColor Gray
    }
    
    # Erstelle neue Task
    $action = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument "-ExecutionPolicy Bypass -File `"$recoveryScript`""
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
    
    try {
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description $taskDescription | Out-Null
        Write-Host "   ✓ Recovery Task erstellt: $taskName" -ForegroundColor Green
    } catch {
        Write-Host "   ✗ Fehler beim Erstellen des Recovery Tasks: $_" -ForegroundColor Red
    }
}

Write-Host ""

# 2. Backup Task (täglich um 2 Uhr nachts)
if ($CreateBackupTask) {
    Write-Host "2. Erstelle Backup Task..." -ForegroundColor Yellow
    
    $taskName = "MySQL-Daily-Backup"
    $taskDescription = "Erstellt täglich ein Backup der MySQL-Datenbank"
    
    # Entferne existierende Task falls vorhanden
    $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($existingTask) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        Write-Host "   Alte Task entfernt" -ForegroundColor Gray
    }
    
    # Erstelle neue Task
    $action = New-ScheduledTaskAction -Execute $backupScript -WorkingDirectory $scriptDir
    $trigger = New-ScheduledTaskTrigger -Daily -At "02:00"
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
    
    try {
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description $taskDescription | Out-Null
        Write-Host "   ✓ Backup Task erstellt: $taskName" -ForegroundColor Green
        Write-Host "     Läuft täglich um 02:00 Uhr" -ForegroundColor Gray
    } catch {
        Write-Host "   ✗ Fehler beim Erstellen des Backup Tasks: $_" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "=== Fertig ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Erstellte Tasks:" -ForegroundColor Yellow
if ($CreateRecoveryTask) { Write-Host "  - MySQL-Auto-Recovery (beim Systemstart)" -ForegroundColor Green }
if ($CreateBackupTask) { Write-Host "  - MySQL-Daily-Backup (täglich 02:00 Uhr)" -ForegroundColor Green }
Write-Host ""
Write-Host "Tasks können in der Aufgabenplanung verwaltet werden:" -ForegroundColor Yellow
Write-Host "  Windows-Taste + R -> taskschd.msc" -ForegroundColor Gray
Write-Host ""

pause




