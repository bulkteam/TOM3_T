# TOM3 - Finde aktuelle Datenbank-Einstellungen
# Prueft PostgreSQL und Neo4j und zeigt die aktuellen Konfigurationen

param(
    [string]$PostgreSQLVersion = "16",
    [string]$InstallPath = "C:\Program Files\PostgreSQL\$PostgreSQLVersion"
)

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "   TOM3 - Datenbank-Einstellungen finden" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# PostgreSQL pruefen
Write-Host "PostgreSQL:" -ForegroundColor Yellow
Write-Host ""

$pgBinPath = "$InstallPath\bin"
if (Test-Path "$pgBinPath\psql.exe") {
    Write-Host "[OK] PostgreSQL gefunden: $InstallPath" -ForegroundColor Green
    
    # Pruefe Service
    $serviceName = "postgresql-x64-$PostgreSQLVersion"
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    
    if (-not $service) {
        $services = Get-Service | Where-Object { $_.Name -like "*postgres*" }
        if ($services) {
            $service = $services[0]
            $serviceName = $service.Name
        }
    }
    
    if ($service) {
        Write-Host "[OK] Service: $serviceName (Status: $($service.Status))" -ForegroundColor Green
    } else {
        Write-Host "[WARN] Service nicht gefunden" -ForegroundColor Yellow
    }
    
    # Pruefe Port
    $configFile = "$InstallPath\data\postgresql.conf"
    $port = "5432"
    if (Test-Path $configFile) {
        $portLine = Select-String -Path $configFile -Pattern "^port\s*=" | Select-Object -First 1
        if ($portLine) {
            $port = ($portLine.Line -split '=')[1].Trim()
        }
    }
    
    Write-Host ""
    Write-Host "Standard-Einstellungen:" -ForegroundColor Cyan
    Write-Host "  Host:     localhost" -ForegroundColor White
    Write-Host "  Port:     $port" -ForegroundColor White
    Write-Host "  Datenbank: tom3 (muss erstellt werden)" -ForegroundColor White
    Write-Host "  Benutzer:  postgres (Standard)" -ForegroundColor White
    Write-Host "  Passwort:  [Das Passwort, das du bei der Installation gesetzt hast]" -ForegroundColor White
    Write-Host ""
    Write-Host "Um das Passwort zu pruefen:" -ForegroundColor Yellow
    Write-Host "  psql -U postgres -h localhost -p $port" -ForegroundColor Gray
    Write-Host ""
    
    # Pruefe vorhandene Datenbanken
    Write-Host "Pruefe vorhandene Datenbanken..." -ForegroundColor Yellow
    try {
        $databases = & "$pgBinPath\psql.exe" -U postgres -h localhost -p $port -l -t 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Host "[OK] Verbindung erfolgreich" -ForegroundColor Green
            Write-Host ""
            Write-Host "Vorhandene Datenbanken:" -ForegroundColor Cyan
            $databases | Where-Object { $_ -match '\S' } | ForEach-Object {
                $dbName = ($_ -split '\s+')[0]
                if ($dbName -and $dbName -ne 'Name') {
                    Write-Host "  - $dbName" -ForegroundColor White
                }
            }
        } else {
            Write-Host "[WARN] Verbindung fehlgeschlagen (Passwort erforderlich)" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "[WARN] Verbindungstest fehlgeschlagen" -ForegroundColor Yellow
    }
} else {
    Write-Host "[FEHLER] PostgreSQL nicht gefunden in: $InstallPath" -ForegroundColor Red
    Write-Host ""
    Write-Host "Moegliche Installationspfade:" -ForegroundColor Yellow
    $possiblePaths = @(
        "C:\Program Files\PostgreSQL\16",
        "C:\Program Files\PostgreSQL\15",
        "C:\Program Files\PostgreSQL\14",
        "C:\Program Files\PostgreSQL\13"
    )
    foreach ($path in $possiblePaths) {
        if (Test-Path "$path\bin\psql.exe") {
            Write-Host "  [OK] $path" -ForegroundColor Green
        } else {
            Write-Host "  [ ] $path" -ForegroundColor Gray
        }
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan

# Neo4j pruefen
Write-Host ""
Write-Host "Neo4j:" -ForegroundColor Yellow
Write-Host ""

$neo4jProcess = Get-Process -Name "Neo4j Desktop" -ErrorAction SilentlyContinue
if ($neo4jProcess) {
    Write-Host "[OK] Neo4j Desktop laeuft" -ForegroundColor Green
} else {
    Write-Host "[WARN] Neo4j Desktop nicht gefunden" -ForegroundColor Yellow
}

$boltPort = Get-NetTCPConnection -LocalPort 7687 -ErrorAction SilentlyContinue
if ($boltPort) {
    Write-Host "[OK] Neo4j Bolt-Port 7687 ist aktiv" -ForegroundColor Green
} else {
    Write-Host "[WARN] Neo4j Bolt-Port 7687 nicht aktiv" -ForegroundColor Yellow
}

$httpPort = Get-NetTCPConnection -LocalPort 7474 -ErrorAction SilentlyContinue
if ($httpPort) {
    Write-Host "[OK] Neo4j HTTP-Port 7474 ist aktiv" -ForegroundColor Green
    Write-Host "  Oeffne: http://localhost:7474" -ForegroundColor Gray
} else {
    Write-Host "[WARN] Neo4j HTTP-Port 7474 nicht aktiv" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Standard-Einstellungen:" -ForegroundColor Cyan
Write-Host "  URI:      bolt://localhost:7687" -ForegroundColor White
Write-Host "  Benutzer: neo4j" -ForegroundColor White
Write-Host "  Passwort: [Das Passwort, das du bei Neo4j Desktop gesetzt hast]" -ForegroundColor White
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Empfehlung:" -ForegroundColor Yellow
Write-Host "  Fuehre aus: .\create-test-users.ps1" -ForegroundColor White
Write-Host "  Dies erstellt automatisch Testnutzer und aktualisiert die Konfiguration." -ForegroundColor Gray
Write-Host ""
