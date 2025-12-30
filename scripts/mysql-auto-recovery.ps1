# MySQL/MariaDB Auto-Recovery Script
# Prüft und repariert Aria-Fehler automatisch vor dem MySQL-Start
# Kann als Scheduled Task oder in XAMPP Startup integriert werden

param(
    [switch]$FixOnly = $false  # Nur reparieren, nicht starten
)

$ErrorActionPreference = "Continue"
$mysqlDataDir = "C:\xampp\mysql\data"
$mysqlBinDir = "C:\xampp\mysql\bin"
$ariaChkPath = Join-Path $mysqlBinDir "aria_chk.exe"

Write-Host "=== MySQL Auto-Recovery ===" -ForegroundColor Cyan
Write-Host ""

# Prüfe ob MySQL läuft
$mysqlRunning = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if ($mysqlRunning) {
    Write-Host "MySQL läuft bereits. Stoppe MySQL..." -ForegroundColor Yellow
    Stop-Process -Name mysqld -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

# Prüfe auf Aria-Fehler in Log
$errorLog = Join-Path $mysqlDataDir "mysql_error.log"
$hasAriaError = $false

if (Test-Path $errorLog) {
    $lastError = Get-Content $errorLog -Tail 20 | Select-String -Pattern "Aria recovery failed|aria_chk" -Quiet
    if ($lastError) {
        $hasAriaError = $true
        Write-Host "Aria-Fehler in Log erkannt!" -ForegroundColor Red
    }
}

# Prüfe ob aria_log_control existiert und korrupt ist
$ariaLogControl = Join-Path $mysqlDataDir "aria_log_control"
if (Test-Path $ariaLogControl) {
    try {
        $content = Get-Content $ariaLogControl -ErrorAction Stop
        if ($null -eq $content -or $content.Length -eq 0) {
            $hasAriaError = $true
            Write-Host "aria_log_control ist leer oder korrupt!" -ForegroundColor Red
        }
    } catch {
        $hasAriaError = $true
        Write-Host "aria_log_control kann nicht gelesen werden!" -ForegroundColor Red
    }
}

# Wenn Aria-Fehler erkannt oder manuell aufgerufen, repariere
if ($hasAriaError -or $FixOnly) {
    Write-Host ""
    Write-Host "Starte Aria-Reparatur..." -ForegroundColor Yellow
    
    # 1. Lösche aria_log Dateien
    Write-Host "  - Lösche aria_log Dateien..." -ForegroundColor Cyan
    Get-ChildItem -Path $mysqlDataDir -Filter "aria_log.*" -ErrorAction SilentlyContinue | 
        Remove-Item -Force -ErrorAction SilentlyContinue
    
    # 2. Lösche aria_log_control
    Write-Host "  - Lösche aria_log_control..." -ForegroundColor Cyan
    if (Test-Path $ariaLogControl) {
        Remove-Item $ariaLogControl -Force -ErrorAction SilentlyContinue
    }
    
    # 3. Repariere Aria-Tabellen mit aria_chk
    if (Test-Path $ariaChkPath) {
        Write-Host "  - Repariere Aria-Tabellen..." -ForegroundColor Cyan
        $ariaTables = Get-ChildItem -Path $mysqlDataDir -Recurse -Filter "*.MAD" -ErrorAction SilentlyContinue
        
        if ($ariaTables) {
            foreach ($table in $ariaTables) {
                $tableName = $table.FullName -replace '\.MAD$', ''
                try {
                    & $ariaChkPath -r "$tableName" 2>&1 | Out-Null
                    Write-Host "    ✓ $($table.Name)" -ForegroundColor Green
                } catch {
                    Write-Host "    ✗ $($table.Name) - Fehler" -ForegroundColor Red
                }
            }
        } else {
            Write-Host "    Keine Aria-Tabellen gefunden" -ForegroundColor Gray
        }
    } else {
        Write-Host "  ⚠ aria_chk.exe nicht gefunden: $ariaChkPath" -ForegroundColor Yellow
    }
    
    Write-Host ""
    Write-Host "✓ Aria-Reparatur abgeschlossen!" -ForegroundColor Green
} else {
    Write-Host "Keine Aria-Fehler erkannt. MySQL sollte normal starten." -ForegroundColor Green
}

Write-Host ""

# Optional: MySQL starten (wenn nicht FixOnly)
if (-not $FixOnly) {
    Write-Host "Hinweis: Starte MySQL manuell über XAMPP Control Panel" -ForegroundColor Yellow
    Write-Host "        oder verwende: C:\xampp\mysql_start.bat" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== Fertig ===" -ForegroundColor Cyan


