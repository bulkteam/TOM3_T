# TOM3 - Komplette Installation
# Installiert PostgreSQL und Neo4j und richtet alles ein

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - Komplette Installation" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Prüfe Administrator-Rechte
if (-NOT ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")) {
    Write-Host "❌ Dieses Script muss als Administrator ausgeführt werden!" -ForegroundColor Red
    Write-Host "Rechtsklick auf PowerShell -> 'Als Administrator ausführen'" -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host "✓ Script läuft als Administrator" -ForegroundColor Green
Write-Host ""

# 1. PostgreSQL
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   1. PostgreSQL Installation" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

& "$PSScriptRoot\install-postgresql.ps1"

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "⚠ PostgreSQL-Installation hatte Probleme" -ForegroundColor Yellow
    Write-Host "  Fortfahren mit Neo4j..." -ForegroundColor Yellow
}

Write-Host ""
Write-Host ""

# 2. Neo4j
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   2. Neo4j Installation" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

& "$PSScriptRoot\install-neo4j.ps1"

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "⚠ Neo4j-Installation hatte Probleme" -ForegroundColor Yellow
}

Write-Host ""
Write-Host ""

# 3. Zusammenfassung
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Installation abgeschlossen" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Nächste Schritte:" -ForegroundColor Yellow
Write-Host ""
Write-Host "1. PostgreSQL-Datenbank erstellen:" -ForegroundColor White
Write-Host "   psql -U postgres" -ForegroundColor Gray
Write-Host "   CREATE DATABASE tom3;" -ForegroundColor Gray
Write-Host "   CREATE USER tom3_user WITH PASSWORD 'tom3_password';" -ForegroundColor Gray
Write-Host "   GRANT ALL PRIVILEGES ON DATABASE tom3 TO tom3_user;" -ForegroundColor Gray
Write-Host ""
Write-Host "2. TOM3 konfigurieren:" -ForegroundColor White
Write-Host "   Copy-Item config\database.php.example config\database.php" -ForegroundColor Gray
Write-Host "   # Bearbeite config\database.php" -ForegroundColor Gray
Write-Host ""
Write-Host "3. TOM3-Datenbank-Schema erstellen:" -ForegroundColor White
Write-Host "   php scripts\setup-database.php" -ForegroundColor Gray
Write-Host ""
Write-Host "4. Neo4j Constraints/Indexes erstellen:" -ForegroundColor White
Write-Host "   # Öffne http://localhost:7474" -ForegroundColor Gray
Write-Host "   # Führe database\neo4j\constraints.cypher aus" -ForegroundColor Gray
Write-Host "   # Führe database\neo4j\indexes.cypher aus" -ForegroundColor Gray
Write-Host ""
Write-Host "5. TOM3 starten:" -ForegroundColor White
Write-Host "   http://localhost/TOM3/public/" -ForegroundColor Gray
Write-Host ""


