# TOM3 - Windows Setup Script
# Prüft und hilft bei der Installation von PostgreSQL und Neo4j

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - Windows Setup Prüfung" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$errors = @()

# Prüfe PostgreSQL
Write-Host "Prüfe PostgreSQL..." -ForegroundColor Yellow
try {
    $psqlVersion = & psql --version 2>$null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ PostgreSQL gefunden: $psqlVersion" -ForegroundColor Green
    } else {
        throw "psql nicht gefunden"
    }
} catch {
    Write-Host "✗ PostgreSQL nicht gefunden" -ForegroundColor Red
    Write-Host "  → Installiere PostgreSQL von: https://www.postgresql.org/download/windows/" -ForegroundColor Yellow
    Write-Host "  → Füge PostgreSQL\bin zu PATH hinzu" -ForegroundColor Yellow
    $errors += "PostgreSQL nicht installiert"
}

# Prüfe PostgreSQL-Service
Write-Host ""
Write-Host "Prüfe PostgreSQL-Service..." -ForegroundColor Yellow
$pgService = Get-Service -Name "*postgres*" -ErrorAction SilentlyContinue
if ($pgService) {
    if ($pgService.Status -eq 'Running') {
        Write-Host "✓ PostgreSQL-Service läuft: $($pgService.Name)" -ForegroundColor Green
    } else {
        Write-Host "⚠ PostgreSQL-Service gestoppt: $($pgService.Name)" -ForegroundColor Yellow
        Write-Host "  → Starte Service mit: Start-Service -Name '$($pgService.Name)'" -ForegroundColor Yellow
    }
} else {
    Write-Host "⚠ PostgreSQL-Service nicht gefunden" -ForegroundColor Yellow
    Write-Host "  → Prüfe, ob PostgreSQL installiert ist" -ForegroundColor Yellow
}

# Prüfe Neo4j
Write-Host ""
Write-Host "Prüfe Neo4j..." -ForegroundColor Yellow
try {
    $neo4jVersion = & neo4j --version 2>$null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ Neo4j gefunden: $neo4jVersion" -ForegroundColor Green
    } else {
        throw "neo4j nicht gefunden"
    }
} catch {
    Write-Host "⚠ Neo4j CLI nicht gefunden (normal bei Desktop-Installation)" -ForegroundColor Yellow
    Write-Host "  → Installiere Neo4j Desktop von: https://neo4j.com/download/" -ForegroundColor Yellow
    Write-Host "  → Oder prüfe, ob Neo4j Desktop läuft" -ForegroundColor Yellow
}

# Prüfe Neo4j Port
Write-Host ""
Write-Host "Prüfe Neo4j Port (7687)..." -ForegroundColor Yellow
$neo4jPort = netstat -ano | Select-String "7687"
if ($neo4jPort) {
    Write-Host "✓ Neo4j Port 7687 ist aktiv" -ForegroundColor Green
} else {
    Write-Host "⚠ Neo4j Port 7687 nicht aktiv" -ForegroundColor Yellow
    Write-Host "  → Starte Neo4j Desktop und starte eine Datenbank" -ForegroundColor Yellow
}

# Prüfe TOM3 Konfiguration
Write-Host ""
Write-Host "Prüfe TOM3 Konfiguration..." -ForegroundColor Yellow
$configFile = "config\database.php"
if (Test-Path $configFile) {
    Write-Host "✓ Konfigurationsdatei gefunden: $configFile" -ForegroundColor Green
} else {
    Write-Host "✗ Konfigurationsdatei nicht gefunden: $configFile" -ForegroundColor Red
    Write-Host "  → Kopiere config\database.php.example nach config\database.php" -ForegroundColor Yellow
    Write-Host "  → Passe die Datenbank-Credentials an" -ForegroundColor Yellow
    $errors += "Konfiguration fehlt"
}

# Prüfe Composer
Write-Host ""
Write-Host "Prüfe Composer..." -ForegroundColor Yellow
try {
    $composerVersion = & composer --version 2>$null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ Composer gefunden" -ForegroundColor Green
    } else {
        throw "composer nicht gefunden"
    }
} catch {
    Write-Host "✗ Composer nicht gefunden" -ForegroundColor Red
    Write-Host "  → Installiere Composer von: https://getcomposer.org/download/" -ForegroundColor Yellow
    $errors += "Composer nicht installiert"
}

# Prüfe PHP
Write-Host ""
Write-Host "Prüfe PHP..." -ForegroundColor Yellow
try {
    $phpVersion = & php --version 2>$null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ PHP gefunden" -ForegroundColor Green
        $phpVersion -match "PHP (\d+\.\d+)" | Out-Null
        $majorVersion = [int]$matches[1]
        if ($majorVersion -ge 8) {
            Write-Host "  Version: $($matches[0])" -ForegroundColor Green
        } else {
            Write-Host "  ⚠ PHP 8.1+ empfohlen" -ForegroundColor Yellow
        }
    } else {
        throw "php nicht gefunden"
    }
} catch {
    Write-Host "✗ PHP nicht gefunden" -ForegroundColor Red
    Write-Host "  → Installiere PHP 8.1+ (z.B. über XAMPP)" -ForegroundColor Yellow
    $errors += "PHP nicht installiert"
}

# Zusammenfassung
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Zusammenfassung" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan

if ($errors.Count -eq 0) {
    Write-Host ""
    Write-Host "✓ Alle Voraussetzungen erfüllt!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Nächste Schritte:" -ForegroundColor Yellow
    Write-Host "1. Erstelle PostgreSQL-Datenbank:" -ForegroundColor White
    Write-Host "   psql -U postgres" -ForegroundColor Gray
    Write-Host "   CREATE DATABASE tom3;" -ForegroundColor Gray
    Write-Host ""
    Write-Host "2. Konfiguriere TOM3:" -ForegroundColor White
    Write-Host "   Kopiere config\database.php.example nach config\database.php" -ForegroundColor Gray
    Write-Host "   Passe die Credentials an" -ForegroundColor Gray
    Write-Host ""
    Write-Host "3. Führe Datenbank-Setup aus:" -ForegroundColor White
    Write-Host "   php scripts\setup-database.php" -ForegroundColor Gray
    Write-Host ""
    Write-Host "4. Starte Neo4j Desktop und erstelle eine Datenbank" -ForegroundColor White
    Write-Host ""
    Write-Host "5. Öffne TOM3 UI:" -ForegroundColor White
    Write-Host "   http://localhost/TOM3/public/" -ForegroundColor Gray
} else {
    Write-Host ""
    Write-Host "✗ Folgende Probleme gefunden:" -ForegroundColor Red
    foreach ($error in $errors) {
        Write-Host "  - $error" -ForegroundColor Red
    }
    Write-Host ""
    Write-Host "Siehe docs\INSTALLATION-WINDOWS.md für Details" -ForegroundColor Yellow
}

Write-Host ""


