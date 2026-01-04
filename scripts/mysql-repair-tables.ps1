# MySQL Tabellen-Reparatur mit mysqlcheck
# Repariert alle Tabellen in allen Datenbanken

$ErrorActionPreference = "Continue"
$mysqlBinDir = "C:\xampp\mysql\bin"
$mysqlCheckPath = Join-Path $mysqlBinDir "mysqlcheck.exe"

Write-Host "=== MySQL Tabellen-Reparatur ===" -ForegroundColor Cyan
Write-Host ""

# Prüfe ob MySQL läuft
$mysqlRunning = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if (-not $mysqlRunning) {
    Write-Host "MySQL läuft nicht. Versuche MySQL zu starten..." -ForegroundColor Yellow
    $mysqlStartScript = "C:\xampp\mysql_start.bat"
    if (Test-Path $mysqlStartScript) {
        try {
            Start-Process -FilePath $mysqlStartScript -WindowStyle Hidden -ErrorAction Stop
            Start-Sleep -Seconds 5
            
            # Prüfe erneut
            $mysqlRunning = Get-Process -Name mysqld -ErrorAction SilentlyContinue
            if (-not $mysqlRunning) {
                Write-Host "FEHLER: MySQL konnte nicht gestartet werden!" -ForegroundColor Red
                Write-Host "Bitte starte MySQL manuell über XAMPP Control Panel." -ForegroundColor Yellow
                exit 1
            }
            Write-Host "✓ MySQL gestartet" -ForegroundColor Green
            Write-Host ""
        } catch {
            Write-Host "FEHLER: MySQL konnte nicht gestartet werden: $_" -ForegroundColor Red
            Write-Host "Bitte starte MySQL manuell über XAMPP Control Panel." -ForegroundColor Yellow
            exit 1
        }
    } else {
        Write-Host "FEHLER: mysql_start.bat nicht gefunden!" -ForegroundColor Red
        Write-Host "Bitte starte MySQL manuell über XAMPP Control Panel." -ForegroundColor Yellow
        exit 1
    }
}

Write-Host "MySQL läuft. Starte Tabellen-Reparatur..." -ForegroundColor Green
Write-Host ""

# Prüfe ob mysqlcheck existiert
if (-not (Test-Path $mysqlCheckPath)) {
    Write-Host "FEHLER: mysqlcheck.exe nicht gefunden: $mysqlCheckPath" -ForegroundColor Red
    exit 1
}

# Repariere mysql System-Datenbank
Write-Host "[1/2] Repariere mysql System-Datenbank..." -ForegroundColor Yellow
try {
    Push-Location $mysqlBinDir
    $result = & $mysqlCheckPath -u root --auto-repair mysql 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  ✓ mysql Datenbank repariert" -ForegroundColor Green
        $result | ForEach-Object { Write-Host "    $_" -ForegroundColor Gray }
    } else {
        Write-Host "  ⚠ Fehler bei mysql Datenbank" -ForegroundColor Yellow
        $result | ForEach-Object { Write-Host "    $_" -ForegroundColor Yellow }
    }
} catch {
    Write-Host "  ✗ Fehler: $_" -ForegroundColor Red
} finally {
    Pop-Location
}

Write-Host ""

# Repariere alle Datenbanken
Write-Host "[2/2] Repariere alle Datenbanken..." -ForegroundColor Yellow
try {
    Push-Location $mysqlBinDir
    $result = & $mysqlCheckPath -u root --auto-repair --all-databases 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  ✓ Alle Datenbanken repariert" -ForegroundColor Green
        $result | ForEach-Object { Write-Host "    $_" -ForegroundColor Gray }
    } else {
        Write-Host "  ⚠ Fehler bei einigen Datenbanken" -ForegroundColor Yellow
        $result | ForEach-Object { Write-Host "    $_" -ForegroundColor Yellow }
    }
} catch {
    Write-Host "  ✗ Fehler: $_" -ForegroundColor Red
} finally {
    Pop-Location
}

Write-Host ""
Write-Host "=== Reparatur abgeschlossen ===" -ForegroundColor Cyan
Write-Host ""


