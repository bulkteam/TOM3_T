# TOM3 - Erstelle Standard-Testnutzer für PostgreSQL
# Erstellt Test-Datenbank und Testnutzer mit Standardwerten

param(
    [string]$PostgreSQLVersion = "16",
    [string]$InstallPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion",
    [string]$TestUser = "tom3_test",
    [string]$TestPassword = "tom3_test",
    [string]$TestDatabase = "tom3",
    [string]$PostgresPassword = ""
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - Testnutzer erstellen" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Prüfe ob PostgreSQL installiert ist
$pgBinPath = "$InstallPath\bin"
if (-not (Test-Path "$pgBinPath\psql.exe")) {
    Write-Host "❌ PostgreSQL nicht gefunden in: $pgBinPath" -ForegroundColor Red
    Write-Host ""
    Write-Host "Bitte installiere PostgreSQL oder passe den InstallPath an:" -ForegroundColor Yellow
    Write-Host "  .\create-test-users.ps1 -InstallPath 'C:\Program Files\PostgreSQL\15'" -ForegroundColor Gray
    exit 1
}

Write-Host "✓ PostgreSQL gefunden: $pgBinPath" -ForegroundColor Green
Write-Host ""

# Prüfe ob PostgreSQL-Service läuft
$serviceName = "postgresql-x64-$PostgreSQLVersion"
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue

if (-not $service) {
    # Versuche andere Service-Namen
    $services = Get-Service | Where-Object { $_.Name -like "*postgres*" }
    if ($services) {
        $service = $services[0]
        $serviceName = $service.Name
        Write-Host "✓ PostgreSQL-Service gefunden: $serviceName" -ForegroundColor Green
    } else {
        Write-Host "⚠ PostgreSQL-Service nicht gefunden" -ForegroundColor Yellow
        Write-Host "  Versuche trotzdem fortzufahren..." -ForegroundColor Yellow
    }
} else {
    Write-Host "✓ PostgreSQL-Service gefunden: $serviceName" -ForegroundColor Green
}

if ($service -and $service.Status -ne 'Running') {
    Write-Host "→ Starte PostgreSQL-Service..." -ForegroundColor Yellow
    try {
        Start-Service -Name $serviceName
        Start-Sleep -Seconds 3
        Write-Host "✓ Service gestartet" -ForegroundColor Green
    } catch {
        Write-Host "❌ Service konnte nicht gestartet werden: $_" -ForegroundColor Red
        exit 1
    }
}

# Frage nach postgres-Passwort, falls nicht angegeben
if ([string]::IsNullOrEmpty($PostgresPassword)) {
    Write-Host ""
    Write-Host "Bitte gib das Passwort für den 'postgres'-Benutzer ein:" -ForegroundColor Yellow
    Write-Host "(Das Passwort, das du bei der PostgreSQL-Installation gesetzt hast)" -ForegroundColor Gray
    $securePassword = Read-Host "Postgres-Passwort" -AsSecureString
    $BSTR = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $PostgresPassword = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($BSTR)
}

# Setze Umgebungsvariable für psql
$env:PGPASSWORD = $PostgresPassword

Write-Host ""
Write-Host "→ Teste Verbindung zu PostgreSQL..." -ForegroundColor Yellow
try {
    $result = & "$pgBinPath\psql.exe" -U postgres -h localhost -p 5432 -c "SELECT version();" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ PostgreSQL-Verbindung erfolgreich!" -ForegroundColor Green
    } else {
        Write-Host "❌ Verbindung fehlgeschlagen" -ForegroundColor Red
        Write-Host "  Prüfe:" -ForegroundColor Yellow
        Write-Host "  - Ist PostgreSQL installiert?" -ForegroundColor Gray
        Write-Host "  - Läuft der PostgreSQL-Service?" -ForegroundColor Gray
        Write-Host "  - Ist das Passwort korrekt?" -ForegroundColor Gray
        exit 1
    }
} catch {
    Write-Host "❌ Verbindung fehlgeschlagen: $_" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "→ Erstelle Test-Datenbank und Testnutzer..." -ForegroundColor Yellow

# SQL-Befehle ausführen
$sqlCommands = @"
-- Erstelle Datenbank (falls nicht vorhanden)
SELECT 'CREATE DATABASE $TestDatabase' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$TestDatabase')\gexec

-- Erstelle Benutzer (falls nicht vorhanden)
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_user WHERE usename = '$TestUser') THEN
        CREATE USER $TestUser WITH PASSWORD '$TestPassword';
    END IF;
END
\$\$;

-- Setze Berechtigungen
GRANT ALL PRIVILEGES ON DATABASE $TestDatabase TO $TestUser;
ALTER DATABASE $TestDatabase OWNER TO $TestUser;
"@

try {
    # Führe SQL-Befehle aus
    $sqlCommands | & "$pgBinPath\psql.exe" -U postgres -h localhost -p 5432 -f - 2>&1 | Out-Null
    
    # Teste Verbindung mit neuem Benutzer
    $env:PGPASSWORD = $TestPassword
    $testResult = & "$pgBinPath\psql.exe" -U $TestUser -h localhost -p 5432 -d $TestDatabase -c "SELECT current_database(), current_user;" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ Test-Datenbank und Testnutzer erfolgreich erstellt!" -ForegroundColor Green
    } else {
        Write-Host "⚠ Datenbank erstellt, aber Verbindungstest fehlgeschlagen" -ForegroundColor Yellow
    }
} catch {
    Write-Host "❌ Fehler beim Erstellen: $_" -ForegroundColor Red
    exit 1
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
    Write-Host "→ Aktualisiere config/database.php..." -ForegroundColor Yellow
    
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
    Write-Host "✓ Konfiguration aktualisiert" -ForegroundColor Green
} else {
    Write-Host "⚠ config/database.php nicht gefunden" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   Nächste Schritte" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Führe Datenbank-Setup aus:" -ForegroundColor Yellow
Write-Host "   php scripts\setup-database.php" -ForegroundColor White
Write-Host ""
Write-Host "2. Teste die API:" -ForegroundColor Yellow
Write-Host "   http://localhost/TOM3/public/api/test.php" -ForegroundColor White
Write-Host ""
Write-Host "✅ Fertig! Testnutzer erstellt." -ForegroundColor Green
Write-Host ""




