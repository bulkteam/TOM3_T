# TOM3 - Quick Start Check
# Prüft alle Voraussetzungen und gibt Anweisungen

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - Quick Start Check" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$ready = $true

# 1. PostgreSQL
Write-Host "1. PostgreSQL..." -ForegroundColor Yellow
$pgService = Get-Service -Name "*postgres*" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($pgService -and $pgService.Status -eq 'Running') {
    Write-Host "   ✓ Service läuft: $($pgService.Name)" -ForegroundColor Green
    
    # Prüfe Datenbank
    $pgBinPath = "C:\Program Files\PostgreSQL\17\bin"
    if (Test-Path "$pgBinPath\psql.exe") {
        $dbCheck = & "$pgBinPath\psql.exe" -U postgres -h localhost -lqt 2>&1 | Select-String "tom3"
        if ($dbCheck) {
            Write-Host "   ✓ Datenbank 'tom3' existiert" -ForegroundColor Green
        } else {
            Write-Host "   ✗ Datenbank 'tom3' fehlt" -ForegroundColor Red
            Write-Host "     → Erstelle: psql -U postgres -c `"CREATE DATABASE tom3;`"" -ForegroundColor Gray
            $ready = $false
        }
    }
} else {
    Write-Host "   ✗ PostgreSQL-Service nicht gestartet" -ForegroundColor Red
    Write-Host "     → Starte: .\scripts\start-postgresql.ps1" -ForegroundColor Gray
    $ready = $false
}

# 2. Neo4j
Write-Host ""
Write-Host "2. Neo4j..." -ForegroundColor Yellow
$neo4jPort = netstat -ano | Select-String "7687"
if ($neo4jPort) {
    Write-Host "   ✓ Neo4j Port 7687 aktiv" -ForegroundColor Green
} else {
    Write-Host "   ⚠ Neo4j nicht aktiv (optional)" -ForegroundColor Yellow
    Write-Host "     → Installiere: .\scripts\install-neo4j.ps1" -ForegroundColor Gray
}

# 3. Konfiguration
Write-Host ""
Write-Host "3. Konfiguration..." -ForegroundColor Yellow
if (Test-Path "config\database.php") {
    Write-Host "   ✓ config\database.php vorhanden" -ForegroundColor Green
} else {
    Write-Host "   ✗ config\database.php fehlt" -ForegroundColor Red
    Write-Host "     → Kopiere: Copy-Item config\database.php.example config\database.php" -ForegroundColor Gray
    Write-Host "     → Bearbeite: config\database.php" -ForegroundColor Gray
    $ready = $false
}

# 4. Composer Dependencies
Write-Host ""
Write-Host "4. Composer Dependencies..." -ForegroundColor Yellow
if (Test-Path "vendor\autoload.php") {
    Write-Host "   ✓ Dependencies installiert" -ForegroundColor Green
} else {
    Write-Host "   ✗ Dependencies fehlen" -ForegroundColor Red
    Write-Host "     → Installiere: composer install" -ForegroundColor Gray
    $ready = $false
}

# 5. Datenbank-Schema
Write-Host ""
Write-Host "5. Datenbank-Schema..." -ForegroundColor Yellow
if ($ready) {
    $pgBinPath = "C:\Program Files\PostgreSQL\17\bin"
    if (Test-Path "$pgBinPath\psql.exe") {
        $tableCheck = & "$pgBinPath\psql.exe" -U postgres -d tom3 -h localhost -c "\dt" 2>&1 | Select-String "case_item"
        if ($tableCheck) {
            Write-Host "   ✓ Schema vorhanden" -ForegroundColor Green
        } else {
            Write-Host "   ✗ Schema fehlt" -ForegroundColor Red
            Write-Host "     → Erstelle: php scripts\setup-database.php" -ForegroundColor Gray
            $ready = $false
        }
    }
}

# Zusammenfassung
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
if ($ready) {
    Write-Host "   ✓ Bereit zum Start!" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Starte TOM3:" -ForegroundColor Yellow
    Write-Host "   http://localhost/TOM3/public/" -ForegroundColor White
    Write-Host ""
    Write-Host "Monitoring:" -ForegroundColor Yellow
    Write-Host "   http://localhost/TOM3/public/monitoring.html" -ForegroundColor White
    Write-Host ""
} else {
    Write-Host "   ✗ Noch nicht bereit" -ForegroundColor Red
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Bitte die oben genannten Schritte ausführen." -ForegroundColor Yellow
    Write-Host ""
}


