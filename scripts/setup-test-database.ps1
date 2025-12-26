# TOM3 - Einfaches Script zum Erstellen von Testnutzern
# Vereinfachte Version ohne Encoding-Probleme

param(
    [string]$PostgreSQLVersion = "16",
    [string]$InstallPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion",
    [string]$TestUser = "tom3_test",
    [string]$TestPassword = "tom3_test",
    [string]$TestDatabase = "tom3"
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - Testnutzer erstellen" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Pruefe PostgreSQL
$pgBinPath = "$InstallPath\bin"
if (-not (Test-Path "$pgBinPath\psql.exe")) {
    Write-Host "[FEHLER] PostgreSQL nicht gefunden in: $pgBinPath" -ForegroundColor Red
    Write-Host ""
    Write-Host "Bitte installiere PostgreSQL oder passe den Pfad an:" -ForegroundColor Yellow
    Write-Host "  .\setup-test-database.ps1 -InstallPath 'C:\Program Files\PostgreSQL\15'" -ForegroundColor Gray
    exit 1
}

Write-Host "[OK] PostgreSQL gefunden: $pgBinPath" -ForegroundColor Green
Write-Host ""

# Frage nach postgres-Passwort
Write-Host "Bitte gib das Passwort fuer den 'postgres'-Benutzer ein:" -ForegroundColor Yellow
Write-Host "(Das Passwort, das du bei der PostgreSQL-Installation gesetzt hast)" -ForegroundColor Gray
$securePassword = Read-Host "Postgres-Passwort" -AsSecureString
$BSTR = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$PostgresPassword = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($BSTR)

# Setze Umgebungsvariable
$env:PGPASSWORD = $PostgresPassword

Write-Host ""
Write-Host "Teste Verbindung..." -ForegroundColor Yellow
$testResult = & "$pgBinPath\psql.exe" -U postgres -h localhost -p 5432 -c "SELECT version();" 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FEHLER] Verbindung fehlgeschlagen" -ForegroundColor Red
    Write-Host "  Pruefe:" -ForegroundColor Yellow
    Write-Host "  - Ist PostgreSQL installiert?" -ForegroundColor Gray
    Write-Host "  - Laeuft der PostgreSQL-Service?" -ForegroundColor Gray
    Write-Host "  - Ist das Passwort korrekt?" -ForegroundColor Gray
    exit 1
}

Write-Host "[OK] Verbindung erfolgreich!" -ForegroundColor Green
Write-Host ""

# Erstelle Datenbank und Benutzer
Write-Host "Erstelle Datenbank und Benutzer..." -ForegroundColor Yellow

# SQL-Befehle
$sql = @"
-- Erstelle Datenbank
SELECT 'CREATE DATABASE $TestDatabase' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$TestDatabase')\gexec

-- Erstelle Benutzer
DO `$`$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_user WHERE usename = '$TestUser') THEN
        CREATE USER $TestUser WITH PASSWORD '$TestPassword';
    END IF;
END
`$`$;

-- Setze Berechtigungen
GRANT ALL PRIVILEGES ON DATABASE $TestDatabase TO $TestUser;
ALTER DATABASE $TestDatabase OWNER TO $TestUser;
"@

$sql | & "$pgBinPath\psql.exe" -U postgres -h localhost -p 5432 -f - 2>&1 | Out-Null

# Teste Verbindung mit neuem Benutzer
$env:PGPASSWORD = $TestPassword
$testResult = & "$pgBinPath\psql.exe" -U $TestUser -h localhost -p 5432 -d $TestDatabase -c "SELECT current_database(), current_user;" 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "[OK] Test-Datenbank und Testnutzer erfolgreich erstellt!" -ForegroundColor Green
} else {
    Write-Host "[WARN] Datenbank erstellt, aber Verbindungstest fehlgeschlagen" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Test-Konfiguration" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "PostgreSQL:" -ForegroundColor Yellow
Write-Host "  Host:     localhost" -ForegroundColor White
Write-Host "  Port:     5432" -ForegroundColor White
Write-Host "  Datenbank: $TestDatabase" -ForegroundColor White
Write-Host "  Benutzer:  $TestUser" -ForegroundColor White
Write-Host "  Passwort:  $TestPassword" -ForegroundColor White
Write-Host ""

# Aktualisiere config/database.php
$configFile = "$PSScriptRoot\..\config\database.php"
if (Test-Path $configFile) {
    Write-Host "Aktualisiere config/database.php..." -ForegroundColor Yellow
    
    $configContent = @"
<?php
/**
 * TOM3 - Database Configuration
 * 
 * Standard-Testkonfiguration (automatisch erstellt)
 */

return [
    'postgresql' => [
        'host' => 'localhost',
        'port' => 5432,
        'dbname' => '$TestDatabase',
        'user' => '$TestUser',
        'password' => '$TestPassword',
        'charset' => 'utf8'
    ],
    'neo4j' => [
        'uri' => 'bolt://localhost:7687',
        'user' => 'neo4j',
        'password' => 'neo4j'
    ]
];
"@
    
    Set-Content -Path $configFile -Value $configContent -Encoding UTF8
    Write-Host "[OK] Konfiguration aktualisiert" -ForegroundColor Green
} else {
    Write-Host "[WARN] config/database.php nicht gefunden" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Naechste Schritte" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Fuehre Datenbank-Setup aus:" -ForegroundColor Yellow
Write-Host "   php scripts\setup-database.php" -ForegroundColor White
Write-Host ""
Write-Host "2. Teste die API:" -ForegroundColor Yellow
Write-Host "   http://localhost/TOM3/public/api/test.php" -ForegroundColor White
Write-Host ""
Write-Host "[OK] Fertig! Testnutzer erstellt." -ForegroundColor Green
Write-Host ""


